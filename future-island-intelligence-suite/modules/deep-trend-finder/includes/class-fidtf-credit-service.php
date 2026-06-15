<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Credit_Service {
    public static function estimate(array $request, array $channels): array {
        $settings = FIDTF_Settings::get();
        $multipliers = is_array($settings['credit_multipliers'] ?? null) ? $settings['credit_multipliers'] : [];
        $local_planning = 1.0 * (float) ($multipliers['planning'] ?? 1.0);
        $ai_planner = FIDTF_Settings::ai_planner_bridge_enabled() ? 1.25 * (float) ($multipliers['planning'] ?? 1.0) : 0.0;
        $source_units = 0.0;
        $batch_units = 0.0;
        foreach ($channels as $source) {
            $limit = FIDTF_Settings::source_limit((string) $source);
            $source_units += (0.75 + min(4.0, $limit / 100.0)) * (float) ($multipliers['source_job'] ?? 1.0);
            $batch_units += max(1, ceil($limit / max(5, (int) ($settings['max_relevance_batch_size'] ?? 40)))) * 0.35 * (float) ($multipliers['relevance_batch'] ?? 1.0);
        }
        $synthesis = 1.5 * (float) ($multipliers['synthesis'] ?? 1.0);
        $deep_video = FIDTF_Settings::deep_video_enabled() ? 10.0 * (float) ($multipliers['deep_video'] ?? 8.0) : 0.0;
        $total = round($local_planning + $ai_planner + $source_units + $batch_units + $synthesis + $deep_video, 2);
        return [
            'estimated_credits' => $total,
            'breakdown' => [
                'local_planning' => round($local_planning, 2),
                'planning' => round($local_planning, 2),
                'ai_planner' => round($ai_planner, 2),
                'source_jobs' => round($source_units, 2),
                'relevance_batches' => round($batch_units, 2),
                'final_synthesis' => round($synthesis, 2),
                'deep_video' => round($deep_video, 2),
            ],
            'charging_policy' => 'No final source charge when a source fails before useful evidence. Partial sources settle proportionally in later phases.',
        ];
    }

    public static function reserve(int $run_id, int $user_id, float $estimate, array $context = []) {
        $settings = FIDTF_Settings::get();
        if (empty($settings['enable_credit_reservation']) || $estimate <= 0 || $user_id <= 0 || !class_exists('VES_Usage_Billing') || !method_exists('VES_Usage_Billing', 'reserve_usage')) {
            return [
                'reserved' => false,
                'credits_reserved' => 0.0,
                'reason' => 'credit_reservation_disabled_or_unavailable',
            ];
        }
        $usage_key = 'deep_trend_run:' . (int) $run_id;
        return VES_Usage_Billing::reserve_usage($user_id, 'deep_trend_run', $usage_key, 'Deep Trend Finder reservation', array_merge($context, [
            'module' => 'deep_trend_finder',
            'run_id' => $run_id,
            'estimated_credits' => $estimate,
        ]));
    }


    public static function credit_mode(array $run): string {
        $final = (float) ($run['final_credits_settled'] ?? 0);
        if ($final > 0) {
            return 'settled';
        }
        $reserved = (float) ($run['credits_reserved'] ?? 0);
        if ($reserved > 0) {
            return 'reserved';
        }
        $status = sanitize_key((string) ($run['status'] ?? ''));
        if (!empty($run['credits_voided']) && in_array($status, ['failed', 'completed_no_relevant_evidence', 'planned_waiting_for_sources'], true)) {
            return 'voided';
        }
        return 'planning_only';
    }

    public static function settlement_for_source(string $status, int $relevant_count): array {
        $status = sanitize_key($status);
        if (in_array($status, ['failed', 'retryable_failed', 'skipped'], true) && $relevant_count <= 0) {
            return ['final_charge_allowed' => false, 'settlement_reason' => 'source_failed_before_useful_evidence'];
        }
        if ($relevant_count <= 0) {
            return ['final_charge_allowed' => false, 'settlement_reason' => 'no_useful_evidence'];
        }
        return ['final_charge_allowed' => true, 'settlement_reason' => 'useful_evidence_delivered'];
    }
}
