<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Review_Decision_Ledger — Phase 9B.1 append-only human-review audit ledger.
 *
 * Every successful lifecycle mutation (insight/brief/draft status change, memory
 * review action) writes one immutable decision row. The class exposes record()
 * and read methods ONLY — there is no update or delete API by design, and the
 * idempotency_key UNIQUE index makes duplicate submissions a deterministic no-op.
 * Payloads are scrubbed before storage: no raw prompts, no provider responses,
 * no secrets. Additive dbDelta table; no destructive migration. PHP 7.4.
 */
final class VES_Review_Decision_Ledger {

    const TABLE_SLUG  = 'ves_review_decisions';
    const DB_VERSION  = '1.1.0';
    const VERSION_OPT = 'ves_review_decisions_db_version';

    const OBJECT_TYPES = ['insight', 'brief', 'draft', 'asset', 'memory_record', 'pattern', 'report', 'outcome', 'prompt_package'];
    const DECISIONS = ['approve', 'reject', 'revise', 'archive', 'reopen', 'restore', 'pin', 'unpin', 'mark_assumption', 'mark_unsupported', 'mark_useful', 'mark_stale', 'mark_needs_revision', 'promote_to_reviewed', 'status_change'];
    const REASON_CODES = ['too_generic','wrong_audience','weak_evidence','unsupported_claim','off_brand','platform_mismatch','good_angle','strong_hook','needs_specificity','client_approved','client_rejected','market_changed','stale_memory','duplicate','useful_pattern','not_actionable'];

    public static function reason_codes(): array { return self::REASON_CODES; }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /** Additive, idempotent installer (dbDelta only adds). */
    public static function create_table($force = false) {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $table = self::table_name();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(32) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            from_status varchar(32) NOT NULL DEFAULT '',
            to_status varchar(32) NOT NULL DEFAULT '',
            decision varchar(32) NOT NULL DEFAULT '',
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reason text NULL,
            reason_code varchar(40) NOT NULL DEFAULT '',
            evidence_snapshot_hash varchar(64) NOT NULL DEFAULT '',
            idempotency_key varchar(64) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY object_lookup (object_type,object_id),
            KEY reason_code (reason_code),
            KEY created_at (created_at),
            UNIQUE KEY idempotency_key (idempotency_key)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    /**
     * Append one review decision. Returns the row id, the EXISTING row id when the
     * idempotency key was already recorded (deterministic no-op), or WP_Error.
     */
    public static function record(array $args) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_ledger_no_db', 'Database unavailable.'); }

        $object_type = self::clean_key((string) ($args['object_type'] ?? ''));
        if (!in_array($object_type, self::OBJECT_TYPES, true)) {
            return new WP_Error('ves_ledger_bad_type', 'Unknown review object type.');
        }
        $decision = self::clean_key((string) ($args['decision'] ?? 'status_change'));
        if (!in_array($decision, self::DECISIONS, true)) { $decision = 'status_change'; }

        $metadata_arg = is_array($args['metadata'] ?? null) ? $args['metadata'] : [];
        $reason_code = self::clean_key((string) ($args['reason_code'] ?? ($metadata_arg['reason_code'] ?? '')), 40);
        if ($reason_code !== '' && !in_array($reason_code, self::REASON_CODES, true)) {
            return new WP_Error('ves_ledger_bad_reason_code', 'Unknown review reason code.');
        }

        $row = [
            'workspace_id'  => max(0, (int) ($args['workspace_id'] ?? 0)),
            'object_type'   => $object_type,
            'object_id'     => max(0, (int) ($args['object_id'] ?? 0)),
            'from_status'   => self::clean_key((string) ($args['from_status'] ?? ''), 32),
            'to_status'     => self::clean_key((string) ($args['to_status'] ?? ''), 32),
            'decision'      => $decision,
            'actor_user_id' => max(0, (int) ($args['actor_user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))),
            'reason'        => self::scrub_text((string) ($args['reason'] ?? ''), 500),
            'reason_code'   => $reason_code,
            'evidence_snapshot_hash' => self::clean_hash((string) ($args['evidence_snapshot_hash'] ?? '')),
            'idempotency_key' => '',
            'metadata'      => '',
            'created_at'    => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
        if ($row['object_id'] <= 0) { return new WP_Error('ves_ledger_bad_object', 'Review decision requires an object id.'); }

        $idem = (string) ($args['idempotency_key'] ?? '');
        if ($idem === '') {
            $idem = hash('sha256', implode('|', [
                $row['workspace_id'], $row['object_type'], $row['object_id'],
                $row['from_status'], $row['to_status'], $row['decision'], $row['actor_user_id'],
                (string) ($args['idempotency_salt'] ?? ''), $row['reason_code'],
            ]));
        }
        $row['idempotency_key'] = substr(preg_replace('/[^a-f0-9]/i', '', $idem) ?: hash('sha256', $idem), 0, 64);

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE idempotency_key = %s LIMIT 1', $row['idempotency_key']
        ));
        if ($existing > 0) { return $existing; }

        $row['metadata'] = self::encode_metadata($reason_code !== '' ? array_merge($metadata_arg, ['reason_code' => $reason_code]) : $metadata_arg);
        $ok = $wpdb->insert(self::table_name(), $row, ['%d','%s','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s']);
        if ($ok) { return (int) $wpdb->insert_id; }
        // Unique-key race: another writer recorded the same decision first.
        $winner = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE idempotency_key = %s LIMIT 1', $row['idempotency_key']
        ));
        return $winner > 0 ? $winner : new WP_Error('ves_ledger_insert_failed', 'Could not append review decision.');
    }

