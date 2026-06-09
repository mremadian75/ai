<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Queryable metric snapshots derived from normalized evidence.
 * Phase 2 foundation: rule-based metrics first; AI interpretation comes later.
 */
final class VES_Metric_Snapshots {
    const DB_VERSION = '1.2.0';
    const OPTION_KEY = 'ves_metric_snapshots_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_metric_snapshots';
    }



    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id', 'workspace_id', 'user_id', 'run_id', 'bundle_id', 'brand_name', 'entity_role', 'entity_key', 'platform', 'metric_key', 'metric_value', 'metric_unit', 'source_count', 'confidence', 'calculated_at', 'meta_json', 'created_at'];
    }



    public static function required_indexes() {
        return ['run_id','workspace_id','entity_key','platform','metric_key','calculated_at'];
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
            bundle_id varchar(80) NULL,
            brand_name varchar(190) NOT NULL DEFAULT '',
            entity_role varchar(32) NOT NULL DEFAULT '',
            entity_key varchar(120) NOT NULL DEFAULT '',
            platform varchar(40) NOT NULL DEFAULT '',
            metric_key varchar(80) NOT NULL DEFAULT '',
            metric_value decimal(16,4) NOT NULL DEFAULT 0.0000,
            metric_unit varchar(32) NOT NULL DEFAULT 'count',
            source_count int(11) NOT NULL DEFAULT 0,
            confidence decimal(5,2) NOT NULL DEFAULT 0.00,
            calculated_at datetime NOT NULL,
            meta_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY bundle_id (bundle_id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY entity_key (entity_key),
            KEY entity_role (entity_role),
            KEY platform (platform),
            KEY metric_key (metric_key),
            KEY calculated_at (calculated_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function delete_for_run($run_id) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return; }
        $wpdb->delete(self::table_name(), ['run_id' => $run_id], ['%s']);
    }

    public static function calculate_from_evidence($run_id, $workspace_id, $user_id, $request = [], $evidence_summary = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['stored' => 0, 'metrics' => []]; }
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $request = is_array($request) ? $request : [];
        $brand = sanitize_text_field((string) ($request['brand_name'] ?? ''));
        $summary = is_array($evidence_summary) && !empty($evidence_summary)
            ? $evidence_summary
            : (class_exists('VES_Evidence_Store') ? VES_Evidence_Store::summary_by_run($run_id) : []);

        self::delete_for_run($run_id);
        $rows = [];
        $total = (int) ($summary['total'] ?? $summary['stored'] ?? 0);
        $avg_quality = (float) ($summary['average_quality'] ?? $summary['average_quality_score'] ?? 0);
        $coverage_score = self::coverage_score($summary);
        $source_coverage_quality = self::source_coverage_quality($summary);
        $strategic_confidence_score = self::strategic_confidence_score($summary, $coverage_score);
        $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'evidence_count_total', $total, 'items', $total, self::confidence_from_count($total), ['source' => 'evidence_summary']);
        $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'evidence_row_quality_score', $avg_quality, 'score', $total, self::confidence_from_count($total), ['scale' => '0-100', 'replaces' => 'avg_quality_score']);
        $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'source_coverage_quality', $source_coverage_quality, 'score', $total, self::confidence_from_count($total), ['scale' => '0-100']);
        $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'strategic_confidence_score', $strategic_confidence_score, 'score', $total, self::confidence_from_count($total), ['scale' => '0-100']);
        $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'coverage_score', $coverage_score, 'score', $total, self::confidence_from_count($total), ['warnings' => $summary['coverage_warnings'] ?? [], 'formula' => 'weighted_source_health_family_diversity_competitor_quality']);

        foreach ((array) ($summary['by_platform'] ?? []) as $platform => $count) {
            $platform = sanitize_key((string) $platform);
            $count = (int) $count;
            $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', $platform, 'evidence_count_by_platform', $count, 'items', $count, self::confidence_from_count($count), []);
        }
        foreach ((array) ($summary['by_entity_type'] ?? []) as $role => $count) {
            $role = self::safe_role($role);
            $count = (int) $count;
            $metric_key = $role === 'competitor' ? 'competitor_mention_count' : ($role === 'main_brand' ? 'main_brand_evidence_count' : $role . '_evidence_count');
            $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, $role, $role, 'all', $metric_key, $count, 'items', $count, self::confidence_from_count($count), []);
        }
        foreach ((array) ($summary['by_source_type'] ?? []) as $source_type => $count) {
            $source_type = sanitize_key((string) $source_type);
            $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'source_type_' . $source_type . '_count', (int) $count, 'items', (int) $count, self::confidence_from_count((int) $count), ['source_type' => $source_type]);
        }

        if (class_exists('VES_Evidence_Store')) {
            $content_counts = self::content_type_counts($run_id);
            foreach ($content_counts as $content_type => $count) {
                $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', 'all', 'top_content_type_count', (int) $count, 'items', (int) $count, self::confidence_from_count((int) $count), ['content_type' => $content_type]);
            }
            $platform_quality = self::platform_quality($run_id);
            foreach ($platform_quality as $platform => $avg) {
                $rows[] = self::row($workspace_id, $user_id, $run_id, $run_id, $brand, 'all', 'all', $platform, 'avg_quality_by_platform', (float) $avg, 'score', (int) (($summary['by_platform'][$platform] ?? 0)), self::confidence_from_count((int) (($summary['by_platform'][$platform] ?? 0))), []);
            }
        }

        $stored = 0;
        foreach ($rows as $row) {
            if ($wpdb->insert(self::table_name(), $row, self::formats()) !== false) { $stored++; }
        }
        return ['stored' => $stored, 'metrics' => self::prompt_pack($run_id, 24)];
    }

    private static function row($workspace_id, $user_id, $run_id, $bundle_id, $brand, $role, $entity_key, $platform, $metric_key, $value, $unit, $source_count, $confidence, $meta) {
        $now = current_time('mysql');
        return [
            'workspace_id' => max(0, (int) $workspace_id),
            'user_id' => max(0, (int) $user_id),
            'run_id' => sanitize_text_field((string) $run_id),
            'bundle_id' => sanitize_text_field((string) $bundle_id),
            'brand_name' => sanitize_text_field((string) $brand),
            'entity_role' => self::safe_role($role),
            'entity_key' => sanitize_title((string) $entity_key),
            'platform' => sanitize_key((string) $platform),
            'metric_key' => sanitize_key((string) $metric_key),
            'metric_value' => round((float) $value, 4),
            'metric_unit' => sanitize_key((string) $unit),
            'source_count' => max(0, (int) $source_count),
            'confidence' => max(0, min(1, (float) $confidence)),
            'calculated_at' => $now,
            'meta_json' => wp_json_encode(self::compact($meta), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ];
    }

    private static function formats() {
        return ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%f','%s','%d','%f','%s','%s','%s'];
    }

    public static function list_by_run($run_id, $limit = 100) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $limit = max(1, min(300, (int) $limit));
        if ($run_id === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY platform ASC, metric_key ASC, id ASC LIMIT %d', $run_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_workspace($workspace_id, $limit = 100) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $limit = max(1, min(300, (int) $limit));
        if ($workspace_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d ORDER BY calculated_at DESC, id DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
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
        foreach (['metric_key' => 'metric_key', 'platform' => 'platform', 'entity_role' => 'entity_role'] as $key => $column) {
            $value = sanitize_key((string) ($filters[$key] ?? ''));
            if ($value !== '') { $where[] = $column . ' = %s'; $params[] = $value; }
        }
        $where_sql = implode(' AND ', $where);
        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where_sql, $params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE ' . $where_sql . ' ORDER BY platform ASC, metric_key ASC, id ASC LIMIT %d OFFSET %d', array_merge($params, [$per_page, $offset])), ARRAY_A);
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
        foreach (['metric_key' => 'metric_key', 'platform' => 'platform', 'entity_role' => 'entity_role'] as $key => $column) {
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

    public static function prompt_pack($run_id, $limit = 24) {
        $rows = self::list_by_run($run_id, $limit);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'metric_key' => $row['metric_key'],
                'platform' => $row['platform'],
                'entity_role' => $row['entity_role'],
                'value' => $row['metric_value'],
                'unit' => $row['metric_unit'],
                'source_count' => $row['source_count'],
                'confidence' => $row['confidence'],
                'meta' => $row['meta'],
            ];
        }
        return $out;
    }

    private static function prepare($row) {
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'bundle_id' => (string) ($row['bundle_id'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'entity_role' => (string) ($row['entity_role'] ?? ''),
            'entity_key' => (string) ($row['entity_key'] ?? ''),
            'platform' => (string) ($row['platform'] ?? ''),
            'metric_key' => (string) ($row['metric_key'] ?? ''),
            'metric_value' => (float) ($row['metric_value'] ?? 0),
            'metric_unit' => (string) ($row['metric_unit'] ?? ''),
            'source_count' => (int) ($row['source_count'] ?? 0),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'calculated_at' => (string) ($row['calculated_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
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

    private static function coverage_score($summary) {
        $summary = is_array($summary) ? $summary : [];
        $total = max(0, (int) ($summary['total'] ?? $summary['stored'] ?? 0));
        $platforms = count((array) ($summary['by_platform'] ?? []));
        $warnings = count((array) ($summary['coverage_warnings'] ?? []));
        $avg_quality = max(0, min(100, (float) ($summary['average_quality'] ?? $summary['average_quality_score'] ?? 0)));
        $source_health = is_array($summary['source_health'] ?? null) ? $summary['source_health'] : $summary;
        $usable = max(0, (int) ($source_health['usable_sources'] ?? 0));
        $partial = max(0, (int) ($source_health['partial_sources'] ?? 0));
        $failed = max(0, (int) ($source_health['failed_sources'] ?? 0));
        $source_total = max(1, (int) ($source_health['total_sources'] ?? ($usable + $partial + $failed)));
        $source_health_score = $source_total > 0 ? ($usable / $source_total) * 100 : 0;
        $family_count = count((array) ($source_health['source_family_coverage'] ?? []));
        if ($family_count <= 0 && $platforms > 0) { $family_count = min(5, $platforms); }
        $family_score = min(100, ($family_count / 5) * 100);
        $diversity_score = min(100, ($platforms / 5) * 70 + min(30, $total));
        $competitor_total = max(0, (int) ($source_health['competitor_total'] ?? 0));
        $competitor_usable = max(0, (int) ($source_health['competitor_usable'] ?? 0));
        $competitor_score = $competitor_total > 0 ? min(100, ($competitor_usable / max(1, $competitor_total)) * 100) : 40;
        $quality_score = $avg_quality;
        $score = ($source_health_score * 0.30) + ($family_score * 0.20) + ($diversity_score * 0.20) + ($competitor_score * 0.15) + ($quality_score * 0.15);
        if ($warnings > 0) { $score -= min(18, $warnings * 4); }
        if (($failed + $partial) > $usable && ($failed + $partial) > 0) { $score = min($score, 70); }
        if ($competitor_total > 0 && $competitor_usable < ceil($competitor_total / 2)) { $score = min($score, 70); }
        if (!empty($source_health['openai_failed']) || !empty($summary['openai_failed'])) { $score = min($score, 70); }
        if (max(0, (int) ($source_health['social_item_count'] ?? 0)) < 20) { $score = min($score, 70); }
        if ($source_total > 0 && ($usable / $source_total) < 0.5) { $score = min($score, 65); }
        return round(max(0, min(100, $score)), 2);
    }

    private static function source_coverage_quality($summary) {
        $summary = is_array($summary) ? $summary : [];
        $source_health = is_array($summary['source_health'] ?? null) ? $summary['source_health'] : $summary;
        $usable = max(0, (int) ($source_health['usable_sources'] ?? 0));
        $total = max(1, (int) ($source_health['total_sources'] ?? $usable));
        return round(max(0, min(100, ($usable / $total) * 100)), 2);
    }

    private static function strategic_confidence_score($summary, $coverage_score) {
        $summary = is_array($summary) ? $summary : [];
        $source_health = is_array($summary['source_health'] ?? null) ? $summary['source_health'] : $summary;
        $coverage_score = (float) $coverage_score;
        if (!empty($source_health['openai_failed']) || !empty($summary['openai_failed'])) { $coverage_score = min($coverage_score, 52); }
        if (!empty($source_health['only_internal_or_search_evidence'])) { $coverage_score = min($coverage_score, 45); }
        if (($source_health['competitor_coverage_status'] ?? '') === 'blind_spot') { $coverage_score = min($coverage_score, 55); }
        return round(max(0, min(100, $coverage_score)), 2);
    }

    private static function confidence_from_count($count) {
        $count = max(0, (int) $count);
        if ($count >= 50) { return 0.85; }
        if ($count >= 20) { return 0.72; }
        if ($count >= 8) { return 0.55; }
        if ($count >= 1) { return 0.35; }
        return 0.15;
    }

    private static function content_type_counts($run_id) {
        global $wpdb;
        $table = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::table_name() : '';
        if ($table === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT content_type, COUNT(*) AS c FROM {$table} WHERE run_id = %s GROUP BY content_type ORDER BY c DESC LIMIT 8", sanitize_text_field((string) $run_id)), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $key = sanitize_key((string) ($row['content_type'] ?? 'unknown')) ?: 'unknown';
            $out[$key] = (int) ($row['c'] ?? 0);
        }
        return $out;
    }

    private static function platform_quality($run_id) {
        global $wpdb;
        $table = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::table_name() : '';
        if ($table === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT platform, AVG(quality_score) AS q FROM {$table} WHERE run_id = %s GROUP BY platform ORDER BY q DESC LIMIT 12", sanitize_text_field((string) $run_id)), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $key = sanitize_key((string) ($row['platform'] ?? 'unknown')) ?: 'unknown';
            $out[$key] = round((float) ($row['q'] ?? 0), 2);
        }
        return $out;
    }

    private static function safe_role($role) {
        $role = sanitize_key((string) $role);
        return in_array($role, ['main_brand','competitor','market','unknown','all'], true) ? $role : 'unknown';
    }

    private static function compact($value, $depth = 0) {
        if ($depth > 4) { return '[truncated_depth]'; }
        if (is_scalar($value) || $value === null) { return is_string($value) ? substr($value, 0, 1200) : $value; }
        if (!is_array($value)) { return ''; }
        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if ($i >= 50) { $out['_truncated'] = true; break; }
            $out[is_int($key) ? $key : sanitize_key((string) $key)] = self::compact($item, $depth + 1);
            $i++;
        }
        return $out;
    }
}
