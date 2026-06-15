<?php
if (!defined('ABSPATH')) { exit; }

/** Productized Social Signal Scan using existing/manual provider outputs only. */
final class VES_Social_Signal_Intelligence_Service {

    public static function run_scan(array $input, array $context = []) {
        if (!class_exists('VES_Playbook_Input_Validator')) { return self::err('ves_social_validator_missing', 'Validator unavailable.'); }
        $v = VES_Playbook_Input_Validator::social_signal_scan($input);
        if (self::is_err($v)) { return $v; }
        if (!class_exists('VES_Run_Service') || !class_exists('VES_Intelligence_Store') || !class_exists('VES_Social_Signal_Mapper')) {
            return self::err('ves_social_spine_missing', 'Canonical social spine unavailable.');
        }
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Workspace_Service')) { $workspace_id = VES_Workspace_Service::current_workspace_id(); }
        if ($workspace_id <= 0) { return self::err('ves_social_missing_workspace', 'Workspace could not be resolved.'); }
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) {
            return self::err('ves_social_forbidden', 'You cannot run social scans in this workspace.');
        }
        $mode_run_type = ['topic' => 'social_scan', 'post' => 'post_analysis', 'profile' => 'profile_analysis', 'competitor' => 'social_scan'][$v['mode']] ?? 'social_scan';
        $run_id = VES_Run_Service::create_run([
            'workspace_id' => $workspace_id,
            'run_type' => $mode_run_type,
            'trigger_type' => 'manual',
            'input_context' => $v,
            'idempotency_key' => self::idempotency_key($workspace_id, $v, (string) ($context['idempotency_key'] ?? '')),
            'metadata' => ['playbook' => 'social_signal_scan', 'no_new_provider' => true, 'provider_ready' => true, 'provider_called' => false],
        ]);
        if (self::is_err($run_id)) { return $run_id; }
        $run_id = (int) $run_id;
        VES_Run_Service::mark_running($run_id);

        $source_id = self::create_social_source($workspace_id, $run_id, $v);
        $rows = self::rows_from_input($v, is_array($input['provider_rows'] ?? null) ? $input['provider_rows'] : []);
        $created = ['sources' => [], 'signals' => [], 'evidence' => [], 'insights' => [], 'briefs' => [], 'usage' => []];
        if ($source_id > 0) { $created['sources'][] = $source_id; self::edge($workspace_id, $run_id, 'run', $run_id, 'source', $source_id, 'created_source'); }

