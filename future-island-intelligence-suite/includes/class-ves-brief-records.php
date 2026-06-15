<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Structured campaign/content brief records generated from evidence-backed opportunities.
 */
final class VES_Brief_Records {
    const DB_VERSION = '1.1.0';
    const OPTION_KEY = 'ves_brief_records_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_brief_records';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return ['id','workspace_id','user_id','run_id','opportunity_id','insight_ids_json','evidence_refs_json','brief_type','title','objective','audience','key_insight','proposition','message_angles_json','content_formats_json','channel_recommendations_json','hooks_json','creative_direction','proof_points_json','risks_json','next_steps_json','status','created_by','created_at','updated_at','meta_json'];
    }



    public static function required_indexes() {
        return ['workspace_id','user_id','run_id','opportunity_id','status','created_at'];
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
            opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            insight_ids_json longtext NULL,
            evidence_refs_json longtext NULL,
            brief_type varchar(40) NOT NULL DEFAULT 'campaign',
            title varchar(255) NOT NULL DEFAULT '',
            objective longtext NULL,
            audience longtext NULL,
            key_insight longtext NULL,
            proposition longtext NULL,
            message_angles_json longtext NULL,
            content_formats_json longtext NULL,
            channel_recommendations_json longtext NULL,
            hooks_json longtext NULL,
            creative_direction longtext NULL,
            proof_points_json longtext NULL,
            risks_json longtext NULL,
            next_steps_json longtext NULL,
            status varchar(32) NOT NULL DEFAULT 'draft',
            created_by varchar(32) NOT NULL DEFAULT 'ai',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            meta_json longtext NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY run_id (run_id),
            KEY opportunity_id (opportunity_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function allowed_statuses() { return ['draft','reviewed','approved','archived']; }
    public static function allowed_types() { return ['campaign','content','social','search','creative']; }

    private static function generation_lock_key($run_id, $opportunity_id, $brief_type) {
        return 'ves_brief_gen_' . md5(sanitize_text_field((string) $run_id) . ':' . (int) $opportunity_id . ':' . sanitize_key((string) $brief_type));
    }

    public static function find_existing_for_opportunity($run_id, $opportunity_id, $brief_type = 'campaign', $statuses = ['draft','reviewed']) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $opportunity_id = max(0, (int) $opportunity_id);
        $brief_type = sanitize_key((string) $brief_type);
        if ($run_id === '' || $opportunity_id <= 0) { return null; }
        $allowed = array_values(array_intersect((array) $statuses, self::allowed_statuses()));
        if (empty($allowed)) { $allowed = ['draft','reviewed']; }
        $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
        $params = array_merge([$run_id, $opportunity_id, $brief_type], $allowed);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE run_id = %s AND opportunity_id = %d AND brief_type = %s AND status IN (' . $placeholders . ') ORDER BY id DESC LIMIT 1', $params), ARRAY_A);
        return is_array($row) ? self::prepare($row) : null;
    }

    public static function create_from_opportunity($opportunity_id, $run_id, $workspace_id, $user_id, $options = []) {
        global $wpdb;
        self::create_table();
        $opportunity_id = max(0, (int) $opportunity_id);
        $run_id = sanitize_text_field((string) $run_id);
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $options = is_array($options) ? $options : [];
        if ($opportunity_id <= 0 || $run_id === '') { return new WP_Error('invalid_brief_source', 'Invalid opportunity or run for brief generation.'); }
        if (!class_exists('VES_Opportunity_Records')) { return new WP_Error('missing_opportunity_store', 'Opportunity records store is unavailable.'); }
        $opportunity = VES_Opportunity_Records::get($opportunity_id);
        if (!$opportunity || (string) ($opportunity['run_id'] ?? '') !== $run_id) { return new WP_Error('opportunity_not_found', 'Opportunity not found for this run.'); }
        if (!VES_Opportunity_Records::user_can_access_record($opportunity, $user_id)) { return new WP_Error('forbidden_opportunity', 'You cannot generate a brief for this opportunity.'); }
        $status = sanitize_key((string) ($opportunity['status'] ?? 'candidate'));
        if (!in_array($status, ['shortlisted','approved'], true) && !(function_exists('current_user_can') && current_user_can('manage_options'))) {
            return new WP_Error('opportunity_not_ready', 'Shortlist or approve this opportunity before generating a brief.');
        }
        $brief_type = sanitize_key((string) ($options['brief_type'] ?? 'campaign'));
        if (!in_array($brief_type, self::allowed_types(), true)) { $brief_type = 'campaign'; }
        $force_new = !empty($options['force_new']) && function_exists('current_user_can') && current_user_can('manage_options');
        $request_id = sanitize_text_field((string) ($options['request_id'] ?? ''));
        if (!$force_new) {
            $existing = self::find_existing_for_opportunity($run_id, $opportunity_id, $brief_type, ['draft','reviewed']);
            if ($existing && self::user_can_access_record($existing, $user_id)) {
                $existing['idempotency_status'] = 'existing_returned';
                return $existing;
            }
        }
        $lock_key = self::generation_lock_key($run_id, $opportunity_id, $brief_type);
        if (get_transient($lock_key)) {
            $existing = self::find_existing_for_opportunity($run_id, $opportunity_id, $brief_type, ['draft','reviewed','approved']);
            if ($existing && self::user_can_access_record($existing, $user_id)) {
                $existing['idempotency_status'] = 'existing_returned_after_lock';
                return $existing;
            }
            return new WP_Error('brief_generation_locked', 'A brief is already being generated for this opportunity.');
        }
        set_transient($lock_key, 1, 120);
        $usage_key = 'brief_generation:' . $user_id . ':' . md5($run_id . ':' . $opportunity_id . ':' . $brief_type . ':' . ($force_new ? 'force' : 'default'));
        $reserved = null;
        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'reserve_usage')) {
            $reserved = VES_Usage_Billing::reserve_usage($user_id, 'brief_generated_with_ai', $usage_key, 'Brief generation reserved', [
                'target_type' => 'brief',
                'target_id' => (string) $opportunity_id,
                'run_id' => $run_id,
                'opportunity_id' => $opportunity_id,
                'brief_type' => $brief_type,
                'request_id' => $request_id,
            ]);
            if (is_wp_error($reserved)) { delete_transient($lock_key); return $reserved; }
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => $user_id, 'run_id' => $run_id, 'entity_type' => 'opportunity', 'entity_id' => (string) $opportunity_id, 'event_type' => 'brief_generation_reserved', 'actor_role' => function_exists('current_user_can') && current_user_can('manage_options') ? 'admin' : 'user', 'source' => 'brief_generator', 'request_id' => $request_id, 'note' => 'Brief generation credit reservation created.']);
            }
        }
        try {
            $refs = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::filter_valid_evidence_refs($run_id, $opportunity['evidence_refs'] ?? []) : (array) ($opportunity['evidence_refs'] ?? []);
            $evidence_items = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::get_items_by_refs($run_id, $refs, 20) : [];
            $insight_ids = array_values(array_filter(array_map('absint', (array) ($opportunity['insight_ids'] ?? []))));
            $insights = [];
            if (class_exists('VES_Insight_Records')) {
                foreach (array_slice($insight_ids, 0, 20) as $insight_id) {
                    $insight = VES_Insight_Records::get($insight_id);
                    if ($insight && (string) ($insight['run_id'] ?? '') === $run_id && VES_Insight_Records::user_can_access_record($insight, $user_id)) { $insights[] = $insight; }
                }
            }
            $context = [
                'run_id' => $run_id,
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'brief_type' => $brief_type,
                'output_language' => sanitize_key((string) ($options['output_language'] ?? '')),
                'opportunity' => $opportunity,
                'linked_insights' => $insights,
                'evidence_refs' => $refs,
                'evidence_items' => $evidence_items,
                'brand_name' => sanitize_text_field((string) ($options['brand_name'] ?? ($opportunity['meta']['brand_name'] ?? ''))),
            ];
            $memory_pack = is_array($options['memory_pack'] ?? null) ? $options['memory_pack'] : [];
            $project_context = $options['project_context'] ?? null;
            $generated = null;
            $created_by = 'local_fallback';
            if (class_exists('VES_OpenAI_Client') && method_exists('VES_OpenAI_Client', 'generate_brief_from_opportunity')) {
                $generated = VES_OpenAI_Client::generate_brief_from_opportunity($context, $project_context, $memory_pack);
                if (!is_wp_error($generated)) { $created_by = 'ai'; }
            }
            if (is_wp_error($generated) || !is_array($generated)) {
                $generated = self::local_fallback_brief($context, is_wp_error($generated) ? $generated->get_error_message() : 'OpenAI unavailable');
                $created_by = 'local_fallback';
            }
            $brief = is_array($generated['brief'] ?? null) ? $generated['brief'] : $generated;
            $validation = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::validate_evidence_refs_for_run($run_id, $brief['evidence_refs'] ?? $refs) : ['valid' => $refs, 'invalid' => [], 'evidence_validation_status' => empty($refs) ? 'unsupported' : 'supported'];
            $brief['evidence_refs'] = $validation['valid'] ?? [];
            $now = current_time('mysql');
            $inserted = $wpdb->insert(self::table_name(), [
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'run_id' => $run_id,
                'opportunity_id' => $opportunity_id,
                'insight_ids_json' => wp_json_encode($insight_ids, JSON_UNESCAPED_UNICODE),
                'evidence_refs_json' => wp_json_encode($brief['evidence_refs'], JSON_UNESCAPED_UNICODE),
                'brief_type' => $brief_type,
                'title' => sanitize_text_field((string) ($brief['title'] ?? $opportunity['title'] ?? 'Campaign brief')),
                'objective' => sanitize_textarea_field((string) ($brief['objective'] ?? '')),
                'audience' => sanitize_textarea_field((string) ($brief['audience'] ?? '')),
                'key_insight' => sanitize_textarea_field((string) ($brief['key_insight'] ?? '')),
                'proposition' => sanitize_textarea_field((string) ($brief['proposition'] ?? '')),
                'message_angles_json' => wp_json_encode(self::string_list($brief['message_angles'] ?? []), JSON_UNESCAPED_UNICODE),
                'content_formats_json' => wp_json_encode(self::string_list($brief['content_formats'] ?? []), JSON_UNESCAPED_UNICODE),
                'channel_recommendations_json' => wp_json_encode(self::string_list($brief['channel_recommendations'] ?? []), JSON_UNESCAPED_UNICODE),
                'hooks_json' => wp_json_encode(self::string_list($brief['hooks'] ?? []), JSON_UNESCAPED_UNICODE),
                'creative_direction' => sanitize_textarea_field((string) ($brief['creative_direction'] ?? '')),
                'proof_points_json' => wp_json_encode(self::string_list($brief['proof_points'] ?? []), JSON_UNESCAPED_UNICODE),
                'risks_json' => wp_json_encode(self::string_list($brief['risks'] ?? []), JSON_UNESCAPED_UNICODE),
                'next_steps_json' => wp_json_encode(self::string_list($brief['next_steps'] ?? []), JSON_UNESCAPED_UNICODE),
                'status' => 'draft',
                'created_by' => $created_by,
                'created_at' => $now,
                'updated_at' => $now,
                'meta_json' => wp_json_encode([
                    'limitations' => self::string_list($brief['limitations'] ?? []),
                    'evidence_validation_status' => $validation['evidence_validation_status'] ?? 'unsupported',
                    'invalid_evidence_refs' => $validation['invalid'] ?? [],
                    'source_opportunity_status' => $status,
                    'generation_format' => $generated['format'] ?? '',
                    'model' => $generated['model'] ?? '',
                    'fallback_reason' => $created_by === 'local_fallback' ? ($generated['fallback_reason'] ?? '') : '',
                    'usage_key' => $usage_key,
                    'force_new' => $force_new ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
            ], ['%d','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
            if ($inserted === false) { throw new RuntimeException('Unable to save generated brief.'); }
            $id = (int) $wpdb->insert_id;
            if (class_exists('VES_Usage_Billing')) {
                if ($created_by === 'ai') {
                    if (method_exists('VES_Usage_Billing', 'post_reserved_usage')) { VES_Usage_Billing::post_reserved_usage($usage_key, 'Brief generation posted'); }
                } else {
                    if (method_exists('VES_Usage_Billing', 'void_reserved_usage')) { VES_Usage_Billing::void_reserved_usage($usage_key, 'OpenAI brief generation fell back to local structured brief.'); }
                }
                if (method_exists('VES_Usage_Billing', 'record_meter_event')) {
                    VES_Usage_Billing::record_meter_event($user_id, 'brief_record_created', 'brief', (string) $id, 'Brief record created', ['run_id' => $run_id, 'opportunity_id' => $opportunity_id], 'meter:brief_record_created:' . $id);
                    if ($created_by !== 'ai') {
                        VES_Usage_Billing::record_meter_event($user_id, 'brief_generated_local_fallback', 'brief', (string) $id, 'Brief local fallback generated', ['run_id' => $run_id, 'opportunity_id' => $opportunity_id], 'meter:brief_generation_local_fallback:' . $id);
                    }
                }
            }
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => $user_id, 'run_id' => $run_id, 'entity_type' => 'brief', 'entity_id' => (string) $id, 'event_type' => 'brief_generated', 'new_status' => 'draft', 'actor_role' => function_exists('current_user_can') && current_user_can('manage_options') ? 'admin' : 'user', 'source' => 'brief_generator', 'request_id' => $request_id, 'note' => 'Brief generated from opportunity.', 'meta' => ['opportunity_id' => $opportunity_id, 'created_by' => $created_by]]);
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => $user_id, 'run_id' => $run_id, 'entity_type' => 'brief', 'entity_id' => (string) $id, 'event_type' => 'brief_generation_posted', 'actor_role' => function_exists('current_user_can') && current_user_can('manage_options') ? 'admin' : 'user', 'source' => 'brief_generator', 'request_id' => $request_id, 'note' => 'Brief generation usage finalized.']);
            }
            $record = self::get($id);
            $record['idempotency_status'] = 'created';
            return $record;
        } catch (Throwable $e) {
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'void_reserved_usage')) { VES_Usage_Billing::void_reserved_usage($usage_key, $e->getMessage()); }
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => $user_id, 'run_id' => $run_id, 'entity_type' => 'opportunity', 'entity_id' => (string) $opportunity_id, 'event_type' => 'brief_generation_voided', 'actor_role' => function_exists('current_user_can') && current_user_can('manage_options') ? 'admin' : 'user', 'source' => 'brief_generator', 'request_id' => $request_id, 'note' => $e->getMessage()]);
            }
            return new WP_Error('brief_insert_failed', $e->getMessage());
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function get($id) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        if ($id <= 0) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return is_array($row) ? self::prepare($row) : null;
    }

    public static function list_by_run($run_id, $limit = 50) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $limit = max(1, min(200, (int) $limit));
        if ($run_id === '') { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE run_id = %s ORDER BY id DESC LIMIT %d', $run_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_opportunity($opportunity_id, $limit = 20) {
        global $wpdb;
        self::create_table();
        $opportunity_id = max(0, (int) $opportunity_id);
        $limit = max(1, min(100, (int) $limit));
        if ($opportunity_id <= 0) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE opportunity_id = %d ORDER BY id DESC LIMIT %d', $opportunity_id, $limit), ARRAY_A);
        return array_map([__CLASS__, 'prepare'], (array) $rows);
    }

    public static function list_by_run_paginated($run_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $run_id = sanitize_text_field((string) $run_id);
        $filters = is_array($filters) ? $filters : [];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        if ($run_id === '') { return ['items' => [], 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0]; }
        $where = ['run_id = %s'];
        $params = [$run_id];
        $status = sanitize_key((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::allowed_statuses(), true)) { $where[] = 'status = %s'; $params[] = $status; }
        $opportunity_id = max(0, (int) ($filters['opportunity_id'] ?? 0));
        if ($opportunity_id > 0) { $where[] = 'opportunity_id = %d'; $params[] = $opportunity_id; }
        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . $where_sql, $params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge($params, [$per_page, $offset])), ARRAY_A);
        return ['items' => array_map([__CLASS__, 'prepare'], (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0];
    }

    public static function list_library($workspace_id, $user_id, $filters = []) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        $filters = is_array($filters) ? $filters : [];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $where = [];
        $params = [];
        if (function_exists('current_user_can') && current_user_can('manage_options') && !empty($filters['workspace_id'])) {
            $where[] = 'workspace_id = %d';
            $params[] = max(0, (int) $filters['workspace_id']);
        } elseif ($workspace_id > 0) {
            $where[] = 'workspace_id = %d';
            $params[] = $workspace_id;
        } elseif ($user_id > 0) {
            $where[] = 'user_id = %d';
            $params[] = $user_id;
        } else {
            return ['items' => [], 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0];
        }
        $status = sanitize_key((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::allowed_statuses(), true)) { $where[] = 'status = %s'; $params[] = $status; }
        $brief_type = sanitize_key((string) ($filters['brief_type'] ?? ''));
        if ($brief_type !== '' && in_array($brief_type, self::allowed_types(), true)) { $where[] = 'brief_type = %s'; $params[] = $brief_type; }
        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . $where_sql, $params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge($params, [$per_page, $offset])), ARRAY_A);
        return ['items' => array_map([__CLASS__, 'prepare'], (array) $rows), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0];
    }

    public static function update_status($id, $run_id, $status, $user_id) {
        global $wpdb;
        self::create_table();
        $id = max(0, (int) $id);
        $run_id = sanitize_text_field((string) $run_id);
        $status = sanitize_key((string) $status);
        if (!in_array($status, self::allowed_statuses(), true)) { return new WP_Error('invalid_brief_status', 'Invalid brief status.'); }
        $record = self::get($id);
        if (!$record || (string) ($record['run_id'] ?? '') !== $run_id) { return new WP_Error('brief_not_found', 'Brief not found for this run.'); }
        if (!self::user_can_access_record($record, $user_id)) { return new WP_Error('forbidden_brief', 'You cannot update this brief.'); }
        $previous = sanitize_key((string) ($record['status'] ?? ''));
        $updated = $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id, 'run_id' => $run_id], ['%s','%s'], ['%d','%s']);
        if ($updated === false) { return new WP_Error('brief_update_failed', 'Unable to update brief status.'); }
        if (class_exists('VES_Workflow_Events')) {
            VES_Workflow_Events::record(['workspace_id' => (int) ($record['workspace_id'] ?? 0), 'user_id' => (int) $user_id, 'run_id' => $run_id, 'entity_type' => 'brief', 'entity_id' => (string) $id, 'event_type' => 'brief_status_changed', 'previous_status' => $previous, 'new_status' => $status, 'actor_role' => function_exists('current_user_can') && current_user_can('manage_options') ? 'admin' : 'user', 'source' => 'frontend', 'note' => 'Brief status updated.']);
        }
        return self::get($id);
    }

    public static function user_can_access_record($record, $user_id = null) {
        $user_id = $user_id === null ? (function_exists('get_current_user_id') ? get_current_user_id() : 0) : (int) $user_id;
        if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }
        if (!is_array($record) || $user_id <= 0) { return false; }
        return (int) ($record['user_id'] ?? 0) === $user_id || (int) ($record['workspace_id'] ?? 0) === $user_id;
    }

    private static function prepare($row) {
        $row = is_array($row) ? $row : [];
        $json_fields = ['insight_ids_json','evidence_refs_json','message_angles_json','content_formats_json','channel_recommendations_json','hooks_json','proof_points_json','risks_json','next_steps_json','meta_json'];
        $decoded = [];
        foreach ($json_fields as $field) { $decoded[$field] = json_decode((string) ($row[$field] ?? '[]'), true); }
        $meta = is_array($decoded['meta_json']) ? $decoded['meta_json'] : [];
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'opportunity_id' => (int) ($row['opportunity_id'] ?? 0),
            'insight_ids' => is_array($decoded['insight_ids_json']) ? array_values($decoded['insight_ids_json']) : [],
            'evidence_refs' => is_array($decoded['evidence_refs_json']) ? array_values($decoded['evidence_refs_json']) : [],
            'brief_type' => (string) ($row['brief_type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'objective' => (string) ($row['objective'] ?? ''),
            'audience' => (string) ($row['audience'] ?? ''),
            'key_insight' => (string) ($row['key_insight'] ?? ''),
            'proposition' => (string) ($row['proposition'] ?? ''),
            'message_angles' => is_array($decoded['message_angles_json']) ? array_values($decoded['message_angles_json']) : [],
            'content_formats' => is_array($decoded['content_formats_json']) ? array_values($decoded['content_formats_json']) : [],
            'channel_recommendations' => is_array($decoded['channel_recommendations_json']) ? array_values($decoded['channel_recommendations_json']) : [],
            'hooks' => is_array($decoded['hooks_json']) ? array_values($decoded['hooks_json']) : [],
            'creative_direction' => (string) ($row['creative_direction'] ?? ''),
            'proof_points' => is_array($decoded['proof_points_json']) ? array_values($decoded['proof_points_json']) : [],
            'risks' => is_array($decoded['risks_json']) ? array_values($decoded['risks_json']) : [],
            'next_steps' => is_array($decoded['next_steps_json']) ? array_values($decoded['next_steps_json']) : [],
            'status' => (string) ($row['status'] ?? ''),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'meta' => $meta,
            'evidence_validation_status' => sanitize_key((string) ($meta['evidence_validation_status'] ?? (empty($decoded['evidence_refs_json']) ? 'unsupported' : 'supported'))),
        ];
    }

    private static function local_fallback_brief($context, $reason = '') {
        $opportunity = is_array($context['opportunity'] ?? null) ? $context['opportunity'] : [];
        $title = sanitize_text_field((string) ($opportunity['title'] ?? 'Campaign brief'));
        $action = sanitize_textarea_field((string) ($opportunity['recommended_action'] ?? $opportunity['description'] ?? 'Turn the opportunity into a focused test brief.'));
        return [
            'brief' => [
                'title' => $title,
                'objective' => $action,
                'audience' => 'Audience to be refined from the brand context and available evidence.',
                'key_insight' => sanitize_textarea_field((string) ($opportunity['description'] ?? $opportunity['title'] ?? 'Evidence-backed opportunity.')),
                'proposition' => sanitize_textarea_field((string) ($opportunity['campaign_angle'] ?? $opportunity['content_brief_seed'] ?? $action)),
                'message_angles' => array_filter([$opportunity['campaign_angle'] ?? '', $opportunity['recommended_action'] ?? '']),
                'content_formats' => ['campaign concept', 'social post series', 'landing-page section'],
                'channel_recommendations' => ['Owned social', 'Website/content hub'],
                'hooks' => ['Lead with the audience tension found in the audit.', 'Use proof points from cited evidence refs.'],
                'creative_direction' => 'Use a clear evidence-led concept. Avoid unsupported performance claims.',
                'proof_points' => (array) ($context['evidence_refs'] ?? []),
                'risks' => ['Local fallback brief. Review before execution.', 'Evidence coverage may be limited.'],
                'next_steps' => ['Review evidence refs.', 'Add brand-specific constraints.', 'Approve brief before production.'],
                'evidence_refs' => (array) ($context['evidence_refs'] ?? []),
                'limitations' => ['Generated locally because OpenAI was unavailable or returned invalid output.', $reason],
            ],
            'format' => 'local_fallback',
            'fallback_reason' => $reason,
        ];
    }

    private static function string_list($value, $limit = 30) {
        if (is_string($value)) { $value = preg_split('/[\n,]+/', $value); }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) { $item = $item['text'] ?? $item['title'] ?? wp_json_encode($item); }
            if (!is_scalar($item)) { continue; }
            $s = sanitize_text_field((string) $item);
            if ($s !== '') { $out[] = substr($s, 0, 400); }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }
}