    /** Recent decisions, newest first. Read-only. */
    public static function recent(array $filters = []) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return []; }
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $where = '1=1'; $params = [];
        if (!empty($filters['workspace_id'])) { $where .= ' AND workspace_id = %d'; $params[] = (int) $filters['workspace_id']; }
        if (!empty($filters['object_type'])) { $where .= ' AND object_type = %s'; $params[] = self::clean_key((string) $filters['object_type']); }
        if (!empty($filters['reason_code'])) { $where .= ' AND reason_code = %s'; $params[] = self::clean_key((string) $filters['reason_code'], 40); }
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d", $params
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** Readiness probe: ledger compiled in + append-only surface (no mutators). */
    public static function ledger_active() {
        return method_exists(__CLASS__, 'record')
            && method_exists(__CLASS__, 'recent')
            && !method_exists(__CLASS__, 'update')
            && !method_exists(__CLASS__, 'delete');
    }

    // ── transition matrices for objects without their own store matrix ─────────

    /** Brief/Draft share the insight-style vocabulary; Memory has its own. */
    public static function transition_allowed($object_type, $from, $to) {
        $object_type = self::clean_key($object_type);
        $from = self::clean_key($from); $to = self::clean_key($to);
        if ($object_type === 'insight' && class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'insight_transition_allowed')) {
            return VES_Intelligence_Store::insight_transition_allowed($from, $to);
        }
        if ($object_type === 'brief' || $object_type === 'draft') {
            $matrix = [
                'draft'     => ['draft', 'ready', 'reviewed', 'needs_revision', 'approved', 'rejected', 'archived'],
                'ready'     => ['ready', 'reviewed', 'needs_revision', 'approved', 'rejected', 'archived'],
                'needs_revision' => ['needs_revision', 'draft', 'ready', 'reviewed', 'archived'],
                'generated' => ['generated', 'edited', 'approved', 'rejected', 'archived'],
                'edited'    => ['edited', 'approved', 'rejected', 'archived'],
                'reviewed'  => ['reviewed', 'needs_revision', 'approved', 'rejected', 'archived'],
                'approved'  => ['approved', 'archived'],
                'rejected'  => ['rejected'],
                'archived'  => ['archived'],
            ];
            return in_array($to, $matrix[$from] ?? [$from], true);
        }
        if ($object_type === 'memory_record') {
            // Trust DOWNGRADES (reject/archive/expire) are always available from live
            // states; trust RESURRECTION is impossible: rejected/archived/expired can
            // never become candidate/active/pinned again.
            $matrix = [
                'candidate' => ['candidate', 'active', 'rejected', 'archived', 'expired'],
                'active'    => ['active', 'pinned', 'rejected', 'archived', 'expired'],
                'pinned'    => ['pinned', 'active', 'rejected', 'archived', 'expired'],
                'rejected'  => ['rejected'],
                'archived'  => ['archived'],
                'expired'   => ['expired', 'archived'],
            ];
            return in_array($to, $matrix[$from] ?? [], true);
        }
        return false; // prompt_package and unknown types are preview-only
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function encode_metadata(array $metadata) {
        $clean = [];
        $forbidden = ['prompt', 'raw_prompt', 'prompt_text', 'messages', 'raw_response', 'provider_response', 'api_key', 'apikey', 'token', 'authorization', 'bearer', 'secret', 'password'];
        $i = 0;
        foreach ($metadata as $k => $v) {
            if ($i++ >= 15) { break; }
            $key = self::clean_key($k, 40);
            if (in_array($key, $forbidden, true)) { continue; }
            if (is_scalar($v) || $v === null) { $clean[$key] = self::scrub_text((string) $v, 200); }
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($clean) : json_encode($clean);
        return is_string($json) ? $json : '{}';
    }

    private static function scrub_text($s, $max) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags((string) $s));
        if (class_exists('VES_Security_Event_Log')) { $s = VES_Security_Event_Log::scrub($s); }
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }

    private static function clean_key($s, $max = 32) {
        $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
        return substr($s, 0, $max);
    }

    private static function clean_hash($s) {
        return substr(preg_replace('/[^a-f0-9]/i', '', (string) $s), 0, 64);
    }
}
