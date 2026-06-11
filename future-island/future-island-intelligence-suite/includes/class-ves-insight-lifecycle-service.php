<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Insight_Lifecycle_Service — Phase 5 safe promote/approve/reject/archive.
 *
 * Promotion is gated by deterministic evidence validation (VES_Insight_Evidence_Validator)
 * AND quality scoring (VES_Insight_Quality_Scorer). The store
 * (VES_Intelligence_Store::update_insight_status) additionally enforces a HARD rule:
 * no insight can reach reviewed/approved without evidence. Reject/archive only record
 * a reason in metadata — never a destructive delete; created_at is preserved.
 * No LLM; AI text can never override evidence validation (rules 12–14).
 */
final class VES_Insight_Lifecycle_Service {

    /** Load an insight + its evidence/source/trend context for assessment. */
    public static function build_insight_context(int $insight_id): array {
        $ctx = ['insight' => [], 'evidence' => [], 'sources' => [], 'trend_record' => [], 'source_ids' => [], 'signal_ids' => []];
        if (!class_exists('VES_Intelligence_Store')) { return $ctx; }
        $insight = VES_Intelligence_Store::get_insight($insight_id);
        if (!is_array($insight)) { return $ctx; }
        $ctx['insight'] = $insight;
        $ctx['signal_ids'] = is_array($insight['signal_ids'] ?? null) ? array_map('intval', $insight['signal_ids']) : [];

        $source_ids = [];
        foreach ((array) ($insight['evidence_ids'] ?? []) as $eid) {
            $e = VES_Intelligence_Store::get_evidence((int) $eid);
            if (is_array($e)) {
                $ctx['evidence'][] = $e;
                $sid = (int) ($e['source_id'] ?? 0);
                if ($sid > 0 && !in_array($sid, $source_ids, true)) { $source_ids[] = $sid; }
            }
        }
        foreach ($source_ids as $sid) { $s = VES_Intelligence_Store::get_source($sid); if (is_array($s)) { $ctx['sources'][] = $s; } }
        $ctx['source_ids'] = $source_ids;

        $trend_id = (int) ($insight['metadata']['trend_record_id'] ?? 0);
        if ($trend_id > 0 && class_exists('VES_Trend_Record_Store')) {
            $tr = VES_Trend_Record_Store::get_trend_record($trend_id);
            if (is_array($tr)) { $ctx['trend_record'] = $tr; }
        }
        return $ctx;
    }

    /** Run the deterministic validator + quality scorer on an insight. */
    public static function reassess_insight(int $insight_id) {
        $ctx = self::build_insight_context($insight_id);
        if (empty($ctx['insight'])) { return new WP_Error('ves_insight_not_found', 'Insight not found.'); }
        $validation = class_exists('VES_Insight_Evidence_Validator') ? VES_Insight_Evidence_Validator::validate_insight($ctx['insight'], $ctx) : ['minimum_requirements_met' => false];
        $quality = class_exists('VES_Insight_Quality_Scorer') ? VES_Insight_Quality_Scorer::score_insight($ctx['insight'], $ctx) : ['quality_score' => 0, 'eligible_for_review' => false, 'eligible_for_approval' => false];
        return [
            'insight_id' => $insight_id,
            'status'     => (string) ($ctx['insight']['status'] ?? ''),
            'validation' => $validation,
            'quality'    => $quality,
        ];
    }

    public static function promote_to_reviewed(int $insight_id, array $args = []) {
        $assess = self::reassess_insight($insight_id);
        if (self::is_err($assess)) { return $assess; }
        if (empty($assess['validation']['minimum_requirements_met'])) {
            return new WP_Error('ves_insight_evidence_required', 'Cannot review: minimum evidence requirements not met.');
        }
        if (empty($assess['quality']['eligible_for_review'])) {
            return new WP_Error('ves_insight_low_quality', 'Cannot review: quality below the review threshold.');
        }
        return VES_Intelligence_Store::update_insight_status($insight_id, 'reviewed', [
            'reviewed_at'   => self::now(),
            'quality_score' => $assess['quality']['quality_score'] ?? 0,
            'reviewed_by'   => self::actor($args),
        ]);
    }

    public static function approve_insight(int $insight_id, array $args = []) {
        $assess = self::reassess_insight($insight_id);
        if (self::is_err($assess)) { return $assess; }
        if (empty($assess['validation']['minimum_requirements_met'])) {
            return new WP_Error('ves_insight_evidence_required', 'Cannot approve: minimum evidence requirements not met.');
        }
        if (empty($assess['quality']['eligible_for_approval'])) {
            return new WP_Error('ves_insight_low_quality', 'Cannot approve: quality/confidence below the approval threshold.');
        }
        return VES_Intelligence_Store::update_insight_status($insight_id, 'approved', [
            'approved_at'   => self::now(),
            'quality_score' => $assess['quality']['quality_score'] ?? 0,
            'approved_by'   => self::actor($args),
        ]);
    }

    public static function reject_insight(int $insight_id, string $reason = '') {
        return VES_Intelligence_Store::update_insight_status($insight_id, 'rejected', [
            'rejected_at'     => self::now(),
            'rejected_reason' => self::clean_reason($reason),
        ]);
    }

