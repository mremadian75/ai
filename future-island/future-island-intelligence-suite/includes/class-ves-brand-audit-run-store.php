<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Brand_Audit_Run_Store {
    const TABLE_SLUG = 'ves_brand_audit_runs';
    const DB_VERSION = '1.1';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table() {
        $version_option = 'ves_brand_audit_runs_db_version';
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
            run_status varchar(24) NOT NULL DEFAULT 'queued',
            confidence_mode varchar(32) NULL,
            brand_name varchar(190) NOT NULL,
            website_url text NULL,
            country_code varchar(16) NULL,
            language_code varchar(16) NULL,
            depth varchar(24) NULL,
            output_type varchar(32) NULL,
            request_json longtext NOT NULL,
            query_plan_json longtext NULL,
            sources_json longtext NULL,
            evidence_pack_json longtext NULL,
            final_payload_json longtext NULL,
            memory_entry_id bigint(20) unsigned NULL,
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
            KEY brand_name (brand_name),
            KEY created_at (created_at),
            KEY memory_entry_id (memory_entry_id)
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
        return in_array($status, ['queued', 'running', 'partial', 'completed', 'failed', 'aborted'], true) ? $status : 'queued';
    }

    private static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, ['validated', 'directional', 'exploratory', 'insufficient_evidence'], true) ? $mode : '';
    }

    public static function create_run($bundle_id, $request, $query_plan, $sources = [], $request_hash = '', $scope_override = []) {
        global $wpdb;
        self::create_table();
        $scope = self::current_scope();
        if (is_array($scope_override)) {
            if (array_key_exists('user_id', $scope_override)) {
                $scope['user_id'] = !empty($scope_override['user_id']) ? (int) $scope_override['user_id'] : null;
            }
            if (isset($scope_override['session_key'])) {
                $scope['session_key'] = sanitize_key((string) $scope_override['session_key']);
            }
        }
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return 0;
        }
        $request = is_array($request) ? $request : [];
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
                'correlation_key' => sanitize_text_field((string) ('brand_audit:' . $request_hash)),
                'run_status' => 'queued',
                'confidence_mode' => null,
                'brand_name' => sanitize_text_field((string) ($request['brand_name'] ?? '')),
                'website_url' => esc_url_raw((string) ($request['website_url'] ?? '')),
                'country_code' => sanitize_text_field((string) ($request['country'] ?? 'ES')),
                'language_code' => sanitize_key((string) ($request['language'] ?? 'es')),
                'depth' => sanitize_key((string) ($request['depth'] ?? 'standard')),
                'output_type' => sanitize_key((string) ($request['output_type'] ?? 'report_deck')),
                'request_json' => self::encode($request),
                'query_plan_json' => self::encode($query_plan),
                'sources_json' => self::encode($sources),
                'evidence_pack_json' => null,
                'final_payload_json' => null,
                'memory_entry_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
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
        $row['request'] = self::decode($row['request_json'] ?? '') ?: [];
        $row['query_plan'] = self::decode($row['query_plan_json'] ?? '') ?: [];
        $row['sources'] = self::decode($row['sources_json'] ?? '') ?: [];
        $row['evidence_pack'] = self::decode($row['evidence_pack_json'] ?? '') ?: [];
        $row['final_payload'] = self::decode($row['final_payload_json'] ?? '') ?: null;
        return $row;
    }

    public static function get_run_by_bundle_id($bundle_id) {
        global $wpdb;
        self::create_table();
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE bundle_id = %s LIMIT 1', $bundle_id), ARRAY_A);
        return self::hydrate_row($row);
    }

    public static function get_run_by_id($id) {
        global $wpdb;
        self::create_table();
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return self::hydrate_row($row);
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
        $formats = ['%s', '%s'];
        if ($status !== '') {
            $data['run_status'] = self::sanitize_status($status);
            $formats[] = '%s';
        }
        return false !== $wpdb->update(self::table_name(), $data, ['bundle_id' => $bundle_id], $formats, ['%s']);
    }

    public static function update_evidence_pack($bundle_id, $evidence_pack) {
        global $wpdb;
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return false;
        }
        return false !== $wpdb->update(self::table_name(), [
            'evidence_pack_json' => self::encode($evidence_pack),
            'updated_at' => self::now_gmt(),
        ], ['bundle_id' => $bundle_id], ['%s', '%s'], ['%s']);
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
        $formats = ['%s', '%s'];
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
            ['%s', '%s', '%s', '%d', '%s', '%s'],
            ['%s']
        );
    }

    public static function get_recent_runs_for_current_scope($statuses = ['queued', 'running'], $limit = 5, $hours = 24) {
        global $wpdb;
        self::create_table();
        $scope = self::current_scope();
        $limit = max(1, min(20, (int) $limit));
        $hours = max(1, min(168, (int) $hours));
        $allowed = ['queued', 'running', 'partial', 'completed', 'failed', 'aborted'];
        $statuses = array_values(array_intersect($allowed, array_map('sanitize_key', (array) $statuses)));
        if (!$statuses) {
            $statuses = ['queued', 'running'];
        }
        $params = [];
        $where = [];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $where[] = 'run_status IN (' . $placeholders . ')';
        foreach ($statuses as $status) { $params[] = $status; }
        $where[] = 'created_at >= %s';
        $params[] = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
        if (!empty($scope['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $scope['user_id'];
        } else {
            $where[] = 'session_key = %s';
            $params[] = (string) ($scope['session_key'] ?? '');
        }
        $params[] = $limit;
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return array_values(array_filter(array_map([__CLASS__, 'hydrate_row'], (array) $rows)));
    }
    public static function get_history_for_current_scope($limit = 12, $hours = 720, $statuses = ['completed', 'partial', 'failed', 'aborted', 'running', 'queued']) {
        global $wpdb;
        self::create_table();
        $scope = self::current_scope();
        $limit = max(1, min(50, (int) $limit));
        $hours = max(1, min(2160, (int) $hours));
        $allowed = ['queued', 'running', 'partial', 'completed', 'failed', 'aborted'];
        $statuses = array_values(array_intersect($allowed, array_map('sanitize_key', (array) $statuses)));
        if (!$statuses) { $statuses = ['completed', 'partial', 'failed', 'aborted', 'running', 'queued']; }
        $params = [];
        $where = [];
        $where[] = 'run_status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
        foreach ($statuses as $status) { $params[] = $status; }
        $where[] = 'created_at >= %s';
        $params[] = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
        if (!empty($scope['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $scope['user_id'];
        } else {
            $where[] = 'session_key = %s';
            $params[] = (string) ($scope['session_key'] ?? '');
        }
        $params[] = $limit;
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return array_values(array_filter(array_map([__CLASS__, 'hydrate_row'], (array) $rows)));
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
        ], ['bundle_id' => $bundle_id], ['%d', '%s'], ['%s']);
    }
}
