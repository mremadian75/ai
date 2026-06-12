<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Insight_Store {
    const TABLE_SLUG = 'ves_trend_insights';
    const DB_VERSION = '1.1';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table() {
        $version_option = 'ves_trend_insights_db_version';
        if (get_option($version_option) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NULL,
            session_key varchar(64) NOT NULL,
            memory_entry_id bigint(20) unsigned NULL,
            insight_type varchar(24) NOT NULL DEFAULT 'pattern',
            status varchar(24) NOT NULL DEFAULT 'new',
            trend_name varchar(190) NOT NULL,
            fit_score decimal(4,1) NULL,
            evidence_score decimal(4,1) NULL,
            confidence_score decimal(4,1) NULL,
            actionability_score decimal(4,1) NULL,
            priority_score decimal(4,1) NULL,
            source_count int(10) unsigned NOT NULL DEFAULT 0,
            usable_source_count int(10) unsigned NOT NULL DEFAULT 0,
            evidence_json longtext NULL,
            observed_signal_json longtext NULL,
            interpretation_json longtext NULL,
            risk_json longtext NULL,
            backfill_job_id varchar(64) NULL,
            legacy_memory_id bigint(20) unsigned NULL,
            legacy_key varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY memory_entry_id (memory_entry_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY trend_name (trend_name),
            KEY backfill_job_id (backfill_job_id),
            KEY legacy_memory_id (legacy_memory_id),
            KEY legacy_key (legacy_key)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option($version_option, self::DB_VERSION, false);
    }

    private static function current_scope() {
        $session_key = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::maybe_set_session_cookie() : 'ves_session';
        return [
            'user_id' => is_user_logged_in() ? (int) get_current_user_id() : null,
            'session_key' => sanitize_key((string) $session_key),
        ];
    }

    public static function encode($value) {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '{}';
    }

    private static function decode($json) {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function now_gmt() {
        return current_time('mysql', true);
    }

    private static function normalize_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, ['new', 'under_review', 'approved', 'rejected', 'archived'], true) ? $status : 'new';
    }

    private static function insert_insight($run_id, $scope, $memory_entry_id, $insight, $extra = []) {
        global $wpdb;
        $now = self::now_gmt();
        $wpdb->insert(self::table_name(), [
            'run_id' => (int) $run_id,
            'user_id' => !empty($scope['user_id']) ? (int) $scope['user_id'] : null,
            'session_key' => (string) ($scope['session_key'] ?? ''),
            'memory_entry_id' => (int) $memory_entry_id,
            'insight_type' => sanitize_key((string) ($insight['insight_type'] ?? 'pattern')),
            'status' => self::normalize_status($insight['status'] ?? 'new'),
            'trend_name' => sanitize_text_field((string) ($insight['trend_name'] ?? 'Trend')),
            'fit_score' => isset($insight['fit_score']) ? round((float) $insight['fit_score'], 1) : null,
            'evidence_score' => isset($insight['evidence_score']) ? round((float) $insight['evidence_score'], 1) : null,
            'confidence_score' => isset($insight['confidence_score']) ? round((float) $insight['confidence_score'], 1) : null,
            'actionability_score' => isset($insight['actionability_score']) ? round((float) $insight['actionability_score'], 1) : null,
            'priority_score' => isset($insight['priority_score']) ? round((float) $insight['priority_score'], 1) : null,
            'source_count' => max(0, (int) ($insight['source_count'] ?? 0)),
            'usable_source_count' => max(0, (int) ($insight['usable_source_count'] ?? 0)),
            'evidence_json' => self::encode($insight['evidence_json'] ?? []),
            'observed_signal_json' => self::encode($insight['observed_signal_json'] ?? []),
            'interpretation_json' => self::encode($insight['interpretation_json'] ?? []),
            'risk_json' => self::encode($insight['risk_json'] ?? []),
            'backfill_job_id' => sanitize_text_field((string) ($extra['backfill_job_id'] ?? '')),
            'legacy_memory_id' => !empty($extra['legacy_memory_id']) ? (int) $extra['legacy_memory_id'] : null,
            'legacy_key' => sanitize_text_field((string) ($extra['legacy_key'] ?? '')),
            'created_at' => sanitize_text_field((string) ($extra['created_at'] ?? $now)),
            'updated_at' => $now,
        ], ['%d','%d','%s','%d','%s','%s','%s','%f','%f','%f','%f','%f','%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s']);
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    public static function replace_for_run($run_id, $memory_entry_id, $insights) {
        global $wpdb;
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return [];
        }
        $scope = self::current_scope();
        $wpdb->delete(self::table_name(), ['run_id' => $run_id], ['%d']);
        $ids = [];
        foreach ((array) $insights as $insight) {
            if (!is_array($insight)) {
                continue;
            }
            $id = self::insert_insight($run_id, $scope, $memory_entry_id, $insight);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function insert_legacy_insights($run_id, $memory_entry_id, $insights, $extra = []) {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return [];
        }
        $scope = [
            'user_id' => !empty($extra['user_id']) ? (int) $extra['user_id'] : null,
            'session_key' => sanitize_key((string) ($extra['session_key'] ?? 'legacy')),
        ];
        $ids = [];
        foreach ((array) $insights as $index => $insight) {
            if (!is_array($insight)) {
                continue;
            }
            $copy = $insight;
            $copy['status'] = $extra['default_status'] ?? ($copy['status'] ?? 'archived');
            $id = self::insert_insight($run_id, $scope, $memory_entry_id, $copy, [
                'backfill_job_id' => $extra['backfill_job_id'] ?? '',
                'legacy_memory_id' => $extra['legacy_memory_id'] ?? 0,
                'legacy_key' => sanitize_text_field((string) ($extra['legacy_key_prefix'] ?? 'legacy') . ':' . (int) $index),
                'created_at' => $extra['created_at'] ?? self::now_gmt(),
            ]);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private static function scope_clause(&$params, $user_id = null) {
        $user_id = $user_id !== null ? (int) $user_id : (is_user_logged_in() ? (int) get_current_user_id() : 0);
        if ($user_id > 0) {
            $params[] = $user_id;
            return 'user_id = %d';
        }
        $session_key = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::maybe_set_session_cookie() : '';
        $params[] = sanitize_key((string) $session_key);
        return 'session_key = %s';
    }

    public static function hydrate_row($row) {
        if (!is_array($row)) {
            return null;
        }
        foreach (['fit_score','evidence_score','confidence_score','actionability_score','priority_score'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (float) $row[$key] : null;
        }
        foreach (['source_count','usable_source_count','run_id','memory_entry_id','legacy_memory_id','id'] as $key) {
            $row[$key] = !empty($row[$key]) ? (int) $row[$key] : 0;
        }
        foreach (['evidence_json','observed_signal_json','interpretation_json','risk_json'] as $key) {
            $row[$key] = self::decode($row[$key] ?? '') ?: [];
        }
        return $row;
    }

    public static function get_insight_for_user($insight_id, $user_id = null) {
        global $wpdb;
        $insight_id = (int) $insight_id;
        if ($insight_id <= 0) {
            return null;
        }
        $params = [$insight_id];
        $where = ['id = %d', self::scope_clause($params, $user_id)];
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        return self::hydrate_row($row);
    }

    public static function get_brief_ready_insights_for_memory_entry($memory_entry_id, $user_id = null) {
        global $wpdb;
        $memory_entry_id = (int) $memory_entry_id;
        if ($memory_entry_id <= 0) {
            return [];
        }
        $params = [$memory_entry_id];
        $where = ['memory_entry_id = %d', self::scope_clause($params, $user_id), "status IN ('new','under_review','approved','archived')"];
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY priority_score DESC, id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $row = self::hydrate_row($row);
            $items[] = [
                'insight_id' => (int) $row['id'],
                'trend_name' => sanitize_text_field((string) ($row['trend_name'] ?? 'Trend')),
                'status' => sanitize_key((string) ($row['status'] ?? 'new')),
                'fit_score' => $row['fit_score'],
                'evidence_score' => $row['evidence_score'],
                'confidence_score' => $row['confidence_score'],
                'actionability_score' => $row['actionability_score'],
                'priority_score' => $row['priority_score'],
                'usable_source_count' => (int) ($row['usable_source_count'] ?? 0),
                'source_count' => (int) ($row['source_count'] ?? 0),
            ];
        }
        return $items;
    }

    public static function count_by_backfill_job($job_id) {
        global $wpdb;
        $job_id = sanitize_text_field((string) $job_id);
        if ($job_id === '') {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE backfill_job_id = %s', $job_id));
    }

    public static function delete_by_backfill_job($job_id) {
        global $wpdb;
        $job_id = sanitize_text_field((string) $job_id);
        if ($job_id === '') {
            return 0;
        }
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . ' WHERE backfill_job_id = %s', $job_id));
    }
}