        foreach ($rows as $row) {
            $norm = VES_Social_Signal_Mapper::normalize_row($row, ['market' => $v['market'], 'language' => $v['language']]);
            if ($norm['relevance_label'] === 'discarded') { continue; }
            $signal_id = VES_Intelligence_Store::create_signal(VES_Social_Signal_Mapper::to_signal_args($norm, $workspace_id, $run_id, $source_id));
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
                'text' => 'Social signal reference: ' . (string) ($norm['caption'] ?: $norm['title']),
                'source_url' => (string) ($norm['source_url'] ?? ''),
                'source_label' => 'Social Signal Scan · ' . (string) ($norm['platform'] ?? 'platform'),
                'observed_at' => (string) ($norm['captured_at'] ?? self::now()),
                'confidence_score' => VES_Social_Signal_Mapper::confidence_for_label((string) ($norm['relevance_label'] ?? 'weak')),
                'metadata' => ['social_signal' => $norm, 'weak_evidence_label' => $norm['relevance_label'] === 'weak'],
            ]);
            if (!self::is_err($evidence_id)) {
                $created['evidence'][] = (int) $evidence_id;
                self::edge($workspace_id, $run_id, 'signal', $signal_id, 'evidence', (int) $evidence_id, 'supports');
            }
        }

        $insight_id = self::create_social_insight($workspace_id, $run_id, $v, $created['signals'], $created['evidence']);
        if ($insight_id > 0) { $created['insights'][] = $insight_id; }
        if ($insight_id > 0 && class_exists('VES_Brief_OS_Service')) {
            $brief_id = VES_Brief_OS_Service::create_manual_brief([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'origin_type' => 'playbook',
                'origin_id' => $insight_id,
                'insight_id' => $insight_id,
                'brief_type' => 'campaign',
                'channel' => 'social',
                'format' => 'social_post',
                'title' => 'Social Signal Scan brief candidate',
                'objective' => $v['goal'],
                'target_audience' => $v['query'] !== '' ? $v['query'] : ($v['profile'] ?: implode(', ', $v['competitor_handles'])),
                'angle' => 'Turn the strongest social evidence into a testable content angle.',
                'key_points' => ['Source family: social', 'Mode: ' . $v['mode'], 'Evidence refs must be reviewed before publication.'],
                'constraints' => ['Do not treat virality as market demand without supporting source families.'],
                'source_evidence' => $created['evidence'],
                'evidence_ids' => $created['evidence'],
                'metadata' => ['playbook' => 'social_signal_scan'],
            ]);
            if (!self::is_err($brief_id) && (int) $brief_id > 0) { $created['briefs'][] = (int) $brief_id; }
        }
        if (class_exists('VES_Usage_Service')) {
            $usage_id = VES_Usage_Service::record_zero_cost_event([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'target_type' => 'playbook',
                'target_id' => 'social_signal_scan',
                'event_type' => 'social_signal_scan_deterministic',
                'module' => 'social_signal_intelligence',
                'idempotency_key' => 'fi:social_scan:' . $workspace_id . ':' . $run_id,
                'message' => 'Social Signal Scan normalized existing/manual rows without provider cost.',
            ]);
            if (!self::is_err($usage_id) && (int) $usage_id > 0) { $created['usage'][] = (int) $usage_id; }
        }
        VES_Run_Service::mark_completed($run_id, ['playbook' => 'social_signal_scan', 'mode' => $v['mode'], 'signals' => count($created['signals']), 'evidence' => count($created['evidence']), 'provider_required' => false]);
        return ['workspace_id' => $workspace_id, 'run_id' => $run_id, 'input' => $v, 'created' => $created, 'provider_required' => false, 'provider_ready' => true, 'provider_called' => false];
    }

    private static function rows_from_input(array $v, array $provider_rows): array {
        if (!empty($provider_rows)) { return array_slice($provider_rows, 0, $v['result_limit']); }
        if ($v['mode'] === 'post') { return [['platform' => self::platform_from_url($v['post_url']), 'source_url' => $v['post_url'], 'caption' => 'Public post URL supplied for analysis. No fetch performed in this deterministic route.', 'relevance_label' => 'direct_match', 'language' => $v['language']]]; }
        if ($v['mode'] === 'profile') { return [['platform' => self::platform_from_url($v['profile']), 'author_handle' => $v['profile'], 'caption' => 'Public profile/handle supplied for analysis. No private content accessed.', 'relevance_label' => 'semantic_fit', 'language' => $v['language']]]; }
        if ($v['mode'] === 'competitor') {
            $rows = [];
            foreach ($v['competitor_handles'] as $h) { $rows[] = ['platform' => self::platform_from_url($h), 'author_handle' => $h, 'caption' => 'Competitor social reference supplied: ' . $h, 'relevance_label' => 'semantic_fit', 'language' => $v['language']]; }
            return $rows;
        }
        return [['platform' => $v['platforms'][0] ?? 'social', 'caption' => 'Topic reference supplied for social scan: ' . $v['query'], 'title' => $v['query'], 'relevance_label' => 'semantic_fit', 'language' => $v['language']]];
    }

    private static function create_social_source(int $workspace_id, int $run_id, array $v): int {
        $title = 'Social Signal Scan · ' . $v['mode'];
        $id = VES_Intelligence_Store::create_source([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'source_type' => 'social',
            'provider' => 'manual',
            'source_url' => $v['mode'] === 'post' ? $v['post_url'] : '',
            'source_title' => $title,
            'external_id' => 'social_scan:' . md5(json_encode($v)),
            'language' => $v['language'],
            'region' => substr(strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v['market'])), 0, 16),
            'credibility_score' => 50,
            'metadata' => ['playbook' => 'social_signal_scan', 'mode' => $v['mode'], 'providers_expanded' => false, 'provider_ready' => true, 'provider_called' => false],
        ]);
        return self::is_err($id) ? 0 : (int) $id;
    }

    private static function create_social_insight(int $workspace_id, int $run_id, array $v, array $signals, array $evidence): int {
        $title = 'Social signal hypothesis ready for review';
        $summary = 'The scan normalized ' . count($signals) . ' social signal(s). Treat weak labels as hypothesis until supported by another source family.';
        $id = VES_Intelligence_Store::create_insight([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'insight_type' => 'content',
            'title' => $title,
            'summary' => $summary,
            'recommendation' => 'Review evidence first, then create or revise a social brief.',
            'confidence_score' => count($evidence) > 0 ? 52 : 30,
            'status' => 'draft',
            'evidence_ids' => $evidence,
            'signal_ids' => $signals,
            'metadata' => ['playbook' => 'social_signal_scan', 'mode' => $v['mode'], 'quality_note' => 'Social virality is not market demand by itself.'],
        ]);
        if (self::is_err($id)) { return 0; }
        $id = (int) $id;
        foreach ($evidence as $eid) { self::edge($workspace_id, $run_id, 'evidence', (int) $eid, 'insight', $id, 'supports'); }
        return $id;
    }

    private static function platform_from_url(string $s): string {
        $s = strtolower($s);
        foreach (['tiktok','instagram','reddit','linkedin','youtube','facebook'] as $p) { if (strpos($s, $p) !== false) { return $p; } }
        return 'social';
    }
    private static function idempotency_key(int $workspace_id, array $v, string $explicit): string { return $explicit !== '' ? substr(preg_replace('/[^a-zA-Z0-9_:\.\-]/', '', $explicit), 0, 191) : 'fi:social_scan:' . $workspace_id . ':' . md5(json_encode($v)); }
    private static function edge(int $workspace_id, int $run_id, string $from_type, int $from_id, string $to_type, int $to_id, string $rel): void { if ($workspace_id > 0 && $from_id > 0 && $to_id > 0 && class_exists('VES_Decision_Edge_Service')) { VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, $from_type, $from_id, $to_type, $to_id, $rel, ['metadata' => ['playbook' => 'social_signal_scan']]); } }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
