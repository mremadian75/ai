<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Persistent workspace/company memory records.
 *
 * WordPress-first RAG-like fallback: compact structured records, tags,
 * importance, expiry and recency scoring. No vector DB required for v1.
 */
final class VES_Memory_Records {
    const DB_VERSION = '1.4.0';
    const OPTION_KEY = 'ves_memory_records_db_version';
    const MIN_PARTIAL_EVIDENCE_FOR_MEMORY = 5;
    const MIN_RESEARCH_EVIDENCE_FOR_MEMORY = 10;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_memory_records';
    }



    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id', 'workspace_id', 'user_id', 'project_id', 'brand_name', 'memory_type', 'title', 'summary', 'content_json', 'source_type', 'source_id', 'tags_json', 'importance_score', 'expires_at', 'is_pinned', 'created_at', 'updated_at'];
    }



    public static function required_indexes() {
        return ['workspace_id','user_id','project_id','brand_name','memory_type','source_lookup','created_at'];
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
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            brand_name varchar(190) NOT NULL DEFAULT '',
            memory_type varchar(64) NOT NULL DEFAULT 'research_summary',
            title varchar(255) NOT NULL DEFAULT '',
            summary longtext NULL,
            content_json longtext NULL,
            source_type varchar(64) NOT NULL DEFAULT '',
            source_id varchar(120) NOT NULL DEFAULT '',
            tags_json longtext NULL,
            importance_score decimal(6,2) NOT NULL DEFAULT 0.00,
            expires_at datetime NULL,
            is_pinned tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY project_id (project_id),
            KEY brand_name (brand_name),
            KEY memory_type (memory_type),
            KEY source_lookup (source_type, source_id),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function workspace_id_for_user($user_id = 0) {
        $user_id = max(0, (int) $user_id);
        if ($user_id <= 0 && function_exists('get_current_user_id')) {
            $user_id = max(0, (int) get_current_user_id());
        }
        return self::infer_workspace_id(['user_id' => $user_id], $user_id);
    }

    public static function infer_workspace_id($request = [], $user_id = 0) {
        // MVP fallback workspace rule: until a formal workspace-membership table exists,
        // a logged-in user owns a single workspace keyed by user_id. Admins may
        // override with an explicit workspace_id for migrations/support.
        $request = is_array($request) ? $request : [];
        $user_id = max(0, (int) ($user_id ?: ($request['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))));
        foreach (['workspace_id', 'workspaceId'] as $key) {
            if (!isset($request[$key]) || !is_numeric($request[$key])) { continue; }
            $candidate = max(0, (int) $request[$key]);
            if ($candidate <= 0) { continue; }
            if ((function_exists('current_user_can') && current_user_can('manage_options')) || $candidate === $user_id) { return $candidate; }
        }
        return $user_id > 0 ? $user_id : 0;
    }

    public static function infer_project_id($request = [], $workspace_id = 0, $user_id = 0) {
        $request = is_array($request) ? $request : [];
        foreach (['project_id', 'projectId'] as $key) {
            if (!isset($request[$key]) || !is_numeric($request[$key])) { continue; }
            $candidate = max(0, (int) $request[$key]);
            if ($candidate <= 0) { continue; }
            if (!class_exists('VES_Projects') || VES_Projects::user_can_access_project($candidate, $user_id, $workspace_id)) { return $candidate; }
            return 0;
        }
        if (class_exists('VES_Projects')) {
            return (int) VES_Projects::resolve_project_from_request($request, $user_id, $workspace_id);
        }
        return 0;
    }

    public static function save_record($args) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $now = current_time('mysql');
        $id = isset($args['id']) ? max(0, (int) $args['id']) : 0;
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = self::infer_workspace_id($args, $user_id);
        $project_id = self::infer_project_id($args, $workspace_id, $user_id);
        $tags = self::normalize_tags($args['tags'] ?? $args['tags_json'] ?? []);
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'project_id' => $project_id,
            'brand_name' => sanitize_text_field((string) ($args['brand_name'] ?? '')),
            'memory_type' => self::safe_memory_type((string) ($args['memory_type'] ?? 'research_summary')),
            'title' => self::truncate(sanitize_text_field((string) ($args['title'] ?? 'Memory record')), 240),
            'summary' => self::truncate(wp_strip_all_tags((string) ($args['summary'] ?? '')), 5000),
            'content_json' => wp_json_encode(self::compact_content($args['content'] ?? $args['content_json'] ?? []), JSON_UNESCAPED_UNICODE),
            'source_type' => sanitize_key((string) ($args['source_type'] ?? 'manual')),
            'source_id' => self::truncate(sanitize_text_field((string) ($args['source_id'] ?? '')), 120),
            'tags_json' => wp_json_encode($tags, JSON_UNESCAPED_UNICODE),
            'importance_score' => self::normalize_score($args['importance_score'] ?? 50),
            'expires_at' => self::normalize_expires_at($args['expires_at'] ?? null, !empty($args['is_pinned'])),
            'is_pinned' => !empty($args['is_pinned']) ? 1 : 0,
            'updated_at' => $now,
        ];

        if ($id <= 0 && !empty($args['dedupe'])) {
            $id = self::find_existing_record($row['workspace_id'], $row['user_id'], $row['project_id'], $row['memory_type'], $row['source_type'], $row['source_id'], $row['title']);
        }
        if ($id > 0) {
            $updated = $wpdb->update(self::table_name(), $row, ['id' => $id], [
                '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s',
            ], ['%d']);
            return $updated === false ? 0 : $id;
        }

        $row['created_at'] = $now;
        $inserted = $wpdb->insert(self::table_name(), $row, [
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s',
        ]);
        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    private static function find_existing_record($workspace_id, $user_id, $project_id, $memory_type, $source_type, $source_id, $title) {
        global $wpdb;
        if ($source_type === '' || $source_id === '') { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE workspace_id = %d AND user_id = %d AND project_id = %d AND memory_type = %s AND source_type = %s AND source_id = %s AND title = %s ORDER BY id DESC LIMIT 1',
            (int) $workspace_id,
            (int) $user_id,
            (int) $project_id,
            (string) $memory_type,
            (string) $source_type,
            (string) $source_id,
            (string) $title
        ));
    }

    public static function create_from_brand_audit($workspace_id, $user_id, $request, $final) {
        $ids = self::create_records_from_brand_audit($workspace_id, $user_id, $request, $final);
        return (int) ($ids['research_summary'] ?? 0);
    }

    public static function create_records_from_brand_audit($workspace_id, $user_id, $request, $final) {
        $request = is_array($request) ? $request : [];
        $final = is_array($final) ? $final : [];
        $user_id = max(0, (int) ($user_id ?: ($request['user_id'] ?? ($final['user_id'] ?? 0))));
        $context_args = array_merge($request, ['workspace_id' => $workspace_id, 'user_id' => $user_id]);
        $workspace_id = self::infer_workspace_id($context_args, $user_id);
        $project_id = self::infer_project_id($context_args, $workspace_id, $user_id);
        if ($workspace_id <= 0 || $user_id <= 0) { return []; }
        $run_status = sanitize_key((string) ($final['run_status'] ?? $final['status'] ?? 'completed'));
        if (in_array($run_status, ['failed', 'aborted', 'cancelled'], true)) { return []; }
        $evidence_summary = is_array($final['evidence_summary'] ?? null) ? $final['evidence_summary'] : [];
        $evidence_count = (int) ($evidence_summary['stored'] ?? $evidence_summary['total'] ?? 0);
        $ai_used = !empty($final['ai_used']) && empty($final['ai_error']);
        if ($run_status === 'partial' && $evidence_count < (int) apply_filters('ves_memory_min_partial_evidence', self::MIN_PARTIAL_EVIDENCE_FOR_MEMORY)) { return []; }

        $brand = sanitize_text_field((string) ($request['brand_name'] ?? $final['requested']['brand_name'] ?? ''));
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : [];
        $bundle_id = sanitize_text_field((string) ($final['bundle_id'] ?? ''));
        $competitors = self::competitors_from_request($request);
        $platforms = array_keys((array) ($evidence_summary['by_platform'] ?? []));
        $base_tags = array_filter(array_merge(
            [$brand, $request['country'] ?? '', $request['industry'] ?? '', 'brand_audit'],
            $platforms,
            $competitors
        ));
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
        $ids = [];

        $summary = (string) ($structured['executive_summary'] ?? $final['analysis'] ?? 'Brand Audit completed.');
        if ($run_status === 'partial') { $summary = 'Partial / low-confidence run: ' . $summary; }
        if (!$ai_used) { $summary = 'Local fallback / no confirmed AI strategy: ' . $summary; }
        $research_content = [
            'requested' => self::compact_request($final['requested'] ?? $request),
            'run_status' => $final['run_status'] ?? '',
            'confidence_mode' => $final['confidence_mode'] ?? '',
            'evidence_summary' => $evidence_summary,
            'strategic_diagnosis' => $structured['strategic_diagnosis'] ?? [],
            'key_findings' => array_slice((array) ($structured['key_findings'] ?? []), 0, 8),
            'insights' => array_slice((array) ($structured['insights'] ?? []), 0, 8),
            'limitations' => array_slice((array) ($structured['limitations'] ?? []), 0, 8),
            'recommended_next_actions' => array_slice((array) ($structured['recommended_next_actions'] ?? []), 0, 8),
        ];
        $importance = $ai_used ? 84 : 54;
        if ($run_status === 'partial') { $importance -= 12; }
        if (!empty($evidence_summary['stored']) && (int) $evidence_summary['stored'] >= 20) { $importance += 8; }
        if ($ai_used && $evidence_count >= (int) apply_filters('ves_memory_min_research_evidence', self::MIN_RESEARCH_EVIDENCE_FOR_MEMORY)) {
            $ids['research_summary'] = self::save_record([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'project_id' => $project_id,
                'brand_name' => $brand,
                'memory_type' => 'research_summary',
                'title' => trim(($run_status === 'partial' ? 'Partial Brand Audit: ' : 'Brand Audit: ') . ($brand !== '' ? $brand : $bundle_id)),
                'summary' => $summary,
                'content' => $research_content,
                'source_type' => 'brand_audit',
                'source_id' => $bundle_id,
                'tags' => array_merge($base_tags, ['research_summary', $run_status === 'partial' ? 'partial' : 'complete']),
                'importance_score' => min(100, $importance),
                'expires_at' => $expires,
                'dedupe' => true,
            ]);
        }

        $evidence_line = self::evidence_summary_sentence($evidence_summary);
        if ($run_status === 'partial') { $evidence_line = 'Partial / low-confidence evidence summary: ' . $evidence_line; }
        $ids['evidence_summary'] = self::save_record([
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'project_id' => $project_id,
            'brand_name' => $brand,
            'memory_type' => 'evidence_summary',
            'title' => trim('Evidence Summary: ' . ($brand !== '' ? $brand : $bundle_id)),
            'summary' => $evidence_line,
            'content' => [
                'evidence_summary' => $evidence_summary,
                'coverage_warnings' => $evidence_summary['coverage_warnings'] ?? [],
                'top_platforms' => $evidence_summary['top_platforms'] ?? [],
            ],
            'source_type' => 'brand_audit',
            'source_id' => $bundle_id,
            'tags' => array_merge($base_tags, ['evidence_summary']),
            'importance_score' => max(25, min(100, $importance - ($run_status === 'partial' ? 18 : 4))),
            'expires_at' => $expires,
            'dedupe' => true,
        ]);

        $competitor_payload = $structured['competitor_gap_matrix'] ?? $structured['competitor_benchmark'] ?? [];
        if (!empty($competitors) || !empty($competitor_payload) || !empty($evidence_summary['by_entity_type']['competitor'])) {
            $ids['competitor_context'] = self::save_record([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'project_id' => $project_id,
                'brand_name' => $brand,
                'memory_type' => 'competitor_context',
                'title' => trim('Competitor Context: ' . ($brand !== '' ? $brand : $bundle_id)),
                'summary' => self::competitor_summary_sentence($competitors, $competitor_payload, $evidence_summary),
                'content' => [
                    'competitors' => $competitors,
                    'competitor_gap_matrix' => array_slice((array) $competitor_payload, 0, 8),
                    'competitor_evidence_count' => (int) ($evidence_summary['by_entity_type']['competitor'] ?? 0),
                ],
                'source_type' => 'brand_audit',
                'source_id' => $bundle_id,
                'tags' => array_merge($base_tags, ['competitor_context']),
                'importance_score' => min(100, $importance),
                'expires_at' => $expires,
                'dedupe' => true,
            ]);
        }

        $opportunities = $ai_used ? self::opportunities_from_structured($structured) : [];
        $i = 0;
        foreach (array_slice($opportunities, 0, 5) as $opportunity) {
            if (!is_array($opportunity)) { continue; }
            $i++;
            $evidence_refs = [];
            if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'normalize_evidence_refs')) {
                $evidence_refs = VES_Evidence_Store::normalize_evidence_refs($opportunity['evidence_refs'] ?? $opportunity['evidence_ids'] ?? [], 20);
                if ($bundle_id !== '' && method_exists('VES_Evidence_Store', 'filter_valid_evidence_refs')) {
                    $evidence_refs = VES_Evidence_Store::filter_valid_evidence_refs($bundle_id, $evidence_refs);
                }
            } else {
                $evidence_refs = self::normalize_tags($opportunity['evidence_refs'] ?? $opportunity['evidence_ids'] ?? []);
            }
            $validation_status = sanitize_key((string) ($opportunity['evidence_validation_status'] ?? (empty($evidence_refs) ? 'unsupported' : 'weak')));
            if ($validation_status === 'unsupported' || empty($evidence_refs)) { continue; }
            $title = sanitize_text_field((string) ($opportunity['title'] ?? $opportunity['name'] ?? ('Opportunity ' . $i)));
            $description = wp_strip_all_tags((string) ($opportunity['description'] ?? $opportunity['business_implication'] ?? $opportunity['recommended_action'] ?? ''));
            $ids['saved_opportunity_' . $i] = self::save_record([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'project_id' => $project_id,
                'brand_name' => $brand,
                'memory_type' => 'saved_opportunity',
                'title' => self::truncate('Opportunity: ' . $title, 240),
                'summary' => $description !== '' ? $description : $title,
                'content' => [
                    'opportunity' => self::compact_content($opportunity),
                    'bundle_id' => $bundle_id,
                ],
                'source_type' => 'brand_audit',
                'source_id' => self::truncate($bundle_id . ':opportunity:' . $i, 120),
                'tags' => array_merge($base_tags, ['saved_opportunity']),
                'importance_score' => min(100, $importance + 4),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS),
                'dedupe' => true,
            ]);
        }

        return array_filter($ids);
    }



    public static function list_for_admin($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $where = ['1=1'];
        $params = [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id > 0) { $where[] = 'workspace_id = %d'; $params[] = $workspace_id; }
        $memory_type = sanitize_key((string) ($args['memory_type'] ?? ''));
        if ($memory_type !== '') { $where[] = 'memory_type = %s'; $params[] = $memory_type; }
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY is_pinned DESC, updated_at DESC, id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$limit])), ARRAY_A);
        return array_map([__CLASS__, 'prepare_admin_row'], (array) $rows);
    }

    public static function set_pinned($id, $pinned = true) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        if ($id <= 0) { return false; }
        return false !== $wpdb->update(self::table_name(), [
            'is_pinned' => $pinned ? 1 : 0,
            'expires_at' => $pinned ? null : self::normalize_expires_at(null, false),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id], ['%d','%s','%s'], ['%d']);
    }

    public static function delete_record($id) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        return $id > 0 && false !== $wpdb->delete(self::table_name(), ['id' => $id], ['%d']);
    }

    public static function clear_workspace($workspace_id) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        if ($workspace_id <= 0) { return false; }
        return false !== $wpdb->delete(self::table_name(), ['workspace_id' => $workspace_id], ['%d']);
    }

    private static function prepare_admin_row($row) {
        $tags = json_decode((string) ($row['tags_json'] ?? '[]'), true);
        $tags = is_array($tags) ? array_values($tags) : [];
        $source_type = sanitize_key((string) ($row['source_type'] ?? ''));
        if (in_array($source_type, ['trend_finder', 'ves_trend'], true) && !in_array('Legacy Trend Finder report', $tags, true)) {
            $tags[] = 'Legacy Trend Finder report';
        }
        if ($source_type === 'deep_trend_finder' && !in_array('Deep Trend Finder', $tags, true)) {
            $tags[] = 'Deep Trend Finder';
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'memory_type' => (string) ($row['memory_type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'summary' => self::truncate((string) ($row['summary'] ?? ''), 600),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_id' => (string) ($row['source_id'] ?? ''),
            'importance_score' => (float) ($row['importance_score'] ?? 0),
            'is_pinned' => !empty($row['is_pinned']),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'tags' => $tags,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public static function get_source_record_ids($source_type, $source_id, $memory_type = '') {
        global $wpdb;
        self::create_table();
        $source_type = sanitize_key((string) $source_type);
        $source_id = sanitize_text_field((string) $source_id);
        if ($source_type === '' || $source_id === '') { return []; }
        $where = ['source_type = %s', 'source_id = %s'];
        $params = [$source_type, $source_id];
        $memory_type = sanitize_key((string) $memory_type);
        if ($memory_type !== '') { $where[] = 'memory_type = %s'; $params[] = $memory_type; }
        $rows = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC', $params));
        return array_map('intval', (array) $rows);
    }

    public static function retrieve_context_pack($args = [], $limit = 5) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(1, min(12, (int) $limit));
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = self::infer_workspace_id($args, $user_id);
        $project_id = self::infer_project_id($args, $workspace_id, $user_id);
        $brand = strtolower(trim((string) ($args['brand_name'] ?? $args['brandName'] ?? '')));
        $context_terms = self::context_terms($args);
        $current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : $user_id;
        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        $now = current_time('mysql');

        $where = ['(expires_at IS NULL OR expires_at = %s OR expires_at > %s OR is_pinned = 1)'];
        $params = ['', $now];
        if ($workspace_id > 0) { $where[] = 'workspace_id = %d'; $params[] = $workspace_id; }
        if (!$is_admin && $current_user_id > 0) { $where[] = '(user_id = %d OR user_id = 0)'; $params[] = $current_user_id; }
        $strict_project_scope = (bool) apply_filters('ves_memory_strict_project_scope', true, $args);
        if ($strict_project_scope && $project_id > 0) { $where[] = 'project_id = %d'; $params[] = $project_id; }

        // v0.9.24.75 — hard brand scoping to prevent cross-project memory leakage.
        // When a brand_name is in the current request, only records for that brand
        // (case-insensitive), pinned records, or workspace-level records with an
        // empty brand_name (treated as global knowledge) are eligible. This addresses
        // the reported leak of e.g. one project context into another project's runs and
        // vice versa. Allow override with the `ves_memory_strict_brand_scope` filter
        // for debugging.
        $strict_brand_scope = (bool) apply_filters('ves_memory_strict_brand_scope', true, $args);
        if ($strict_brand_scope && $brand !== '') {
            $where[] = '(LOWER(brand_name) = %s OR brand_name = %s OR is_pinned = 1)';
            $params[] = $brand;
            $params[] = '';
        }

        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY is_pinned DESC, created_at DESC LIMIT 120';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows) || empty($rows)) { return []; }

        $scored = [];
        foreach ($rows as $row) {
            $score = self::score_row($row, $brand, $workspace_id, $context_terms);
            if ($score <= 0) { continue; }
            // v0.9.24.75 — second guard: even if SQL filter is loosened by filter,
            // apply a strong penalty for cross-brand records so they never outrank
            // the active brand's memories.
            if ($project_id > 0 && (int) ($row['project_id'] ?? 0) !== $project_id && empty($row['is_pinned'])) {
                continue; // hard drop unrelated-project memory records
            }
            $row_brand = strtolower(trim((string) ($row['brand_name'] ?? '')));
            if ($brand !== '' && $row_brand !== '' && $row_brand !== $brand
                && strpos($row_brand, $brand) === false && strpos($brand, $row_brand) === false
                && empty($row['is_pinned'])) {
                continue; // hard drop unrelated-brand records from a brand-scoped lookup
            }
            $row['_score'] = $score;
            $scored[] = $row;
        }
        usort($scored, static function($a, $b) {
            if ((float) $a['_score'] === (float) $b['_score']) { return strcmp((string) $b['created_at'], (string) $a['created_at']); }
            return (float) $a['_score'] < (float) $b['_score'] ? 1 : -1;
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $row) {
            $tags = json_decode((string) ($row['tags_json'] ?? '[]'), true);
            $content = json_decode((string) ($row['content_json'] ?? '{}'), true);
            $content = is_array($content) ? $content : [];
            $out[] = [
                'id' => (int) $row['id'],
                'workspace_id' => (int) $row['workspace_id'],
                'project_id' => (int) ($row['project_id'] ?? 0),
                'brand_name' => (string) $row['brand_name'],
                'memory_type' => (string) $row['memory_type'],
                'confidence_mode' => sanitize_key((string) (($content['confidence_mode'] ?? $content['report_status'] ?? ''))),
                'report_status' => sanitize_key((string) (($content['report_status'] ?? ''))),
                'fallback_used' => !empty($content['fallback_used']),
                'title' => (string) $row['title'],
                'summary' => self::truncate((string) $row['summary'], 900),
                'source_type' => (string) $row['source_type'],
                'source_id' => (string) $row['source_id'],
                'tags' => $tags,
                'importance_score' => (float) $row['importance_score'],
                'score' => round((float) $row['_score'], 2),
                'is_pinned' => !empty($row['is_pinned']),
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    public static function build_prompt_pack($args = [], $limit = 5) {
        $records = self::retrieve_context_pack($args, $limit);
        if (empty($records)) { return ['records' => [], 'summary' => 'No persistent workspace memory matched this context.']; }
        $lines = [];
        foreach ($records as $record) {
            $lines[] = sprintf('- [%s] %s: %s', $record['memory_type'], $record['title'], $record['summary']);
        }
        return ['records' => $records, 'summary' => implode("\n", $lines)];
    }

    private static function score_row($row, $brand, $workspace_id, $context_terms) {
        $score = (float) ($row['importance_score'] ?? 0);
        if (!empty($row['is_pinned'])) { $score += 35; }
        if ($workspace_id > 0 && (int) $row['workspace_id'] === $workspace_id) { $score += 20; }
        $row_brand = strtolower(trim((string) ($row['brand_name'] ?? '')));
        if ($brand !== '' && $row_brand !== '') {
            if ($brand === $row_brand) { $score += 35; }
            elseif (strpos($row_brand, $brand) !== false || strpos($brand, $row_brand) !== false) { $score += 12; }
        }
        $haystack = strtolower(implode(' ', [
            $row['brand_name'] ?? '', $row['memory_type'] ?? '', $row['title'] ?? '', $row['summary'] ?? '', $row['tags_json'] ?? '',
        ]));
        foreach ($context_terms as $term) {
            if ($term !== '' && strpos($haystack, $term) !== false) { $score += 8; }
        }
        $created = strtotime((string) ($row['created_at'] ?? ''));
        if ($created) {
            $age_days = max(0, (time() - $created) / DAY_IN_SECONDS);
            $score += max(0, 20 - min(20, $age_days * 1.8));
        }
        return $score;
    }

    private static function context_terms($args) {
        $terms = [];
        foreach (['brand_name', 'brandName', 'platform', 'source_type', 'context_type', 'research_goal', 'audit_goal', 'country', 'industry', 'run_type'] as $key) {
            if (!empty($args[$key]) && is_scalar($args[$key])) { $terms[] = (string) $args[$key]; }
        }
        foreach (['competitors', 'tags', 'platforms'] as $key) {
            if (!empty($args[$key]) && is_array($args[$key])) { $terms = array_merge($terms, array_map('strval', $args[$key])); }
        }
        $out = [];
        foreach ($terms as $term) {
            $term = strtolower(trim(sanitize_text_field($term)));
            if ($term !== '' && strlen($term) >= 3) { $out[] = $term; }
        }
        return array_values(array_unique(array_slice($out, 0, 30)));
    }

    private static function compact_request($request) {
        $request = is_array($request) ? $request : [];
        $allowed = ['brand_name', 'website_url', 'country', 'language', 'industry', 'depth', 'output_type', 'audit_goal'];
        $out = [];
        foreach ($allowed as $key) {
            if (isset($request[$key]) && is_scalar($request[$key])) { $out[$key] = sanitize_text_field((string) $request[$key]); }
        }
        return $out;
    }

    private static function compact_content($content, $depth = 0) {
        if ($depth > 4) { return '[truncated_depth]'; }
        if (is_scalar($content) || $content === null) {
            return is_string($content) ? self::truncate($content, 4000) : $content;
        }
        if (!is_array($content)) { return ''; }
        $out = [];
        $i = 0;
        foreach ($content as $key => $value) {
            if ($i >= 80) { $out['_truncated'] = true; break; }
            $out[is_int($key) ? $key : sanitize_key((string) $key)] = self::compact_content($value, $depth + 1);
            $i++;
        }
        return $out;
    }

    private static function competitors_from_request($request) {
        $items = $request['competitors'] ?? [];
        if (is_string($items)) { $items = preg_split('/[\n,]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['name'] ?? $item['brand_name'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $name = trim(sanitize_text_field((string) $item));
            if ($name !== '') { $out[] = $name; }
        }
        return array_values(array_unique($out));
    }

    private static function opportunities_from_structured($structured) {
        if (is_array($structured['opportunities'] ?? null) && !empty($structured['opportunities'])) { return $structured['opportunities']; }
        if (is_array($structured['opportunity_territories'] ?? null) && !empty($structured['opportunity_territories'])) { return $structured['opportunity_territories']; }
        return [];
    }

    private static function evidence_summary_sentence($evidence_summary) {
        $total = (int) ($evidence_summary['stored'] ?? $evidence_summary['total'] ?? 0);
        $platforms = array_keys((array) ($evidence_summary['by_platform'] ?? []));
        $entities = array_keys((array) ($evidence_summary['by_entity_type'] ?? []));
        $warnings = (array) ($evidence_summary['coverage_warnings'] ?? []);
        $line = sprintf('Stored %d normalized evidence items', $total);
        if ($platforms) { $line .= ' across ' . implode(', ', array_slice($platforms, 0, 6)); }
        if ($entities) { $line .= '. Entity types: ' . implode(', ', array_slice($entities, 0, 6)); }
        if ($warnings) { $line .= '. Coverage warnings: ' . implode(' | ', array_slice(array_map('strval', $warnings), 0, 3)); }
        return $line;
    }

    private static function competitor_summary_sentence($competitors, $payload, $evidence_summary) {
        $names = array_slice((array) $competitors, 0, 6);
        $count = (int) ($evidence_summary['by_entity_type']['competitor'] ?? 0);
        $line = $names ? ('Competitors requested: ' . implode(', ', $names) . '.') : 'Competitor context was generated from collected evidence.';
        $line .= ' Competitor-attributed evidence items: ' . $count . '.';
        if (!empty($payload)) { $line .= ' Structured competitor benchmark/gap notes are available in content_json.'; }
        return $line;
    }

    private static function normalize_tags($tags) {
        if (is_string($tags)) { $tags = preg_split('/[,\n]+/', $tags); }
        if (!is_array($tags)) { return []; }
        $out = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) { continue; }
            $tag = strtolower(trim(sanitize_text_field((string) $tag)));
            if ($tag !== '') { $out[] = $tag; }
        }
        return array_values(array_unique(array_slice($out, 0, 50)));
    }

    private static function safe_memory_type($type) {
        $type = sanitize_key($type);
        $allowed = [
            'brand_profile', 'research_summary', 'competitor_context', 'evidence_summary', 'saved_insight',
            'saved_opportunity', 'assistant_decision', 'generated_brief', 'campaign_strategy', 'user_preference',
            'market_learning', 'workspace_note', 'creative_learning', 'search_learning',
            'validated_ai_report', 'partial_report', 'fallback_diagnostic', 'raw_scrape_result', 'failed_run', 'brief', 'analysis_output',
            // Phase 6A — Brand Context (Memory V2) record types (additive; no schema change).
            'brand_fact', 'voice_rule', 'forbidden_claim', 'preferred_term', 'avoided_term',
            'audience_fact', 'competitor_fact', 'offer_fact', 'positioning_note',
            'review_learning', 'reusable_context', 'failed_assumption', 'campaign_learning',
        ];
        return in_array($type, $allowed, true) ? $type : 'research_summary';
    }

    private static function normalize_score($value) {
        $value = is_numeric($value) ? (float) $value : 50.0;
        return min(100, max(0, $value));
    }

    private static function normalize_expires_at($value, $is_pinned) {
        if ($is_pinned) { return null; }
        if ($value === null || $value === '') { return null; }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$ts) { return null; }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function truncate($text, $max) {
        $text = (string) $text;
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) { return mb_substr($text, 0, $max); }
        if (strlen($text) > $max) { return substr($text, 0, $max); }
        return $text;
    }
}
