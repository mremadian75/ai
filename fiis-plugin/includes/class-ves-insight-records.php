<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Queryable insight records derived from final AI output.
 * Phase 2/3 foundation: persists interpreted insights so they are not trapped in final report JSON.
 */
final class VES_Insight_Records {
    const DB_VERSION = '1.2.0';
    const OPTION_KEY = 'ves_insight_records_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_insight_records';
    }



    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id', 'workspace_id', 'user_id', 'run_id', 'insight_key', 'title', 'insight_type', 'summary', 'evidence_refs_json', 'pattern_ids_json', 'confidence', 'business_impact', 'urgency', 'status', 'created_by', 'created_at', 'updated_at', 'meta_json'];
    }



    public static function required_indexes() {
        return ['workspace_id','user_id','run_id','insight_type','status','created_at'];
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
            insight_key varchar(64) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            insight_type varchar(40) NOT NULL DEFAULT 'content',
            summary longtext NULL,
            evidence_refs_json longtext NULL,
            pattern_ids_json longtext NULL,
            confidence decimal(5,2) NOT NULL DEFAULT 0.00,
            business_impact varchar(24) NOT NULL DEFAULT 'medium',
            urgency varchar(24) NOT NULL DEFAULT 'medium',
            status varchar(24) NOT NULL DEFAULT 'draft',
            created_by varchar(32) NOT NULL DEFAULT 'ai',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            meta_json longtext NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY run_id (run_id),
            KEY insight_key (insight_key),
            KEY insight_type (insight_type),
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
        if ($run_id === '') { return; }
        $wpdb->delete(self::table_name(), ['run_id' => $run_id], ['%s']);
    }

    public static function create_from_final_ai_output($run_id, $workspace_id, $user_id, $request = [], $structured = []) {
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return ['stored' => 0, 'records' => []]; }
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $request = is_array($request) ? $request : [];
        $structured = is_array($structured) ? $structured : [];
        $items = !empty($structured['insights']) && is_array($structured['insights']) ? $structured['insights'] : ((!empty($structured['key_findings']) && is_array($structured['key_findings'])) ? $structured['key_findings'] : []);
        self::delete_for_run($run_id);
        $stored = 0;
        foreach ($items as $idx => $item) {
            if (!is_array($item)) { continue; }
            if (self::insert_record($run_id, $workspace_id, $user_id, $request, $item, $idx)) { $stored++; }
        }
        return ['stored' => $stored, 'records' => self::list_by_run($run_id, 60)];
    }

    private static function insert_record($run_id, $workspace_id, $user_id, $request, $item, $idx) {
        global $wpdb;
        $now = current_time('mysql');
        $title = sanitize_text_field((string) ($item['title'] ?? $item['finding'] ?? ('Insight ' . ((int) $idx + 1))));
        $summary = (string) ($item['summary'] ?? $item['explanation'] ?? $item['business_implication'] ?? $item['finding'] ?? '');
        $type = self::safe_type($item['insight_type'] ?? $item['type'] ?? $item['category'] ?? 'content');
        $refs_requested = self::evidence_refs($item);
        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'filter_valid_evidence_refs')) {
            $refs = VES_Evidence_Store::filter_valid_evidence_refs($run_id, $refs_requested);
            $invalid = array_values(array_diff($refs_requested, $refs));
        } else {
            $refs = $refs_requested;
            $invalid = [];
        }
        $validation = [
            'valid' => $refs,
            'invalid' => $invalid,
            'evidence_ref_count' => count($refs),
            'unsupported_by_evidence' => empty($refs),
            'evidence_validation_status' => empty($refs) ? 'unsupported' : (count($refs) < 2 ? 'weak' : 'supported'),
        ];
        $pattern_ids = self::string_list($item['pattern_ids'] ?? $item['pattern_ids_json'] ?? []);
        $confidence = self::confidence($item['confidence'] ?? 0.45);
        if (!empty($validation['unsupported_by_evidence'])) { $confidence = min($confidence, 0.35); }
        elseif (!empty($validation['invalid'])) { $confidence = min($confidence, 0.65); }
        $impact = self::impact($item['business_impact'] ?? $item['impact'] ?? $item['severity'] ?? 'medium');
        $urgency = self::impact($item['urgency'] ?? $item['severity'] ?? 'medium');
        $status = self::status($item['status'] ?? 'draft');
        $created_by = sanitize_key((string) ($item['created_by'] ?? 'ai')) ?: 'ai';
        $insight_key = hash('sha256', implode('|', [$run_id, $type, strtolower($title), implode(',', $refs)]));
        $limitations = is_array($item['limitations'] ?? null) ? $item['limitations'] : [];
        if (!empty($validation['unsupported_by_evidence'])) { $limitations[] = 'No valid evidence_ref from this run supports this insight; treat it as a hypothesis.'; }
        $meta = [
            'recommended_action' => $item['recommended_action'] ?? '',
            'next_workflow' => $item['next_workflow'] ?? '',
            'source_platforms' => $item['source_platforms'] ?? [],
            'competitor_refs' => $item['competitor_refs'] ?? [],
            'limitations' => array_values(array_unique(array_filter(array_map('sanitize_text_field', $limitations)))),
            'raw_compact' => self::compact($item),
            'brand_name' => $request['brand_name'] ?? '',
            'unsupported_by_evidence' => !empty($validation['unsupported_by_evidence']),
            'invalid_evidence_refs' => $validation['invalid'] ?? [],
            'evidence_ref_count' => (int) ($validation['evidence_ref_count'] ?? count($refs)),
            'evidence_validation_status' => $validation['evidence_validation_status'] ?? (empty($refs) ? 'unsupported' : 'supported'),
        ];
        return false !== $wpdb->insert(self::table_name(), [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'run_id' => $run_id,
            'insight_key' => $insight_key,
            'title' => $title,
            'insight_type' => $type,
            'summary' => sanitize_textarea_field($summary),
            'evidence_refs_json' => wp_json_encode($refs, JSON_UNESCAPED_UNICODE),
            'pattern_ids_json' => wp_json_encode($pattern_ids, JSON_UNESCAPED_UNICODE),
            'confidence' => $confidence,
            'business_impact' => $impact,
            'urgency' => $urgency,
            'status' => $status,
            'created_by' => $created_by,
            'created_at' => $now,
            'updated_at' => $now,
            'meta_json' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s','%s']);
    }

    public static function list_by_run($run_id, $limit = 60) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $limit = max(1, min(300, (int) $limit));
        if ($run_id === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY confidence DESC, id ASC LIMIT %d', $run_id, $limit), ARRAY_A);
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
        foreach (['insight_type' => 'insight_type', 'status' => 'status'] as $key => $column) {
            $value = sanitize_key((string) ($filters[$key] ?? ''));
            if ($value !== '') { $where[] = "$column = %s"; $params[] = $value; }
        }
        $where_sql = implode(' AND ', $where);
        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where_sql} ORDER BY confidence DESC, id ASC LIMIT %d OFFSET %d", array_merge($params, [$per_page, $offset])), ARRAY_A);
        return ['items' => array_map([__CLASS__, 'prepare'], (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0];
    }

    public static function list_by_workspace($workspace_id, $limit = 80) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $limit = max(1, min(300, (int) $limit));
        if ($workspace_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function count_by_run($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return 0; }
        $filters = is_array($filters) ? $filters : [];
        $where = ['run_id = %s'];
        $params = [$run_id];
        foreach (['insight_type' => 'insight_type', 'status' => 'status'] as $key => $column) {
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

    public static function update_status($id, $run_id, $status, $user_id = 0) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        $run_id = sanitize_text_field((string) $run_id);
        $status = self::status($status);
        if ($id <= 0 || $run_id === '') { return new WP_Error('invalid_record', 'Invalid insight record.'); }
        $record = self::get($id);
        if (!$record || (string) ($record['run_id'] ?? '') !== $run_id) { return new WP_Error('not_found', 'Insight record not found for this run.'); }
        if (!self::user_can_access_record($record, $user_id ?: null)) { return new WP_Error('forbidden', 'You cannot update this insight.'); }
        $updated = $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id, 'run_id' => $run_id], ['%s','%s'], ['%d','%s']);
        return false === $updated ? new WP_Error('db_error', 'Unable to update insight status.') : self::get($id);
    }



    public static function update_status_by_id($id, $status, $user_id = 0) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        $status = self::status($status);
        if ($id <= 0) { return new WP_Error('invalid_record', 'Invalid insight record.'); }
        $record = self::get($id);
        if (!$record) { return new WP_Error('not_found', 'Insight record not found.'); }
        if (!self::user_can_access_record($record, $user_id ?: null)) { return new WP_Error('forbidden', 'You cannot update this insight.'); }
        $updated = $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id], ['%s','%s'], ['%d']);
        return false === $updated ? new WP_Error('db_error', 'Unable to update insight status.') : self::get($id);
    }

    /**
     * Legacy evidence gate (deterministic, no LLM, no network). Given a PREPARED
     * legacy insight record (as returned by self::get()/self::prepare()), decide
     * whether it carries supporting evidence and may be manually approved.
     *
     * Conservative: the legacy schema has structured evidence fields
     * (evidence_refs + meta evidence_ref_count / evidence_validation_status), so we
     * rely on those — never on free text. Empty/unsupported => not approvable.
     *
     * @return array{allowed:bool,reason:string,evidence_count:int,details:array}
     */
    public static function has_supporting_evidence($record): array {
        if (!is_array($record)) {
            return ['allowed' => false, 'reason' => 'invalid_record', 'evidence_count' => 0, 'details' => []];
        }
        $refs = is_array($record['evidence_refs'] ?? null) ? $record['evidence_refs'] : [];
        $count = max((int) ($record['evidence_ref_count'] ?? 0), count($refs));
        $status = function_exists('sanitize_key')
            ? sanitize_key((string) ($record['evidence_validation_status'] ?? ''))
            : strtolower((string) ($record['evidence_validation_status'] ?? ''));
        $unsupported = !empty($record['unsupported_by_evidence']) || $status === 'unsupported';
        $allowed = ($count > 0) && !$unsupported;
        if ($allowed) {
            $reason = 'evidence_present';
        } elseif ($count <= 0) {
            $reason = 'no_evidence_refs';
        } else {
            $reason = 'evidence_marked_unsupported';
        }
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'evidence_count' => $count,
            'details' => [
                'evidence_validation_status' => $status,
                'unsupported_by_evidence' => $unsupported,
            ],
        ];
    }

    /**
     * Load a legacy insight by id and evaluate its approval evidence gate.
     * @return array{allowed:bool,reason:string,evidence_count:int,details:array}
     */
    public static function can_approve_legacy_insight($insight_id): array {
        $record = self::get((int) $insight_id);
        if (!is_array($record)) {
            return ['allowed' => false, 'reason' => 'record_not_found', 'evidence_count' => 0, 'details' => []];
        }
        return self::has_supporting_evidence($record);
    }

    /**
     * Read-only counts for admin/readiness visibility (Part E). No raw evidence
     * text — counts only. Bounds the approved-row scan so it never full-scans.
     * @return array{legacy_total:int,legacy_approved:int,legacy_approved_without_evidence:int,gate:string}
     */
    public static function legacy_approval_health($workspace_id = 0, int $limit = 500): array {
        global $wpdb;
        self::create_table();
        $out = ['legacy_total' => 0, 'legacy_approved' => 0, 'legacy_approved_without_evidence' => 0, 'gate' => 'active'];
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return $out; }
        $table = self::table_name();
        $ws = max(0, (int) $workspace_id);
        $limit = max(1, min(2000, $limit));
        if ($ws > 0) {
            $out['legacy_total'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE workspace_id = %d', $ws));
            $out['legacy_approved'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE workspace_id = %d AND status = %s', $ws, 'approved'));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE workspace_id = %d AND status = %s LIMIT %d', $ws, 'approved', $limit), ARRAY_A);
        } else {
            $out['legacy_total'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
            $out['legacy_approved'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE status = %s', 'approved'));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE status = %s LIMIT %d', 'approved', $limit), ARRAY_A);
        }
        foreach ((array) $rows as $row) {
            $gate = self::has_supporting_evidence(self::prepare($row));
            if (empty($gate['allowed'])) { $out['legacy_approved_without_evidence']++; }
        }
        return $out;
    }

    public static function retrieve_for_ai_context($args = [], $limit = 6) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(1, min(20, (int) $limit));
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        if ($workspace_id <= 0) { return []; }
        $statuses = array_values(array_intersect(array_map('sanitize_key', (array) ($args['statuses'] ?? ['approved','accepted','saved'])), ['approved','accepted','saved']));
        if (empty($statuses)) { $statuses = ['approved','accepted','saved']; }
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge([$workspace_id], $statuses);
        $platform = sanitize_key((string) ($args['platform'] ?? $args['source_type'] ?? ''));
        $terms = [];
        foreach (['brand_name','topic','focus','industry','country','research_goal','audit_goal'] as $key) {
            if (!empty($args[$key]) && is_scalar($args[$key])) { $terms[] = sanitize_text_field((string) $args[$key]); }
        }
        $sql = 'SELECT * FROM ' . self::table_name() . " WHERE workspace_id = %d AND status IN ({$placeholders}) ORDER BY confidence DESC, updated_at DESC LIMIT 120";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $scored = [];
        foreach ((array) $rows as $row) {
            $score = ((float) ($row['confidence'] ?? 0)) * 100;
            $haystack = strtolower(implode(' ', [$row['title'] ?? '', $row['summary'] ?? '', $row['insight_type'] ?? '', $row['meta_json'] ?? '', $platform]));
            foreach ($terms as $term) {
                $term = strtolower(trim((string) $term));
                if ($term !== '' && strlen($term) >= 3 && strpos($haystack, $term) !== false) { $score += 18; }
            }
            $row['_score'] = $score;
            $scored[] = $row;
        }
        usort($scored, static function($a, $b) {
            if ((float) $a['_score'] === (float) $b['_score']) { return strcmp((string) $b['updated_at'], (string) $a['updated_at']); }
            return (float) $a['_score'] < (float) $b['_score'] ? 1 : -1;
        });
        return array_map([__CLASS__, 'prepare'], array_slice($scored, 0, $limit));
    }

    public static function retrieve_excluded_for_ai_context($args = [], $limit = 8) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(1, min(30, (int) $limit));
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        if ($workspace_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT title, summary, status FROM ' . self::table_name() . " WHERE workspace_id = %d AND status IN ('rejected','superseded') ORDER BY updated_at DESC LIMIT %d",
            $workspace_id,
            $limit
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $line = trim((string) ($row['title'] ?? '') . ': ' . (string) ($row['summary'] ?? ''));
            if ($line !== '') { $out[] = sanitize_textarea_field($line); }
        }
        return $out;
    }

    public static function list_for_admin($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(1, min(200, (int) ($args['limit'] ?? 80)));
        $where = ['1=1'];
        $params = [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id > 0) { $where[] = 'workspace_id = %d'; $params[] = $workspace_id; }
        $status = sanitize_key((string) ($args['status'] ?? ''));
        if ($status !== '') { $where[] = 'status = %s'; $params[] = $status; }
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$limit])), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function save_proposed_insight($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $user_id = max(0, (int) ($args['user_id'] ?? 0));
        $run_id = sanitize_text_field((string) ($args['run_id'] ?? 'manual'));
        $title = sanitize_text_field((string) ($args['title'] ?? 'Proposed insight'));
        $summary = sanitize_textarea_field((string) ($args['summary'] ?? ''));
        if ($workspace_id <= 0 || $title === '' || $summary === '') { return new WP_Error('invalid_insight', 'Invalid insight.'); }
        $type = self::safe_type($args['insight_type'] ?? 'opportunity');
        $status = self::status($args['status'] ?? 'proposed');
        if ($status === 'draft') { $status = 'proposed'; }
        $refs = self::string_list($args['evidence_refs'] ?? [], 80);
        $meta = is_array($args['meta'] ?? null) ? $args['meta'] : [];
        $meta['evidence_validation_status'] = empty($refs) ? 'unsupported' : (count($refs) < 2 ? 'weak' : 'supported');
        $meta['evidence_ref_count'] = count($refs);
        $now = current_time('mysql');
        $insight_key = hash('sha256', implode('|', [$workspace_id, $run_id, strtolower($title), $type, implode(',', $refs)]));
        $existing_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table_name() . ' WHERE insight_key = %s ORDER BY id DESC LIMIT 1', $insight_key));
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'run_id' => $run_id,
            'insight_key' => $insight_key,
            'title' => $title,
            'insight_type' => $type,
            'summary' => $summary,
            'evidence_refs_json' => wp_json_encode($refs, JSON_UNESCAPED_UNICODE),
            'pattern_ids_json' => wp_json_encode([], JSON_UNESCAPED_UNICODE),
            'confidence' => max(0, min(1, (float) ($args['confidence'] ?? 0.45))),
            'business_impact' => self::impact($args['business_impact'] ?? 'medium'),
            'urgency' => self::impact($args['urgency'] ?? 'medium'),
            'status' => $status,
            'created_by' => sanitize_key((string) ($args['created_by'] ?? 'ai')) ?: 'ai',
            'updated_at' => $now,
            'meta_json' => wp_json_encode(self::compact($meta), JSON_UNESCAPED_UNICODE),
        ];
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s'];
        if ($existing_id > 0) {
            $updated = $wpdb->update(self::table_name(), $row, ['id' => $existing_id], $formats, ['%d']);
            return false === $updated ? new WP_Error('db_error', 'Unable to update insight.') : self::get($existing_id);
        }
        $row['created_at'] = $now;
        $inserted = $wpdb->insert(self::table_name(), $row, array_merge($formats, ['%s']));
        return false === $inserted ? new WP_Error('db_error', 'Unable to save insight.') : self::get((int) $wpdb->insert_id);
    }

    public static function prompt_pack($run_id, $limit = 24) {
        $rows = self::list_by_run($run_id, $limit);
        return array_map(static function($row) {
            return [
                'insight_ref' => 'ins_' . (int) $row['id'],
                'title' => $row['title'],
                'type' => $row['insight_type'],
                'summary' => $row['summary'],
                'evidence_refs' => $row['evidence_refs'],
                'confidence' => $row['confidence'],
                'business_impact' => $row['business_impact'],
                'urgency' => $row['urgency'],
                'evidence_validation_status' => $row['evidence_validation_status'],
                'unsupported_by_evidence' => $row['unsupported_by_evidence'],
                'recommended_action' => $row['meta']['recommended_action'] ?? '',
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
        $patterns = json_decode((string) ($row['pattern_ids_json'] ?? '[]'), true);
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        $refs = is_array($refs) ? array_values($refs) : [];
        $status = sanitize_key((string) ($meta['evidence_validation_status'] ?? (empty($refs) ? 'unsupported' : 'supported')));
        $prepared = [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'insight_key' => (string) ($row['insight_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'insight_type' => (string) ($row['insight_type'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'evidence_refs' => $refs,
            'pattern_ids' => is_array($patterns) ? array_values($patterns) : [],
            'confidence' => (float) ($row['confidence'] ?? 0),
            'business_impact' => (string) ($row['business_impact'] ?? ''),
            'urgency' => (string) ($row['urgency'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'meta' => is_array($meta) ? $meta : [],
            'unsupported_by_evidence' => !empty($meta['unsupported_by_evidence']) || $status === 'unsupported',
            'invalid_evidence_refs' => is_array($meta['invalid_evidence_refs'] ?? null) ? array_values($meta['invalid_evidence_refs']) : [],
            'evidence_ref_count' => (int) ($meta['evidence_ref_count'] ?? count($refs)),
            'evidence_validation_status' => $status,
        ];

        // Phase 23: additive, read-only canonical confidence annotation (VES_Confidence).
        // Derived purely from the fields already present in $prepared — no DB write, no change
        // to any existing key/value, and not rendered by the current UI (the frontend reads
        // named fields only). Guarded so the absence of the mapper never fatals.
        if (class_exists('VES_Confidence')) {
            $base = VES_Confidence::from_brand_audit($prepared);
            $prepared['confidence_canonical'] = VES_Confidence::from_evidence_validation($prepared, $base);
        }

        return $prepared;
    }

    private static function evidence_refs($item) {
        return self::string_list($item['evidence_refs'] ?? $item['evidence_ids'] ?? $item['evidence'] ?? $item['evidence_used'] ?? [], 80);
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
        $map = ['content_gap' => 'content', 'platform_weakness' => 'performance_gap', 'audience_intent' => 'audience', 'creative_opportunity' => 'creative', 'website_gap' => 'search', 'search_gap' => 'search', 'campaign_opportunity' => 'opportunity', 'quick_win' => 'opportunity', 'competitor_advantage' => 'competitor', 'trust_gap' => 'positioning', 'conversion_gap' => 'performance_gap'];
        if (isset($map[$type])) { return $map[$type]; }
        $allowed = ['audience','competitor','content','search','trend','positioning','creative','risk','opportunity','performance_gap','pattern','gap','recommendation','hypothesis'];
        return in_array($type, $allowed, true) ? $type : 'content';
    }

    private static function confidence($value) {
        if (is_string($value) && !is_numeric($value)) { $value = ['low' => 0.35, 'medium' => 0.6, 'high' => 0.85][$value] ?? 0.45; }
        return max(0, min(1, (float) $value));
    }

    private static function impact($value) {
        $value = sanitize_key((string) $value);
        return in_array($value, ['low','medium','high'], true) ? $value : 'medium';
    }

    private static function status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, ['draft','proposed','approved','accepted','rejected','saved','archived','superseded'], true) ? $status : 'draft';
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
