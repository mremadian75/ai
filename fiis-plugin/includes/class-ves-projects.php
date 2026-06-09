<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Minimal database-backed project layer for workspace isolation.
 *
 * The previous project context lived only in user meta. This class creates a
 * real project entity that can be referenced by memory, evidence, runs,
 * reports and usage events. It deliberately stays small: no team hierarchy,
 * a diagnostic-only project admin screen, and no billing assumptions beyond access checks.
 */
final class VES_Projects {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'ves_projects_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_projects';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id','workspace_id','user_id','name','brand_name','market','language','status','settings_json','created_at','updated_at'];
    }

    public static function required_indexes() {
        return ['workspace_id','user_id','brand_name','status','updated_at'];
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

    public static function missing_required_columns() {
        global $wpdb;
        if (!self::table_exists()) { return self::required_columns(); }
        $columns = $wpdb->get_col('DESC ' . self::table_name(), 0);
        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        return array_values(array_diff(self::required_columns(), $columns));
    }

    public static function missing_required_indexes() {
        return array_values(array_diff(self::required_indexes(), self::existing_indexes()));
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
            name varchar(190) NOT NULL DEFAULT '',
            brand_name varchar(190) NOT NULL DEFAULT '',
            market varchar(120) NOT NULL DEFAULT '',
            language varchar(24) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT 'active',
            settings_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY brand_name (brand_name),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function maybe_repair_schema() {
        if (!self::schema_is_current()) { self::create_table(true); }
        return self::schema_health_check();
    }

    public static function infer_workspace_id($request = [], $user_id = 0) {
        $request = is_array($request) ? $request : [];
        if (class_exists('VES_Memory_Records')) {
            $workspace_request = $request;
            unset($workspace_request['project_id'], $workspace_request['projectId']);
            return (int) VES_Memory_Records::infer_workspace_id($workspace_request, $user_id);
        }
        $user_id = max(0, (int) ($user_id ?: ($request['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))));
        foreach (['workspace_id','workspaceId'] as $key) {
            if (!isset($request[$key]) || !is_numeric($request[$key])) { continue; }
            $candidate = max(0, (int) $request[$key]);
            if ($candidate > 0 && ((function_exists('current_user_can') && current_user_can('manage_options')) || $candidate === $user_id)) { return $candidate; }
        }
        return $user_id > 0 ? $user_id : 0;
    }

    public static function find($id) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        if ($id <= 0) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return is_array($row) ? self::prepare_row($row) : null;
    }


    public static function list_for_admin($limit = 200) {
        global $wpdb;
        self::create_table();
        $limit = max(1, min(1000, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' ORDER BY updated_at DESC, id DESC LIMIT %d', $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare_row'], is_array($rows) ? $rows : []);
    }

    public static function list_for_user($user_id = 0, $workspace_id = 0, $limit = 100) {
        global $wpdb;
        self::create_table();
        $user_id = max(0, (int) ($user_id ?: (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = max(0, (int) $workspace_id);
        $limit = max(1, min(500, (int) $limit));
        if ($user_id <= 0) { return []; }
        if ($workspace_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d AND workspace_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d', $user_id, $workspace_id, $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d', $user_id, $limit), ARRAY_A);
        }
        return array_map([__CLASS__, 'prepare_row'], is_array($rows) ? $rows : []);
    }

    public static function find_for_user_brand($workspace_id, $user_id, $brand_name) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $brand = strtolower(trim(sanitize_text_field((string) $brand_name)));
        if ($workspace_id <= 0 || $user_id <= 0 || $brand === '') { return null; }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d AND user_id = %d AND LOWER(brand_name) = %s AND status != %s ORDER BY updated_at DESC, id DESC LIMIT 1',
            $workspace_id,
            $user_id,
            $brand,
            'archived'
        ), ARRAY_A);
        return is_array($row) ? self::prepare_row($row) : null;
    }

    public static function create($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $now = current_time('mysql');
        $row = [
            'workspace_id' => max(0, (int) ($args['workspace_id'] ?? 0)),
            'user_id' => max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))),
            'name' => self::truncate(sanitize_text_field((string) ($args['name'] ?? 'Default Project')), 190),
            'brand_name' => self::truncate(sanitize_text_field((string) ($args['brand_name'] ?? '')), 190),
            'market' => self::truncate(sanitize_text_field((string) ($args['market'] ?? '')), 120),
            'language' => self::truncate(sanitize_text_field((string) ($args['language'] ?? '')), 24),
            'status' => self::safe_status((string) ($args['status'] ?? 'active')),
            'settings_json' => wp_json_encode(self::compact_settings($args['settings'] ?? $args['settings_json'] ?? []), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($row['workspace_id'] <= 0) { $row['workspace_id'] = self::infer_workspace_id($args, $row['user_id']); }
        if ($row['name'] === '') { $row['name'] = $row['brand_name'] !== '' ? $row['brand_name'] : 'Default Project'; }
        $inserted = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s']);
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function create_default_project($workspace_id = 0, $user_id = 0, $args = []) {
        $args = is_array($args) ? $args : [];
        $user_id = max(0, (int) ($user_id ?: ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))));
        $workspace_id = max(0, (int) ($workspace_id ?: ($args['workspace_id'] ?? self::infer_workspace_id($args, $user_id))));
        if ($user_id <= 0 || $workspace_id <= 0) { return 0; }
        $brand = sanitize_text_field((string) ($args['brand_name'] ?? $args['brandName'] ?? ''));
        if ($brand !== '') {
            $existing = self::find_for_user_brand($workspace_id, $user_id, $brand);
            if ($existing && !empty($existing['id'])) { return (int) $existing['id']; }
        }
        $name = sanitize_text_field((string) ($args['name'] ?? ''));
        if ($name === '') { $name = $brand !== '' ? $brand : 'Default Project'; }
        return self::create(array_merge($args, [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'name' => $name,
            'brand_name' => $brand,
            'status' => 'active',
        ]));
    }

    public static function resolve_project_from_request($request = [], $user_id = 0, $workspace_id = 0) {
        $request = is_array($request) ? $request : [];
        $user_id = max(0, (int) ($user_id ?: ($request['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))));
        $workspace_id = max(0, (int) ($workspace_id ?: self::infer_workspace_id($request, $user_id)));
        foreach (['project_id','projectId'] as $key) {
            if (empty($request[$key]) || !is_numeric($request[$key])) { continue; }
            $project_id = max(0, (int) $request[$key]);
            if ($project_id > 0 && self::user_can_access_project($project_id, $user_id, $workspace_id)) { return $project_id; }
            return 0;
        }
        $brand = sanitize_text_field((string) ($request['brand_name'] ?? $request['brandName'] ?? ''));
        if ($brand !== '') {
            $existing = self::find_for_user_brand($workspace_id, $user_id, $brand);
            if ($existing && !empty($existing['id'])) { return (int) $existing['id']; }
        }
        return self::create_default_project($workspace_id, $user_id, $request);
    }

    public static function get_current_project_id($request = []) {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        return self::resolve_project_from_request(is_array($request) ? $request : [], $user_id, 0);
    }

    public static function user_can_access_project($project_id, $user_id = 0, $workspace_id = 0) {
        $project = self::find($project_id);
        if (!$project) { return false; }
        if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }
        $user_id = max(0, (int) ($user_id ?: (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = max(0, (int) $workspace_id);
        if ($user_id <= 0) { return false; }
        // SECURITY (IDOR fix): a non-admin may only access a project they own. An
        // owner-less row (user_id = 0 — the table default for system/cron-created or
        // legacy rows) must NOT be accessible to every logged-in user. Admins already
        // returned true above via manage_options.
        if ((int) $project['user_id'] !== $user_id) { return false; }
        if ($workspace_id > 0 && (int) $project['workspace_id'] !== $workspace_id) { return false; }
        return true;
    }

    public static function current_project_summary($request = [], $user_id = 0) {
        $project_id = self::resolve_project_from_request($request, $user_id, 0);
        return $project_id > 0 ? self::find($project_id) : null;
    }


    public static function list_recent($limit = 50, $user_id = 0, $workspace_id = 0) {
        global $wpdb;
        self::create_table();
        $limit = max(1, min(200, (int) $limit));
        $user_id = max(0, (int) $user_id);
        $workspace_id = max(0, (int) $workspace_id);
        $where = ['1=1'];
        $params = [];
        if ($workspace_id > 0) { $where[] = 'workspace_id = %d'; $params[] = $workspace_id; }
        if ($user_id > 0 && !(function_exists('current_user_can') && current_user_can('manage_options'))) { $where[] = 'user_id = %d'; $params[] = $user_id; }
        $where_sql = implode(' AND ', $where);
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . $where_sql . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
        $params[] = $limit;
        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return array_map([__CLASS__, 'prepare_row'], is_array($rows) ? $rows : []);
    }


    public static function list_projects($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $where = ['1=1'];
        $params = [];
        if (!empty($args['workspace_id'])) { $where[] = 'workspace_id = %d'; $params[] = (int) $args['workspace_id']; }
        if (!empty($args['user_id'])) { $where[] = 'user_id = %d'; $params[] = (int) $args['user_id']; }
        if (isset($args['status']) && $args['status'] !== '') { $where[] = 'status = %s'; $params[] = self::safe_status((string) $args['status']); }
        $limit = max(1, min(300, (int) ($args['limit'] ?? 120)));
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return array_map([__CLASS__, 'prepare_row'], is_array($rows) ? $rows : []);
    }

    public static function update_status($project_id, $status) {
        global $wpdb;
        self::create_table();
        $project_id = max(0, (int) $project_id);
        $status = self::safe_status($status);
        if ($project_id <= 0) { return false; }
        return false !== $wpdb->update(self::table_name(), [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $project_id], ['%s','%s'], ['%d']);
    }

    public static function admin_counts_for_project($project_id) {
        global $wpdb;
        $project_id = max(0, (int) $project_id);
        $out = ['memory_records' => 0, 'evidence_records' => 0, 'trend_runs' => 0, 'brand_audit_runs' => 0];
        if ($project_id <= 0) { return $out; }
        $count_table = static function($table, $column = 'project_id') use ($wpdb, $project_id) {
            if (!$table) { return 0; }
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) { return 0; }
            $cols = $wpdb->get_col('DESC ' . $table, 0);
            if (!is_array($cols) || !in_array($column, $cols, true)) { return 0; }
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = %d', $project_id));
        };
        if (class_exists('VES_Memory_Records')) { $out['memory_records'] = $count_table(VES_Memory_Records::table_name()); }
        if (class_exists('VES_Evidence_Store')) { $out['evidence_records'] = $count_table(VES_Evidence_Store::table_name()); }
        if (class_exists('VES_Trend_Run_Store')) { $out['trend_runs'] = $count_table(VES_Trend_Run_Store::table_name()); }
        if (class_exists('VES_Brand_Audit_Run_Store')) { $out['brand_audit_runs'] = $count_table(VES_Brand_Audit_Run_Store::table_name()); }
        return $out;
    }

    private static function prepare_row($row) {
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'market' => (string) ($row['market'] ?? ''),
            'language' => (string) ($row['language'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'settings' => is_array($settings) ? $settings : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function safe_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, ['active','paused','archived'], true) ? $status : 'active';
    }

    private static function compact_settings($value) {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private static function truncate($value, $max) {
        $value = (string) $value;
        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $max) { return mb_substr($value, 0, $max, 'UTF-8'); }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
