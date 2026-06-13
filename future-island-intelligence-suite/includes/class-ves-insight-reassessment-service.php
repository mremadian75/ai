<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Insight_Reassessment_Service — Phase 5B batch reassessment of existing insights.
 *
 * Re-runs the Phase 5 deterministic evidence validator + quality scorer over old
 * insights and produces a STATUS RECOMMENDATION. Hard safety rules (12–15):
 *   - DRY-RUN by default (no writes).
 *   - apply writes ONLY assessment metadata by default (never status).
 *   - --promote-reviewed-safe promotes ONLY evidence-backed, review-eligible drafts
 *     (re-gated by the lifecycle + store hard gate).
 *   - --reject-unsupported rejects ONLY unsupported drafts, with a recorded reason.
 *   - There is NO approve path and NO delete path — ever. Unsupported insights are
 *     never promoted. No LLM is used to decide anything.
 */
final class VES_Insight_Reassessment_Service {

    const RECOMMENDATIONS = [
        'keep_draft', 'eligible_for_review', 'eligible_for_approval_manual_only',
        'needs_evidence', 'reject_unsupported', 'archive_stale', 'reassess_later',
    ];

    /** Discover insights to reassess. Read-only. */
    public static function find_candidates(array $filters = []): array {
        if (!class_exists('VES_Intelligence_Store')) { return []; }
        $list_filters = ['limit' => max(1, min(500, (int) ($filters['limit'] ?? 100)))];
        if (!empty($filters['workspace_id'])) { $list_filters['workspace_id'] = (int) $filters['workspace_id']; }
        if (!empty($filters['status'])) { $list_filters['status'] = (string) $filters['status']; }
        $rows = VES_Intelligence_Store::list_insights($list_filters);

        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $before = isset($filters['created_before']) ? strtotime((string) $filters['created_before']) : 0;
        $after  = isset($filters['created_after']) ? strtotime((string) $filters['created_after']) : 0;
        $want_missing = !empty($filters['missing_evidence']);

        $out = [];
        foreach ($rows as $r) {
            $created = strtotime((string) ($r['created_at'] ?? ''));
            if ($before && $created && $created > $before) { continue; }
            if ($after && $created && $created < $after) { continue; }
            $evc = count(is_array($r['evidence_ids'] ?? null) ? $r['evidence_ids'] : []);
            if ($want_missing && $evc > 0) { continue; }
            $out[] = ['id' => (int) ($r['id'] ?? 0), 'status' => (string) ($r['status'] ?? ''), 'evidence_count' => $evc, 'created_at' => (string) ($r['created_at'] ?? '')];
        }
        if ($offset > 0) { $out = array_slice($out, $offset); }
        return $out;
    }

    /** Deterministic status recommendation. NEVER recommends auto-approval. */
    public static function build_status_recommendation(array $insight, array $assessment): array {
        $v = is_array($assessment['validation'] ?? null) ? $assessment['validation'] : [];
        $q = is_array($assessment['quality'] ?? null) ? $assessment['quality'] : [];
        $status = (string) ($insight['status'] ?? 'draft');
        $has_evidence = !empty($v['has_evidence']);
        $min_met = !empty($v['minimum_requirements_met']);
        $unsupported = !empty($v['unsupported_claims']) && empty($v['supported_claims']);
        $reasons = [];

        if (!$has_evidence) {
            $rec = ($status === 'draft') ? 'needs_evidence' : 'reject_unsupported';
            $reasons[] = 'no_evidence';
        } elseif ($unsupported) {
            $rec = 'reject_unsupported'; $reasons[] = 'claims_unsupported';
        } elseif (!empty($q['eligible_for_approval']) && $min_met) {
            $rec = 'eligible_for_approval_manual_only'; $reasons[] = 'approval_eligible'; // manual only — never auto
        } elseif (!empty($q['eligible_for_review']) && $min_met) {
            $rec = 'eligible_for_review'; $reasons[] = 'review_eligible';
        } elseif (in_array('stale_evidence', (array) ($q['warnings'] ?? []), true)) {
            $rec = 'archive_stale'; $reasons[] = 'stale';
        } else {
            $rec = 'keep_draft'; $reasons[] = 'below_review_threshold';
        }
        return ['recommendation' => $rec, 'reasons' => $reasons];
    }

