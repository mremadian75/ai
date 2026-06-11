<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Periodically consolidates approved workspace knowledge into graph nodes/edges.
 *
 * This class compresses profile fields, approved insights and selected memory into
 * durable structured knowledge. It does not call OpenAI/Apify and does not store
 * raw provider payloads.
 */
final class VES_Knowledge_Consolidator {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'ves_knowledge_consolidator_db_version';
    const LOCK_PREFIX = 'ves_kg_consolidation_lock_';

    public static function runs_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_knowledge_consolidation_runs';
    }

    public static function create_table($force = false) {
        if (!$force && get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::runs_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL DEFAULT 'started',
            scope varchar(40) NOT NULL DEFAULT 'workspace',
            input_counts_json longtext NULL,
            summary_json longtext NULL,
            created_nodes int unsigned NOT NULL DEFAULT 0,
            updated_nodes int unsigned NOT NULL DEFAULT 0,
            created_edges int unsigned NOT NULL DEFAULT 0,
            updated_edges int unsigned NOT NULL DEFAULT 0,
            error_summary text NULL,
            started_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY started_at (started_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::runs_table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function run($args = []) {
        self::create_table();
        if (class_exists('VES_Knowledge_Graph')) { VES_Knowledge_Graph::create_tables(); }
        $args = is_array($args) ? $args : [];
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id(array_merge($args, ['user_id' => $user_id]), $user_id);
        }
        if ($workspace_id <= 0) { return ['status' => 'skipped', 'reason' => 'missing_workspace_id']; }

        $lock_key = self::LOCK_PREFIX . md5((string) $workspace_id);
        if (function_exists('get_transient') && get_transient($lock_key)) {
            return ['status' => 'skipped', 'reason' => 'locked', 'workspace_id' => $workspace_id];
        }
        if (function_exists('set_transient')) { set_transient($lock_key, 1, 15 * MINUTE_IN_SECONDS); }

        $run_id = self::insert_run($workspace_id, $user_id, 'started', sanitize_key((string) ($args['scope'] ?? 'workspace')));
        $stats = ['created_nodes' => 0, 'updated_nodes' => 0, 'created_edges' => 0, 'updated_edges' => 0];
        $input_counts = ['profile_fields' => 0, 'insights' => 0, 'memory' => 0];
        $summary = [];
        try {
            $profile = class_exists('VES_Workspace_Profile') ? VES_Workspace_Profile::get_profile($workspace_id) : [];
            if (is_array($profile) && !empty(array_filter($profile))) {
                $input_counts['profile_fields'] = count(array_filter($profile));
                self::consolidate_profile($workspace_id, $user_id, $profile, $stats, $summary);
            }
            $insights = self::load_approved_insights($workspace_id, $user_id, 40);
            $input_counts['insights'] = count($insights);
            self::consolidate_insights($workspace_id, $user_id, $profile, $insights, $stats, $summary);

            $memories = self::load_memory_records($workspace_id, $user_id, 30);
            $input_counts['memory'] = count($memories);
            self::consolidate_memory_themes($workspace_id, $user_id, $profile, $memories, $stats, $summary);

            self::update_run($run_id, 'completed', $input_counts, $summary, $stats, '');
        } catch (Throwable $e) {
            self::update_run($run_id, 'failed', $input_counts, $summary, $stats, $e->getMessage());
            if (function_exists('delete_transient')) { delete_transient($lock_key); }
            return ['status' => 'failed', 'workspace_id' => $workspace_id, 'error' => $e->getMessage()];
        }
        if (function_exists('delete_transient')) { delete_transient($lock_key); }
        return array_merge(['status' => 'completed', 'workspace_id' => $workspace_id, 'run_id' => $run_id, 'input_counts' => $input_counts], $stats, ['summary' => $summary]);
    }

    public static function list_runs_for_admin($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        if ($workspace_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::runs_table_name() . ' WHERE workspace_id = %d ORDER BY started_at DESC, id DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::runs_table_name() . ' ORDER BY started_at DESC, id DESC LIMIT %d', $limit), ARRAY_A);
        }
        return array_map([__CLASS__, 'prepare_run'], (array) $rows);
    }

    public static function run_scheduled_consolidation($payload = []) {
        $payload = is_array($payload) ? $payload : [];
        $workspace_id = max(0, (int) ($payload['workspace_id'] ?? 0));
        if ($workspace_id > 0) { return self::run($payload); }
        $workspace_ids = self::discover_workspace_ids(20);
        $results = [];
        foreach ($workspace_ids as $id) {
            $results[] = self::run(['workspace_id' => $id, 'user_id' => 0, 'scope' => 'scheduled']);
        }
        return ['status' => 'completed', 'workspaces' => count($workspace_ids), 'results' => $results];
    }

    public static function discover_workspace_ids($limit = 20) {
        global $wpdb;
        $ids = [];
        if (class_exists('VES_Knowledge_Graph')) { VES_Knowledge_Graph::create_tables(); }
        if (class_exists('VES_Workspace_Profile')) { VES_Workspace_Profile::create_table(); }
        foreach ([class_exists('VES_Workspace_Profile') ? VES_Workspace_Profile::table_name() : '', class_exists('VES_Memory_Records') ? VES_Memory_Records::table_name() : '', class_exists('VES_Semantic_Index') ? VES_Semantic_Index::table_name() : ''] as $table) {
            if ($table === '') { continue; }
            $rows = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT workspace_id FROM ' . $table . ' WHERE workspace_id > 0 ORDER BY workspace_id DESC LIMIT %d', max(1, min(200, (int) $limit))));
            foreach ((array) $rows as $row) { $ids[] = (int) $row; }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        return array_slice($ids, 0, max(1, min(200, (int) $limit)));
    }

    public static function consolidate_profile($workspace_id, $user_id, $profile, &$stats, &$summary) {
        $company_key = self::ensure_company_node($workspace_id, $user_id, $profile, $stats);

        foreach (['market_country' => ['market', 'operates_in'], 'industry' => ['industry', 'belongs_to_industry']] as $field => $map) {
            if (empty($profile[$field])) { continue; }
            $label = is_array($profile[$field]) ? implode(', ', $profile[$field]) : (string) $profile[$field];
            $node_key = VES_Knowledge_Graph::node_key($map[0], $label, $workspace_id);
            self::upsert_node_counted($stats, ['workspace_id' => $workspace_id, 'user_id' => $user_id, 'node_key' => $node_key, 'node_type' => $map[0], 'label' => $label, 'status' => 'consolidated', 'confidence_score' => 75, 'importance_score' => 75, 'replace_mentions_count' => true]);
            self::upsert_edge_counted($stats, ['workspace_id' => $workspace_id, 'from_node_key' => $company_key, 'to_node_key' => $node_key, 'relation_type' => $map[1], 'status' => 'consolidated', 'weight' => 75, 'confidence_score' => 75]);
        }

        foreach (['known_products_services','products_services','competitors','audience_segments','known_strengths','known_risks','known_opportunities','approved_strategic_learnings','rejected_outdated_assumptions'] as $field) {
            if (empty($profile[$field])) { continue; }
            list($node_type, $relation) = VES_Knowledge_Graph::relation_for_profile_field($field);
            $status = $field === 'rejected_outdated_assumptions' ? 'rejected' : 'consolidated';
            foreach (self::to_list($profile[$field], 30) as $label) {
                $node_key = VES_Knowledge_Graph::node_key($node_type, $label, $workspace_id);
                self::upsert_node_counted($stats, ['workspace_id' => $workspace_id, 'user_id' => $user_id, 'node_key' => $node_key, 'node_type' => $node_type, 'label' => $label, 'status' => $status, 'summary' => $label, 'confidence_score' => $status === 'rejected' ? 85 : 70, 'importance_score' => $status === 'rejected' ? 80 : 65, 'replace_mentions_count' => true]);
                self::upsert_edge_counted($stats, ['workspace_id' => $workspace_id, 'from_node_key' => $company_key, 'to_node_key' => $node_key, 'relation_type' => $relation, 'status' => $status === 'rejected' ? 'outdated' : 'consolidated', 'summary' => $label, 'weight' => $status === 'rejected' ? 80 : 60, 'confidence_score' => 70]);
            }
        }
        $summary['profile_consolidated'] = true;
    }

    public static function consolidate_insights($workspace_id, $user_id, $profile, $insights, &$stats, &$summary) {
        $company_key = self::ensure_company_node($workspace_id, $user_id, $profile, $stats);
        $processed = 0;
        foreach ((array) $insights as $insight) {
            if (!is_array($insight)) { continue; }
            $status = sanitize_key((string) ($insight['status'] ?? ''));
            if (!in_array($status, ['approved','accepted','saved'], true)) { continue; }
            $title = sanitize_text_field((string) ($insight['title'] ?? 'Approved insight'));
            $summary_text = sanitize_textarea_field((string) ($insight['summary'] ?? ''));
            $type = sanitize_key((string) ($insight['insight_type'] ?? 'insight'));
            $node_key = VES_Knowledge_Graph::node_key('insight', $title . '-' . ($insight['id'] ?? md5($title . $summary_text)), $workspace_id);
            $confidence = self::score_from_maybe_fraction($insight['confidence_score'] ?? $insight['confidence'] ?? 0.65);
            $importance = max(60, min(95, $confidence + (float) ($insight['actionability_score'] ?? 0) * 15));
            self::upsert_node_counted($stats, ['workspace_id' => $workspace_id, 'user_id' => $user_id, 'node_key' => $node_key, 'node_type' => 'insight', 'label' => $title, 'summary' => $summary_text, 'status' => 'approved', 'evidence_refs' => $insight['evidence_refs'] ?? [], 'confidence_score' => $confidence, 'importance_score' => $importance, 'source_refs' => ['insight:' . ($insight['id'] ?? '')], 'replace_mentions_count' => true]);
            $relation = self::relation_for_insight_type($type);
            self::upsert_edge_counted($stats, ['workspace_id' => $workspace_id, 'from_node_key' => $company_key, 'to_node_key' => $node_key, 'relation_type' => $relation, 'summary' => $summary_text, 'status' => 'approved', 'evidence_refs' => $insight['evidence_refs'] ?? [], 'weight' => $importance, 'confidence_score' => $confidence]);
            $processed++;
        }
        $summary['approved_insights_consolidated'] = $processed;
    }

    public static function consolidate_memory_themes($workspace_id, $user_id, $profile, $memories, &$stats, &$summary) {
        $company_key = self::ensure_company_node($workspace_id, $user_id, $profile, $stats);
        $theme_counts = [];
        foreach ((array) $memories as $memory) {
            if (!is_array($memory)) { continue; }
            $text = trim((string) (($memory['title'] ?? '') . ' ' . ($memory['summary'] ?? '')));
            $terms = class_exists('VES_Semantic_Index') ? VES_Semantic_Index::keyword_terms($text, 8) : VES_Knowledge_Graph::extract_entity_terms($text, 8);
            foreach ($terms as $term) {
                if (strlen((string) $term) < 4) { continue; }
                $theme_counts[$term] = ($theme_counts[$term] ?? 0) + 1;
            }
        }
        arsort($theme_counts);
        $themes_consolidated = 0;
        foreach (array_slice($theme_counts, 0, 12, true) as $theme => $count) {
            if ($count < 2) { continue; }
            $node_key = VES_Knowledge_Graph::node_key('theme', $theme, $workspace_id);
            self::upsert_node_counted($stats, ['workspace_id' => $workspace_id, 'user_id' => $user_id, 'node_key' => $node_key, 'node_type' => 'theme', 'label' => $theme, 'summary' => 'Repeated recent memory theme observed ' . (int) $count . ' times.', 'status' => 'consolidated', 'confidence_score' => min(85, 45 + $count * 10), 'importance_score' => min(85, 45 + $count * 8), 'mentions_count' => $count, 'replace_mentions_count' => true]);
            self::upsert_edge_counted($stats, ['workspace_id' => $workspace_id, 'from_node_key' => $company_key, 'to_node_key' => $node_key, 'relation_type' => 'has_theme', 'summary' => 'Repeated operational memory theme.', 'status' => 'consolidated', 'weight' => min(85, 45 + $count * 8), 'confidence_score' => min(85, 45 + $count * 10)]);
            $themes_consolidated++;
        }
        $summary['memory_themes_consolidated'] = $themes_consolidated;
    }

    private static function ensure_company_node($workspace_id, $user_id, $profile, &$stats) {
        $profile = is_array($profile) ? $profile : [];
        $company = self::company_label($profile, $workspace_id);
        $company_key = VES_Knowledge_Graph::node_key('company', $company, $workspace_id);
        self::upsert_node_counted($stats, [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'node_key' => $company_key,
            'node_type' => 'company',
            'label' => $company,
            'summary' => (string) ($profile['brand_positioning'] ?? ''),
            'status' => 'consolidated',
            'confidence_score' => 80,
            'importance_score' => 95,
            'source_refs' => $profile['source_refs'] ?? [],
            'replace_mentions_count' => true,
        ]);
        return $company_key;
    }

    private static function load_approved_insights($workspace_id, $user_id, $limit) {
        if (!class_exists('VES_Insight_Records')) { return []; }
        if (method_exists('VES_Insight_Records', 'retrieve_for_ai_context')) {
            return VES_Insight_Records::retrieve_for_ai_context(['workspace_id' => $workspace_id, 'user_id' => $user_id, 'statuses' => ['approved','accepted','saved']], $limit);
        }
        if (method_exists('VES_Insight_Records', 'list_for_admin')) {
            $items = VES_Insight_Records::list_for_admin(['workspace_id' => $workspace_id, 'limit' => $limit]);
            return array_values(array_filter((array) $items, static function($item) { return is_array($item) && in_array(sanitize_key((string) ($item['status'] ?? '')), ['approved','accepted','saved'], true); }));
        }
        return [];
    }

    private static function load_memory_records($workspace_id, $user_id, $limit) {
        if (!class_exists('VES_Memory_Records')) { return []; }
        if (method_exists('VES_Memory_Records', 'retrieve_context_pack')) {
            return VES_Memory_Records::retrieve_context_pack(['workspace_id' => $workspace_id, 'user_id' => $user_id], $limit);
        }
        if (method_exists('VES_Memory_Records', 'list_for_admin')) {
            return VES_Memory_Records::list_for_admin(['workspace_id' => $workspace_id, 'limit' => $limit]);
        }
        return [];
    }

    private static function upsert_node_counted(&$stats, $args) {
        $existing = null;
        if (!empty($args['workspace_id']) && !empty($args['node_key'])) { $existing = VES_Knowledge_Graph::get_node((int) $args['workspace_id'], (string) $args['node_key']); }
        $id = VES_Knowledge_Graph::upsert_node($args);
        if ($id) { empty($existing) ? $stats['created_nodes']++ : $stats['updated_nodes']++; }
        return $id;
    }

    private static function upsert_edge_counted(&$stats, $args) {
        $existing = self::edge_exists((int) ($args['workspace_id'] ?? 0), (string) ($args['from_node_key'] ?? ''), (string) ($args['to_node_key'] ?? ''), (string) ($args['relation_type'] ?? 'related_to'));
        $id = VES_Knowledge_Graph::upsert_edge($args);
        if ($id) { $existing ? $stats['updated_edges']++ : $stats['created_edges']++; }
        return $id;
    }

    private static function edge_exists($workspace_id, $from, $to, $relation) {
        global $wpdb;
        if ($workspace_id <= 0 || $from === '' || $to === '') { return false; }
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . VES_Knowledge_Graph::edges_table_name() . ' WHERE workspace_id = %d AND from_node_key = %s AND to_node_key = %s AND relation_type = %s LIMIT 1', $workspace_id, $from, $to, VES_Knowledge_Graph::safe_relation_type($relation)));
        return $id > 0;
    }

    private static function insert_run($workspace_id, $user_id, $status, $scope) {
        global $wpdb;
        $row = ['workspace_id' => $workspace_id, 'user_id' => $user_id, 'status' => sanitize_key($status), 'scope' => sanitize_key($scope), 'started_at' => current_time('mysql')];
        $inserted = $wpdb->insert(self::runs_table_name(), $row, ['%d','%d','%s','%s','%s']);
        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    private static function update_run($run_id, $status, $input_counts, $summary, $stats, $error) {
        global $wpdb;
        if ($run_id <= 0) { return; }
        $wpdb->update(self::runs_table_name(), [
            'status' => sanitize_key($status),
            'input_counts_json' => wp_json_encode($input_counts, JSON_UNESCAPED_UNICODE),
            'summary_json' => wp_json_encode($summary, JSON_UNESCAPED_UNICODE),
            'created_nodes' => (int) ($stats['created_nodes'] ?? 0),
            'updated_nodes' => (int) ($stats['updated_nodes'] ?? 0),
            'created_edges' => (int) ($stats['created_edges'] ?? 0),
            'updated_edges' => (int) ($stats['updated_edges'] ?? 0),
            'error_summary' => sanitize_textarea_field((string) $error),
            'completed_at' => current_time('mysql'),
        ], ['id' => $run_id], ['%s','%s','%s','%d','%d','%d','%d','%s','%s'], ['%d']);
    }

    public static function prepare_run($row) {
        $input = json_decode((string) ($row['input_counts_json'] ?? '{}'), true);
        $summary = json_decode((string) ($row['summary_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'scope' => (string) ($row['scope'] ?? ''),
            'input_counts' => is_array($input) ? $input : [],
            'summary' => is_array($summary) ? $summary : [],
            'created_nodes' => (int) ($row['created_nodes'] ?? 0),
            'updated_nodes' => (int) ($row['updated_nodes'] ?? 0),
            'created_edges' => (int) ($row['created_edges'] ?? 0),
            'updated_edges' => (int) ($row['updated_edges'] ?? 0),
            'error_summary' => (string) ($row['error_summary'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
        ];
    }

    private static function company_label($profile, $workspace_id) {
        $profile = is_array($profile) ? $profile : [];
        $label = sanitize_text_field((string) ($profile['company_name'] ?? $profile['brand_name'] ?? ''));
        return $label !== '' ? $label : 'Workspace ' . (int) $workspace_id;
    }

    private static function to_list($value, $limit = 30) {
        if (is_string($value)) { $value = preg_split('/[\n,]+/', $value); }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) { $item = $item['name'] ?? $item['title'] ?? $item['summary'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $item = trim(sanitize_text_field((string) $item));
            if ($item !== '') { $out[] = $item; }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    private static function relation_for_insight_type($type) {
        $type = sanitize_key((string) $type);
        if ($type === 'risk') { return 'has_risk'; }
        if ($type === 'opportunity' || $type === 'recommendation') { return 'has_opportunity'; }
        if ($type === 'gap' || $type === 'pattern') { return 'has_insight'; }
        return 'supports';
    }

    private static function score_from_maybe_fraction($value) {
        if (!is_numeric($value)) { return 60.0; }
        $value = (float) $value;
        if ($value <= 1) { $value *= 100; }
        return max(0, min(100, $value));
    }
}
