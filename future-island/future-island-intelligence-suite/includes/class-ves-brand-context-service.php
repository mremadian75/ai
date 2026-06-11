<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Brand_Context_Service — Phase 6A (Memory V2 / Brand Context Foundations).
 *
 * Reviewable, per-workspace Brand Context built ON TOP of the existing
 * VES_Memory_Records store. NO schema change: the trust/review status lives in
 * content_json['brand_context'] (the store preserves the key), pinned mirrors the
 * existing is_pinned column, and expiry uses the existing expires_at column.
 *
 * Trust boundary (the whole point of this phase):
 *   - AI may only ever propose CANDIDATE memory.
 *   - Only human-approved ACTIVE/PINNED, non-expired, non-rejected memory is
 *     retrieved as trusted context for generation.
 *   - Candidate / rejected / archived / expired memory is NEVER used in a context
 *     package (it is visible for review/diagnostics only).
 *
 * This is NOT Brand Brain, NOT a graph, NOT embeddings/RAG, NOT training. Learning
 * here = better reusable rules/context, human-reviewed. PHP 7.4 compatible.
 */
final class VES_Brand_Context_Service {

    /** Brand-context record types (additive; also registered in VES_Memory_Records). */
    const RECORD_TYPES = [
        'brand_fact', 'voice_rule', 'forbidden_claim', 'preferred_term', 'avoided_term',
        'audience_fact', 'competitor_fact', 'offer_fact', 'positioning_note',
        'review_learning', 'reusable_context', 'failed_assumption', 'campaign_learning',
    ];

    /** Review / trust states (encoded in content_json, not a new column). */
    const STATUS_CANDIDATE = 'candidate';
    const STATUS_ACTIVE    = 'active';
    const STATUS_PINNED    = 'pinned';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_ARCHIVED  = 'archived';
    const STATUSES = ['candidate', 'active', 'pinned', 'rejected', 'expired', 'archived'];

    const USE_CASES = ['insight_generation', 'brief_generation', 'draft_generation', 'qa_check'];

    const DEFAULT_MAX_ITEMS = 12;
    const DEFAULT_MAX_CHARS = 4000;

    /** Keys we must never persist into a memory summary/payload. */
    const FORBIDDEN_KEYS = ['prompt', 'raw_prompt', 'messages', 'response', 'raw_response', 'api_key', 'apikey', 'authorization', 'bearer', 'token', 'secret', 'password'];

    public static function is_brand_context_type($type) {
        return in_array((string) $type, self::RECORD_TYPES, true);
    }
    public static function is_valid_status($status) {
        return in_array((string) $status, self::STATUSES, true);
    }

    /** Which use cases a record type may inform. Memory guides tone/constraints — never replaces evidence. */
    public static function use_cases_for_type($type) {
        $tone = ['voice_rule', 'preferred_term', 'avoided_term', 'forbidden_claim'];
        $learn = ['failed_assumption', 'review_learning', 'campaign_learning'];
        if (in_array($type, $tone, true)) { return ['brief_generation', 'draft_generation', 'qa_check']; }
        if (in_array($type, $learn, true)) { return ['insight_generation', 'brief_generation', 'qa_check']; }
        return self::USE_CASES; // facts/positioning/context inform everything
    }

    // ── Pure helpers (no DB) — directly unit-testable ──────────────────────────

    /** Drop forbidden keys and bound size; never store secrets/raw prompts/responses. */
    public static function scrub_payload($data, $depth = 0) {
        if ($depth > 4) { return '[truncated]'; }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (in_array(strtolower((string) $k), self::FORBIDDEN_KEYS, true)) { continue; }
                $out[$k] = self::scrub_payload($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($data)) {
            $clean = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($data) : strip_tags($data);
            return self::truncate(self::redact_sensitive_text($clean), 600);
        }
        return $data;
    }

    private static function truncate($s, $max) {
        $s = (string) $s;
        return (strlen($s) > $max) ? (substr($s, 0, $max - 1) . '…') : $s;
    }

