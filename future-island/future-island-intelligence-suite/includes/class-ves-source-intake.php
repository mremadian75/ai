<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Source_Intake — Phase 2 core-loop intake surface.
 *
 * The minimal REAL way material enters the loop by hand:
 *   1. Manual text source        (operator notes — nothing fetched)
 *   2. URL-record source         (the URL is RECORDED as a reference; it is
 *                                 NEVER fetched — no SSRF surface, no scraping)
 *   3. Signal from a source      (normalized + deterministically scored by the store)
 *   4. Evidence + draft insight from a signal (traceable: evidence carries the
 *                                 source/signal ids; insight links both)
 *   5. Brief from an APPROVED insight (evidence ids carry through — human
 *                                 review always happens before a brief exists)
 *
 * Security: manage_options + per-action nonce on every mutation; all input
 * validated → sanitized → bounded; all output escaped; workspace membership
 * asserted on every cross-object action; WP_Error on every failure path.
 * No AI calls, no generation, no auto-approval, no publishing.
 */
final class VES_Source_Intake {

    const PAGE_SLUG       = 'fi-intake';
    const ACTION_SOURCE   = 'ves_intake_source';
    const ACTION_SIGNAL   = 'ves_intake_signal';
    const ACTION_INSIGHT  = 'ves_intake_signal_to_insight';
    const ACTION_BRIEF    = 'ves_insight_to_brief';
    const ACTION_PREVIEW  = 'ves_intake_prompt_preview';
    const ACTION_MEMORY   = 'ves_intake_memory_candidate';
    const MAX_NOTES_CHARS = 4000;

    /** Signal types offered by the intake form — the store's canonical signal_type enum. */
    const SIGNAL_TYPES = ['mention', 'trend', 'competitor_move', 'citation', 'claim', 'content_pattern', 'audience_signal', 'market_signal', 'ai_visibility', 'other'];

    public static function register() {
        if (!function_exists('add_action')) { return; }
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_' . self::ACTION_SOURCE, [__CLASS__, 'handle_source']);
        add_action('admin_post_' . self::ACTION_SIGNAL, [__CLASS__, 'handle_signal']);
        add_action('admin_post_' . self::ACTION_INSIGHT, [__CLASS__, 'handle_signal_to_insight']);
        add_action('admin_post_' . self::ACTION_BRIEF, [__CLASS__, 'handle_insight_to_brief']);
        add_action('admin_post_' . self::ACTION_PREVIEW, [__CLASS__, 'handle_prompt_preview']);
        add_action('admin_post_' . self::ACTION_MEMORY, [__CLASS__, 'handle_memory_candidate']);
    }

    public static function register_menu() {
        if (!function_exists('add_management_page')) { return; }
        add_management_page('Future Island — Intake', 'FI Intake', 'manage_options', self::PAGE_SLUG, [__CLASS__, 'render_page']);
    }

