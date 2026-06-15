<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Rule-based pattern candidates for Phase 2. These are candidates, not final insights.
 */
final class VES_Pattern_Candidates {
    const DB_VERSION = '1.2.0';
    const OPTION_KEY = 'ves_pattern_candidates_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_pattern_candidates';
    }



    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id', 'workspace_id', 'user_id', 'run_id', 'pattern_key', 'pattern_type', 'title', 'description', 'platform', 'entity_role', 'entity_key', 'evidence_refs_json', 'support_count', 'confidence', 'strength_score', 'status', 'created_at', 'updated_at', 'meta_json'];
    }



    public static function required_indexes() {
        return ['workspace_id','run_id','pattern_type','platform','status','created_at'];
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
            pattern_key varchar(64) NOT NULL DEFAULT '',
            pattern_type varchar(64) NOT NULL DEFAULT 'content_format',
            title varchar(255) NOT NULL DEFAULT '',
            description longtext NULL,
            platform varchar(40) NOT NULL DEFAULT '',
            entity_role varchar(32) NOT NULL DEFAULT '',
            entity_key varchar(120) NOT NULL DEFAULT '',
            evidence_refs_json longtext NULL,
            support_count int(11) NOT NULL DEFAULT 0,
            confidence decimal(5,2) NOT NULL DEFAULT 0.00,
            strength_score decimal(8,2) NOT NULL DEFAULT 0.00,
            status varchar(32) NOT NULL DEFAULT 'candidate',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            meta_json longtext NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY run_id (run_id),
            KEY pattern_key (pattern_key),
            KEY pattern_type (pattern_type),
            KEY platform (platform),
            KEY entity_role (entity_role),
            KEY entity_key (entity_key),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function delete_for_run($run_id) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id !== '') { $wpdb->delete(self::table_name(), ['run_id' => $run_id], ['%s']); }
    }

    public static function extract_from_evidence($run_id, $workspace_id, $user_id, $request = [], $evidence_summary = []) {
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['stored' => 0, 'patterns' => []]; }
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $request = is_array($request) ? $request : [];
        $summary = is_array($evidence_summary) && !empty($evidence_summary)
            ? $evidence_summary
            : (class_exists('VES_Evidence_Store') ? VES_Evidence_Store::summary_by_run($run_id) : []);

        self::delete_for_run($run_id);
        $patterns = [];
        foreach ((array) ($summary['by_platform'] ?? []) as $platform => $count) {
            $platform = sanitize_key((string) $platform);
            $count = (int) $count;
            if ($count >= 3) {
                $patterns[] = [
                    'pattern_type' => self::platform_to_pattern_type($platform),
                    'title' => ucfirst(str_replace('_', ' ', $platform)) . ' has enough evidence for directional pattern reading',
                    'description' => 'The run collected ' . $count . ' normalized evidence items on ' . $platform . '. Treat this as directional unless sample quality is high.',
                    'platform' => $platform,
                    'entity_role' => 'all',
                    'entity_key' => 'all',
                    'support_count' => $count,
                    'confidence' => self::confidence_from_count($count),
                    'strength_score' => min(100, $count * 3),
                    'evidence_refs' => self::evidence_refs_for($run_id, ['platform' => $platform], 8),
                    'meta' => ['source' => 'rule_based_platform_count'],
                ];
            }
        }
        $main = (int) ($summary['by_entity_type']['main_brand'] ?? 0);
        $competitor = (int) ($summary['by_entity_type']['competitor'] ?? 0);
        if ($competitor > 0) {
            $patterns[] = [
                'pattern_type' => 'competitor_behavior',
                'title' => 'Competitor evidence is present and should be compared separately',
                'description' => 'The evidence layer contains ' . $competitor . ' competitor-attributed item(s) versus ' . $main . ' main-brand item(s). Do not merge competitor posts into brand-owned evidence.',
                'platform' => 'all',
                'entity_role' => 'competitor',
                'entity_key' => 'competitor',
                'support_count' => $competitor,
                'confidence' => self::confidence_from_count($competitor),
                'strength_score' => min(100, $competitor * 4),
                'evidence_refs' => self::evidence_refs_for($run_id, ['entity_type' => 'competitor'], 10),
                'meta' => ['main_brand_count' => $main, 'competitor_count' => $competitor],
            ];
        }
        foreach ((array) ($summary['by_source_type'] ?? []) as $source_type => $count) {
            $source_type = sanitize_key((string) $source_type);
            $count = (int) $count;
            if (in_array($source_type, ['search_result','competitor_signal','news_result','trend'], true) && $count >= 3) {
                $patterns[] = [
                    'pattern_type' => $source_type === 'search_result' ? 'search_intent' : ($source_type === 'trend' ? 'platform_trend' : 'market_gap'),
                    'title' => ucfirst(str_replace('_', ' ', $source_type)) . ' signal cluster detected',
                    'description' => 'There are ' . $count . ' normalized ' . $source_type . ' evidence items. This can support a later AI pattern extraction step.',
                    'platform' => 'all',
                    'entity_role' => 'market',
                    'entity_key' => $source_type,
                    'support_count' => $count,
                    'confidence' => self::confidence_from_count($count),
                    'strength_score' => min(100, $count * 3.5),
                    'evidence_refs' => self::evidence_refs_for($run_id, ['source_type' => $source_type], 8),
                    'meta' => ['source_type' => $source_type],
                ];
            }
        }
        $patterns = array_merge($patterns, self::patterns_from_evidence_rows($run_id));

        $patterns = array_merge($patterns, self::detailed_evidence_patterns($run_id));

        foreach ((array) ($summary['coverage_warnings'] ?? []) as $warning) {
            $warning = sanitize_text_field((string) $warning);
            if ($warning === '') { continue; }
            $patterns[] = [
                'pattern_type' => 'risk_signal',
                'title' => 'Coverage limitation: ' . substr($warning, 0, 90),
                'description' => $warning,
                'platform' => 'all',
                'entity_role' => 'unknown',
                'entity_key' => 'coverage_warning',
                'support_count' => 1,
                'confidence' => 0.9,
                'strength_score' => 55,
                'evidence_refs' => [],
                'meta' => ['source' => 'coverage_warning'],
            ];
        }

        $stored = 0;
        foreach ($patterns as $pattern) {
            if (self::insert($workspace_id, $user_id, $run_id, $pattern)) { $stored++; }
        }
        return ['stored' => $stored, 'patterns' => self::prompt_pack($run_id, 20)];
    }

    private static function patterns_from_evidence_rows($run_id) {
        if (!class_exists('VES_Evidence_Store') || !method_exists('VES_Evidence_Store', 'list_items')) { return []; }
        $payload = VES_Evidence_Store::list_items($run_id, ['page' => 1, 'per_page' => 120, 'sort' => 'metric_desc']);
        $rows = (array) ($payload['items'] ?? []);
        if (empty($rows)) { return []; }
        $clusters = ['content_type' => [], 'theme' => [], 'format' => [], 'hook' => []];
        $high_metric = [];
        $competitor_refs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $ref = sanitize_text_field((string) ($row['evidence_ref'] ?? ''));
            if ($ref === '') { continue; }
            $platform = sanitize_key((string) ($row['platform'] ?? 'all')) ?: 'all';
            $content_type = sanitize_key((string) ($row['content_type'] ?? 'unknown'));
            if ($content_type !== '' && $content_type !== 'unknown') { self::cluster_add($clusters['content_type'], $platform . '|' . $content_type, $ref, ['platform' => $platform, 'value' => $content_type]); }
            $normalized = is_array($row['normalized'] ?? null) ? $row['normalized'] : [];
            foreach (['detected_themes' => 'theme', 'detected_formats' => 'format', 'detected_hooks' => 'hook'] as $key => $cluster_key) {
                foreach ((array) ($normalized[$key] ?? []) as $value) {
                    if (!is_scalar($value)) { continue; }
                    $clean = sanitize_text_field((string) $value);
                    if ($clean !== '') { self::cluster_add($clusters[$cluster_key], $platform . '|' . sanitize_title($clean), $ref, ['platform' => $platform, 'value' => $clean]); }
                }
            }
            $metric_score = (float) ($row['metric_score'] ?? 0);
            if ($metric_score > 0) { $high_metric[] = ['ref' => $ref, 'score' => $metric_score, 'platform' => $platform]; }
            if (($row['entity_role'] ?? $row['entity_type'] ?? '') === 'competitor') { $competitor_refs[] = $ref; }
        }
        $patterns = [];
        foreach ($clusters['content_type'] as $cluster) { if (count($cluster['refs']) >= 3) { $patterns[] = self::cluster_pattern('content_format', 'Repeated content type: ' . $cluster['value'], 'Repeated ' . $cluster['value'] . ' evidence appears on ' . $cluster['platform'] . '.', $cluster, 3.8, 'content_type_cluster'); } }
        foreach ($clusters['theme'] as $cluster) { if (count($cluster['refs']) >= 2) { $patterns[] = self::cluster_pattern('message_angle', 'Theme cluster: ' . $cluster['value'], 'A recurring theme appears across collected evidence. Treat as a pattern candidate, not a confirmed strategic insight.', $cluster, 4.2, 'detected_theme_cluster'); } }
        foreach ($clusters['format'] as $cluster) { if (count($cluster['refs']) >= 2) { $patterns[] = self::cluster_pattern('creative_pattern', 'Format cluster: ' . $cluster['value'], 'A recurring creative/content format appears in normalized evidence.', $cluster, 4.0, 'detected_format_cluster'); } }
        foreach ($clusters['hook'] as $cluster) { if (count($cluster['refs']) >= 2) { $patterns[] = self::cluster_pattern('hook', 'Hook cluster: ' . $cluster['value'], 'A repeated hook or opening angle was detected in normalized evidence.', $cluster, 4.0, 'detected_hook_cluster'); } }
        usort($high_metric, static function($a, $b) { return ($b['score'] <=> $a['score']); });
        if (count($high_metric) >= 3) {
            $top = array_slice($high_metric, 0, 8);
            $patterns[] = [
                'pattern_type' => 'audience_reaction', 'title' => 'High metric-score evidence cluster',
                'description' => 'Several items have comparatively high metric_score. This is an attention signal candidate and still needs platform/context validation.',
                'platform' => 'all', 'entity_role' => 'all', 'entity_key' => 'high_metric_score', 'support_count' => count($top),
                'confidence' => min(0.75, 0.35 + (count($top) * 0.06)), 'strength_score' => min(100, 45 + count($top) * 6),
                'evidence_refs' => array_column($top, 'ref'), 'meta' => ['source' => 'metric_score_cluster', 'scores' => array_slice($top, 0, 5)],
            ];
        }
        if (count($competitor_refs) >= 3 && count($competitor_refs) / max(1, count($rows)) >= 0.35) {
            $patterns[] = [
                'pattern_type' => 'competitor_behavior', 'title' => 'Competitor-heavy evidence cluster',
                'description' => 'A large share of stored evidence belongs to competitors. This supports competitor gap analysis but should not be confused with main-brand performance.',
                'platform' => 'all', 'entity_role' => 'competitor', 'entity_key' => 'competitor_heavy_cluster', 'support_count' => count($competitor_refs),
                'confidence' => min(0.8, 0.35 + (count($competitor_refs) * 0.04)), 'strength_score' => min(100, 40 + count($competitor_refs) * 4),
                'evidence_refs' => array_slice($competitor_refs, 0, 12), 'meta' => ['source' => 'competitor_share_cluster', 'share' => round(count($competitor_refs) / max(1, count($rows)), 3)],
            ];
        }
        return array_slice($patterns, 0, 30);
    }

    private static function cluster_add(&$clusters, $key, $ref, $meta) {
        if (!isset($clusters[$key])) { $clusters[$key] = ['refs' => [], 'platform' => $meta['platform'] ?? 'all', 'value' => $meta['value'] ?? '']; }
        $clusters[$key]['refs'][] = $ref;
        $clusters[$key]['refs'] = array_values(array_unique($clusters[$key]['refs']));
    }

    private static function cluster_pattern($type, $title, $description, $cluster, $score_multiplier, $source) {
        $support = count((array) ($cluster['refs'] ?? []));
        return [
            'pattern_type' => $type, 'title' => $title, 'description' => $description,
            'platform' => sanitize_key((string) ($cluster['platform'] ?? 'all')) ?: 'all', 'entity_role' => 'all', 'entity_key' => sanitize_title((string) ($cluster['value'] ?? $type)),
            'support_count' => $support, 'confidence' => min(0.78, 0.32 + ($support * 0.08)), 'strength_score' => min(100, 35 + ($support * $score_multiplier)),
            'evidence_refs' => array_slice((array) ($cluster['refs'] ?? []), 0, 12), 'meta' => ['source' => $source, 'cluster_value' => $cluster['value'] ?? ''],
        ];
    }

    private static function insert($workspace_id, $user_id, $run_id, $pattern) {
        global $wpdb;
        $pattern = is_array($pattern) ? $pattern : [];
        $now = current_time('mysql');
        $title = sanitize_text_field((string) ($pattern['title'] ?? 'Pattern candidate'));
        $type = self::safe_pattern_type($pattern['pattern_type'] ?? 'content_format');
        $platform = sanitize_key((string) ($pattern['platform'] ?? 'all'));
        $entity_role = self::safe_role($pattern['entity_role'] ?? 'unknown');
        $entity_key = sanitize_title((string) ($pattern['entity_key'] ?? $entity_role));
        $pattern_key = hash('sha256', implode('|', [$run_id, $type, $platform, $entity_role, $entity_key, strtolower($title)]));
        $refs = self::string_list($pattern['evidence_refs'] ?? []);
        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'filter_valid_evidence_refs')) {
            $refs = VES_Evidence_Store::filter_valid_evidence_refs($run_id, $refs);
        }
        return false !== $wpdb->insert(self::table_name(), [
            'workspace_id' => max(0, (int) $workspace_id),
            'user_id' => max(0, (int) $user_id),
            'run_id' => sanitize_text_field((string) $run_id),
            'pattern_key' => $pattern_key,
            'pattern_type' => $type,
            'title' => $title,
            'description' => sanitize_textarea_field((string) ($pattern['description'] ?? '')),
            'platform' => $platform,
            'entity_role' => $entity_role,
            'entity_key' => $entity_key,
            'evidence_refs_json' => wp_json_encode($refs, JSON_UNESCAPED_UNICODE),
            'support_count' => max(0, (int) ($pattern['support_count'] ?? 0)),
            'confidence' => max(0, min(1, (float) ($pattern['confidence'] ?? 0.4))),
            'strength_score' => max(0, min(100, (float) ($pattern['strength_score'] ?? 0))),
            'status' => self::safe_status($pattern['status'] ?? 'candidate'),
            'created_at' => $now,
            'updated_at' => $now,
            'meta_json' => wp_json_encode(self::compact($pattern['meta'] ?? []), JSON_UNESCAPED_UNICODE),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%f','%f','%s','%s','%s','%s']);
    }

    public static function list_by_run($run_id, $limit = 50) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $limit = max(1, min(200, (int) $limit));
        if ($run_id === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY strength_score DESC, id ASC LIMIT %d', $run_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_workspace($workspace_id, $limit = 50) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $limit = max(1, min(200, (int) $limit));
        if ($workspace_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_run_paginated($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]; }
        $filters = is_array($filters) ? $filters : [];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(80, (int) ($filters['per_page'] ?? 20)));
        $where = ['run_id = %s'];
        $params = [$run_id];
        foreach (['pattern_type' => 'pattern_type', 'platform' => 'platform', 'status' => 'status'] as $key => $column) {
            $value = sanitize_key((string) ($filters[$key] ?? ''));
            if ($value !== '') { $where[] = $column . ' = %s'; $params[] = $value; }
        }
        $where_sql = implode(' AND ', $where);
        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where_sql, $params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE ' . $where_sql . ' ORDER BY strength_score DESC, id ASC LIMIT %d OFFSET %d', array_merge($params, [$per_page, $offset])), ARRAY_A);
        return ['items' => array_map([__CLASS__, 'prepare'], (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => (int) ceil($total / $per_page)];
    }

    public static function count_by_run($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return 0; }
        $filters = is_array($filters) ? $filters : [];
        $where = ['run_id = %s'];
        $params = [$run_id];
        foreach (['pattern_type' => 'pattern_type', 'platform' => 'platform', 'entity_role' => 'entity_role', 'status' => 'status'] as $key => $column) {
            if (!isset($filters[$key]) || $filters[$key] === '') { continue; }
            $value = sanitize_key((string) $filters[$key]);
            if ($value !== '') { $where[] = $column . ' = %s'; $params[] = $value; }
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where), $params));
    }

    public static function get($id) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        if ($id <= 0) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return is_array($row) ? self::prepare($row) : null;
    }

    public static function prompt_pack($run_id, $limit = 20) {
        $rows = self::list_by_run($run_id, $limit);
        return array_map(static function($row) {
            return [
                'pattern_ref' => 'pat_' . (int) $row['id'],
                'pattern_type' => $row['pattern_type'],
                'title' => $row['title'],
                'description' => $row['description'],
                'platform' => $row['platform'],
                'entity_role' => $row['entity_role'],
                'support_count' => $row['support_count'],
                'confidence' => $row['confidence'],
                'evidence_refs' => $row['evidence_refs'],
            ];
        }, $rows);
    }

    private static function prepare($row) {
        $refs = json_decode((string) ($row['evidence_refs_json'] ?? '[]'), true);
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'pattern_key' => (string) ($row['pattern_key'] ?? ''),
            'pattern_type' => (string) ($row['pattern_type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'platform' => (string) ($row['platform'] ?? ''),
            'entity_role' => (string) ($row['entity_role'] ?? ''),
            'entity_key' => (string) ($row['entity_key'] ?? ''),
            'evidence_refs' => is_array($refs) ? array_values($refs) : [],
            'support_count' => (int) ($row['support_count'] ?? 0),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'strength_score' => (float) ($row['strength_score'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'meta' => is_array($meta) ? $meta : [],
        ];
    }

    public static function user_can_access_record($id, $user_id = 0) {
        if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }
        $row = self::get($id);
        if (!$row) { return false; }
        $user_id = max(0, (int) ($user_id ?: (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        return $user_id > 0 && ((int) $row['user_id'] === $user_id || (int) $row['workspace_id'] === $user_id);
    }

    private static function detailed_evidence_patterns($run_id) {
        if (!class_exists('VES_Evidence_Store') || !method_exists('VES_Evidence_Store', 'list_items')) { return []; }
        $payload = VES_Evidence_Store::list_items($run_id, ['page' => 1, 'per_page' => 80, 'sort' => 'metric_desc']);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (empty($items)) { return []; }
        $groups = ['content_type' => [], 'theme' => [], 'format' => [], 'hook' => [], 'high_metric' => [], 'competitor_platform' => []];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $ref = (string) ($item['evidence_ref'] ?? '');
            if ($ref === '') { continue; }
            $platform = sanitize_key((string) ($item['platform'] ?? 'all')) ?: 'all';
            $role = sanitize_key((string) ($item['entity_type'] ?? $item['entity_role'] ?? 'unknown')) ?: 'unknown';
            $content_type = sanitize_key((string) ($item['content_type'] ?? 'unknown')) ?: 'unknown';
            $groups['content_type'][$platform . '|' . $content_type]['refs'][] = $ref;
            $groups['content_type'][$platform . '|' . $content_type]['platform'] = $platform;
            $groups['content_type'][$platform . '|' . $content_type]['label'] = $content_type;
            if ($role === 'competitor') {
                $groups['competitor_platform'][$platform]['refs'][] = $ref;
                $groups['competitor_platform'][$platform]['platform'] = $platform;
                $groups['competitor_platform'][$platform]['label'] = $platform;
            }
            if ((float) ($item['metric_score'] ?? 0) > 0) {
                $bucket = $platform . '|high_metric';
                $groups['high_metric'][$bucket]['refs'][] = $ref;
                $groups['high_metric'][$bucket]['platform'] = $platform;
                $groups['high_metric'][$bucket]['label'] = 'high metric-score';
            }
            $normalized = is_array($item['normalized'] ?? null) ? $item['normalized'] : [];
            foreach (['detected_themes' => 'theme', 'detected_formats' => 'format', 'detected_hooks' => 'hook'] as $key => $bucket) {
                $values = $normalized[$key] ?? [];
                if (is_string($values)) { $values = preg_split('/[,\n]+/', $values); }
                foreach ((array) $values as $value) {
                    if (!is_scalar($value)) { continue; }
                    $value = trim(sanitize_text_field((string) $value));
                    if ($value === '') { continue; }
                    $gkey = $platform . '|' . sanitize_title($value);
                    $groups[$bucket][$gkey]['refs'][] = $ref;
                    $groups[$bucket][$gkey]['platform'] = $platform;
                    $groups[$bucket][$gkey]['label'] = $value;
                }
            }
        }
        $patterns = [];
        $build = function($collection, $type, $title_tpl, $meta_source, $min_support = 3) use (&$patterns) {
            foreach ($collection as $group) {
                $refs = array_values(array_unique((array) ($group['refs'] ?? [])));
                $count = count($refs);
                if ($count < $min_support) { continue; }
                $platform = sanitize_key((string) ($group['platform'] ?? 'all')) ?: 'all';
                $label = sanitize_text_field((string) ($group['label'] ?? 'cluster'));
                $patterns[] = [
                    'pattern_type' => $type,
                    'title' => sprintf($title_tpl, $label, $platform),
                    'description' => 'Rule-based pattern candidate supported by ' . $count . ' evidence item(s). This is a candidate signal, not a confirmed strategic conclusion.',
                    'platform' => $platform,
                    'entity_role' => $type === 'competitor_behavior' ? 'competitor' : 'all',
                    'entity_key' => sanitize_title($label),
                    'support_count' => $count,
                    'confidence' => self::confidence_from_count($count),
                    'strength_score' => min(100, 35 + ($count * 5)),
                    'evidence_refs' => array_slice($refs, 0, 12),
                    'meta' => ['source' => $meta_source, 'label' => $label, 'rule' => 'normalized_evidence_cluster'],
                ];
            }
        };
        $build($groups['content_type'], 'content_format', 'Repeated %s content type on %s', 'content_type_cluster', 3);
        $build($groups['theme'], 'message_angle', 'Repeated theme "%s" on %s', 'detected_theme_cluster', 2);
        $build($groups['format'], 'creative_pattern', 'Repeated format "%s" on %s', 'detected_format_cluster', 2);
        $build($groups['hook'], 'hook', 'Repeated hook "%s" on %s', 'detected_hook_cluster', 2);
        $build($groups['high_metric'], 'audience_reaction', 'High metric-score evidence cluster on %s', 'high_metric_cluster', 3);
        $build($groups['competitor_platform'], 'competitor_behavior', 'Competitor-heavy signal cluster on %s', 'competitor_cluster', 2);
        return $patterns;
    }

    private static function evidence_refs_for($run_id, $filters, $limit) {
        if (!class_exists('VES_Evidence_Store') || !method_exists('VES_Evidence_Store', 'list_items')) { return []; }
        $payload = VES_Evidence_Store::list_items($run_id, array_merge((array) $filters, ['page' => 1, 'per_page' => max(1, min(20, (int) $limit)), 'sort' => 'quality_desc']));
        $refs = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $ref = (string) ($item['evidence_ref'] ?? '');
            if ($ref !== '') { $refs[] = $ref; }
        }
        return array_values(array_unique($refs));
    }

    private static function platform_to_pattern_type($platform) {
        $platform = sanitize_key((string) $platform);
        if (in_array($platform, ['instagram','tiktok','youtube','twitter','facebook','linkedin','reddit','social'], true)) { return 'content_format'; }
        if (in_array($platform, ['google_search','google_trends'], true)) { return 'search_intent'; }
        if ($platform === 'google_news') { return 'market_gap'; }
        return 'platform_trend';
    }

    private static function confidence_from_count($count) {
        $count = max(0, (int) $count);
        if ($count >= 30) { return 0.78; }
        if ($count >= 12) { return 0.64; }
        if ($count >= 3) { return 0.42; }
        return 0.25;
    }

    private static function safe_pattern_type($type) {
        $type = sanitize_key((string) $type);
        $allowed = ['content_format','hook','message_angle','competitor_behavior','audience_reaction','creative_pattern','search_intent','market_gap','platform_trend','risk_signal'];
        return in_array($type, $allowed, true) ? $type : 'content_format';
    }

    private static function safe_role($role) {
        $role = sanitize_key((string) $role);
        return in_array($role, ['main_brand','competitor','market','unknown','all'], true) ? $role : 'unknown';
    }

    private static function safe_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, ['candidate','accepted','rejected','archived'], true) ? $status : 'candidate';
    }

    private static function string_list($items) {
        if (is_string($items)) { $items = preg_split('/[\n,]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) { continue; }
            $item = trim(sanitize_text_field((string) $item));
            if ($item !== '') { $out[] = $item; }
        }
        return array_values(array_unique(array_slice($out, 0, 40)));
    }

    private static function compact($value, $depth = 0) {
        if ($depth > 4) { return '[truncated_depth]'; }
        if (is_scalar($value) || $value === null) { return is_string($value) ? substr($value, 0, 1600) : $value; }
        if (!is_array($value)) { return ''; }
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