    /**
     * Reassess one insight. @return array|WP_Error
     * $args flags: apply, apply_metadata, promote_reviewed_safe, reject_unsupported.
     */
    public static function reassess_one(int $insight_id, array $args = []) {
        if (!class_exists('VES_Insight_Lifecycle_Service')) { return new WP_Error('ves_reassess_unavailable', 'Lifecycle service unavailable.'); }
        $assess = VES_Insight_Lifecycle_Service::reassess_insight($insight_id);
        if (self::is_err($assess)) { return $assess; }
        $insight = is_array($assess['insight'] ?? null) ? $assess['insight'] : (class_exists('VES_Intelligence_Store') ? (array) VES_Intelligence_Store::get_insight($insight_id) : []);
        if (empty($insight)) { $insight = ['status' => (string) ($assess['status'] ?? 'draft')]; }
        $rec = self::build_status_recommendation($insight, $assess);

        $apply_any = !empty($args['apply']) || !empty($args['apply_metadata']) || !empty($args['promote_reviewed_safe']) || !empty($args['reject_unsupported']);
        $result = [
            'insight_id'     => $insight_id,
            'current_status' => (string) ($assess['status'] ?? ''),
            'quality_score'  => (float) ($assess['quality']['quality_score'] ?? 0),
            'evidence_count' => (int) ($assess['validation']['evidence_count'] ?? 0),
            'has_evidence'   => (bool) ($assess['validation']['has_evidence'] ?? false),
            'recommendation' => $rec['recommendation'],
            'reasons'        => $rec['reasons'],
            'applied'        => [],
            'dry_run'        => !$apply_any,
        ];
        if (!$apply_any) { return $result; }

        // 1) Always write assessment metadata (never status) when applying.
        if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'merge_insight_metadata')) {
            $m = VES_Intelligence_Store::merge_insight_metadata($insight_id, [
                'reassessed_at'        => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
                'reassessment_quality' => $result['quality_score'],
                'reassessment_recommendation' => $result['recommendation'],
                'eligible_for_review'  => (bool) ($assess['quality']['eligible_for_review'] ?? false),
                'eligible_for_approval'=> (bool) ($assess['quality']['eligible_for_approval'] ?? false),
            ]);
            if (!self::is_err($m)) { $result['applied'][] = 'metadata'; }
        }

        $status = $result['current_status'];
        // 2) Safe reviewed promotion (gated). NEVER approve.
        if (!empty($args['promote_reviewed_safe']) && $rec['recommendation'] === 'eligible_for_review' && $status === 'draft') {
            $p = VES_Insight_Lifecycle_Service::promote_to_reviewed($insight_id);
            if (!self::is_err($p)) { $result['applied'][] = 'promoted_reviewed'; }
            else { $result['promote_blocked'] = $p->get_error_code(); }
        }
        // 3) Reject only explicitly-unsupported drafts.
        if (!empty($args['reject_unsupported']) && $rec['recommendation'] === 'reject_unsupported' && $status === 'draft') {
            $rj = VES_Insight_Lifecycle_Service::reject_insight($insight_id, 'reassessment: unsupported claim / no evidence');
            if (!self::is_err($rj)) { $result['applied'][] = 'rejected'; }
        }
        return $result;
    }

    /** Reassess a batch of candidates and aggregate. DRY-RUN unless an apply flag is set. */
    public static function reassess_batch(array $args = []): array {
        $candidates = self::find_candidates($args);
        $apply_any = !empty($args['apply']) || !empty($args['apply_metadata']) || !empty($args['promote_reviewed_safe']) || !empty($args['reject_unsupported']);
        $summary = [
            'dry_run' => !$apply_any, 'candidates' => count($candidates), 'reassessed' => 0,
            'by_recommendation' => array_fill_keys(self::RECOMMENDATIONS, 0),
            'metadata_written' => 0, 'promoted_reviewed' => 0, 'rejected' => 0, 'errors' => 0, 'details' => [],
        ];
        foreach ($candidates as $c) {
            $r = self::reassess_one((int) $c['id'], $args);
            if (self::is_err($r)) { $summary['errors']++; continue; }
            $summary['reassessed']++;
            $rec = $r['recommendation'];
            if (isset($summary['by_recommendation'][$rec])) { $summary['by_recommendation'][$rec]++; }
            if (in_array('metadata', $r['applied'], true)) { $summary['metadata_written']++; }
            if (in_array('promoted_reviewed', $r['applied'], true)) { $summary['promoted_reviewed']++; }
            if (in_array('rejected', $r['applied'], true)) { $summary['rejected']++; }
            if (count($summary['details']) < 50) { $summary['details'][] = $r; }
        }
        return $summary;
    }

    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
}
