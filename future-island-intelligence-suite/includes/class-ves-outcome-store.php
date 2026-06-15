<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Outcome_Store — manual Outcome Receipts for the Future Island spine.
 *
 * Additive append-only store. Starts manual on purpose: connects briefs, drafts,
 * assets, reports, or campaigns to client/channel reality without waiting for
 * analytics integrations.
 */
final class VES_Outcome_Store {
    const TABLE_SLUG = 'ves_outcomes';
    const DB_VERSION = '1.0.0';
    const VERSION_OPT = 'ves_outcomes_db_version';

    const TARGET_TYPES = ['draft','asset','brief','campaign','report','insight','memory_record'];
    const OUTCOME_TYPES = ['manual_note','performance_metric','client_feedback','campaign_result','qualitative_signal','social_performance','publication_status','sales_feedback','aeo_visibility_movement','creative_test_result'];
    const RESULT_LABELS = ['won','lost','mixed','inconclusive','pending','approved','rejected','published','not_published','performed_well','performed_poorly'];

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table($force = false): void {
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
            target_type varchar(60) NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            channel varchar(80) NOT NULL DEFAULT '',
            outcome_type varchar(60) NOT NULL,
            observed_at datetime NULL,
            metrics_json longtext NULL,
            qualitative_note longtext NULL,
            result_label varchar(40) NOT NULL DEFAULT 'inconclusive',
            confidence_score decimal(5,4) NULL,
            source_ref varchar(255) NOT NULL DEFAULT '',
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY run_id (run_id),
            KEY target_lookup (target_type,target_id),
            KEY outcome_type (outcome_type),
            KEY result_label (result_label),
            KEY observed_at (observed_at),
            KEY created_at (created_at)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    /** @return int|WP_Error */
    public static function record_outcome(array $args) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return self::err('ves_outcome_no_db', 'Database unavailable.'); }
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return self::err('ves_outcome_missing_workspace', 'workspace_id is required.'); }

        $target_type = self::clean_key((string) ($args['target_type'] ?? ''), 60);
        $target_id = max(0, (int) ($args['target_id'] ?? 0));
        if (!in_array($target_type, self::TARGET_TYPES, true) || $target_id <= 0) { return self::err('ves_outcome_bad_target', 'Outcome requires a valid target.'); }

        $outcome_type = self::clean_key((string) ($args['outcome_type'] ?? 'manual_note'), 60);
        if (!in_array($outcome_type, self::OUTCOME_TYPES, true)) { return self::err('ves_outcome_bad_type', 'Unknown outcome type.'); }

        $label = self::clean_key((string) ($args['result_label'] ?? 'inconclusive'), 40);
        if (!in_array($label, self::RESULT_LABELS, true)) { $label = 'inconclusive'; }

        $note = self::long_text((string) ($args['qualitative_note'] ?? ''), 5000);
        $metrics = is_array($args['metrics'] ?? null) ? $args['metrics'] : [];
        if ($note === '' && empty($metrics)) { return self::err('ves_outcome_empty', 'Outcome requires a note or metrics.'); }

        $confidence = isset($args['confidence_score']) && $args['confidence_score'] !== '' ? max(0, min(1, (float) $args['confidence_score'])) : null;
        $run_id = max(0, (int) ($args['run_id'] ?? 0));
        $row = [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id > 0 ? $run_id : null,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'channel' => self::clean_key((string) ($args['channel'] ?? ''), 80),
            'outcome_type' => $outcome_type,
            'observed_at' => self::date_or_null($args['observed_at'] ?? null),
            'metrics_json' => self::encode_json($metrics),
            'qualitative_note' => $note,
            'result_label' => $label,
            'confidence_score' => $confidence,
            'source_ref' => self::clean_text((string) ($args['source_ref'] ?? ''), 255),
            'metadata_json' => self::encode_json(is_array($args['metadata'] ?? null) ? $args['metadata'] : []),
            'created_at' => self::now(),
        ];
        $ok = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%d','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s']);
        if ($ok === false) { return self::err('ves_outcome_insert_failed', 'Could not record outcome.'); }
        $id = (int) $wpdb->insert_id;
        if ($id > 0 && class_exists('VES_Decision_Edge_Service')) {
            VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, $target_type, $target_id, 'outcome', $id, 'produced_outcome', [
                'metadata' => ['outcome_type' => $outcome_type, 'result_label' => $label],
            ]);
        }
        if ($id > 0 && $run_id > 0 && class_exists('VES_Usage_Service')) {
            VES_Usage_Service::record_zero_cost_event([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'target_type' => 'outcome',
                'target_id' => $id,
                'event_type' => 'outcome_recorded',
                'idempotency_key' => 'fi-outcome:' . $id,
            ]);
        }
        if ($id > 0 && class_exists('VES_Pattern_Observation_Service') && in_array($label, ['won','approved','performed_well'], true)) {
            VES_Pattern_Observation_Service::record_pattern([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'pattern_key' => $target_type . '_' . $outcome_type . '_' . $label,
                'platform' => (string) ($row['channel'] ?? ''),
                'content_type' => $target_type,
                'success_count' => 1,
                'trial_count' => 1,
                'weighted_score' => 1,
                'confidence_score' => 0.2,
                'source_refs' => [['type' => 'outcome', 'id' => $id]],
                'metadata' => ['created_from_outcome' => $id],
            ]);
        }
        return $id;
    }

    public static function list_for_target($workspace_id, $target_type, $target_id): array {
        return self::query(['workspace_id' => (int) $workspace_id, 'target_type' => (string) $target_type, 'target_id' => (int) $target_id]);
    }

    public static function list_for_run($workspace_id, $run_id): array {
        return self::query(['workspace_id' => (int) $workspace_id, 'run_id' => (int) $run_id]);
    }

    private static function query(array $filters): array {
        global $wpdb;
        $workspace_id = max(0, (int) ($filters['workspace_id'] ?? 0));
        if ($workspace_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $where = 'workspace_id = %d';
        $params = [$workspace_id];
        if (!empty($filters['run_id'])) { $where .= ' AND run_id = %d'; $params[] = (int) $filters['run_id']; }
        if (!empty($filters['target_type']) && !empty($filters['target_id'])) {
            $where .= ' AND target_type = %s AND target_id = %d';
            $params[] = self::clean_key((string) $filters['target_type'], 60);
            $params[] = (int) $filters['target_id'];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY id DESC LIMIT 100", $params), ARRAY_A);
        return array_map([__CLASS__, 'decode_row'], is_array($rows) ? $rows : []);
    }

    private static function decode_row(array $row): array {
        foreach (['id','workspace_id','run_id','target_id'] as $key) { if (isset($row[$key])) { $row[$key] = (int) $row[$key]; } }
        foreach (['metrics_json' => 'metrics', 'metadata_json' => 'metadata'] as $col => $alias) {
            $decoded = json_decode((string) ($row[$col] ?? ''), true);
            $row[$alias] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($s))); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function long_text(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function date_or_null($value) { $value = trim((string) $value); return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value) ? (strlen($value) === 10 ? $value . ' 00:00:00' : $value) : null; }
    private static function encode_json(array $value): string { $json = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : json_encode($value); return is_string($json) ? $json : '{}'; }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
