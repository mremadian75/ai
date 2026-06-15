<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Queryable opportunity records derived from final AI output.
 * Phase 2 foundation: makes recommendations actionable beyond a report blob.
 */
final class VES_Opportunity_Records {
    const DB_VERSION = '1.2.0';
    const OPTION_KEY = 'ves_opportunity_records_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_opportunity_records';
    }



    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id', 'workspace_id', 'user_id', 'run_id', 'opportunity_key', 'title', 'description', 'opportunity_type', 'evidence_refs_json', 'insight_ids_json', 'impact_score', 'effort_score', 'confidence', 'priority_score', 'recommended_action', 'campaign_angle', 'content_brief_seed', 'status', 'created_at', 'updated_at', 'meta_json'];
    }



    public static function required_indexes() {
        return ['workspace_id','user_id','run_id','opportunity_type','status','priority_score'];
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
            opportunity_key varchar(64) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            description longtext NULL,
            opportunity_type varchar(40) NOT NULL DEFAULT 'content',
            evidence_refs_json longtext NULL,
            insight_ids_json longtext NULL,
            impact_score decimal(5,2) NOT NULL DEFAULT 0.00,
            effort_score decimal(5,2) NOT NULL DEFAULT 0.00,
            confidence decimal(5,2) NOT NULL DEFAULT 0.00,
            priority_score decimal(6,2) NOT NULL DEFAULT 0.00,
            recommended_action longtext NULL,
            campaign_angle longtext NULL,
            content_brief_seed longtext NULL,
            status varchar(32) NOT NULL DEFAULT 'candidate',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            meta_json longtext NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY run_id (run_id),
            KEY opportunity_key (opportunity_key),
            KEY opportunity_type (opportunity_type),
            KEY status (status),
            KEY priority_score (priority_score),
            KEY created_at (created_at)
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

    public static function create_from_final_ai_output($run_id, $workspace_id, $user_id, $request = [], $structured = [], $insight_records = []) {
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['stored' => 0, 'records' => []]; }
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $request = is_array($request) ? $request : [];
        $structured = is_array($structured) ? $structured : [];
        $items = [];
        if (!empty($structured['opportunities']) && is_array($structured['opportunities'])) {
            $items = $structured['opportunities'];
        } elseif (!empty($structured['opportunity_territories']) && is_array($structured['opportunity_territories'])) {
            $items = $structured['opportunity_territories'];
        }
        self::delete_for_run($run_id);
        $stored = 0;
        foreach ($items as $idx => $item) {
            if (!is_array($item)) { continue; }
            if (self::insert_record($run_id, $workspace_id, $user_id, $request, $item, $idx, $insight_records)) { $stored++; }
        }
        return ['stored' => $stored, 'records' => self::list_by_run($run_id, 60)];
    }

    private static function insert_record($run_id, $workspace_id, $user_id, $request, $item, $idx, $insight_records) {
        global $wpdb;
        $now = current_time('mysql');
        $title = sanitize_text_field((string) ($item['title'] ?? $item['name'] ?? ('Opportunity ' . ((int) $idx + 1))));
        $description = (string) ($item['description'] ?? $item['summary'] ?? $item['why_it_matters'] ?? '');
        $type = self::safe_type($item['opportunity_type'] ?? $item['type'] ?? $item['suggested_workflow'] ?? 'content');
        $refs_requested = self::evidence_refs($item);
        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'filter_valid_evidence_refs')) {
            $refs = VES_Evidence_Store::filter_valid_evidence_refs($run_id, $refs_requested);
            $invalid = array_values(array_diff($refs_requested, $refs));
        } else {
            $refs = $refs_requested;
            $invalid = [];
        }
        $evidence_validation_status = empty($refs) ? 'unsupported' : (count($refs) < 2 ? 'weak' : 'supported');
        $insight_ids = self::insight_ids($item, $insight_records);
        $impact = self::score($item['impact_score'] ?? $item['impact'] ?? $item['business_value'] ?? $item['opportunity_score'] ?? 'medium');
        $effort = self::score($item['effort_score'] ?? $item['effort'] ?? 'medium');
        $confidence = self::confidence($item['confidence'] ?? 0.45);
        if ($evidence_validation_status === 'unsupported') { $confidence = min($confidence, 0.35); }
        elseif ($evidence_validation_status === 'weak') { $confidence = min($confidence, 0.65); }
        $priority = self::priority($impact, $effort, $confidence);
        $opportunity_key = hash('sha256', implode('|', [$run_id, $type, strtolower($title), implode(',', $refs)]));
        $meta = [
            'recommended_next_steps' => $item['recommended_next_steps'] ?? [],
            'suggested_workflow' => $item['suggested_workflow'] ?? '',
            'starter_prompt_for_assistant' => $item['starter_prompt_for_assistant'] ?? '',
            'why_it_matters' => $item['why_it_matters'] ?? '',
            'raw_compact' => self::compact($item),
            'brand_name' => $request['brand_name'] ?? '',
            'unsupported_by_evidence' => $evidence_validation_status === 'unsupported',
            'invalid_evidence_refs' => $invalid,
            'evidence_ref_count' => count($refs),
            'evidence_validation_status' => $evidence_validation_status,
        ];
        return false !== $wpdb->insert(self::table_name(), [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'run_id' => $run_id,
            'opportunity_key' => $opportunity_key,
            'title' => $title,
            'description' => sanitize_textarea_field($description),
            'opportunity_type' => $type,
            'evidence_refs_json' => wp_json_encode($refs, JSON_UNESCAPED_UNICODE),
            'insight_ids_json' => wp_json_encode($insight_ids, JSON_UNESCAPED_UNICODE),
            'impact_score' => $impact,
            'effort_score' => $effort,
            'confidence' => $confidence,
            'priority_score' => $priority,
            'recommended_action' => sanitize_textarea_field((string) ($item['recommended_action'] ?? $item['suggested_next_step'] ?? '')),
            'campaign_angle' => sanitize_textarea_field((string) ($item['campaign_angle'] ?? $item['content_or_campaign_angle'] ?? '')),
            'content_brief_seed' => sanitize_textarea_field((string) ($item['content_brief_seed'] ?? '')),
            'status' => self::status($item['status'] ?? 'candidate'),
            'created_at' => $now,
            'updated_at' => $now,
            'meta_json' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%s']);
    }

    public static function list_by_run($run_id, $limit = 60) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $limit = max(1, min(200, (int) $limit));
        if ($run_id === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY priority_score DESC, id ASC LIMIT %d', $run_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_workspace($workspace_id, $limit = 80) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $limit = max(1, min(200, (int) $limit));
        if ($workspace_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d ORDER BY priority_score DESC, updated_at DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_run_paginated($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $filters = is_array($filters) ? $filters : [];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($filters['per_page'] ?? 30)));
        if ($run_id === '') { return ['items' => [], 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0]; }
        $where = ['run_id = %s'];
        $params = [$run_id];
        foreach (['opportunity_type' => 'opportunity_type', 'status' => 'status'] as $key => $column) {
            $value = sanitize_key((string) ($filters[$key] ?? ''));
            if ($value !== '') { $where[] = $column . ' = %s'; $params[] = $value; }
        }
        $where_sql = implode(' AND ', $where);
        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where_sql, $params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE ' . $where_sql . ' ORDER BY priority_score DESC, id ASC LIMIT %d OFFSET %d', array_merge($params, [$per_page, $offset])), ARRAY_A);
        return ['items' => array_map([__CLASS__, 'prepare'], (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0];
    }

    public static function update_status($id, $run_id, $status, $user_id = 0) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        $run_id = sanitize_text_field((string) $run_id);
        $status = self::status($status);
        if ($id <= 0 || $run_id === '') { return new WP_Error('invalid_record', 'Invalid opportunity record.'); }
        $record = self::get($id);
        if (!$record || (string) ($record['run_id'] ?? '') !== $run_id) { return new WP_Error('not_found', 'Opportunity record not found for this run.'); }
        if (!self::user_can_access_record($record, $user_id ?: null)) { return new WP_Error('forbidden', 'You cannot update this opportunity.'); }
        $updated = $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id, 'run_id' => $run_id], ['%s','%s'], ['%d','%s']);
        return false === $updated ? new WP_Error('db_error', 'Unable to update opportunity status.') : self::get($id);
    }

    public static function count_by_run($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return 0; }
        $filters = is_array($filters) ? $filters : [];
        $where = ['run_id = %s'];
        $params = [$run_id];
        foreach (['opportunity_type' => 'opportunity_type', 'status' => 'status'] as $key => $column) {
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
        return array_map(static function($row) {
            return [
                'opportunity_ref' => 'opp_' . (int) $row['id'],
                'title' => $row['title'],
                'type' => $row['opportunity_type'],
                'description' => $row['description'],
                'evidence_refs' => $row['evidence_refs'],
                'insight_ids' => $row['insight_ids'],
                'impact_score' => $row['impact_score'],
                'effort_score' => $row['effort_score'],
                'priority_score' => $row['priority_score'],
                'confidence' => $row['confidence'],
                'evidence_validation_status' => $row['evidence_validation_status'],
                'unsupported_by_evidence' => $row['unsupported_by_evidence'],
                'recommended_action' => $row['recommended_action'],
                'campaign_angle' => $row['campaign_angle'],
            ];
        }, $rows);
    }

    public static function user_can_access_record($record, $user_id = null) {
        $user_id = $user_id === null ? get_current_user_id() : (int) $user_id;
        if (current_user_can('manage_options')) { return true; }
        if (!is_array($record) || $user_id <= 0) { return false; }
        return (int) ($record['user_id'] ?? 0) === $user_id || (int) ($record['workspace_id'] ?? 0) === $user_id;
    }

    private static function prepare($row) {
        $refs = json_decode((string) ($row['evidence_refs_json'] ?? '[]'), true);
        $insights = json_decode((string) ($row['insight_ids_json'] ?? '[]'), true);
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        $prepared = [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'opportunity_key' => (string) ($row['opportunity_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'opportunity_type' => (string) ($row['opportunity_type'] ?? ''),
            'evidence_refs' => is_array($refs) ? array_values($refs) : [],
            'insight_ids' => is_array($insights) ? array_values($insights) : [],
            'impact_score' => (float) ($row['impact_score'] ?? 0),
            'effort_score' => (float) ($row['effort_score'] ?? 0),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'priority_score' => (float) ($row['priority_score'] ?? 0),
            'recommended_action' => (string) ($row['recommended_action'] ?? ''),
            'campaign_angle' => (string) ($row['campaign_angle'] ?? ''),
            'content_brief_seed' => (string) ($row['content_brief_seed'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'meta' => is_array($meta) ? $meta : [],
            'unsupported_by_evidence' => !empty($meta['unsupported_by_evidence']),
            'invalid_evidence_refs' => is_array($meta['invalid_evidence_refs'] ?? null) ? array_values($meta['invalid_evidence_refs']) : [],
            'evidence_ref_count' => (int) ($meta['evidence_ref_count'] ?? count(is_array($refs) ? $refs : [])),
            'evidence_validation_status' => sanitize_key((string) ($meta['evidence_validation_status'] ?? (empty($refs) ? 'unsupported' : 'supported'))),
        ];

        // Phase 25: additive, read-only canonical confidence annotation (VES_Confidence).
        // Derived purely from the fields already present in $prepared — no DB write, no change
        // to any existing key/value, and not rendered by the current UI (the frontend reads
        // named fields only). Guarded so the absence of the mapper never fatals. Mirrors the
        // Phase 23 insight-records consumer; opportunities are Brand-Audit-derived with the same
        // 0–1 confidence + evidence-ref validation semantics, so from_brand_audit() is correct.
        if (class_exists('VES_Confidence')) {
            $base = VES_Confidence::from_brand_audit($prepared);
            $prepared['confidence_canonical'] = VES_Confidence::from_evidence_validation($prepared, $base);
        }

        return $prepared;
    }

    private static function evidence_refs($item) {
        return self::string_list($item['evidence_refs'] ?? $item['evidence_ids'] ?? $item['evidence'] ?? $item['evidence_used'] ?? [], 80);
    }

    private static function insight_ids($item, $insight_records) {
        $explicit = self::string_list($item['linked_insight_ids'] ?? $item['insight_ids'] ?? [], 30);
        if (!empty($explicit)) { return $explicit; }
        $out = [];
        foreach ((array) $insight_records as $record) {
            if (!is_array($record)) { continue; }
            $out[] = 'ins_' . (int) ($record['id'] ?? 0);
            if (count($out) >= 3) { break; }
        }
        return array_values(array_filter($out));
    }

    private static function string_list($items, $limit = 50) {
        if (is_string($items)) { $items = preg_split('/[\n,]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['evidence_ref'] ?? $item['ref'] ?? $item['id'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $item = trim(sanitize_text_field((string) $item));
            if ($item !== '') { $out[] = $item; }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    private static function safe_type($type) {
        $type = sanitize_key((string) $type);
        $map = [
            'generate_brief' => 'content', 'content_brief' => 'content', 'create_content_calendar' => 'content',
            'campaign_strategy' => 'campaign', 'build_campaign' => 'campaign', 'creative_test' => 'creative',
            'seo_plan' => 'seo', 'assistant_discussion' => 'audience_gap', 'deck_export' => 'campaign',
        ];
        if (isset($map[$type])) { return $map[$type]; }
        $allowed = ['content','campaign','creative','seo','competitor_gap','audience_gap','product_message','channel','trend_response'];
        return in_array($type, $allowed, true) ? $type : 'content';
    }

    private static function status($status) {
        $status = sanitize_key((string) $status);
        $allowed = ['candidate','shortlisted','approved','rejected','converted_to_brief','archived'];
        return in_array($status, $allowed, true) ? $status : 'candidate';
    }

    private static function score($value) {
        if (is_string($value) && !is_numeric($value)) {
            $value = ['low' => 30, 'medium' => 60, 'high' => 85][$value] ?? 50;
        }
        $value = (float) $value;
        if ($value <= 1) { $value *= 100; }
        return max(0, min(100, round($value, 2)));
    }

    private static function confidence($value) {
        if (is_string($value) && !is_numeric($value)) {
            $value = ['low' => 0.35, 'medium' => 0.6, 'high' => 0.85][$value] ?? 0.45;
        }
        return max(0, min(1, (float) $value));
    }

    private static function priority($impact, $effort, $confidence) {
        return max(0, min(100, round(((float) $impact * 0.65) + ((100 - (float) $effort) * 0.2) + ((float) $confidence * 100 * 0.15), 2)));
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