    public static function archive_insight(int $insight_id, string $reason = '') {
        return VES_Intelligence_Store::update_insight_status($insight_id, 'archived', [
            'archived_at'     => self::now(),
            'archived_reason' => self::clean_reason($reason),
        ]);
    }

    /**
     * v0.1 RC — the ONLY safe way out of the terminal 'rejected' state. Reopening
     * always lands on 'draft' (back into human review with the evidence/quality
     * gates ahead of it); it can never jump to approved. Requires a reason and
     * records who reopened it.
     */
    public static function reopen_insight(int $insight_id, string $reason = '', array $args = []) {
        $reason = self::clean_reason($reason);
        if ($reason === '') { return new WP_Error('ves_insight_reason_required', 'Reopening a rejected insight requires a reason.'); }
        return VES_Intelligence_Store::update_insight_status($insight_id, 'draft', [
            'reopened_at'     => self::now(),
            'reopened_reason' => $reason,
            'reopened_by'     => self::actor($args),
        ], ['allow_reopen' => true]);
    }

    /**
     * v0.1 RC — the ONLY safe way out of the terminal 'archived' state. Restoring
     * always lands on 'draft'; it can never jump to approved. Requires a reason.
     */
    public static function restore_insight(int $insight_id, string $reason = '', array $args = []) {
        $reason = self::clean_reason($reason);
        if ($reason === '') { return new WP_Error('ves_insight_reason_required', 'Restoring an archived insight requires a reason.'); }
        return VES_Intelligence_Store::update_insight_status($insight_id, 'draft', [
            'restored_at'     => self::now(),
            'restored_reason' => $reason,
            'restored_by'     => self::actor($args),
        ], ['allow_restore' => true]);
    }

    /**
     * Phase 5 (Part H) — admin diagnostics provider. Counts + a bounded reassessed
     * sample so the panel can show evidence/quality health cheaply. No secrets.
     */
    public static function quality_overview(int $workspace_id = 0, array $opts = []): array {
        $sample = max(1, min(50, (int) ($opts['sample'] ?? 10)));
        $out = [
            'totals' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::count_insights_by_status($workspace_id) : [],
            'missing_evidence' => class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::count_insights_missing_evidence($workspace_id) : 0,
            'sample_size' => 0, 'eligible_review' => 0, 'eligible_approval' => 0, 'below_quality' => 0,
            'avg_quality' => 0.0, 'trends_without_insight' => 0, 'recent' => [],
        ];
        if (!class_exists('VES_Intelligence_Store')) { return $out; }

        $filters = ['limit' => $sample];
        if ($workspace_id > 0) { $filters['workspace_id'] = $workspace_id; }
        $insights = VES_Intelligence_Store::list_insights($filters);
        $qsum = 0.0; $review_min = 50.0;
        if (class_exists('VES_Insight_Quality_Scorer')) { $review_min = (float) VES_Insight_Quality_Scorer::get_thresholds()['review_quality_min']; }
        foreach ($insights as $ins) {
            $assess = self::reassess_insight((int) ($ins['id'] ?? 0));
            if (self::is_err($assess)) { continue; }
            $q = (float) ($assess['quality']['quality_score'] ?? 0);
            $out['sample_size']++;
            $qsum += $q;
            if (!empty($assess['quality']['eligible_for_review'])) { $out['eligible_review']++; }
            if (!empty($assess['quality']['eligible_for_approval'])) { $out['eligible_approval']++; }
            if ($q < $review_min) { $out['below_quality']++; }
            $out['recent'][] = [
                'id' => (int) ($ins['id'] ?? 0),
                'title' => (string) ($ins['title'] ?? ''),
                'status' => (string) ($ins['status'] ?? ''),
                'quality_score' => $q,
                'evidence_count' => (int) ($assess['validation']['evidence_count'] ?? 0),
                'eligible_for_review' => (bool) ($assess['quality']['eligible_for_review'] ?? false),
            ];
        }
        $out['avg_quality'] = $out['sample_size'] > 0 ? round($qsum / $out['sample_size'], 2) : 0.0;

        // High-score trend records that have no insight yet (bounded).
        if (class_exists('VES_Trend_Record_Store')) {
            $min_score = (float) ($opts['trend_min_score'] ?? 60);
            $trends = VES_Trend_Record_Store::list_trend_records(array_merge(['min_score' => $min_score, 'limit' => 50], $workspace_id > 0 ? ['workspace_id' => $workspace_id] : []));
            foreach ($trends as $tr) {
                if (in_array(($tr['classification'] ?? ''), ['noise', 'insufficient_data'], true)) { continue; }
                $ws = (int) ($tr['workspace_id'] ?? 0);
                if ($ws > 0 && VES_Intelligence_Store::find_insight_by_trend_record($ws, (int) ($tr['id'] ?? 0)) === 0) { $out['trends_without_insight']++; }
            }
        }
        return $out;
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function clean_reason(string $r): string {
        $r = function_exists('sanitize_text_field') ? sanitize_text_field($r) : trim(strip_tags($r));
        return substr($r, 0, 300);
    }
    private static function actor(array $args): int {
        if (isset($args['actor_id'])) { return max(0, (int) $args['actor_id']); }
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }
}
