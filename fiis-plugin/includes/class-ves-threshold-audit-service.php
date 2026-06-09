<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Threshold_Audit_Service — Phase 5B. Read-only audit of whether the Phase 4/5
 * thresholds look too strict or too loose against the CURRENT data. Deterministic,
 * no LLM, no external calls, no writes. Used by the staging validation service and
 * the admin readiness panel.
 */
final class VES_Threshold_Audit_Service {

    /** Audit insight eligibility/quality distribution over a bounded reassessed sample. */
    public static function audit_insight_thresholds(array $filters = []): array {
        $ws = (int) ($filters['workspace_id'] ?? 0);
        $sample = max(1, min(100, (int) ($filters['sample'] ?? 30)));
        $by_status = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::count_insights_by_status($ws) : [];
        $total = 0; foreach ($by_status as $c) { $total += (int) $c; }

        $ov = class_exists('VES_Insight_Lifecycle_Service') ? VES_Insight_Lifecycle_Service::quality_overview($ws, ['sample' => $sample]) : [];
        $assessed = (int) ($ov['sample_size'] ?? 0);
        $qualities = [];
        $no_evidence = 0;
        foreach ((array) ($ov['recent'] ?? []) as $r) {
            $qualities[] = (float) ($r['quality_score'] ?? 0);
            if ((int) ($r['evidence_count'] ?? 0) === 0) { $no_evidence++; }
        }
        return [
            'total_insights'        => $total,
            'total_assessed'        => $assessed,
            'review_eligible_pct'   => self::pct((int) ($ov['eligible_review'] ?? 0), $assessed),
            'approval_eligible_pct' => self::pct((int) ($ov['eligible_approval'] ?? 0), $assessed),
            'no_evidence_pct'       => self::pct($no_evidence, $assessed),
            'low_quality_pct'       => self::pct((int) ($ov['below_quality'] ?? 0), $assessed),
            'average_quality'       => (float) ($ov['avg_quality'] ?? 0),
            'median_quality'        => self::median($qualities),
            'missing_evidence_total'=> class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::count_insights_missing_evidence($ws) : 0,
            'by_status'             => $by_status,
        ];
    }

    /** Audit trend record distribution + coverage against the current thresholds. */
    public static function audit_trend_thresholds(array $filters = []): array {
        $ws = (int) ($filters['workspace_id'] ?? 0);
        $by_class = class_exists('VES_Trend_Record_Store') ? VES_Trend_Record_Store::count_by_classification($ws) : [];
        $total = 0; foreach ($by_class as $c) { $total += (int) $c; }

        $list_filters = ['limit' => 200]; if ($ws > 0) { $list_filters['workspace_id'] = $ws; }
        $records = class_exists('VES_Trend_Record_Store') ? VES_Trend_Record_Store::list_trend_records($list_filters) : [];
        $scores = []; $high_no_insight = 0; $min_high = (float) ($filters['high_score'] ?? 60);
        foreach ($records as $tr) {
            $scores[] = (float) ($tr['trend_score'] ?? 0);
            $class = (string) ($tr['classification'] ?? '');
            if ((float) ($tr['trend_score'] ?? 0) >= $min_high && !in_array($class, ['noise', 'insufficient_data'], true)) {
                $rws = (int) ($tr['workspace_id'] ?? 0);
                if ($rws > 0 && class_exists('VES_Intelligence_Store')
                    && VES_Intelligence_Store::find_insight_by_trend_record($rws, (int) ($tr['id'] ?? 0)) === 0) { $high_no_insight++; }
            }
        }
        $golden = class_exists('VES_Trend_Golden_Set') ? VES_Trend_Golden_Set::evaluate() : ['passed' => 0, 'total' => 0, 'failed' => 0];

        return [
            'total_records'            => $total,
            'classification_distribution' => $by_class,
            'insufficient_data_pct'    => self::pct((int) ($by_class['insufficient_data'] ?? 0), $total),
            'noise_pct'                => self::pct((int) ($by_class['noise'] ?? 0), $total),
            'high_score_no_insight'    => $high_no_insight,
            'average_trend_score'      => $scores ? round(array_sum($scores) / count($scores), 2) : 0.0,
            'median_trend_score'       => self::median($scores),
            'golden_set'               => ['passed' => (int) ($golden['passed'] ?? 0), 'total' => (int) ($golden['total'] ?? 0), 'failed' => (int) ($golden['failed'] ?? 0)],
        ];
    }

    /** Deterministic threshold warnings from the audit results. */
    public static function suggest_threshold_warnings(array $audit): array {
        $w = [];
        $insight = is_array($audit['insight'] ?? null) ? $audit['insight'] : [];
        $trend   = is_array($audit['trend'] ?? null) ? $audit['trend'] : [];

        if ((int) ($insight['total_assessed'] ?? 0) > 0 && (float) ($insight['review_eligible_pct'] ?? 0) < 10.0) {
            $w[] = 'Few insights are review-eligible; thresholds may be too strict or evidence coverage is weak.';
        }
        if ((float) ($insight['no_evidence_pct'] ?? 0) >= 50.0) {
            $w[] = 'Over half of sampled insights have no evidence; improve evidence linking before promoting.';
        }
        if ((int) ($insight['missing_evidence_total'] ?? 0) > 0 && (int) (($insight['by_status']['approved'] ?? 0)) > 0) {
            // approved insights should always have evidence (hard gate) — flag any mismatch defensively.
            $w[] = 'Some insights are approved while missing-evidence records exist; re-run insight-assess to confirm integrity.';
        }
        if ((int) ($trend['high_score_no_insight'] ?? 0) > 0) {
            $w[] = 'High-score trends have no draft insight; run wp ves trend-insights --apply.';
        }
        $g = is_array($trend['golden_set'] ?? null) ? $trend['golden_set'] : [];
        if ((int) ($g['failed'] ?? 0) > 0) {
            $w[] = 'Golden set FAILED; do not promote trend thresholds until the classifier passes.';
        }
        return $w;
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function pct(int $part, int $total): float { return $total > 0 ? round($part / $total * 100, 1) : 0.0; }
    private static function median(array $vals): float {
        $vals = array_values(array_filter($vals, 'is_numeric'));
        $n = count($vals);
        if ($n === 0) { return 0.0; }
        sort($vals);
        $mid = (int) floor(($n - 1) / 2);
        return $n % 2 === 1 ? round($vals[$mid], 2) : round(($vals[$mid] + $vals[$mid + 1]) / 2, 2);
    }
}
