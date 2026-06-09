<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight workspace knowledge graph.
 *
 * Stores durable company/workspace concepts and relationships that can be reused
 * by AI context building. This is structured memory, not model training and not a
 * vector database.
 */
final class VES_Knowledge_Graph {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'ves_knowledge_graph_db_version';

    public static function nodes_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_knowledge_nodes';
    }

    public static function edges_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_knowledge_edges';
    }

    public static function create_tables($force = false) {
        if (!$force && get_option(self::OPTION_KEY) === self::DB_VERSION && self::tables_exist()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $nodes = self::nodes_table_name();
        $edges = self::edges_table_name();

        $nodes_sql = "CREATE TABLE {$nodes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            node_key varchar(160) NOT NULL DEFAULT '',
            node_type varchar(40) NOT NULL DEFAULT 'concept',
            label varchar(255) NOT NULL DEFAULT '',
            summary longtext NULL,
            status varchar(24) NOT NULL DEFAULT 'active',
            source_refs_json longtext NULL,
            evidence_refs_json longtext NULL,
            confidence_score decimal(7,2) NOT NULL DEFAULT 0.00,
            importance_score decimal(7,2) NOT NULL DEFAULT 0.00,
            feedback_score decimal(7,2) NOT NULL DEFAULT 0.00,
            mentions_count int unsigned NOT NULL DEFAULT 0,
            last_seen_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY node_lookup (workspace_id, node_key),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY node_type (node_type),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $edges_sql = "CREATE TABLE {$edges} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            from_node_key varchar(160) NOT NULL DEFAULT '',
            to_node_key varchar(160) NOT NULL DEFAULT '',
            relation_type varchar(48) NOT NULL DEFAULT 'related_to',
            summary longtext NULL,
            status varchar(24) NOT NULL DEFAULT 'active',
            evidence_refs_json longtext NULL,
            confidence_score decimal(7,2) NOT NULL DEFAULT 0.00,
            weight decimal(7,2) NOT NULL DEFAULT 0.00,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY edge_lookup (workspace_id, from_node_key, to_node_key, relation_type),
            KEY workspace_id (workspace_id),
            KEY from_node_key (from_node_key),
            KEY to_node_key (to_node_key),
            KEY relation_type (relation_type),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        dbDelta($nodes_sql);
        dbDelta($edges_sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function tables_exist() {
        global $wpdb;
        $nodes = self::nodes_table_name();
        $edges = self::edges_table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nodes)) === $nodes
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $edges)) === $edges;
    }

    public static function safe_node_type($type) {
        $type = sanitize_key((string) $type);
        $allowed = ['company','product','service','competitor','audience','market','industry','strength','risk','opportunity','insight','assumption','theme','platform','memory','concept'];
        return in_array($type, $allowed, true) ? $type : 'concept';
    }

    public static function safe_relation_type($type) {
        $type = sanitize_key((string) $type);
        $allowed = ['related_to','offers','competes_with','serves','operates_in','belongs_to_industry','has_strength','has_risk','has_opportunity','has_insight','supports','contradicts','supersedes','rejects','mentions','derived_from','observed_on','uses_tone','has_theme'];
        return in_array($type, $allowed, true) ? $type : 'related_to';
    }

    public static function safe_status($status) {
        $status = sanitize_key((string) $status);
        $allowed = ['active','approved','consolidated','pinned','rejected','archived','superseded','outdated'];
        return in_array($status, $allowed, true) ? $status : 'active';
    }

    public static function node_key($node_type, $label, $workspace_id = 0) {
        $node_type = self::safe_node_type($node_type);
        $label = trim(wp_strip_all_tags((string) $label));
        $label = preg_replace('/\s+/u', ' ', $label);
        $slug = function_exists('sanitize_title') ? sanitize_title($label) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
        $slug = trim((string) $slug, '-');
        if ($slug === '') { $slug = substr(md5($node_type . '|' . $label), 0, 16); }
        $prefix = $workspace_id > 0 ? ((int) $workspace_id . ':') : '';
        return self::truncate($prefix . $node_type . ':' . $slug, 160);
    }

    public static function upsert_node($args = []) {
        global $wpdb;
        self::create_tables();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        $node_type = self::safe_node_type($args['node_type'] ?? 'concept');
        $label = self::truncate(sanitize_text_field((string) ($args['label'] ?? '')), 240);
        if ($workspace_id <= 0 || $label === '') { return 0; }
        $node_key = sanitize_text_field((string) ($args['node_key'] ?? self::node_key($node_type, $label, $workspace_id)));
        $now = current_time('mysql');
        $existing = self::get_node($workspace_id, $node_key);
        $mentions = max(1, (int) ($args['mentions_count'] ?? 1));
        if ($existing && empty($args['replace_mentions_count'])) {
            $mentions += (int) ($existing['mentions_count'] ?? 0);
        }
        $source_refs = self::string_list($args['source_refs'] ?? [], 60);
        $evidence_refs = self::string_list($args['evidence_refs'] ?? [], 80);
        if ($existing && empty($args['replace_refs'])) {
            $source_refs = self::merge_string_lists((array) ($existing['source_refs'] ?? []), $source_refs, 60);
            $evidence_refs = self::merge_string_lists((array) ($existing['evidence_refs'] ?? []), $evidence_refs, 80);
        }
        $summary = self::truncate(sanitize_textarea_field((string) ($args['summary'] ?? '')), 2500);
        if ($summary === '' && $existing && !empty($existing['summary'])) {
            $summary = self::truncate((string) $existing['summary'], 2500);
        }
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => max(0, (int) ($args['user_id'] ?? 0)),
            'node_key' => self::truncate($node_key, 160),
            'node_type' => $node_type,
            'label' => $label,
            'summary' => $summary,
            'status' => self::safe_status($args['status'] ?? ($existing['status'] ?? 'active')),
            'source_refs_json' => wp_json_encode($source_refs, JSON_UNESCAPED_UNICODE),
            'evidence_refs_json' => wp_json_encode($evidence_refs, JSON_UNESCAPED_UNICODE),
            'confidence_score' => self::normalize_score($args['confidence_score'] ?? ($existing['confidence_score'] ?? 60)),
            'importance_score' => self::normalize_score($args['importance_score'] ?? ($existing['importance_score'] ?? 50)),
            'feedback_score' => self::normalize_feedback_score($args['feedback_score'] ?? ($existing['feedback_score'] ?? 0)),
            'mentions_count' => $mentions,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%f','%f','%f','%d','%s','%s'];
        if (!empty($existing['id'])) {
            $updated = $wpdb->update(self::nodes_table_name(), $row, ['id' => (int) $existing['id']], $formats, ['%d']);
            return false === $updated ? 0 : (int) $existing['id'];
        }
        $row['created_at'] = $now;
        $inserted = $wpdb->insert(self::nodes_table_name(), $row, array_merge($formats, ['%s']));
        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    public static function upsert_edge($args = []) {
        global $wpdb;
        self::create_tables();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $from = sanitize_text_field((string) ($args['from_node_key'] ?? ''));
        $to = sanitize_text_field((string) ($args['to_node_key'] ?? ''));
        $relation = self::safe_relation_type($args['relation_type'] ?? 'related_to');
        if ($workspace_id <= 0 || $from === '' || $to === '') { return 0; }
        $now = current_time('mysql');
        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::edges_table_name() . ' WHERE workspace_id = %d AND from_node_key = %s AND to_node_key = %s AND relation_type = %s LIMIT 1',
            $workspace_id,
            self::truncate($from, 160),
            self::truncate($to, 160),
            $relation
        ), ARRAY_A);
        $existing_id = is_array($existing) ? (int) ($existing['id'] ?? 0) : 0;
        $summary = self::truncate(sanitize_textarea_field((string) ($args['summary'] ?? '')), 1800);
        if ($summary === '' && is_array($existing) && !empty($existing['summary'])) {
            $summary = self::truncate((string) $existing['summary'], 1800);
        }
        $evidence_refs = self::string_list($args['evidence_refs'] ?? [], 80);
        if (is_array($existing) && empty($args['replace_refs'])) {
            $existing_refs = json_decode((string) ($existing['evidence_refs_json'] ?? '[]'), true);
            $evidence_refs = self::merge_string_lists(is_array($existing_refs) ? $existing_refs : [], $evidence_refs, 80);
        }
        $row = [
            'workspace_id' => $workspace_id,
            'from_node_key' => self::truncate($from, 160),
            'to_node_key' => self::truncate($to, 160),
            'relation_type' => $relation,
            'summary' => $summary,
            'status' => self::safe_status($args['status'] ?? ($existing['status'] ?? 'active')),
            'evidence_refs_json' => wp_json_encode($evidence_refs, JSON_UNESCAPED_UNICODE),
            'confidence_score' => self::normalize_score($args['confidence_score'] ?? ($existing['confidence_score'] ?? 60)),
            'weight' => self::normalize_score($args['weight'] ?? ($existing['weight'] ?? 50)),
            'updated_at' => $now,
        ];
        $formats = ['%d','%s','%s','%s','%s','%s','%s','%f','%f','%s'];
        if ($existing_id > 0) {
            $updated = $wpdb->update(self::edges_table_name(), $row, ['id' => $existing_id], $formats, ['%d']);
            return false === $updated ? 0 : $existing_id;
        }
        $row['created_at'] = $now;
        $inserted = $wpdb->insert(self::edges_table_name(), $row, array_merge($formats, ['%s']));
        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    public static function get_node($workspace_id, $node_key) {
        global $wpdb;
        self::create_tables();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::nodes_table_name() . ' WHERE workspace_id = %d AND node_key = %s LIMIT 1',
            max(0, (int) $workspace_id),
            sanitize_text_field((string) $node_key)
        ), ARRAY_A);
        return is_array($row) ? self::prepare_node($row) : null;
    }

    public static function graph_stats($workspace_id = 0) {
        global $wpdb;
        self::create_tables();
        $workspace_id = max(0, (int) $workspace_id);
        if ($workspace_id > 0) {
            return [
                'nodes' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::nodes_table_name() . ' WHERE workspace_id = %d', $workspace_id)),
                'edges' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::edges_table_name() . ' WHERE workspace_id = %d', $workspace_id)),
                'active_nodes' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::nodes_table_name() . ' WHERE workspace_id = %d AND status IN (\'active\',\'approved\',\'consolidated\',\'pinned\')', $workspace_id)),
                'outdated_nodes' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::nodes_table_name() . ' WHERE workspace_id = %d AND status IN (\'rejected\',\'outdated\',\'superseded\')', $workspace_id)),
            ];
        }
        return [
            'nodes' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::nodes_table_name()),
            'edges' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::edges_table_name()),
        ];
    }

    public static function list_nodes_for_admin($args = []) {
        global $wpdb;
        self::create_tables();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        if ($workspace_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::nodes_table_name() . ' WHERE workspace_id = %d ORDER BY importance_score DESC, updated_at DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::nodes_table_name() . ' ORDER BY updated_at DESC LIMIT %d', $limit), ARRAY_A);
        }
        return array_map([__CLASS__, 'prepare_node'], (array) $rows);
    }

    public static function list_edges_for_admin($args = []) {
        global $wpdb;
        self::create_tables();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        if ($workspace_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::edges_table_name() . ' WHERE workspace_id = %d ORDER BY weight DESC, updated_at DESC LIMIT %d', $workspace_id, $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::edges_table_name() . ' ORDER BY updated_at DESC LIMIT %d', $limit), ARRAY_A);
        }
        return array_map([__CLASS__, 'prepare_edge'], (array) $rows);
    }

    public static function retrieve_for_ai_context($args = [], $limit = 8) {
        global $wpdb;
        self::create_tables();
        $args = is_array($args) ? $args : [];
        $limit = max(0, min(30, (int) $limit));
        if ($limit <= 0) { return ['nodes' => [], 'edges' => [], 'stats' => []]; }
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        if ($workspace_id <= 0) { return ['nodes' => [], 'edges' => [], 'stats' => []]; }
        $query = class_exists('VES_Semantic_Index') ? VES_Semantic_Index::query_text_from_args($args) : '';
        $terms = class_exists('VES_Semantic_Index') ? VES_Semantic_Index::keyword_terms($query, 50) : self::extract_entity_terms($query, 50);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::nodes_table_name() . ' WHERE workspace_id = %d AND status IN (\'active\',\'approved\',\'consolidated\',\'pinned\') ORDER BY updated_at DESC LIMIT 260',
            $workspace_id
        ), ARRAY_A);
        $scored = [];
        foreach ((array) $rows as $row) {
            $node = self::prepare_node($row);
            $node = self::score_node_for_terms($node, $terms);
            if (self::passes_retrieval_gate($node, !empty($terms))) { $scored[] = $node; }
        }
        usort($scored, static function($a, $b) {
            if ((float) ($a['_kg_score'] ?? 0) === (float) ($b['_kg_score'] ?? 0)) {
                return (float) ($a['importance_score'] ?? 0) < (float) ($b['importance_score'] ?? 0) ? 1 : -1;
            }
            return (float) ($a['_kg_score'] ?? 0) < (float) ($b['_kg_score'] ?? 0) ? 1 : -1;
        });
        $nodes = array_slice($scored, 0, $limit);
        $keys = array_values(array_filter(array_map(static function($node) { return (string) ($node['node_key'] ?? ''); }, $nodes)));
        $edges = [];
        if (!empty($keys)) {
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $sql = 'SELECT * FROM ' . self::edges_table_name() . " WHERE workspace_id = %d AND status IN ('active','approved','consolidated','pinned') AND from_node_key IN ({$placeholders}) AND to_node_key IN ({$placeholders}) ORDER BY weight DESC, updated_at DESC LIMIT 40";
            $edge_rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$workspace_id], $keys, $keys)), ARRAY_A);
            $edges = array_slice(array_map([__CLASS__, 'prepare_edge'], (array) $edge_rows), 0, max(0, min(20, $limit * 2)));
        }
        return [
            'nodes' => self::compact_nodes_for_context($nodes),
            'edges' => self::compact_edges_for_context($edges),
            'stats' => [
                'nodes_considered' => count((array) $rows),
                'nodes_used' => count($nodes),
                'edges_used' => count($edges),
                'query_terms_used' => count($terms),
            ],
        ];
    }

    public static function score_node_for_terms($node, $terms = []) {
        $node = is_array($node) ? $node : [];
        $terms = array_values(array_unique(array_filter(array_map('strval', (array) $terms))));
        $relevance = self::relevance_score_for_terms($node, $terms);
        $score = $relevance;
        $score += min(16.0, ((float) ($node['importance_score'] ?? 0)) * 0.16);
        $score += min(10.0, ((float) ($node['confidence_score'] ?? 0)) * 0.10);
        $score += max(-30.0, min(24.0, (float) ($node['feedback_score'] ?? 0)));
        if (($node['status'] ?? '') === 'pinned') { $score += 12.0; }
        if (in_array(($node['node_type'] ?? ''), ['company','market','industry'], true)) { $score += 8.0; }
        $node['_kg_relevance_score'] = round($relevance, 2);
        $node['_kg_score'] = round($score, 2);
        $node['_kg_reason'] = self::score_reason($node, $terms);
        return $node;
    }

    public static function relevance_score_for_terms($node, $terms = []) {
        $node = is_array($node) ? $node : [];
        $terms = array_values(array_unique(array_filter(array_map('strval', (array) $terms))));
        if (empty($terms)) { return 0.0; }
        $label = strtolower((string) ($node['label'] ?? ''));
        $summary = strtolower((string) ($node['summary'] ?? ''));
        $type = strtolower((string) ($node['node_type'] ?? ''));
        $text = trim($label . ' ' . $summary . ' ' . $type);
        $score = 0.0;
        foreach ($terms as $term) {
            $term = strtolower(trim((string) $term));
            if ($term === '') { continue; }
            if ($label === $term || strpos($label, $term) !== false) { $score += 18.0; continue; }
            if (strpos($summary, $term) !== false) { $score += 10.0; continue; }
            if ($type === $term || strpos($type, $term) !== false) { $score += 4.0; continue; }
            $short = strlen($term) > 6 ? substr($term, 0, 6) : '';
            if ($short !== '' && strpos($text, $short) !== false) { $score += 2.0; }
        }
        return round(min(100.0, $score), 2);
    }

    public static function passes_retrieval_gate($node, $has_terms = true) {
        $node = is_array($node) ? $node : [];
        $status = self::safe_status($node['status'] ?? 'active');
        if (in_array($status, ['rejected','archived','superseded','outdated'], true)) { return false; }
        $feedback = (float) ($node['feedback_score'] ?? 0);
        if ($feedback <= -25.0) { return false; }
        $type = self::safe_node_type($node['node_type'] ?? 'concept');
        $is_core = in_array($type, ['company','market','industry'], true);
        if (!$has_terms) {
            return $is_core || $status === 'pinned';
        }
        $relevance = (float) ($node['_kg_relevance_score'] ?? 0);
        if ($is_core && $feedback > -15.0) { return true; }
        if ($status === 'pinned' && $relevance >= 4.0) { return true; }
        return $relevance >= 8.0;
    }

    private static function score_reason($node, $terms) {
        $parts = [];
        if (!empty($terms)) { $parts[] = 'terms=' . count($terms); }
        if ((float) ($node['_kg_relevance_score'] ?? 0) > 0) { $parts[] = 'relevance=' . (float) $node['_kg_relevance_score']; }
        if ((float) ($node['feedback_score'] ?? 0) !== 0.0) { $parts[] = 'feedback=' . (float) $node['feedback_score']; }
        if (in_array(($node['node_type'] ?? ''), ['company','market','industry'], true)) { $parts[] = 'core_context'; }
        return implode('; ', $parts);
    }

    public static function compact_nodes_for_context($nodes, $limit = 12) {
        $out = [];
        foreach (array_slice((array) $nodes, 0, max(1, (int) $limit)) as $node) {
            if (!is_array($node)) { continue; }
            $status = self::safe_status($node['status'] ?? 'active');
            if (in_array($status, ['rejected','archived','superseded','outdated'], true)) { continue; }
            $out[] = [
                'node_key' => (string) ($node['node_key'] ?? ''),
                'node_type' => self::safe_node_type($node['node_type'] ?? 'concept'),
                'label' => self::truncate((string) ($node['label'] ?? ''), 160),
                'summary' => self::truncate((string) ($node['summary'] ?? ''), 400),
                'confidence_score' => (float) ($node['confidence_score'] ?? 0),
                'importance_score' => (float) ($node['importance_score'] ?? 0),
                'evidence_refs' => array_slice((array) ($node['evidence_refs'] ?? []), 0, 8),
            ];
        }
        return $out;
    }

    public static function compact_edges_for_context($edges, $limit = 20) {
        $out = [];
        foreach (array_slice((array) $edges, 0, max(1, (int) $limit)) as $edge) {
            if (!is_array($edge)) { continue; }
            $status = self::safe_status($edge['status'] ?? 'active');
            if (in_array($status, ['rejected','archived','superseded','outdated'], true)) { continue; }
            $out[] = [
                'from' => (string) ($edge['from_node_key'] ?? ''),
                'relation' => self::safe_relation_type($edge['relation_type'] ?? 'related_to'),
                'to' => (string) ($edge['to_node_key'] ?? ''),
                'summary' => self::truncate((string) ($edge['summary'] ?? ''), 300),
                'weight' => (float) ($edge['weight'] ?? 0),
            ];
        }
        return $out;
    }

    public static function extract_entity_terms($text, $limit = 30) {
        $text = wp_strip_all_tags((string) $text);
        $text = preg_replace('/https?:\/\/\S+/i', ' ', $text);
        $parts = preg_split('/[\n,;|]+/', $text);
        $out = [];
        foreach ((array) $parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', (string) $part));
            if ($part === '') { continue; }
            if ((function_exists('mb_strlen') ? mb_strlen($part) : strlen($part)) < 3) { continue; }
            $out[] = self::truncate(sanitize_text_field($part), 120);
        }
        return array_values(array_unique(array_slice($out, 0, max(1, min(100, (int) $limit)))));
    }

    public static function relation_for_profile_field($field) {
        $field = sanitize_key((string) $field);
        $map = [
            'known_products_services' => ['product', 'offers'],
            'products_services' => ['product', 'offers'],
            'competitors' => ['competitor', 'competes_with'],
            'audience_segments' => ['audience', 'serves'],
            'known_strengths' => ['strength', 'has_strength'],
            'known_risks' => ['risk', 'has_risk'],
            'known_opportunities' => ['opportunity', 'has_opportunity'],
            'approved_strategic_learnings' => ['insight', 'has_insight'],
            'rejected_outdated_assumptions' => ['assumption', 'rejects'],
        ];
        return $map[$field] ?? ['concept', 'related_to'];
    }

    public static function prepare_node($row) {
        $source_refs = json_decode((string) ($row['source_refs_json'] ?? '[]'), true);
        $evidence_refs = json_decode((string) ($row['evidence_refs_json'] ?? '[]'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'node_key' => (string) ($row['node_key'] ?? ''),
            'node_type' => (string) ($row['node_type'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'summary' => self::truncate((string) ($row['summary'] ?? ''), 1200),
            'status' => (string) ($row['status'] ?? ''),
            'source_refs' => is_array($source_refs) ? $source_refs : [],
            'evidence_refs' => is_array($evidence_refs) ? $evidence_refs : [],
            'confidence_score' => (float) ($row['confidence_score'] ?? 0),
            'importance_score' => (float) ($row['importance_score'] ?? 0),
            'feedback_score' => (float) ($row['feedback_score'] ?? 0),
            'mentions_count' => (int) ($row['mentions_count'] ?? 0),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public static function prepare_edge($row) {
        $evidence_refs = json_decode((string) ($row['evidence_refs_json'] ?? '[]'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'from_node_key' => (string) ($row['from_node_key'] ?? ''),
            'to_node_key' => (string) ($row['to_node_key'] ?? ''),
            'relation_type' => (string) ($row['relation_type'] ?? ''),
            'summary' => self::truncate((string) ($row['summary'] ?? ''), 900),
            'status' => (string) ($row['status'] ?? ''),
            'evidence_refs' => is_array($evidence_refs) ? $evidence_refs : [],
            'confidence_score' => (float) ($row['confidence_score'] ?? 0),
            'weight' => (float) ($row['weight'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function merge_string_lists($existing, $incoming, $limit = 80) {
        return self::string_list(array_merge((array) $existing, (array) $incoming), $limit);
    }

    private static function string_list($items, $limit = 50) {
        if (is_string($items)) { $items = preg_split('/[\n,]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['ref'] ?? $item['id'] ?? $item['evidence_ref'] ?? $item['source_id'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $item = trim(sanitize_text_field((string) $item));
            if ($item !== '') { $out[] = self::truncate($item, 140); }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    private static function normalize_score($value) {
        return max(0, min(100, is_numeric($value) ? (float) $value : 50.0));
    }

    private static function normalize_feedback_score($value) {
        return max(-60, min(60, is_numeric($value) ? (float) $value : 0.0));
    }

    private static function truncate($value, $max) {
        $value = trim((string) $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) { return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value; }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
