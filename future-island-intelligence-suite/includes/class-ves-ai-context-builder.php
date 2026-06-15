<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Central AI context builder for cumulative workspace/company analysis.
 *
 * Retrieval v3 adds a lightweight knowledge graph and periodic consolidation
 * while intentionally avoiding a required vector DB. Embeddings remain optional/non-blocking.
 */
final class VES_AI_Context_Builder {
    const DEFAULT_RETENTION_DAYS = 7;
    const DEFAULT_MAX_MEMORY_RECORDS = 6;
    const DEFAULT_MAX_APPROVED_INSIGHTS = 6;
    const DEFAULT_MAX_RECENT_RUNS = 4;
    const DEFAULT_MAX_CONTEXT_CHARS = 14000;
    const DEFAULT_MAX_SEMANTIC_MATCHES = 5;
    const DEFAULT_SEMANTIC_MIN_SCORE = 20;
    const DEFAULT_MAX_KNOWLEDGE_GRAPH_ITEMS = 8;

    public static function limits() {
        $settings = class_exists('VES_Config') ? VES_Config::get_settings() : [];
        $retention = defined('VE_SOCIAL_MEMORY_RETENTION_DAYS') ? (int) VE_SOCIAL_MEMORY_RETENTION_DAYS : (int) ($settings['memory_retention_days'] ?? self::DEFAULT_RETENTION_DAYS);
        $max_memory = defined('VE_SOCIAL_AI_CONTEXT_MAX_MEMORY_RECORDS') ? (int) VE_SOCIAL_AI_CONTEXT_MAX_MEMORY_RECORDS : (int) ($settings['ai_context_max_memory_records'] ?? self::DEFAULT_MAX_MEMORY_RECORDS);
        $max_insights = defined('VE_SOCIAL_AI_CONTEXT_MAX_APPROVED_INSIGHTS') ? (int) VE_SOCIAL_AI_CONTEXT_MAX_APPROVED_INSIGHTS : (int) ($settings['ai_context_max_approved_insights'] ?? self::DEFAULT_MAX_APPROVED_INSIGHTS);
        $max_chars = defined('VE_SOCIAL_AI_CONTEXT_MAX_CHARS') ? (int) VE_SOCIAL_AI_CONTEXT_MAX_CHARS : (int) ($settings['ai_context_max_chars'] ?? self::DEFAULT_MAX_CONTEXT_CHARS);
        $semantic_enabled = defined('VE_SOCIAL_SEMANTIC_RETRIEVAL_ENABLED') ? (bool) VE_SOCIAL_SEMANTIC_RETRIEVAL_ENABLED : !empty($settings['semantic_retrieval_enabled']);
        if (!isset($settings['semantic_retrieval_enabled'])) { $semantic_enabled = true; }
        $max_semantic = defined('VE_SOCIAL_AI_CONTEXT_MAX_SEMANTIC_MATCHES') ? (int) VE_SOCIAL_AI_CONTEXT_MAX_SEMANTIC_MATCHES : (int) ($settings['ai_context_max_semantic_matches'] ?? self::DEFAULT_MAX_SEMANTIC_MATCHES);
        $semantic_min_score = defined('VE_SOCIAL_SEMANTIC_MIN_SCORE') ? (int) VE_SOCIAL_SEMANTIC_MIN_SCORE : (int) ($settings['semantic_min_score'] ?? self::DEFAULT_SEMANTIC_MIN_SCORE);
        $kg_enabled = defined('VE_SOCIAL_KNOWLEDGE_GRAPH_ENABLED') ? (bool) VE_SOCIAL_KNOWLEDGE_GRAPH_ENABLED : !empty($settings['knowledge_graph_enabled']);
        if (!isset($settings['knowledge_graph_enabled'])) { $kg_enabled = true; }
        $max_kg = defined('VE_SOCIAL_AI_CONTEXT_MAX_KNOWLEDGE_GRAPH_ITEMS') ? (int) VE_SOCIAL_AI_CONTEXT_MAX_KNOWLEDGE_GRAPH_ITEMS : (int) ($settings['ai_context_max_knowledge_graph_items'] ?? self::DEFAULT_MAX_KNOWLEDGE_GRAPH_ITEMS);
        return [
            'retention_days' => max(1, min(90, $retention)),
            'max_memory_records' => max(0, min(20, $max_memory)),
            'max_approved_insights' => max(0, min(20, $max_insights)),
            'max_recent_runs' => self::DEFAULT_MAX_RECENT_RUNS,
            'max_context_chars' => max(3000, min(50000, $max_chars)),
            'semantic_retrieval_enabled' => (bool) $semantic_enabled,
            'max_semantic_matches' => max(0, min(20, $max_semantic)),
            'semantic_min_score' => max(0, min(200, $semantic_min_score)),
            'knowledge_graph_enabled' => (bool) $kg_enabled,
            'max_knowledge_graph_items' => max(0, min(30, $max_kg)),
        ];
    }

