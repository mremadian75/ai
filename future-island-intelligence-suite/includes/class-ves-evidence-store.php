<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WordPress-first normalized evidence store for the Intelligence Suite (VES).
 *
 * Stores de-duplicated, source-level evidence rows for Brand Audit and future
 * intelligence workflows. This is intentionally relational/JSON hybrid so the
 * plugin can support filters, assistant context, memory, metrics and later
 * pattern/insight layers without requiring a vector database first.
 */
final class VES_Evidence_Store {
    const DB_VERSION = '1.6.0';
    const OPTION_KEY = 'ves_evidence_store_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_evidence_items';
    }



    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id', 'workspace_id', 'user_id', 'project_id', 'run_id', 'source_id', 'item_hash', 'evidence_ref', 'external_id', 'entity_name', 'entity_type', 'entity_role', 'entity_key', 'platform', 'source_type', 'source_status', 'source_label', 'content_type', 'url', 'title', 'text', 'author', 'published_at', 'collected_at', 'media_url', 'metrics_json', 'normalized_json', 'raw_payload_json', 'quality_score', 'metric_score', 'quality_flags_json', 'created_at'];
    }



    public static function required_indexes() {
        return ['run_item_hash','run_id','workspace_id','user_id','project_id','evidence_ref','platform'];
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
            run_id varchar(80) NOT NULL DEFAULT '',
            source_id varchar(120) NOT NULL DEFAULT '',
            item_hash varchar(64) NOT NULL DEFAULT '',
            evidence_ref varchar(80) NOT NULL DEFAULT '',
            external_id varchar(190) NOT NULL DEFAULT '',
            entity_name varchar(190) NOT NULL DEFAULT '',
            entity_type varchar(32) NOT NULL DEFAULT 'unknown',
            entity_role varchar(32) NOT NULL DEFAULT '',
            entity_key varchar(120) NOT NULL DEFAULT '',
            platform varchar(40) NOT NULL DEFAULT '',
            source_type varchar(64) NOT NULL DEFAULT '',
            source_status varchar(32) NOT NULL DEFAULT '',
            source_label varchar(190) NOT NULL DEFAULT '',
            content_type varchar(32) NOT NULL DEFAULT 'unknown',
            url text NULL,
            title text NULL,
            text longtext NULL,
            author varchar(190) NOT NULL DEFAULT '',
            published_at datetime NULL,
            collected_at datetime NULL,
            media_url text NULL,
            metrics_json longtext NULL,
            normalized_json longtext NULL,
            raw_payload_json longtext NULL,
            quality_score decimal(5,2) NOT NULL DEFAULT 0.00,
            metric_score decimal(10,2) NOT NULL DEFAULT 0.00,
            quality_flags_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_item_hash (run_id, item_hash),
            KEY run_id (run_id),
            KEY user_id (user_id),
            KEY project_id (project_id),
            KEY workspace_id (workspace_id),
            KEY item_hash (item_hash),
            KEY evidence_ref (evidence_ref),
            KEY external_id (external_id),
            KEY platform (platform),
            KEY source_id (source_id),
            KEY entity_type (entity_type),
            KEY entity_role (entity_role),
            KEY entity_key (entity_key),
            KEY source_status (source_status),
            KEY collected_at (collected_at),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function clear_run($run_id) {
        global $wpdb;
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return; }
        self::create_table();
        $wpdb->delete(self::table_name(), ['run_id' => $run_id], ['%s']);
    }

    public static function infer_workspace_id($request, $user_id = 0) {
        $request = is_array($request) ? $request : [];
        $user_id = max(0, (int) ($user_id ?: ($request['user_id'] ?? 0)));
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'infer_workspace_id')) {
            return (int) VES_Memory_Records::infer_workspace_id($request, $user_id);
        }
        foreach (['workspace_id', 'workspaceId'] as $key) {
            if (isset($request[$key]) && is_numeric($request[$key])) {
                $candidate = max(0, (int) $request[$key]);
                if ($candidate > 0 && ($candidate === $user_id || (function_exists('current_user_can') && current_user_can('manage_options')))) {
                    return $candidate;
                }
            }
        }
        if ($user_id > 0) { return $user_id; }
        return function_exists('get_current_user_id') ? max(0, (int) get_current_user_id()) : 0;
    }

    public static function infer_project_id($request, $workspace_id = 0, $user_id = 0) {
        $request = is_array($request) ? $request : [];
        foreach (['project_id', 'projectId'] as $key) {
            if (empty($request[$key]) || !is_numeric($request[$key])) { continue; }
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

    public static function store_brand_audit_evidence_pack($bundle_id, $run_id, $request, $evidence_pack, $sources = []) {
        global $wpdb;
        self::create_table();
        $bundle_id = sanitize_text_field((string) $bundle_id);
        $run_id = sanitize_text_field((string) ($run_id ?: $bundle_id));
        if ($run_id === '') { return self::empty_summary($run_id); }

        $request = is_array($request) ? $request : [];
        $evidence_pack = is_array($evidence_pack) ? $evidence_pack : [];
        $sources = is_array($sources) ? $sources : [];
        $user_id = max(0, (int) ($request['user_id'] ?? 0));
        if ($user_id <= 0 && function_exists('get_current_user_id')) { $user_id = (int) get_current_user_id(); }
        $workspace_id = self::infer_workspace_id($request, $user_id);
        $project_id = self::infer_project_id($request, $workspace_id, $user_id);

        $source_meta = self::source_meta_map($sources);
        $items = self::extract_items_from_brand_audit_pack($evidence_pack, $request, $sources);
        $rows_by_hash = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $row = self::normalize_row($item, $workspace_id, $user_id, $project_id, $run_id, $request, $source_meta);
            if (!self::row_has_evidence($row) || $row['item_hash'] === '') { continue; }
            $hash = $row['item_hash'];
            if (!isset($rows_by_hash[$hash]) || self::row_completeness_score($row) > self::row_completeness_score($rows_by_hash[$hash])) {
                $rows_by_hash[$hash] = $row;
            }
        }

        $stored = 0;
        $merged = 0;
        $skipped = 0;
        foreach ($rows_by_hash as $row) {
            $existing = self::existing_by_hash($run_id, $row['item_hash']);
            if ($existing) {
                if (self::update_existing_if_richer($existing, $row)) { $merged++; } else { $skipped++; }
                continue;
            }
            $row['evidence_ref'] = '';
            $inserted = $wpdb->insert(self::table_name(), $row, self::insert_formats());
            if ($inserted === false) { continue; }
            $insert_id = (int) $wpdb->insert_id;
            $ref = self::build_evidence_ref($insert_id, $row['item_hash']);
            $wpdb->update(self::table_name(), ['evidence_ref' => $ref], ['id' => $insert_id], ['%s'], ['%d']);
            $stored++;
        }

        $summary = self::summary_by_run($run_id);
        $summary['inserted'] = $stored;
        $summary['merged'] = $merged;
        $summary['dedupe_skipped'] = $skipped;
        return $summary;
    }

    private static function insert_formats() {
        return [
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s',
        ];
    }

    private static function empty_summary($run_id) {
        return [
            'stored' => 0,
            'total' => 0,
            'run_id' => sanitize_text_field((string) $run_id),
            'by_platform' => [],
            'by_entity' => [],
            'by_entity_type' => [],
            'by_source_type' => [],
            'by_source_status' => [],
            'average_quality' => 0,
            'average_quality_score' => 0,
            'coverage_warnings' => [],
            'top_platforms' => [],
            'schema' => 'wp_ves_evidence_items_v1_4',
        ];
    }

    private static function increment_summary(&$summary, $row) {
        $platform = (string) ($row['platform'] ?? 'unknown');
        $entity_name = (string) ($row['entity_name'] ?? '');
        $entity_type = (string) ($row['entity_type'] ?? 'unknown');
        $source_type = (string) ($row['source_type'] ?? 'unknown');
        $source_status = (string) ($row['source_status'] ?? 'unknown');
        $summary['by_platform'][$platform] = (int) ($summary['by_platform'][$platform] ?? 0) + 1;
        if ($entity_name !== '') { $summary['by_entity'][$entity_name] = (int) ($summary['by_entity'][$entity_name] ?? 0) + 1; }
        $summary['by_entity_type'][$entity_type] = (int) ($summary['by_entity_type'][$entity_type] ?? 0) + 1;
        $summary['by_source_type'][$source_type] = (int) ($summary['by_source_type'][$source_type] ?? 0) + 1;
        $summary['by_source_status'][$source_status] = (int) ($summary['by_source_status'][$source_status] ?? 0) + 1;
    }

    public static function count_by_run($run_id) {
        global $wpdb;
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return 0; }
        self::create_table();
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE run_id = %s', $run_id));
    }

    public static function summary_by_run($run_id) {
        global $wpdb;
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return self::empty_summary(''); }
        self::create_table();
        $table = self::table_name();
        $summary = self::empty_summary($run_id);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id = %s", $run_id));
        $summary['stored'] = $total;
        $summary['total'] = $total;
        $summary['average_quality'] = round((float) $wpdb->get_var($wpdb->prepare("SELECT AVG(quality_score) FROM {$table} WHERE run_id = %s", $run_id)), 2);
        $summary['average_quality_score'] = $summary['average_quality'];
        foreach ([
            'by_platform' => 'platform',
            'by_entity' => 'entity_name',
            'by_entity_type' => 'entity_type',
            'by_source_type' => 'source_type',
            'by_source_status' => 'source_status',
        ] as $key => $column) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT {$column} AS k, COUNT(*) AS c FROM {$table} WHERE run_id = %s GROUP BY {$column} ORDER BY c DESC", $run_id), ARRAY_A);
            foreach ((array) $rows as $row) {
                $k = trim((string) ($row['k'] ?? ''));
                if ($k === '') { $k = 'unknown'; }
                $summary[$key][$k] = (int) ($row['c'] ?? 0);
            }
        }
        $summary['top_platforms'] = array_slice($summary['by_platform'], 0, 6, true);
        $summary['coverage_warnings'] = self::coverage_warnings($summary);
        return $summary;
    }

    public static function coverage_summary($run_id) {
        global $wpdb;
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return []; }
        self::create_table();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT platform, source_type, source_status, COUNT(*) AS c, AVG(quality_score) AS q FROM ' . self::table_name() . ' WHERE run_id = %s GROUP BY platform, source_type, source_status ORDER BY c DESC', $run_id), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function list_items($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]; }
        $filters = is_array($filters) ? $filters : [];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;
        $where = ['run_id = %s'];
        $params = [$run_id];

        foreach ([
            'platform' => 'platform',
            'entity_type' => 'entity_type',
            'source_type' => 'source_type',
            'source_status' => 'source_status',
        ] as $filter_key => $column) {
            $value = sanitize_key((string) ($filters[$filter_key] ?? ''));
            if ($value !== '') { $where[] = "{$column} = %s"; $params[] = $value; }
        }
        $entity_name = sanitize_text_field((string) ($filters['entity_name'] ?? ''));
        if ($entity_name !== '') { $where[] = 'entity_name = %s'; $params[] = $entity_name; }
        $search = sanitize_text_field((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(title LIKE %s OR text LIKE %s OR author LIKE %s OR url LIKE %s OR entity_name LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sort = sanitize_key((string) ($filters['sort'] ?? 'created_desc'));
        $orders = [
            'created_desc' => 'created_at DESC, id DESC',
            'created_asc' => 'created_at ASC, id ASC',
            'quality_desc' => 'quality_score DESC, id DESC',
            'metric_desc' => 'metric_score DESC, id DESC',
            'published_desc' => 'published_at DESC, created_at DESC, id DESC',
            'published_asc' => 'published_at ASC, created_at ASC, id ASC',
        ];
        $order = $orders[$sort] ?? $orders['created_desc'];
        $where_sql = implode(' AND ', $where);
        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params));
        $query_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, workspace_id, user_id, project_id, run_id, source_id, item_hash, evidence_ref, external_id, entity_name, entity_type, entity_role, entity_key, platform, source_type, source_status, source_label, content_type, url, title, text, author, published_at, collected_at, media_url, metrics_json, normalized_json, quality_score, metric_score, quality_flags_json, CASE WHEN raw_payload_json IS NULL OR raw_payload_json = '' THEN '' ELSE '1' END AS raw_payload_json, created_at FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d", $query_params), ARRAY_A);
        return [
            'items' => array_map([__CLASS__, 'prepare_item_for_response'], (array) $rows),
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
        ];
    }

    public static function prompt_items($run_id, $limit = 60) {
        $limit = max(1, min(120, (int) $limit));
        $result = self::list_items($run_id, ['per_page' => $limit, 'page' => 1, 'sort' => 'quality_desc']);
        $items = [];
        foreach ((array) ($result['items'] ?? []) as $item) {
            $items[] = [
                'evidence_ref' => $item['evidence_ref'] ?? '',
                'platform' => $item['platform'] ?? '',
                'entity_name' => $item['entity_name'] ?? '',
                'entity_type' => $item['entity_type'] ?? '',
                'entity_role' => $item['entity_role'] ?? '',
                'source_type' => $item['source_type'] ?? '',
                'source_status' => $item['source_status'] ?? '',
                'content_type' => $item['content_type'] ?? '',
                'title' => self::truncate((string) ($item['title'] ?? ''), 240),
                'text' => self::truncate((string) ($item['text'] ?? ''), 500),
                'url' => $item['url'] ?? '',
                'published_at' => $item['published_at'] ?? '',
                'quality_score' => $item['quality_score'] ?? 0,
                'metric_score' => $item['metric_score'] ?? 0,
                'metrics' => $item['metrics'] ?? [],
                'quality_flags' => $item['quality_flags'] ?? [],
            ];
        }
        return $items;
    }


    public static function count_missing_or_invalid_evidence_refs($run_id = '') {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $where = '(evidence_ref = "" OR evidence_ref IS NULL OR evidence_ref NOT REGEXP %s)';
        $params = ['^ev_[0-9]+_[a-f0-9]{6,20}$'];
        if ($run_id !== '') { $where .= ' AND run_id = %s'; $params[] = $run_id; }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . $where, $params));
    }

    public static function repair_evidence_refs_for_run($run_id) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['scanned' => 0, 'repaired' => 0]; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, item_hash, evidence_ref FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY id ASC', $run_id), ARRAY_A);
        $scanned = 0;
        $repaired = 0;
        foreach ((array) $rows as $row) {
            $scanned++;
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) { continue; }
            $current = sanitize_text_field((string) ($row['evidence_ref'] ?? ''));
            $expected = self::evidence_ref_for_row($row);
            if ($expected !== '' && $current !== $expected) {
                $ok = $wpdb->update(self::table_name(), ['evidence_ref' => $expected], ['id' => $id], ['%s'], ['%d']);
                if ($ok !== false) { $repaired++; }
            }
        }
        return ['scanned' => $scanned, 'repaired' => $repaired];
    }

    public static function repair_missing_evidence_refs($limit = 500) {
        global $wpdb;
        self::create_table();
        $limit = max(1, min(2000, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, item_hash, evidence_ref FROM ' . self::table_name() . ' WHERE evidence_ref = "" OR evidence_ref IS NULL OR evidence_ref NOT REGEXP %s ORDER BY id ASC LIMIT %d', '^ev_[0-9]+_[a-f0-9]{6,20}$', $limit), ARRAY_A);
        $repaired = 0;
        foreach ((array) $rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) { continue; }
            $ref = self::evidence_ref_for_row($row);
            if ($ref === '') { continue; }
            $ok = $wpdb->update(self::table_name(), ['evidence_ref' => $ref], ['id' => $id], ['%s'], ['%d']);
            if ($ok !== false) { $repaired++; }
        }
        return ['scanned' => count((array) $rows), 'repaired' => $repaired, 'remaining' => self::count_missing_or_invalid_evidence_refs('')];
    }

    public static function evidence_refs_for_run($run_id) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return []; }
        self::repair_evidence_refs_for_run($run_id);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, item_hash, evidence_ref FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY id ASC', $run_id), ARRAY_A);
        $refs = [];
        foreach ((array) $rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $stored_ref = sanitize_text_field((string) ($row['evidence_ref'] ?? ''));
            $ref = self::evidence_ref_for_row($row);
            if ($id > 0 && $ref !== '' && $stored_ref !== $ref) {
                $wpdb->update(self::table_name(), ['evidence_ref' => $ref], ['id' => $id], ['%s'], ['%d']);
            }
            if ($ref !== '') { $refs[$ref] = true; }
        }
        return array_keys($refs);
    }

    public static function evidence_refs_exist($run_id, $refs) {
        $refs = self::normalize_evidence_refs($refs);
        if (empty($refs)) { return false; }
        return count(self::filter_valid_evidence_refs($run_id, $refs)) === count($refs);
    }

    public static function filter_valid_evidence_refs($run_id, $refs) {
        $refs = self::normalize_evidence_refs($refs);
        if (empty($refs)) { return []; }
        $allowed = array_flip(self::evidence_refs_for_run($run_id));
        $valid = [];
        foreach ($refs as $ref) {
            if (isset($allowed[$ref])) { $valid[] = $ref; }
        }
        return array_values(array_unique($valid));
    }

    public static function validate_evidence_refs_for_run($run_id, $refs) {
        $requested = self::normalize_evidence_refs($refs, 80);
        $valid = self::filter_valid_evidence_refs($run_id, $requested);
        $invalid = array_values(array_diff($requested, $valid));
        $status = empty($valid) ? 'unsupported' : (count($valid) < 2 ? 'weak' : 'supported');
        return [
            'requested' => $requested,
            'valid' => array_values($valid),
            'invalid' => $invalid,
            'evidence_ref_count' => count($valid),
            'unsupported_by_evidence' => empty($valid),
            'evidence_validation_status' => $status,
        ];
    }

    public static function get_items_by_refs($run_id, $refs, $limit = 20) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $refs = self::normalize_evidence_refs($refs, $limit);
        $limit = max(1, min(50, (int) $limit));
        if ($run_id === '' || empty($refs)) { return []; }
        $placeholders = implode(',', array_fill(0, count($refs), '%s'));
        $params = array_merge([$run_id], $refs);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, workspace_id, user_id, project_id, run_id, source_id, item_hash, evidence_ref, external_id, entity_name, entity_type, entity_role, entity_key, platform, source_type, source_status, source_label, content_type, url, title, text, author, published_at, collected_at, media_url, metrics_json, normalized_json, quality_score, metric_score, quality_flags_json, CASE WHEN raw_payload_json IS NULL OR raw_payload_json = "" THEN "" ELSE "1" END AS raw_payload_json, created_at FROM ' . self::table_name() . ' WHERE run_id = %s AND evidence_ref IN (' . $placeholders . ') LIMIT ' . (int) $limit, $params), ARRAY_A);
        $by_ref = [];
        foreach ((array) $rows as $row) {
            $item = self::prepare_item_for_response($row, false);
            $by_ref[$item['evidence_ref']] = $item;
        }
        $ordered = [];
        foreach ($refs as $ref) {
            if (isset($by_ref[$ref])) { $ordered[] = $by_ref[$ref]; }
        }
        return $ordered;
    }

    public static function validate_ai_reference_payload($run_id, $structured) {
        $structured = is_array($structured) ? $structured : [];
        $allowed = self::evidence_refs_for_run($run_id);
        $allowed_map = array_flip($allowed);
        $invalid_all = [];
        $validate_list = function($items) use ($allowed_map, &$invalid_all) {
            $items = is_array($items) ? $items : [];
            foreach ($items as $idx => $item) {
                if (!is_array($item)) { continue; }
                $raw_refs = $item['evidence_refs'] ?? $item['evidence_ids'] ?? $item['evidence'] ?? [];
                $refs = self::normalize_evidence_refs($raw_refs);
                $valid = [];
                $invalid = [];
                foreach ($refs as $ref) {
                    if (isset($allowed_map[$ref])) { $valid[] = $ref; } else { $invalid[] = $ref; }
                }
                $valid = array_values(array_unique($valid));
                $invalid = array_values(array_unique($invalid));
                $items[$idx]['evidence_refs'] = $valid;
                $items[$idx]['evidence_ids'] = $valid;
                $meta = is_array($items[$idx]['meta'] ?? null) ? $items[$idx]['meta'] : [];
                if (!empty($invalid)) {
                    $invalid_all = array_merge($invalid_all, $invalid);
                    $meta['invalid_evidence_refs'] = $invalid;
                    $items[$idx]['limitations'] = array_values(array_unique(array_merge((array) ($items[$idx]['limitations'] ?? []), ['Some AI-cited evidence refs were invalid and removed.'])));
                }
                $meta['evidence_ref_count'] = count($valid);
                if (count($valid) <= 0) {
                    $meta['unsupported_by_evidence'] = true;
                    $meta['evidence_validation_status'] = 'unsupported';
                    $items[$idx]['evidence_validation_status'] = 'unsupported';
                    $items[$idx]['confidence'] = min((float) ($items[$idx]['confidence'] ?? 0.25), 0.35);
                    $items[$idx]['limitations'] = array_values(array_unique(array_merge((array) ($items[$idx]['limitations'] ?? []), ['No valid current-run evidence_ref supports this item; treat as hypothesis.'])));
                } elseif (count($valid) < 2) {
                    $meta['unsupported_by_evidence'] = false;
                    $meta['evidence_validation_status'] = 'weak';
                    $items[$idx]['evidence_validation_status'] = 'weak';
                    $items[$idx]['confidence'] = min((float) ($items[$idx]['confidence'] ?? 0.5), 0.65);
                } else {
                    $meta['unsupported_by_evidence'] = false;
                    $meta['evidence_validation_status'] = 'supported';
                    $items[$idx]['evidence_validation_status'] = 'supported';
                }
                $items[$idx]['meta'] = $meta;
            }
            return $items;
        };
        foreach (['insights', 'opportunities', 'opportunity_territories'] as $key) {
            if (isset($structured[$key])) { $structured[$key] = $validate_list($structured[$key]); }
        }
        if (isset($structured['evidence_used'])) {
            $valid_used = [];
            foreach (self::normalize_evidence_refs($structured['evidence_used'], 80) as $ref) {
                if (isset($allowed_map[$ref])) { $valid_used[] = $ref; }
                else { $invalid_all[] = $ref; }
            }
            $structured['evidence_used'] = array_values(array_unique($valid_used));
        }
        $structured['_evidence_ref_validation'] = [
            'allowed_count' => count($allowed),
            'invalid_evidence_refs' => array_values(array_unique($invalid_all)),
            'status' => empty($invalid_all) ? 'clean' : 'invalid_refs_removed',
        ];
        return $structured;
    }

    public static function normalize_evidence_refs($refs, $limit = 80) {
        if (is_string($refs)) { $refs = preg_split('/[\s,]+/', $refs); }
        if (!is_array($refs)) { return []; }
        $out = [];
        foreach ($refs as $ref) {
            if (is_array($ref)) { $ref = $ref['evidence_ref'] ?? $ref['ref'] ?? $ref['id'] ?? ''; }
            if (!is_scalar($ref)) { continue; }
            $ref = trim(sanitize_text_field((string) $ref));
            if (preg_match('/^ev_\d+_[a-f0-9]{6,20}$/', $ref)) { $out[] = $ref; }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    public static function get_item($id, $run_id = '') {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        if ($id <= 0) { return null; }
        $where = 'id = %d';
        $params = [$id];
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id !== '') { $where .= ' AND run_id = %s'; $params[] = $run_id; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE ' . $where . ' LIMIT 1', $params), ARRAY_A);
        return is_array($row) ? self::prepare_item_for_response($row, true) : null;
    }

    private static function prepare_item_for_response($row, $include_raw = false) {
        $row = is_array($row) ? $row : [];
        $metrics = json_decode((string) ($row['metrics_json'] ?? '{}'), true);
        $normalized = json_decode((string) ($row['normalized_json'] ?? '{}'), true);
        $flags = json_decode((string) ($row['quality_flags_json'] ?? '[]'), true);
        $out = [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'source_id' => (string) ($row['source_id'] ?? ''),
            'item_hash' => (string) ($row['item_hash'] ?? ''),
            'evidence_ref' => self::evidence_ref_for_row($row),
            'external_id' => (string) ($row['external_id'] ?? ''),
            'entity_name' => (string) ($row['entity_name'] ?? ''),
            'entity_type' => (string) ($row['entity_type'] ?? 'unknown'),
            'entity_role' => (string) ($row['entity_role'] ?? ''),
            'entity_key' => (string) ($row['entity_key'] ?? ''),
            'platform' => (string) ($row['platform'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_status' => (string) ($row['source_status'] ?? ''),
            'source_label' => (string) ($row['source_label'] ?? ''),
            'content_type' => (string) ($row['content_type'] ?? 'unknown'),
            'url' => (string) ($row['url'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'text' => self::truncate((string) ($row['text'] ?? ''), 1500),
            'author' => (string) ($row['author'] ?? ''),
            'published_at' => (string) ($row['published_at'] ?? ''),
            'collected_at' => (string) ($row['collected_at'] ?? ''),
            'media_url' => (string) ($row['media_url'] ?? ''),
            'metrics' => is_array($metrics) ? $metrics : [],
            'normalized' => is_array($normalized) ? $normalized : [],
            'quality_score' => (float) ($row['quality_score'] ?? 0),
            'metric_score' => (float) ($row['metric_score'] ?? 0),
            'quality_flags' => is_array($flags) ? array_values($flags) : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'raw_available' => !empty($row['raw_payload_json']),
        ];
        if ($include_raw) {
            $raw = json_decode((string) ($row['raw_payload_json'] ?? '{}'), true);
            $out['raw_payload'] = is_array($raw) ? $raw : [];
        }
        return $out;
    }

    private static function extract_items_from_brand_audit_pack($pack, $request, $sources) {
        $items = [];
        $brand = sanitize_text_field((string) ($request['brand_name'] ?? ''));
        $map = [
            ['owned_web', 'pages', 'website', 'webpage', 'owned_web', 'main_brand'],
            ['search_intent', 'google_search_items', 'google_search', 'search_result', 'search_intent', 'market'],
            ['public_web_news', 'news_items', 'google_news', 'news_result', 'public_web_news', 'market'],
            ['trend_interest', 'trend_items', 'google_trends', 'trend', 'trend_interest', 'market'],
            ['social_owned', 'posts', 'social', 'post', 'social_owned', 'unknown'],
            ['social_owned', 'top_posts', 'social', 'post', 'social_owned', 'unknown'],
            ['social_owned', 'items', 'social', 'post', 'social_owned', 'unknown'],
            ['social_owned', 'mentions', 'social', 'post', 'social_owned', 'market'],
            ['social_owned', 'earned_mentions', 'social', 'post', 'social_owned', 'market'],
            ['social_owned', 'owned_posts', 'social', 'post', 'social_owned', 'main_brand'],
            ['social_owned', 'earned_posts', 'social', 'post', 'social_owned', 'market'],
            ['social_owned', 'competitor_social_posts', 'social', 'post', 'social_owned', 'competitor'],
            ['reviews_maps', 'places', 'google_maps', 'place', 'reviews_maps', 'main_brand'],
            ['reviews_maps', 'review_items', 'google_maps', 'review', 'reviews_maps', 'main_brand'],
            ['competitor_context', 'competitor_search_items', 'google_search', 'competitor_signal', 'competitor_context', 'competitor'],
            ['competitor_context', 'signals', 'google_search', 'competitor_signal', 'competitor_context', 'competitor'],
        ];
        foreach ($map as $spec) {
            [$section, $key, $platform, $source_type, $source_id, $fallback_entity_type] = $spec;
            foreach (self::array_path_items($pack, [$section, $key]) as $raw) {
                if (!is_array($raw)) { continue; }
                $raw['_ves_platform'] = $platform;
                $raw['_ves_source_type'] = $source_type;
                $raw['_ves_source_id'] = $source_id;
                $raw['_ves_source_section'] = $section;
                $raw['_ves_source_item_key'] = $key;
                $raw['_ves_entity_type'] = self::infer_entity_type($raw, $fallback_entity_type, $brand, $request);
                $raw['_ves_entity_name'] = self::infer_entity_name($raw, $brand, $raw['_ves_entity_type'], $request);
                $items[] = $raw;
            }
        }
        return $items;
    }

    private static function source_meta_map($sources) {
        $map = [];
        foreach ((array) $sources as $key => $source) {
            if (!is_array($source)) { continue; }
            $candidates = array_filter(array_map('strval', [
                $key,
                $source['source_id'] ?? '',
                $source['source_key'] ?? '',
                $source['key'] ?? '',
                $source['module_key'] ?? '',
                $source['platform'] ?? '',
                $source['source_type'] ?? '',
            ]));
            $meta = [
                'status' => self::safe_source_status((string) ($source['status'] ?? $source['dispatch_status'] ?? $source['analytical_usability_status'] ?? 'unknown')),
                'label' => sanitize_text_field((string) ($source['label'] ?? $source['source_label'] ?? $key)),
                'platform' => sanitize_key((string) ($source['platform'] ?? '')),
            ];
            foreach ($candidates as $candidate) {
                $id = sanitize_key($candidate);
                if ($id !== '') { $map[$id] = $meta; }
            }
        }
        return $map;
    }

    private static function array_path_items($array, $path) {
        $cursor = is_array($array) ? $array : [];
        foreach ($path as $key) {
            if (!is_array($cursor) || !isset($cursor[$key])) { return []; }
            $cursor = $cursor[$key];
        }
        return is_array($cursor) ? array_values($cursor) : [];
    }

    private static function normalize_row($item, $workspace_id, $user_id, $project_id, $run_id, $request, $source_meta = []) {
        $platform = self::infer_platform($item, (string) ($item['_ves_platform'] ?? 'unknown'));
        $source_type = sanitize_key((string) ($item['_ves_source_type'] ?? $item['source_type'] ?? 'unknown'));
        $source_id = self::infer_source_id($item, (string) ($item['_ves_source_id'] ?? $source_type));
        $meta = self::find_source_meta($source_id, $item, $platform, $source_type, $source_meta);
        $title = self::first_text($item, ['title', 'caption', 'headline', 'name', 'query', 'text_short']);
        $text = self::first_text($item, ['text', 'description', 'caption', 'snippet', 'content', 'body', 'review_text']);
        $url = self::first_url($item, ['url', 'link', 'source_url', 'post_url', 'video_url', 'page_url']);
        $media_url = self::first_url($item, ['media_url', 'image_url', 'thumbnail_url', 'thumbnail', 'video_url']);
        $published_at = self::normalize_datetime($item['published_at'] ?? $item['publishedAt'] ?? $item['timestamp'] ?? $item['date'] ?? $item['created_time'] ?? $item['createdAt'] ?? null);
        $collected_at = self::normalize_datetime($item['collected_at'] ?? $item['scraped_at'] ?? $item['fetched_at'] ?? null);
        if ($collected_at === null) { $collected_at = current_time('mysql'); }
        $metrics = self::extract_metrics($item);
        $content_type = self::infer_content_type($item);
        $fallback_entity_type = self::safe_entity_type((string) ($item['_ves_entity_type'] ?? 'unknown'));
        $brand = sanitize_text_field((string) ($request['brand_name'] ?? ''));
        $entity_type = self::infer_entity_type($item, $fallback_entity_type, $brand, $request);
        $entity_name = sanitize_text_field((string) ($item['_ves_entity_name'] ?? ''));
        if ($entity_name === '') { $entity_name = self::infer_entity_name($item, $brand, $entity_type, $request); }
        $entity_role = self::normalize_entity_role($item['entity_role'] ?? $item['role'] ?? $entity_type);
        if ($entity_role === '' || $entity_role === 'unknown') { $entity_role = $entity_type; }
        if ($entity_type === 'competitor') { $entity_role = 'competitor'; }
        if ($entity_type === 'main_brand' && $entity_role === 'brand') { $entity_role = 'main_brand'; }
        $entity_key = sanitize_title((string) ($item['entity_key'] ?? $entity_name ?: $entity_type));
        $external_id = self::external_id($item);
        $metric_score = self::metric_score($metrics);
        $flags = self::quality_flags($url, $title, $text, $published_at, $metrics, $entity_type, $item);
        $quality = self::quality_score($url, $title, $text, $published_at, $metrics, $item, $flags);
        $source_status = self::safe_source_status((string) ($item['source_status'] ?? $item['status'] ?? ($meta['status'] ?? 'unknown')));
        $source_label = sanitize_text_field((string) ($item['source_label'] ?? $item['label'] ?? ($meta['label'] ?? $source_id)));
        $item_hash = self::item_hash($platform, $entity_name, $url, $title, $published_at, $external_id, $source_type);
        $normalized = [
            'content_type' => $content_type,
            'detected_themes' => self::string_list($item['detected_themes'] ?? $item['themes'] ?? []),
            'detected_formats' => self::string_list($item['detected_formats'] ?? $item['formats'] ?? []),
            'detected_hooks' => self::string_list($item['detected_hooks'] ?? $item['hooks'] ?? []),
            'language' => sanitize_key((string) ($item['language'] ?? $request['language'] ?? '')),
            'source_variant' => sanitize_key((string) ($item['source_variant'] ?? '')),
            'ownership' => sanitize_key((string) ($item['ownership'] ?? '')),
            'entity_role' => $entity_role,
            'entity_key' => $entity_key,
            'source_section' => sanitize_key((string) ($item['_ves_source_section'] ?? '')),
            'source_item_key' => sanitize_key((string) ($item['_ves_source_item_key'] ?? '')),
        ];
        return [
            'workspace_id' => max(0, (int) $workspace_id),
            'user_id' => max(0, (int) $user_id),
            'project_id' => max(0, (int) $project_id),
            'run_id' => $run_id,
            'source_id' => sanitize_text_field($source_id),
            'item_hash' => $item_hash,
            'evidence_ref' => '',
            'external_id' => $external_id,
            'entity_name' => $entity_name,
            'entity_type' => $entity_type,
            'entity_role' => $entity_role,
            'entity_key' => $entity_key,
            'platform' => $platform !== '' ? $platform : 'unknown',
            'source_type' => $source_type !== '' ? $source_type : 'unknown',
            'source_status' => $source_status,
            'source_label' => $source_label,
            'content_type' => $content_type,
            'url' => $url,
            'title' => self::truncate($title, 800),
            'text' => self::truncate($text, 6000),
            'author' => sanitize_text_field((string) ($item['author'] ?? $item['username'] ?? $item['channel'] ?? $item['account'] ?? $item['source'] ?? '')),
            'published_at' => $published_at,
            'collected_at' => $collected_at,
            'media_url' => $media_url,
            'metrics_json' => wp_json_encode($metrics, JSON_UNESCAPED_UNICODE),
            'normalized_json' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'raw_payload_json' => wp_json_encode(self::trim_raw_payload($item), JSON_UNESCAPED_UNICODE),
            'quality_score' => $quality,
            'metric_score' => $metric_score,
            'quality_flags_json' => wp_json_encode($flags, JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql'),
        ];
    }

    private static function existing_by_hash($run_id, $item_hash) {
        global $wpdb;
        $run_id = sanitize_text_field((string) $run_id);
        $item_hash = sanitize_text_field((string) $item_hash);
        if ($run_id === '' || $item_hash === '') { return null; }
        // v0.9.24.8: fetch only the columns needed by update_existing_if_richer.
        // raw_payload_json can be up to 12KB; avoiding it here saves memory
        // when ingesting hundreds of evidence rows per brand audit run.
        $cols = 'id, workspace_id, user_id, project_id, run_id, source_id, item_hash, evidence_ref, external_id, entity_name, entity_type, entity_role, entity_key, platform, source_type, source_status, source_label, content_type, url, title, author, published_at, collected_at, media_url, metrics_json, normalized_json, quality_score, metric_score, quality_flags_json, created_at, CASE WHEN raw_payload_json IS NULL OR raw_payload_json = "" THEN "" ELSE "1" END AS raw_payload_json';
        $row = $wpdb->get_row($wpdb->prepare("SELECT {$cols} FROM " . self::table_name() . ' WHERE run_id = %s AND item_hash = %s ORDER BY id ASC LIMIT 1', $run_id, $item_hash), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function update_existing_if_richer($existing, $row) {
        global $wpdb;
        $existing = is_array($existing) ? $existing : [];
        $row = is_array($row) ? $row : [];
        $id = (int) ($existing['id'] ?? 0);
        if ($id <= 0) { return false; }
        $old_score = self::row_completeness_score($existing);
        $new_score = self::row_completeness_score($row);
        if ($new_score <= $old_score) {
            if (empty($existing['evidence_ref'])) {
                $wpdb->update(self::table_name(), ['evidence_ref' => self::build_evidence_ref($id, (string) ($existing['item_hash'] ?? $row['item_hash'] ?? ''))], ['id' => $id], ['%s'], ['%d']);
            }
            return false;
        }
        $updates = [];
        foreach (['external_id','entity_name','entity_type','entity_role','entity_key','platform','source_type','source_status','source_label','content_type','url','title','text','author','published_at','collected_at','media_url','metrics_json','normalized_json','quality_score','metric_score','quality_flags_json'] as $key) {
            if (array_key_exists($key, $row)) { $updates[$key] = $row[$key]; }
        }
        if (empty($existing['raw_payload_json']) && !empty($row['raw_payload_json'])) {
            $updates['raw_payload_json'] = $row['raw_payload_json'];
        }
        if (empty($existing['evidence_ref'])) {
            $updates['evidence_ref'] = self::build_evidence_ref($id, (string) ($row['item_hash'] ?? ''));
        }
        if (empty($updates)) { return false; }
        $formats = [];
        foreach (array_keys($updates) as $key) {
            $formats[] = in_array($key, ['quality_score','metric_score'], true) ? '%f' : '%s';
        }
        return false !== $wpdb->update(self::table_name(), $updates, ['id' => $id], $formats, ['%d']);
    }

    private static function row_completeness_score($row) {
        $row = is_array($row) ? $row : [];
        $score = (float) ($row['quality_score'] ?? 0);
        foreach (['external_id','url','title','text','author','published_at','media_url','metrics_json','normalized_json','raw_payload_json'] as $key) {
            if (!empty($row[$key])) { $score += in_array($key, ['text','raw_payload_json'], true) ? 3 : 2; }
        }
        if (($row['entity_type'] ?? 'unknown') !== 'unknown') { $score += 5; }
        if (!empty($row['metric_score'])) { $score += min(10, (float) $row['metric_score'] / 100); }
        return $score;
    }

    private static function build_evidence_ref($id, $hash) {
        $id = max(0, (int) $id);
        $hash = preg_replace('/[^a-f0-9]/', '', strtolower((string) $hash));
        $short = $hash !== '' ? substr($hash, 0, 10) : substr(hash('sha256', (string) $id), 0, 10);
        return 'ev_' . $id . '_' . $short;
    }

    private static function evidence_ref_for_row($row) {
        $ref = sanitize_text_field((string) ($row['evidence_ref'] ?? ''));
        if ($ref !== '' && preg_match('/^ev_\d+_[a-f0-9]{6,20}$/', $ref)) { return $ref; }
        return self::build_evidence_ref((int) ($row['id'] ?? 0), (string) ($row['item_hash'] ?? ''));
    }

    private static function row_has_evidence($row) {
        return trim((string) ($row['url'] ?? '')) !== ''
            || trim((string) ($row['title'] ?? '')) !== ''
            || trim((string) ($row['text'] ?? '')) !== ''
            || trim((string) ($row['external_id'] ?? '')) !== '';
    }

    private static function infer_platform($item, $fallback) {
        $platform = sanitize_key((string) ($item['platform'] ?? $item['source_platform'] ?? $fallback));
        if ($platform === 'x') { return 'twitter'; }
        if ($platform === 'google') { return sanitize_key((string) $fallback); }
        return $platform !== '' ? $platform : sanitize_key((string) $fallback);
    }

    private static function infer_source_id($item, $fallback) {
        foreach (['source_id', 'source_key', 'source', 'provider', 'actor'] as $key) {
            if (!empty($item[$key]) && is_scalar($item[$key])) { return sanitize_text_field((string) $item[$key]); }
        }
        return sanitize_text_field((string) $fallback);
    }

    private static function find_source_meta($source_id, $item, $platform, $source_type, $source_meta) {
        foreach ([$source_id, $item['_ves_source_section'] ?? '', $item['source_key'] ?? '', $platform, $source_type] as $candidate) {
            $key = sanitize_key((string) $candidate);
            if ($key !== '' && is_array($source_meta[$key] ?? null)) { return $source_meta[$key]; }
        }
        return [];
    }

    private static function infer_entity_type($item, $fallback, $brand, $request) {
        $role = self::normalize_entity_role($item['entity_role'] ?? $item['role'] ?? '');
        $ownership = sanitize_key((string) ($item['ownership'] ?? $item['owner_role'] ?? $item['source_role'] ?? ''));
        if ($role === 'competitor' || $ownership === 'competitor') { return 'competitor'; }
        if (in_array($role, ['brand', 'main_brand'], true)) { return 'main_brand'; }
        if (!empty($item['competitor_name']) || !empty($item['competitor'])) { return 'competitor'; }
        if (!empty($item['entity_type'])) {
            $explicit = self::safe_entity_type((string) $item['entity_type']);
            if ($explicit !== 'unknown') { return $explicit; }
        }
        $entity = strtolower(trim((string) ($item['entity_name'] ?? $item['brand'] ?? $item['account'] ?? $item['author'] ?? '')));
        $brand_lower = strtolower(trim((string) $brand));
        if ($brand_lower !== '' && $entity !== '' && self::names_match($entity, $brand_lower)) { return 'main_brand'; }
        foreach (self::competitor_names($request) as $competitor) {
            if ($entity !== '' && self::names_match($entity, strtolower($competitor))) { return 'competitor'; }
        }
        return self::safe_entity_type((string) $fallback);
    }

    private static function infer_entity_name($item, $brand, $entity_type, $request) {
        if ($entity_type === 'competitor') {
            foreach (['competitor_name', 'competitor', 'entity_name', 'account', 'author', 'source'] as $key) {
                if (!empty($item[$key]) && is_scalar($item[$key])) { return sanitize_text_field((string) $item[$key]); }
            }
            return 'Competitor';
        }
        if ($entity_type === 'main_brand' && $brand !== '') { return $brand; }
        foreach (['entity_name', 'brand', 'account', 'author', 'source'] as $key) {
            if (!empty($item[$key]) && is_scalar($item[$key])) { return sanitize_text_field((string) $item[$key]); }
        }
        if ($entity_type === 'market') { return sanitize_text_field((string) ($request['country'] ?? 'Market')); }
        return '';
    }

    private static function competitor_names($request) {
        $request = is_array($request) ? $request : [];
        $competitors = $request['competitors'] ?? [];
        if (is_string($competitors)) { $competitors = preg_split('/[\n,]+/', $competitors); }
        if (!is_array($competitors)) { $competitors = []; }
        $out = [];
        foreach ($competitors as $item) {
            if (is_array($item)) { $item = $item['name'] ?? $item['brand_name'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $name = trim(sanitize_text_field((string) $item));
            if ($name !== '') { $out[] = $name; }
        }
        return array_values(array_unique($out));
    }

    private static function names_match($a, $b) {
        $a = trim(strtolower((string) $a));
        $b = trim(strtolower((string) $b));
        if ($a === '' || $b === '') { return false; }
        if ($a === $b) { return true; }
        return strpos($a, $b) !== false || strpos($b, $a) !== false;
    }

    private static function normalize_entity_role($value) {
        $value = sanitize_key((string) $value);
        if (in_array($value, ['brand', 'owned', 'main', 'primary', 'mainbrand'], true)) { return 'main_brand'; }
        if (in_array($value, ['competitor', 'rival', 'competitor_brand', 'competitor_owned', 'competitor_earned', 'competitor_social'], true)) { return 'competitor'; }
        if (in_array($value, ['earned', 'market_signal', 'market'], true)) { return 'market'; }
        if (in_array($value, ['main_brand', 'unknown'], true)) { return $value; }
        return $value !== '' ? $value : 'unknown';
    }

    private static function safe_entity_type($value) {
        $value = sanitize_key($value);
        if ($value === 'brand' || $value === 'owned') { $value = 'main_brand'; }
        if ($value === 'earned') { $value = 'market'; }
        $allowed = ['main_brand', 'competitor', 'market', 'unknown'];
        return in_array($value, $allowed, true) ? $value : 'unknown';
    }

    private static function safe_source_status($value) {
        $value = strtolower(sanitize_key((string) $value));
        $map = ['succeeded' => 'succeeded', 'success' => 'succeeded', 'completed' => 'succeeded', 'usable' => 'succeeded', 'partial' => 'partial', 'limited' => 'partial', 'failed' => 'failed', 'error' => 'failed', 'timeout' => 'timeout', 'timed-out' => 'timeout', 'queued' => 'queued', 'running' => 'running', 'pending' => 'pending', 'aborted' => 'aborted'];
        return $map[$value] ?? ($value !== '' ? $value : 'unknown');
    }

    private static function infer_content_type($item) {
        $type = sanitize_key((string) ($item['content_type'] ?? $item['type'] ?? ''));
        $allowed = ['post', 'reel', 'video', 'search_result', 'webpage', 'ad', 'trend', 'review', 'place', 'competitor_signal', 'unknown'];
        if (in_array($type, $allowed, true)) { return $type; }
        $source_type = sanitize_key((string) ($item['_ves_source_type'] ?? ''));
        if ($source_type === 'webpage') { return 'webpage'; }
        if (strpos($source_type, 'search') !== false || strpos($source_type, 'news') !== false) { return 'search_result'; }
        if ($source_type === 'trend') { return 'trend'; }
        if ($source_type === 'review') { return 'review'; }
        if ($source_type === 'place') { return 'place'; }
        if ($source_type === 'competitor_signal') { return 'competitor_signal'; }
        if (!empty($item['video_url']) || !empty($item['views']) || !empty($item['metrics']['views'])) { return 'video'; }
        return 'unknown';
    }

    private static function external_id($item) {
        foreach (['external_id', 'id', 'post_id', 'postId', 'video_id', 'videoId', 'tweet_id', 'tweetId', 'shortCode', 'code', 'pk', 'ad_archive_id'] as $key) {
            if (!empty($item[$key]) && is_scalar($item[$key])) { return self::truncate(sanitize_text_field((string) $item[$key]), 180); }
        }
        return '';
    }

    private static function item_hash($platform, $entity_name, $url, $title, $published_at, $external_id, $source_type) {
        $base = trim(implode('|', array_map('strval', [$platform, $entity_name, $external_id, $url, $title, $published_at, $source_type])));
        if ($base === '||||||') { $base = wp_generate_uuid4(); }
        return hash('sha256', strtolower($base));
    }

    private static function extract_metrics($item) {
        $metrics = [];
        if (isset($item['metrics']) && is_array($item['metrics'])) { $metrics = $item['metrics']; }
        foreach (['views', 'likes', 'comments', 'shares', 'saves', 'impressions', 'followers', 'rating', 'review_count', 'engagement_total'] as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== '' && $item[$key] !== null) {
                $metrics[$key] = is_numeric($item[$key]) ? 0 + $item[$key] : sanitize_text_field((string) $item[$key]);
            }
        }
        $clean = [];
        foreach ($metrics as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') { continue; }
            $clean[$key] = is_numeric($value) ? 0 + $value : sanitize_text_field((string) $value);
        }
        return $clean;
    }

    private static function metric_score($metrics) {
        $metrics = is_array($metrics) ? $metrics : [];
        $views = (float) ($metrics['views'] ?? 0);
        $likes = (float) ($metrics['likes'] ?? 0);
        $comments = (float) ($metrics['comments'] ?? 0);
        $shares = (float) ($metrics['shares'] ?? 0);
        $saves = (float) ($metrics['saves'] ?? 0);
        $engagement = (float) ($metrics['engagement_total'] ?? ($likes + $comments + $shares + $saves));
        return round($engagement + ($views * 0.1), 2);
    }

    private static function quality_flags($url, $title, $text, $published_at, $metrics, $entity_type, $raw) {
        $flags = [];
        if ($url === '') { $flags[] = 'missing_url'; }
        if ($title === '' && $text === '') { $flags[] = 'missing_text'; }
        if ($published_at === null) { $flags[] = 'missing_date'; }
        if (empty($metrics)) { $flags[] = 'missing_metrics'; }
        if ($entity_type === 'unknown') { $flags[] = 'weak_entity_attribution'; }
        if (in_array(strtolower((string) ($raw['source_status'] ?? '')), ['failed', 'timeout', 'partial'], true)) { $flags[] = 'source_not_fully_successful'; }
        return array_values(array_unique($flags));
    }

    private static function quality_score($url, $title, $text, $published_at, $metrics, $raw, $flags = []) {
        $score = 20;
        if ($url !== '') { $score += 20; }
        if ($title !== '') { $score += 15; }
        if ($text !== '') { $score += 20; }
        if ($published_at !== null) { $score += 10; }
        if (!empty($metrics)) { $score += 10; }
        if (!in_array('weak_entity_attribution', (array) $flags, true)) { $score += 5; }
        return min(100, max(0, (float) $score));
    }

    private static function coverage_warnings($summary) {
        $warnings = [];
        $total = (int) ($summary['total'] ?? 0);
        if ($total <= 0) { $warnings[] = 'No normalized evidence rows were stored for this run.'; }
        $competitors = (int) ($summary['by_entity_type']['competitor'] ?? 0);
        if ($competitors <= 0) { $warnings[] = 'No competitor-attributed evidence was stored; competitor comparisons should be treated as blind spots.'; }
        $social = 0;
        foreach (['instagram', 'tiktok', 'youtube', 'twitter', 'facebook', 'linkedin', 'reddit', 'social'] as $platform) {
            $social += (int) ($summary['by_platform'][$platform] ?? 0);
        }
        if ($social > 0 && $social < 20) { $warnings[] = 'Social sample is below the minimum 20-item directional threshold.'; }
        if ((float) ($summary['average_quality'] ?? 0) > 0 && (float) $summary['average_quality'] < 55) { $warnings[] = 'Average evidence quality is low; results need conservative confidence.'; }
        return $warnings;
    }

    private static function first_text($item, $keys) {
        foreach ($keys as $key) {
            if (!isset($item[$key])) { continue; }
            $value = $item[$key];
            if (is_scalar($value)) {
                $value = trim(wp_strip_all_tags((string) $value));
                if ($value !== '') { return self::truncate($value, 4000); }
            }
        }
        return '';
    }

    private static function first_url($item, $keys) {
        foreach ($keys as $key) {
            if (empty($item[$key]) || !is_scalar($item[$key])) { continue; }
            $url = esc_url_raw((string) $item[$key]);
            if ($url !== '') { return $url; }
        }
        return '';
    }

    private static function normalize_datetime($value) {
        if ($value === null || $value === '') { return null; }
        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts > 20000000000) { $ts = (int) round($ts / 1000); }
            return gmdate('Y-m-d H:i:s', $ts);
        }
        // v0.9.24.8: strtotime() returns false on failure, not null/0.
        // Using !$ts would wrongly discard a valid epoch-0 timestamp.
        $ts = strtotime((string) $value);
        if ($ts === false) { return null; }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function string_list($value) {
        if (is_string($value)) { $value = preg_split('/[,\n]+/', $value); }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim(sanitize_text_field((string) $item));
                if ($s !== '') { $out[] = $s; }
            }
        }
        return array_values(array_unique(array_slice($out, 0, 20)));
    }

    private static function trim_raw_payload($item) {
        unset($item['_ves_platform'], $item['_ves_source_type'], $item['_ves_source_id'], $item['_ves_entity_type'], $item['_ves_entity_name'], $item['_ves_source_section'], $item['_ves_source_item_key']);
        $max_bytes = (int) apply_filters('ves_evidence_raw_payload_max_bytes', 12000);
        $max_bytes = max(2000, min(60000, $max_bytes));
        $json = wp_json_encode($item, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > $max_bytes) { return ['truncated' => true, 'preview' => substr($json, 0, $max_bytes)]; }
        return $item;
    }

    /**
     * Keep final payloads useful for the UI without duplicating large raw evidence.
     * Raw evidence remains available through wp_ves_evidence_items + ves_evidence_get.
     */
    public static function compact_evidence_pack_for_final_payload($pack) {
        $pack = is_array($pack) ? $pack : [];
        $keep = [];
        foreach (['_normalized_evidence_summary', '_metric_snapshots', '_pattern_candidates', '_allowed_evidence_refs', 'source_quality'] as $key) {
            if (array_key_exists($key, $pack)) { $keep[$key] = self::compact_for_storage($pack[$key], 0); }
        }
        foreach (['owned_web', 'search_intent', 'public_web_news', 'trend_interest', 'social_owned', 'reviews_maps', 'competitor_context'] as $section) {
            if (!isset($pack[$section]) || !is_array($pack[$section])) { continue; }
            $keep[$section] = self::compact_for_storage($pack[$section], 0, 18);
        }
        $keep['_compacted'] = true;
        $keep['_compaction_note'] = 'Large raw source payloads are stored in the evidence table and are loaded on demand only.';
        return $keep;
    }

    public static function compact_final_payload_for_store($payload) {
        $payload = is_array($payload) ? $payload : [];
        if (isset($payload['evidence_pack']) && is_array($payload['evidence_pack'])) {
            $payload['evidence_pack'] = self::compact_evidence_pack_for_final_payload($payload['evidence_pack']);
        }
        foreach (['raw_items', 'raw_payload', 'raw_payloads', 'apify_raw', 'debug_raw', 'prompt', 'system_prompt', 'messages'] as $raw_key) {
            if (isset($payload[$raw_key])) { unset($payload[$raw_key]); }
        }
        return self::compact_for_storage($payload, 0, 40);
    }

    private static function compact_for_storage($value, $depth = 0, $max_items = 40) {
        if ($depth > 5) { return '[truncated_depth]'; }
        if (is_scalar($value) || $value === null) {
            if (is_string($value)) { return self::truncate($value, $depth <= 2 ? 2500 : 900); }
            return $value;
        }
        if (!is_array($value)) { return null; }
        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            $clean_key = is_int($key) ? $key : sanitize_key((string) $key);
            if (self::is_raw_storage_key($clean_key)) { continue; }
            if ($i >= $max_items) { $out['_truncated_count'] = max(0, count($value) - $i); break; }
            $out[$clean_key] = self::compact_for_storage($item, $depth + 1, max(10, (int) floor($max_items * 0.75)));
            $i++;
        }
        return $out;
    }

    private static function is_raw_storage_key($key) {
        $key = strtolower((string) $key);
        if ($key === '') { return false; }
        foreach (['raw', 'payload', 'debug', 'html', 'markdown', 'prompt', 'body', 'screenshot', 'image_base64'] as $needle) {
            if (strpos($key, $needle) !== false) { return true; }
        }
        return false;
    }

    private static function truncate($text, $max) {
        $text = (string) $text;
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) { return mb_substr($text, 0, $max); }
        if (strlen($text) > $max) { return substr($text, 0, $max); }
        return $text;
    }
}