    /**
     * Conservative free-text secret redaction for summaries/titles (issue 6).
     * Key-based scrubbing alone misses secrets pasted into prose, so redact common
     * token shapes before storing. Returns text with secrets replaced by [redacted].
     */
    public static function redact_sensitive_text($text) {
        $text = (string) $text;
        if ($text === '') { return $text; }
        $patterns = [
            '/\bsk-[A-Za-z0-9_\-]{8,}/',                              // OpenAI-style keys
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/i',                       // Bearer tokens
            '/\bAuthorization\s*:\s*[A-Za-z0-9._\-\s]{8,}/i',          // Authorization headers
            '/\b(api[_\-]?key|token|secret|password)\s*[=:]\s*\S{4,}/i', // key=value pairs
            '/\bAIza[A-Za-z0-9_\-]{10,}/',                            // Google API keys
            '/\bapify_api_[A-Za-z0-9]{8,}/i',                         // Apify tokens
        ];
        return preg_replace($patterns, '[redacted]', $text);
    }

    /** Stored review status as written (no pin/expiry precedence). '' if missing/invalid. */
    public static function stored_status_of($row) {
        $row = is_array($row) ? $row : [];
        $content = self::decode_content($row);
        $bc = (isset($content['brand_context']) && is_array($content['brand_context'])) ? $content['brand_context'] : [];
        $status = (isset($bc['status']) && is_scalar($bc['status'])) ? (string) $bc['status'] : '';
        return self::is_valid_status($status) ? $status : '';
    }

    /**
     * Effective status of a (decoded) memory row. Conservative precedence:
     *   1. missing/invalid stored status  -> candidate (untrusted; existence != trust)
     *   2. explicit rejected/archived/candidate -> as stored (OVERRIDES pin)
     *   3. expired (past expires_at)       -> expired (OVERRIDES active/pinned)
     *   4. explicit pinned                 -> pinned (trusted)
     *   5. explicit active                 -> active (trusted)
     * The is_pinned COLUMN alone never confers trust — only an explicit stored
     * active/pinned status does.
     */
    public static function status_of($row) {
        $row = is_array($row) ? $row : [];
        $stored = self::stored_status_of($row);
        if ($stored === '') { return self::STATUS_CANDIDATE; }
        if ($stored === self::STATUS_REJECTED || $stored === self::STATUS_ARCHIVED || $stored === self::STATUS_CANDIDATE) {
            return $stored; // explicit non-trusted overrides pin/expiry
        }
        if (self::is_expired($row)) { return self::STATUS_EXPIRED; } // expiry overrides active/pinned
        if ($stored === self::STATUS_PINNED) { return self::STATUS_PINNED; }
        if ($stored === self::STATUS_ACTIVE) { return self::STATUS_ACTIVE; }
        return self::STATUS_CANDIDATE; // safety default: untrusted
    }

    public static function is_expired($row) {
        $exp = isset($row['expires_at']) ? (string) $row['expires_at'] : '';
        if ($exp === '' || $exp === '0000-00-00 00:00:00') { return false; }
        $ts = is_numeric($exp) ? (int) $exp : strtotime($exp);
        if (!$ts) { return false; }
        return $ts <= self::now_ts();
    }

    /** Trusted = usable as generation context. */
    public static function is_trusted($row) {
        $st = self::status_of($row);
        return ($st === self::STATUS_ACTIVE || $st === self::STATUS_PINNED);
    }

