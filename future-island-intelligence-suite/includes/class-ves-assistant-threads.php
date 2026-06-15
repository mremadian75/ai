<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Minimal persistent assistant threads/messages for the Intelligence Suite assistant v1.
 */
final class VES_Assistant_Threads {
    const DB_VERSION = '1.2.0';
    const OPTION_KEY = 'ves_assistant_threads_db_version';

    public static function threads_table() {
        global $wpdb;
        return $wpdb->prefix . 'ves_assistant_threads';
    }

    public static function messages_table() {
        global $wpdb;
        return $wpdb->prefix . 'ves_assistant_messages';
    }



    public static function table_exists($table_key = 'threads') {
        global $wpdb;
        $table = $table_key === 'messages' ? self::messages_table() : self::threads_table();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns($table_key = '') {
        $threads = ['id','workspace_id','user_id','brand_name','context_type','context_id','title','status','created_at','updated_at'];
        $messages = ['id','thread_id','user_id','role','message','content_json','context_json','created_at'];
        if ($table_key === 'threads') { return $threads; }
        if ($table_key === 'messages') { return $messages; }
        return ['threads' => $threads, 'messages' => $messages];
    }



    public static function required_indexes($table_key = '') {
        $threads = ['workspace_id','user_id','context_lookup','updated_at'];
        $messages = ['thread_id','user_id','role','created_at'];
        if ($table_key === 'threads') { return $threads; }
        if ($table_key === 'messages') { return $messages; }
        return ['threads' => $threads, 'messages' => $messages];
    }

    public static function existing_indexes($table_key = 'threads') {
        global $wpdb;
        $table = $table_key === 'messages' ? self::messages_table() : self::threads_table();
        if (!self::table_exists($table_key)) { return []; }
        $rows = $wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A);
        $indexes = [];
        foreach ((array) $rows as $row) {
            if (!empty($row['Key_name'])) { $indexes[] = (string) $row['Key_name']; }
        }
        return array_values(array_unique($indexes));
    }

    public static function missing_required_indexes($table_key = '') {
        $keys = $table_key !== '' ? [$table_key] : ['threads', 'messages'];
        $missing = [];
        foreach ($keys as $key) {
            $diff = array_values(array_diff(self::required_indexes($key), self::existing_indexes($key)));
            if (!empty($diff)) { $missing[$key] = $diff; }
        }
        return $table_key !== '' ? ($missing[$table_key] ?? []) : $missing;
    }

    public static function missing_required_columns($table_key = '') {
        global $wpdb;
        $keys = $table_key !== '' ? [$table_key] : ['threads', 'messages'];
        $missing = [];
        foreach ($keys as $key) {
            $table = $key === 'messages' ? self::messages_table() : self::threads_table();
            if (!self::table_exists($key)) { $missing[$key] = self::required_columns($key); continue; }
            $columns = $wpdb->get_col('DESC ' . $table, 0);
            $columns = is_array($columns) ? array_map('strval', $columns) : [];
            $diff = array_values(array_diff(self::required_columns($key), $columns));
            if (!empty($diff)) { $missing[$key] = $diff; }
        }
        return $table_key !== '' ? ($missing[$table_key] ?? []) : $missing;
    }

    public static function schema_is_current() {
        return get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists('threads') && self::table_exists('messages') && empty(self::missing_required_columns()) && empty(self::missing_required_indexes());
    }

    public static function schema_health_check() {
        return [
            'tables' => ['threads' => self::threads_table(), 'messages' => self::messages_table()],
            'exists' => ['threads' => self::table_exists('threads'), 'messages' => self::table_exists('messages')],
            'db_version' => get_option(self::OPTION_KEY),
            'expected_version' => self::DB_VERSION,
            'missing_columns' => self::missing_required_columns(),
            'missing_indexes' => self::missing_required_indexes(),
            'is_current' => self::schema_is_current(),
        ];
    }

    public static function maybe_repair_schema() {
        if (!self::schema_is_current()) { self::create_tables(true); }
        return self::schema_health_check();
    }

