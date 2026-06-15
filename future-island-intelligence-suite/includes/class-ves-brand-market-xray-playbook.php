<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Brand & Market X-Ray — deterministic self-serve playbook route.
 * Stores URLs as references only; no crawler/provider dependency.
 */
final class VES_Brand_Market_Xray_Playbook {

    public static function run(array $input, array $context = []) {
        if (!class_exists('VES_Playbook_Input_Validator')) { return self::err('ves_xray_validator_missing', 'Playbook validator unavailable.'); }
        $validated = VES_Playbook_Input_Validator::brand_market_xray($input);
        if (self::is_err($validated)) { return $validated; }
        if (!class_exists('VES_Intelligence_Store') || !class_exists('VES_Run_Service')) {
            return self::err('ves_xray_spine_missing', 'Canonical spine is unavailable.');
        }

        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Workspace_Service')) { $workspace_id = VES_Workspace_Service::current_workspace_id(); }
        if ($workspace_id <= 0) { return self::err('ves_xray_missing_workspace', 'Workspace could not be resolved.'); }
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) {
            return self::err('ves_xray_forbidden', 'You cannot run playbooks in this workspace.');
        }

        $idem = self::idempotency_key($workspace_id, $validated, (string) ($context['idempotency_key'] ?? ''));
        $run_id = VES_Run_Service::create_run([
            'workspace_id' => $workspace_id,
            'run_type' => 'brand_xray',
            'trigger_type' => 'manual',
            'input_context' => $validated,
            'idempotency_key' => $idem,
            'metadata' => ['playbook' => 'brand_market_xray', 'url_reference_only' => true],
        ]);
        if (self::is_err($run_id)) { return $run_id; }
        $run_id = (int) $run_id;
        VES_Run_Service::mark_running($run_id);