    private static function decode_content($row) {
        if (isset($row['content']) && is_array($row['content'])) { return $row['content']; }
        $raw = isset($row['content_json']) ? $row['content_json'] : '';
        if (is_array($raw)) { return $raw; }
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    private static function now_ts() {
        return isset($GLOBALS['ves_brand_context_now']) ? (int) $GLOBALS['ves_brand_context_now'] : time();
    }

    /**
     * Deterministic context-package builder from already-loaded rows (pure).
     * Sort: pinned first, then importance desc, then recency desc, then id desc.
     * Hard limits on item count + total chars. Trusted rows only.
     *
     * @return array structured package (JSON-safe; no raw payload blobs).
     */
    public static function build_package_from_rows($rows, $args = []) {
        $args = is_array($args) ? $args : [];
        $use_case = in_array(($args['use_case'] ?? ''), self::USE_CASES, true) ? $args['use_case'] : 'brief_generation';
        $max_items = max(1, min(50, (int) ($args['max_items'] ?? self::DEFAULT_MAX_ITEMS)));
        $max_chars = max(200, min(20000, (int) ($args['max_chars'] ?? self::DEFAULT_MAX_CHARS)));
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));

        $excluded = ['untrusted' => 0, 'expired' => 0, 'rejected' => 0, 'use_case_mismatch' => 0, 'over_limit' => 0, 'not_brand_context' => 0];
        $eligible = [];
        foreach ((array) $rows as $row) {
            $type = (string) ($row['memory_type'] ?? '');
            if (!self::is_brand_context_type($type)) { $excluded['not_brand_context']++; continue; }
            $st = self::status_of($row);
            if ($st === self::STATUS_REJECTED) { $excluded['rejected']++; continue; }
            if ($st === self::STATUS_EXPIRED) { $excluded['expired']++; continue; }
            if ($st === self::STATUS_CANDIDATE || $st === self::STATUS_ARCHIVED) { $excluded['untrusted']++; continue; }
            if (!in_array($use_case, self::use_cases_for_type($type), true)) { $excluded['use_case_mismatch']++; continue; }
            $eligible[] = $row;
        }

        usort($eligible, [__CLASS__, 'compare_rows']);

        $items = []; $chars = 0;
        foreach ($eligible as $row) {
            if (count($items) >= $max_items) { $excluded['over_limit']++; continue; }
            $summary = self::truncate((string) ($row['summary'] ?? ''), 600);
            if ($chars + strlen($summary) > $max_chars) { $excluded['over_limit']++; continue; }
            $chars += strlen($summary);
            $items[] = [
                'memory_id' => (int) ($row['id'] ?? 0),
                'record_type' => (string) ($row['memory_type'] ?? ''),
                'summary' => $summary,
                'importance_score' => (float) ($row['importance_score'] ?? 0),
                'source_target_type' => (string) ($row['source_type'] ?? ''),
                'source_target_id' => (int) ($row['source_id'] ?? 0),
                'status' => self::status_of($row),
            ];
        }

