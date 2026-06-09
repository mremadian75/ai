<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight workflow activity log for intelligence objects.
 * Records user/system actions without storing hidden prompts or raw payloads.
 */
final class VES_Workflow_Events {
    const DB_VERSION = '1.1.0';
    const OPTION_KEY = 'ves_workflow_events_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_workflow_events';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id','workspace_id','user_id','run_id','entity_type','entity_id','event_type','previous_status','new_status','actor_role','source','request_id','note','created_at','meta_json'];
    }



    public static function required_indexes() {
        return ['workspace_id','user_id','run_id','entity_lookup','event_type','source','created_at'];
    }

    public static function existing_indexes() {
        global $wpdb;
        if (!self::table_exists()) { return []; }
        $rows = $wpdb->get_results('SHOW INDEX FROM ' . self::table_name(), ARRAY_A);
        $indexes = [];
        foreach ((array) $rows as $row) {
            if (!empty($row['Key_name'])) { $indexes[] = (string) $row['Key_name']; }
        }
        return array_values(array_unique($indexes));
    }

    public static function missing_required_indexes() {
        return array_values(array_diff(self::required_indexes(), self::existing_indexes()));
    }

    public static function missing_required_columns() {
        global $wpdb;
        if (!self::table_exists()) { return self::required_columns(); }
        $columns = $wpdb->get_col('DESC ' . self::table_name(), 0);
        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        return array_values(array_diff(self::required_columns(), $columns));
    }

    public static function schema_is_current() {
        return get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists() && empty(self::missing_required_columns()) && empty(self::missing_required_indexes());
    }

    public static function schema_health_check() {
        return [
            'table' => self::table_name(),
            'exists' => self::table_exists(),
            'db_version' => get_option(self::OPTION_KEY),
            'expected_version' => self::DB_VERSION,
            'missing_columns' => self::missing_required_columns(),
            'missing_indexes' => self::missing_required_indexes(),
            'is_current' => self::schema_is_current(),
        ];
    }

    public static function maybe_repair_schema() {
        if (!self::schema_is_current()) { self::create_table(true); }
        return self::schema_health_check();
    }

    public static function create_table($force = false) {
        if (!$force && self::schema_is_current()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            run_id varchar(80) NOT NULL DEFAULT '',
            entity_type varchar(64) NOT NULL DEFAULT '',
            entity_id varchar(120) NOT NULL DEFAULT '',
            event_type varchar(80) NOT NULL DEFAULT '',
            previous_status varchar(32) NOT NULL DEFAULT '',
            new_status varchar(32) NOT NULL DEFAULT '',
            actor_role varchar(40) NOT NULL DEFAULT '',
            source varchar(80) NOT NULL DEFAULT '',
            request_id varchar(80) NOT NULL DEFAULT '',
            note longtext NULL,
            created_at datetime NOT NULL,
            meta_json longtext NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY run_id (run_id),
            KEY entity_lookup (entity_type, entity_id),
            KEY event_type (event_type),
            KEY source (source),
            KEY request_id (request_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function record($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $run_id = sanitize_text_field((string) ($args['run_id'] ?? ''));
        $entity_type = sanitize_key((string) ($args['entity_type'] ?? ''));
        $entity_id = sanitize_text_field((string) ($args['entity_id'] ?? ''));
        $event_type = sanitize_key((string) ($args['event_type'] ?? ''));
        if ($event_type === '') { return 0; }
        $inserted = $wpdb->insert(self::table_name(), [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'run_id' => $run_id,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'event_type' => $event_type,
            'previous_status' => sanitize_key((string) ($args['previous_status'] ?? '')),
            'new_status' => sanitize_key((string) ($args['new_status'] ?? '')),
            'actor_role' => sanitize_key((string) ($args['actor_role'] ?? (function_exists('current_user_can') && current_user_can('manage_options') ? 'admin' : ($user_id > 0 ? 'user' : 'system')))),
            'source' => sanitize_key((string) ($args['source'] ?? 'frontend')),
            'request_id' => sanitize_text_field((string) ($args['request_id'] ?? '')),
            'note' => sanitize_textarea_field((string) ($args['note'] ?? '')),
            'created_at' => current_time('mysql'),
            'meta_json' => wp_json_encode(self::compact($args['meta'] ?? []), JSON_UNESCAPED_UNICODE),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    public static function list_by_run($run_id, $limit = 50, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $limit = max(1, min(200, (int) $limit));
        $filters = is_array($filters) ? $filters : [];
        if ($run_id === '') { return []; }
        $where = ['run_id = %s'];
        $params = [$run_id];
        $entity_type = sanitize_key((string) ($filters['entity_type'] ?? ''));
        $event_type = sanitize_key((string) ($filters['event_type'] ?? ''));
        $since = sanitize_text_field((string) ($filters['since'] ?? ''));
        if ($entity_type !== '') { $where[] = 'entity_type = %s'; $params[] = $entity_type; }
        if ($event_type !== '') { $where[] = 'event_type = %s'; $params[] = $event_type; }
        if ($since !== '') { $where[] = 'created_at >= %s'; $params[] = $since; }
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d', $params), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    private static function prepare($row) {
        $row = is_array($row) ? $row : [];
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'previous_status' => (string) ($row['previous_status'] ?? ''),
            'new_status' => (string) ($row['new_status'] ?? ''),
            'actor_role' => (string) ($row['actor_role'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'request_id' => (string) ($row['request_id'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'meta' => is_array($meta) ? $meta : [],
        ];
    }

    private static function compact($value, $depth = 0) {
        if ($depth > 4) { return '[truncated_depth]'; }
        if (is_scalar($value) || $value === null) {
            if (is_string($value)) { return substr($value, 0, 1800); }
            return $value;
        }
        if (!is_array($value)) { return null; }
        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if ($i >= 60) { $out['_truncated'] = true; break; }
            $out[is_int($key) ? $key : sanitize_key((string) $key)] = self::compact($item, $depth + 1);
            $i++;
        }
        return $out;
    }
}