    public static function create_tables($force = false) {
        if (!$force && self::schema_is_current()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $threads = self::threads_table();
        $messages = self::messages_table();
        $sql_threads = "CREATE TABLE {$threads} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            brand_name varchar(190) NOT NULL DEFAULT '',
            context_type varchar(64) NOT NULL DEFAULT '',
            context_id varchar(120) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY context_lookup (context_type, context_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";
        $sql_messages = "CREATE TABLE {$messages} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            role varchar(32) NOT NULL DEFAULT '',
            message longtext NULL,
            content_json longtext NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id),
            KEY user_id (user_id),
            KEY role (role),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_threads);
        dbDelta($sql_messages);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function get_or_create_thread($args = []) {
        global $wpdb;
        self::create_tables();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $context_type = sanitize_key((string) ($args['context_type'] ?? 'general'));
        $context_id = sanitize_text_field((string) ($args['context_id'] ?? ''));
        $brand_name = sanitize_text_field((string) ($args['brand_name'] ?? ''));
        $default_thread_title = class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant';
        $title = self::truncate(sanitize_text_field((string) ($args['title'] ?? $default_thread_title)), 240);
        $existing = 0;
        if ($user_id > 0 && $context_type !== '' && $context_id !== '') {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::threads_table() . ' WHERE user_id = %d AND context_type = %s AND context_id = %s AND status = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
                $user_id,
                $context_type,
                $context_id,
                'active'
            ));
        }
        if ($existing > 0) {
            $wpdb->update(self::threads_table(), ['updated_at' => current_time('mysql')], ['id' => $existing], ['%s'], ['%d']);
            return $existing;
        }
        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::threads_table(), [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'brand_name' => $brand_name,
            'context_type' => $context_type,
            'context_id' => $context_id,
            'title' => $title,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    public static function add_message($thread_id, $role, $message, $content = [], $context = []) {
        global $wpdb;
        self::create_tables();
        $thread_id = max(0, (int) $thread_id);
        if ($thread_id <= 0) { return 0; }
        $role = sanitize_key((string) $role);
        if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) { $role = 'user'; }
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::messages_table(), [
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'role' => $role,
            'message' => self::truncate((string) $message, 12000),
            'content_json' => wp_json_encode(self::compact($content), JSON_UNESCAPED_UNICODE),
            'context_json' => wp_json_encode(self::compact($context), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);
        if ($inserted !== false) {
            $wpdb->update(self::threads_table(), ['updated_at' => $now], ['id' => $thread_id], ['%s'], ['%d']);
            return (int) $wpdb->insert_id;
        }
        return 0;
    }

    public static function list_threads_for_context($workspace_id, $user_id, $context_type, $context_id, $limit = 10) {
        global $wpdb;
        self::create_tables();
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $context_type = sanitize_key((string) $context_type);
        $context_id = sanitize_text_field((string) $context_id);
        $limit = max(1, min(30, (int) $limit));
        if ($user_id <= 0) { return []; }
        $where = ['user_id = %d'];
        $params = [$user_id];
        if ($workspace_id > 0) { $where[] = 'workspace_id = %d'; $params[] = $workspace_id; }
        if ($context_type !== '') { $where[] = 'context_type = %s'; $params[] = $context_type; }
        if ($context_id !== '') { $where[] = 'context_id = %s'; $params[] = $context_id; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::threads_table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT %d', array_merge($params, [$limit])), ARRAY_A);
        return array_map([__CLASS__, 'prepare_thread'], (array) $rows);
    }

    public static function get_recent_messages($thread_id, $limit = 8) {
        global $wpdb;
        self::create_tables();
        $thread_id = max(0, (int) $thread_id);
        $limit = max(1, min(20, (int) $limit));
        if ($thread_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::messages_table() . ' WHERE thread_id = %d ORDER BY id DESC LIMIT %d', $thread_id, $limit), ARRAY_A);
        $rows = array_reverse((array) $rows);
        return array_map([__CLASS__, 'prepare_message'], $rows);
    }

    private static function prepare_thread($row) {
        $row = is_array($row) ? $row : [];
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'context_type' => (string) ($row['context_type'] ?? ''),
            'context_id' => (string) ($row['context_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function prepare_message($row) {
        $row = is_array($row) ? $row : [];
        $content = json_decode((string) ($row['content_json'] ?? '{}'), true);
        $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'thread_id' => (int) ($row['thread_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'role' => sanitize_key((string) ($row['role'] ?? '')),
            'message' => (string) ($row['message'] ?? ''),
            'content' => is_array($content) ? $content : [],
            'context' => is_array($context) ? $context : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }


    public static function get_thread($thread_id) {
        global $wpdb;
        self::create_tables();
        $thread_id = max(0, (int) $thread_id);
        if ($thread_id <= 0) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::threads_table() . ' WHERE id = %d LIMIT 1', $thread_id), ARRAY_A);
        return is_array($row) ? self::prepare_thread($row) : null;
    }

    public static function thread_matches_context($thread_id, $context_type, $context_id, $workspace_id = 0) {
        $thread = self::get_thread($thread_id);
        if (!$thread) { return false; }
        $context_type = sanitize_key((string) $context_type);
        $context_id = sanitize_text_field((string) $context_id);
        $workspace_id = max(0, (int) $workspace_id);
        if ($context_type !== '' && (string) ($thread['context_type'] ?? '') !== $context_type) { return false; }
        if ($context_id !== '' && (string) ($thread['context_id'] ?? '') !== $context_id) { return false; }
        if ($workspace_id > 0 && (int) ($thread['workspace_id'] ?? 0) !== $workspace_id) { return false; }
        return true;
    }

    public static function user_can_access_thread_context($thread_id, $user_id, $context_type, $context_id, $workspace_id = 0) {
        if (!self::user_can_access_thread($thread_id, $user_id)) { return false; }
        if (function_exists('current_user_can') && current_user_can('manage_options') && $context_id === '') { return true; }
        return self::thread_matches_context($thread_id, $context_type, $context_id, $workspace_id);
    }

    public static function user_can_access_thread($thread_id, $user_id = 0) {
        global $wpdb;
        self::create_tables();
        $thread_id = max(0, (int) $thread_id);
        if ($thread_id <= 0) { return false; }
        if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }
        $user_id = max(0, (int) ($user_id ?: (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        if ($user_id <= 0) { return false; }
        $owner = (int) $wpdb->get_var($wpdb->prepare('SELECT user_id FROM ' . self::threads_table() . ' WHERE id = %d', $thread_id));
        return $owner === $user_id;
    }

    private static function compact($content, $depth = 0) {
        if ($depth > 4) { return '[truncated_depth]'; }
        if (is_scalar($content) || $content === null) {
            return is_string($content) ? self::truncate($content, 6000) : $content;
        }
        if (!is_array($content)) { return ''; }
        $out = [];
        $i = 0;
        foreach ($content as $key => $value) {
            if ($i >= 80) { $out['_truncated'] = true; break; }
            $out[is_int($key) ? $key : sanitize_key((string) $key)] = self::compact($value, $depth + 1);
            $i++;
        }
        return $out;
    }

    private static function truncate($text, $max) {
        $text = (string) $text;
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) { return mb_substr($text, 0, $max); }
        if (strlen($text) > $max) { return substr($text, 0, $max); }
        return $text;
    }
}
