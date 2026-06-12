<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Report_Service {
    // Compatibility marker: Professional evidence synthesis. Legacy schema marker: 'schema_version' => 'fidtf.report.v3.2'.
    public static function build_or_get_report(int $run_id, array $run = [], bool $force = false): array {
        if (!$force) {
            $existing = self::get_latest_report($run_id);
            if (!empty($existing)) {
                $decoded = json_decode((string) ($existing['final_synthesis_json'] ?? ''), true);
                if (is_array($decoded)) {
                    $cached_version = sanitize_text_field((string) ($decoded['addon_version'] ?? ''));
                    if ($cached_version === (defined('FIDTF_VERSION') ? FIDTF_VERSION : '')) {
                        $decoded['report_id'] = (int) ($existing['id'] ?? 0);
                        $decoded['from_cache'] = true;
                        return $decoded;
                    }
                }
            }
        }
        return self::build_report($run_id, $run);
    }

    public static function get_latest_report(int $run_id): array {
        global $wpdb;
        if ($run_id <= 0 || !self::db_ready()) { return []; }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . FIDTF_DB::table('reports') . ' WHERE run_id = %d AND report_type = %s ORDER BY id DESC LIMIT 1',
                $run_id,
                'final_synthesis'
            ),
            (defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A')
        );
        return is_array($row) ? $row : [];
    }

    public static function build_report(int $run_id, array $run = []): array {
        // Phase 1 A2/A3: bind request context so any AI call in this run is attributed,
        // and run an advisory (non-blocking) preflight estimate before synthesis.
        // Wrapped in try/finally so ambient context can never leak on an exception path.
        $ws_id = (int) ($run['workspace_id'] ?? 0);
        $usr_id = (int) ($run['user_id'] ?? 0);
        $has_tracker = class_exists('VES_AI_Usage_Tracker');
        if ($has_tracker) {
            VES_AI_Usage_Tracker::set_context([
                'module' => 'deep_trend_finder', 'operation_type' => 'trend_analysis',
                'workspace_id' => $ws_id, 'user_id' => $usr_id, 'run_id' => (string) $run_id,
            ]);
            if (method_exists('VES_AI_Usage_Tracker', 'preflight')) {
                try {
                    VES_AI_Usage_Tracker::preflight([
                        'module' => 'deep_trend_finder', 'operation_type' => 'trend_analysis',
                        'workspace_id' => $ws_id, 'user_id' => $usr_id, 'run_id' => (string) $run_id,
                        'model' => class_exists('VES_Config') ? VES_Config::get_deep_analysis_model() : 'gpt-4.1-mini',
                        'input_text' => (string) ($run['focus'] ?? $run['query'] ?? ''), 'max_output_tokens' => 3200,
                        'record_estimate' => true, // Fix 2: persist the estimate as a usage row.
                    ]);
                } catch (\Throwable $e) { /* advisory only */ }
            }
        }

        try {
            $jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
            $items = self::decode_items(FIDTF_Source_Job_Service::get_items($run_id, true, ['per_page' => 200, 'internal' => true]));
            $source_summary = self::source_summary($jobs, $items);
            $report = self::local_synthesis($run_id, $run, $source_summary, $items);

            $external = apply_filters('fidtf_final_synthesis', null, $report, $items, $run);
            if (is_array($external)) {
                $sanitized_external = self::sanitize_external_report($external);
                if (!empty($sanitized_external)) {
                    $report = array_merge($report, $sanitized_external);
                    $report['synthesis_mode'] = 'external_model_bridge';
                } else {
                    $report['warnings'][] = 'External synthesis bridge returned no supported report fields. Local deterministic synthesis was kept.';
                }
            }

            $report_id = self::store_report($run_id, $source_summary, $report);
            if ($report_id > 0) {
                $report['report_id'] = $report_id;
                FIDTF_Memory_Bridge::remember_report($run_id, $report);
            }

            // Phase 2 (B6): additively project this report into the canonical intelligence
            // contract. Best-effort and fully isolated — never alters the response.
            self::write_intelligence_records($run_id, $run, $report);

            $report['from_cache'] = false;
            return $report;
        } finally {
            if ($has_tracker) { VES_AI_Usage_Tracker::clear_context(); }
        }
    }

    /**
     * Phase 2 (B6) — map a Deep Trend Finder report into the canonical
     * Source → Signal → Evidence → Insight → Draft spine. Additive only:
     * wrapped so a mapping/storage failure can never affect report delivery.
     */
    private static function write_intelligence_records(int $run_id, array $run, array $report): void {
        if (!class_exists('VES_Intelligence_Store')) { return; }
        try {
            $workspace_id = (int) ($run['workspace_id'] ?? 0);
            $user_id = (int) ($run['user_id'] ?? 0);
            if ($workspace_id <= 0) { return; } // tenant isolation required; skip quietly

            $title = (string) ($report['report_title'] ?? $report['status_label'] ?? ('Deep Trend run #' . $run_id));
            $summary = (string) ($report['executive_summary'] ?? '');
            $model = (string) ($report['analysis_model'] ?? ($report['synthesis_mode'] ?? ''));

            $observed_at = gmdate('Y-m-d H:i:s');
            $signals = [];
            foreach (array_slice(self::flatten_terms($report['top_terms'] ?? []), 0, 5) as $term) {
                // Phase 3 provenance: extraction method + source provider on each signal.
                $signals[] = ['signal_type' => 'trend', 'title' => $term, 'confidence_score' => 50,
                    'source_provider' => 'apify', 'extraction_method' => 'deterministic_synthesis', 'occurred_at' => $observed_at];
            }
            $evidence = [];
            foreach (array_slice(self::flatten_terms($report['top_hashtags'] ?? []), 0, 3) as $tag) {
                $evidence[] = ['evidence_type' => 'observation', 'text' => 'Recurring signal: ' . $tag,
                    'observed_at' => $observed_at, 'metadata' => ['source_provider' => 'apify']];
            }

            // Phase 3B Part B: backlink to the scrape usage events recorded for this run.
            $scrape_uids = (class_exists('FIDTF_Source_Job_Service') && method_exists('FIDTF_Source_Job_Service', 'get_scrape_usage_events'))
                ? FIDTF_Source_Job_Service::get_scrape_usage_events($run_id) : [];
            $primary_usage_event_id = !empty($scrape_uids) ? (int) $scrape_uids[0] : 0;

            $bundle = [
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'usage_event_id' => $primary_usage_event_id, // applied to source/signal/evidence metadata
                'source' => [
                    'source_type' => 'social', 'provider' => 'apify',
                    'source_title' => substr($title, 0, 255), 'external_id' => 'fidtf_run_' . $run_id,
                    // Phase 3 provenance: retrieval method + dedup hashing (auto in normalize_source).
                    'retrieval_method' => 'apify_scrape',
                    'metadata' => ['run_id' => $run_id, 'report_id' => (int) ($report['report_id'] ?? 0), 'scrape_usage_event_ids' => $scrape_uids],
                ],
                'signals' => $signals,
                'evidence' => $evidence,
                'insight' => [
                    'insight_type' => 'trend', 'title' => substr($title, 0, 255),
                    'summary' => $summary, 'status' => 'draft',
                    'confidence_score' => is_numeric($report['confidence'] ?? null) ? (float) $report['confidence'] : 0,
                ],
            ];
            if ($summary !== '') {
                $bundle['draft'] = ['draft_type' => 'report_section', 'body' => $summary, 'model' => $model];
            }

            $ids = VES_Intelligence_Store::ingest_bundle($bundle);
            if (class_exists('VES_Log')) {
                VES_Log::info('deep_trend_finder', 'intelligence_records_written', [
                    'run_id' => $run_id, 'workspace_id' => $workspace_id,
                    'operation_type' => 'trend_analysis',
                ]);
            }
            // Phase 4: deterministic trend engine (additive, isolated below).
            self::write_trend_intelligence($run_id, $workspace_id, $user_id, $report, is_array($ids) ? $ids : []);
            unset($ids);
        } catch (\Throwable $e) {
            if (class_exists('VES_Log')) { VES_Log::warn('deep_trend_finder', 'intelligence_records_failed', ['run_id' => $run_id, 'error_code' => 'intel_write_failed']); }
        }
    }

    /**
     * Phase 4 (Part F) — deterministic trend engine, run additively after the
     * canonical bundle is written. Extracts observations from the report's top
     * terms/hashtags, builds + scores per-term series, upserts trend records, and
     * creates a DRAFT trend insight for high-confidence, evidence-backed trends.
     * Fully isolated: any failure is logged and NEVER affects report delivery.
     * Default classification threshold via the `fidtf_trend_min_score` filters.
     */
    private static function write_trend_intelligence(int $run_id, int $workspace_id, int $user_id, array $report, array $bundle_ids): void {
        if ($workspace_id <= 0 || !class_exists('VES_Trend_Observation_Store') || !class_exists('VES_Trend_Scorer')) { return; }
        try {
            $source_ids = ($source_id = (int) ($bundle_ids['source_id'] ?? 0)) > 0 ? [$source_id] : [];
            $evidence_ids = is_array($bundle_ids['evidence_ids'] ?? null) ? array_map('intval', $bundle_ids['evidence_ids']) : [];
            $signal_ids = is_array($bundle_ids['signal_ids'] ?? null) ? array_map('intval', $bundle_ids['signal_ids']) : [];
            $now = gmdate('Y-m-d H:i:s');

            // Phase 4B: insight gate uses the calibrated thresholds (filterable via
            // ves_trend_scorer_thresholds); the legacy fidtf_trend_min_* filters still win if set.
            $th = class_exists('VES_Trend_Scorer') ? VES_Trend_Scorer::get_thresholds() : ['min_score_for_insight' => 60, 'min_confidence_for_insight' => 60];
            $min_score = (float) (function_exists('apply_filters') ? apply_filters('fidtf_trend_min_score', $th['min_score_for_insight']) : $th['min_score_for_insight']);
            $min_conf  = (float) (function_exists('apply_filters') ? apply_filters('fidtf_trend_min_confidence', $th['min_confidence_for_insight']) : $th['min_confidence_for_insight']);

            $entries = array_merge(
                self::trend_term_entries($report['top_terms'] ?? [], 'keyword'),
                self::trend_term_entries($report['top_hashtags'] ?? [], 'hashtag')
            );

            $created = 0; $insights = 0;
            foreach ($entries as $entry) {
                [$term, $count, $type] = $entry;
                if ($term === '') { continue; }
                // 1) Record one observation for this run.
                $obs_id = VES_Trend_Observation_Store::create_or_get_observation([
                    'workspace_id' => $workspace_id, 'run_id' => (string) $run_id,
                    'observation_type' => $type, 'term' => $term,
                    'platform' => '', 'provider' => 'apify', 'observed_at' => $now,
                    'value_number' => $count, 'raw_count' => (int) $count,
                    'source_id' => $source_id,
                ]);
                if (self::is_wp_error_local($obs_id)) { continue; }
                $normalized = VES_Trend_Observation_Store::normalize_term($term, $type);

                // 2) Build + score the historical series for this term.
                $series = class_exists('VES_Trend_Series_Builder')
                    ? VES_Trend_Series_Builder::build_series([
                        'workspace_id' => $workspace_id, 'normalized_term' => $normalized,
                        'observation_type' => $type, 'granularity' => 'day',
                    ]) : ['points' => [], 'summary' => []];
                $score = VES_Trend_Scorer::score_series($series);

                // 3) Upsert the trend record (preserving links).
                if (class_exists('VES_Trend_Record_Store')) {
                    $rec_id = VES_Trend_Record_Store::create_or_update_trend_record(array_merge($score, [
                        'workspace_id' => $workspace_id, 'run_id' => (string) $run_id,
                        'normalized_term' => $normalized, 'display_term' => $term,
                        'observation_type' => $type, 'provider' => 'apify',
                        'point_count' => (int) ($score['metadata']['point_count'] ?? 0),
                        'last_seen_at' => $now, 'source_ids' => $source_ids, 'signal_ids' => $signal_ids,
                        'metadata' => ['reasons' => implode(';', (array) ($score['reasons'] ?? []))],
                    ]));
                    if (!self::is_wp_error_local($rec_id) && !empty($evidence_ids)) {
                        foreach ($evidence_ids as $eid) { VES_Trend_Record_Store::link_evidence((int) $rec_id, (int) $eid); }
                    }
                    if (!self::is_wp_error_local($rec_id)) { $created++; }

                    // 4) Phase 5: evidence-first trend → DRAFT insight via the builder.
                    // The builder enforces evidence, score thresholds, dedup (by
                    // trend_record_id), weak-class exclusion, and quality scoring.
                    // Falls back to the legacy inline creation only if the builder is absent.
                    if (!self::is_wp_error_local($rec_id) && class_exists('VES_Trend_Insight_Builder')) {
                        $ins = VES_Trend_Insight_Builder::create_draft_insight_from_trend((int) $rec_id, [
                            'min_score' => $min_score, 'min_confidence' => $min_conf,
                        ]);
                        if (!self::is_wp_error_local($ins)) { $insights++; }
                    } elseif (class_exists('VES_Intelligence_Store')
                        && (float) $score['trend_score'] >= $min_score
                        && (float) $score['confidence_score'] >= $min_conf
                        && !empty($evidence_ids)
                        && !in_array($score['classification'], ['insufficient_data', 'noise'], true)) {
                        $ins = VES_Intelligence_Store::create_insight([
                            'workspace_id' => $workspace_id, 'insight_type' => 'trend',
                            'title' => 'Trend: ' . substr($term, 0, 200) . ' (' . $score['classification'] . ')',
                            'summary' => 'Deterministic trend score ' . $score['trend_score'] . ', confidence ' . $score['confidence_score'] . '.',
                            'confidence_score' => (float) $score['confidence_score'],
                            'status' => 'draft', 'evidence_ids' => $evidence_ids, 'signal_ids' => $signal_ids,
                            'metadata' => ['trend_record_id' => (int) $rec_id, 'classification' => $score['classification'], 'usage_event_id' => (int) ($report['_usage_event_id'] ?? 0)],
                        ]);
                        if (!self::is_wp_error_local($ins)) { $insights++; }
                    }
                }
            }
            if (class_exists('VES_Log')) {
                VES_Log::info('deep_trend_finder', 'trend_engine_run', [
                    'run_id' => $run_id, 'workspace_id' => $workspace_id, 'operation_type' => 'trend_scoring',
                ]);
            }
        } catch (\Throwable $e) {
            if (class_exists('VES_Log')) { VES_Log::warn('deep_trend_finder', 'trend_engine_failed', ['run_id' => $run_id, 'error_code' => 'trend_engine_failed']); }
        }
    }

    /** Extract [term, count, type] tuples from a top_terms/top_hashtags structure. */
    private static function trend_term_entries($raw, string $type): array {
        $out = [];
        foreach ((array) $raw as $k => $v) {
            $term = ''; $count = 1.0;
            if (is_string($v) && $v !== '') { $term = $v; }
            elseif (is_array($v)) {
                $term = (string) ($v['term'] ?? $v['label'] ?? $v['hashtag'] ?? $v['name'] ?? (is_string($k) ? $k : ''));
                $count = (float) ($v['count'] ?? $v['value'] ?? $v['frequency'] ?? $v['weight'] ?? 1);
            } elseif (is_string($k) && $k !== '') { $term = $k; $count = (float) $v; }
            $term = trim((string) $term);
            if ($term !== '') { $out[] = [$term, max(1.0, $count), $type]; }
        }
        return array_slice($out, 0, 30);
    }

    private static function is_wp_error_local($thing): bool {
        return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error);
    }

    /** Coerce a top_terms/top_hashtags structure into a flat list of label strings. */
    private static function flatten_terms($terms): array {
        $out = [];
        foreach ((array) $terms as $k => $v) {
            if (is_string($v) && $v !== '') { $out[] = $v; }
            elseif (is_array($v)) {
                $label = (string) ($v['term'] ?? $v['label'] ?? $v['hashtag'] ?? $v['name'] ?? (is_string($k) ? $k : ''));
                if ($label !== '') { $out[] = $label; }
            } elseif (is_string($k) && $k !== '') { $out[] = $k; }
        }
        return array_values(array_unique($out));
    }

    public static function render_html(array $report): string {
        ob_start();
        include FIDTF_PLUGIN_DIR . 'templates/report-deep-trend-finder.php';
        return (string) ob_get_clean();
    }

    private static function local_synthesis(int $run_id, array $run, array $source_summary, array $items): array {
        $items = self::annotate_evidence_items($items);
        $usable_count = count($items);
        $decision_items = self::decision_ready_items($items);
        $creative_candidate_items = self::creative_candidate_items($items);
        $stats = self::statistical_summary($source_summary, $items);
        $credit_estimate = self::credit_estimate_for_run($run);
        $live_sources = self::sources_with_provider_rows($source_summary);
        $sources_with_evidence = self::sources_with_evidence($source_summary);
        $top_terms = !empty($decision_items) ? self::top_terms($decision_items, 14) : [];
        $top_hashtags = !empty($decision_items) ? self::top_hashtags($decision_items, 14) : [];
        $top_items = !empty($decision_items) ? self::top_items($decision_items, 5) : self::top_items($creative_candidate_items, 3);
        $format_patterns = self::format_patterns($items);
        $evidence_quality = self::evidence_quality_summary($items, $source_summary, $stats);
        $evidence_intelligence = self::evidence_intelligence($items);
        $request_focus = self::request_focus($run);
        $signal_clusters = self::signal_clusters($items, $request_focus);
        $warnings = [];

        if ($usable_count === 0) {
            $warnings[] = 'No relevant evidence has been ingested yet. This is a planned run or a provider run waiting for usable rows.';
            $insights = [];
            $opportunities = [[
                'title' => 'Connect or repair a provider bridge before synthesis',
                'why' => 'The system created a structured plan, but no relevant public signal was available for interpretation.',
                'next_action' => 'Enable at least one live provider bridge, verify actor mapping, or ingest normalized source results through the controlled REST endpoint.',
            ]];
            $executive_summary = 'No usable public signal was available. The output is a planning shell, not a market finding.';
        } else {
            $insights = self::professional_insights($items, $source_summary, $stats, $top_terms, $top_hashtags, $top_items, $format_patterns);
            $opportunities = self::professional_opportunities($items, $source_summary, $top_terms, $top_hashtags, $format_patterns);
            $executive_summary = self::executive_summary($run, $usable_count, $source_summary, $top_terms, $top_hashtags, $format_patterns, $stats, $evidence_intelligence);
        }

        return [
            'schema_version' => 'fidtf.report.v3.8',
            'addon_version' => defined('FIDTF_VERSION') ? FIDTF_VERSION : '',
            'report_kind' => $usable_count > 0 ? 'professional_evidence_synthesis' : 'planned_run_not_evidence_report',
            'run_id' => $run_id,
            'status' => $usable_count > 0 ? 'professional_partial_synthesis' : 'planned_waiting_for_sources',
            'status_label' => $usable_count > 0 ? self::report_title($run, $request_focus) : 'Planned run, waiting for usable evidence',
            'synthesis_mode' => 'local_deterministic_professional',
            'credit_mode' => FIDTF_Credit_Service::credit_mode($run),
            'credit_estimate' => $credit_estimate,
            'credit_breakdown' => (array) ($credit_estimate['breakdown'] ?? []),
            'credits_reserved' => (float) ($run['credits_reserved'] ?? 0),
            'final_credits_settled' => (float) ($run['final_credits_settled'] ?? 0),
            'source_summary' => $source_summary,
            'statistical_summary' => $stats,
            'usable_evidence_count' => $usable_count,
            'sources_with_provider_rows' => $live_sources,
            'sources_with_evidence' => $sources_with_evidence,
            'top_terms' => $top_terms,
            'top_hashtags' => $top_hashtags,
            'format_patterns' => $format_patterns,
            'top_evidence_items' => $top_items,
            'executive_summary' => $executive_summary,
            'marketing_decision_summary' => self::marketing_decision_summary($run, $request_focus, $source_summary, $evidence_quality, $evidence_intelligence, $top_terms, $top_hashtags, $format_patterns),
            'confidence_breakdown' => self::confidence_breakdown($source_summary, $evidence_quality, $evidence_intelligence),
            'source_role_matrix' => self::source_role_matrix($source_summary),
            'key_patterns' => self::key_patterns($top_terms, $top_hashtags, $format_patterns, $stats),
            'strategic_readout' => self::strategic_readout($usable_count, $source_summary, $top_items, $evidence_quality, $evidence_intelligence),
            'content_angle_briefs' => self::content_angle_briefs($top_terms, $top_hashtags, $top_items, $format_patterns, $evidence_intelligence),
            'recommended_validation_plan' => self::validation_plan($source_summary, $top_terms, $top_hashtags, $request_focus),
            'evidence_quality_summary' => $evidence_quality,
            'evidence_intelligence' => $evidence_intelligence,
            'request_focus' => $request_focus,
            'evidence_fit_diagnosis' => self::evidence_fit_diagnosis($items, $source_summary, $request_focus, $evidence_intelligence, $stats),
            'cross_platform_intelligence' => self::cross_platform_intelligence($items, $source_summary, $request_focus, $stats, $evidence_intelligence),
            'signal_clusters' => $signal_clusters,
            'briefing_recommendations' => self::briefing_recommendations($signal_clusters, $request_focus, $evidence_intelligence, $source_summary),
            'creative_territories' => self::creative_territories($top_terms, $top_hashtags, $format_patterns, $evidence_intelligence),
            'platform_asset_blocks' => [
                'google_ads' => self::google_ads_asset_block($top_terms, $top_hashtags, $format_patterns, $evidence_quality, $evidence_intelligence, $request_focus, $source_summary),
            ],
            'proof_still_needed' => self::proof_still_needed($source_summary, $evidence_quality, $evidence_intelligence, $request_focus),
            'risk_notes' => self::risk_notes($items, $source_summary, $evidence_intelligence),
            'source_confidence' => self::source_confidence($source_summary, $evidence_quality),
            'insights' => $insights,
            'opportunities' => $opportunities,
            'warnings' => $warnings,
            'confidence' => self::overall_confidence($usable_count, $stats),
            'can_say' => self::can_say($usable_count, $live_sources),
            'cannot_say' => self::cannot_say($usable_count, $live_sources),
            'next_setup_step' => $usable_count > 0
                ? 'Use the strongest repeated patterns to create validation briefs, then add a second source family for confirmation before making campaign-level claims.'
                : 'Connect a provider bridge or ingest source outputs through the admin-only REST endpoint.',
            'not_claimed' => self::not_claimed($usable_count, $live_sources),
        ];
    }

    private static function report_title(array $run, array $focus): string {
        $brand = trim((string) ($focus['brand'] ?? ''));
        $market = trim((string) ($focus['market'] ?? $run['market'] ?? ''));
        $mode = self::mode_label((string) ($focus['research_mode'] ?? 'creative_validation'));
        $subject = $brand !== '' ? $brand : self::short_text(self::first_non_empty([(string) ($run['user_brief'] ?? ''), (string) ($run['objective'] ?? '')]), 70);
        $title = trim($subject . ($market !== '' ? ' / ' . $market : '') . ': ' . $mode . ' readout');
        return $title !== '' ? $title : 'Marketing validation readout';
    }

    private static function mode_label(string $mode): string {
        $mode = sanitize_key($mode);
        $labels = [
            'category_trend_discovery' => 'Category trend discovery',
            'brand_opportunity' => 'Brand opportunity',
            'competitor_benchmark' => 'Competitor benchmark',
            'creative_mechanics_mining' => 'Creative mechanics',
            'campaign_validation' => 'Campaign validation',
            'creative_validation' => 'Creative validation',
        ];
        return $labels[$mode] ?? 'Creative validation';
    }

    private static function annotate_evidence_items(array $items): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $tier = sanitize_key((string) ($item['evidence_tier'] ?? ''));
            if ($tier === '') { $tier = self::infer_evidence_tier($item); }
            $item['evidence_tier'] = $tier;
            if (empty($item['marketing_allowed_use'])) { $item['marketing_allowed_use'] = self::allowed_use_from_tier($item); }
            if (!isset($item['client_safe'])) { $item['client_safe'] = in_array($tier, ['strong','support'], true); }
            if (empty($item['risk_note'])) { $item['risk_note'] = self::risk_note_for_item($item); }
            $out[] = $item;
        }
        return $out;
    }

    private static function infer_evidence_tier(array $item): string {
        $score = (float) ($item['relevance_score'] ?? 0);
        $tier = sanitize_key((string) ($item['relevance_tier'] ?? ''));
        $use = sanitize_key((string) ($item['recommended_use'] ?? ''));
        $source = sanitize_key((string) ($item['source_key'] ?? ''));
        if ($use === 'hide' || $tier === 'noise' || $score < 35) { return 'noise'; }
        if ($tier === 'direct' && $score >= 72) { return 'strong'; }
        if ($tier === 'direct' && $score >= 50) { return 'support'; }
        if (in_array($source, ['tiktok','instagram'], true) && in_array($tier, ['adjacent','weak'], true)) { return 'creative_only'; }
        if ($tier === 'adjacent' && $score >= 50) { return 'support'; }
        return 'weak';
    }

    private static function allowed_use_from_tier(array $item): string {
        $tier = sanitize_key((string) ($item['evidence_tier'] ?? 'weak'));
        if ($tier === 'strong') { return 'claim_support_after_review'; }
        if ($tier === 'support') { return 'directional_support'; }
        if ($tier === 'creative_only') { return 'creative_inspiration_only'; }
        if ($tier === 'noise') { return 'excluded'; }
        return 'background_only';
    }

    private static function risk_note_for_item(array $item): string {
        $source = sanitize_key((string) ($item['source_key'] ?? ''));
        $tier = sanitize_key((string) ($item['evidence_tier'] ?? 'weak'));
        if ($tier === 'creative_only' || in_array($source, ['tiktok','instagram'], true)) { return 'Use for hook, format or language inspiration only unless confirmed by demand/search evidence.'; }
        if ($source === 'reddit') { return 'Treat as community discourse, not representative market opinion.'; }
        if ($source === 'google_trends') { return 'Directional movement only; it does not explain why demand changed.'; }
        if ($source === 'google_news') { return 'Media framing only; not purchase or audience intent by itself.'; }
        return 'Human strategic review required before client-facing use.';
    }

    private static function decision_ready_items(array $items): array {
        $out = [];
        foreach ($items as $item) {
            $tier = sanitize_key((string) ($item['evidence_tier'] ?? ''));
            if (in_array($tier, ['strong','support'], true)) { $out[] = $item; }
        }
        return $out;
    }

    private static function creative_candidate_items(array $items): array {
        $out = [];
        foreach ($items as $item) {
            $tier = sanitize_key((string) ($item['evidence_tier'] ?? ''));
            $use = sanitize_key((string) ($item['marketing_allowed_use'] ?? ''));
            if ($tier === 'creative_only' || $use === 'creative_inspiration_only') { $out[] = $item; }
        }
        return $out;
    }

    private static function marketing_decision_summary(array $run, array $focus, array $source_summary, array $quality, array $intel, array $terms, array $hashtags, array $formats): array {
        $sources = count(self::sources_with_evidence($source_summary));
        $decision_ready = (int) ($quality['decision_ready_rows'] ?? 0);
        $creative_only = (int) ($intel['creative_only_count'] ?? 0);
        $strong = (int) ($intel['strong_count'] ?? 0);
        $support = (int) ($intel['support_count'] ?? 0);
        $mode = self::mode_label((string) ($focus['research_mode'] ?? 'creative_validation'));
        $best_term = self::label_from_ranked($terms, 0, 'the selected topic');
        $format = self::label_from_ranked($formats, 0, 'short-form test');
        $demand_rows = (int) ($quality['demand_decision_ready_rows'] ?? self::demand_evidence_rows($source_summary));
        $has_validated_demand = $demand_rows >= 3;
        $status = 'internal_validation_ready';
        if ($strong + $support <= 0) { $status = 'not_ready_for_strategy'; }
        elseif (!$has_validated_demand) { $status = 'ready_for_internal_test_not_market_claim'; }
        elseif ($sources >= 3 && $decision_ready >= 10) { $status = 'directional_brief_ready'; }
        $opportunity = ($strong + $support) > 0
            ? 'Build a controlled validation brief around ' . $best_term . ', not a final campaign claim.'
            : 'No evidence-backed opportunity is ready yet. Clean weak rows, then rerun with stricter source coverage.';
        return [
            'research_mode' => $mode,
            'best_current_opportunity' => $opportunity,
            'strongest_audience_or_occasion' => self::audience_or_occasion_hint($run, $terms, $hashtags),
            'recommended_channel_to_test' => self::recommended_channel_to_test($source_summary, $formats),
            'recommended_creative_route' => ($strong + $support + $creative_only) > 0 ? 'Start with a ' . $format . ' route and carry the evidence caveat into the brief.' : 'Do not create a creative route until at least one decision-ready evidence group exists.',
            'main_risk' => $has_validated_demand ? 'Evidence still needs human review before client-facing claims.' : 'Social/search evidence is not enough to prove market demand yet; demand source rows are below the minimum validation threshold.',
            'proof_still_needed' => $sources < 2 ? 'Add a second source family.' : 'Add competitor/category benchmark and at least 3 demand/search rows against the same terms.',
            'decision_status' => $status,
            'decision_ready_rows' => $decision_ready,
            'creative_only_rows' => $creative_only,
            'demand_rows' => $demand_rows,
        ];
    }

    private static function source_has_evidence(array $source_summary, string $source): bool {
        return isset($source_summary[$source]) && (int) ($source_summary[$source]['relevant_count'] ?? 0) > 0;
    }

    private static function audience_or_occasion_hint(array $run, array $terms, array $hashtags): string {
        $audience = trim((string) ($run['audience'] ?? ''));
        if ($audience !== '') { return $audience; }
        $lang = self::ranked_labels_sentence($terms, 3);
        $tags = self::ranked_labels_sentence($hashtags, 3);
        return trim($lang . ($tags !== '' ? ' / ' . $tags : '')) ?: 'Audience or occasion still needs validation.';
    }

    private static function recommended_channel_to_test(array $source_summary, array $formats): string {
        $has_tiktok = self::source_has_evidence($source_summary, 'tiktok');
        $has_instagram = self::source_has_evidence($source_summary, 'instagram');
        $has_demand = self::demand_evidence_rows($source_summary) >= 3;
        $format = self::label_from_ranked($formats, 0, 'short-form test');
        if ($has_tiktok && !$has_demand) {
            return 'First test environment: TikTok-style short-form format (' . $format . '). Not yet a recommended media channel.';
        }
        if ($has_instagram && !$has_demand) {
            return 'First test environment: Instagram-style visual/caption format. Not yet a recommended media channel.';
        }
        if (($has_tiktok || $has_instagram) && $has_demand) {
            return 'Candidate channel test: social short-form, validated against demand/search evidence before media recommendation.';
        }
        return 'Run one social source and one demand/search source before channel selection.';
    }

    private static function confidence_breakdown(array $source_summary, array $quality, array $intel): array {
        $families = count(self::sources_with_evidence($source_summary));
        $decision_ready = (int) ($quality['decision_ready_rows'] ?? 0);
        $decision_sources = (int) ($quality['decision_ready_source_families'] ?? 0);
        $direct = (int) ($intel['direct_count'] ?? 0);
        $creative_only = (int) ($intel['creative_only_count'] ?? 0);
        $demand_rows = (int) ($quality['demand_decision_ready_rows'] ?? self::demand_evidence_rows($source_summary));
        return [
            'source_coverage' => ($families >= 3 && $decision_sources >= 2) ? 'medium' : (($families >= 2 || $decision_sources >= 1) ? 'low-medium' : 'low'),
            'relevance_confidence' => ($decision_ready >= 20 && $decision_sources >= 2) ? 'medium' : ($decision_ready >= 5 ? 'low-medium' : 'low'),
            'market_demand_confidence' => $demand_rows >= 3 ? 'directional-low' : ($demand_rows > 0 ? 'weak_single_point_not_validated' : 'not_proven'),
            'creative_mechanics_confidence' => $creative_only >= 5 ? 'medium' : ($creative_only > 0 ? 'low-medium' : 'low'),
            'brand_fit_confidence' => ($direct >= 10 && $decision_sources >= 1) ? 'medium' : ($direct > 0 ? 'low-medium' : 'low'),
            'client_readiness' => ($decision_sources >= 2 && $decision_ready >= 20 && $demand_rows >= 3) ? 'directional_client_safe_after_review' : 'internal_validation_only',
        ];
    }

    private static function is_demand_source(string $source): bool {
        return in_array(sanitize_key($source), ['google_trends','google_news','google_search','serp','search'], true);
    }

    private static function demand_evidence_rows(array $source_summary): int {
        $total = 0;
        foreach ($source_summary as $source => $summary) {
            if (!self::is_demand_source((string) $source)) { continue; }
            if (array_key_exists('decision_ready_count', (array) $summary)) {
                $total += (int) ($summary['decision_ready_count'] ?? 0);
            } else {
                $total += (int) ($summary['relevant_count'] ?? 0);
            }
        }
        return $total;
    }

    private static function source_role_matrix(array $source_summary): array {
        $matrix = [];
        foreach ($source_summary as $source => $summary) {
            $source = sanitize_key((string) $source);
            $matrix[] = [
                'source' => $source,
                'role' => self::source_role($source),
                'can_support' => self::source_can_support($source),
                'cannot_support' => self::source_cannot_support($source),
                'relevant_rows' => (int) ($summary['relevant_count'] ?? 0),
                'decision_ready_rows' => (int) ($summary['decision_ready_count'] ?? 0),
                'creative_only_rows' => (int) ($summary['creative_only_count'] ?? 0),
                'weak_or_noise_rows' => (int) ($summary['weak_or_noise_count'] ?? 0),
            ];
        }
        return $matrix;
    }

    private static function source_can_support(string $source): string {
        $map = [
            'tiktok' => 'Hook mechanics, creator delivery, meme/format traction.',
            'instagram' => 'Visual occasion, caption language, lifestyle context.',
            'reddit' => 'Objections, discourse, community language.',
            'google_trends' => 'Directional demand movement.',
            'google_news' => 'Media framing and current public context.',
            'ai_research' => 'Hypothesis framing and synthesis support.',
        ];
        return $map[$source] ?? 'Supporting context.';
    }

    private static function source_cannot_support(string $source): string {
        $map = [
            'tiktok' => 'Purchase intent, market size or sales impact.',
            'instagram' => 'Broad demand or representative audience opinion.',
            'reddit' => 'Market-wide representativeness without subreddit fit and repetition.',
            'google_trends' => 'Causality or audience motivation.',
            'google_news' => 'Consumer resonance or purchase intent.',
            'ai_research' => 'Live evidence unless a live research bridge is configured.',
        ];
        return $map[$source] ?? 'Final campaign proof by itself.';
    }

    private static function executive_summary(array $run, int $count, array $source_summary, array $terms, array $hashtags, array $formats, array $stats = [], array $intel = []): string {
        $market = trim((string) ($run['market'] ?? ''));
        $topic = self::first_non_empty([(string) ($run['brand'] ?? ''), (string) ($run['objective'] ?? ''), (string) ($run['user_brief'] ?? '')]);
        $main_term = self::label_from_ranked($terms, 0, 'the selected topic');
        $second_term = self::label_from_ranked($terms, 1, 'adjacent conversation');
        $format = self::label_from_ranked($formats, 0, 'repeatable short-form formats');
        $source_count = count(self::sources_with_evidence($source_summary));
        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        $market_part = $market !== '' ? ' in ' . $market : '';
        $topic_part = $topic !== '' ? ' for ' . self::short_text($topic, 90) : '';
        $read = 'The run found ' . $count . ' usable public signals from ' . $source_count . ' ' . self::plural($source_count, 'source family', 'source families') . $market_part . $topic_part . '. ';
        if ($direct > 0) {
            $read .= $direct . ' rows are direct keyword matches and ' . $adjacent . ' are adjacent cultural/context signals. Keyword fit is not treated as market demand by itself. ';
        } else {
            $read .= 'The evidence is mainly adjacent context rather than direct brand/request proof. ';
        }
        $read .= 'The strongest briefing territory is ' . $main_term . ', with secondary language around ' . $second_term . ' and recurring ' . $format . '. ';
        $read .= 'Use this as an evidence-backed validation brief, not a final market conclusion, until at least one more source family confirms the pattern.';
        return $read;
    }

    private static function professional_insights(array $items, array $source_summary, array $stats, array $terms, array $hashtags, array $top_items, array $formats): array {
        $source_count = count(self::sources_with_evidence($source_summary));
        $intel = self::evidence_intelligence($items);
        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        $top = $top_items[0] ?? [];
        $top_title = self::short_text((string) (($top['title'] ?? '') ?: ($top['caption_or_text'] ?? '')), 150);
        $eng = self::engagement_for_item($top);
        $insights = [];

        $insights[] = [
            'title' => 'Primary signal cluster: ' . self::label_from_ranked($terms, 0, 'category conversation'),
            'evidence' => 'Repeated language: ' . self::ranked_labels_sentence($terms, 6) . '. Hashtags: ' . self::ranked_labels_sentence($hashtags, 5) . '.',
            'implication' => 'Brief from the vocabulary that actually appears in the evidence. Separate direct request terms from broad category terms so the output does not become generic.',
            'confidence' => self::confidence_label((float) ($top['relevance_score'] ?? 0), count($items), $source_count),
        ];
        $insights[] = [
            'title' => 'Evidence mix: ' . $direct . ' direct vs ' . $adjacent . ' adjacent signals',
            'evidence' => 'Direct signals contain core request terms. Adjacent signals contain broader category/context language and should be used as creative inspiration, not proof.',
            'implication' => $direct > 0 ? 'Prioritize direct signals for claims and use adjacent signals for tone, setting, meme mechanics, and creator formats.' : 'Do not frame this as direct brand demand yet. Use it as a category/culture scan and run a confirmation source.',
            'confidence' => $direct > 0 ? 'medium' : 'low-medium',
        ];
        $insights[] = [
            'title' => 'Format mechanic to test: ' . self::label_from_ranked($formats, 0, 'short-form social evidence'),
            'evidence' => 'Detected format signals include ' . self::ranked_labels_sentence($formats, 4) . '.',
            'implication' => 'The next brief should define opening hook, scene type, creator POV, caption pattern, and risk guardrail. Topic alone is not enough.',
            'confidence' => count($formats) >= 2 ? 'medium-low' : 'low',
        ];
        $insights[] = [
            'title' => 'Representative evidence shows attention mechanics, not campaign certainty',
            'evidence' => $top_title . ($eng !== '' ? ' (' . $eng . ')' : ''),
            'implication' => 'Use top-performing evidence to abstract why it worked. Do not copy posts or overclaim business impact from engagement alone.',
            'confidence' => 'directional',
        ];
        if ($source_count < 2) {
            $insights[] = [
                'title' => 'Single-source evidence still needs confirmation',
                'evidence' => 'Current evidence source(s): ' . implode(', ', self::sources_with_evidence($source_summary)) . '.',
                'implication' => 'Before a client-facing recommendation, validate the same cluster with Instagram, Reddit, Google Trends, Google News, search, or competitor evidence.',
                'confidence' => 'low-medium',
            ];
        }
        return $insights;
    }

    private static function professional_opportunities(array $items, array $source_summary, array $terms, array $hashtags, array $formats): array {
        $main_term = self::label_from_ranked($terms, 0, 'repeated signal');
        $main_format = self::label_from_ranked($formats, 0, 'short-form test');
        return [
            [
                'title' => 'Build a validation brief around ' . $main_term,
                'why' => 'This is the clearest repeated language cluster in the collected evidence.',
                'next_action' => 'Create 3 content routes: direct keyword route, adjacent cultural route, and creator-format route. Test them against one audience segment.',
            ],
            [
                'title' => 'Translate the strongest format into a repeatable creative template',
                'why' => 'The evidence suggests the format mechanics are as important as the topic.',
                'next_action' => 'Write one hook structure, one visual premise, one caption pattern, and one risk note for the ' . $main_format . ' route.',
            ],
            [
                'title' => 'Add a second evidence family before final recommendations',
                'why' => 'A single source can reveal direction, but it cannot prove market-wide demand.',
                'next_action' => 'Run Instagram or Google Trends/News with the same core terms and compare repeated clusters before creating the final campaign brief.',
            ],
        ];
    }

    private static function key_patterns(array $terms, array $hashtags, array $formats, array $stats): array {
        return [
            ['label' => 'Repeated language', 'value' => self::ranked_labels_sentence($terms, 6)],
            ['label' => 'Hashtag cluster', 'value' => self::ranked_labels_sentence($hashtags, 6)],
            ['label' => 'Content mechanics', 'value' => self::ranked_labels_sentence($formats, 5)],
            ['label' => 'Engagement spread', 'value' => self::engagement_spread_sentence($stats)],
        ];
    }

    private static function strategic_readout(int $count, array $source_summary, array $top_items, array $quality, array $intel = []): array {
        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        return [
            'diagnosis' => $count > 0 ? 'There is enough evidence to create a directional validation brief, but not enough to claim final market certainty.' : 'There is not enough evidence to synthesize a finding.',
            'evidence_shape' => 'Direct signals: ' . $direct . '. Adjacent/context signals: ' . $adjacent . '. Source families with evidence: ' . count(self::sources_with_evidence($source_summary)) . '.',
            'business_implication' => count(self::sources_with_evidence($source_summary)) > 1 ? 'Multiple meaningful source families can reduce false-positive risk, but weak/empty families must not be counted as proof.' : 'This should be positioned as a single-source signal scan. Use it to prioritize tests, not to close strategy.',
            'evidence_quality' => (string) ($quality['summary'] ?? ''),
            'representative_evidence' => self::top_item_sentence($top_items[0] ?? []),
        ];
    }

    private static function content_angle_briefs(array $terms, array $hashtags, array $top_items, array $formats, array $intel = []): array {
        $term1 = self::label_from_ranked($terms, 0, 'core topic');
        $term2 = self::label_from_ranked($terms, 1, 'adjacent topic');
        $format = self::label_from_ranked($formats, 0, 'short-form post');
        $direct_count = (int) ($intel['direct_count'] ?? 0);
        return [
            [
                'angle' => 'Direct validation route',
                'hook' => $direct_count > 0 ? 'Open with the clearest direct audience language around ' . $term1 . ', then make the brand/product role specific.' : 'Do not force a brand claim yet. Open with the category tension around ' . $term1 . ' and validate direct demand separately.',
                'evidence_basis' => self::ranked_labels_sentence($terms, 4),
                'test_output' => '3 hooks, 1 caption set, 1 evidence caveat, 1 measurement question.',
            ],
            [
                'angle' => 'Adjacent culture route',
                'hook' => 'Connect ' . $term1 . ' with ' . $term2 . ' as a social occasion or cultural behavior, not as a hard product claim.',
                'evidence_basis' => self::ranked_labels_sentence($hashtags, 4),
                'test_output' => '2 creator POV scripts + 2 meme/UGC-safe variants + 1 brand safety note.',
            ],
            [
                'angle' => 'Format-first route',
                'hook' => 'Rebuild the post around a proven ' . $format . ' mechanic and adapt the first 2 seconds for the selected audience.',
                'evidence_basis' => self::top_item_sentence($top_items[0] ?? []),
                'test_output' => '1 storyboard, 1 opening shot, 3 caption endings, 1 channel-specific adaptation.',
            ],
        ];
    }


    private static function request_focus(array $run): array {
        $keywords = [];
        if (!empty($run['keywords_json'])) {
            $decoded = json_decode((string) $run['keywords_json'], true);
            if (is_array($decoded)) { $keywords = $decoded; }
        }
        $meta = [];
        if (!empty($run['request_meta_json'])) {
            $decoded_meta = json_decode((string) $run['request_meta_json'], true);
            if (is_array($decoded_meta)) { $meta = $decoded_meta; }
        }
        $brand = self::first_non_empty([(string) ($meta['brand'] ?? ''), (string) ($run['brand'] ?? '')]);
        $company = self::first_non_empty([(string) ($meta['company'] ?? ''), (string) ($run['company'] ?? ''), $brand]);
        $core = array_merge([$brand, $company], (array) $keywords);
        $core = self::clean_terms($core, 24);
        $category = [];
        foreach ($core as $term) {
            if (self::is_generic_category_term($term)) { $category[] = $term; }
        }
        return [
            'brand' => $brand,
            'company' => $company,
            'research_mode' => sanitize_key((string) ($meta['research_mode'] ?? $run['research_mode'] ?? 'creative_validation')),
            'market' => sanitize_text_field((string) ($run['market'] ?? '')),
            'audience' => sanitize_text_field((string) ($meta['audience'] ?? $run['audience'] ?? '')),
            'language' => sanitize_key((string) ($run['language'] ?? '')),
            'keywords' => self::clean_terms((array) $keywords, 24),
            'core_terms' => $core,
            'category_terms' => array_values(array_unique($category)),
        ];
    }

    private static function evidence_fit_diagnosis(array $items, array $source_summary, array $focus, array $intel, array $stats): array {
        $total = count($items);
        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        $weak = (int) ($intel['weak_count'] ?? 0);
        $creative = (int) ($intel['creative_only_count'] ?? 0);
        $brand = trim((string) ($focus['brand'] ?? ''));
        $brand_direct = self::count_items_matching_terms($items, $brand !== '' ? [$brand] : []);
        $source_families = count(self::sources_with_evidence($source_summary));
        $decision_sources = 0;
        foreach ($source_summary as $summary) {
            if ((int) ($summary['decision_ready_count'] ?? 0) > 0) { $decision_sources++; }
        }
        $demand_rows = self::demand_evidence_rows($source_summary);
        $semantic_fit = ($direct >= 5 && $decision_sources >= 1) ? 'directional_internal' : ($direct > 0 ? 'weak_keyword_only' : 'not_proven');
        $market_fit = $demand_rows >= 3 ? 'directional_search_interest' : ($demand_rows > 0 ? 'single_point_not_enough' : 'not_proven');
        $actionability = ($decision_sources >= 2 && $direct >= 10) ? 'brief_ready_after_review' : (($direct > 0 || $creative > 0) ? 'hypothesis_only' : 'insufficient');
        $dominant = $total > 0 && ($adjacent + $weak) > $direct;
        $diagnosis = [];
        $diagnosis['direct_keyword_match'] = $direct > 0
            ? $direct . ' of ' . $total . ' usable rows contain direct request language. This is lexical fit, not market demand.'
            : 'No usable row directly contains the core request strongly enough; the run is currently category/context-led.';
        $diagnosis['direct_semantic_fit'] = self::human_label($semantic_fit) . '. Human review must confirm that matched terms appear in the title/body/caption, not only metadata.';
        $diagnosis['creative_mechanic_fit'] = $creative > 0
            ? $creative . ' creative/mechanic rows can inform hooks, formats, captions or scene structures.'
            : 'No strong creative-mechanic fit has been isolated yet.';
        $diagnosis['market_demand_fit'] = self::human_label($market_fit) . '. TikTok views, Instagram captions and Google Trends direction are not treated as demand proof by themselves.';
        $diagnosis['brand_actionability'] = self::human_label($actionability) . '. Use as a hypothesis unless direct evidence and source coverage improve.';
        if ($brand !== '') {
            $diagnosis['brand_fit'] = $brand_direct > 0
                ? $brand_direct . ' ' . self::plural($brand_direct, 'row explicitly mentions', 'rows explicitly mention') . ' ' . $brand . '. Treat the rest as category or cultural context.'
                : 'The brand term ' . $brand . ' is not strongly present in the stored evidence. Do not describe this as brand demand.';
        }
        $diagnosis['context_fit'] = $dominant
            ? 'Adjacent/context signals dominate the evidence. They are useful for creative territories, not for hard strategic claims.'
            : 'Direct evidence can guide an internal validation brief, with adjacent signals as support.';
        $diagnosis['source_family_support'] = $source_families . ' ' . self::plural($source_families, 'source family contains', 'source families contain') . ' usable evidence; ' . $decision_sources . ' ' . self::plural($decision_sources, 'family has', 'families have') . ' decision-ready rows.';
        $diagnosis['claim_readiness'] = ($decision_sources >= 2 && $demand_rows >= 3) ? 'Directional after review; still not campaign certainty.' : 'Hypothesis only. Cross-source confirmation is required before client-facing certainty.';
        $diagnosis['quality_gate'] = 'Use direct rows for claims, adjacent rows for tone and format inspiration, creative-only rows for mechanics, and weak rows only as background.';
        return $diagnosis;
    }

    private static function signal_clusters(array $items, array $focus): array {
        $clusters = [
            'brand_direct' => ['label' => 'Brand-direct evidence', 'count' => 0, 'examples' => [], 'raw_terms' => []],
            'category_context' => ['label' => 'Category/context evidence', 'count' => 0, 'examples' => [], 'raw_terms' => []],
            'occasion_social' => ['label' => 'Occasion and social behavior', 'count' => 0, 'examples' => [], 'raw_terms' => []],
            'humor_meme' => ['label' => 'Humor / meme mechanics', 'count' => 0, 'examples' => [], 'raw_terms' => []],
            'format_hook' => ['label' => 'TikTok-style football hook format', 'count' => 0, 'examples' => [], 'raw_terms' => []],
        ];
        $brand_terms = self::clean_terms([(string) ($focus['brand'] ?? ''), (string) ($focus['company'] ?? '')], 6);
        $category_terms = self::clean_terms(array_merge((array) ($focus['category_terms'] ?? []), ['beer','cerveza','beverage','drink','drinks','bebida','bebidas','football','soccer','fifa world cup','world cup']), 20);
        $phrase_terms = self::top_terms($items, 6);
        $primary_phrase = self::human_cluster_label(self::label_from_ranked($phrase_terms, 0, 'category conversation'), $focus);
        if ($primary_phrase !== '') {
            $clusters['category_context']['label'] = $primary_phrase;
        }
        foreach ($items as $item) {
            $text = self::item_plain_text($item);
            $title = self::short_text((string) (($item['title'] ?? '') ?: ($item['caption_or_text'] ?? 'Evidence item')), 110);
            if (!empty($brand_terms) && self::text_has_any($text, $brand_terms)) { self::add_cluster_example($clusters['brand_direct'], $title); self::add_cluster_term($clusters['brand_direct'], $brand_terms[0] ?? 'brand'); }
            if (!empty($category_terms) && self::text_has_any($text, $category_terms)) { self::add_cluster_example($clusters['category_context'], $title); self::add_cluster_term($clusters['category_context'], self::label_from_ranked($phrase_terms, 0, 'category')); }
            if (preg_match('/fiesta|party|celebr|night|noche|amigos|friends|summer|verano|festival|bar|terrace|terraza|moment|occasion/u', $text)) { self::add_cluster_example($clusters['occasion_social'], $title); self::add_cluster_term($clusters['occasion_social'], 'occasion'); }
            if (preg_match('/meme|😂|🤣|humor|joke|funny|viral|trend|challenge|plantilla/u', $text)) { self::add_cluster_example($clusters['humor_meme'], $title); self::add_cluster_term($clusters['humor_meme'], 'meme mechanics'); }
            if (preg_match('/pov|cuando|when|how|como|receta|recipe|storytime|hook|template|before|after|short[- ]form|tiktok/u', $text)) { self::add_cluster_example($clusters['format_hook'], $title); self::add_cluster_term($clusters['format_hook'], 'hook format'); }
        }
        foreach ($clusters as $key => $cluster) {
            if ((int) ($cluster['count'] ?? 0) <= 0) { unset($clusters[$key]); continue; }
            $clusters[$key]['label'] = self::human_cluster_label((string) ($cluster['label'] ?? ''), $focus);
            $clusters[$key]['raw_terms'] = array_values(array_unique((array) ($cluster['raw_terms'] ?? [])));
        }
        uasort($clusters, static function($a, $b) { return ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0)); });
        return array_values($clusters);
    }

    private static function briefing_recommendations(array $clusters, array $focus, array $intel, array $source_summary): array {
        $brand = trim((string) ($focus['brand'] ?? ''));
        $direct = (int) ($intel['direct_count'] ?? 0);
        $sources = count(self::sources_with_evidence($source_summary));
        $out = [];
        if ($brand !== '' && $direct <= 0) {
            $out[] = 'Do not pitch the finding as direct demand for ' . $brand . '. Pitch it as category/culture context and run a brand-specific confirmation search.';
        } else {
            $out[] = 'Build the first validation brief from direct evidence first; use adjacent evidence only to enrich tone, format and setting.';
        }
        if (!empty($clusters[0]['label'])) {
            $out[] = 'Use the dominant cluster, ' . (string) $clusters[0]['label'] . ', as the first creative territory, but attach an evidence caveat to the brief.';
        }
        if ($sources < 2) {
            $out[] = 'Run at least one second source family before turning this into a client-facing strategic recommendation.';
        }
        $out[] = 'For every generated output, carry forward: evidence source, signal tier, risk note, and the exact assumption being tested.';
        return array_values(array_unique($out));
    }

    private static function clean_terms(array $terms, int $limit): array {
        $out = [];
        foreach ($terms as $term) {
            $term = trim(wp_strip_all_tags((string) $term));
            $term = ltrim($term, '#');
            if ($term !== '') { $out[] = sanitize_text_field($term); }
            if (count($out) >= $limit) { break; }
        }
        return array_values(array_unique($out));
    }

    private static function is_generic_category_term(string $term): bool {
        $term = function_exists('mb_strtolower') ? mb_strtolower(trim($term), 'UTF-8') : strtolower(trim($term));
        return in_array($term, ['beer','cerveza','beverage','beverages','drink','drinks','bebida','bebidas','food','summer','trend','viral'], true);
    }

    private static function count_items_matching_terms(array $items, array $terms): int {
        if (empty($terms)) { return 0; }
        $count = 0;
        foreach ($items as $item) {
            if (self::text_has_any(self::item_plain_text($item), $terms)) { $count++; }
        }
        return $count;
    }

    private static function item_plain_text(array $item): string {
        $text = (string) (($item['title'] ?? '') . ' ' . ($item['caption_or_text'] ?? '') . ' ' . ($item['author'] ?? '') . ' ' . implode(' ', (array) ($item['hashtags'] ?? [])) . ' ' . implode(' ', (array) ($item['matched_keywords'] ?? [])));
        $text = wp_strip_all_tags($text);
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    private static function text_has_any(string $text, array $terms): bool {
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') { continue; }
            $needle = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
            if (function_exists('mb_stripos')) {
                if (mb_stripos($text, $needle, 0, 'UTF-8') !== false) { return true; }
            } elseif (stripos($text, $needle) !== false) { return true; }
        }
        return false;
    }

    private static function add_cluster_example(array &$cluster, string $title): void {
        $cluster['count'] = (int) ($cluster['count'] ?? 0) + 1;
        if ($title !== '' && count((array) ($cluster['examples'] ?? [])) < 3) {
            $cluster['examples'][] = $title;
        }
    }

    private static function validation_plan(array $source_summary, array $terms, array $hashtags, array $focus = []): array {
        $request_terms = self::ranked_labels_sentence(self::focus_terms_as_ranked($focus), 4);
        $evidence_terms = self::ranked_labels_sentence($terms, 4);
        $demand_rows = self::demand_evidence_rows($source_summary);
        return [
            'step_1' => 'Review the evidence scorecard first: remove noise, weak rows, unsafe examples and source rows marked creative-only before briefing.',
            'step_2' => 'Run one confirmation source using request terms: ' . $request_terms . '. Compare against evidence language: ' . $evidence_terms . '.',
            'step_3' => 'If this is brand-specific, add one competitor/category benchmark run before claiming ownership of the territory.',
            'step_4' => 'Compare context language and channel mechanics: ' . self::ranked_labels_sentence($hashtags, 4) . '.',
            'step_5' => $demand_rows >= 3 ? 'Demand/search evidence exists, but still keep campaign claims directional until human review.' : 'Add at least 3 demand/search rows before market-demand or client-facing campaign claims.',
            'step_6' => 'Only after cross-source confirmation, generate final creative briefs and production-ready outputs.',
        ];
    }

    private static function focus_terms_as_ranked(array $focus): array {
        $out = [];
        foreach ((array) ($focus['core_terms'] ?? []) as $term) {
            $term = sanitize_text_field((string) $term);
            if ($term !== '' && !self::is_report_noise_term($term, false)) { $out[] = ['label' => $term, 'count' => 1]; }
        }
        return $out;
    }

    private static function evidence_intelligence(array $items): array {
        $direct = 0; $adjacent = 0; $weak = 0; $creative_only = 0; $strong = 0; $support = 0; $noise = 0; $malformed_empty = 0; $sources = []; $signals = []; $hooks = [];
        foreach ($items as $item) {
            $tier = sanitize_key((string) ($item['relevance_tier'] ?? ''));
            $evidence_tier = sanitize_key((string) ($item['evidence_tier'] ?? ''));
            if ($evidence_tier === 'strong') { $strong++; }
            elseif ($evidence_tier === 'support') { $support++; }
            elseif ($evidence_tier === 'creative_only') { $creative_only++; }
            elseif ($evidence_tier === 'noise') { $noise++; }
            if (!empty($item['provider_malformed_empty_item']) || !empty($item['evidence_quality']['provider_malformed_empty_item'])) { $malformed_empty++; }
            if ($tier === 'direct' && in_array($evidence_tier, ['strong','support'], true)) { $direct++; }
            elseif ($tier === 'adjacent' || $evidence_tier === 'creative_only') { $adjacent++; }
            else { $weak++; }
            $src = sanitize_key((string) ($item['source_key'] ?? ''));
            if ($src !== '') { $sources[$src] = ($sources[$src] ?? 0) + 1; }
            $sig = sanitize_text_field((string) ($item['signal_type'] ?? 'signal'));
            if ($sig !== '') { $signals[$sig] = ($signals[$sig] ?? 0) + 1; }
            $text = (string) (($item['title'] ?? '') ?: ($item['caption_or_text'] ?? ''));
            $hook = self::hook_family($text);
            if ($hook !== '') { $hooks[$hook] = ($hooks[$hook] ?? 0) + 1; }
        }
        arsort($sources); arsort($signals); arsort($hooks);
        return [
            'direct_count' => $direct,
            'adjacent_count' => $adjacent,
            'weak_count' => $weak,
            'strong_count' => $strong,
            'support_count' => $support,
            'creative_only_count' => $creative_only,
            'noise_count' => $noise,
            'provider_malformed_empty_count' => $malformed_empty,
            'source_mix' => self::assoc_counts_to_rows($sources, 8),
            'signal_mix' => self::assoc_counts_to_rows($signals, 8),
            'hook_families' => self::assoc_counts_to_rows($hooks, 8),
        ];
    }

    private static function creative_territories(array $terms, array $hashtags, array $formats, array $intel): array {
        $primary = self::label_from_ranked($terms, 0, 'the core signal');
        $secondary = self::label_from_ranked($terms, 1, 'the adjacent signal');
        $format = self::label_from_ranked($formats, 0, 'short-form mechanic');
        return [
            [
                'territory' => 'Behavior-first territory',
                'idea' => 'Frame ' . $primary . ' as a lived behavior or social moment before mentioning the brand.',
                'why_it_matters' => 'Behavior-first content is safer when evidence is partly adjacent and not fully brand-direct.',
                'proof_needed' => 'Confirm with a second source that the same behavior appears outside the current channel.',
            ],
            [
                'territory' => 'Language capture territory',
                'idea' => 'Use repeated wording around ' . $primary . ' / ' . $secondary . ' as the raw material for hooks and captions.',
                'why_it_matters' => 'Audience-native language usually beats abstract campaign phrasing in early validation.',
                'proof_needed' => 'Check whether the language appears in search/news/community sources, not only short-form posts.',
            ],
            [
                'territory' => 'Format mechanic territory',
                'idea' => 'Prototype the idea using a ' . $format . ' structure, then adapt it to brand tone.',
                'why_it_matters' => 'The evidence shows mechanics and context, not just topics.',
                'proof_needed' => 'Test at least two hooks with the same premise before production-level investment.',
            ],
        ];
    }

    private static function risk_notes(array $items, array $source_summary, array $intel): array {
        $notes = [];
        if (count(self::sources_with_evidence($source_summary)) < 2) {
            $notes[] = 'Single-source bias: validate the same pattern with another source family before client-facing conclusions.';
        }
        if ((int) ($intel['direct_count'] ?? 0) === 0 && count($items) > 0) {
            $notes[] = 'No direct core-match evidence: current output is adjacent cultural context, not direct demand proof.';
        }
        $notes[] = 'Engagement is not intent: high views/likes can identify hooks, but cannot prove sales impact or campaign effectiveness.';
        $notes[] = 'Do not copy source posts. Extract mechanics, language, and context; create original brand-safe executions.';
        return array_values(array_unique($notes));
    }

    private static function source_confidence(array $source_summary, array $quality): array {
        $families = count(self::sources_with_evidence($source_summary));
        $decision_sources = (int) ($quality['decision_ready_source_families'] ?? 0);
        $rows = 0;
        foreach ($source_summary as $summary) { $rows += (int) ($summary['relevant_count'] ?? 0); }
        $level = 'low';
        if ($decision_sources >= 2 && $rows >= 50) { $level = 'medium'; }
        elseif (($families >= 2 || $decision_sources >= 1) && $rows >= 10) { $level = 'low-medium'; }
        return [
            'level' => $level,
            'reason' => $families . ' ' . self::plural($families, 'source family has', 'source families have') . ' relevant evidence; ' . $decision_sources . ' ' . self::plural($decision_sources, 'source family has', 'source families have') . ' decision-ready evidence; ' . $rows . ' relevant stored rows. ' . (string) ($quality['summary'] ?? ''),
        ];
    }

    private static function hook_family(string $text): string {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (preg_match('/\?|how|why|como|por qué|que /u', $lower)) { return 'question / explanation hook'; }
        if (preg_match('/pov|point of view|cuando|when /u', $lower)) { return 'POV / situation hook'; }
        if (preg_match('/#|meme|viral|trend/u', $lower)) { return 'meme / trend hook'; }
        if (preg_match('/recipe|receta|make|making|prepar/u', $lower)) { return 'recipe / how-to hook'; }
        if (preg_match('/party|celebr|fiesta|night|noche|friends|amigos/u', $lower)) { return 'occasion / celebration hook'; }
        return 'statement / caption hook';
    }

    private static function assoc_counts_to_rows(array $counts, int $limit): array {
        $rows = [];
        foreach ($counts as $label => $count) {
            $rows[] = ['label' => sanitize_text_field((string) $label), 'count' => (int) $count];
            if (count($rows) >= $limit) { break; }
        }
        return $rows;
    }


    /**
     * v0.3.41: data-scientist style cross-platform readout.
     *
     * This is intentionally deterministic. It does not pretend to run an LLM,
     * but it gives the final report a stronger analytical backbone while the
     * optional OpenAI bridge remains an enhancement layer.
     */
    private static function cross_platform_intelligence(array $items, array $source_summary, array $focus, array $stats, array $intel): array {
        $by_source = self::group_by_source($items);
        $source_roles = [];
        foreach ($source_summary as $source => $summary) {
            $source_key = sanitize_key((string) $source);
            $rows = (array) ($by_source[$source_key] ?? []);
            $source_roles[] = [
                'source' => $source_key,
                'role' => self::source_role($source_key),
                'provider_rows' => max((int) ($summary['provider_dataset_total_rows'] ?? 0), (int) ($summary['provider_dataset_rows'] ?? 0), (int) ($summary['raw_count'] ?? 0)),
                'relevant_rows' => count($rows) > 0 ? count($rows) : (int) ($summary['relevant_count'] ?? 0),
                'dominant_terms' => self::ranked_labels_sentence(self::top_terms($rows, 5), 5),
                'quality_note' => self::source_quality_note($source_key, $rows, (array) $summary),
            ];
        }

        $shared_terms = self::shared_terms_by_source($by_source);
        $platform_biases = [];
        foreach ($by_source as $source => $rows) {
            $platform_biases[] = self::platform_bias_note((string) $source, (array) $rows);
        }

        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        $weak = (int) ($intel['weak_count'] ?? 0);
        $source_count = count(self::sources_with_evidence($source_summary));
        $trend_sources = array_intersect(array_keys($by_source), ['google_trends', 'google_news']);
        $social_sources = array_intersect(array_keys($by_source), ['tiktok', 'instagram', 'reddit']);

        $convergence = 'low';
        if ($source_count >= 3 && !empty($shared_terms)) { $convergence = 'medium'; }
        if ($source_count >= 4 && count($shared_terms) >= 2 && $direct > $weak) { $convergence = 'medium-high'; }

        $hypotheses = [];
        $primary_term = self::label_from_ranked(self::top_terms($items, 6), 0, 'the primary topic');
        $hypotheses[] = 'If ' . $primary_term . ' is a real cross-platform opportunity, it should appear in at least one social source and one demand/news source in the same market window.';
        $hypotheses[] = 'If high-engagement social evidence is only weakly related to the query, treat it as a hook mechanic signal rather than audience demand.';
        if (!empty($trend_sources)) { $hypotheses[] = 'If Google Trends is directional, validate whether the highest-interest days align with social/news spikes before building a campaign claim.'; }
        if (!empty($social_sources)) { $hypotheses[] = 'If TikTok/Instagram/Reddit show similar language, convert that language into 2-3 controlled creative hypotheses instead of one final idea.'; }

        $statistical_scorecard = self::cross_platform_statistical_scorecard($by_source, $source_summary, $intel, $stats);
        $claim_readiness = self::cross_platform_claim_readiness($by_source, $source_summary, $intel, $focus, $statistical_scorecard);
        return [
            'analysis_level' => 'cross_platform_data_scientist_readout',
            'convergence_level' => $convergence,
            'source_roles' => $source_roles,
            'statistical_scorecard' => $statistical_scorecard,
            'claim_readiness' => $claim_readiness,
            'shared_language' => array_slice($shared_terms, 0, 10),
            'signal_balance' => [
                'direct' => $direct,
                'adjacent' => $adjacent,
                'weak' => $weak,
                'direct_share' => self::safe_ratio($direct, max(1, $direct + $adjacent + $weak)),
                'interpretation' => $direct > 0 ? 'There is some direct request fit; adjacent signals should support, not replace, the direct evidence.' : 'Direct fit is weak; this should be treated as exploratory context until confirmed.',
            ],
            'platform_biases' => array_values(array_filter(array_merge($platform_biases, (array) ($statistical_scorecard['overfit_warnings'] ?? [])))),
            'hypotheses_to_test' => array_values(array_unique(array_merge($hypotheses, (array) ($statistical_scorecard['validation_hypotheses'] ?? [])))),
            'decision_rule' => (string) ($statistical_scorecard['decision_rule'] ?? ($source_count >= 3
                ? 'Use the output for prioritizing validation briefs. Require one additional targeted run before campaign-level recommendations.'
                : 'Do not move to campaign-level recommendations yet. Add at least two more source families or increase evidence volume.')),
        ];
    }


    /**
     * v0.3.45: deterministic claim-readiness gate for cross-platform analysis.
     * This prevents the report and the optional GPT layer from treating source
     * presence or engagement as proof. It translates the evidence mix into a
     * data-scientist style decision framework before any campaign recommendation.
     */
    private static function cross_platform_claim_readiness(array $by_source, array $source_summary, array $intel, array $focus, array $scorecard): array {
        $families = [
            'social_creative' => ['tiktok', 'instagram'],
            'community_discourse' => ['reddit'],
            'demand_movement' => ['google_trends'],
            'market_framing' => ['google_news'],
        ];
        $coverage = [];
        foreach ($families as $family => $sources) {
            $coverage[$family] = 0;
            foreach ($sources as $source) { $coverage[$family] += count((array) ($by_source[$source] ?? [])); }
        }
        $covered_families = 0;
        foreach ($coverage as $count) { if ($count > 0) { $covered_families++; } }

        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        $weak = (int) ($intel['weak_count'] ?? 0);
        $total_fit = max(1, $direct + $adjacent + $weak);
        $direct_ratio = $direct / $total_fit;
        $dominant_share = (float) str_replace('%', '', (string) ($scorecard['dominant_source_share'] ?? '0')) / 100;

        $score = 0;
        $score += min(35, $covered_families * 8.75);
        $score += min(30, $direct_ratio * 30);
        if (($coverage['social_creative'] ?? 0) > 0 && (($coverage['demand_movement'] ?? 0) > 0 || ($coverage['market_framing'] ?? 0) > 0)) { $score += 20; }
        if (($coverage['community_discourse'] ?? 0) > 0) { $score += 5; }
        if ($dominant_share >= 0.7) { $score -= 15; }
        if ($weak > $direct && $weak > 0) { $score -= 12; }
        $score = max(0, min(100, round($score, 1)));

        $level = 'do_not_claim';
        if ($score >= 70 && $covered_families >= 3 && $direct_ratio >= 0.45) { $level = 'claim_directionally'; }
        elseif ($score >= 45 && $covered_families >= 2) { $level = 'hypothesis_only'; }

        $request_terms = array_values(array_filter(array_map('strval', (array) ($focus['core_terms'] ?? []))));
        $topic = !empty($request_terms) ? implode(', ', array_slice($request_terms, 0, 5)) : 'the requested topic';
        $blocked = [
            'Do not claim market demand from TikTok or Instagram engagement alone.',
            'Do not treat Reddit upvotes or comments as representative market opinion without subreddit fit and repeated community evidence.',
            'Do not treat Google News publisher/source names as proof that the article content is about ' . $topic . '.',
            'Do not claim causality between Google Trends movement and social content unless timing and content evidence align.',
        ];
        $directional = [];
        if (($coverage['social_creative'] ?? 0) > 0) { $directional[] = 'Creative-language or hook mechanics may be extracted from social rows if the row text matches the core topic.'; }
        if (($coverage['demand_movement'] ?? 0) > 0) { $directional[] = 'Demand movement can be described as directional search interest only, not purchase intent.'; }
        if (($coverage['market_framing'] ?? 0) > 0) { $directional[] = 'News rows can support market framing only when title/content matches the topic, not when only the publisher name matches.'; }
        if (($coverage['community_discourse'] ?? 0) > 0) { $directional[] = 'Reddit rows can reveal objections or language, but only if subreddit and body/title fit the topic.'; }

        return [
            'score' => $score,
            'level' => $level,
            'covered_evidence_families' => $covered_families,
            'coverage' => $coverage,
            'direct_ratio' => (string) round($direct_ratio * 100, 1) . '%',
            'dominant_source_share' => (string) round($dominant_share * 100, 1) . '%',
            'directional_claims_allowed' => $directional,
            'claims_blocked' => $blocked,
            'minimum_next_tests' => [
                'Collect at least 10 directly matching social rows across TikTok/Instagram before converting creative mechanics into a brief.',
                'Collect at least 7 days of Google Trends or repeat the query weekly before calling a movement stable.',
                'Collect at least 5 matching news/community rows where the match appears in title/body, not only source/community metadata.',
            ],
        ];
    }

    private static function cross_platform_statistical_scorecard(array $by_source, array $source_summary, array $intel, array $stats): array {
        $families = [
            'social_creative' => ['tiktok', 'instagram'],
            'community_discourse' => ['reddit'],
            'demand_movement' => ['google_trends'],
            'market_framing' => ['google_news'],
        ];
        $coverage = [];
        foreach ($families as $family => $sources) {
            $count = 0;
            foreach ($sources as $source) { $count += count((array) ($by_source[$source] ?? [])); }
            $coverage[$family] = $count;
        }
        $source_counts = [];
        foreach ($source_summary as $source => $summary) {
            $source_key = sanitize_key((string) $source);
            $source_counts[$source_key] = max(count((array) ($by_source[$source_key] ?? [])), (int) ($summary['relevant_count'] ?? 0));
        }
        arsort($source_counts);
        $total_relevant = max(1, array_sum($source_counts));
        $dominant_source = (string) (array_key_first($source_counts) ?: 'none');
        $dominant_share = self::safe_ratio((int) reset($source_counts), $total_relevant);
        $direct = (int) ($intel['direct_count'] ?? 0);
        $adjacent = (int) ($intel['adjacent_count'] ?? 0);
        $weak = (int) ($intel['weak_count'] ?? 0);
        $direct_share = self::safe_ratio($direct, max(1, $direct + $adjacent + $weak));
        $platform_weighting = [];
        foreach ($source_counts as $source => $count) {
            $platform_weighting[] = [
                'source' => (string) $source,
                'relevant_rows' => (int) $count,
                'share_of_relevant_evidence' => self::safe_ratio((int) $count, $total_relevant),
                'recommended_use' => self::source_role((string) $source),
            ];
        }
        $warnings = [];
        if ($dominant_share >= 0.65 && $total_relevant >= 3) {
            $warnings[] = 'Evidence is concentrated in ' . $dominant_source . ' (' . $dominant_share . '). Do not generalize across the market without another source family.';
        }
        if ($direct_share < 0.35 && ($direct + $adjacent + $weak) > 0) {
            $warnings[] = 'Direct evidence share is low (' . $direct_share . '). Treat the report as exploratory, not campaign proof.';
        }
        if (($coverage['demand_movement'] ?? 0) > 0 && (($coverage['social_creative'] ?? 0) + ($coverage['market_framing'] ?? 0)) === 0) {
            $warnings[] = 'Demand movement exists without social/news explanation. Validate why the movement occurred before making causal claims.';
        }
        if (($coverage['social_creative'] ?? 0) > 0 && (($coverage['demand_movement'] ?? 0) + ($coverage['market_framing'] ?? 0)) === 0) {
            $warnings[] = 'Social creative signals exist without demand/news confirmation. Treat them as hook mechanics, not market demand.';
        }
        // v0.3.43 live QA: short acronyms such as SEO can appear as publisher/entity
        // names in news/community sources. Require title/body context before using
        // them as demand evidence.
        $warnings[] = 'Acronym and entity-name collisions are checked before cross-platform claims; publisher/community names are display metadata, not semantic proof.';
        $validation = [
            'Run one focused follow-up query on the dominant source to verify repeatability.',
            'Run at least one demand/news source against the same terms to separate creative resonance from market demand.',
            'Review weak high-engagement rows manually before they influence a brief.',
        ];
        $coverage_score = 0;
        foreach ($coverage as $count) { if ($count > 0) { $coverage_score++; } }
        $analysis_confidence = 'low';
        if ($coverage_score >= 3 && $direct_share >= 0.35 && $dominant_share < 0.65) { $analysis_confidence = 'directional-medium'; }
        if ($coverage_score >= 4 && $direct_share >= 0.5 && $dominant_share < 0.55) { $analysis_confidence = 'medium'; }
        return [
            'analysis_confidence' => $analysis_confidence,
            'coverage_by_evidence_family' => $coverage,
            'platform_weighting' => $platform_weighting,
            'dominant_source' => $dominant_source,
            'dominant_source_share' => $dominant_share,
            'direct_evidence_share' => $direct_share,
            'overfit_warnings' => $warnings,
            'validation_hypotheses' => $validation,
            'decision_rule' => $analysis_confidence === 'medium'
                ? 'Use this as a strong directional brief, but still validate with a focused second run before campaign-level claims.'
                : 'Use this as an exploratory intelligence readout. Do not convert it into campaign recommendations until evidence coverage and direct-fit improve.',
        ];
    }

    private static function safe_ratio(int $part, int $total): string {
        if ($total <= 0) { return '0%'; }
        return (string) round(($part / $total) * 100, 1) . '%';
    }

    private static function source_role(string $source): string {
        $roles = [
            'tiktok' => 'short-form creative mechanics and fast cultural language',
            'instagram' => 'visual/caption language and creator/post format evidence',
            'reddit' => 'community objections, long-form opinions and weak-signal objections',
            'google_trends' => 'directional demand movement over time',
            'google_news' => 'media/news framing and market context',
            'ai_research' => 'AI-assisted hypothesis framing, not live public evidence by itself',
        ];
        return $roles[$source] ?? 'supporting evidence source';
    }

    private static function source_quality_note(string $source, array $rows, array $summary): string {
        $count = count($rows);
        $provider_rows = max((int) ($summary['provider_dataset_total_rows'] ?? 0), (int) ($summary['provider_dataset_rows'] ?? 0), (int) ($summary['raw_count'] ?? 0));
        if ($provider_rows > 0 && $count <= 0) { return 'Provider returned rows, but no rows survived normalization/relevance. Inspect adapter mapping and relevance thresholds.'; }
        if ($count <= 0) { return 'No usable evidence from this source yet.'; }
        if ($source === 'reddit') { return 'Useful for objections and discourse, but high engagement can be off-topic. Treat relevance as more important than votes.'; }
        if ($source === 'google_trends') { return 'Useful for demand direction, not for explaining why demand changed without social/news context.'; }
        if ($source === 'google_news') { return 'Useful for market framing and newsworthiness, not for consumer intent by itself.'; }
        if ($source === 'tiktok' || $source === 'instagram') { return 'Useful for creative mechanics and repeated language; engagement alone does not prove purchase intent.'; }
        return 'Usable supporting evidence. Review examples before client-facing claims.';
    }

    private static function shared_terms_by_source(array $by_source): array {
        $term_sources = [];
        foreach ($by_source as $source => $rows) {
            foreach (self::top_terms((array) $rows, 12) as $row) {
                $term = sanitize_text_field((string) ($row['label'] ?? ''));
                if ($term === '') { continue; }
                if (!isset($term_sources[$term])) { $term_sources[$term] = []; }
                $term_sources[$term][$source] = true;
            }
        }
        $out = [];
        foreach ($term_sources as $term => $sources) {
            if (count($sources) < 2) { continue; }
            $out[] = [
                'term' => $term,
                'source_count' => count($sources),
                'sources' => array_keys($sources),
            ];
        }
        usort($out, static function($a, $b) {
            return ((int) ($b['source_count'] ?? 0)) <=> ((int) ($a['source_count'] ?? 0));
        });
        return $out;
    }

    private static function platform_bias_note(string $source, array $rows): string {
        if (empty($rows)) { return ''; }
        if ($source === 'reddit') { return 'Reddit may surface argumentative or celebrity/community discourse that matches words but not business intent.'; }
        if ($source === 'tiktok') { return 'TikTok overweights hook performance and creator delivery; treat views as creative traction, not market size.'; }
        if ($source === 'instagram') { return 'Instagram hashtag scraping can overrepresent low-engagement recent posts; filter for relevance before deriving strategy.'; }
        if ($source === 'google_trends') { return 'Google Trends is indexed relative interest; it needs trend direction and cross-source explanation.'; }
        if ($source === 'google_news') { return 'Google News captures publisher agenda and current events, not necessarily audience demand.'; }
        return '';
    }

    private static function evidence_quality_summary(array $items, array $source_summary, array $stats): array {
        $source_count = count(self::sources_with_evidence($source_summary));
        $count = count($items);
        $has_metrics = 0;
        $has_url = 0;
        $tier_counts = ['strong' => 0, 'support' => 0, 'creative_only' => 0, 'weak' => 0, 'noise' => 0];
        $decision_sources = [];
        $creative_sources = [];
        $weak_sources = [];
        $demand_decision_ready_rows = 0;
        $demand_any_rows = 0;
        $provider_malformed_empty_rows = 0;
        foreach ($items as $item) {
            if (!empty($item['metrics'])) { $has_metrics++; }
            if (!empty($item['provider_malformed_empty_item']) || !empty($item['evidence_quality']['provider_malformed_empty_item'])) { $provider_malformed_empty_rows++; }
            if (!empty($item['url'])) { $has_url++; }
            $tier = sanitize_key((string) ($item['evidence_tier'] ?? 'weak'));
            if (!isset($tier_counts[$tier])) { $tier = 'weak'; }
            $tier_counts[$tier]++;
            $src = sanitize_key((string) ($item['source_key'] ?? $item['source'] ?? 'unknown'));
            if (self::is_demand_source($src)) {
                $demand_any_rows++;
                if (in_array($tier, ['strong','support'], true)) { $demand_decision_ready_rows++; }
            }
            if (in_array($tier, ['strong','support'], true)) { $decision_sources[$src] = true; }
            elseif ($tier === 'creative_only') { $creative_sources[$src] = true; }
            else { $weak_sources[$src] = true; }
        }
        $decision_ready = (int) ($tier_counts['strong'] + $tier_counts['support']);
        $decision_source_count = count($decision_sources);
        $summary = $count . ' relevant ' . self::plural($count, 'row', 'rows') . '; ' . $source_count . ' ' . self::plural($source_count, 'source family', 'source families') . '; ' . $decision_source_count . ' ' . self::plural($decision_source_count, 'source family', 'source families') . ' with decision-ready evidence; ' . $has_metrics . ' ' . self::plural($has_metrics, 'row', 'rows') . ' with metrics; ' . $has_url . ' ' . self::plural($has_url, 'row', 'rows') . ' with source URLs. Decision-ready rows: ' . $decision_ready . '; creative-only rows: ' . (int) $tier_counts['creative_only'] . '; weak/noise rows: ' . (int) ($tier_counts['weak'] + $tier_counts['noise']) . '.';
        if ($source_count < 2 || count($decision_sources) < 2) { $summary .= ' Cross-source confirmation is still needed before client-facing claims.'; }
        return [
            'summary' => $summary,
            'rows_with_metrics' => $has_metrics,
            'rows_with_urls' => $has_url,
            'source_families_with_evidence' => $source_count,
            'decision_ready_source_families' => count($decision_sources),
            'creative_only_source_families' => count($creative_sources),
            'weak_or_noise_source_families' => count($weak_sources),
            'tier_counts' => $tier_counts,
            'decision_ready_rows' => $decision_ready,
            'demand_rows' => $demand_any_rows,
            'demand_decision_ready_rows' => $demand_decision_ready_rows,
            'provider_malformed_empty_rows' => $provider_malformed_empty_rows,
            'engagement_ranges' => (array) ($stats['engagement_ranges'] ?? []),
        ];
    }

    private static function can_say(int $usable_count, array $live_sources): array {
        if ($usable_count <= 0) {
            return [
                'This is a planned run, not an evidence report yet.',
                'Source jobs, planned queries and credit estimates were created.',
                'No live scraping has been executed.',
                'No AI web research has been executed.',
                'No public trend claim can be made until provider data is ingested.',
                'No final credits were settled for this planned/no-evidence run.',
            ];
        }
        $prefix = !empty($live_sources) ? 'Live provider data was ingested from: ' . implode(', ', $live_sources) . '.' : 'Relevant source rows were ingested.';
        return [
            $prefix,
            'Rows were normalized, deduplicated, scored and stored as evidence items.',
            'The report can identify repeated language, format patterns, and validation opportunities.',
            'The report can support brief planning and content testing, not final campaign certainty.',
        ];
    }

    private static function cannot_say(int $usable_count, array $live_sources): array {
        if ($usable_count <= 0) {
            return [
                'No live scraping has been executed.',
                'No live provider evidence has been interpreted yet.',
                'No AI web research has been executed unless a separate AI research bridge is configured.',
                'It cannot produce strategic conclusions from zero evidence.',
            ];
        }
        return [
            'It cannot claim market-wide representativeness from a single run.',
            'It cannot claim causality, sales impact, or campaign success from public engagement signals alone.',
            'It cannot claim deep video, frame, audio or transcript understanding unless those provider fields exist and are reviewed.',
            'It cannot replace human strategic review before client-facing recommendations.',
        ];
    }

    private static function not_claimed(int $usable_count, array $live_sources): array {
        return [
            'No hidden live sources: only configured provider bridges are treated as live.',
            'No deep video/audio understanding unless transcripts or extracted media fields are provided by a source bridge.',
            'No AI web research unless an explicit AI research bridge is configured and enabled.',
            'Credit mode is reported explicitly so planning estimates are not confused with settled usage.',
            'Only relevant, deduplicated evidence rows are exposed in the frontend.',
        ];
    }

    private static function proof_still_needed(array $source_summary, array $quality, array $intel, array $focus): array {
        $out = [];
        if (count(self::sources_with_evidence($source_summary)) < 2) {
            $out[] = 'Add at least one second source family before making a client-facing recommendation.';
        }
        if (self::demand_evidence_rows($source_summary) < 3) {
            $out[] = 'Add search or demand evidence before claiming market demand.';
        }
        if ((int) ($quality['decision_ready_rows'] ?? 0) < 10) {
            $out[] = 'Collect more decision-ready rows where the match appears in evidence text, not only metadata.';
        }
        if ((int) ($intel['creative_only_count'] ?? 0) > 0) {
            $out[] = 'Review creative-only rows manually before converting mechanics into paid media direction.';
        }
        $brand = trim((string) ($focus['brand'] ?? ''));
        if ($brand !== '') {
            $out[] = 'Confirm whether the repeated pattern is actually tied to ' . $brand . ', not just the wider category.';
        }
        return array_values(array_unique($out));
    }

    /**
     * v0.3.55 — campaign-facing Google Ads field preview.
     *
     * The copy is what a CONSUMER would see in a live ad about the topic, for
     * the market/audience under research. Internal-process language ("test the
     * signal route", "validate before campaign") is meta-copy, not ad copy —
     * it can never ship to a platform field. All workflow framing (status,
     * caveat, proof-needed) travels in metadata, never inside the copy text.
     */
    private static function google_ads_asset_block(array $terms, array $hashtags, array $formats, array $quality, array $intel, array $focus, array $source_summary): array {
        $topic = self::campaign_topic($terms, $focus);
        $topic_title = self::asset_title_case($topic);
        $market = self::campaign_phrase((string) ($focus['market'] ?? ''), 16);
        $audience = self::campaign_phrase((string) ($focus['audience'] ?? ''), 32);
        $format = self::plain_asset_term(self::label_from_ranked($formats, 0, 'short-form video'), 'short-form video');
        $where = $market !== '' ? ' in ' . $market : '';
        $who = $audience !== '' ? $audience : 'your audience';

        $demand_rows = self::demand_evidence_rows($source_summary);
        $decision_ready = (int) ($quality['decision_ready_rows'] ?? 0);
        $source_families = count(self::sources_with_evidence($source_summary));
        $usable_rows = (int) array_sum((array) ($quality['tier_counts'] ?? []));
        if ($usable_rows <= 0 && $decision_ready <= 0) {
            $status = 'blocked_insufficient_evidence';
        } elseif ($decision_ready >= 10 && $source_families >= 2 && $demand_rows >= 3) {
            $status = 'ready_for_internal_test';
        } else {
            $status = 'hypothesis';
        }
        $evidence_caveat = 'Draft assets only. Public signals support a test route, not final campaign certainty.';
        if ($demand_rows <= 0) {
            $evidence_caveat = 'Draft assets only. Creative/social evidence does not prove market demand.';
        }
        $proof_needed = self::proof_still_needed($source_summary, $quality, $intel, $focus);

        $headlines = [
            $topic_title . ' ideas' . ($market !== '' ? ' · ' . $market : ''),
            'Discover ' . $topic_title,
            'New ' . $topic . ' content',
            $topic_title . ', made simple',
            'Your ' . $topic . ' guide',
        ];
        $long = [
            'Discover the ' . $topic . ' moments ' . $who . ' are already sharing' . $where,
            'New ' . $topic . ' ideas, formats and stories — made for ' . ($market !== '' ? $market : 'your market'),
            'From ' . $format . ' to everyday moments: ' . $topic . ' content that connects',
            'See what people are talking about around ' . $topic . $where . ' right now',
            'Real ' . $topic . ' stories and formats, picked for ' . $who,
        ];
        $descriptions = [
            'Explore ' . $topic . ' ideas and find the angle that fits your brand' . $where . '.',
            'Fresh ' . $topic . ' content and formats, picked for ' . $who . '.',
            'Join the ' . $topic . ' conversation with formats people already enjoy.',
            'Discover ' . $format . ' takes on ' . $topic . ' worth your attention.',
            $topic_title . $where . ': stories, ideas and moments that matter.',
        ];
        return [
            'platform' => 'Google Ads',
            'status' => $status,
            'status_label' => self::human_label($status),
            'cta_suggestion' => 'Learn more',
            'evidence_caveat' => $evidence_caveat,
            'proof_needed_note' => !empty($proof_needed) ? implode(' ', array_slice($proof_needed, 0, 2)) : 'Human review still required before launch.',
            'derived_from' => [
                'topic' => $topic,
                'market' => $market,
                'audience' => $audience,
                'format' => $format,
                'evidence_terms' => array_slice(array_map(static function ($t) { return is_array($t) ? (string) ($t['label'] ?? $t['term'] ?? '') : (string) $t; }, $terms), 0, 3),
            ],
            'headlines' => self::asset_fields($headlines, 40, $status),
            'long_headlines' => self::asset_fields($long, 90, $status),
            'descriptions' => self::asset_fields($descriptions, 90, $status),
        ];
    }

    /** Words that mark internal-process meta-copy or unsupported certainty — never valid inside platform copy. */
    public static function banned_asset_copy_terms(): array {
        return [
            // internal/meta language
            'future island', 'signal route', 'signal into', 'evidence caveat', 'proof-needed', 'proof needed',
            'hypothesis', 'validate', 'validation', 'internal test', 'reviewed brief', 'asset draft',
            'draft', 'workbench', 'evidence-backed', 'evidence-led', 'copy fields', 'market test',
            'claim readiness', 'decision-ready',
            // unsupported certainty
            'guaranteed', '#1', 'number one', 'best in', 'proven demand', 'everyone is', 'viral success',
        ];
    }

    private static function asset_fields(array $texts, int $limit, string $status): array {
        $banned = self::banned_asset_copy_terms();
        $out = [];
        foreach (array_slice($texts, 0, 5) as $text) {
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
            $count = self::string_length($text);
            $flags = [];
            if ($count > $limit) { $flags[] = 'over_limit'; }
            $lower = strtolower($text);
            foreach ($banned as $needle) {
                if (strpos($lower, $needle) !== false) { $flags[] = 'meta_copy_blocked'; break; }
            }
            $out[] = [
                'text' => $text,
                'limit' => $limit,
                'count' => $count,
                'valid' => empty($flags),
                'flags' => $flags,
                'status' => $status,
                'status_label' => self::human_label($status),
            ];
        }
        return $out;
    }

    /** Topic phrase for campaign copy: best evidence term, then request keyword, never meta filler. */
    private static function campaign_topic(array $terms, array $focus): string {
        $candidates = [self::label_from_ranked($terms, 0, '')];
        foreach ((array) ($focus['keywords'] ?? []) as $kw) { $candidates[] = (string) $kw; }
        foreach ((array) ($focus['core_terms'] ?? []) as $ct) { $candidates[] = (string) $ct; }
        foreach ($candidates as $candidate) {
            $candidate = self::plain_asset_term((string) $candidate, '');
            if ($candidate !== '') { return strtolower($candidate); }
        }
        return 'new ideas';
    }

    private static function campaign_phrase(string $value, int $max): string {
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($value)));
        if ($value === '') { return ''; }
        if (self::string_length($value) <= $max) { return $value; }
        $cut = function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
        $space = strrpos($cut, ' ');
        return $space !== false && $space > 3 ? substr($cut, 0, $space) : $cut;
    }

    private static function asset_title_case(string $value): string {
        return preg_replace_callback('/\b[a-z]/', static function ($m) { return strtoupper($m[0]); }, strtolower($value));
    }

    private static function plain_asset_term(string $term, string $fallback = 'new ideas'): string {
        $term = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($term)));
        $term = preg_replace('/^#/', '', (string) $term);
        if ($term === '' || self::string_length($term) > 24) { return $fallback; }
        return $term;
    }

    private static function source_state_from_summary(array $summary): string {
        $status = sanitize_key((string) ($summary['status'] ?? ''));
        $error = sanitize_key((string) ($summary['error_code'] ?? ''));
        $provider = (int) ($summary['provider_returned_items'] ?? 0);
        $parsed = (int) ($summary['parsed_items'] ?? 0);
        $normalized = (int) ($summary['normalized_items'] ?? 0);
        $relevant = (int) ($summary['relevant_count'] ?? $summary['relevant_items'] ?? 0);
        if ($error === 'invalid_input') { return 'invalid_input'; }
        if ($error === 'actor_not_allowlisted' || strpos($error, 'allowlist') !== false) { return 'source_actor_not_allowlisted'; }
        if ($error !== '') { return 'provider_result_discarded'; }
        if (in_array($status, ['skipped', 'not_allowed', 'unavailable'], true)) { return 'source_' . $status; }
        if ($provider <= 0 && in_array($status, ['completed', 'done', 'success'], true)) { return 'zero_provider_results'; }
        if ($provider > 0 && $parsed <= 0) { return 'provider_result_parsing_failed'; }
        if ($provider > 0 && $relevant <= 0) { return 'provider_results_zero_usable_evidence'; }
        if ($relevant > 0) { return 'usable_evidence_available'; }
        if (in_array($status, ['planned', 'queued', 'running'], true)) { return 'source_' . $status; }
        return 'source_unavailable';
    }

    private static function source_state_label(string $state): string {
        $map = [
            'zero_provider_results' => '0 provider results',
            'provider_results_zero_usable_evidence' => 'Provider returned rows, 0 usable evidence rows',
            'provider_result_parsing_failed' => 'Provider result exists, parsing failed',
            'provider_result_discarded' => 'Provider result discarded',
            'source_skipped' => 'Source skipped',
            'source_unavailable' => 'Source unavailable',
            'source_not_allowed' => 'Source not available on this server',
            'source_actor_not_allowlisted' => 'Source actor not allowlisted',
            'invalid_input' => 'Invalid input',
            'usable_evidence_available' => 'Usable evidence available',
            'source_planned' => 'Planned, not run yet',
            'source_queued' => 'Queued',
            'source_running' => 'Running',
        ];
        return $map[$state] ?? self::human_label($state);
    }

    private static function source_status_label(string $status, string $error_code = ''): string {
        if ($error_code !== '') { return self::human_label($error_code); }
        return self::human_label($status !== '' ? $status : 'planned');
    }

    private static function add_cluster_term(array &$cluster, string $term): void {
        $term = trim((string) $term);
        if ($term === '') { return; }
        if (!isset($cluster['raw_terms']) || !is_array($cluster['raw_terms'])) { $cluster['raw_terms'] = []; }
        if (count($cluster['raw_terms']) < 6) { $cluster['raw_terms'][] = $term; }
    }

    private static function human_cluster_label(string $label, array $focus): string {
        $label = trim($label);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        $blocked = ['world','cup','team','video','today','people','trend','viral'];
        if ($label === '' || in_array($lower, $blocked, true)) { return 'Low-confidence signal cluster'; }
        if ($lower === 'world cup' || $lower === 'world cup cultural moment') { return 'World Cup cultural moment'; }
        if (strpos($lower, 'fifa world cup') !== false) { return 'FIFA World Cup 2026 conversation'; }
        if (strpos($lower, 'football') !== false && (strpos($lower, 'meme') !== false || strpos($lower, 'hook') !== false || strpos($lower, 'format') !== false)) { return $label; }
        if (strpos($lower, 'google trends') !== false) { return 'Spain + World Cup search interest'; }
        return self::human_label($label);
    }

    private static function human_label(string $value): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        $map = [
            'ready_for_internal_test_not_market_claim' => 'Ready for internal test, not market claim',
            'ready_for_internal_test' => 'Ready for internal test',
            'json_only_unverified' => 'JSON-only, not file-verified',
            'provider_transport_error' => 'Provider connection issue',
            'actor_dispatch_failed' => 'Source actor failed to start',
            'invalid_input' => 'Invalid input',
            'not_proven' => 'Not proven',
            'weak_keyword_only' => 'Weak keyword-only fit',
            'directional_internal' => 'Directional internal fit',
            'directional_search_interest' => 'Directional search interest',
            'single_point_not_enough' => 'Single point, not enough proof',
            'brief_ready_after_review' => 'Brief-ready after review',
            'hypothesis_only' => 'Hypothesis only',
            'hypothesis' => 'Hypothesis — internal draft',
            'blocked_insufficient_evidence' => 'Blocked — insufficient evidence',
            'not_client_ready' => 'Not client-ready',
            'do_not_claim' => 'Do not claim',
            'source_actor_not_allowlisted' => 'Source actor not allowlisted',
            'provider_actor_not_allowlisted' => 'Source actor not allowlisted on this server',
            'provider_timeout_stale' => 'Source run timed out (watchdog)',
        ];
        $key = sanitize_key($value);
        if (isset($map[$key])) { return $map[$key]; }
        $label = str_replace(['_', '-'], ' ', $value);
        $label = preg_replace('/\s+/', ' ', $label);
        $label = ucfirst(trim($label));
        $label = str_replace(['Tiktok', 'Google trends', 'Google news'], ['TikTok', 'Google Trends', 'Google News'], $label);
        return $label;
    }

    private static function plural(int $count, string $one, string $many): string {
        return abs($count) === 1 ? $one : $many;
    }

    private static function string_length(string $text): int {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function credit_estimate_for_run(array $run): array {
        $channels = [];
        if (!empty($run['channels_json'])) {
            $decoded = json_decode((string) $run['channels_json'], true);
            if (is_array($decoded)) { $channels = $decoded; }
        }
        if (empty($channels) && !empty($run['ai_planner_output_json'])) {
            $plan = json_decode((string) $run['ai_planner_output_json'], true);
            if (is_array($plan) && !empty($plan['sources'])) { $channels = array_keys((array) $plan['sources']); }
        }
        $request = [
            'user_brief' => (string) ($run['user_brief'] ?? ''),
            'objective' => (string) ($run['objective'] ?? ''),
            'market' => (string) ($run['market'] ?? ''),
            'language' => (string) ($run['language'] ?? ''),
            'date_range' => (string) ($run['date_range'] ?? 'last_30_days'),
            'channels' => $channels,
        ];
        return FIDTF_Credit_Service::estimate($request, $channels);
    }

    private static function source_summary(array $jobs, array $items): array {
        $summary = [];
        foreach ($jobs as $job) {
            $source = sanitize_key((string) ($job['source_key'] ?? 'unknown'));
            $provider_returned = max(
                (int) ($job['provider_returned_items'] ?? 0),
                (int) ($job['provider_dataset_total_rows'] ?? 0),
                (int) ($job['provider_dataset_rows'] ?? 0),
                (int) ($job['raw_count'] ?? 0)
            );
            $parsed = max(
                (int) ($job['parsed_items'] ?? 0),
                (int) ($job['flattened_raw_items'] ?? 0),
                (int) ($job['normalized_count'] ?? 0)
            );
            $normalized = (int) ($job['normalized_items'] ?? $job['normalized_count'] ?? 0);
            $relevant = (int) ($job['relevant_items'] ?? $job['relevant_count'] ?? 0);
            $actor_dataset = max((int) ($job['actor_dataset_items'] ?? 0), (int) ($job['provider_dataset_rows'] ?? 0));
            $status = FIDTF_Source_Job_Service::sanitize_status((string) ($job['status'] ?? ''), 'planned');
            $error_code = sanitize_text_field((string) ($job['error_code'] ?? ''));
            $summary[$source] = [
                'status' => $status,
                'status_label' => self::source_status_label($status, $error_code),
                'source_state' => '',
                'source_state_label' => '',
                'raw_count' => (int) ($job['raw_count'] ?? 0),
                'provider_returned_items' => $provider_returned,
                'actor_dataset_items' => $actor_dataset,
                'parsed_items' => $parsed,
                'normalized_items' => $normalized,
                'normalized_count' => $normalized,
                'relevant_items' => $relevant,
                'relevant_count' => $relevant,
                'provider_dataset_rows' => (int) ($job['provider_dataset_rows'] ?? 0),
                'provider_dataset_total_rows' => (int) ($job['provider_dataset_total_rows'] ?? 0),
                'flattened_raw_items' => (int) ($job['flattened_raw_items'] ?? 0),
                'decision_ready_items' => 0,
                'decision_ready_count' => 0,
                'creative_only_items' => 0,
                'creative_only_count' => 0,
                'weak_items' => 0,
                'weak_or_noise_count' => 0,
                'discarded_items' => 0,
                'discard_reasons' => [],
                'error_code' => $error_code,
                'error_label' => $error_code !== '' ? self::human_label($error_code) : '',
            ];
        }
        $item_counts = [];
        $tier_counts_by_source = [];
        foreach ($items as $item) {
            $source = sanitize_key((string) ($item['source_key'] ?? $item['source'] ?? 'unknown'));
            if (!isset($tier_counts_by_source[$source])) {
                $tier_counts_by_source[$source] = ['decision_ready_count' => 0, 'creative_only_count' => 0, 'weak_or_noise_count' => 0, 'discarded_items' => 0, 'discard_reasons' => []];
            }
            $tier = sanitize_key((string) ($item['evidence_tier'] ?? 'weak'));
            $use = sanitize_key((string) ($item['recommended_use'] ?? ''));
            $is_report_relevant = ($tier !== 'noise' && $use !== 'hide');
            if ($is_report_relevant) {
                $item_counts[$source] = ($item_counts[$source] ?? 0) + 1;
            }
            if (in_array($tier, ['strong','support'], true) && $is_report_relevant) {
                $tier_counts_by_source[$source]['decision_ready_count']++;
            } elseif ($tier === 'creative_only' && $is_report_relevant) {
                $tier_counts_by_source[$source]['creative_only_count']++;
            } else {
                $tier_counts_by_source[$source]['weak_or_noise_count']++;
                if (!$is_report_relevant) {
                    $tier_counts_by_source[$source]['discarded_items']++;
                    $reason = sanitize_key((string) ($item['discard_reason'] ?? ($use === 'hide' ? 'hidden_by_relevance_gate' : 'noise_tier')));
                    $tier_counts_by_source[$source]['discard_reasons'][$reason] = true;
                }
            }
            if (!isset($summary[$source])) {
                $summary[$source] = [
                    'status' => 'ingested', 'status_label' => 'Ingested', 'source_state' => '', 'source_state_label' => '',
                    'raw_count' => 0, 'provider_returned_items' => 0, 'actor_dataset_items' => 0, 'parsed_items' => 0,
                    'normalized_items' => 0, 'normalized_count' => 0, 'relevant_items' => 0, 'relevant_count' => 0,
                    'provider_dataset_rows' => 0, 'provider_dataset_total_rows' => 0, 'flattened_raw_items' => 0,
                    'decision_ready_items' => 0, 'decision_ready_count' => 0, 'creative_only_items' => 0,
                    'creative_only_count' => 0, 'weak_items' => 0, 'weak_or_noise_count' => 0,
                    'discarded_items' => 0, 'discard_reasons' => [], 'error_code' => '', 'error_label' => '',
                ];
            }
        }
        foreach ($summary as $source => $row) {
            $source = sanitize_key((string) $source);
            $counts = (array) ($tier_counts_by_source[$source] ?? []);
            $relevant = max((int) ($row['relevant_count'] ?? 0), (int) ($item_counts[$source] ?? 0));
            $decision = (int) ($counts['decision_ready_count'] ?? $row['decision_ready_count'] ?? 0);
            $creative = (int) ($counts['creative_only_count'] ?? $row['creative_only_count'] ?? 0);
            $weak = (int) ($counts['weak_or_noise_count'] ?? $row['weak_or_noise_count'] ?? 0);
            $provider_returned = (int) ($row['provider_returned_items'] ?? 0);
            $parsed = (int) ($row['parsed_items'] ?? 0);
            $normalized = (int) ($row['normalized_items'] ?? 0);
            $discarded_from_pipeline = max(0, $provider_returned - $relevant);
            $discarded = max((int) ($counts['discarded_items'] ?? 0), $discarded_from_pipeline);
            $reasons = array_keys((array) ($counts['discard_reasons'] ?? []));
            if ($provider_returned > 0 && $parsed <= 0) { $reasons[] = 'provider_result_not_parseable'; }
            elseif ($parsed > 0 && $normalized <= 0) { $reasons[] = 'parsed_but_not_normalized'; }
            elseif ($normalized > 0 && $relevant <= 0) { $reasons[] = 'normalized_but_not_relevant'; }
            if ((string) ($row['error_code'] ?? '') !== '') { $reasons[] = (string) $row['error_code']; }
            $summary[$source]['relevant_count'] = $relevant;
            $summary[$source]['relevant_items'] = $relevant;
            $summary[$source]['decision_ready_count'] = $decision;
            $summary[$source]['decision_ready_items'] = $decision;
            $summary[$source]['creative_only_count'] = $creative;
            $summary[$source]['creative_only_items'] = $creative;
            $summary[$source]['weak_or_noise_count'] = $weak;
            $summary[$source]['weak_items'] = $weak;
            $summary[$source]['discarded_items'] = $discarded;
            $summary[$source]['discard_reasons'] = array_values(array_unique(array_filter(array_map('sanitize_key', $reasons))));
            $state = self::source_state_from_summary($summary[$source]);
            $summary[$source]['source_state'] = $state;
            $summary[$source]['source_state_label'] = self::source_state_label($state);
        }
        return $summary;
    }

    private static function statistical_summary(array $source_summary, array $items): array {
        $by_source = [];
        $by_signal_type = [];
        $engagement_ranges = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($source_summary as $source => $summary) {
            $by_source[$source] = [
                'provider_returned_items' => (int) ($summary['provider_returned_items'] ?? 0),
                'actor_dataset_items' => (int) ($summary['actor_dataset_items'] ?? 0),
                'parsed_items' => (int) ($summary['parsed_items'] ?? 0),
                'normalized_items' => (int) ($summary['normalized_items'] ?? 0),
                'raw_count' => (int) ($summary['raw_count'] ?? 0),
                'relevant_count' => (int) ($summary['relevant_count'] ?? 0),
                'decision_ready_items' => (int) ($summary['decision_ready_items'] ?? 0),
                'creative_only_items' => (int) ($summary['creative_only_items'] ?? 0),
                'weak_items' => (int) ($summary['weak_items'] ?? 0),
                'discarded_items' => (int) ($summary['discarded_items'] ?? 0),
                'provider_dataset_rows' => (int) ($summary['provider_dataset_rows'] ?? 0),
            ];
        }
        foreach ($items as $item) {
            $signal = sanitize_text_field((string) ($item['signal_type'] ?? 'unknown'));
            $by_signal_type[$signal] = ($by_signal_type[$signal] ?? 0) + 1;
            $engagement = self::engagement_score($item);
            if ($engagement >= 100000) { $engagement_ranges['high']++; }
            elseif ($engagement >= 1000) { $engagement_ranges['medium']++; }
            else { $engagement_ranges['low']++; }
        }
        return [
            'total_provider_returned_items' => array_sum(array_map(static function($row) { return (int) ($row['provider_returned_items'] ?? 0); }, $by_source)),
            'total_actor_dataset_items' => array_sum(array_map(static function($row) { return (int) ($row['actor_dataset_items'] ?? 0); }, $by_source)),
            'total_parsed_items' => array_sum(array_map(static function($row) { return (int) ($row['parsed_items'] ?? 0); }, $by_source)),
            'total_normalized_items' => array_sum(array_map(static function($row) { return (int) ($row['normalized_items'] ?? 0); }, $by_source)),
            'total_raw_items' => array_sum(array_map(static function($row) { return (int) ($row['parsed_items'] ?? $row['raw_count'] ?? 0); }, $by_source)),
            'total_relevant_items' => count($items),
            'total_decision_ready_items' => array_sum(array_map(static function($row) { return (int) ($row['decision_ready_items'] ?? 0); }, $by_source)),
            'total_creative_only_items' => array_sum(array_map(static function($row) { return (int) ($row['creative_only_items'] ?? 0); }, $by_source)),
            'total_weak_items' => array_sum(array_map(static function($row) { return (int) ($row['weak_items'] ?? 0); }, $by_source)),
            'total_discarded_items' => array_sum(array_map(static function($row) { return (int) ($row['discarded_items'] ?? 0); }, $by_source)),
            'by_source' => $by_source,
            'by_signal_type' => $by_signal_type,
            'engagement_ranges' => $engagement_ranges,
        ];
    }

    private static function decode_items(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['normalized_json'])) {
                $decoded = json_decode((string) $row['normalized_json'], true);
                if (is_array($decoded)) {
                    $decoded['relevance_score'] = (float) ($row['relevance_score'] ?? ($decoded['relevance_score'] ?? 0));
                    $decoded['relevance_reason'] = (string) ($row['relevance_reason'] ?? ($decoded['relevance_reason'] ?? ''));
                    $out[] = $decoded;
                    continue;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function group_by_source(array $items): array {
        $out = [];
        foreach ($items as $item) {
            $source = sanitize_key((string) ($item['source_key'] ?? $item['source'] ?? 'unknown'));
            $out[$source][] = $item;
        }
        return $out;
    }

    private static function sources_with_evidence(array $source_summary): array {
        $out = [];
        foreach ($source_summary as $source => $summary) {
            if ((int) ($summary['relevant_count'] ?? $summary['relevant_items'] ?? 0) > 0) { $out[] = (string) $source; }
        }
        return $out;
    }

    private static function sources_with_provider_rows(array $source_summary): array {
        $out = [];
        foreach ($source_summary as $source => $summary) {
            $rows = max((int) ($summary['provider_returned_items'] ?? 0), (int) ($summary['actor_dataset_items'] ?? 0), (int) ($summary['provider_dataset_rows'] ?? 0));
            if ($rows > 0) { $out[] = (string) $source; }
        }
        return $out;
    }

    private static function confidence_label(float $top_score, int $count, int $source_count): string {
        if ($top_score >= 75 && $count >= 10 && $source_count >= 2) { return 'medium'; }
        if ($top_score >= 50 && $count >= 20) { return 'low-medium'; }
        if ($top_score >= 50) { return 'directional'; }
        return 'low';
    }

    private static function overall_confidence(int $usable_count, array $stats): string {
        $sources_with_evidence = 0;
        foreach ((array) ($stats['by_source'] ?? []) as $row) {
            if ((int) ($row['relevant_count'] ?? 0) > 0) { $sources_with_evidence++; }
        }
        if ($usable_count >= 80 && $sources_with_evidence >= 3) { return 'medium'; }
        if ($usable_count >= 40 && $sources_with_evidence >= 2) { return 'directional-medium'; }
        return $usable_count > 0 ? 'directional-low-medium' : 'low';
    }

    private static function top_items(array $items, int $limit = 5): array {
        usort($items, static function($a, $b) {
            $score_b = (float) ($b['relevance_score'] ?? 0) + min(25, self::engagement_score($b) / 5000);
            $score_a = (float) ($a['relevance_score'] ?? 0) + min(25, self::engagement_score($a) / 5000);
            return $score_b <=> $score_a;
        });
        return array_slice($items, 0, $limit);
    }

    private static function top_terms(array $items, int $limit = 10): array {
        $text = [];
        foreach ($items as $item) {
            // Provider query is provenance, not evidence language. Do not let the
            // requested search terms dominate the strategic readout unless the
            // returned row actually contains those terms in title/body/caption.
            $text[] = (string) ($item['title'] ?? '');
            $text[] = (string) ($item['caption_or_text'] ?? '');
            $text[] = (string) ($item['text'] ?? '');
            $text[] = (string) ($item['caption'] ?? '');
        }
        $joined = implode(' ', $text);
        $counts = self::token_counts($joined);
        foreach (self::phrase_counts($joined) as $phrase => $count) {
            $counts[$phrase] = max((int) ($counts[$phrase] ?? 0), (int) $count + 1);
        }
        return self::ranked_counts($counts, $limit);
    }

    private static function top_hashtags(array $items, int $limit = 10): array {
        $counts = [];
        foreach ($items as $item) {
            foreach ((array) ($item['hashtags'] ?? []) as $tag) {
                $tag = trim(strtolower((string) $tag));
                $tag = ltrim($tag, '#');
                if ($tag === '' || self::is_report_noise_term($tag, true)) { continue; }
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
            $text = (string) (($item['title'] ?? '') . ' ' . ($item['caption_or_text'] ?? ''));
            if (preg_match_all('/#([\p{L}\p{N}_\-]+)/u', $text, $m)) {
                foreach ($m[1] as $tag) {
                    $tag = strtolower((string) $tag);
                    if ($tag === '' || self::is_report_noise_term($tag, true)) { continue; }
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
        }
        return self::ranked_counts($counts, $limit, '#');
    }

    private static function format_patterns(array $items): array {
        $counts = [];
        foreach ($items as $item) {
            $text = strtolower((string) (($item['title'] ?? '') . ' ' . ($item['caption_or_text'] ?? '')));
            $media = (array) ($item['media'] ?? []);
            if (strpos($text, 'pov') !== false || strpos($text, 'point of view') !== false) { $counts['creator POV'] = ($counts['creator POV'] ?? 0) + 1; }
            if (strpos($text, 'recipe') !== false || strpos($text, 'how to') !== false || strpos($text, 'make') !== false) { $counts['recipe / how-to'] = ($counts['recipe / how-to'] ?? 0) + 1; }
            if (strpos($text, 'meme') !== false || strpos($text, '😂') !== false || strpos($text, '🤣') !== false) { $counts['humor / meme'] = ($counts['humor / meme'] ?? 0) + 1; }
            if (strpos($text, 'summer') !== false || strpos($text, 'fiesta') !== false || strpos($text, 'party') !== false) { $counts['seasonal / occasion'] = ($counts['seasonal / occasion'] ?? 0) + 1; }
            if (($media['type'] ?? '') === 'video') { $counts['short-form video'] = ($counts['short-form video'] ?? 0) + 1; }
            if (strlen($text) < 90) { $counts['short caption / hook'] = ($counts['short caption / hook'] ?? 0) + 1; }
        }
        return self::ranked_counts($counts, 8);
    }

    private static function token_counts(string $text): array {
        $text = strtolower(wp_strip_all_tags($text));
        $counts = [];
        if (!preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_\-]{2,}/u', $text, $m)) { return []; }
        $stop = array_flip(['the','and','for','with','that','this','from','are','was','were','you','your','our','their','para','con','que','por','una','uno','los','las','del','de','la','el','en','y','un','to','of','in','on','it','is','be','or','as','at','by','a','i','me','my','we','us','not','but','all','every','day','will','can','has','have','had','como','pero','mas','más','sin','sus','todo','todos','esta','este','eso','esa']);
        foreach ($m[0] as $token) {
            $token = trim((string) $token);
            if ($token === '' || isset($stop[$token]) || strlen($token) < 3 || self::is_report_noise_term($token, false)) { continue; }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }
        return $counts;
    }


    private static function is_report_noise_term(string $term, bool $is_hashtag = false): bool {
        $term = strtolower(trim($term));
        $term = ltrim($term, '#');
        if ($term === '') { return true; }
        $term = preg_replace('/[^a-z0-9_\-\p{L}\p{N}]+/u', '', $term);
        if ($term === '' || strlen($term) < 3) { return true; }
        $artifacts = [
            'amp','nbsp','http','https','www','com','html','utm','utm_source','utm_medium','utm_campaign','ref','src','href','redd','redditcom','tiktokcom','instagramcom','twittercom','youtubecom','bitly','linkinbio'
        ];
        if (in_array($term, $artifacts, true)) { return true; }
        if (preg_match('/^[0-9]+$/', $term)) { return true; }
        if (preg_match('/^https?$/', $term)) { return true; }
        $platform_noise = ['fyp','fy','foryou','foryoupage','viral','viralpost','trending','trend','trends','explore','explorepage','reels','reel','shorts','video','myvideo','parati','xyzbca','follow','like','likes','share','comment','comments','views','view','capcut'];
        $generic_singletons = ['world','cup','team','today','people','person','thing','things','good','best','new','old','real','time','just','very','more','most','less','first','last','great','big','small','one','two','three','watch','seen','here','there'];
        return in_array($term, array_merge($platform_noise, $generic_singletons), true);
    }

    private static function phrase_counts(string $text): array {
        $text = strtolower(wp_strip_all_tags($text));
        $counts = [];
        $known = [
            'world cup' => 'World Cup cultural moment',
            'fifa world cup' => 'FIFA World Cup conversation',
            'short form' => 'short-form creative mechanics',
            'short-form' => 'short-form creative mechanics',
            'google trends' => 'Google Trends signal',
            'google news' => 'Google News framing',
        ];
        foreach ($known as $needle => $label) {
            $found = substr_count($text, $needle);
            if ($found > 0) { $counts[$label] = ($counts[$label] ?? 0) + $found; }
        }
        if (preg_match_all('/(?:fifa\s+)?world\s+cup\s+2026/u', $text, $m)) {
            $counts['FIFA World Cup 2026 conversation'] = ($counts['FIFA World Cup 2026 conversation'] ?? 0) + count($m[0]);
        }
        if (preg_match_all('/(?:tiktok|short[- ]form)\s+(?:football|soccer|futbol|fútbol)\s+(?:hook|meme|format|mechanic)s?/u', $text, $m)) {
            $counts['TikTok-style football hook format'] = ($counts['TikTok-style football hook format'] ?? 0) + count($m[0]);
        }
        if (preg_match_all('/(?:football|soccer|futbol|fútbol)\s+(?:meme|memes|hook|hooks|format|formats)/u', $text, $m)) {
            $counts['short-form football meme mechanics'] = ($counts['short-form football meme mechanics'] ?? 0) + count($m[0]);
        }
        return $counts;
    }

    private static function ranked_counts(array $counts, int $limit, string $prefix = ''): array {
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $label => $count) {
            $out[] = ['label' => $prefix . (string) $label, 'count' => (int) $count];
        }
        return $out;
    }

    private static function ranked_labels_sentence(array $ranked, int $limit): string {
        $labels = [];
        foreach (array_slice($ranked, 0, $limit) as $row) {
            $label = (string) ($row['label'] ?? '');
            if ($label !== '') { $labels[] = $label; }
        }
        return !empty($labels) ? implode(', ', $labels) : 'no repeated pattern strong enough yet';
    }

    private static function label_from_ranked(array $ranked, int $index, string $fallback): string {
        return (string) ($ranked[$index]['label'] ?? $fallback);
    }

    private static function engagement_score(array $item): float {
        $m = (array) ($item['metrics'] ?? []);
        return (float) (($m['views'] ?? 0) + (($m['likes'] ?? 0) * 3) + (($m['comments'] ?? 0) * 8) + (($m['shares'] ?? 0) * 10) + (($m['bookmarks'] ?? 0) * 5) + (($m['score'] ?? 0) * 20));
    }

    private static function engagement_for_item(array $item): string {
        $m = (array) ($item['metrics'] ?? []);
        $parts = [];
        foreach (['views' => 'views', 'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'bookmarks' => 'bookmarks'] as $key => $label) {
            if (!empty($m[$key])) { $parts[] = self::format_number((float) $m[$key]) . ' ' . $label; }
        }
        return implode(', ', $parts);
    }

    private static function format_number(float $value): string {
        if (function_exists('number_format_i18n')) { return number_format_i18n($value); }
        return number_format($value);
    }

    private static function engagement_spread_sentence(array $stats): string {
        $ranges = (array) ($stats['engagement_ranges'] ?? []);
        return 'high: ' . (int) ($ranges['high'] ?? 0) . ', medium: ' . (int) ($ranges['medium'] ?? 0) . ', low: ' . (int) ($ranges['low'] ?? 0);
    }

    private static function top_item_sentence(array $item): string {
        if (empty($item)) { return 'No representative evidence item available.'; }
        $title = self::short_text((string) (($item['title'] ?? '') ?: ($item['caption_or_text'] ?? '')), 140);
        $eng = self::engagement_for_item($item);
        return $title . ($eng !== '' ? ' — ' . $eng : '');
    }

    private static function first_non_empty(array $values): string {
        foreach ($values as $value) {
            $value = trim(wp_strip_all_tags((string) $value));
            if ($value !== '') { return $value; }
        }
        return '';
    }

    private static function sanitize_external_report(array $report): array {
        if (isset($report['error']) && is_array($report['error'])) { return []; }
        if (isset($report['status']) && in_array(sanitize_key((string) $report['status']), ['incomplete', 'failed', 'cancelled', 'canceled', 'requires_action'], true)) { return []; }
        if (!isset($report['executive_summary']) && !isset($report['insights']) && !isset($report['opportunities'])) {
            $parsed = self::external_parsed_payload($report);
            if (is_array($parsed)) {
                $report = $parsed;
            } else {
                $model_text = self::external_model_text($report);
                if ($model_text !== '') {
                    $decoded = self::decode_external_json_text($model_text);
                    if (is_array($decoded)) { $report = $decoded; }
                }
            }
        }
        $allowed = [];
        foreach (['status', 'status_label', 'executive_summary', 'key_patterns', 'strategic_readout', 'content_angle_briefs', 'recommended_validation_plan', 'evidence_quality_summary', 'insights', 'opportunities', 'warnings', 'confidence'] as $key) {
            if (isset($report[$key])) { $allowed[$key] = $report[$key]; }
        }
        if (!empty($allowed['executive_summary'])) { $allowed['executive_summary'] = self::short_text((string) $allowed['executive_summary'], 1200); }
        return $allowed;
    }

    private static function external_parsed_payload(array $result) {
        $candidates = [];
        foreach (['parsed', 'output_parsed', 'json', 'structured_output'] as $field) {
            if (isset($result[$field]) && is_array($result[$field])) { $candidates[] = $result[$field]; }
        }
        if (isset($result['choices'][0]['message']) && is_array($result['choices'][0]['message'])) {
            foreach (['parsed', 'output_parsed'] as $field) {
                if (isset($result['choices'][0]['message'][$field]) && is_array($result['choices'][0]['message'][$field])) { $candidates[] = $result['choices'][0]['message'][$field]; }
            }
        }
        if (isset($result['output']) && is_array($result['output'])) {
            foreach ((array) $result['output'] as $output_item) {
                if (!is_array($output_item)) { continue; }
                foreach (['parsed', 'output_parsed'] as $field) {
                    if (isset($output_item[$field]) && is_array($output_item[$field])) { $candidates[] = $output_item[$field]; }
                }
                if (isset($output_item['content']) && is_array($output_item['content'])) {
                    foreach ((array) $output_item['content'] as $content_item) {
                        if (!is_array($content_item)) { continue; }
                        foreach (['parsed', 'output_parsed'] as $field) {
                            if (isset($content_item[$field]) && is_array($content_item[$field])) { $candidates[] = $content_item[$field]; }
                        }
                    }
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($candidate['executive_summary']) || isset($candidate['insights']) || isset($candidate['opportunities'])) { return $candidate; }
        }
        return null;
    }

    private static function external_model_text(array $result): string {
        $candidates = [];
        if (isset($result['error']) && is_array($result['error'])) { return ''; }
        if (isset($result['status']) && in_array(sanitize_key((string) $result['status']), ['incomplete', 'failed', 'cancelled', 'canceled', 'requires_action'], true)) { return ''; }
        if (isset($result['output_text'])) { $candidates[] = $result['output_text']; }
        if (isset($result['content'])) {
            if (is_array($result['content'])) { $candidates = array_merge($candidates, self::external_text_from_blocks((array) $result['content'])); }
            else { $candidates[] = $result['content']; }
        }
        if (isset($result['choices'][0]['message']['content'])) {
            $content = $result['choices'][0]['message']['content'];
            if (is_array($content)) { $candidates = array_merge($candidates, self::external_text_from_blocks((array) $content)); }
            else { $candidates[] = $content; }
        }
        if (isset($result['output']) && is_array($result['output'])) {
            foreach ((array) $result['output'] as $output_item) {
                if (!is_array($output_item)) { continue; }
                if (isset($output_item['content']) && is_array($output_item['content'])) {
                    $candidates = array_merge($candidates, self::external_text_from_blocks((array) $output_item['content']));
                }
                foreach (['text', 'content', 'output_text'] as $field) {
                    if (isset($output_item[$field]) && is_scalar($output_item[$field])) { $candidates[] = (string) $output_item[$field]; }
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                $text = trim((string) $candidate);
                if ($text !== '') { return $text; }
            }
        }
        return '';
    }

    private static function external_text_from_blocks(array $blocks): array {
        $out = [];
        foreach ($blocks as $block) {
            if (is_string($block) || is_numeric($block)) { $out[] = (string) $block; continue; }
            if (!is_array($block)) { continue; }
            if (isset($block['refusal'])) { continue; }
            foreach (['text', 'content', 'output_text'] as $field) {
                if (isset($block[$field]) && is_scalar($block[$field])) { $out[] = (string) $block[$field]; }
            }
        }
        return $out;
    }

    private static function decode_external_json_text(string $text) {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', (string) $text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) { return $decoded; }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) { return $decoded; }
        }
        return null;
    }

    private static function store_report(int $run_id, array $source_summary, array $report): int {
        global $wpdb;
        if (!self::db_ready()) { return 0; }
        $html = self::render_html($report);
        $inserted = $wpdb->insert(FIDTF_DB::table('reports'), [
            'run_id' => $run_id,
            'report_type' => 'final_synthesis',
            'status' => sanitize_key((string) ($report['status'] ?? 'draft')),
            'source_summary_json' => wp_json_encode($source_summary),
            'final_synthesis_json' => wp_json_encode($report),
            'html_snapshot' => $html,
            'created_at' => current_time('mysql'),
        ]);
        if (false === $inserted) { return 0; }
        return (int) $wpdb->insert_id;
    }

    private static function short_text(string $text, int $limit = 180): string {
        $text = trim(wp_strip_all_tags($text));
        if (function_exists('mb_substr')) { return mb_substr($text, 0, $limit, 'UTF-8'); }
        return substr($text, 0, $limit);
    }

    private static function db_ready(): bool {
        global $wpdb;
        return is_object($wpdb) && method_exists($wpdb, 'insert') && isset($wpdb->prefix);
    }
}
