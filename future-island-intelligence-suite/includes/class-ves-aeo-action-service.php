<?php
if (!defined('ABSPATH')) { exit; }

/** Provider-ready AEO/GEO action loop skeleton: manual/provider rows only, no external calls. */
final class VES_AEO_Action_Service {
    public static function run_prompt_set(array $input, array $context = []) {
        if (!class_exists('VES_Run_Service') || !class_exists('VES_Intelligence_Store')) { return self::err('ves_aeo_spine_missing', 'Canonical AEO spine unavailable.'); }
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Workspace_Service')) { $workspace_id = VES_Workspace_Service::current_workspace_id(); }
        if ($workspace_id <= 0) { return self::err('ves_aeo_missing_workspace', 'Workspace could not be resolved.'); }
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) { return self::err('ves_aeo_forbidden', 'You cannot run AEO prompt sets in this workspace.'); }
        $prompts = self::list_value($input['prompts'] ?? ($input['prompt_set'] ?? []), 20);
        $answers = is_array($input['answers'] ?? null) ? array_slice($input['answers'], 0, 20) : [];
        if (empty($prompts) && empty($answers)) { return self::err('ves_aeo_empty', 'AEO prompt set requires prompts or manually captured answer rows.'); }
        $market = self::text((string) ($input['market'] ?? ''), 80);
        $language = self::key((string) ($input['language'] ?? 'en'), 20) ?: 'en';
        $run_id = VES_Run_Service::create_run([
            'workspace_id' => $workspace_id,
            'run_type' => 'aeo_gap',
            'trigger_type' => 'manual',
            'input_context' => ['prompts' => $prompts, 'answer_count' => count($answers), 'market' => $market, 'language' => $language],
            'idempotency_key' => self::idempotency_key($workspace_id, $prompts, $answers, (string) ($context['idempotency_key'] ?? '')),
            'metadata' => ['playbook' => 'aeo_action_loop', 'provider_ready' => true, 'provider_called' => false],
        ]);
        if (self::is_err($run_id)) { return $run_id; }
        $run_id = (int) $run_id;
        VES_Run_Service::mark_running($run_id);
        $created = ['sources' => [], 'signals' => [], 'evidence' => [], 'insights' => [], 'briefs' => [], 'usage' => []];
        $source_id = VES_Intelligence_Store::create_source([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'source_type' => 'ai_answer',
            'provider' => 'manual',
            'source_title' => 'AEO/GEO prompt set reference',
            'external_id' => 'aeo_prompt_set:' . md5(json_encode([$prompts, $answers])),
            'language' => $language,
            'region' => substr(strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $market)), 0, 16),
            'credibility_score' => 45,
            'metadata' => ['manual_answer_capture' => true, 'provider_ready' => true],
        ]);
        $source_id = self::is_err($source_id) ? 0 : (int) $source_id;
        if ($source_id > 0) { $created['sources'][] = $source_id; self::edge($workspace_id, $run_id, 'run', $run_id, 'source', $source_id, 'created_source'); }
        $rows = self::answer_rows($prompts, $answers, $language, $market);
        foreach ($rows as $row) {
            $signal_id = VES_Intelligence_Store::create_signal([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'source_id' => $source_id,
                'signal_type' => 'ai_visibility',
                'title' => 'AEO visibility signal: ' . self::text((string) ($row['prompt'] ?? ''), 120),
                'summary' => self::textarea((string) ($row['answer'] ?? 'Prompt captured without answer.'), 5000),
                'value_text' => self::textarea((string) ($row['answer'] ?? ''), 5000),
                'confidence_score' => !empty($row['answer']) ? 52 : 30,
                'metadata' => ['aeo_prompt' => (string) ($row['prompt'] ?? ''), 'mentioned_brand' => !empty($row['mentioned_brand']), 'cited_sources' => (array) ($row['cited_sources'] ?? [])],
            ]);
            if (self::is_err($signal_id)) { continue; }
            $signal_id = (int) $signal_id; $created['signals'][] = $signal_id;
            self::edge($workspace_id, $run_id, 'run', $run_id, 'signal', $signal_id, 'captured');
            $evidence_id = VES_Intelligence_Store::create_evidence([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'source_id' => $source_id,
                'signal_id' => $signal_id,
                'evidence_type' => 'observation',
                'text' => 'AEO answer observation for prompt: ' . (string) ($row['prompt'] ?? ''),
                'source_label' => 'Manual AEO/GEO answer capture',
                'confidence_score' => !empty($row['answer']) ? 52 : 30,
                'metadata' => ['memory_is_not_evidence' => true, 'provider_called' => false],
            ]);
            if (!self::is_err($evidence_id)) { $created['evidence'][] = (int) $evidence_id; self::edge($workspace_id, $run_id, 'signal', $signal_id, 'evidence', (int) $evidence_id, 'supports'); }
        }
        $insight_id = VES_Intelligence_Store::create_insight([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'insight_type' => 'aeo',
            'title' => 'AEO visibility gap ready for review',
            'summary' => 'Manual/provider-ready AEO prompt set captured ' . count($created['signals']) . ' signal(s). Use this as a corrective brief input, not as a tracker-only dashboard.',
            'recommendation' => 'Review answer evidence, identify missing citations or weak mentions, then create a corrective content brief.',
            'confidence_score' => count($created['evidence']) > 0 ? 50 : 25,
            'status' => 'draft',
            'evidence_ids' => $created['evidence'],
            'signal_ids' => $created['signals'],
            'metadata' => ['playbook' => 'aeo_action_loop', 'provider_ready' => true],
        ]);
        if (!self::is_err($insight_id)) { $insight_id = (int) $insight_id; $created['insights'][] = $insight_id; foreach ($created['evidence'] as $eid) { self::edge($workspace_id, $run_id, 'evidence', (int) $eid, 'insight', $insight_id, 'supports'); } }
        if (!empty($created['insights']) && class_exists('VES_Brief_OS_Service')) {
            $brief_id = VES_Brief_OS_Service::create_manual_brief([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'origin_type' => 'playbook',
                'origin_id' => $created['insights'][0],
                'brief_type' => 'aeo_fix',
                'channel' => 'aeo_page',
                'format' => 'landing_page_section',
                'title' => 'Corrective AEO brief candidate',
                'objective' => 'Improve answer-readiness and evidence coverage for the captured prompt set.',
                'target_audience' => $market,
                'angle' => 'Correct the visibility gap with specific, source-backed content.',
                'key_points' => ['Use owned page evidence', 'Answer the specific prompt language', 'Do not present unsupported claims as facts'],
                'constraints' => ['Evidence refs required before final report claims'],
                'source_evidence' => $created['evidence'],
                'evidence_ids' => $created['evidence'],
                'metadata' => ['playbook' => 'aeo_action_loop'],
            ]);
            if (!self::is_err($brief_id)) { $created['briefs'][] = (int) $brief_id; }
        }
        if (class_exists('VES_Usage_Service')) {
            $usage = VES_Usage_Service::record_zero_cost_event(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'target_type' => 'playbook', 'target_id' => 'aeo_action_loop', 'event_type' => 'aeo_prompt_set_manual_capture', 'module' => 'aeo_action_loop', 'idempotency_key' => 'fi:aeo:' . $workspace_id . ':' . $run_id, 'message' => 'AEO prompt set captured manually without provider cost.']);
            if (!self::is_err($usage)) { $created['usage'][] = (int) $usage; }
        }
        VES_Run_Service::mark_completed($run_id, ['playbook' => 'aeo_action_loop', 'signals' => count($created['signals']), 'provider_called' => false]);
        return ['workspace_id' => $workspace_id, 'run_id' => $run_id, 'created' => $created, 'provider_called' => false];
    }

    private static function answer_rows(array $prompts, array $answers, string $language, string $market): array {
        $rows = [];
        foreach ($answers as $a) { if (is_array($a)) { $rows[] = ['prompt' => self::textarea((string) ($a['prompt'] ?? ''), 1000), 'answer' => self::textarea((string) ($a['answer'] ?? ''), 5000), 'mentioned_brand' => !empty($a['mentioned_brand']), 'cited_sources' => is_array($a['cited_sources'] ?? null) ? $a['cited_sources'] : []]; } }
        foreach ($prompts as $p) { $rows[] = ['prompt' => $p, 'answer' => '', 'mentioned_brand' => false, 'cited_sources' => []]; }
        return array_slice($rows, 0, 20);
    }
    private static function list_value($value, int $max): array { $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value); $out = []; foreach ((array) $items as $i) { $v = self::textarea((string) $i, 1000); if ($v !== '') { $out[] = $v; } if (count($out) >= $max) { break; } } return $out; }
    private static function idempotency_key(int $workspace_id, array $prompts, array $answers, string $explicit): string { return $explicit !== '' ? substr(preg_replace('/[^a-zA-Z0-9_:\.\-]/', '', $explicit), 0, 191) : 'fi:aeo:' . $workspace_id . ':' . md5(json_encode([$prompts, $answers])); }
    private static function edge(int $workspace_id, int $run_id, string $from_type, int $from_id, string $to_type, int $to_id, string $rel): void { if ($workspace_id > 0 && $from_id > 0 && $to_id > 0 && class_exists('VES_Decision_Edge_Service')) { VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, $from_type, $from_id, $to_type, $to_id, $rel, ['metadata' => ['playbook' => 'aeo_action_loop']]); } }
    private static function key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