        return [
            'workspace_id' => $workspace_id,
            'use_case' => $use_case,
            'generated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'items' => $items,
            'item_count' => count($items),
            'char_count' => $chars,
            'excluded' => $excluded,
            'limits' => ['max_items' => $max_items, 'max_chars' => $max_chars],
            'note' => empty($items) ? 'No trusted brand context for this workspace/use case yet.' : '',
        ];
    }

    /** Sort comparator: pinned first, importance desc, recency desc, id desc. */
    public static function compare_rows($a, $b) {
        $pa = !empty($a['is_pinned']) ? 1 : 0; $pb = !empty($b['is_pinned']) ? 1 : 0;
        if ($pa !== $pb) { return $pb - $pa; }
        $ia = (float) ($a['importance_score'] ?? 0); $ib = (float) ($b['importance_score'] ?? 0);
        if ($ia !== $ib) { return ($ib < $ia) ? -1 : 1; }
        $ra = strtotime((string) ($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $rb = strtotime((string) ($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
        if ($ra !== $rb) { return ($rb < $ra) ? -1 : 1; }
        return ((int) ($b['id'] ?? 0)) - ((int) ($a['id'] ?? 0));
    }

    // ── Candidate proposers from reviewed objects (pure) ───────────────────────
    // Never trust AI output: these only ever propose status=candidate, and only
    // from APPROVED (or, for drafts, approved/rejected-with-notes) objects.

    public static function propose_from_insight($insight) {
        $insight = is_array($insight) ? $insight : [];
        if (sanitize_key((string) ($insight['status'] ?? '')) !== 'approved') { return null; } // J12: no unapproved memory
        $type = !empty($insight['failed_assumption']) ? 'failed_assumption' : 'reusable_context';
        $summary = self::summarize($insight, ['title', 'summary', 'insight']);
        if ($summary === '') { return null; }
        return self::candidate_spec($type, $summary, 'insight', (int) ($insight['id'] ?? 0), 70);
    }

    public static function propose_from_brief($brief) {
        $brief = is_array($brief) ? $brief : [];
        if (sanitize_key((string) ($brief['status'] ?? '')) !== 'approved') { return null; }
        $type = !empty($brief['audience']) ? 'audience_fact' : 'positioning_note';
        $summary = self::summarize($brief, ['title', 'positioning', 'summary', 'audience']);
        if ($summary === '') { return null; }
        return self::candidate_spec($type, $summary, 'brief', (int) ($brief['id'] ?? 0), 65);
    }

    public static function propose_from_draft($draft) {
        $draft = is_array($draft) ? $draft : [];
        $status = sanitize_key((string) ($draft['status'] ?? ''));
        if ($status === 'approved') {
            $type = !empty($draft['voice_rule']) ? 'voice_rule' : 'reusable_context';
            $summary = self::summarize($draft, ['voice_rule', 'title', 'summary']);
            if ($summary === '') { return null; }
            return self::candidate_spec($type, $summary, 'draft', (int) ($draft['id'] ?? 0), 60);
        }
        if ($status === 'rejected') {
            $notes = self::summarize($draft, ['notes', 'rejection_reason', 'review_notes']);
            if ($notes === '') { return null; } // J11: rejected draft needs notes to learn from
            $type = !empty($draft['forbidden_claim']) ? 'forbidden_claim' : 'review_learning';
            return self::candidate_spec($type, $notes, 'draft', (int) ($draft['id'] ?? 0), 55);
        }
        return null;
    }

    private static function summarize($obj, $keys) {
        foreach ($keys as $k) {
            if (!empty($obj[$k]) && is_string($obj[$k])) {
                $clean = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($obj[$k]) : strip_tags($obj[$k]);
                $clean = self::redact_sensitive_text(trim($clean)); // issue 6: redact secrets in free text
                if ($clean !== '') { return self::truncate($clean, 600); }
            }
        }
        return '';
    }

    private static function candidate_spec($type, $summary, $source_type, $source_id, $importance) {
        return [
            'record_type' => $type,
            'summary' => $summary,
            'source_target_type' => $source_type,
            'source_target_id' => (int) $source_id,
            'importance_score' => (int) $importance,
            'status' => self::STATUS_CANDIDATE,
        ];
    }

    // ── DB-backed operations (thin wrappers over VES_Memory_Records) ───────────

    /** Persist a candidate proposal. Returns memory id or 0. Status is forced to candidate. */
    public static function create_candidate($workspace_id, $spec, $user_id = 0) {
        $spec = is_array($spec) ? $spec : [];
        $type = (string) ($spec['record_type'] ?? '');
        if (!self::is_brand_context_type($type)) { return 0; }
        $summary = self::summarize($spec, ['summary']);
        if ($summary === '') { return 0; }
        if (!class_exists('VES_Memory_Records') || !method_exists('VES_Memory_Records', 'save_record')) { return 0; }
        $id = (int) VES_Memory_Records::save_record([
            'workspace_id' => max(0, (int) $workspace_id),
            'user_id' => (int) $user_id,
            'memory_type' => $type,
            'title' => self::truncate(ucfirst(str_replace('_', ' ', $type)), 180),
            'summary' => $summary,
            'content' => ['brand_context' => self::scrub_payload([
                'status' => self::STATUS_CANDIDATE,
                'source_target_type' => (string) ($spec['source_target_type'] ?? ''),
                'source_target_id' => (int) ($spec['source_target_id'] ?? 0),
                'proposed_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            ])],
            'source_type' => (string) ($spec['source_target_type'] ?? ''),
            'source_id' => (string) ($spec['source_target_id'] ?? ''),
            'importance_score' => (int) ($spec['importance_score'] ?? 50),
            'tags' => ['brand_context', $type, self::STATUS_CANDIDATE],
            // Phase 9A.4 — same source target proposes the SAME candidate record
            // (records-layer dedupe), never a growing pile of duplicates.
            'dedupe' => true,
        ]);
        self::log('candidate_created', ['memory_id' => $id, 'record_type' => $type, 'workspace_id' => (int) $workspace_id]);
        return $id;
    }

    /**
     * Change the review status of a brand-context memory record. Human-gated at the
     * caller (capability/nonce). Never auto-approves. Returns true on success.
     */
    public static function set_status($id, $status, $actor_id = 0, $workspace_id = null) {
        $id = (int) $id;
        if ($id <= 0 || !self::is_valid_status($status)) { return false; }
        if (!class_exists('VES_Memory_Records')) { return false; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return false; }
        $table = VES_Memory_Records::table_name();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id = %d', $id), ARRAY_A);
        if (!is_array($row)) { return false; }
        // Issue 4: only ever mutate brand-context rows, never other memory types.
        if (!self::is_brand_context_type((string) ($row['memory_type'] ?? ''))) { return false; }
        // Optional workspace scoping: never trust a cross-workspace id.
        if ($workspace_id !== null && (int) $workspace_id > 0 && (int) ($row['workspace_id'] ?? 0) !== (int) $workspace_id) { return false; }
        // Phase 9B.1 — memory transition matrix: a rejected/archived/expired record
        // can never be resurrected to candidate/active/pinned. Downgrades stay open.
        $from_status = self::stored_status_of($row);
        if (class_exists('VES_Review_Decision_Ledger')
            && method_exists('VES_Review_Decision_Ledger', 'transition_allowed')
            && !VES_Review_Decision_Ledger::transition_allowed('memory_record', $from_status, $status)) {
            if (class_exists('VES_Security_Event_Log')) {
                VES_Security_Event_Log::record('lifecycle_transition_blocked', 'Memory status transition blocked.', [
                    'memory_id' => $id, 'from' => $from_status, 'to' => (string) $status,
                ]);
            }
            self::log('status_change_blocked', ['memory_id' => $id, 'from' => $from_status, 'to' => (string) $status]);
            return false;
        }
        $content = self::decode_content($row);
        if (!isset($content['brand_context']) || !is_array($content['brand_context'])) { $content['brand_context'] = []; }
        $content['brand_context']['status'] = $status;
        $content['brand_context']['reviewed_by'] = (int) $actor_id;
        $content['brand_context']['reviewed_at'] = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        // Issue 1: only an explicit 'pinned' keeps is_pinned=1. rejected/archived/
        // candidate/expired (and active) all clear the pin so a stale pin can never
        // re-confer trust.
        $pin = ($status === self::STATUS_PINNED) ? 1 : 0;
        $ok = $wpdb->update($table, [
            'content_json' => wp_json_encode($content, JSON_UNESCAPED_UNICODE),
            'is_pinned' => $pin,
            'updated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
        // Phase 9D/9E low fix — never log success before the DB proves it:
        // status_changed ONLY after a successful update; failures get their own
        // honest event, the ledger is skipped, and the caller receives false.
        if ($ok === false) {
            self::log('status_change_failed', ['memory_id' => $id, 'status' => $status, 'actor' => (int) $actor_id]);
            return false;
        }
        self::log('status_changed', ['memory_id' => $id, 'status' => $status, 'actor' => (int) $actor_id]);
        // Phase 9B.1 — every successful memory review action appends one immutable
        // ledger decision (duplicate submissions collapse on the idempotency key).
        if ($ok !== false && class_exists('VES_Review_Decision_Ledger') && method_exists('VES_Review_Decision_Ledger', 'record')) {
            $decision_map = [
                self::STATUS_ACTIVE => ($from_status === self::STATUS_PINNED ? 'unpin' : 'approve'),
                self::STATUS_PINNED => 'pin',
                self::STATUS_REJECTED => 'reject',
                self::STATUS_ARCHIVED => 'archive',
            ];
            VES_Review_Decision_Ledger::record([
                'workspace_id' => (int) ($row['workspace_id'] ?? 0),
                'object_type' => 'memory_record',
                'object_id' => $id,
                'from_status' => $from_status,
                'to_status' => (string) $status,
                'decision' => $decision_map[$status] ?? 'status_change',
                'actor_user_id' => (int) $actor_id,
            ]);
        }
        return $ok !== false;
    }

    /** Fetch decoded brand-context rows for a workspace (newest/most-important first). */
    public static function load_rows($workspace_id, $limit = 200) {
        if (!class_exists('VES_Memory_Records')) { return []; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return []; }
        $table = VES_Memory_Records::table_name();
        $limit = max(1, min(500, (int) $limit));
        $ws = max(0, (int) $workspace_id);
        $rows = $ws > 0
            ? $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE workspace_id = %d ORDER BY is_pinned DESC, importance_score DESC, updated_at DESC, id DESC LIMIT %d', $ws, $limit), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' ORDER BY is_pinned DESC, importance_score DESC, updated_at DESC, id DESC LIMIT %d', $limit), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            if (self::is_brand_context_type((string) ($r['memory_type'] ?? ''))) { $out[] = $r; }
        }
        return $out;
    }

    /** Build a context package for a workspace + use case (DB-backed). */
    public static function build_context_package($args = []) {
        $args = is_array($args) ? $args : [];
        $rows = self::load_rows((int) ($args['workspace_id'] ?? 0));
        $pkg = self::build_package_from_rows($rows, $args);
        self::log('context_build', ['workspace_id' => (int) ($args['workspace_id'] ?? 0), 'use_case' => $pkg['use_case'], 'items' => $pkg['item_count']]);
        return $pkg;
    }

    /** Trusted rows only (active/pinned, non-expired) for a workspace. */
    public static function retrieve_trusted_context($workspace_id, $args = []) {
        $rows = self::load_rows((int) $workspace_id);
        $trusted = [];
        foreach ($rows as $r) { if (self::is_trusted($r)) { $trusted[] = $r; } }
        return $trusted;
    }

    /** Summary counts by status + type (read-only, for CLI/admin). */
    public static function summary($workspace_id = 0) {
        $rows = self::load_rows((int) $workspace_id);
        $by_status = []; $by_type = []; $trusted = 0; $candidates = 0;
        foreach ($rows as $r) {
            $st = self::status_of($r); $ty = (string) ($r['memory_type'] ?? '');
            $by_status[$st] = (isset($by_status[$st]) ? $by_status[$st] : 0) + 1;
            $by_type[$ty] = (isset($by_type[$ty]) ? $by_type[$ty] : 0) + 1;
            if (self::is_trusted($r)) { $trusted++; }
            if ($st === self::STATUS_CANDIDATE) { $candidates++; }
        }
        return ['total' => count($rows), 'trusted' => $trusted, 'candidates' => $candidates, 'by_status' => $by_status, 'by_type' => $by_type];
    }

    /** Dry-run by default. Marks past-expiry, non-pinned, non-candidate rows as expired. */
    public static function expire_stale($workspace_id = 0, $apply = false) {
        $rows = self::load_rows((int) $workspace_id);
        $targets = [];
        $terminal = [self::STATUS_EXPIRED, self::STATUS_REJECTED, self::STATUS_ARCHIVED];
        foreach ($rows as $r) {
            $pinned = !empty($r['is_pinned']) || self::stored_status_of($r) === self::STATUS_PINNED;
            $stored = self::stored_status_of($r);
            // Eligible: not pinned, past expires_at, and STORED status is not already
            // terminal. Uses stored_status_of (not status_of, which would already
            // report 'expired' and zero-out the count).
            if (!$pinned && self::is_expired($r) && !in_array($stored, $terminal, true)) {
                $targets[] = (int) ($r['id'] ?? 0);
            }
        }
        $applied = 0;
        if ($apply) { foreach ($targets as $tid) { if (self::set_status($tid, self::STATUS_EXPIRED, 0)) { $applied++; } } }
        return ['eligible' => count($targets), 'applied' => $applied, 'dry_run' => !$apply];
    }

    private static function log($event, array $meta) {
        if (class_exists('VES_Log') && method_exists('VES_Log', 'info')) {
            VES_Log::info('brand_context', 'Brand context ' . $event, $meta);
        }
    }

    // ── Admin render (escaped HTML string; testable) ───────────────────────────

    private static function e($s) {
        return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES);
    }

    public static function render_admin_html($workspace_id, $preview_use_case = 'brief_generation') {
        $rows = self::load_rows((int) $workspace_id);
        $trusted = []; $candidates = []; $stale = [];
        foreach ($rows as $r) {
            $st = self::status_of($r);
            if ($st === self::STATUS_ACTIVE || $st === self::STATUS_PINNED) { $trusted[] = $r; }
            elseif ($st === self::STATUS_CANDIDATE) { $candidates[] = $r; }
            else { $stale[] = $r; }
        }
        $h = '<div class="fiis-brand-context"><h2>' . self::e('Brand Context (Memory V2)') . '</h2>';

        $h .= '<h3>' . self::e('Trusted Brand Context') . '</h3>';
        $h .= self::render_rows_table($trusted, true);

        $h .= '<h3>' . self::e('Candidate Memory') . '</h3>';
        $h .= '<p class="description"><strong>' . self::e('Candidate memory is not used in generation until approved.') . '</strong></p>';
        $h .= self::render_rows_table($candidates, false);

        $h .= '<h3>' . self::e('Expired / Archived (diagnostics)') . '</h3>';
        $h .= self::render_rows_table($stale, false);

        $uc = in_array($preview_use_case, self::USE_CASES, true) ? $preview_use_case : 'brief_generation';
        $pkg = self::build_package_from_rows($rows, ['workspace_id' => (int) $workspace_id, 'use_case' => $uc]);
        $h .= '<h3>' . self::e('Context Preview') . ' — ' . self::e($uc) . '</h3>';
        if (empty($pkg['items'])) {
            $h .= '<p class="description">' . self::e($pkg['note']) . '</p>';
        } else {
            $h .= '<ul class="fiis-bc-preview">';
            foreach ($pkg['items'] as $it) {
                $h .= '<li><code>' . self::e($it['record_type']) . '</code> · ' . self::e($it['summary'])
                    . ' <span class="description">(' . self::e($it['status']) . ', imp ' . self::e((string) (int) $it['importance_score']) . ')</span></li>';
            }
            $h .= '</ul>';
        }
        $h .= '<p class="description">' . self::e(sprintf('Included %d · excluded: %s', $pkg['item_count'], wp_json_encode($pkg['excluded']))) . '</p>';
        $h .= '</div>';
        return $h;
    }

    private static function render_rows_table($rows, $with_pin_actions) {
        if (empty($rows)) { return '<p class="description">' . self::e('None.') . '</p>'; }
        $h = '<table class="widefat"><thead><tr><th>' . self::e('Type') . '</th><th>' . self::e('Summary') . '</th><th>'
           . self::e('Importance') . '</th><th>' . self::e('Source') . '</th><th>' . self::e('Status') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td><code>' . self::e((string) ($r['memory_type'] ?? '')) . '</code></td>'
                . '<td>' . self::e(self::truncate((string) ($r['summary'] ?? ''), 240)) . '</td>'
                . '<td>' . self::e((string) (int) ($r['importance_score'] ?? 0)) . '</td>'
                . '<td>' . self::e((string) ($r['source_type'] ?? '') . ' #' . (int) ($r['source_id'] ?? 0)) . '</td>'
                . '<td>' . self::e(self::status_of($r)) . '</td></tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }
}
