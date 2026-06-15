<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Decision_Edge_Store — append-only skeleton for the Marketing Decision Graph.
 */
final class VES_Decision_Edge_Store {
    const TABLE_SLUG  = 'ves_decision_edges';
    const DB_VERSION  = '1.0.0';
    const VERSION_OPT = 'ves_decision_edges_db_version';

    const RELATION_TYPES = [
        'derived_from', 'generated', 'captured', 'supports', 'contradicts',
        'reviewed', 'approved', 'rejected', 'revised',
        'reviewed_by', 'approved_by', 'rejected_by', 'revised_by',
        'used_as_evidence', 'created_memory', 'produced_outcome', 'informed_pattern',
        'spent_usage', 'superseded', 'depends_on', 'triggered_tool',
        'imported_from_mcp', 'exported_to_report',
    ];

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
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $table = self::table_name();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            run_id bigint(20) unsigned NULL,
            from_type varchar(60) NOT NULL,
            from_id bigint(20) unsigned NOT NULL,
            to_type varchar(60) NOT NULL,
            to_id bigint(20) unsigned NOT NULL,
            relation_type varchar(60) NOT NULL,
            confidence_score decimal(5,4) NULL,
            evidence_refs_json longtext NULL,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY run_id (run_id),
            KEY from_lookup (from_type,from_id),
            KEY to_lookup (to_type,to_id),
            KEY relation_type (relation_type),
            KEY created_at (created_at)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    public static function add_edge($workspace_id, $run_id, $from_type, $from_id, $to_type, $to_id, $relation_type, $args = []) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return self::err('ves_edge_no_db', 'Database unavailable.'); }
        $workspace_id = max(0, (int) $workspace_id);
        $run_id = max(0, (int) $run_id);
        $from_type = self::clean_type($from_type);
        $to_type = self::clean_type($to_type);
        $relation_type = self::clean_type($relation_type);
        $from_id = max(0, (int) $from_id);
        $to_id = max(0, (int) $to_id);
        if ($workspace_id <= 0) { return self::err('ves_edge_missing_workspace', 'workspace_id is required.'); }
        if ($from_type === '' || $to_type === '') { return self::err('ves_edge_bad_type', 'Decision edge requires from_type and to_type.'); }
        if ($from_id <= 0 || $to_id <= 0) { return self::err('ves_edge_bad_id', 'Decision edge requires positive ids.'); }
        if (!in_array($relation_type, self::RELATION_TYPES, true)) { return self::err('ves_edge_bad_relation', 'Unknown relation_type.'); }
        $args = is_array($args) ? $args : [];
        $confidence = isset($args['confidence_score']) && is_numeric($args['confidence_score']) ? max(0, min(1, (float) $args['confidence_score'])) : null;
        $evidence_refs = is_array($args['evidence_refs'] ?? null) ? array_values(array_slice($args['evidence_refs'], 0, 50)) : [];
        $metadata = self::clean_metadata(is_array($args['metadata'] ?? null) ? $args['metadata'] : []);
        $row = [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id > 0 ? $run_id : null,
            'from_type' => $from_type,
            'from_id' => $from_id,
            'to_type' => $to_type,
            'to_id' => $to_id,
            'relation_type' => $relation_type,
            'confidence_score' => $confidence,
            'evidence_refs_json' => self::encode($evidence_refs),
            'metadata_json' => self::encode($metadata),
            'created_at' => self::now(),
        ];
        $ok = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%d','%s','%d','%s','%f','%s','%s','%s']);
        return $ok === false ? self::err('ves_edge_insert_failed', 'Could not append decision edge.') : (int) $wpdb->insert_id;
    }

    public static function list_edges_for_run($workspace_id, $run_id) {
        $workspace_id = max(0, (int) $workspace_id); $run_id = max(0, (int) $run_id);
        if ($workspace_id <= 0 || $run_id <= 0) { return []; }
        return self::query_edges('workspace_id = %d AND run_id = %d', [$workspace_id, $run_id]);
    }

    public static function list_edges_for_target($workspace_id, $target_type, $target_id) {
        $workspace_id = max(0, (int) $workspace_id); $target_id = max(0, (int) $target_id); $target_type = self::clean_type($target_type);
        if ($workspace_id <= 0 || $target_id <= 0 || $target_type === '') { return []; }
        return self::query_edges('workspace_id = %d AND to_type = %s AND to_id = %d', [$workspace_id, $target_type, $target_id]);
    }

    public static function list_edges_from_source($workspace_id, $source_type, $source_id) {
        $workspace_id = max(0, (int) $workspace_id); $source_id = max(0, (int) $source_id); $source_type = self::clean_type($source_type);
        if ($workspace_id <= 0 || $source_id <= 0 || $source_type === '') { return []; }
        return self::query_edges('workspace_id = %d AND from_type = %s AND from_id = %d', [$workspace_id, $source_type, $source_id]);
    }

    private static function query_edges($where, $params) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return []; }
        $params[] = 100;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY id ASC LIMIT %d", $params), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        return array_map([__CLASS__, 'decode_row'], $rows);
    }

    public static function decode_row($row) {
        $row = is_array($row) ? $row : [];
        foreach (['id','workspace_id','run_id','from_id','to_id'] as $k) { $row[$k] = !empty($row[$k]) ? (int) $row[$k] : 0; }
        $row['confidence_score'] = isset($row['confidence_score']) && $row['confidence_score'] !== null ? (float) $row['confidence_score'] : null;
        $row['evidence_refs'] = self::decode($row['evidence_refs_json'] ?? '');
        $row['metadata'] = self::decode($row['metadata_json'] ?? '');
        return $row;
    }

    private static function clean_type($s) {
        $s = function_exists('sanitize_key') ? sanitize_key((string) $s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
        return substr($s, 0, 60);
    }

    private static function clean_metadata($metadata) {
        $out = [];
        $forbidden = ['prompt', 'raw_prompt', 'raw_response', 'provider_response', 'api_key', 'apikey', 'token', 'authorization', 'secret', 'password'];
        $i = 0;
        foreach ($metadata as $k => $v) {
            if ($i++ >= 25) { break; }
            $key = self::clean_type($k);
            if ($key === '' || in_array($key, $forbidden, true)) { continue; }
            if (is_scalar($v) || $v === null) { $out[$key] = self::scrub_text((string) $v, 300); }
        }
        return $out;
    }

    private static function encode($value) { $json = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : json_encode($value); return is_string($json) ? $json : '[]'; }
    private static function decode($json) { $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : []; return is_array($decoded) ? $decoded : []; }
    private static function now() { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function scrub_text($s, $max) { $s = function_exists('sanitize_text_field') ? sanitize_text_field((string) $s) : trim(strip_tags((string) $s)); return strlen($s) > $max ? substr($s, 0, max(0, (int) $max - 1)) . '...' : $s; }
    private static function err($code, $message) { return new WP_Error($code, $message); }
}
