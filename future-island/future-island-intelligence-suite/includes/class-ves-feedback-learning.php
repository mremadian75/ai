<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Feedback learning ledger.
 *
 * Records explicit admin/user feedback signals and converts them into retrieval
 * weights. This is product feedback learning, not model training.
 */
final class VES_Feedback_Learning {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'ves_feedback_learning_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_feedback_events';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function create_table($force = false) {
        if (!$force && get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(40) NOT NULL DEFAULT '',
            object_id varchar(80) NOT NULL DEFAULT '',
            feedback_type varchar(40) NOT NULL DEFAULT '',
            rating smallint NOT NULL DEFAULT 0,
            comment text NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY object_lookup (workspace_id, object_type, object_id),
            KEY feedback_type (feedback_type),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function record_feedback($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        $object_type = class_exists('VES_Semantic_Index') ? VES_Semantic_Index::safe_object_type($args['object_type'] ?? 'memory') : sanitize_key((string) ($args['object_type'] ?? 'memory'));
        $object_id = sanitize_text_field((string) ($args['object_id'] ?? ''));
        $feedback_type = self::safe_feedback_type($args['feedback_type'] ?? 'helpful');
        $rating = self::rating_from($args['rating'] ?? null, $feedback_type);
        if ($workspace_id <= 0 || $object_id === '') { return 0; }
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))),
            'object_type' => $object_type,
            'object_id' => self::truncate($object_id, 80),
            'feedback_type' => $feedback_type,
            'rating' => $rating,
            'comment' => self::truncate(sanitize_textarea_field((string) ($args['comment'] ?? '')), 1200),
            'context_json' => wp_json_encode(self::compact_context($args['context'] ?? []), JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql'),
        ];
        $inserted = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%s','%s','%d','%s','%s','%s']);
        if (false === $inserted) { return 0; }
        if (class_exists('VES_Semantic_Index')) {
            VES_Semantic_Index::refresh_feedback_score($workspace_id, $object_type, $row['object_id']);
        }
        return (int) $wpdb->insert_id;
    }

    public static function aggregate_score($workspace_id, $object_type, $object_id) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $object_type = sanitize_key((string) $object_type);
        $object_id = sanitize_text_field((string) $object_id);
        if ($workspace_id <= 0 || $object_type === '' || $object_id === '') { return 0.0; }
        $rating = (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(rating),0) FROM ' . self::table_name() . ' WHERE workspace_id = %d AND object_type = %s AND object_id = %s',
            $workspace_id,
            $object_type,
            $object_id
        ));
        return max(-60, min(60, $rating * 12));
    }

    public static function list_for_admin($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(1, min(200, (int) ($args['limit'] ?? 40)));
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $where = ['1=1'];
        $params = [];
        if ($workspace_id > 0) { $where[] = 'workspace_id = %d'; $params[] = $workspace_id; }
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$limit])), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function safe_feedback_type($type) {
        $type = sanitize_key((string) $type);
        $allowed = ['helpful','not_helpful','approved','rejected','used_in_brief','ignored','outdated','duplicate'];
        return in_array($type, $allowed, true) ? $type : 'helpful';
    }

    public static function rating_from($rating, $feedback_type = 'helpful') {
        if (is_numeric($rating)) { return max(-3, min(3, (int) $rating)); }
        $map = [
            'helpful' => 1,
            'approved' => 2,
            'used_in_brief' => 2,
            'not_helpful' => -1,
            'ignored' => -1,
            'rejected' => -2,
            'outdated' => -3,
            'duplicate' => -1,
        ];
        return $map[self::safe_feedback_type($feedback_type)] ?? 0;
    }

    public static function prepare($row) {
        $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'object_type' => (string) ($row['object_type'] ?? ''),
            'object_id' => (string) ($row['object_id'] ?? ''),
            'feedback_type' => (string) ($row['feedback_type'] ?? ''),
            'rating' => (int) ($row['rating'] ?? 0),
            'comment' => (string) ($row['comment'] ?? ''),
            'context' => is_array($context) ? $context : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private static function compact_context($context, $depth = 0) {
        if ($depth > 3) { return '[trimmed]'; }
        if (is_array($context)) {
            $out = [];
            $count = 0;
            foreach ($context as $key => $value) {
                if (++$count > 20) { $out['__trimmed__'] = true; break; }
                $out[sanitize_key((string) $key)] = self::compact_context($value, $depth + 1);
            }
            return $out;
        }
        if (is_object($context)) { return self::compact_context((array) $context, $depth + 1); }
        if (is_bool($context) || is_int($context) || is_float($context) || $context === null) { return $context; }
        return self::truncate(sanitize_text_field((string) $context), 500);
    }

    private static function truncate($value, $max) {
        $value = trim((string) $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) { return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value; }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
