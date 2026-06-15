<?php
if (!defined('ABSPATH')) { exit; }

/** Append-only Pattern Observation skeleton for review/outcome learning. */
final class VES_Pattern_Observation_Store {
    const TABLE_SLUG = 'ves_pattern_observations';
    const DB_VERSION = '1.0.0';
    const VERSION_OPT = 'ves_pattern_observations_db_version';

    public static function table_name(): string { global $wpdb; return $wpdb->prefix . self::TABLE_SLUG; }

    public static function create_table($force = false): void {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $table = self::table_name();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            run_id bigint(20) unsigned NULL,
            pattern_key varchar(120) NOT NULL,
            platform varchar(60) NOT NULL DEFAULT '',
            content_type varchar(80) NOT NULL DEFAULT '',
            hook_type varchar(80) NOT NULL DEFAULT '',
            message_angle varchar(120) NOT NULL DEFAULT '',
            success_count int unsigned NOT NULL DEFAULT 0,
            trial_count int unsigned NOT NULL DEFAULT 0,
            weighted_score decimal(5,4) NOT NULL DEFAULT 0.0000,
            confidence_score decimal(5,4) NOT NULL DEFAULT 0.0000,
            last_seen_at datetime NOT NULL,
            source_refs_json longtext NOT NULL,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY run_id (run_id),
            KEY pattern_key (pattern_key),
            KEY platform (platform),
            KEY content_type (content_type),
            KEY last_seen_at (last_seen_at)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    /** @return int|WP_Error */
    public static function record_pattern(array $args) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return self::wp_error('ves_pattern_no_db', 'Database unavailable.'); }
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return self::wp_error('ves_pattern_missing_workspace', 'workspace_id is required.'); }
        $pattern_key = self::clean_key((string) ($args['pattern_key'] ?? ''), 120);
        if ($pattern_key === '') { return self::wp_error('ves_pattern_missing_key', 'pattern_key is required.'); }
        $source_refs = self::normalize_refs($args['source_refs'] ?? []);
        if (empty($source_refs)) { return self::wp_error('ves_pattern_missing_refs', 'Pattern observations require source references.'); }
        $trial_count = max(0, (int) ($args['trial_count'] ?? 0));
        $success_count = max(0, min($trial_count > 0 ? $trial_count : PHP_INT_MAX, (int) ($args['success_count'] ?? 0)));
        $confidence = max(0, min(1, (float) ($args['confidence_score'] ?? 0)));
        $weighted = max(0, min(1, (float) ($args['weighted_score'] ?? ($trial_count > 0 ? $success_count / $trial_count : 0))));
        $now = self::now();
        $row = [
            'workspace_id' => $workspace_id,
            'run_id' => max(0, (int) ($args['run_id'] ?? 0)) ?: null,
            'pattern_key' => $pattern_key,
            'platform' => self::clean_key((string) ($args['platform'] ?? ''), 60),
            'content_type' => self::clean_key((string) ($args['content_type'] ?? ''), 80),
            'hook_type' => self::clean_key((string) ($args['hook_type'] ?? ''), 80),
            'message_angle' => self::clean_key((string) ($args['message_angle'] ?? ''), 120),
            'success_count' => $success_count,
            'trial_count' => $trial_count,
            'weighted_score' => $weighted,
            'confidence_score' => $confidence,
            'last_seen_at' => self::datetime((string) ($args['last_seen_at'] ?? '')) ?: $now,
            'source_refs_json' => self::encode_json($source_refs),
            'metadata_json' => self::encode_json(is_array($args['metadata'] ?? null) ? $args['metadata'] : []),
            'created_at' => $now,
        ];
        $ok = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%s','%s','%s','%s','%d','%d','%f','%f','%s','%s','%s','%s']);
        if ($ok === false) { return self::wp_error('ves_pattern_insert_failed', 'Could not record pattern observation.'); }
        $id = (int) $wpdb->insert_id;
        $edge_run_id = (int) ($row['run_id'] ?? 0);
        if ($id > 0 && $edge_run_id > 0 && class_exists('VES_Decision_Edge_Service')) {
            VES_Decision_Edge_Service::add_edge($workspace_id, $edge_run_id, 'run', $edge_run_id, 'pattern', $id, 'informed_pattern', [
                'evidence_refs' => $source_refs,
                'metadata' => ['pattern_key' => $pattern_key],
            ]);
        }
        if ($id > 0 && class_exists('VES_Usage_Service')) {
            VES_Usage_Service::record_zero_cost_event(['workspace_id' => $workspace_id, 'run_id' => (int) ($row['run_id'] ?? 0), 'target_type' => 'pattern', 'target_id' => $id, 'event_type' => 'pattern_observation_created', 'idempotency_key' => 'fi-pattern:' . $id]);
        }
        return $id;
    }

    public static function list_for_workspace($workspace_id, array $args = []): array {
        global $wpdb;
        $workspace_id = max(0, (int) $workspace_id);
        if ($workspace_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return []; }
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d ORDER BY last_seen_at DESC, id DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'decode_row'], is_array($rows) ? $rows : []);
    }

    private static function decode_row(array $row): array { foreach (['id','workspace_id','run_id','success_count','trial_count'] as $key) { if (isset($row[$key])) { $row[$key] = (int) $row[$key]; } } foreach (['source_refs_json' => 'source_refs', 'metadata_json' => 'metadata'] as $col => $alias) { $d = json_decode((string) ($row[$col] ?? ''), true); $row[$alias] = is_array($d) ? $d : []; } return $row; }
    private static function normalize_refs($refs): array { $out = []; foreach ((array) $refs as $ref) { if (is_scalar($ref)) { $v = trim((string) $ref); if ($v !== '') { $out[] = substr($v, 0, 160); } } elseif (is_array($ref)) { $clean = []; foreach ($ref as $k => $v) { if (is_scalar($v)) { $clean[self::clean_key($k, 60)] = substr((string) $v, 0, 160); } } if (!empty($clean)) { $out[] = $clean; } } if (count($out) >= 50) { break; } } return $out; }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function datetime(string $value): string { return preg_match('/^\d{4}-\d{2}-\d{2}(\s|T)\d{2}:\d{2}(:\d{2})?$/', $value) ? str_replace('T', ' ', strlen($value) === 16 ? $value . ':00' : $value) : ''; }
    private static function clean_key($value, int $max = 60): string { $value = function_exists('sanitize_key') ? sanitize_key((string) $value) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); return substr($value, 0, $max); }
    private static function encode_json(array $data): string { $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data); return is_string($json) ? $json : '[]'; }
    private static function wp_error($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