        $created = ['sources' => [], 'signals' => [], 'evidence' => [], 'insights' => [], 'briefs' => [], 'drafts' => [], 'memory' => [], 'usage' => [], 'edges' => []];
        try {
            $brand_source = self::create_source($workspace_id, $run_id, $validated, 'brand');
            if ($brand_source > 0) { $created['sources'][] = $brand_source; self::edge($workspace_id, $run_id, 'run', $run_id, 'source', $brand_source, 'created_source'); }

            foreach ($validated['competitors'] as $competitor) {
                $source_id = self::create_source($workspace_id, $run_id, array_merge($validated, ['source_label' => $competitor]), 'competitor');
                if ($source_id > 0) { $created['sources'][] = $source_id; self::edge($workspace_id, $run_id, 'run', $run_id, 'source', $source_id, 'created_source'); }
            }

            $signal_specs = self::signal_specs($validated);
            foreach ($signal_specs as $i => $spec) {
                $source_id = $brand_source ?: (int) ($created['sources'][0] ?? 0);
                $signal_id = VES_Intelligence_Store::create_signal([
                    'workspace_id' => $workspace_id,
                    'run_id' => $run_id,
                    'source_id' => $source_id,
                    'signal_type' => (string) ($spec['signal_type'] ?? 'market_signal'),
                    'title' => (string) ($spec['title'] ?? 'Manual market signal'),
                    'summary' => (string) ($spec['summary'] ?? ''),
                    'value_text' => (string) ($spec['value_text'] ?? ''),
                    'confidence_score' => 55,
                    'occurred_at' => self::now(),
                    'metadata' => ['playbook' => 'brand_market_xray', 'source_family' => (string) ($spec['source_family'] ?? 'manual'), 'language' => $validated['language'], 'market' => $validated['market']],
                ]);
                if (self::is_err($signal_id)) { continue; }
                $signal_id = (int) $signal_id;
                $created['signals'][] = $signal_id;
                self::edge($workspace_id, $run_id, 'run', $run_id, 'signal', $signal_id, 'captured');
                if ($source_id > 0) { self::edge($workspace_id, $run_id, 'source', $source_id, 'signal', $signal_id, 'produced'); }

                $evidence_id = VES_Intelligence_Store::create_evidence([
                    'workspace_id' => $workspace_id,
                    'run_id' => $run_id,
                    'source_id' => $source_id,
                    'signal_id' => $signal_id,
                    'evidence_type' => 'observation',
                    'text' => 'Reference evidence: ' . (string) ($spec['summary'] ?? $spec['title']),
                    'source_url' => $validated['brand_url'],
                    'source_label' => 'Brand & Market X-Ray reference input',
                    'observed_at' => self::now(),
                    'confidence_score' => 55,
                    'metadata' => ['source_family' => (string) ($spec['source_family'] ?? 'manual'), 'memory_is_not_evidence' => true],
                ]);
                if (self::is_err($evidence_id)) { continue; }
                $evidence_id = (int) $evidence_id;
                $created['evidence'][] = $evidence_id;
                self::edge($workspace_id, $run_id, 'signal', $signal_id, 'evidence', $evidence_id, 'supports');
            }

            $insight_ids = self::create_insights($workspace_id, $run_id, $validated, $created['signals'], $created['evidence']);
            $created['insights'] = $insight_ids;

            $first_insight = (int) ($insight_ids[0] ?? 0);
            $brief_id = 0;
            if (class_exists('VES_Brief_OS_Service')) {
                $brief_id = VES_Brief_OS_Service::create_manual_brief([
                    'workspace_id' => $workspace_id,
                    'run_id' => $run_id,
                    'origin_type' => 'playbook',
                    'origin_id' => $first_insight,
                    'insight_id' => $first_insight,
                    'brief_type' => 'campaign',
                    'channel' => 'generic',
                    'format' => 'campaign_angle',
                    'title' => 'Brand & Market X-Ray brief candidate',
                    'objective' => $validated['goal'],
                    'target_audience' => $validated['audience'],
                    'angle' => self::xray_angle($validated),
                    'key_message' => self::xray_angle($validated),
                    'key_points' => self::brief_points($validated),
                    'constraints' => ['Evidence-first: treat manual inputs as assumptions until confirmed by source-family evidence.'],
                    'source_evidence' => $created['evidence'],
                    'evidence_ids' => $created['evidence'],
                    'status' => 'draft',
                    'metadata' => ['playbook' => 'brand_market_xray', 'assumption_label' => empty($created['evidence']) ? 'manual / assumption-based brief' : 'reference-evidence-backed brief'],
                ]);
                if (!self::is_err($brief_id) && (int) $brief_id > 0) { $created['briefs'][] = (int) $brief_id; }
            }
            if ($brief_id > 0 && class_exists('VES_Draft_Service')) {
                $draft_id = VES_Draft_Service::create_draft_from_brief((int) $brief_id, ['run_id' => $run_id, 'mode' => 'deterministic_preview', 'channel' => 'generic', 'metadata' => ['playbook' => 'brand_market_xray']]);
                if (!self::is_err($draft_id) && (int) $draft_id > 0) { $created['drafts'][] = (int) $draft_id; }
            }

            $memory_id = VES_Intelligence_Store::create_memory_record([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'memory_type' => 'research_summary',
                'text' => 'Brand & Market X-Ray candidate: ' . self::xray_angle($validated),
                'source_entity_type' => 'run',
                'source_entity_id' => (string) $run_id,
                'status' => 'candidate',
                'importance_score' => 65,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
                'metadata' => ['requires_review' => true, 'trust_label' => 'candidate_context_not_evidence', 'playbook' => 'brand_market_xray'],
            ]);
            if (!self::is_err($memory_id) && (int) $memory_id > 0) {
                $created['memory'][] = (int) $memory_id;
                self::edge($workspace_id, $run_id, 'run', $run_id, 'memory_record', (int) $memory_id, 'created_memory');
            }

            if (class_exists('VES_Usage_Service')) {
                $usage_id = VES_Usage_Service::record_zero_cost_event([
                    'workspace_id' => $workspace_id,
                    'run_id' => $run_id,
                    'target_type' => 'playbook',
                    'target_id' => 'brand_market_xray',
                    'event_type' => 'brand_xray_deterministic_run',
                    'module' => 'brand_market_xray',
                    'idempotency_key' => 'fi:brand_xray:' . $workspace_id . ':' . $run_id,
                    'message' => 'Brand & Market X-Ray deterministic route completed without provider cost.',
                ]);
                if (!self::is_err($usage_id) && (int) $usage_id > 0) { $created['usage'][] = (int) $usage_id; }
            }

            VES_Run_Service::mark_completed($run_id, [
                'playbook' => 'brand_market_xray',
                'source_count' => count($created['sources']),
                'signal_count' => count($created['signals']),
                'evidence_count' => count($created['evidence']),
                'insight_count' => count($created['insights']),
                'brief_count' => count($created['briefs']),
                'draft_count' => count($created['drafts']),
                'memory_count' => count($created['memory']),
                'provider_required' => false,
            ]);
        } catch (\Throwable $e) {
            VES_Run_Service::mark_failed($run_id, 'Brand & Market X-Ray failed before completion.', ['error_class' => get_class($e)]);
            return self::err('ves_xray_failed', 'Brand & Market X-Ray failed before completion.');
        }

