<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Run_Store — canonical Future Island Run spine.
 *
 * Additive store for generic product runs. Legacy trend/DTF/brand-audit run
 * stores remain in place; this table is the compatibility-safe product-level
 * container used by the Source -> Signal -> Insight -> Brief -> Draft loop.
 */
final class VES_Run_Store {
    const TABLE_SLUG  = 'ves_intel_runs';
    const DB_VERSION  = '1.0.0';
    const VERSION_OPT = 'ves_intel_runs_db_version';

    const RUN_TYPES = [
        'research', 'creation', 'hybrid', 'social_scan', 'profile_analysis', 'post_analysis',
        'trend_scan', 'brand_xray', 'aeo_gap', 'creative_analysis', 'creative_test',
        'report_export', 'memory_refresh', 'tool_action',
    ];
    const TRIGGER_TYPES = ['manual', 'scheduled', 'api', 'downstream', 'retry'];
    const STATUSES = ['queued', 'running', 'completed', 'failed', 'timed_out', 'canceled', 'partial'];
    const TERMINAL_STATUSES = ['completed', 'failed', 'timed_out', 'canceled', 'partial'];

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table($force = false) {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $table = self::table_name();
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NULL,
            run_type varchar(40) NOT NULL,
            trigger_type varchar(40) NOT NULL,
            status varchar(40) NOT NULL,
            input_context_json longtext NULL,
            parent_run_id bigint(20) unsigned NULL,
            correlation_key varchar(191) NULL,
            idempotency_key varchar(191) NULL,
            orchestrator_ref varchar(191) NULL,
            initiated_by_user_id bigint(20) unsigned NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            result_summary_json longtext NULL,
            error_summary text NULL,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY source_id (source_id),
            KEY status (status),
            KEY correlation_key (correlation_key),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY created_at (created_at)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    public static function create_run($args) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return self::err('ves_run_no_db', 'Database unavailable.'); }
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return self::err('ves_run_missing_workspace', 'workspace_id is required.'); }

        $idempotency_key = self::sanitize_token((string) ($args['idempotency_key'] ?? ''), 191, true);
        if ($idempotency_key !== '') {
            $existing = self::find_by_idempotency_key($workspace_id, $idempotency_key);
            if (is_array($existing) && !empty($existing['id'])) { return (int) $existing['id']; }
        }

        $now = self::now();
        $status = self::sanitize_status($args['status'] ?? 'queued');
        if ($status !== 'queued') { $status = 'queued'; }
        $row = [
            'workspace_id' => $workspace_id,
            'source_id' => !empty($args['source_id']) ? max(0, (int) $args['source_id']) : null,
            'run_type' => self::sanitize_run_type($args['run_type'] ?? 'research'),
            'trigger_type' => self::sanitize_trigger_type($args['trigger_type'] ?? 'manual'),
            'status' => $status,
            'input_context_json' => self::encode_json(is_array($args['input_context'] ?? null) ? $args['input_context'] : (is_array($args['input_context_json'] ?? null) ? $args['input_context_json'] : [])),
            'parent_run_id' => !empty($args['parent_run_id']) ? max(0, (int) $args['parent_run_id']) : null,
            'correlation_key' => self::sanitize_token((string) ($args['correlation_key'] ?? ''), 191, true) ?: null,
            'idempotency_key' => $idempotency_key !== '' ? $idempotency_key : null,
            'orchestrator_ref' => self::sanitize_token((string) ($args['orchestrator_ref'] ?? ''), 191, true) ?: null,
            'initiated_by_user_id' => !empty($args['initiated_by_user_id']) ? max(0, (int) $args['initiated_by_user_id']) : (function_exists('get_current_user_id') ? max(0, (int) get_current_user_id()) : null),
            'started_at' => null,
            'completed_at' => null,
            'result_summary_json' => null,
            'error_summary' => null,
            'metadata_json' => self::encode_json(self::clean_metadata(is_array($args['metadata'] ?? null) ? $args['metadata'] : [])),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ((int) $row['source_id'] <= 0) { $row['source_id'] = null; }
        if ((int) $row['initiated_by_user_id'] <= 0) { $row['initiated_by_user_id'] = null; }

        $ok = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%s','%s','%s','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s']);
        if ($ok !== false) { return (int) $wpdb->insert_id; }

        // Race-safe idempotency: if another writer won, return its row.
        if ($idempotency_key !== '') {
            $winner = self::find_by_idempotency_key($workspace_id, $idempotency_key);
            if (is_array($winner) && !empty($winner['id'])) { return (int) $winner['id']; }
        }
        return self::err('ves_run_insert_failed', 'Could not create canonical run.');
    }

    public static function get_run($run_id) {
        global $wpdb;
        $run_id = max(0, (int) $run_id);
        if ($run_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $run_id), ARRAY_A);
        return is_array($row) ? self::decode_row($row) : null;
    }

    public static function find_by_idempotency_key($workspace_id, $idempotency_key) {
        global $wpdb;
        $workspace_id = max(0, (int) $workspace_id);
        $idempotency_key = self::sanitize_token((string) $idempotency_key, 191, true);
        if ($workspace_id <= 0 || $idempotency_key === '' || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d AND idempotency_key = %s LIMIT 1', $workspace_id, $idempotency_key), ARRAY_A);
        return is_array($row) ? self::decode_row($row) : null;
    }

    public static function list_runs_for_workspace($workspace_id, $args = []) {
        global $wpdb;
        $workspace_id = max(0, (int) $workspace_id);
        $args = is_array($args) ? $args : [];
        if ($workspace_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return []; }
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $where = 'workspace_id = %d';
        $params = [$workspace_id];
        if (!empty($args['source_id'])) { $where .= ' AND source_id = %d'; $params[] = max(0, (int) $args['source_id']); }
        if (!empty($args['status'])) { $where .= ' AND status = %s'; $params[] = self::sanitize_status($args['status']); }
        if (!empty($args['run_type'])) { $where .= ' AND run_type = %s'; $params[] = self::sanitize_run_type($args['run_type']); }
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT %d", $params), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        return array_map([__CLASS__, 'decode_row'], $rows);
    }

    public static function mark_running($run_id) { return self::transition($run_id, 'running', [], ''); }
    public static function mark_completed($run_id, $summary = []) { return self::transition($run_id, 'completed', is_array($summary) ? $summary : [], ''); }
    public static function mark_partial($run_id, $summary = []) { return self::transition($run_id, 'partial', is_array($summary) ? $summary : [], ''); }
    public static function mark_failed($run_id, $error_summary, $metadata = []) { return self::transition($run_id, 'failed', is_array($metadata) ? $metadata : [], (string) $error_summary); }
    public static function mark_timed_out($run_id, $metadata = []) { return self::transition($run_id, 'timed_out', is_array($metadata) ? $metadata : [], ''); }
    public static function cancel_run($run_id) { return self::transition($run_id, 'canceled', [], ''); }

    public static function assert_workspace($run_id, $workspace_id) {
        $run = self::get_run($run_id);
        if (!is_array($run) || empty($run['id'])) { return self::err('ves_run_not_found', 'Run not found.'); }
        if ((int) ($run['workspace_id'] ?? 0) !== (int) $workspace_id) { return self::err('ves_run_workspace_mismatch', 'Run belongs to a different workspace.'); }
        return true;
    }

    private static function transition($run_id, $to, $summary = [], $error_summary = '') {
        global $wpdb;
        $run = self::get_run($run_id);
        if (!is_array($run) || empty($run['id'])) { return self::err('ves_run_not_found', 'Run not found.'); }
        $from = self::sanitize_status($run['status'] ?? 'queued');
        $to = self::sanitize_status($to);
        if (!self::transition_allowed($from, $to)) {
            return self::err('ves_run_transition_blocked', "Run status transition '{$from}' -> '{$to}' is not allowed.", ['from' => $from, 'to' => $to]);
        }
        if (!isset($wpdb) || !is_object($wpdb)) { return self::err('ves_run_no_db', 'Database unavailable.'); }
        $now = self::now();
        $row = ['status' => $to, 'updated_at' => $now];
        $formats = ['%s', '%s'];
        if ($to === 'running' && empty($run['started_at'])) { $row['started_at'] = $now; $formats[] = '%s'; }
        if (in_array($to, self::TERMINAL_STATUSES, true)) { $row['completed_at'] = $now; $formats[] = '%s'; }
        if (!empty($summary)) { $row['result_summary_json'] = self::encode_json($summary); $formats[] = '%s'; }
        if ($error_summary !== '') { $row['error_summary'] = self::scrub_text($error_summary, 1000); $formats[] = '%s'; }
        $ok = $wpdb->update(self::table_name(), $row, ['id' => (int) $run['id']], $formats, ['%d']);
        return $ok === false ? self::err('ves_run_update_failed', 'Could not update canonical run.') : (int) $run['id'];
    }

    public static function transition_allowed($from, $to) {
        $from = self::sanitize_status($from);
        $to = self::sanitize_status($to);
        if ($from === $to) { return true; }
        if (in_array($from, self::TERMINAL_STATUSES, true)) { return false; }
        if ($from === 'queued') { return in_array($to, ['running', 'canceled'], true); }
        if ($from === 'running') { return in_array($to, ['completed', 'failed', 'timed_out', 'canceled', 'partial'], true); }
        return false;
    }

    public static function decode_row($row) {
        $row = is_array($row) ? $row : [];
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['workspace_id'] = (int) ($row['workspace_id'] ?? 0);
        $row['source_id'] = !empty($row['source_id']) ? (int) $row['source_id'] : 0;
        $row['parent_run_id'] = !empty($row['parent_run_id']) ? (int) $row['parent_run_id'] : 0;
        $row['initiated_by_user_id'] = !empty($row['initiated_by_user_id']) ? (int) $row['initiated_by_user_id'] : 0;
        $row['input_context'] = self::decode_json($row['input_context_json'] ?? '');
        $row['result_summary'] = self::decode_json($row['result_summary_json'] ?? '');
        $row['metadata'] = self::decode_json($row['metadata_json'] ?? '');
        return $row;
    }

    public static function sanitize_run_type($type) {
        $type = self::sanitize_key($type, 40);
        return in_array($type, self::RUN_TYPES, true) ? $type : 'research';
    }

    public static function sanitize_trigger_type($type) {
        $type = self::sanitize_key($type, 40);
        return in_array($type, self::TRIGGER_TYPES, true) ? $type : 'manual';
    }

    public static function sanitize_status($status) {
        $status = self::sanitize_key($status, 40);
        return in_array($status, self::STATUSES, true) ? $status : 'queued';
    }

    private static function now() { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }

    private static function encode_json($value) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : json_encode($value);
        return is_string($json) ? $json : '{}';
    }

    private static function decode_json($json) {
        if (!is_string($json) || $json === '') { return []; }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function clean_metadata($metadata) {
        $metadata = is_array($metadata) ? $metadata : [];
        $out = [];
        $forbidden = ['prompt', 'raw_prompt', 'messages', 'raw_response', 'provider_response', 'api_key', 'apikey', 'token', 'authorization', 'bearer', 'secret', 'password'];
        $i = 0;
        foreach ($metadata as $k => $v) {
            if ($i++ >= 30) { break; }
            $key = self::sanitize_key($k, 60);
            if ($key === '' || in_array($key, $forbidden, true)) { continue; }
            if (is_scalar($v) || $v === null) { $out[$key] = self::scrub_text((string) $v, 500); }
            elseif (is_array($v)) { $out[$key] = array_slice($v, 0, 20); }
        }
        return $out;
    }

    private static function sanitize_key($s, $max = 40) {
        $s = function_exists('sanitize_key') ? sanitize_key((string) $s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
        return substr($s, 0, max(1, (int) $max));
    }

    private static function sanitize_token($s, $max = 191, $allow_colon = false) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field((string) $s) : trim(strip_tags((string) $s));
        $pattern = $allow_colon ? '/[^a-zA-Z0-9_:\.\-]/' : '/[^a-zA-Z0-9_\.\-]/';
        return substr(preg_replace($pattern, '', $s), 0, max(1, (int) $max));
    }

    private static function scrub_text($s, $max) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field((string) $s) : trim(strip_tags((string) $s));
        if (class_exists('VES_Security_Event_Log') && method_exists('VES_Security_Event_Log', 'scrub')) { $s = VES_Security_Event_Log::scrub($s); }
        return strlen($s) > $max ? substr($s, 0, max(0, (int) $max - 1)) . '...' : $s;
    }

    private static function err($code, $message, $data = []) { return new WP_Error($code, $message, $data); }
}