    public static function build($args = []) {
        $args = is_array($args) ? $args : [];
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($args, ['user_id' => $user_id]), $user_id) : $user_id;
        $limits = self::limits();

        $profile = class_exists('VES_Workspace_Profile') ? VES_Workspace_Profile::get_profile($workspace_id) : null;
        $legacy_project_context = null;
        if (class_exists('VES_Project_Context') && $user_id > 0) {
            $legacy_project_context = VES_Project_Context::get_context_summary($user_id);
            if ((!is_array($profile) || empty($profile['company_name'])) && !empty($legacy_project_context)) {
                $profile = self::profile_from_project_context($legacy_project_context, $workspace_id, $user_id);
            }
        }

        $approved_insights = [];
        if ($limits['max_approved_insights'] > 0 && class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'retrieve_for_ai_context')) {
            $approved_insights = VES_Insight_Records::retrieve_for_ai_context(array_merge($args, [
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'statuses' => ['approved','accepted','saved'],
            ]), $limits['max_approved_insights']);
        }
        $approved_insights = self::filter_insights_for_context($approved_insights);

        $recent_memory = [];
        if ($limits['max_memory_records'] > 0 && class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'retrieve_context_pack')) {
            $recent_memory = VES_Memory_Records::retrieve_context_pack(array_merge($args, [
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
            ]), $limits['max_memory_records']);
        }

        $operational_memory = [];
        if (class_exists('VES_Temp_Memory') && method_exists('VES_Temp_Memory', 'get_related_memory_context')) {
            $operational_memory = VES_Temp_Memory::get_related_memory_context($args, $limits['max_recent_runs']);
        }

        $semantic_seed = [];
        $semantic_matches = [];
        if (!empty($limits['semantic_retrieval_enabled']) && $limits['max_semantic_matches'] > 0 && class_exists('VES_Semantic_Index')) {
            $semantic_seed = VES_Semantic_Index::seed_from_context($workspace_id, $user_id, is_array($profile) ? $profile : [], $approved_insights, $recent_memory);
            $semantic_matches = VES_Semantic_Index::retrieve(array_merge($args, [
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
            ]), $limits['max_semantic_matches']);
            $semantic_matches = self::filter_semantic_matches_for_context($semantic_matches, (float) $limits['semantic_min_score']);
        }

        $knowledge_graph = ['nodes' => [], 'edges' => [], 'stats' => []];
        if (!empty($limits['knowledge_graph_enabled']) && $limits['max_knowledge_graph_items'] > 0 && class_exists('VES_Knowledge_Graph')) {
            $knowledge_graph = VES_Knowledge_Graph::retrieve_for_ai_context(array_merge($args, [
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
            ]), $limits['max_knowledge_graph_items']);
        }

        $excluded = [];
        if (is_array($profile) && !empty($profile['rejected_outdated_assumptions'])) {
            $excluded = array_merge($excluded, (array) $profile['rejected_outdated_assumptions']);
        }
        if (class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'retrieve_excluded_for_ai_context')) {
            $excluded = array_merge($excluded, VES_Insight_Records::retrieve_excluded_for_ai_context(array_merge($args, ['workspace_id' => $workspace_id, 'user_id' => $user_id]), 8));
        }
        $excluded = self::sanitize_list($excluded, 12, 220);

        $profile_context = self::profile_context($profile, $legacy_project_context);
        $project_context = [
            'project_profile' => $profile_context,
            'available_fields' => array_keys(array_filter($profile_context, static function($value) { return $value !== '' && $value !== [] && $value !== null; })),
            'has_profile' => !empty(array_filter($profile_context)),
            'workspace_knowledge' => self::workspace_knowledge_items($profile, $approved_insights, $recent_memory),
            'knowledge_graph' => $knowledge_graph,
            'workspace_knowledge_count' => count($approved_insights) + count($recent_memory) + count($semantic_matches) + count((array) ($knowledge_graph['nodes'] ?? [])),
            'excluded_rejected_assumptions' => $excluded,
            'memory_policy' => [
                'retention_days' => $limits['retention_days'],
                'approved_insights_only_strongly_influence' => true,
                'rejected_assumptions_must_not_be_reused' => true,
                'semantic_retrieval_is_feedback_weighted' => !empty($limits['semantic_retrieval_enabled']),
                'knowledge_graph_is_consolidated_memory' => !empty($limits['knowledge_graph_enabled']),
            ],
        ];
        if (!empty($profile_context['website_url'])) {
            $host = wp_parse_url($profile_context['website_url'], PHP_URL_HOST);
            if (is_string($host) && $host !== '') { $project_context['website_domain'] = strtolower($host); }
        }

        $related_memory = [];
        if (!empty($approved_insights)) {
            $related_memory[] = [
                'entry_type' => 'approved_insight_ledger',
                'platform' => sanitize_key((string) ($args['platform'] ?? 'workspace')),
                'title' => 'Approved insight ledger',
                'summary' => 'Approved/pinned insights that may influence this analysis.',
                'records' => $approved_insights,
            ];
        }
        if (!empty($recent_memory)) {
            $related_memory[] = [
                'entry_type' => 'persistent_workspace_memory',
                'platform' => sanitize_key((string) ($args['platform'] ?? 'workspace')),
                'title' => 'Recent workspace memory',
                'summary' => 'Recent operational memory, capped and filtered by workspace/source context.',
                'records' => $recent_memory,
            ];
        }
        if (!empty($knowledge_graph['nodes']) || !empty($knowledge_graph['edges'])) {
            $related_memory[] = [
                'entry_type' => 'knowledge_graph_context',
                'platform' => 'workspace',
                'title' => 'Knowledge graph context',
                'summary' => 'Consolidated entities and relationships from profile, approved insights, and recurring memory. Treat as durable workspace knowledge, not fresh evidence.',
                'records' => array_merge((array) ($knowledge_graph['nodes'] ?? []), (array) ($knowledge_graph['edges'] ?? [])),
            ];
        }
        if (!empty($semantic_matches)) {
            $related_memory[] = [
                'entry_type' => 'semantic_retrieval_matches',
                'platform' => sanitize_key((string) ($args['platform'] ?? 'workspace')),
                'title' => 'Semantic memory matches',
                'summary' => 'Feedback-weighted semantic-lite matches. Treat as historical context, not fresh evidence.',
                'records' => $semantic_matches,
            ];
        }
        foreach ((array) $operational_memory as $packet) {
            if (is_array($packet)) { $related_memory[] = $packet; }
        }
        if (!empty($excluded)) {
            $related_memory[] = [
                'entry_type' => 'excluded_assumptions',
                'platform' => 'workspace',
                'title' => 'Rejected or outdated assumptions',
                'summary' => implode(' | ', $excluded),
                'records' => array_map(static function($item) { return ['title' => 'Do not reuse', 'summary' => $item, 'status' => 'rejected']; }, $excluded),
            ];
        }

        $capped_related_memory = self::cap_related_memory($related_memory, $limits);
        $included_counts = self::count_included_context($capped_related_memory);

        $pack = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'project_context' => $project_context,
            'related_memory' => $capped_related_memory,
            'diagnostics' => [
                'profile_present' => !empty($profile_context),
                'approved_insights_used' => (int) ($included_counts['approved_insights'] ?? 0),
                'recent_memory_used' => (int) ($included_counts['recent_memory'] ?? 0),
                'operational_packets_used' => (int) ($included_counts['operational_packets'] ?? 0),
                'semantic_matches_used' => (int) ($included_counts['semantic_matches'] ?? 0),
                'knowledge_graph_nodes_used' => (int) ($included_counts['knowledge_graph_nodes'] ?? 0),
                'knowledge_graph_edges_used' => (int) ($included_counts['knowledge_graph_edges'] ?? 0),
                'knowledge_graph_stats' => $knowledge_graph['stats'] ?? [],
                'semantic_seeded' => $semantic_seed,
                'feedback_weighting_enabled' => !empty($limits['semantic_retrieval_enabled']),
                'knowledge_graph_enabled' => !empty($limits['knowledge_graph_enabled']),
                'excluded_assumptions_used' => count($excluded),
                'estimated_context_chars' => 0,
                'limits' => $limits,
            ],
        ];
        $pack['diagnostics']['estimated_context_chars'] = strlen(wp_json_encode([
            'project_context' => $pack['project_context'],
            'related_memory' => $pack['related_memory'],
        ], JSON_UNESCAPED_UNICODE));
        $pack['used_context'] = self::summarize_used_context($pack);
        return $pack;
    }

    public static function summarize_used_context($pack) {
        $diag = is_array($pack['diagnostics'] ?? null) ? $pack['diagnostics'] : [];
        $parts = [];
        if (!empty($diag['profile_present'])) { $parts[] = 'company profile'; }
        $approved = (int) ($diag['approved_insights_used'] ?? 0);
        if ($approved > 0) { $parts[] = $approved . ' approved insight' . ($approved === 1 ? '' : 's'); }
        $recent = (int) ($diag['recent_memory_used'] ?? 0) + (int) ($diag['operational_packets_used'] ?? 0);
        if ($recent > 0) { $parts[] = $recent . ' recent memory item' . ($recent === 1 ? '' : 's'); }
        $semantic = (int) ($diag['semantic_matches_used'] ?? 0);
        if ($semantic > 0) { $parts[] = $semantic . ' semantic match' . ($semantic === 1 ? '' : 'es'); }
        $kg_nodes = (int) ($diag['knowledge_graph_nodes_used'] ?? 0);
        if ($kg_nodes > 0) { $parts[] = $kg_nodes . ' graph node' . ($kg_nodes === 1 ? '' : 's'); }
        $excluded = (int) ($diag['excluded_assumptions_used'] ?? 0);
        if ($excluded > 0) { $parts[] = $excluded . ' rejected/outdated assumption' . ($excluded === 1 ? '' : 's'); }
        return 'Used context: ' . (!empty($parts) ? implode(' + ', $parts) : 'current request only') . '.';
    }

    public static function filter_semantic_matches_for_context($matches, $min_score = 0) {
        $out = [];
        foreach ((array) $matches as $match) {
            if (!is_array($match)) { continue; }
            $status = sanitize_key((string) ($match['status'] ?? ''));
            if (in_array($status, ['rejected','archived','superseded','proposed'], true)) { continue; }
            if (sanitize_key((string) ($match['object_type'] ?? '')) === 'profile') { continue; }
            if ((float) ($match['feedback_score'] ?? 0) <= -36 && $status !== 'pinned') { continue; }
            if ((float) ($match['semantic_score'] ?? 0) < (float) $min_score) { continue; }
            if (array_key_exists('semantic_relevance_score', $match) && (float) ($match['semantic_relevance_score'] ?? 0) <= 0) { continue; }
            $out[] = $match;
        }
        return $out;
    }

    public static function filter_insights_for_context($insights) {
        $out = [];
        foreach ((array) $insights as $insight) {
            if (!is_array($insight)) { continue; }
            $status = sanitize_key((string) ($insight['status'] ?? ''));
            if (in_array($status, ['rejected','archived','superseded'], true)) { continue; }
            if (!in_array($status, ['approved','accepted','saved'], true)) { continue; }
            $out[] = $insight;
        }
        return $out;
    }

    public static function writeback_after_analysis($args = []) {
        $args = is_array($args) ? $args : [];
        $analysis_text = trim(wp_strip_all_tags((string) ($args['analysis'] ?? '')));
        if ($analysis_text === '' || strlen($analysis_text) < 80) { return ['memory_id' => 0, 'insight_id' => 0]; }
        $user_id = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($args, ['user_id' => $user_id]), $user_id) : $user_id;
        if ($workspace_id <= 0 || $user_id <= 0) { return ['memory_id' => 0, 'insight_id' => 0]; }
        $platform = sanitize_key((string) ($args['platform'] ?? 'ai_analysis'));
        $source_id = sanitize_text_field((string) ($args['source_id'] ?? ($args['request_id'] ?? md5($analysis_text))));
        $title = sanitize_text_field((string) ($args['title'] ?? ('AI analysis: ' . $platform)));
        $summary = self::first_sentences($analysis_text, 700);
        $memory_id = 0;
        if (class_exists('VES_Memory_Records')) {
            $memory_id = (int) VES_Memory_Records::save_record([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'brand_name' => sanitize_text_field((string) ($args['brand_name'] ?? '')),
                'memory_type' => 'analysis_output',
                'title' => $title,
                'summary' => $summary,
                'content' => [
                    'platform' => $platform,
                    'focus' => sanitize_textarea_field((string) ($args['focus'] ?? '')),
                    'used_context' => sanitize_text_field((string) ($args['used_context'] ?? '')),
                    'evidence_refs' => self::sanitize_list($args['evidence_refs'] ?? [], 20, 120),
                ],
                'source_type' => $platform,
                'source_id' => $source_id,
                'tags' => array_filter([$platform, 'analysis_output', $args['brand_name'] ?? '']),
                'importance_score' => 58,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::limits()['retention_days'] * DAY_IN_SECONDS),
                'dedupe' => true,
            ]);
        }
        $insight_id = 0;
        if (class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'save_proposed_insight')) {
            $saved = VES_Insight_Records::save_proposed_insight([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'run_id' => $source_id,
                'title' => $title,
                'summary' => $summary,
                'insight_type' => 'hypothesis',
                'status' => 'proposed',
                'confidence' => 0.45,
                'evidence_refs' => self::sanitize_list($args['evidence_refs'] ?? [], 20, 120),
                'meta' => ['memory_id' => $memory_id, 'platform' => $platform, 'source' => 'ai_writeback'],
            ]);
            $insight_id = is_wp_error($saved) ? 0 : (int) ($saved['id'] ?? 0);
        }
        if (class_exists('VES_Semantic_Index') && $memory_id > 0) {
            VES_Semantic_Index::upsert_item([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'object_type' => 'memory',
                'object_id' => (string) $memory_id,
                'status' => 'recent',
                'title' => $title,
                'summary' => $summary,
                'source_family' => $platform,
                'platform' => $platform,
                'evidence_refs' => self::sanitize_list($args['evidence_refs'] ?? [], 20, 120),
                'importance_score' => 58,
            ]);
        }
        if (class_exists('VES_Semantic_Index') && $insight_id > 0) {
            VES_Semantic_Index::upsert_item([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'object_type' => 'insight',
                'object_id' => (string) $insight_id,
                'status' => 'proposed',
                'title' => $title,
                'summary' => $summary,
                'source_family' => 'insight_ledger',
                'platform' => $platform,
                'evidence_refs' => self::sanitize_list($args['evidence_refs'] ?? [], 20, 120),
                'importance_score' => 45,
            ]);
        }
        return ['memory_id' => $memory_id, 'insight_id' => $insight_id];
    }

    private static function profile_from_project_context($context, $workspace_id, $user_id) {
        $profile = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'company_name' => $context['brand_name'] ?? '',
            'website_url' => $context['website_url'] ?? '',
            'market_country' => $context['markets'] ?? '',
            'brand_positioning' => $context['brand_summary'] ?? '',
            'known_products_services' => $context['products_services'] ?? '',
            'audience_segments' => $context['target_audience'] ?? '',
            'competitors' => $context['competitors'] ?? '',
            'tone_style_guidance' => $context['tone_of_voice'] ?? '',
            'approved_strategic_learnings' => $context['notes'] ?? '',
        ];
        if (class_exists('VES_Workspace_Profile')) {
            $saved = VES_Workspace_Profile::upsert_from_project_context($workspace_id, $user_id, $context, 'project_context');
            if (!is_wp_error($saved) && is_array($saved)) { return $saved; }
        }
        return $profile;
    }

    private static function profile_context($profile, $legacy_context = null) {
        $profile = is_array($profile) ? $profile : [];
        $legacy_context = is_array($legacy_context) ? $legacy_context : [];
        $context = [
            'brand_name' => $profile['company_name'] ?? ($legacy_context['brand_name'] ?? ''),
            'company_name' => $profile['company_name'] ?? ($legacy_context['brand_name'] ?? ''),
            'website_url' => $legacy_context['website_url'] ?? '',
            'market_country' => $profile['market_country'] ?? ($legacy_context['markets'] ?? ''),
            'industry' => $profile['industry'] ?? '',
            'brand_positioning' => $profile['brand_positioning'] ?? ($legacy_context['brand_summary'] ?? ''),
            'known_products_services' => $profile['known_products_services'] ?? self::sanitize_list($legacy_context['products_services'] ?? []),
            'competitors' => $profile['competitors'] ?? self::sanitize_list($legacy_context['competitors'] ?? []),
            'audience_segments' => $profile['audience_segments'] ?? self::sanitize_list($legacy_context['target_audience'] ?? []),
            'known_strengths' => $profile['known_strengths'] ?? [],
            'known_risks' => $profile['known_risks'] ?? [],
            'known_opportunities' => $profile['known_opportunities'] ?? [],
            'tone_style_guidance' => $profile['tone_style_guidance'] ?? ($legacy_context['tone_of_voice'] ?? ''),
            'approved_strategic_learnings' => $profile['approved_strategic_learnings'] ?? self::sanitize_list($legacy_context['notes'] ?? []),
            'rejected_outdated_assumptions' => $profile['rejected_outdated_assumptions'] ?? [],
            'last_updated_at' => $profile['updated_at'] ?? '',
            'source_refs' => $profile['source_refs'] ?? [],
        ];
        return $context;
    }

    private static function workspace_knowledge_items($profile, $approved_insights, $recent_memory) {
        $items = [];
        if (is_array($profile) && !empty($profile['approved_strategic_learnings'])) {
            foreach (array_slice((array) $profile['approved_strategic_learnings'], 0, 6) as $learning) {
                $items[] = ['title' => 'Approved strategic learning', 'summary' => sanitize_textarea_field((string) $learning), 'status' => 'approved'];
            }
        }
        foreach (array_slice((array) $approved_insights, 0, 8) as $insight) {
            $items[] = [
                'title' => sanitize_text_field((string) ($insight['title'] ?? 'Approved insight')),
                'summary' => sanitize_textarea_field((string) ($insight['summary'] ?? '')),
                'status' => sanitize_key((string) ($insight['status'] ?? 'approved')),
                'source_type' => 'insight_ledger',
                'source_id' => (string) ($insight['id'] ?? ''),
            ];
        }
        foreach (array_slice((array) $recent_memory, 0, 6) as $record) {
            $items[] = [
                'title' => sanitize_text_field((string) ($record['title'] ?? 'Memory')),
                'summary' => sanitize_textarea_field((string) ($record['summary'] ?? '')),
                'status' => !empty($record['is_pinned']) ? 'pinned' : 'recent',
                'source_type' => sanitize_key((string) ($record['source_type'] ?? 'memory')),
                'source_id' => (string) ($record['id'] ?? ''),
            ];
        }
        return array_slice($items, 0, 16);
    }


    private static function count_included_context($packets) {
        $counts = [
            'approved_insights' => 0,
            'recent_memory' => 0,
            'operational_packets' => 0,
            'semantic_matches' => 0,
            'knowledge_graph_nodes' => 0,
            'knowledge_graph_edges' => 0,
        ];
        foreach ((array) $packets as $packet) {
            if (!is_array($packet)) { continue; }
            $type = sanitize_key((string) ($packet['entry_type'] ?? ''));
            $records = is_array($packet['records'] ?? null) ? $packet['records'] : [];
            if ($type === 'approved_insight_ledger') { $counts['approved_insights'] += count($records); }
            elseif ($type === 'persistent_workspace_memory') { $counts['recent_memory'] += count($records); }
            elseif ($type === 'semantic_retrieval_matches') { $counts['semantic_matches'] += count($records); }
            elseif ($type === 'knowledge_graph_context') {
                foreach ($records as $record) {
                    if (!is_array($record)) { continue; }
                    if (isset($record['node_type']) || isset($record['node_key']) || isset($record['label'])) { $counts['knowledge_graph_nodes']++; }
                    elseif (isset($record['relation']) || isset($record['from']) || isset($record['to'])) { $counts['knowledge_graph_edges']++; }
                }
            } elseif ($type !== 'excluded_assumptions') {
                $counts['operational_packets']++;
            }
        }
        return $counts;
    }

    private static function cap_related_memory($packets, $limits) {
        $packets = array_values(array_filter((array) $packets, 'is_array'));
        $max_chars = max(1000, (int) ($limits['max_context_chars'] ?? self::DEFAULT_MAX_CONTEXT_CHARS));
        $out = [];
        $chars = 0;
        foreach ($packets as $packet) {
            $packet = self::compact_packet_for_context($packet);
            if (empty($packet)) { continue; }
            $encoded = wp_json_encode($packet, JSON_UNESCAPED_UNICODE);
            $len = strlen((string) $encoded);
            while ($len > $max_chars && !empty($packet['records'])) {
                array_pop($packet['records']);
                $encoded = wp_json_encode($packet, JSON_UNESCAPED_UNICODE);
                $len = strlen((string) $encoded);
            }
            if ($len > $max_chars) { continue; }
            if ($chars + $len > $max_chars) { break; }
            $out[] = $packet;
            $chars += $len;
            if (count($out) >= 8) { break; }
        }
        return $out;
    }

    private static function compact_packet_for_context($packet) {
        if (!is_array($packet)) { return []; }
        $out = [
            'entry_type' => sanitize_key((string) ($packet['entry_type'] ?? 'memory_context')),
            'platform' => sanitize_key((string) ($packet['platform'] ?? 'workspace')),
            'title' => self::truncate(sanitize_text_field((string) ($packet['title'] ?? $packet['report_title'] ?? 'Memory context')), 180),
            'summary' => self::truncate(sanitize_textarea_field((string) ($packet['summary'] ?? '')), 500),
            'records' => [],
        ];
        foreach (array_slice((array) ($packet['records'] ?? []), 0, 8) as $record) {
            $record = self::compact_record_for_context($record);
            if (!empty($record)) { $out['records'][] = $record; }
        }
        // Sprint 2 (Part B): attach deterministic, internal claim-readiness
        // metadata for the packet's evidence bundle. The compacted records
        // already carry Sprint 1 evidence-quality metadata, so this only reads
        // them. No prompt change, no render change, evidence_refs untouched.
        if (!empty($out['records']) && class_exists('VES_Claim_Readiness')) {
            $readiness = VES_Claim_Readiness::assess($out['records']);
            $out['claim_readiness'] = $readiness['claim_readiness'];
            $out['claim_strength_score'] = $readiness['claim_strength_score'];
            $out['claim_limitations'] = $readiness['claim_limitations'];
            $out['recommended_language'] = $readiness['recommended_language'];
            $out['minimum_required_action'] = $readiness['minimum_required_action'];
        }
        return $out;
    }

    private static function compact_record_for_context($record) {
        if (!is_array($record)) { return []; }
        $allowed = [
            'title','summary','status','platform','source_type','source_id','object_type','object_id',
            'node_type','node_key','label','from','relation','to','confidence_score','importance_score',
            'semantic_score','semantic_relevance_score','weight','evidence_refs'
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $record)) { continue; }
            $value = $record[$key];
            if ($key === 'evidence_refs') {
                $out[$key] = self::sanitize_list($value, 8, 120);
            } elseif (is_numeric($value)) {
                $out[$key] = (float) $value;
            } elseif (is_scalar($value)) {
                $max = in_array($key, ['summary','label'], true) ? 700 : 180;
                $out[$key] = self::truncate(sanitize_textarea_field((string) $value), $max);
            }
        }
        if (empty($out['title']) && !empty($out['label'])) { $out['title'] = $out['label']; }
        if (empty($out['summary']) && !empty($out['title'])) { $out['summary'] = $out['title']; }
        // Sprint 1 (Part C): attach deterministic, internal evidence-quality
        // metadata to the structured context only. No prompt change, no render
        // change; existing fields (including evidence_refs) are left untouched.
        if (class_exists('VES_Evidence_Quality')) {
            $quality = VES_Evidence_Quality::classify($record);
            $out['evidence_quality_tier'] = $quality['evidence_quality_tier'];
            $out['evidence_kind'] = $quality['evidence_kind'];
            $out['evidence_limitations'] = $quality['evidence_limitations'];
        }
        return $out;
    }

    private static function sanitize_list($items, $limit = 20, $max_len = 180) {
        if (is_string($items)) { $items = preg_split('/[\n,;]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['summary'] ?? $item['title'] ?? $item['name'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $item = sanitize_textarea_field((string) $item);
            $item = self::truncate($item, $max_len);
            if ($item !== '') { $out[] = $item; }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    private static function first_sentences($text, $max = 700) {
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = trim((string) $text);
        if ($text === '') { return ''; }
        if ((function_exists('mb_strlen') ? mb_strlen($text) : strlen($text)) <= $max) { return $text; }
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text);
        $out = '';
        foreach ((array) $sentences as $sentence) {
            $candidate = trim($out . ' ' . $sentence);
            if ((function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate)) > $max) { break; }
            $out = $candidate;
        }
        return $out !== '' ? $out : self::truncate($text, $max);
    }

    private static function truncate($value, $max) {
        $value = trim((string) $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
