<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Run_Store {
    const TABLE_SLUG = 'ves_trend_runs';
    const DB_VERSION = '1.3';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table() {
        $version_option = 'ves_trend_runs_db_version';
        if (get_option($version_option) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bundle_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NULL,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            session_key varchar(64) NOT NULL,
            request_hash varchar(64) NOT NULL,
            correlation_key varchar(120) NULL,
            orchestrator_ref varchar(190) NULL,
            run_status varchar(24) NOT NULL DEFAULT 'queued',
            confidence_mode varchar(32) NULL,
            requested_topic varchar(190) NULL,
            country_code varchar(16) NULL,
            duration_code varchar(16) NULL,
            request_json longtext NOT NULL,
            query_plan_json longtext NULL,
            sources_json longtext NULL,
            final_payload_json longtext NULL,
            memory_entry_id bigint(20) unsigned NULL,
            backfill_job_id varchar(64) NULL,
            legacy_memory_id bigint(20) unsigned NULL,
            legacy_key varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY bundle_id (bundle_id),
            KEY user_id (user_id),
            KEY workspace_id (workspace_id),
            KEY project_id (project_id),
            KEY request_hash (request_hash),
            KEY correlation_key (correlation_key),
            KEY run_status (run_status),
            KEY created_at (created_at),
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

    private static function now_gmt() {
        return current_time('mysql', true);
    }

    public static function encode($value) {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '{}';
    }

    public static function decode($json) {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function sanitize_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, ['queued', 'running', 'partial', 'completed', 'failed', 'aborted', 'fallback_completed', 'waiting_retry', 'stale_needs_recovery', 'completed_low_confidence', 'needs_validation', 'completed_with_limitations'], true) ? $status : 'queued';
    }

    private static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, ['validated', 'directional', 'exploratory', 'low_confidence', 'needs_validation', 'diagnostic_fallback', 'insufficient_evidence'], true) ? $mode : '';
    }

    public static function create_run($bundle_id, $request, $query_plan, $sources = [], $request_hash = '', $scope_override = []) {
        global $wpdb;
        $scope = self::current_scope();
        if (is_array($scope_override)) {
            if (isset($scope_override['user_id'])) {
                $scope['user_id'] = $scope_override['user_id'] ? (int) $scope_override['user_id'] : null;
            }
            if (isset($scope_override['session_key'])) {
                $scope['session_key'] = sanitize_key((string) $scope_override['session_key']);
            }
        }
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return 0;
        }
        $request_hash = sanitize_text_field((string) $request_hash);
        $user_id = !empty($scope['user_id']) ? (int) $scope['user_id'] : 0;
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id($request, $user_id) : $user_id;
        $project_id = class_exists('VES_Projects') ? VES_Projects::resolve_project_from_request($request, $user_id, $workspace_id) : 0;
        $now = self::now_gmt();
        $wpdb->insert(
            self::table_name(),
            [
                'bundle_id' => $bundle_id,
                'user_id' => $user_id > 0 ? $user_id : null,
                'workspace_id' => max(0, (int) $workspace_id),
                'project_id' => max(0, (int) $project_id),
                'session_key' => (string) $scope['session_key'],
                'request_hash' => $request_hash,
                'correlation_key' => sanitize_text_field((string) ('trend:' . $request_hash)),
                'orchestrator_ref' => null,
                'run_status' => 'queued',
                'confidence_mode' => null,
                'requested_topic' => sanitize_text_field((string) ($request['topic'] ?? '')),
                'country_code' => sanitize_text_field((string) ($request['country'] ?? 'None')),
                'duration_code' => sanitize_key((string) ($request['duration'] ?? '7d')),
                'request_json' => self::encode($request),
                'query_plan_json' => self::encode($query_plan),
                'sources_json' => self::encode($sources),
                'final_payload_json' => null,
                'memory_entry_id' => null,
                'backfill_job_id' => null,
                'legacy_memory_id' => null,
                'legacy_key' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
            ]
        );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    public static function create_legacy_run($bundle_id, $args = []) {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return 0;
        }
        $legacy_key = sanitize_text_field((string) ($args['legacy_key'] ?? ''));
        if ($legacy_key !== '') {
            $existing = self::get_run_by_legacy_key($legacy_key);
            if (!empty($existing['id'])) {
                return (int) $existing['id'];
            }
        }
        $request = is_array($args['request'] ?? null) ? $args['request'] : [];
        $query_plan = is_array($args['query_plan'] ?? null) ? $args['query_plan'] : [];
        $sources = is_array($args['sources'] ?? null) ? $args['sources'] : [];
        $user_id = !empty($args['user_id']) ? (int) $args['user_id'] : 0;
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id($request, $user_id) : $user_id;
        $project_id = class_exists('VES_Projects') ? VES_Projects::resolve_project_from_request($request, $user_id, $workspace_id) : 0;
        $now = self::now_gmt();
        $wpdb->insert(
            self::table_name(),
            [
                'bundle_id' => $bundle_id,
                'user_id' => $user_id > 0 ? $user_id : null,
                'workspace_id' => max(0, (int) $workspace_id),
                'project_id' => max(0, (int) $project_id),
                'session_key' => sanitize_key((string) ($args['session_key'] ?? 'legacy')),
                'request_hash' => sanitize_text_field((string) ($args['request_hash'] ?? '')),
                'run_status' => self::sanitize_status($args['run_status'] ?? 'partial'),
                'confidence_mode' => self::sanitize_mode($args['confidence_mode'] ?? ''),
                'requested_topic' => sanitize_text_field((string) ($args['requested_topic'] ?? ($request['topic'] ?? ''))),
                'country_code' => sanitize_text_field((string) ($args['country_code'] ?? ($request['country'] ?? 'None'))),
                'duration_code' => sanitize_key((string) ($args['duration_code'] ?? ($request['duration'] ?? '7d'))),
                'request_json' => self::encode($request),
                'query_plan_json' => self::encode($query_plan),
                'sources_json' => self::encode($sources),
                'final_payload_json' => self::encode($args['final_payload'] ?? []),
                'memory_entry_id' => !empty($args['memory_entry_id']) ? (int) $args['memory_entry_id'] : null,
                'backfill_job_id' => sanitize_text_field((string) ($args['backfill_job_id'] ?? '')),
                'legacy_memory_id' => !empty($args['legacy_memory_id']) ? (int) $args['legacy_memory_id'] : null,
                'legacy_key' => $legacy_key !== '' ? $legacy_key : null,
                'created_at' => sanitize_text_field((string) ($args['created_at'] ?? $now)),
                'updated_at' => $now,
                'completed_at' => sanitize_text_field((string) ($args['completed_at'] ?? $now)),
            ]
        );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    public static function hydrate_row($row) {
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['user_id'] = !empty($row['user_id']) ? (int) $row['user_id'] : null;
        $row['workspace_id'] = (int) ($row['workspace_id'] ?? 0);
        $row['project_id'] = (int) ($row['project_id'] ?? 0);
        $row['memory_entry_id'] = !empty($row['memory_entry_id']) ? (int) $row['memory_entry_id'] : 0;
        $row['legacy_memory_id'] = !empty($row['legacy_memory_id']) ? (int) $row['legacy_memory_id'] : 0;
        $row['request'] = self::decode($row['request_json'] ?? '') ?: [];
        $row['query_plan'] = self::decode($row['query_plan_json'] ?? '') ?: [];
        $row['sources'] = self::decode($row['sources_json'] ?? '') ?: [];
        $row['final_payload'] = self::decode($row['final_payload_json'] ?? '') ?: null;
        return $row;
    }

    public static function get_run_by_bundle_id($bundle_id) {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE bundle_id = %s LIMIT 1', $bundle_id), ARRAY_A);
        return self::hydrate_row($row);
    }

    public static function get_run_by_legacy_key($legacy_key) {
        global $wpdb;
        $legacy_key = sanitize_text_field((string) $legacy_key);
        if ($legacy_key === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE legacy_key = %s LIMIT 1', $legacy_key), ARRAY_A);
        return self::hydrate_row($row);
    }

    public static function get_job_summary($job_id) {
        global $wpdb;
        $job_id = sanitize_text_field((string) $job_id);
        if ($job_id === '') {
            return [];
        }
        $table = self::table_name();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT run_status, COUNT(*) AS total FROM {$table} WHERE backfill_job_id = %s GROUP BY run_status", $job_id), ARRAY_A);
        $summary = ['total' => 0, 'statuses' => []];
        foreach ((array) $rows as $row) {
            $status = sanitize_key((string) ($row['run_status'] ?? 'unknown'));
            $count = (int) ($row['total'] ?? 0);
            $summary['statuses'][$status] = $count;
            $summary['total'] += $count;
        }
        return $summary;
    }

    public static function delete_by_backfill_job($job_id) {
        global $wpdb;
        $job_id = sanitize_text_field((string) $job_id);
        if ($job_id === '') {
            return 0;
        }
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . ' WHERE backfill_job_id = %s', $job_id));
    }

    public static function update_sources($bundle_id, $sources, $status = '') {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return false;
        }
        $data = [
            'sources_json' => self::encode($sources),
            'updated_at' => self::now_gmt(),
        ];
        $formats = ['%s','%s'];
        if ($status !== '') {
            $data['run_status'] = self::sanitize_status($status);
            $formats[] = '%s';
        }
        return false !== $wpdb->update(self::table_name(), $data, ['bundle_id' => $bundle_id], $formats, ['%s']);
    }

    public static function set_status($bundle_id, $status, $confidence_mode = '') {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return false;
        }
        $data = [
            'run_status' => self::sanitize_status($status),
            'updated_at' => self::now_gmt(),
        ];
        $formats = ['%s','%s'];
        if ($confidence_mode !== '') {
            $data['confidence_mode'] = self::sanitize_mode($confidence_mode);
            $formats[] = '%s';
        }
        return false !== $wpdb->update(self::table_name(), $data, ['bundle_id' => $bundle_id], $formats, ['%s']);
    }

    public static function complete_run($bundle_id, $status, $final_payload, $confidence_mode = '', $memory_entry_id = 0) {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return false;
        }
        $now = self::now_gmt();
        return false !== $wpdb->update(
            self::table_name(),
            [
                'run_status' => self::sanitize_status($status),
                'confidence_mode' => $confidence_mode !== '' ? self::sanitize_mode($confidence_mode) : null,
                'final_payload_json' => self::encode($final_payload),
                'memory_entry_id' => (int) $memory_entry_id,
                'updated_at' => $now,
                'completed_at' => $now,
            ],
            ['bundle_id' => $bundle_id],
            ['%s','%s','%s','%d','%s','%s'],
            ['%s']
        );
    }

    public static function get_run_by_id($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return self::hydrate_row($row);
    }

    public static function set_orchestrator_ref($bundle_id, $ref) {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return false;
        }
        return false !== $wpdb->update(self::table_name(), [
            'orchestrator_ref' => sanitize_text_field((string) $ref),
            'updated_at' => self::now_gmt(),
        ], ['bundle_id' => $bundle_id], ['%s','%s'], ['%s']);
    }

    public static function attach_memory_entry($bundle_id, $memory_entry_id) {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return false;
        }
        return false !== $wpdb->update(self::table_name(), [
            'memory_entry_id' => (int) $memory_entry_id,
            'updated_at' => self::now_gmt(),
        ], ['bundle_id' => $bundle_id], ['%d','%s'], ['%s']);
    }
}