        return [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'status' => 'completed',
            'input' => $validated,
            'created' => $created,
            'source_coverage' => self::coverage($validated, $created),
            'next_actions' => ['Review Insight', 'Build Brief', 'Generate Draft', 'Save Memory', 'Open Decision Report'],
            'decision_report' => class_exists('VES_Decision_Report_Service') ? VES_Decision_Report_Service::build_for_run($workspace_id, $run_id) : [],
        ];
    }

    public static function result_for_run(int $workspace_id, int $run_id): array {
        $run = class_exists('VES_Run_Service') ? VES_Run_Service::get_run($run_id) : null;
        if (!is_array($run) || (int) ($run['workspace_id'] ?? 0) !== $workspace_id) { return []; }
        return [
            'run' => $run,
            'sources' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_sources(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 25]) : [],
            'signals' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_signals(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 25]) : [],
            'evidence' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_evidence(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 25]) : [],
            'insights' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_insights(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 25]) : [],
            'briefs' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_briefs(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 25]) : [],
            'drafts' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_drafts(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 25]) : [],
            'memory' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::list_memory_records(['workspace_id' => $workspace_id, 'limit' => 25]) : [],
        ];
    }

    private static function create_source(int $workspace_id, int $run_id, array $v, string $kind): int {
        $label = $kind === 'competitor' ? (string) ($v['source_label'] ?? 'Competitor') : ($v['brand_name'] !== '' ? $v['brand_name'] : 'Brand reference');
        $url = $kind === 'brand' ? (string) $v['brand_url'] : (VES_Playbook_Input_Validator::safe_url($label) ?: '');
        $source_type = $url !== '' ? 'web' : 'manual';
        $id = VES_Intelligence_Store::create_source([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'source_type' => $source_type,
            'provider' => 'manual',
            'source_url' => $url,
            'source_title' => $label,
            'external_id' => 'brand_xray:' . $kind . ':' . md5($label . '|' . $url),
            'language' => $v['language'],
            'region' => substr(strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v['market'])), 0, 16),
            'credibility_score' => 55,
            'metadata' => ['playbook' => 'brand_market_xray', 'kind' => $kind, 'url_reference_only' => true],
        ]);
        return self::is_err($id) ? 0 : (int) $id;
    }

    private static function signal_specs(array $v): array {
        $specs = [];
        $brand = $v['brand_name'] !== '' ? $v['brand_name'] : 'Brand';
        $specs[] = ['signal_type' => 'market_signal', 'title' => $brand . ' context captured', 'summary' => $v['brand_description'] !== '' ? $v['brand_description'] : 'Brand URL/reference supplied for analysis.', 'value_text' => $v['brand_description'], 'source_family' => $v['brand_url'] !== '' ? 'web_reference' : 'manual'];
        if ($v['audience'] !== '') { $specs[] = ['signal_type' => 'audience_signal', 'title' => 'Audience context captured', 'summary' => $v['audience'], 'value_text' => $v['audience'], 'source_family' => 'manual']; }
        if ($v['market'] !== '') { $specs[] = ['signal_type' => 'market_signal', 'title' => 'Market context captured', 'summary' => $v['market'], 'value_text' => $v['market'], 'source_family' => 'manual']; }
        foreach ($v['competitors'] as $c) { $specs[] = ['signal_type' => 'competitor_move', 'title' => 'Competitor reference: ' . $c, 'summary' => 'Competitor supplied for comparison: ' . $c, 'value_text' => $c, 'source_family' => 'competitor_reference']; }
        foreach ($v['keywords'] as $k) { $specs[] = ['signal_type' => 'content_pattern', 'title' => 'Topic/keyword reference: ' . $k, 'summary' => 'Keyword/topic supplied for the X-Ray route: ' . $k, 'value_text' => $k, 'source_family' => 'manual']; }
        foreach ($v['social_handles'] as $h) { $specs[] = ['signal_type' => 'mention', 'title' => 'Social handle reference: ' . $h, 'summary' => 'Public social handle supplied as reference; no provider fetch performed in this route.', 'value_text' => $h, 'source_family' => 'social_reference']; }
        if ($v['goal'] !== '') { $specs[] = ['signal_type' => 'claim', 'title' => 'Goal captured', 'summary' => $v['goal'], 'value_text' => $v['goal'], 'source_family' => 'manual']; }
        return $specs;
    }

    private static function create_insights(int $workspace_id, int $run_id, array $v, array $signals, array $evidence): array {
        $ids = [];
        $base = [
            ['type' => 'opportunity', 'title' => 'Clarify the market position before generating campaign output', 'summary' => self::xray_angle($v), 'recommendation' => 'Review this as a strategic hypothesis, then convert the approved direction into a brief.'],
            ['type' => 'audience', 'title' => 'Audience language should guide the first brief', 'summary' => $v['audience'] !== '' ? 'The supplied audience context should constrain tone, examples, and proof.' : 'Audience context is incomplete; treat output as a hypothesis until audience evidence is added.', 'recommendation' => 'Add audience evidence before final report claims.'],
            ['type' => 'competitor', 'title' => 'Competitor references create a comparison map, not proof yet', 'summary' => !empty($v['competitors']) ? 'Competitors supplied: ' . implode(', ', $v['competitors']) : 'No competitor context supplied.', 'recommendation' => 'Use Social Signal Scan or source intake next to strengthen source-family coverage.'],
        ];
        foreach ($base as $spec) {
            $id = VES_Intelligence_Store::create_insight([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'insight_type' => $spec['type'],
                'title' => $spec['title'],
                'summary' => $spec['summary'],
                'recommendation' => $spec['recommendation'],
                'confidence_score' => !empty($evidence) ? 55 : 35,
                'status' => 'draft',
                'evidence_ids' => $evidence,
                'signal_ids' => $signals,
                'metadata' => ['playbook' => 'brand_market_xray', 'review_required' => true, 'assumption_state' => !empty($evidence) ? 'reference_supported' : 'manual_assumption'],
            ]);
            if (self::is_err($id)) { continue; }
            $id = (int) $id;
            $ids[] = $id;
            foreach ($evidence as $eid) { self::edge($workspace_id, $run_id, 'evidence', (int) $eid, 'insight', $id, 'supports'); }
            foreach ($signals as $sid) { self::edge($workspace_id, $run_id, 'signal', (int) $sid, 'insight', $id, 'supports'); }
        }
        return $ids;
    }

    private static function coverage(array $v, array $created): array {
        return [
            'manual_context' => true,
            'brand_url_reference' => $v['brand_url'] !== '',
            'competitor_references' => count($v['competitors']),
            'social_handle_references' => count($v['social_handles']),
            'signals' => count($created['signals']),
            'evidence_refs' => count($created['evidence']),
            'coverage_label' => count($created['evidence']) >= 3 ? 'reference_supported' : 'manual_assumption_heavy',
        ];
    }

    private static function brief_points(array $v): array {
        $points = [];
        if ($v['brand_name'] !== '') { $points[] = 'Brand: ' . $v['brand_name']; }
        if ($v['market'] !== '') { $points[] = 'Market: ' . $v['market']; }
        if ($v['audience'] !== '') { $points[] = 'Audience: ' . $v['audience']; }
        if (!empty($v['competitors'])) { $points[] = 'Competitor frame: ' . implode(', ', $v['competitors']); }
        if (!empty($v['keywords'])) { $points[] = 'Topic frame: ' . implode(', ', $v['keywords']); }
        return $points;
    }

    private static function xray_angle(array $v): string {
        $brand = $v['brand_name'] !== '' ? $v['brand_name'] : 'this brand';
        $market = $v['market'] !== '' ? (' in ' . $v['market']) : '';
        return 'Position ' . $brand . $market . ' around a specific audience problem, then use social/search evidence to prove which message deserves production.';
    }

    private static function idempotency_key(int $workspace_id, array $v, string $explicit): string {
        if ($explicit !== '') { return substr(preg_replace('/[^a-zA-Z0-9_:\.\-]/', '', $explicit), 0, 191); }
        return 'fi:brand_xray:' . $workspace_id . ':' . md5(json_encode($v));
    }
    private static function edge(int $workspace_id, int $run_id, string $from_type, int $from_id, string $to_type, int $to_id, string $rel): void {
        if ($workspace_id <= 0 || $from_id <= 0 || $to_id <= 0 || !class_exists('VES_Decision_Edge_Service')) { return; }
        VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, $from_type, $from_id, $to_type, $to_id, $rel, ['metadata' => ['playbook' => 'brand_market_xray']]);
    }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