    // ── Escaping helpers (shim-safe, same idiom as the other surfaces) ─────────
    private static function e($s)  { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function eu($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function txt($s, $max = 255) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field((string) $s) : trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s)));
        return self::cut($s, $max);
    }
    private static function long_txt($s, $max = self::MAX_NOTES_CHARS) {
        $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field((string) $s) : trim(strip_tags((string) $s));
        return self::cut($s, $max);
    }
    private static function cut($s, $max) {
        if (function_exists('mb_substr')) { return mb_substr((string) $s, 0, (int) $max); }
        return substr((string) $s, 0, (int) $max);
    }
    private static function err($code, $message) { return new WP_Error($code, $message); }
    private static function is_err($t) { return function_exists('is_wp_error') ? is_wp_error($t) : ($t instanceof WP_Error); }

    /**
     * Reference-URL shape check. The URL is never fetched, so this is about
     * recording CLEAN references: http/https only, a host, no embedded
     * credentials, bounded length. No DNS, no network.
     */
    public static function valid_reference_url($url) {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2000) { return false; }
        $p = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($p)) { return false; }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) { return false; }
        if (empty($p['host'])) { return false; }
        if (isset($p['user']) || isset($p['pass'])) { return false; }
        return true;
    }

    private static function assert_workspace($ws) {
        $ws = (int) $ws;
        if ($ws <= 0) { return self::err('ves_intake_workspace', 'A positive workspace id is required.'); }
        return $ws;
    }

    /** Workspace membership: guard class when present, direct comparison always. */
    private static function assert_in_workspace($type, array $row, $ws) {
        if (class_exists('VES_Workspace_Guard') && method_exists('VES_Workspace_Guard', 'assert_object_in_workspace')) {
            $check = VES_Workspace_Guard::assert_object_in_workspace($type, $row, $ws);
            if (self::is_err($check)) { return $check; }
            return true;
        }
        if ((int) ($row['workspace_id'] ?? 0) !== (int) $ws) {
            return self::err('ves_workspace_mismatch', ucfirst((string) $type) . ' belongs to a different workspace.');
        }
        return true;
    }

    // ── Core processors (testable; no nonce/redirect concerns) ─────────────────

    /**
     * Create a source from operator input. NOTHING is fetched — a URL intake
     * records the reference; a manual intake records the operator's note.
     * @return array{source_id:int}|WP_Error
     */
    public static function process_source(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $type  = (string) ($in['intake_type'] ?? 'manual') === 'url' ? 'url' : 'manual';
        $title = self::txt($in['source_title'] ?? '', 255);
        $notes = self::long_txt($in['notes'] ?? '');

        if ($type === 'url') {
            $url = trim((string) ($in['source_url'] ?? ''));
            if (!self::valid_reference_url($url)) {
                return self::err('ves_intake_bad_url', 'Provide a plain http(s) URL without embedded credentials. The URL is recorded as a reference only — it is never fetched.');
            }
            $url = function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            $payload = [
                'workspace_id' => $ws,
                'source_type'  => 'web',
                'provider'     => 'operator',
                'source_url'   => $url,
                'source_title' => $title !== '' ? $title : $host,
                'metadata'     => [
                    'retrieval_method' => 'url_reference',
                    'fetch_status'     => 'not_fetched',
                    'intake_notes'     => self::cut($notes, 2000),
                    'entered_by'       => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                ],
            ];
        } else {
            if ($title === '') { return self::err('ves_intake_title_required', 'A title is required for a manual source.'); }
            $payload = [
                'workspace_id' => $ws,
                'source_type'  => 'manual',
                'provider'     => 'operator',
                'source_title' => $title,
                // Identity for dedup: same title+notes in a workspace is the same source.
                'external_id'  => substr(hash('sha256', $title . '|' . $notes), 0, 32),
                'raw_payload'  => $title . '|' . $notes,
                'metadata'     => [
                    'retrieval_method' => 'manual_intake',
                    'fetch_status'     => 'not_fetched',
                    'intake_notes'     => self::cut($notes, 2000),
                    'entered_by'       => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                ],
            ];
        }
        $id = VES_Intelligence_Store::create_or_get_source($payload);
        if (self::is_err($id)) { return $id; }
        return ['source_id' => (int) $id];
    }

    /**
     * Create a normalized, deterministically scored signal from an existing source.
     * @return array{signal_id:int}|WP_Error
     */
    public static function process_signal(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $source_id = (int) ($in['source_id'] ?? 0);
        $source = $source_id > 0 ? VES_Intelligence_Store::get_source($source_id) : null;
        if (!is_array($source) || empty($source['id'])) { return self::err('ves_intake_source_missing', 'Source not found. Create or pick a source first.'); }
        $in_ws = self::assert_in_workspace('source', $source, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }

        $title = self::txt($in['title'] ?? '', 255);
        if ($title === '') { return self::err('ves_intake_title_required', 'A signal needs a title (what was observed).'); }
        $stype = self::txt($in['signal_type'] ?? 'other', 40);
        $stype = in_array($stype, self::SIGNAL_TYPES, true) ? $stype : 'other';

        $occurred = '';
        $raw_occurred = trim((string) ($in['occurred_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw_occurred)) {
            $occurred = str_replace('T', ' ', $raw_occurred) . ':00';
        }

        $id = VES_Intelligence_Store::create_or_get_signal([
            'workspace_id' => $ws,
            'source_id'    => (int) $source['id'],
            'signal_type'  => $stype,
            'title'        => $title,
            'summary'      => self::long_txt($in['summary'] ?? ''),
            'value_text'   => self::txt($in['value_text'] ?? '', 120),
            'occurred_at'  => $occurred,
            'source'       => $source, // lets the store score credibility/freshness
            'metadata'     => [
                'extraction_method' => 'manual_intake',
                'entered_by'        => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            ],
        ]);
        if (self::is_err($id)) { return $id; }
        return ['signal_id' => (int) $id];
    }

    /**
     * Promote a signal into evidence + a DRAFT insight (never reviewed/approved).
     * The evidence record carries the source/signal ids and the source URL, so
     * everything downstream stays traceable. Opportunity is an insight TYPE with
     * a bounded metadata score — never a separate entity.
     * @return array{evidence_id:int,insight_id:int}|WP_Error
     */
    public static function process_signal_to_insight(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $signal_id = (int) ($in['signal_id'] ?? 0);
        $signal = $signal_id > 0 ? VES_Intelligence_Store::get_signal($signal_id) : null;
        if (!is_array($signal) || empty($signal['id'])) { return self::err('ves_intake_signal_missing', 'Signal not found. Record the signal first.'); }
        $in_ws = self::assert_in_workspace('signal', $signal, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }

        $title = self::txt($in['title'] ?? '', 255);
        if ($title === '') { return self::err('ves_intake_title_required', 'An insight needs a title (the finding).'); }
        $itype = VES_Intelligence_Store::sanitize_insight_type($in['insight_type'] ?? 'other');

        $source = VES_Intelligence_Store::get_source((int) ($signal['source_id'] ?? 0));
        $source_url = is_array($source) ? (string) ($source['source_url'] ?? '') : '';
        $source_label = is_array($source) ? self::txt(($source['source_title'] ?? '') !== '' ? $source['source_title'] : ($source['provider'] ?? 'unknown'), 190) : 'unknown';

        $evidence_text = 'Signal: ' . (string) ($signal['title'] ?? '');
        if ((string) ($signal['summary'] ?? '') !== '') { $evidence_text .= "\nSummary: " . (string) $signal['summary']; }
        if ((string) ($signal['value_text'] ?? '') !== '') { $evidence_text .= "\nValue: " . (string) $signal['value_text']; }

        $evidence_id = VES_Intelligence_Store::create_evidence([
            'workspace_id'    => $ws,
            'source_id'       => (int) ($signal['source_id'] ?? 0),
            'signal_id'       => (int) $signal['id'],
            'evidence_type'   => 'observation',
            'text'            => self::cut($evidence_text, self::MAX_NOTES_CHARS),
            'source_url'      => $source_url,
            'source_label'    => $source_label,
            'observed_at'     => (string) ($signal['occurred_at'] ?? ''),
            'confidence_score' => (float) ($signal['confidence_score'] ?? 0),
            'metadata'        => ['created_via' => 'intake_promotion', 'signal_id' => (int) $signal['id']],
        ]);
        if (self::is_err($evidence_id)) { return $evidence_id; }

        $meta = ['created_via' => 'intake_promotion', 'source_signal_id' => (int) $signal['id']];
        if ($itype === 'opportunity') {
            $meta['opportunity_score'] = VES_Intelligence_Store::sanitize_opportunity_score($in['opportunity_score'] ?? 0);
        }
        $insight_id = VES_Intelligence_Store::create_insight([
            'workspace_id'   => $ws,
            'insight_type'   => $itype,
            'title'          => $title,
            'summary'        => self::long_txt($in['summary'] ?? ''),
            'recommendation' => self::long_txt($in['recommendation'] ?? '', 2000),
            'status'         => 'draft', // review states advance ONLY through the audited lifecycle
            'evidence_ids'   => [(int) $evidence_id],
            'signal_ids'     => [(int) $signal['id']],
            'metadata'       => $meta,
        ]);
        if (self::is_err($insight_id)) { return $insight_id; }
        return ['evidence_id' => (int) $evidence_id, 'insight_id' => (int) $insight_id];
    }

    /**
     * Brief from an APPROVED insight only — human review always precedes a brief.
     * Evidence ids carry through via the builder (traceability preserved).
     * @return array{brief_id:int}|WP_Error
     */
    public static function process_insight_to_brief(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        if (!class_exists('VES_Insight_Brief_Builder')) { return self::err('ves_intake_builder_missing', 'Brief builder unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $insight_id = (int) ($in['insight_id'] ?? 0);
        $insight = $insight_id > 0 ? VES_Intelligence_Store::get_insight($insight_id) : null;
        if (!is_array($insight) || empty($insight['id'])) { return self::err('ves_intake_insight_missing', 'Insight not found.'); }
        $in_ws = self::assert_in_workspace('insight', $insight, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        if ((string) ($insight['status'] ?? '') !== 'approved') {
            return self::err('ves_intake_insight_not_approved', 'Only an APPROVED insight can become a brief — approve it in review first.');
        }
        $brief_id = VES_Insight_Brief_Builder::create_brief_from_insight($insight_id);
        if (self::is_err($brief_id)) { return $brief_id; }
        return ['brief_id' => (int) $brief_id];
    }

    /**
     * Draft preview as a real loop ACTION: build the prompt package for the
     * brief (no provider call, ever) and ledger ONE usage event for it —
     * idempotent via run_id, so a double-click can never double-charge the
     * ledger. Returns the brief id + the usage event id.
     * @return array{brief_id:int,usage_event_id:int,reused_event:bool}|WP_Error
     */
    public static function process_prompt_preview(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        if (!class_exists('VES_Generation_Prompt_Package_Builder')) { return self::err('ves_intake_builder_missing', 'Prompt package builder unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $brief_id = (int) ($in['brief_id'] ?? 0);
        $brief = $brief_id > 0 ? VES_Intelligence_Store::get_brief($brief_id) : null;
        if (!is_array($brief) || empty($brief['id'])) { return self::err('ves_intake_brief_missing', 'Brief not found.'); }
        $in_ws = self::assert_in_workspace('brief', $brief, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }

        $pkg = VES_Generation_Prompt_Package_Builder::build([
            'workspace_id' => $ws, 'use_case' => 'draft_generation', 'target_type' => 'brief', 'target_id' => $brief_id,
        ]);
        if (!is_array($pkg) || ($pkg['build_status'] ?? '') !== 'ready') {
            return self::err('ves_intake_preview_blocked', 'The prompt package is blocked: ' . (string) ($pkg['blocking_reason'] ?? 'unknown') . '. Approve the brief first.');
        }

        // Idempotent ledger write: one preview event per brief per operator.
        $run_id = 'preview-brief-' . $brief_id . '-u' . (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $event_id = 0; $reused = false;
        if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'table_name')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $event_id = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . VES_AI_Usage_Tracker::table_name() . ' WHERE run_id = %s AND operation_type = %s ORDER BY id ASC LIMIT 1',
                    $run_id, 'prompt_preview'
                ));
                $reused = $event_id > 0;
            }
        }
        if ($event_id <= 0 && class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'record')) {
            $event_id = (int) VES_AI_Usage_Tracker::record([
                'provider' => 'none', 'model' => '', 'status' => 'completed',
                'module' => 'core_loop', 'operation_type' => 'prompt_preview',
                'workspace_id' => $ws, 'run_id' => $run_id,
                'input_tokens' => 0, 'output_tokens' => 0,
            ]);
        }
        return ['brief_id' => $brief_id, 'usage_event_id' => $event_id, 'reused_event' => $reused];
    }

    /**
     * Memory candidate from an APPROVED insight — the human-review boundary
     * holds: the service forces candidate status and dedupes; nothing enters
     * trusted context without a separate approval.
     * @return array{memory_id:int}|WP_Error
     */
    public static function process_memory_candidate(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        if (!class_exists('VES_Brand_Context_Service') || !method_exists('VES_Brand_Context_Service', 'create_candidate')) {
            return self::err('ves_intake_memory_unavailable', 'Memory candidate service unavailable.');
        }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $insight_id = (int) ($in['insight_id'] ?? 0);
        $insight = $insight_id > 0 ? VES_Intelligence_Store::get_insight($insight_id) : null;
        if (!is_array($insight) || empty($insight['id'])) { return self::err('ves_intake_insight_missing', 'Insight not found.'); }
        $in_ws = self::assert_in_workspace('insight', $insight, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        if ((string) ($insight['status'] ?? '') !== 'approved') {
            return self::err('ves_intake_insight_not_approved', 'Only an APPROVED insight can propose a memory candidate.');
        }
        $summary = self::cut(trim((string) ($insight['title'] ?? '') . ' — ' . (string) ($insight['summary'] ?? '')), 500);
        $memory_id = (int) VES_Brand_Context_Service::create_candidate($ws, [
            'record_type' => 'review_learning',
            'summary' => $summary,
            'source_target_type' => 'insight',
            'source_target_id' => $insight_id,
            'importance_score' => 60,
        ], function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        if ($memory_id <= 0) { return self::err('ves_intake_memory_failed', 'The memory candidate could not be saved.'); }
        return ['memory_id' => $memory_id];
    }

    // ── admin-post wrappers: capability + nonce + redirect ─────────────────────

    public static function handle_source()            { self::handle(self::ACTION_SOURCE,  'process_source',            'source_created',  'source_id'); }
    public static function handle_signal()            { self::handle(self::ACTION_SIGNAL,  'process_signal',            'signal_created',  'signal_id'); }
    public static function handle_signal_to_insight() { self::handle(self::ACTION_INSIGHT, 'process_signal_to_insight', 'insight_created', 'insight_id'); }
    public static function handle_insight_to_brief()  { self::handle(self::ACTION_BRIEF,   'process_insight_to_brief',  'brief_created',   'brief_id'); }
    public static function handle_memory_candidate()  { self::handle(self::ACTION_MEMORY,  'process_memory_candidate',  'memory_candidate_created', 'memory_id'); }

    /** Preview lands the operator ON the preview (draft workbench), not back on intake. */
    public static function handle_prompt_preview() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die(self::e('Insufficient permissions.'), '', ['response' => 403]); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION_PREVIEW); }
        $in = isset($_POST) && is_array($_POST) ? $_POST : [];
        $in = function_exists('wp_unslash') ? wp_unslash($in) : $in;
        $res = self::process_prompt_preview($in);
        if (self::is_err($res)) {
            $args = ['page' => self::PAGE_SLUG, 'workspace_id' => max(1, (int) ($in['workspace_id'] ?? 1)),
                     'fi_err' => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $res->get_error_code()))];
        } else {
            $args = ['page' => 'fi-draft-workbench', 'workspace_id' => max(1, (int) ($in['workspace_id'] ?? 1)),
                     'brief_id' => (int) $res['brief_id'], 'fi_notice' => 'preview_recorded', 'fi_id' => (int) $res['usage_event_id']];
        }
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $url = function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        if (!defined('VES_INTAKE_NO_EXIT')) { exit; }
    }

    private static function handle($action, $method, $success_key, $id_key) {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die(self::e('Insufficient permissions.'), '', ['response' => 403]); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer($action); }
        $in = isset($_POST) && is_array($_POST) ? $_POST : [];
        $in = function_exists('wp_unslash') ? wp_unslash($in) : $in;
        $res = self::$method($in);
        $args = ['page' => self::PAGE_SLUG, 'workspace_id' => max(1, (int) ($in['workspace_id'] ?? 1))];
        if (self::is_err($res)) {
            $args['fi_err'] = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $res->get_error_code()));
            // Observability: WHY a loop action failed — error code only, never payloads.
            if (class_exists('VES_Log') && method_exists('VES_Log', 'warn')) {
                VES_Log::warn('intake', 'Intake action refused', ['action' => $action, 'error_code' => $args['fi_err'], 'workspace_id' => $args['workspace_id']]);
            }
        } else {
            $args['fi_notice'] = $success_key;
            $args['fi_id'] = (int) ($res[$id_key] ?? 0);
        }
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $url = function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        if (!defined('VES_INTAKE_NO_EXIT')) { exit; }
    }

    // ── Page render (read-only except the nonce'd forms) ───────────────────────

    public static function render_page() {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return; }
        echo self::render_html(self::request_workspace());
    }

    private static function request_workspace() {
        $ws = isset($_GET['workspace_id']) ? (int) $_GET['workspace_id'] : 0;
        if ($ws <= 0 && class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'workspace_id_for_user') && function_exists('get_current_user_id')) {
            $ws = (int) VES_Memory_Records::workspace_id_for_user(get_current_user_id());
        }
        return $ws > 0 ? $ws : 1;
    }

    /** Fixed code → operator message map; unknown codes get the generic line (no raw echo). */
    private static function notice_html() {
        $notice = isset($_GET['fi_notice']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $_GET['fi_notice'])) : '';
        $err    = isset($_GET['fi_err']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['fi_err'])) : '';
        $id     = isset($_GET['fi_id']) ? (int) $_GET['fi_id'] : 0;
        if ($notice !== '') {
            $map = [
                'source_created'  => 'Source recorded',
                'signal_created'  => 'Signal recorded',
                'insight_created' => 'Evidence + draft insight created',
                'brief_created'   => 'Brief created from the approved insight',
                'memory_candidate_created' => 'Memory candidate proposed — it stays CANDIDATE until approved on the memory page',
            ];
            if (isset($map[$notice])) {
                return '<div class="notice notice-success"><p>' . self::e($map[$notice] . ($id > 0 ? ' (id ' . $id . ').' : '.')) . '</p></div>';
            }
            return '';
        }
        if ($err !== '') {
            $map = [
                'ves_intake_workspace'            => 'A positive workspace id is required.',
                'ves_intake_title_required'       => 'A title is required.',
                'ves_intake_bad_url'              => 'The URL must be plain http(s) without embedded credentials. It is recorded as a reference only — never fetched.',
                'ves_intake_source_missing'       => 'Source not found — create or pick a source first.',
                'ves_intake_signal_missing'       => 'Signal not found — record the signal first.',
                'ves_intake_insight_missing'      => 'Insight not found.',
                'ves_intake_insight_not_approved' => 'Only an APPROVED insight can become a brief — approve it in review first.',
                'ves_workspace_mismatch'          => 'That object belongs to a different workspace.',
                'ves_brief_no_evidence'           => 'The insight has no linked evidence, so no brief can be built from it.',
                'ves_intake_brief_missing'        => 'Brief not found.',
                'ves_intake_preview_blocked'      => 'The prompt package is blocked — approve the brief first, then preview.',
                'ves_intake_memory_unavailable'   => 'Memory candidate service unavailable on this install.',
                'ves_intake_memory_failed'        => 'The memory candidate could not be saved.',
            ];
            $msg = isset($map[$err]) ? $map[$err] : 'The action could not be completed (' . $err . ').';
            return '<div class="notice notice-error"><p>' . self::e($msg) . '</p></div>';
        }
        return '';
    }

    /** Intake page URL for a workspace (prefill links build on it). */
    private static function page_url($ws, array $extra = []) {
        $args = array_merge(['page' => self::PAGE_SLUG, 'workspace_id' => max(1, (int) $ws)], $extra);
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        return function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
    }

    /** Load + workspace-check a prefill target from a GET id; null when invalid. */
    private static function prefill_row($entity, $param, $ws) {
        $id = isset($_GET[$param]) ? max(0, (int) $_GET[$param]) : 0;
        if ($id <= 0 || !class_exists('VES_Intelligence_Store')) { return null; }
        $row = $entity === 'signal' ? VES_Intelligence_Store::get_signal($id) : VES_Intelligence_Store::get_source($id);
        if (!is_array($row) || empty($row['id']) || (int) ($row['workspace_id'] ?? 0) !== (int) $ws) { return null; }
        return $row;
    }

    /**
     * Next-action panel: the single most useful step, derived from real counts.
     * Teaches the loop instead of decorating it.
     */
    private static function next_action_panel($ws) {
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'counts')) { return ''; }
        try {
            $c = (array) VES_Intelligence_Store::counts($ws);
            $by_status = method_exists('VES_Intelligence_Store', 'count_insights_by_status') ? (array) VES_Intelligence_Store::count_insights_by_status($ws) : [];
        } catch (\Throwable $e) { return ''; }
        $sources = (int) ($c['sources'] ?? 0); $signals = (int) ($c['signals'] ?? 0);
        $insights = (int) ($c['insights'] ?? 0); $briefs = (int) ($c['briefs'] ?? 0);
        $approved = (int) ($by_status['approved'] ?? 0);
        if ($sources === 0)      { $next = ['Record your first source — a note or a URL reference.', '#fi-intake-source-form', 'Record a source']; }
        elseif ($signals === 0)  { $next = ['You have ' . $sources . ' source(s). Record what one of them actually showed.', '#fi-intake-signal-form', 'Record a signal']; }
        elseif ($insights === 0) { $next = ['Promote a signal to evidence + a draft insight when a finding emerges.', '#fi-intake-insight-form', 'Promote a signal']; }
        elseif ($approved === 0) { $next = ['A draft insight is waiting for human review — open it in the workbench from the archive below.', '#fi-intake-recent', 'Open the archive']; }
        elseif ($briefs === 0)   { $next = ['An approved insight can become a brief — use its row action in the archive below.', '#fi-intake-recent', 'Build a brief']; }
        else                     { $next = ['The loop is flowing. Review briefs in the workbench and record prompt previews from the archive below.', '#fi-intake-recent', 'Open the archive']; }
        return '<aside class="fi-intake-next" aria-label="' . self::ea('Next action') . '">'
            . '<span class="fi-intake-next-k">' . self::e('Next') . '</span>'
            . '<p>' . self::e($next[0]) . '</p>'
            . '<a class="button button-secondary" href="' . self::ea($next[1]) . '">' . self::e($next[2]) . '</a></aside>';
    }

    public static function render_html($workspace_id) {
        $ws = max(1, (int) $workspace_id);
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function ($action) {
            if (function_exists('wp_nonce_field')) { return wp_nonce_field($action, '_wpnonce', true, false); }
            return '';
        };
        $ws_field = '<label>Workspace <input type="number" name="workspace_id" min="1" value="' . self::ea((string) $ws) . '" required></label>';
        $pre_source = self::prefill_row('source', 'prefill_source', $ws);
        $pre_signal = self::prefill_row('signal', 'prefill_signal', $ws);

        $h  = '<div class="wrap ves-wrap fi-intake-page">';
        $h .= '<a class="fi-skip-link" href="#fi-intake-recent">' . self::e('Skip to the archive') . '</a>';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Intake') . '</p>';
        $h .= '<h1>' . self::e('Intake') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Evidence first. Everything below records what YOU observed — nothing is fetched, nothing is generated, nothing is approved automatically.') . '</p>';
        $h .= self::notice_html();

        // Route spine — where intake sits in the loop, with live counts.
        $counts = [];
        if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'counts')) {
            try { $counts = (array) VES_Intelligence_Store::counts($ws); } catch (\Throwable $e) { $counts = []; }
        }
        $h .= '<nav class="fi-workflow-spine fi-intake-spine" aria-label="' . self::ea('Intake route') . '"><ol>';
        foreach (['Source' => 'sources', 'Signal' => 'signals', 'Insight' => 'insights', 'Brief' => 'briefs', 'Preview' => null] as $label => $ck) {
            $val = $ck !== null && array_key_exists($ck, $counts) ? (string) (int) $counts[$ck] : '—';
            $h .= '<li class="fiis-sr-step"><span class="fiis-sr-step-label">' . self::e($label) . '</span><span class="fiis-sr-step-count">' . self::e($val) . '</span></li>';
        }
        $h .= '</ol></nav>';

        $h .= self::next_action_panel($ws);

        // 01/02 — the two ways material enters (side by side on wide screens).
        $h .= '<div class="fi-intake-grid" id="fi-intake-source-form">';
        $h .= '<section class="fi-intake-card"><p class="fi-intake-no">' . self::e('01 · Source') . '</p><h2>' . self::e('Manual note') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('An interview note, a meeting takeaway, a field observation. Same title + notes dedupes to one source.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SOURCE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SOURCE) . '"><input type="hidden" name="intake_type" value="manual">';
        $h .= '<p>' . $ws_field . '</p>';
        $h .= '<p><label>Title<br><input type="text" name="source_title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Notes<br><textarea name="notes" rows="4" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record source') . '</button></p>';
        $h .= '</form></section>';

        $h .= '<section class="fi-intake-card"><p class="fi-intake-no">' . self::e('02 · Source') . '</p><h2>' . self::e('URL reference') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('The URL is recorded as a reference only — it is never fetched, crawled, or scraped from here.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SOURCE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SOURCE) . '"><input type="hidden" name="intake_type" value="url">';
        $h .= '<p>' . $ws_field . '</p>';
        $h .= '<p><label>URL<br><input type="url" name="source_url" maxlength="2000" required class="regular-text" placeholder="https://"></label></p>';
        $h .= '<p><label>Title (optional)<br><input type="text" name="source_title" maxlength="255" class="regular-text"></label></p>';
        $h .= '<p><label>Notes<br><textarea name="notes" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record URL reference') . '</button></p>';
        $h .= '</form></section>';
        $h .= '</div>';

        // 03 — signal from a source (prefilled from a row action when present).
        $h .= '<section class="fi-intake-card" id="fi-intake-signal-form"><p class="fi-intake-no">' . self::e('03 · Signal') . '</p><h2>' . self::e('What did the source show?') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('The store normalizes, dedupes and scores it deterministically — no AI involved.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SIGNAL);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SIGNAL) . '">';
        if (is_array($pre_source)) {
            $h .= '<p class="fi-intake-prefill">' . self::e('From source #' . (int) $pre_source['id'] . ' — “' . self::cut((string) ($pre_source['source_title'] ?? ''), 80) . '”')
                . ' <a href="' . self::eu(self::page_url($ws)) . '">' . self::e('change') . '</a></p>'
                . '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">'
                . '<input type="hidden" name="source_id" value="' . self::ea((string) (int) $pre_source['id']) . '">';
        } else {
            $h .= '<p>' . $ws_field . ' <label>Source id <input type="number" name="source_id" min="1" required></label></p>';
        }
        $h .= '<p><label>Type <select name="signal_type">';
        foreach (self::SIGNAL_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '">' . self::e($t) . '</option>'; }
        $h .= '</select></label></p>';
        $h .= '<p><label>Title (what was observed)<br><input type="text" name="title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Summary<br><textarea name="summary" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Value (optional, e.g. a number or short quote) <input type="text" name="value_text" maxlength="120"></label> ';
        $h .= '<label>Observed at <input type="datetime-local" name="occurred_at"></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record signal') . '</button></p>';
        $h .= '</form></section>';

        // 04 — evidence + draft insight from a signal (prefilled from a row action).
        $h .= '<section class="fi-intake-card" id="fi-intake-insight-form"><p class="fi-intake-no">' . self::e('04 · Insight') . '</p><h2>' . self::e('Promote a signal to evidence + draft insight') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Creates a traceable evidence record from the signal and a DRAFT insight linked to it. Review states only advance through the audited lifecycle — never from here.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_INSIGHT);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_INSIGHT) . '">';
        if (is_array($pre_signal)) {
            $h .= '<p class="fi-intake-prefill">' . self::e('From signal #' . (int) $pre_signal['id'] . ' — “' . self::cut((string) ($pre_signal['title'] ?? ''), 80) . '”')
                . ' <a href="' . self::eu(self::page_url($ws)) . '">' . self::e('change') . '</a></p>'
                . '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">'
                . '<input type="hidden" name="signal_id" value="' . self::ea((string) (int) $pre_signal['id']) . '">';
        } else {
            $h .= '<p>' . $ws_field . ' <label>Signal id <input type="number" name="signal_id" min="1" required></label></p>';
        }
        $h .= '<p><label>Insight type <select name="insight_type">';
        foreach (VES_Intelligence_Store::INSIGHT_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '"' . ($t === 'opportunity' ? ' selected' : '') . '>' . self::e($t) . '</option>'; }
        $h .= '</select></label> ';
        $h .= '<label>Opportunity score (0–100, used when type is opportunity) <input type="number" name="opportunity_score" min="0" max="100" value="0"></label></p>';
        $h .= '<p><label>Insight title (the finding)<br><input type="text" name="title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Summary<br><textarea name="summary" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Recommendation (optional)<br><textarea name="recommendation" rows="2" maxlength="2000" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Create evidence + draft insight') . '</button></p>';
        $h .= '</form></section>';

        // 05 — ID fallback only: the archive's row actions are the normal path.
        $h .= '<section class="fi-intake-card fi-intake-fallback" id="fi-intake-brief-form"><p class="fi-intake-no">' . self::e('05 · Brief — ID fallback') . '</p><h2>' . self::e('Build a brief by insight id') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Normal path: use the “Build brief” row action in the archive below. This form is the debugging fallback. Only an APPROVED insight can become a brief; its evidence links carry through.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_BRIEF);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_BRIEF) . '">';
        $h .= '<p>' . $ws_field . ' <label>Insight id <input type="number" name="insight_id" min="1" required></label> ';
        $h .= '<button type="submit" class="button button-secondary">' . self::e('Build brief') . '</button></p>';
        $h .= '</form></section>';

        $h .= self::recent_objects($ws);
        $h .= '<p class="fi-memory-policy">' . self::e('No AI generation, no auto-approval, no publishing, no fetching. Memory is not evidence.') . '</p>';
        $h .= '</div>';
        return $h;
    }

    /** A small nonce'd one-button POST form for a row action. */
    private static function row_action_form($action, array $hidden, $label, $primary = false) {
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field($action, '_wpnonce', true, false) : '';
        $h = '<form method="post" action="' . self::eu($post_url) . '" class="fi-row-action">' . $nonce
           . '<input type="hidden" name="action" value="' . self::ea($action) . '">';
        foreach ($hidden as $k => $v) {
            $h .= '<input type="hidden" name="' . self::ea((string) $k) . '" value="' . self::ea((string) $v) . '">';
        }
        return $h . '<button type="submit" class="button button-small' . ($primary ? ' button-primary' : '') . '">' . self::e($label) . '</button></form>';
    }

    /** Workbench deep link (whitelisted slugs only). */
    private static function workbench_link($page, $param, $id, $ws, $label) {
        $page = in_array($page, ['fi-brief-workbench', 'fi-draft-workbench'], true) ? $page : 'fi-brief-workbench';
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $url = function_exists('add_query_arg') ? add_query_arg(['page' => $page, 'workspace_id' => max(1, (int) $ws), $param => (int) $id], $url)
            : $url . '?' . http_build_query(['page' => $page, 'workspace_id' => max(1, (int) $ws), $param => (int) $id]);
        return '<a class="button button-small" href="' . self::eu($url) . '">' . self::e($label) . '</a>';
    }

    /**
     * The archive — recent objects with traceability columns AND the row
     * actions that drive the loop (no ID copying on the normal path).
     */
    private static function recent_objects($ws) {
        if (!class_exists('VES_Intelligence_Store')) { return ''; }
        $h = '<section class="fi-intake-card fi-intake-archive" id="fi-intake-recent"><p class="fi-intake-no">' . self::e('Archive') . '</p><h2>' . self::e('Recent objects — workspace ' . (int) $ws) . '</h2>';
        try {
            $sources = (array) VES_Intelligence_Store::list_sources(['workspace_id' => $ws, 'limit' => 8]);
            $signals = (array) VES_Intelligence_Store::list_signals(['workspace_id' => $ws, 'limit' => 8]);
            $insights = (array) VES_Intelligence_Store::list_insights(['workspace_id' => $ws, 'limit' => 8]);
            $briefs = (array) VES_Intelligence_Store::list_briefs(['workspace_id' => $ws, 'limit' => 8]);
        } catch (\Throwable $e) {
            return $h . '<p>' . self::e('Recent objects unavailable.') . '</p></section>';
        }
        $badge = function ($state, $label = null) {
            return class_exists('VES_Review_State') ? VES_Review_State::badge($state, $label) : self::e($label !== null ? $label : (string) $state);
        };

        $h .= '<h3>' . self::e('Sources') . '</h3>';
        if (count($sources) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No sources yet — record one above.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>type</th><th>title</th><th>reference</th><th>' . self::e('action') . '</th></tr></thead><tbody>';
            foreach ($sources as $s) {
                $sid = (int) ($s['id'] ?? 0);
                $url = (string) ($s['source_url'] ?? '');
                $act = '<a class="button button-small" href="' . self::eu(self::page_url($ws, ['prefill_source' => $sid]) . '#fi-intake-signal-form') . '">' . self::e('Record signal →') . '</a>';
                $h .= '<tr><td>' . self::e((string) $sid) . '</td><td>' . self::e((string) ($s['source_type'] ?? '')) . '</td><td>' . self::e((string) ($s['source_title'] ?? '')) . '</td><td>' . ($url !== '' ? '<span class="fi-intake-mono">' . self::e(self::cut($url, 80)) . '</span>' : self::e('—')) . '</td><td>' . $act . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Signals') . '</h3>';
        if (count($signals) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No signals yet — a signal records what a source showed.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>source</th><th>type</th><th>title</th><th>seen</th><th>' . self::e('action') . '</th></tr></thead><tbody>';
            foreach ($signals as $s) {
                $gid = (int) ($s['id'] ?? 0);
                $act = '<a class="button button-small" href="' . self::eu(self::page_url($ws, ['prefill_signal' => $gid]) . '#fi-intake-insight-form') . '">' . self::e('Promote →') . '</a>';
                $h .= '<tr><td>' . self::e((string) $gid) . '</td><td>' . self::e('#' . (int) ($s['source_id'] ?? 0)) . '</td><td>' . self::e((string) ($s['signal_type'] ?? '')) . '</td><td>' . self::e((string) ($s['title'] ?? '')) . '</td><td>' . self::e((string) (int) ($s['recurrence_count'] ?? 1) . '×') . '</td><td>' . $act . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Insights') . '</h3>';
        if (count($insights) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No insights yet — promote a signal when a finding emerges.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>type</th><th>title</th><th>state</th><th>evidence</th><th>' . self::e('actions') . '</th></tr></thead><tbody>';
            foreach ($insights as $i) {
                $iid = (int) ($i['id'] ?? 0);
                $status = (string) ($i['status'] ?? 'draft');
                $pstate = method_exists('VES_Intelligence_Store', 'insight_presentation_state') ? VES_Intelligence_Store::insight_presentation_state($i) : $status;
                $evi = is_array($i['evidence_ids'] ?? null) ? count($i['evidence_ids']) : 0;
                $acts = self::workbench_link('fi-brief-workbench', 'insight_id', $iid, $ws, 'Workbench');
                if ($status === 'approved') {
                    $acts .= ' ' . self::row_action_form(self::ACTION_BRIEF, ['workspace_id' => $ws, 'insight_id' => $iid], 'Build brief', true);
                    $acts .= ' ' . self::row_action_form(self::ACTION_MEMORY, ['workspace_id' => $ws, 'insight_id' => $iid], 'Memory candidate');
                }
                $h .= '<tr><td>' . self::e((string) $iid) . '</td><td>' . self::e((string) ($i['insight_type'] ?? '')) . '</td><td>' . self::e((string) ($i['title'] ?? '')) . '</td><td>' . $badge($pstate) . '</td><td>' . self::e($evi . ' linked') . '</td><td class="fi-intake-actions">' . $acts . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Briefs') . '</h3>';
        if (count($briefs) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No briefs yet — approve an insight, then use its “Build brief” row action.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>insight</th><th>title</th><th>state</th><th>' . self::e('actions') . '</th></tr></thead><tbody>';
            foreach ($briefs as $b) {
                $bid = (int) ($b['id'] ?? 0);
                $acts = self::workbench_link('fi-draft-workbench', 'brief_id', $bid, $ws, 'Workbench');
                $acts .= ' ' . self::row_action_form(self::ACTION_PREVIEW, ['workspace_id' => $ws, 'brief_id' => $bid], 'Preview + usage');
                $h .= '<tr><td>' . self::e((string) $bid) . '</td><td>' . self::e('#' . (int) ($b['insight_id'] ?? 0)) . '</td><td>' . self::e((string) ($b['title'] ?? '')) . '</td><td>' . $badge((string) ($b['status'] ?? 'draft')) . '</td><td class="fi-intake-actions">' . $acts . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</section>';
        return $h;
    }
}
