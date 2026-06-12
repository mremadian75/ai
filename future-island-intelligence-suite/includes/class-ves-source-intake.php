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
    const ACTION_DRAFT    = 'ves_brief_to_draft';
    const ACTION_MEMORY   = 'ves_draft_to_memory';
    const ACTION_USAGE    = 'ves_draft_usage_event';
    // v0.3.55: review decisions were reachable only by leaving the intake loop —
    // an operator could create a draft insight here but never approve it, which
    // blocked Insight → Brief on this surface entirely.
    const ACTION_APPROVE_INSIGHT = 'ves_intake_approve_insight';
    const ACTION_REJECT_INSIGHT  = 'ves_intake_reject_insight';
    const ACTION_APPROVE_DRAFT   = 'ves_intake_approve_draft';
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
        add_action('admin_post_' . self::ACTION_DRAFT, [__CLASS__, 'handle_brief_to_draft']);
        add_action('admin_post_' . self::ACTION_MEMORY, [__CLASS__, 'handle_draft_to_memory']);
        add_action('admin_post_' . self::ACTION_USAGE, [__CLASS__, 'handle_record_usage_event']);
        add_action('admin_post_' . self::ACTION_APPROVE_INSIGHT, [__CLASS__, 'handle_approve_insight']);
        add_action('admin_post_' . self::ACTION_REJECT_INSIGHT, [__CLASS__, 'handle_reject_insight']);
        add_action('admin_post_' . self::ACTION_APPROVE_DRAFT, [__CLASS__, 'handle_approve_draft']);
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
     * Create a deterministic Google Ads / prompt-preview draft from a brief.
     * This is an operator bridge, not ad-platform integration and not AI generation.
     * @return array{draft_id:int}|WP_Error
     */
    public static function process_brief_to_draft(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $brief_id = (int) ($in['brief_id'] ?? 0);
        $brief = $brief_id > 0 ? VES_Intelligence_Store::get_brief($brief_id) : null;
        if (!is_array($brief) || empty($brief['id'])) { return self::err('ves_intake_brief_missing', 'Brief not found.'); }
        $in_ws = self::assert_in_workspace('brief', $brief, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        if ((string) ($brief['status'] ?? '') === 'archived') { return self::err('ves_intake_brief_archived', 'Archived briefs cannot create drafts.'); }

        $existing = self::find_existing_draft_for_brief($ws, $brief_id);
        if ($existing > 0) { return ['draft_id' => $existing]; }

        $title = self::txt('Google Ads field draft · ' . (string) ($brief['title'] ?? ('Brief #' . $brief_id)), 255);
        $key_message = self::long_txt((string) (($brief['key_message'] ?? '') ?: ($brief['objective'] ?? '')), 900);
        $audience = self::txt((string) ($brief['audience'] ?? 'reviewed audience'), 140);
        $body = self::google_ads_prompt_preview_body($brief, $key_message, $audience);
        $evidence_ids = is_array($brief['evidence_ids'] ?? null) ? array_values(array_map('intval', $brief['evidence_ids'])) : [];
        $draft_id = VES_Intelligence_Store::create_draft([
            'workspace_id'  => $ws,
            'brief_id'      => $brief_id,
            'draft_type'    => 'ad',
            'title'         => $title,
            'body'          => $body,
            'channel'       => 'google_ads',
            'model'         => 'deterministic_intake_bridge',
            'status'        => 'generated',
            'evidence_ids'  => $evidence_ids,
            'metadata'      => [
                'created_via' => 'intake_brief_to_google_ads_draft',
                'asset_status' => 'hypothesis',
                'evidence_caveat' => 'Draft field preview only. Evidence must be reviewed before paid media use.',
                'proof_needed' => 'Confirm demand/source coverage before launch.',
            ],
        ]);
        if (self::is_err($draft_id)) { return $draft_id; }
        return ['draft_id' => (int) $draft_id];
    }

    /**
     * Approve an insight through the audited lifecycle (evidence + quality
     * gates still decide — this is a doorway, not a bypass).
     * @return array{insight_id:int}|WP_Error
     */
    public static function process_approve_insight(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        if (!class_exists('VES_Insight_Lifecycle_Service')) { return self::err('ves_intake_lifecycle_missing', 'Insight lifecycle service unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $insight_id = (int) ($in['insight_id'] ?? 0);
        $insight = $insight_id > 0 ? VES_Intelligence_Store::get_insight($insight_id) : null;
        if (!is_array($insight) || empty($insight['id'])) { return self::err('ves_intake_insight_missing', 'Insight not found.'); }
        $in_ws = self::assert_in_workspace('insight', $insight, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        $result = VES_Insight_Lifecycle_Service::approve_insight($insight_id, [
            'actor' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        ]);
        if (self::is_err($result)) { return $result; }
        return ['insight_id' => $insight_id];
    }

    /** @return array{insight_id:int}|WP_Error */
    public static function process_reject_insight(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        if (!class_exists('VES_Insight_Lifecycle_Service')) { return self::err('ves_intake_lifecycle_missing', 'Insight lifecycle service unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $insight_id = (int) ($in['insight_id'] ?? 0);
        $insight = $insight_id > 0 ? VES_Intelligence_Store::get_insight($insight_id) : null;
        if (!is_array($insight) || empty($insight['id'])) { return self::err('ves_intake_insight_missing', 'Insight not found.'); }
        $in_ws = self::assert_in_workspace('insight', $insight, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        $result = VES_Insight_Lifecycle_Service::reject_insight($insight_id, self::txt($in['reason'] ?? 'Rejected from intake review.', 300));
        if (self::is_err($result)) { return $result; }
        return ['insight_id' => $insight_id];
    }

    /**
     * Approve a generated/edited draft so memory + usage steps can proceed.
     * The store's transition ledger still validates the state change.
     * @return array{draft_id:int}|WP_Error
     */
    public static function process_approve_draft(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $draft_id = (int) ($in['draft_id'] ?? 0);
        $draft = $draft_id > 0 ? VES_Intelligence_Store::get_draft($draft_id) : null;
        if (!is_array($draft) || empty($draft['id'])) { return self::err('ves_intake_draft_missing', 'Draft not found.'); }
        $in_ws = self::assert_in_workspace('draft', $draft, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        if ((string) ($draft['status'] ?? '') === 'approved') { return ['draft_id' => $draft_id]; }
        $result = VES_Intelligence_Store::update_draft_status($draft_id, 'approved', [
            'approved_via' => 'intake_review',
            'approved_by'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        ]);
        if (self::is_err($result)) { return $result; }
        return ['draft_id' => $draft_id];
    }

    /** @return array{memory_id:int}|WP_Error */
    public static function process_draft_to_memory(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $draft_id = (int) ($in['draft_id'] ?? 0);
        $draft = $draft_id > 0 ? VES_Intelligence_Store::get_draft($draft_id) : null;
        if (!is_array($draft) || empty($draft['id'])) { return self::err('ves_intake_draft_missing', 'Draft not found.'); }
        $in_ws = self::assert_in_workspace('draft', $draft, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        $existing = VES_Intelligence_Store::list_memory_records([
            'workspace_id' => $ws,
            'source_entity_type' => 'draft',
            'source_entity_id' => (string) $draft_id,
            'limit' => 1,
        ]);
        if (!empty($existing[0]['id'])) { return ['memory_id' => (int) $existing[0]['id']]; }
        $body = self::cut((string) ($draft['body'] ?? ''), 900);
        $memory_id = VES_Intelligence_Store::create_memory_record([
            'workspace_id' => $ws,
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'memory_type' => 'learning',
            'title' => self::txt('Memory candidate from draft #' . $draft_id, 240),
            'text' => self::cut('Draft memory candidate: ' . (string) ($draft['title'] ?? '') . "\n" . $body, 1800),
            'importance_score' => 55,
            'source_entity_type' => 'draft',
            'source_entity_id' => (string) $draft_id,
            'status' => 'active',
            // approval_status travels in metadata because the store's status enum
            // has no pending state; consumers must treat pending_review memory as
            // continuity context, never as approved evidence.
            'metadata' => ['created_via' => 'intake_draft_to_memory', 'requires_review' => true, 'approval_status' => 'pending_review'],
        ]);
        if (self::is_err($memory_id)) { return $memory_id; }
        return ['memory_id' => (int) $memory_id];
    }

    /** @return array{usage_event_id:int}|WP_Error */
    public static function process_record_usage_event(array $in) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_intake_store_missing', 'Intelligence store unavailable.'); }
        if (!class_exists('VES_AI_Usage_Tracker')) { return self::err('ves_intake_usage_tracker_missing', 'Usage tracker unavailable.'); }
        $ws = self::assert_workspace($in['workspace_id'] ?? 0);
        if (self::is_err($ws)) { return $ws; }
        $draft_id = (int) ($in['draft_id'] ?? 0);
        $draft = $draft_id > 0 ? VES_Intelligence_Store::get_draft($draft_id) : null;
        if (!is_array($draft) || empty($draft['id'])) { return self::err('ves_intake_draft_missing', 'Draft not found.'); }
        $in_ws = self::assert_in_workspace('draft', $draft, $ws);
        if (self::is_err($in_ws)) { return $in_ws; }
        if ((string) ($draft['status'] ?? '') !== 'approved') { return self::err('ves_intake_draft_not_approved', 'Only an approved output can record a final usage event.'); }

        $transient_key = 'ves_intake_usage_' . $ws . '_' . $draft_id;
        if (function_exists('get_transient')) {
            $existing = (int) get_transient($transient_key);
            if ($existing > 0) { return ['usage_event_id' => $existing]; }
        }
        $event_id = (int) VES_AI_Usage_Tracker::record([
            'workspace_id' => $ws,
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'module' => 'future_island_intake',
            'operation_type' => 'approved_output_usage_event',
            'run_id' => 'fi-intake-draft-' . $draft_id,
            'provider' => 'manual',
            'model' => 'operator_review',
            'status' => 'completed',
            'estimated_provider_cost' => 0,
            'actual_provider_cost' => 0,
            'estimated_credits' => 0,
            'credits_charged' => 0,
            'pricing_source' => 'unavailable',
            'metadata' => [
                'target_type' => 'draft',
                'target_id' => $draft_id,
                'created_via' => 'intake_approved_output_usage_event',
            ],
        ]);
        if ($event_id <= 0) { return self::err('ves_intake_usage_record_failed', 'Usage event could not be recorded.'); }
        if (function_exists('set_transient')) {
            $ttl = 7 * (defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400);
            set_transient($transient_key, $event_id, $ttl);
        }
        return ['usage_event_id' => $event_id];
    }

    private static function find_existing_draft_for_brief($ws, $brief_id): int {
        if (!class_exists('VES_Intelligence_Store')) { return 0; }
        $drafts = (array) VES_Intelligence_Store::list_drafts(['workspace_id' => (int) $ws, 'limit' => 200]);
        foreach ($drafts as $draft) {
            if ((int) ($draft['brief_id'] ?? 0) !== (int) $brief_id) { continue; }
            $meta = (array) ($draft['metadata'] ?? []);
            if ((string) ($meta['created_via'] ?? '') === 'intake_brief_to_google_ads_draft') { return (int) ($draft['id'] ?? 0); }
        }
        return 0;
    }

    /**
     * v0.3.55 — the copy lines are CAMPAIGN-FACING (what a consumer would see),
     * derived from the brief topic/message/audience. Workflow framing (status,
     * caveat, proof-needed) stays in the header lines, never inside the copy.
     */
    private static function google_ads_prompt_preview_body(array $brief, string $key_message, string $audience): string {
        $topic = self::campaign_topic_from_brief($brief, $key_message);
        $topic_title = preg_replace_callback('/\b[a-z]/', static function ($m) { return strtoupper($m[0]); }, strtolower($topic));
        $who = self::asset_line($audience !== '' && $audience !== 'reviewed audience' ? $audience : 'your audience', 32);
        $message = self::asset_line($key_message !== '' ? $key_message : ('Discover ' . $topic . ' content that connects'), 88);
        $headlines = [
            self::asset_line($topic_title . ' ideas', 40),
            self::asset_line('Discover ' . $topic_title, 40),
            self::asset_line('New ' . $topic . ' content', 40),
            self::asset_line($topic_title . ', made simple', 40),
            self::asset_line('Your ' . $topic . ' guide', 40),
        ];
        $long = [
            self::asset_line($message, 90),
            self::asset_line('Discover the ' . $topic . ' moments ' . $who . ' are already sharing', 90),
            self::asset_line('New ' . $topic . ' ideas, formats and stories — updated regularly', 90),
            self::asset_line('See what people are talking about around ' . $topic . ' right now', 90),
            self::asset_line('Real ' . $topic . ' stories and formats, picked for ' . $who, 90),
        ];
        $descriptions = [
            self::asset_line('Explore ' . $topic . ' ideas and find the angle that fits your brand.', 90),
            self::asset_line('Fresh ' . $topic . ' content and formats, picked for ' . $who . '.', 90),
            self::asset_line('Join the ' . $topic . ' conversation with formats people already enjoy.', 90),
            self::asset_line('New ' . $topic . ' takes worth your attention.', 90),
            self::asset_line($topic_title . ': stories, ideas and moments that matter.', 90),
        ];
        $out = [];
        $out[] = 'Google Ads asset field preview';
        $out[] = 'Status: hypothesis / draft';
        $out[] = 'Evidence caveat: draft only; unsupported claims must be reviewed before use.';
        $out[] = 'Proof needed: confirm source coverage and demand evidence before launch.';
        $out[] = 'CTA suggestion: Learn more';
        $out[] = '';
        $out[] = 'Headlines, max 40 chars:';
        foreach ($headlines as $line) { $out[] = '- ' . $line . ' [' . strlen($line) . '/40]'; }
        $out[] = '';
        $out[] = 'Long headlines, max 90 chars:';
        foreach ($long as $line) { $out[] = '- ' . $line . ' [' . strlen($line) . '/90]'; }
        $out[] = '';
        $out[] = 'Descriptions, max 90 chars:';
        foreach ($descriptions as $line) { $out[] = '- ' . $line . ' [' . strlen($line) . '/90]'; }
        return implode("\n", $out);
    }

    /** Short consumer-facing topic phrase from the brief (title without workflow prefixes). */
    private static function campaign_topic_from_brief(array $brief, string $key_message): string {
        $title = self::txt((string) ($brief['title'] ?? ''), 80);
        // Strip workflow prefixes briefs commonly carry ("Brief: …", "Insight from signal #4: …").
        $title = preg_replace('/^(brief|insight|draft|signal)[^:]*:\s*/i', '', $title);
        $title = trim(preg_replace('/\s+/', ' ', (string) $title));
        if ($title !== '' && strlen($title) <= 28) { return strtolower($title); }
        if ($title !== '') {
            $cut = substr($title, 0, 28);
            $space = strrpos($cut, ' ');
            return strtolower(trim($space !== false && $space > 3 ? substr($cut, 0, $space) : $cut));
        }
        if ($key_message !== '') { return strtolower(self::asset_line($key_message, 28)); }
        return 'new ideas';
    }

    private static function asset_line(string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
        if (strlen($text) <= $limit) { return $text; }
        return rtrim(substr($text, 0, max(0, $limit - 1))) . '…';
    }

    // ── admin-post wrappers: capability + nonce + redirect ─────────────────────

    public static function handle_source()            { self::handle(self::ACTION_SOURCE,  'process_source',            'source_created',  'source_id'); }
    public static function handle_signal()            { self::handle(self::ACTION_SIGNAL,  'process_signal',            'signal_created',  'signal_id'); }
    public static function handle_signal_to_insight() { self::handle(self::ACTION_INSIGHT, 'process_signal_to_insight', 'insight_created', 'insight_id'); }
    public static function handle_insight_to_brief()  { self::handle(self::ACTION_BRIEF,   'process_insight_to_brief',  'brief_created',   'brief_id'); }
    public static function handle_brief_to_draft()    { self::handle(self::ACTION_DRAFT,   'process_brief_to_draft',    'draft_created',   'draft_id'); }
    public static function handle_draft_to_memory()   { self::handle(self::ACTION_MEMORY,  'process_draft_to_memory',   'memory_created',  'memory_id'); }
    public static function handle_record_usage_event(){ self::handle(self::ACTION_USAGE,   'process_record_usage_event','usage_recorded',  'usage_event_id'); }
    public static function handle_approve_insight()   { self::handle(self::ACTION_APPROVE_INSIGHT, 'process_approve_insight', 'insight_approved', 'insight_id'); }
    public static function handle_reject_insight()    { self::handle(self::ACTION_REJECT_INSIGHT,  'process_reject_insight',  'insight_rejected', 'insight_id'); }
    public static function handle_approve_draft()     { self::handle(self::ACTION_APPROVE_DRAFT,   'process_approve_draft',   'draft_approved',   'draft_id'); }

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
                'insight_approved'=> 'Insight approved — it can now become a brief',
                'insight_rejected'=> 'Insight rejected',
                'brief_created'   => 'Brief created from the approved insight',
                'draft_created'   => 'Google Ads draft block created from the brief',
                'draft_approved'  => 'Draft approved — memory and usage steps unlocked',
                'memory_created'  => 'Memory candidate created from the draft (pending review)',
                'usage_recorded'  => 'Usage event recorded for the approved output',
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
                'ves_intake_brief_missing'        => 'Brief not found.',
                'ves_intake_brief_archived'       => 'Archived briefs cannot create drafts.',
                'ves_intake_draft_missing'        => 'Draft not found.',
                'ves_intake_draft_not_approved'   => 'Only an approved output can record a final usage event.',
                'ves_intake_usage_tracker_missing'=> 'Usage tracker unavailable.',
                'ves_intake_usage_record_failed'  => 'Usage event could not be recorded.',
                'ves_workspace_mismatch'          => 'That object belongs to a different workspace.',
                'ves_brief_no_evidence'           => 'The insight has no linked evidence, so no brief can be built from it.',
                'ves_intake_lifecycle_missing'    => 'Insight lifecycle service unavailable.',
                'ves_insight_evidence_required'   => 'Cannot approve: the insight does not meet the minimum evidence requirements yet. Link more evidence first.',
                'ves_insight_low_quality'         => 'Cannot approve: insight quality/confidence is below the approval threshold. Strengthen the evidence or summary first.',
                'ves_intel_transition_blocked'    => 'That status change is not allowed from the current state.',
                'ves_intel_invalid_enum'          => 'That status is not valid for this object.',
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
        $prefill_source_id = isset($_GET['prefill_source_id']) ? max(0, (int) $_GET['prefill_source_id']) : 0;
        $prefill_signal_id = isset($_GET['prefill_signal_id']) ? max(0, (int) $_GET['prefill_signal_id']) : 0;
        $prefill_insight_id = isset($_GET['prefill_insight_id']) ? max(0, (int) $_GET['prefill_insight_id']) : 0;
        $prefill_brief_id = isset($_GET['prefill_brief_id']) ? max(0, (int) $_GET['prefill_brief_id']) : 0;
        $prefill_draft_id = isset($_GET['prefill_draft_id']) ? max(0, (int) $_GET['prefill_draft_id']) : 0;

        $h  = '<div class="wrap ves-wrap fi-intake-page fi-signal-room">';
        $h .= '<a class="fi-skip-link" href="#fi-intake-recent">' . self::e('Skip to the pipeline') . '</a>';

        // Context strip: what am I looking at, in which workspace, what loop.
        $h .= '<header class="fi-room-context">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Signal room') . '</p>';
        $h .= '<h1>' . self::e('Intake & pipeline') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Source → Signal → Insight → Brief → Output → Memory → Usage. Everything records what YOU observed — nothing is fetched, generated, or approved automatically.') . '</p>';
        $h .= '<p class="fi-room-workspace">' . self::e('Workspace ' . $ws) . '</p>';
        $h .= '</header>';
        $h .= self::notice_html();

        $h .= '<div class="fi-room-grid">';

        // ── Center: the pipeline IS the workspace. Each object row carries its
        // own next-action rail, so the normal path never asks for an id.
        $h .= '<main class="fi-room-main">' . self::recent_objects($ws) . '</main>';

        // ── Right rail: add material + record a signal (the only steps that
        // genuinely need free-text entry).
        $h .= '<aside class="fi-room-rail">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Add a manual source') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('An interview note, a meeting takeaway, a field observation. Same title + notes dedupes to one source.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SOURCE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SOURCE) . '"><input type="hidden" name="intake_type" value="manual">';
        $h .= '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">';
        $h .= '<p><label>Title<br><input type="text" name="source_title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Notes<br><textarea name="notes" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record source') . '</button></p>';
        $h .= '</form></section>';

        $h .= '<section class="fi-intake-card"><h2>' . self::e('Add a URL reference') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Recorded as a reference only — never fetched, crawled, or scraped from here.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SOURCE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SOURCE) . '"><input type="hidden" name="intake_type" value="url">';
        $h .= '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">';
        $h .= '<p><label>URL<br><input type="url" name="source_url" maxlength="2000" required class="regular-text" placeholder="https://"></label></p>';
        $h .= '<p><label>Title (optional)<br><input type="text" name="source_title" maxlength="255" class="regular-text"></label></p>';
        $h .= '<p><label>Notes<br><textarea name="notes" rows="2" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record URL reference') . '</button></p>';
        $h .= '</form></section>';

        // Record a signal: source id arrives prefilled from the pipeline's
        // "Use for signal" action; typing it by hand is the fallback, not the path.
        $h .= '<section class="fi-intake-card" id="fi-intake-signal"><h2>' . self::e('Record a signal') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e($prefill_source_id > 0 ? 'Recording what source #' . $prefill_source_id . ' showed.' : 'Pick a source in the pipeline ("Use for signal") — its id arrives here prefilled.') . '</p>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_SIGNAL);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SIGNAL) . '">';
        $h .= '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">';
        $h .= '<p><label>Source id <input type="number" name="source_id" min="1" value="' . self::ea((string) $prefill_source_id) . '" required></label> ';
        $h .= '<label>Type <select name="signal_type">';
        foreach (self::SIGNAL_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '">' . self::e($t) . '</option>'; }
        $h .= '</select></label></p>';
        $h .= '<p><label>Title (what was observed)<br><input type="text" name="title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Summary<br><textarea name="summary" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Value (optional) <input type="text" name="value_text" maxlength="120"></label> ';
        $h .= '<label>Observed at <input type="datetime-local" name="occurred_at"></label></p>';
        $h .= '<p><button type="submit" class="button button-primary">' . self::e('Record signal') . '</button></p>';
        $h .= '</form></section>';
        $h .= '</aside>';
        $h .= '</div>'; // .fi-room-grid

        // ── Advanced: by-id forms are DEBUG tools, collapsed by default. The
        // normal path is the action rail on each pipeline row above.
        $h .= '<details class="fi-intake-advanced"' . (($prefill_signal_id || $prefill_insight_id || $prefill_brief_id || $prefill_draft_id) ? ' open' : '') . '>';
        $h .= '<summary>' . self::e('Advanced: run a step by object id (debug path)') . '</summary>';
        $h .= '<div class="fi-intake-advanced-grid">';

        $h .= '<section class="fi-intake-card"><h3>' . self::e('Promote a signal to evidence + draft insight') . '</h3>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_INSIGHT);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_INSIGHT) . '">';
        $h .= '<p>' . $ws_field . ' <label>Signal id <input type="number" name="signal_id" min="1" value="' . self::ea((string) $prefill_signal_id) . '" required></label> ';
        $h .= '<label>Insight type <select name="insight_type">';
        foreach (VES_Intelligence_Store::INSIGHT_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '"' . ($t === 'opportunity' ? ' selected' : '') . '>' . self::e($t) . '</option>'; }
        $h .= '</select></label> ';
        $h .= '<label>Opportunity score (0–100) <input type="number" name="opportunity_score" min="0" max="100" value="0"></label></p>';
        $h .= '<p><label>Insight title (the finding)<br><input type="text" name="title" maxlength="255" required class="regular-text"></label></p>';
        $h .= '<p><label>Summary<br><textarea name="summary" rows="3" maxlength="' . self::ea((string) self::MAX_NOTES_CHARS) . '" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Recommendation (optional)<br><textarea name="recommendation" rows="2" maxlength="2000" class="large-text"></textarea></label></p>';
        $h .= '<p><button type="submit" class="button">' . self::e('Create evidence + draft insight') . '</button></p>';
        $h .= '</form></section>';

        $h .= '<section class="fi-intake-card"><h3>' . self::e('Build a brief from an APPROVED insight') . '</h3>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_BRIEF);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_BRIEF) . '">';
        $h .= '<p>' . $ws_field . ' <label>Insight id <input type="number" name="insight_id" min="1" value="' . self::ea((string) $prefill_insight_id) . '" required></label> ';
        $h .= '<button type="submit" class="button">' . self::e('Build brief') . '</button></p>';
        $h .= '</form></section>';

        $h .= '<section class="fi-intake-card"><h3>' . self::e('Generate Google Ads draft block from a brief') . '</h3>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_DRAFT);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_DRAFT) . '">';
        $h .= '<p>' . $ws_field . ' <label>Brief id <input type="number" name="brief_id" min="1" value="' . self::ea((string) $prefill_brief_id) . '" required></label> ';
        $h .= '<button type="submit" class="button">' . self::e('Create Google Ads draft block') . '</button></p>';
        $h .= '</form></section>';

        $h .= '<section class="fi-intake-card"><h3>' . self::e('Create memory candidate from a draft') . '</h3>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_MEMORY);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_MEMORY) . '">';
        $h .= '<p>' . $ws_field . ' <label>Draft id <input type="number" name="draft_id" min="1" value="' . self::ea((string) $prefill_draft_id) . '" required></label> ';
        $h .= '<button type="submit" class="button">' . self::e('Create memory candidate') . '</button></p>';
        $h .= '</form></section>';

        $h .= '<section class="fi-intake-card"><h3>' . self::e('Record usage event for an approved output') . '</h3>';
        $h .= '<form method="post" action="' . self::eu($post_url) . '">' . $nonce(self::ACTION_USAGE);
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION_USAGE) . '">';
        $h .= '<p>' . $ws_field . ' <label>Approved draft id <input type="number" name="draft_id" min="1" value="' . self::ea((string) $prefill_draft_id) . '" required></label> ';
        $h .= '<button type="submit" class="button button-secondary">' . self::e('Record usage event') . '</button></p>';
        $h .= '</form></section>';

        $h .= '</div></details>';

        $h .= '<p class="fi-memory-policy">' . self::e('No AI generation, no auto-approval, no publishing, no fetching. Memory is not evidence.') . '</p>';
        $h .= '</div>';
        return $h;
    }

    private static function page_url($ws, array $args = []): string {
        $base = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $args = array_merge(['page' => self::PAGE_SLUG, 'workspace_id' => max(1, (int) $ws)], $args);
        return function_exists('add_query_arg') ? add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }

    /** Deep link into the brief/draft workbench pages registered by VES_Admin. */
    private static function workbench_url(string $page, array $args = []): string {
        $base = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $args = array_merge(['page' => preg_replace('/[^a-z0-9\-]/', '', $page)], $args);
        return function_exists('add_query_arg') ? add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }

    private static function action_form($action, array $fields, string $label, string $class = 'button button-small'): string {
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field($action, '_wpnonce', true, false) : '';
        $h = '<form class="fi-intake-inline-action" method="post" action="' . self::eu($post_url) . '">' . $nonce;
        $h .= '<input type="hidden" name="action" value="' . self::ea($action) . '">';
        foreach ($fields as $key => $value) {
            $h .= '<input type="hidden" name="' . self::ea((string) $key) . '" value="' . self::ea((string) $value) . '">';
        }
        $h .= '<button type="submit" class="' . self::ea($class) . '">' . self::e($label) . '</button></form>';
        return $h;
    }

    /** Read-only recent-objects tables with traceability columns. */
    private static function recent_objects($ws) {
        if (!class_exists('VES_Intelligence_Store')) { return ''; }
        $h = '<section class="fi-intake-card" id="fi-intake-recent"><h2>' . self::e('Pipeline — recent objects (workspace ' . (int) $ws . ')') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Each row carries its own next action: promote, approve/reject, build, export, record. No id copying on the normal path.') . '</p>';
        try {
            $sources = (array) VES_Intelligence_Store::list_sources(['workspace_id' => $ws, 'limit' => 8]);
            $signals = (array) VES_Intelligence_Store::list_signals(['workspace_id' => $ws, 'limit' => 8]);
            $insights = (array) VES_Intelligence_Store::list_insights(['workspace_id' => $ws, 'limit' => 8]);
            $briefs = (array) VES_Intelligence_Store::list_briefs(['workspace_id' => $ws, 'limit' => 8]);
            $drafts = (array) VES_Intelligence_Store::list_drafts(['workspace_id' => $ws, 'limit' => 8]);
        } catch (\Throwable $e) {
            return $h . '<p>' . self::e('Recent objects unavailable.') . '</p></section>';
        }
        $badge = function ($state, $label = null) {
            return class_exists('VES_Review_State') ? VES_Review_State::badge($state, $label) : self::e($label !== null ? $label : (string) $state);
        };

        $h .= '<h3>' . self::e('Sources') . '</h3>';
        if (count($sources) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No sources yet — record one above.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>type</th><th>title</th><th>reference</th><th>next action</th></tr></thead><tbody>';
            foreach ($sources as $s) {
                $id = (int) ($s['id'] ?? 0);
                $url = (string) ($s['source_url'] ?? '');
                $use_url = self::page_url($ws, ['prefill_source_id' => $id]) . '#fi-intake-signal';
                $h .= '<tr><td>' . self::e((string) $id) . '</td><td>' . self::e((string) ($s['source_type'] ?? '')) . '</td><td>' . self::e((string) ($s['source_title'] ?? '')) . '</td><td>' . ($url !== '' ? '<span class="fi-intake-mono">' . self::e(self::cut($url, 80)) . '</span>' : self::e('—')) . '</td><td><a class="button button-small" href="' . self::eu($use_url) . '">' . self::e('Use for signal') . '</a></td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Signals') . '</h3>';
        if (count($signals) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No signals yet — a signal records what a source showed.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>source</th><th>type</th><th>title</th><th>seen</th><th>next action</th></tr></thead><tbody>';
            foreach ($signals as $s) {
                $id = (int) ($s['id'] ?? 0);
                $title = self::txt((string) ($s['title'] ?? ''), 180);
                $summary = self::long_txt((string) ($s['summary'] ?? $title), 1200);
                $fields = ['workspace_id' => $ws, 'signal_id' => $id, 'insight_type' => 'content_pattern', 'opportunity_score' => 0, 'title' => 'Insight from signal #' . $id . ': ' . $title, 'summary' => $summary, 'recommendation' => 'Review evidence fit before briefing.'];
                $h .= '<tr><td>' . self::e((string) $id) . '</td><td>' . self::e('#' . (int) ($s['source_id'] ?? 0)) . '</td><td>' . self::e((string) ($s['signal_type'] ?? '')) . '</td><td>' . self::e($title) . '</td><td>' . self::e((string) (int) ($s['recurrence_count'] ?? 1) . '×') . '</td><td>' . self::action_form(self::ACTION_INSIGHT, $fields, 'Promote to insight') . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Insights') . '</h3>';
        if (count($insights) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No insights yet — promote a signal when a finding emerges.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>type</th><th>title</th><th>state</th><th>evidence</th><th>next action</th></tr></thead><tbody>';
            foreach ($insights as $i) {
                $id = (int) ($i['id'] ?? 0);
                $status = (string) ($i['status'] ?? 'draft');
                $pstate = method_exists('VES_Intelligence_Store', 'insight_presentation_state') ? VES_Intelligence_Store::insight_presentation_state($i) : $status;
                $evi = is_array($i['evidence_ids'] ?? null) ? count($i['evidence_ids']) : 0;
                $wb = '<a class="button button-small" href="' . self::eu(self::workbench_url('fi-brief-workbench', ['insight_id' => $id, 'workspace_id' => $ws])) . '">' . self::e('Workbench') . '</a> ';
                if ($status === 'approved') {
                    $action = self::action_form(self::ACTION_BRIEF, ['workspace_id' => $ws, 'insight_id' => $id], 'Build brief');
                } elseif (in_array($status, ['draft', 'reviewed'], true)) {
                    // The decision lives HERE now: review without leaving the loop.
                    // The lifecycle service still enforces evidence/quality gates.
                    $action = self::action_form(self::ACTION_APPROVE_INSIGHT, ['workspace_id' => $ws, 'insight_id' => $id], 'Approve')
                        . self::action_form(self::ACTION_REJECT_INSIGHT, ['workspace_id' => $ws, 'insight_id' => $id], 'Reject', 'button button-small fi-intake-danger');
                } else {
                    $action = '<span class="fi-intake-disabled">' . self::e($status === 'rejected' ? 'Rejected — terminal state' : 'No action in this state') . '</span>';
                }
                $h .= '<tr><td>' . self::e((string) $id) . '</td><td>' . self::e((string) ($i['insight_type'] ?? '')) . '</td><td>' . self::e((string) ($i['title'] ?? '')) . '</td><td>' . $badge($pstate) . '</td><td>' . self::e($evi . ' linked') . '</td><td>' . $wb . $action . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Briefs') . '</h3>';
        if (count($briefs) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No briefs yet — approve an insight, then build its brief above.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>insight</th><th>title</th><th>state</th><th>next action</th></tr></thead><tbody>';
            foreach ($briefs as $b) {
                $id = (int) ($b['id'] ?? 0);
                $wb = '<a class="button button-small" href="' . self::eu(self::workbench_url('fi-draft-workbench', ['brief_id' => $id, 'workspace_id' => $ws])) . '">' . self::e('Workbench') . '</a> ';
                $action = self::action_form(self::ACTION_DRAFT, ['workspace_id' => $ws, 'brief_id' => $id], 'Create Google Ads block');
                $h .= '<tr><td>' . self::e((string) $id) . '</td><td>' . self::e('#' . (int) ($b['insight_id'] ?? 0)) . '</td><td>' . self::e((string) ($b['title'] ?? '')) . '</td><td>' . $badge((string) ($b['status'] ?? 'draft')) . '</td><td>' . $wb . $action . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h3>' . self::e('Drafts') . '</h3>';
        if (count($drafts) === 0) { $h .= '<p class="fi-queue-hint">' . self::e('No drafts yet — generate a draft block from a brief.') . '</p>'; }
        else {
            $h .= '<table class="widefat striped"><thead><tr><th>id</th><th>brief</th><th>channel</th><th>title</th><th>state</th><th>next action</th></tr></thead><tbody>';
            foreach ($drafts as $d) {
                $id = (int) ($d['id'] ?? 0);
                $status = (string) ($d['status'] ?? 'generated');
                $memory = self::action_form(self::ACTION_MEMORY, ['workspace_id' => $ws, 'draft_id' => $id], 'Create memory');
                if ($status === 'approved') {
                    $review = self::action_form(self::ACTION_USAGE, ['workspace_id' => $ws, 'draft_id' => $id], 'Record usage', 'button button-small button-secondary');
                } elseif (in_array($status, ['generated', 'edited'], true)) {
                    $review = self::action_form(self::ACTION_APPROVE_DRAFT, ['workspace_id' => $ws, 'draft_id' => $id], 'Approve output');
                } else {
                    $review = '<span class="fi-intake-disabled">' . self::e('No review action in this state') . '</span>';
                }
                $h .= '<tr><td>' . self::e((string) $id) . '</td><td>' . self::e('#' . (int) ($d['brief_id'] ?? 0)) . '</td><td>' . self::e((string) ($d['channel'] ?? '')) . '</td><td>' . self::e((string) ($d['title'] ?? '')) . '</td><td>' . $badge($status) . '</td><td>' . $memory . $review . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</section>';
        return $h;
    }
}
