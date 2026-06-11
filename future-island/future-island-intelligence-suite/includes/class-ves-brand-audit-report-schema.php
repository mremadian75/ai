<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Strict client-ready Brand Deep Audit report schema and normalizer.
 */
final class VES_Brand_Audit_Report_Schema {
    const PROMPT_VERSION = 'brand_deep_audit_report_schema_v2_2026_05_12';
    const MIN_EVIDENCE_FOR_COMPLETED = 10;
    const MIN_EVIDENCE_FOR_LIMITED = 4;

    public static function openai_response_format() {
        return [
            'type' => 'json_schema',
            'name' => 'brand_deep_audit_report',
            'strict' => true,
            'schema' => self::schema(),
        ];
    }

    public static function schema() {
        $s = static function() { return ['type' => 'string']; };
        $n = static function() { return ['type' => 'number']; };
        $i = static function() { return ['type' => 'integer']; };
        $b = static function() { return ['type' => 'boolean']; };
        $str_arr = ['type' => 'array', 'items' => ['type' => 'string']];
        $evidence_arr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['report_meta','executive_verdict','evidence_quality','what_we_know','what_we_do_not_know','key_patterns','strategic_diagnosis','competitor_implications','opportunities','recommended_actions','briefs','next_data_collection_plan','evidence_appendix'],
            'properties' => [
                'report_meta' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['brand_name','market','language','status','evidence_count','confidence','generated_at','model','prompt_version'],
                    'properties' => [
                        'brand_name' => $s(), 'market' => $s(), 'language' => $s(),
                        'status' => ['type' => 'string', 'enum' => ['completed','completed_with_limitations','needs_more_data','failed']],
                        'evidence_count' => $i(), 'confidence' => $n(), 'generated_at' => $s(), 'model' => $s(), 'prompt_version' => $s(),
                    ],
                ],
                'executive_verdict' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['headline','summary','confidence','client_ready','limitations'],
                    'properties' => ['headline' => $s(), 'summary' => $s(), 'confidence' => $n(), 'client_ready' => $b(), 'limitations' => $str_arr],
                ],
                'evidence_quality' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['usable_count','partial_count','failed_count','source_breakdown','freshness','reliability_notes','missing_sources'],
                    'properties' => [
                        'usable_count' => $i(), 'partial_count' => $i(), 'failed_count' => $i(),
                        'source_breakdown' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'required' => ['source','usable_count','total_count','notes'],
                            'properties' => ['source' => $s(), 'usable_count' => $i(), 'total_count' => $i(), 'notes' => $s()],
                        ]],
                        'freshness' => $s(), 'reliability_notes' => $str_arr, 'missing_sources' => $str_arr,
                    ],
                ],
                'what_we_know' => $str_arr,
                'what_we_do_not_know' => $str_arr,
                'key_patterns' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['claim','evidence','confidence','why_it_matters','missing_data'],
                    'properties' => ['claim' => $s(), 'evidence' => $evidence_arr, 'confidence' => $n(), 'why_it_matters' => $s(), 'missing_data' => $str_arr],
                ]],
                'strategic_diagnosis' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['diagnosis','business_implication','risk','confidence'],
                    'properties' => ['diagnosis' => $s(), 'business_implication' => $s(), 'risk' => $s(), 'confidence' => $n()],
                ],
                'competitor_implications' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['competitor','observed_gap','evidence','implication','recommended_response','confidence'],
                    'properties' => ['competitor' => $s(), 'observed_gap' => $s(), 'evidence' => $evidence_arr, 'implication' => $s(), 'recommended_response' => $s(), 'confidence' => $n()],
                ]],
                'opportunities' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['title','target_audience','channel','gap','evidence','recommended_angle','expected_business_value','effort','confidence','next_action'],
                    'properties' => ['title' => $s(), 'target_audience' => $s(), 'channel' => $s(), 'gap' => $s(), 'evidence' => $evidence_arr, 'recommended_angle' => $s(), 'expected_business_value' => $s(), 'effort' => ['type' => 'string', 'enum' => ['low','medium','high']], 'confidence' => $n(), 'next_action' => $s()],
                ]],
                'recommended_actions' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['action','priority','reason','owner_role','timeframe','confidence'],
                    'properties' => ['action' => $s(), 'priority' => ['type' => 'string', 'enum' => ['high','medium','low']], 'reason' => $s(), 'owner_role' => $s(), 'timeframe' => $s(), 'confidence' => $n()],
                ]],
                'briefs' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['title','objective','audience','channel','message','proof_points','format','cta','next_step'],
                    'properties' => ['title' => $s(), 'objective' => $s(), 'audience' => $s(), 'channel' => $s(), 'message' => $s(), 'proof_points' => $str_arr, 'format' => $s(), 'cta' => $s(), 'next_step' => $s()],
                ]],
                'next_data_collection_plan' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['source','reason','priority','next_action'],
                    'properties' => ['source' => $s(), 'reason' => $s(), 'priority' => ['type' => 'string', 'enum' => ['high','medium','low']], 'next_action' => $s()],
                ]],
                'evidence_appendix' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['evidence_ref','source','entity','summary','url','quality_score'],
                    'properties' => ['evidence_ref' => $s(), 'source' => $s(), 'entity' => $s(), 'summary' => $s(), 'url' => $s(), 'quality_score' => $n()],
                ]],
            ],
        ];
    }

    public static function normalize($structured = [], $meta = [], $evidence_summary = [], $evidence_items = []) {
        $structured = is_array($structured) ? $structured : [];
        $meta = is_array($meta) ? $meta : [];
        $report = (isset($structured['report_meta'], $structured['executive_verdict']) && is_array($structured['report_meta'])) ? $structured : (is_array($structured['report_schema'] ?? null) ? $structured['report_schema'] : []);
        $request = is_array($meta['request'] ?? null) ? $meta['request'] : [];
        $brand = self::text($report['report_meta']['brand_name'] ?? $request['brand_name'] ?? $request['brandName'] ?? $structured['brand_name'] ?? '');
        $market = self::text($report['report_meta']['market'] ?? $request['market'] ?? $request['country'] ?? $structured['market'] ?? '');
        $language = self::text($report['report_meta']['language'] ?? $request['language'] ?? $structured['language'] ?? '');
        $usable = (int) ($evidence_summary['usable_count'] ?? $evidence_summary['stored_count'] ?? $evidence_summary['total_saved'] ?? $report['evidence_quality']['usable_count'] ?? 0);
        $partial = (int) ($evidence_summary['partial_count'] ?? $report['evidence_quality']['partial_count'] ?? 0);
        $failed = (int) ($evidence_summary['failed_count'] ?? $report['evidence_quality']['failed_count'] ?? 0);
        $evidence_count = max(0, (int) ($report['report_meta']['evidence_count'] ?? $evidence_summary['total_saved'] ?? $evidence_summary['stored_count'] ?? count((array) $evidence_items)));
        if ($evidence_count <= 0) { $evidence_count = $usable + $partial; }
        $confidence = self::confidence($report['report_meta']['confidence'] ?? $structured['confidence'] ?? $meta['confidence'] ?? null, $evidence_count);
        $limitations = self::list_values($report['executive_verdict']['limitations'] ?? $structured['limitations'] ?? $structured['data_limitations'] ?? []);
        $status = self::status($report['report_meta']['status'] ?? '', $evidence_count, $usable, $partial, $failed, $confidence, $limitations);
        $source_breakdown = self::source_breakdown($report['evidence_quality']['source_breakdown'] ?? [], $evidence_summary, $evidence_items);
        $what_we_know = self::what_we_know($report, $structured);
        $what_we_do_not_know = self::what_we_do_not_know($report, $structured, $limitations, $evidence_count);
        $patterns = self::patterns($report, $structured);
        $diagnosis = self::diagnosis($report, $structured, $patterns, $confidence);
        $competitors = self::competitors($report, $structured);
        $opportunities = self::opportunities($report, $structured);
        $actions = self::actions($report, $structured, $opportunities);
        $briefs = self::briefs($report, $structured, $opportunities);
        $data_plan = self::data_plan($report, $what_we_do_not_know, $source_breakdown);
        $appendix = self::appendix($report, $evidence_items);
        $headline = self::text($report['executive_verdict']['headline'] ?? $structured['executive_summary']['headline'] ?? $diagnosis['diagnosis'] ?? 'Brand Deep Audit verdict');
        $summary = self::text($report['executive_verdict']['summary'] ?? $structured['executive_summary']['summary'] ?? $diagnosis['business_implication'] ?? 'Evidence was normalized into a structured audit. Review the evidence quality and recommended actions before using externally.');
        return [
            'report_meta' => [
                'brand_name' => $brand,
                'market' => $market,
                'language' => $language,
                'status' => $status,
                'evidence_count' => $evidence_count,
                'confidence' => $confidence,
                'generated_at' => self::text($report['report_meta']['generated_at'] ?? $meta['generated_at'] ?? (function_exists('current_time') ? current_time('mysql') : gmdate('c'))),
                'model' => self::text($report['report_meta']['model'] ?? $meta['model'] ?? $structured['model'] ?? ''),
                'prompt_version' => self::text($report['report_meta']['prompt_version'] ?? $meta['prompt_version'] ?? self::PROMPT_VERSION),
            ],
            'executive_verdict' => [
                'headline' => $headline,
                'summary' => $summary,
                'confidence' => self::confidence($report['executive_verdict']['confidence'] ?? $confidence, $evidence_count),
                'client_ready' => $status === 'completed' && $confidence >= 0.7 && empty($limitations),
                'limitations' => $limitations,
            ],
            'evidence_quality' => [
                'usable_count' => $usable,
                'partial_count' => $partial,
                'failed_count' => $failed,
                'source_breakdown' => $source_breakdown,
                'freshness' => self::text($report['evidence_quality']['freshness'] ?? ($evidence_count > 0 ? 'Mixed public evidence; verify source dates before client use.' : 'No usable evidence was available.')),
                'reliability_notes' => self::list_values($report['evidence_quality']['reliability_notes'] ?? ($evidence_count > 0 ? ['Claims should be read as evidence-led inferences, not absolute market truth.'] : ['The run did not collect enough evidence for a client-ready audit.'])),
                'missing_sources' => self::list_values($report['evidence_quality']['missing_sources'] ?? []),
            ],
            'what_we_know' => $what_we_know,
            'what_we_do_not_know' => $what_we_do_not_know,
            'key_patterns' => $patterns,
            'strategic_diagnosis' => $diagnosis,
            'competitor_implications' => $competitors,
            'opportunities' => $opportunities,
            'recommended_actions' => $actions,
            'briefs' => $briefs,
            'next_data_collection_plan' => $data_plan,
            'evidence_appendix' => $appendix,
        ];
    }

    private static function status($status, $evidence_count, $usable, $partial, $failed, $confidence, $limitations) {
        $status = sanitize_key((string) $status);
        if ($status === 'failed') { return 'failed'; }
        if ($evidence_count < self::MIN_EVIDENCE_FOR_LIMITED || $usable <= 0) { return 'needs_more_data'; }
        if ($evidence_count < self::MIN_EVIDENCE_FOR_COMPLETED || $partial > 0 || $failed > 0 || $confidence < 0.7 || !empty($limitations)) { return 'completed_with_limitations'; }
        return 'completed';
    }

    private static function what_we_know($report, $structured) {
        $out = self::list_values($report['what_we_know'] ?? []);
        foreach (['brand_positioning_readout','search_intent_readout','social_readout','review_and_trust_readout'] as $key) {
            if (!empty($structured[$key]) && is_array($structured[$key])) {
                foreach ($structured[$key] as $item) {
                    if (is_scalar($item)) { $out[] = self::text($item); }
                    elseif (is_array($item)) { $out[] = self::text($item['summary'] ?? $item['finding'] ?? $item['claim'] ?? $item['title'] ?? ''); }
                }
            }
        }
        return self::dedupe_nonempty($out, 8);
    }

    private static function what_we_do_not_know($report, $structured, $limitations, $evidence_count) {
        $out = array_merge(self::list_values($report['what_we_do_not_know'] ?? []), $limitations);
        if ($evidence_count < self::MIN_EVIDENCE_FOR_COMPLETED) { $out[] = 'Evidence volume is below the threshold for a fully client-ready verdict.'; }
        if (!empty($structured['questions_to_explore_with_assistant']) && is_array($structured['questions_to_explore_with_assistant'])) {
            $out = array_merge($out, self::list_values($structured['questions_to_explore_with_assistant']));
        }
        return self::dedupe_nonempty($out, 8);
    }

    private static function patterns($report, $structured) {
        $items = is_array($report['key_patterns'] ?? null) ? $report['key_patterns'] : (is_array($structured['insights'] ?? null) ? $structured['insights'] : []);
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) { $item = ['claim' => $item]; }
            if (!is_array($item)) { continue; }
            $claim = self::text($item['claim'] ?? $item['title'] ?? $item['finding'] ?? $item['insight'] ?? '');
            if ($claim === '') { continue; }
            $out[] = [
                'claim' => $claim,
                'evidence' => self::list_values($item['evidence'] ?? $item['evidence_refs'] ?? $item['sources'] ?? []),
                'confidence' => self::confidence($item['confidence'] ?? null, count((array) ($item['evidence'] ?? []))),
                'why_it_matters' => self::text($item['why_it_matters'] ?? $item['business_implication'] ?? $item['implication'] ?? 'This pattern affects positioning, messaging or content prioritization.'),
                'missing_data' => self::list_values($item['missing_data'] ?? $item['missing_sources'] ?? []),
            ];
        }
        return array_slice($out, 0, 8);
    }

    private static function diagnosis($report, $structured, $patterns, $confidence) {
        $diag = is_array($report['strategic_diagnosis'] ?? null) ? $report['strategic_diagnosis'] : (is_array($structured['strategic_diagnosis'] ?? null) ? $structured['strategic_diagnosis'] : []);
        $fallback = !empty($patterns[0]['claim']) ? $patterns[0]['claim'] : 'The audit has not produced a strong evidence-backed diagnosis yet.';
        return [
            'diagnosis' => self::text($diag['diagnosis'] ?? $diag['main_finding'] ?? $diag['finding'] ?? $fallback),
            'business_implication' => self::text($diag['business_implication'] ?? $diag['implication'] ?? $diag['why_it_matters'] ?? 'Use this as a directional diagnosis until more sources are collected.'),
            'risk' => self::text($diag['risk'] ?? $diag['risk_if_ignored'] ?? 'Overstating weak evidence or acting before source gaps are closed.'),
            'confidence' => self::confidence($diag['confidence'] ?? $confidence, count($patterns)),
        ];
    }

    private static function competitors($report, $structured) {
        $items = is_array($report['competitor_implications'] ?? null) ? $report['competitor_implications'] : (is_array($structured['competitor_gap_matrix'] ?? null) ? $structured['competitor_gap_matrix'] : []);
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) { $item = ['competitor' => $item]; }
            if (!is_array($item)) { continue; }
            $competitor = self::text($item['competitor'] ?? $item['name'] ?? 'Competitor');
            $out[] = [
                'competitor' => $competitor,
                'observed_gap' => self::text($item['observed_gap'] ?? $item['gap'] ?? $item['difference'] ?? ''),
                'evidence' => self::list_values($item['evidence'] ?? $item['evidence_refs'] ?? []),
                'implication' => self::text($item['implication'] ?? $item['threat'] ?? ''),
                'recommended_response' => self::text($item['recommended_response'] ?? $item['response'] ?? $item['recommendation'] ?? ''),
                'confidence' => self::confidence($item['confidence'] ?? null, count((array) ($item['evidence'] ?? []))),
            ];
        }
        return array_slice($out, 0, 6);
    }

    private static function opportunities($report, $structured) {
        $items = is_array($report['opportunities'] ?? null) ? $report['opportunities'] : [];
        if (empty($items) && is_array($structured['opportunities'] ?? null)) { $items = $structured['opportunities']; }
        if (empty($items) && is_array($structured['opportunity_territories'] ?? null)) { $items = $structured['opportunity_territories']; }
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) { $item = ['title' => $item]; }
            if (!is_array($item)) { continue; }
            $title = self::text($item['title'] ?? $item['name'] ?? $item['opportunity'] ?? 'Opportunity');
            if ($title === '') { continue; }
            $out[] = [
                'title' => $title,
                'target_audience' => self::text($item['target_audience'] ?? $item['audience'] ?? ''),
                'channel' => self::text($item['channel'] ?? $item['platform'] ?? 'Multi-channel'),
                'gap' => self::text($item['gap'] ?? $item['observed_gap'] ?? $item['why_now'] ?? ''),
                'evidence' => self::list_values($item['evidence'] ?? $item['evidence_refs'] ?? []),
                'recommended_angle' => self::text($item['recommended_angle'] ?? $item['angle'] ?? $item['campaign_angle'] ?? ''),
                'expected_business_value' => self::text($item['expected_business_value'] ?? $item['business_value'] ?? $item['value'] ?? ''),
                'effort' => self::effort($item['effort'] ?? 'medium'),
                'confidence' => self::confidence($item['confidence'] ?? null, count((array) ($item['evidence'] ?? []))),
                'next_action' => self::text($item['next_action'] ?? $item['recommended_action'] ?? 'Validate this opportunity with additional source evidence and draft a brief.'),
            ];
        }
        return array_slice($out, 0, 8);
    }

    private static function actions($report, $structured, $opportunities) {
        $items = is_array($report['recommended_actions'] ?? null) ? $report['recommended_actions'] : (is_array($structured['recommended_next_actions'] ?? null) ? $structured['recommended_next_actions'] : []);
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) { $item = ['action' => $item]; }
            if (!is_array($item)) { continue; }
            $action = self::text($item['action'] ?? $item['title'] ?? '');
            if ($action === '') { continue; }
            $out[] = [
                'action' => $action,
                'priority' => self::priority($item['priority'] ?? 'medium'),
                'reason' => self::text($item['reason'] ?? $item['why'] ?? ''),
                'owner_role' => self::text($item['owner_role'] ?? $item['owner'] ?? 'Marketing lead'),
                'timeframe' => self::text($item['timeframe'] ?? $item['timing'] ?? 'Next planning cycle'),
                'confidence' => self::confidence($item['confidence'] ?? null, 1),
            ];
        }
        if (empty($out) && !empty($opportunities)) {
            foreach (array_slice($opportunities, 0, 3) as $opp) {
                $out[] = ['action' => 'Turn "' . $opp['title'] . '" into a validated brief.', 'priority' => 'medium', 'reason' => $opp['gap'], 'owner_role' => 'Strategist', 'timeframe' => '1-2 weeks', 'confidence' => $opp['confidence']];
            }
        }
        return array_slice($out, 0, 8);
    }

    private static function briefs($report, $structured, $opportunities) {
        $items = is_array($report['briefs'] ?? null) ? $report['briefs'] : (is_array($structured['briefs'] ?? null) ? $structured['briefs'] : []);
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $out[] = self::brief_from_item($item);
        }
        if (empty($out)) {
            foreach (array_slice($opportunities, 0, 3) as $opp) { $out[] = self::brief_from_item($opp); }
        }
        return array_slice($out, 0, 5);
    }

    private static function brief_from_item($item) {
        return [
            'title' => self::text($item['title'] ?? 'Evidence-led content brief'),
            'objective' => self::text($item['objective'] ?? $item['expected_business_value'] ?? 'Convert audit evidence into a usable campaign/content direction.'),
            'audience' => self::text($item['audience'] ?? $item['target_audience'] ?? ''),
            'channel' => self::text($item['channel'] ?? 'Multi-channel'),
            'message' => self::text($item['message'] ?? $item['recommended_angle'] ?? ''),
            'proof_points' => self::list_values($item['proof_points'] ?? $item['evidence'] ?? []),
            'format' => self::text($item['format'] ?? 'Brief / campaign angle'),
            'cta' => self::text($item['cta'] ?? 'Review evidence and approve next step'),
            'next_step' => self::text($item['next_step'] ?? $item['next_action'] ?? 'Refine into production-ready copy or creative assets.'),
        ];
    }

    private static function data_plan($report, $unknowns, $source_breakdown) {
        $items = is_array($report['next_data_collection_plan'] ?? null) ? $report['next_data_collection_plan'] : [];
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) { $item = ['source' => 'Additional evidence', 'reason' => $item]; }
            if (!is_array($item)) { continue; }
            $out[] = ['source' => self::text($item['source'] ?? 'Additional evidence'), 'reason' => self::text($item['reason'] ?? ''), 'priority' => self::priority($item['priority'] ?? 'medium'), 'next_action' => self::text($item['next_action'] ?? 'Collect and normalize this source before using the claim externally.')];
        }
        if (empty($out)) {
            foreach (array_slice($unknowns, 0, 4) as $unknown) { $out[] = ['source' => 'Source gap', 'reason' => $unknown, 'priority' => 'medium', 'next_action' => 'Collect targeted evidence and rerun the audit.']; }
        }
        return array_slice($out, 0, 6);
    }

    private static function appendix($report, $evidence_items) {
        $items = is_array($report['evidence_appendix'] ?? null) ? $report['evidence_appendix'] : [];
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $out[] = ['evidence_ref' => self::text($item['evidence_ref'] ?? ''), 'source' => self::text($item['source'] ?? ''), 'entity' => self::text($item['entity'] ?? ''), 'summary' => self::text($item['summary'] ?? ''), 'url' => self::text($item['url'] ?? ''), 'quality_score' => self::confidence($item['quality_score'] ?? null, 1)];
        }
        foreach ((array) $evidence_items as $item) {
            if (!is_array($item)) { continue; }
            $out[] = [
                'evidence_ref' => self::text($item['evidence_ref'] ?? $item['ref'] ?? $item['id'] ?? ''),
                'source' => self::text($item['source'] ?? $item['source_type'] ?? ''),
                'entity' => self::text($item['entity'] ?? $item['brand_name'] ?? ''),
                'summary' => self::text($item['summary'] ?? $item['text'] ?? $item['title'] ?? ''),
                'url' => self::text($item['url'] ?? $item['source_url'] ?? ''),
                'quality_score' => self::confidence($item['quality_score'] ?? $item['confidence'] ?? null, 1),
            ];
        }
        return array_slice($out, 0, 80);
    }

    private static function source_breakdown($items, $summary, $evidence_items) {
        $out = [];
        foreach ((array) $items as $item) {
            if (!is_array($item)) { continue; }
            $out[] = ['source' => self::text($item['source'] ?? ''), 'usable_count' => (int) ($item['usable_count'] ?? 0), 'total_count' => (int) ($item['total_count'] ?? 0), 'notes' => self::text($item['notes'] ?? '')];
        }
        if (!empty($out)) { return array_slice($out, 0, 12); }
        $by_source = [];
        foreach ((array) $evidence_items as $item) {
            $source = self::text(is_array($item) ? ($item['source'] ?? $item['source_type'] ?? 'Evidence') : 'Evidence');
            if ($source === '') { $source = 'Evidence'; }
            if (!isset($by_source[$source])) { $by_source[$source] = ['source' => $source, 'usable_count' => 0, 'total_count' => 0, 'notes' => 'Normalized evidence items.']; }
            $by_source[$source]['usable_count']++;
            $by_source[$source]['total_count']++;
        }
        return array_slice(array_values($by_source), 0, 12);
    }

    private static function confidence($value, $evidence_count = 0) {
        if (is_numeric($value)) {
            $value = (float) $value;
            if ($value > 1) { $value = $value / 100; }
            return max(0.0, min(1.0, round($value, 2)));
        }
        if ($evidence_count >= self::MIN_EVIDENCE_FOR_COMPLETED) { return 0.72; }
        if ($evidence_count >= self::MIN_EVIDENCE_FOR_LIMITED) { return 0.55; }
        return 0.25;
    }

    private static function priority($value) { $value = sanitize_key((string) $value); return in_array($value, ['high','medium','low'], true) ? $value : 'medium'; }
    private static function effort($value) { $value = sanitize_key((string) $value); return in_array($value, ['low','medium','high'], true) ? $value : 'medium'; }
    private static function text($value) { return trim(wp_strip_all_tags(is_scalar($value) ? (string) $value : '')); }
    private static function list_values($value) {
        if (is_string($value)) { $value = [$value]; }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) { $item = $item['claim'] ?? $item['summary'] ?? $item['title'] ?? $item['source'] ?? ''; }
            $text = self::text($item);
            if ($text !== '') { $out[] = $text; }
        }
        return self::dedupe_nonempty($out, 20);
    }
    private static function dedupe_nonempty($items, $limit) {
        $out = [];
        foreach ((array) $items as $item) {
            $item = self::text($item);
            if ($item === '') { continue; }
            $key = strtolower($item);
            if (isset($out[$key])) { continue; }
            $out[$key] = $item;
            if (count($out) >= $limit) { break; }
        }
        return array_values($out);
    }
}
