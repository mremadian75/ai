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

    // ── admin-post wrappers: capability + nonce + redirect ─────────────────────

    public static function handle_source()            { self::handle(self::ACTION_SOURCE,  'process_source',            'source_created',  'source_id'); }
    public static function handle_signal()            { self::handle(self::ACTION_SIGNAL,  'process_signal',            'signal_created',  'signal_id'); }
    public static function handle_signal_to_insight() { self::handle(self::ACTION_INSIGHT, 'process_signal_to_insight', 'insight_created', 'insight_id'); }
    public static function handle_insight_to_brief()  { self::handle(self::ACTION_BRIEF,   'process_insight_to_brief',  'brief_created',   'brief_id'); }

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
            ];
            $msg = isset($map[$err]) ? $map[$err] : 'The action could not be completed (' . $err . ').';
            return '<div class="notice notice-error"><p>' . self::e($msg) . '</p></div>';
        }
        return '';
    }

    public static function render_html($workspace_id) {
        $ws = max(1, (int) $workspace_id);
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function ($action) {
            if (function_exists('wp_nonce_field')) { return wp_nonce_field($action, '_wpnonce', true, false); }
            return '';
        };
        $ws_field = '<label>Workspace <input type="number" name="workspace_id" min="1" value="' . self::ea((string) $ws) . '" required></label>';

        $h  = '<div class="wrap ves-wrap fi-intake-page">';
        $h .= '<a class="fi-skip-link" href="#fi-intake-recent">' . self::e('Skip to recent objects') . '</a>';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Intake') . '</p>';
        $h .= '<h1>' . self::e('Intake') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Evidence first. Everything below records what YOU observed — nothing is fetched, nothing is generated, nothing is approved automatically.') . '</p>';
        $h .= self::notice_html();

        // 1. Manual source
        $h .= '<section class="fi-intake-card"><h2>' . self::e('1 · Record a manual source') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('An interview note, a meeting takeaway, a field observation. Same title + notes in a workspace dedupes to one source.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SOURCE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SOURCE) . '"><input type="hidden" name="intake_type" value="manual">';
        $h .= '<p>' . $ws_field . '</p>';
        $h .= '<p><label>Title<br><input type="text" name="source_title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Notes<br><textarea name="notes" rows="4" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record source') . '</button></p>';
        $h .= '</form></section>';

        // 2. URL-record source
        $h .= '<section class="fi-intake-card"><h2>' . self::e('2 · Record a URL reference') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('The URL is recorded as a reference only — it is never fetched, crawled, or scraped from here.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SOURCE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SOURCE) . '"><input type="hidden" name="intake_type" value="url">';
        $h .= '<p>' . $ws_field . '</p>';
        $h .= '<p><label>URL<br><input type="url" name="source_url" maxlength="2000" required class="regular-text" placeholder="https://"></label></p>';
        $h .= '<p><label>Title (optional)<br><input type="text" name="source_title" maxlength="255" class="regular-text"></label></p>';
        $h .= '<p><label>Notes<br><textarea name="notes" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record URL reference') . '</button></p>';
        $h .= '</form></section>';

        // 3. Signal from source
        $h .= '<section class="fi-intake-card"><h2>' . self::e('3 · Record a signal from a source') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('What did the source actually show? The store normalizes, dedupes and scores it deterministically — no AI involved.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SIGNAL);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SIGNAL) . '">';
        $h .= '<p>' . $ws_field . ' <label>Source id <input type="number" name="source_id" min="1" required></label> ';
        $h .= '<label>Type <select name="signal_type">';
        foreach (self::SIGNAL_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '">' . self::e($t) . '</option>'; }
        $h .= '</select></label></p>';
        $h .= '<p><label>Title (what was observed)<br><input type="text" name="title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Summary<br><textarea name="summary" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Value (optional, e.g. a number or short quote) <input type="text" name="value_text" maxlength="120"></label> ';
        $h .= '<label>Observed at <input type="datetime-local" name="occurred_at"></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record signal') . '</button></p>';
        $h .= '</form></section>';

        // 4. Evidence + draft insight from signal
        $h .= '<section class="fi-intake-card"><h2>' . self::e('4 · Promote a signal to evidence + draft insight') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Creates a traceable evidence record from the signal and a DRAFT insight linked to it. Review states only advance through the audited lifecycle — never from here.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_INSIGHT);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_INSIGHT) . '">';
        $h .= '<p>' . $ws_field . ' <label>Signal id <input type="number" name="signal_id" min="1" required></label> ';
        $h .= '<label>Insight type <select name="insight_type">';
        foreach (VES_Intelligence_Store::INSIGHT_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '"' . ($t === 'opportunity' ? ' selected' : '') . '>' . self::e($t) . '</option>'; }
        $h .= '</select></label> ';
        $h .= '<label>Opportunity score (0–100, used when type is opportunity) <input type="number" name="opportunity_score" min="0" max="100" value="0"></label></p>';
        $h .= '<p><label>Insight title (the finding)<br><input type="text" name="title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Summary<br><textarea name="summary" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Recommendation (optional)<br><textarea name="recommendation" rows="2" maxlength="2000" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Create evidence + draft insight') . '</button></p>';
        $h .= '</form></section>';

        // 5. Brief from APPROVED insight
        $h .= '<section class="fi-intake-card"><h2>' . self::e('5 · Build a brief from an APPROVED insight') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Human review always comes first: only an approved insight can become a brief. Its evidence links carry through to the brief.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_BRIEF);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_BRIEF) . '">';
        $h .= '<p>' . $ws_field . ' <label>Insight id <input type="number" name="insight_id" min="1" required></label> ';
        $h .= '<button type="submit" class="button button-primary">' . self::e('Build brief') . '</button></p>';
        $h .= '</form></section>';

        $h .= self::recent_objects($ws);
        $h .= '<p class="fi-memory-policy">' . self::e('No AI generation, no auto-approval, no publishing, no fetching. Memory is not evidence.') . '</p>';
        $h .= '</div>';
        return $h;
    }

    /** Read-only recent-objects tables with traceability columns. */
    private static function recent_objects($ws) {
        if (!class_exists('VES_Intelligence_Store')) { return ''; }
        $h = '<section class="fi-intake-card" id="fi-intake-recent"><h2>' . self::e('Recent objects (workspace ' . (int) $ws . ')') . '</h2>';
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
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>type</th><th>title</th><th>reference</th></tr></thead><tbody>';
            foreach ($sources as $s) {
                $url = (string) ($s['source_url'] ?? '');
                $h .= '<tr><td>' . self::e((string) (int) ($s['id'] ?? 0)) . '</td><td>' . self::e((string) ($s['source_type'] ?? '')) . '</td><td>' . self::e((string) ($s['source_title'] ?? '')) . '</td><td>' . ($url !== '' ? '<span class="fi-intake-mono">' . self::e(self::cut($url, 80)) . '</span>' : self::e('—')) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Signals') . '</h3>';
        if (count($signals) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No signals yet — a signal records what a source showed.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>source</th><th>type</th><th>title</th><th>seen</th></tr></thead><tbody>';
            foreach ($signals as $s) {
                $h .= '<tr><td>' . self::e((string) (int) ($s['id'] ?? 0)) . '</td><td>' . self::e('#' . (int) ($s['source_id'] ?? 0)) . '</td><td>' . self::e((string) ($s['signal_type'] ?? '')) . '</td><td>' . self::e((string) ($s['title'] ?? '')) . '</td><td>' . self::e((string) (int) ($s['recurrence_count'] ?? 1) . '×') . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Insights') . '</h3>';
        if (count($insights) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No insights yet — promote a signal when a finding emerges.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>type</th><th>title</th><th>state</th><th>evidence</th></tr></thead><tbody>';
            foreach ($insights as $i) {
                $pstate = method_exists('VES_Intelligence_Store', 'insight_presentation_state') ? VES_Intelligence_Store::insight_presentation_state($i) : (string) ($i['status'] ?? 'draft');
                $evi = is_array($i['evidence_ids'] ?? null) ? count($i['evidence_ids']) : 0;
                $h .= '<tr><td>' . self::e((string) (int) ($i['id'] ?? 0)) . '</td><td>' . self::e((string) ($i['insight_type'] ?? '')) . '</td><td>' . self::e((string) ($i['title'] ?? '')) . '</td><td>' . $badge($pstate) . '</td><td>' . self::e($evi . ' linked') . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Briefs') . '</h3>';
        if (count($briefs) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No briefs yet — approve an insight, then build its brief above.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>insight</th><th>title</th><th>state</th></tr></thead><tbody>';
            foreach ($briefs as $b) {
                $h .= '<tr><td>' . self::e((string) (int) ($b['id'] ?? 0)) . '</td><td>' . self::e('#' . (int) ($b['insight_id'] ?? 0)) . '</td><td>' . self::e((string) ($b['title'] ?? '')) . '</td><td>' . $badge((string) ($b['status'] ?? 'draft')) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</section>';
        return $h;
    }
}
