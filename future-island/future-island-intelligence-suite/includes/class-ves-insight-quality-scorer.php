<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Insight_Quality_Scorer — Phase 5 deterministic insight quality model.
 *
 * Scores an insight on evidence / source / freshness / specificity / actionability /
 * confidence and derives a composite quality_score plus review/approval eligibility.
 * NO LLM, no external calls. Missing evidence strongly reduces eligibility; an
 * unsupported insight is never eligible for review or approval (rules 12–14).
 *
 * Thresholds are filterable via `ves_insight_quality_thresholds` /
 * `fidtf_insight_quality_thresholds` (validated + clamped).
 */
final class VES_Insight_Quality_Scorer {

    private static function default_thresholds(): array {
        return [
            'min_evidence_for_review'    => 1,
            'min_evidence_for_approval'  => 2,
            'review_quality_min'         => 50.0,
            'approval_quality_min'       => 70.0,
            'approval_confidence_min'    => 60.0,
            'evidence_confidence_min'    => 40.0, // below this, evidence is "weak"
            'freshness_days'             => 30,
        ];
    }

    public static function get_thresholds(array $overrides = []): array {
        $th = self::default_thresholds();
        if (function_exists('apply_filters')) {
            foreach (['ves_insight_quality_thresholds', 'fidtf_insight_quality_thresholds'] as $hook) {
                $f = apply_filters($hook, $th);
                if (is_array($f)) { $th = self::validate($th, $f); }
            }
        }
        if (!empty($overrides)) { $th = self::validate($th, $overrides); }
        return $th;
    }

    private static function validate(array $base, array $cand): array {
        $bounds = [
            'min_evidence_for_review'   => ['min' => 0, 'max' => 100, 'int' => true],
            'min_evidence_for_approval' => ['min' => 1, 'max' => 100, 'int' => true],
            'review_quality_min'        => ['min' => 0.0, 'max' => 100.0],
            'approval_quality_min'      => ['min' => 0.0, 'max' => 100.0],
            'approval_confidence_min'   => ['min' => 0.0, 'max' => 100.0],
            'evidence_confidence_min'   => ['min' => 0.0, 'max' => 100.0],
            'freshness_days'            => ['min' => 1, 'max' => 3650, 'int' => true],
        ];
        foreach ($cand as $k => $v) {
            if (!isset($bounds[$k]) || !is_numeric($v)) { continue; }
            $val = (float) $v;
            $val = max($bounds[$k]['min'], min($bounds[$k]['max'], $val));
            $base[$k] = !empty($bounds[$k]['int']) ? (int) round($val) : $val;
        }
        return $base;
    }

    /**
     * @param array $insight  canonical insight row (title, summary, recommendation, confidence_score, evidence_ids, metadata)
     * @param array $context  ['evidence'=>[rows], 'sources'=>[rows], 'signals'=>[rows], 'trend_record'=>row, 'now'=>ts]
     * @return array scoring result (see class doc)
     */
    public static function score_insight(array $insight, array $context = []): array {
        $th = self::get_thresholds(is_array($context['thresholds'] ?? null) ? $context['thresholds'] : []);
        $now = isset($context['now']) ? (int) $context['now'] : time();

        $evidence = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];
        $sources  = is_array($context['sources'] ?? null) ? $context['sources'] : [];
        $trend    = is_array($context['trend_record'] ?? null) ? $context['trend_record'] : [];

        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? $insight['evidence_ids'] : [];
        $evidence_count = max(count($evidence), count($evidence_ids));

        $reasons = []; $warnings = [];

        // ── evidence_score ──
        $avg_conf = self::avg($evidence, 'confidence_score');
        $avg_len  = self::avg_len($evidence, 'text');
        $evidence_score = 0.0;
        if ($evidence_count > 0) {
            $evidence_score = self::clamp(
                min(1.0, $evidence_count / 3.0) * 50.0
                + ($avg_conf / 100.0) * 30.0
                + min(1.0, $avg_len / 120.0) * 20.0
            );
            if ($avg_conf > 0 && $avg_conf < $th['evidence_confidence_min']) { $warnings[] = 'weak_evidence_confidence'; }
        } else {
            $reasons[] = 'no_evidence';
        }

        // ── source_score ──
        $source_count = count($sources);
        $providers = [];
        foreach ($sources as $s) { $p = strtolower((string) ($s['provider'] ?? '')); if ($p !== '') { $providers[$p] = true; } }
        $trusted = ['google', 'reddit', 'apify', 'openai'];
        $has_trusted = (bool) array_intersect(array_keys($providers), $trusted);
        $source_score = self::clamp(
            min(1.0, $source_count / 2.0) * 40.0
            + min(1.0, count($providers) / 2.0) * 30.0
            + ($has_trusted ? 30.0 : ($source_count > 0 ? 15.0 : 0.0))
        );
        if ($source_count === 0) { $warnings[] = 'no_source_link'; }
        elseif (count($providers) < 2) { $warnings[] = 'low_source_diversity'; }

        // ── freshness_score ── (most recent evidence/source timestamp)
        $latest = 0;
        foreach (array_merge($evidence, $sources) as $r) {
            foreach (['observed_at', 'created_at', 'last_seen_at'] as $col) {
                if (!empty($r[$col])) { $ts = strtotime((string) $r[$col] . ' UTC'); if ($ts && $ts > $latest) { $latest = $ts; } }
            }
        }
        if ($latest > 0) {
            $age_days = max(0, ($now - $latest) / 86400);
            $freshness_score = self::clamp(100 - ($age_days / max(1, $th['freshness_days'])) * 100);
            if ($age_days > $th['freshness_days']) { $warnings[] = 'stale_evidence'; }
        } else {
            $freshness_score = 50.0; // unknown
        }

        // ── specificity_score ──
        $title = (string) ($insight['title'] ?? '');
        $specificity_score = self::clamp(
            min(1.0, strlen($title) / 40.0) * 30.0
            + (!empty($trend['classification']) && !in_array($trend['classification'], ['noise', 'insufficient_data'], true) ? 30.0 : 0.0)
            + (!empty($trend['trend_score']) ? min(1.0, (float) $trend['trend_score'] / 100.0) * 20.0 : 0.0)
            + ($evidence_count > 0 ? 20.0 : 0.0)
        );

        // ── actionability_score ──
        $recommendation = trim((string) ($insight['recommendation'] ?? ($insight['metadata']['recommended_action'] ?? '')));
        $actionability_score = $recommendation !== '' ? self::clamp(60.0 + min(1.0, strlen($recommendation) / 80.0) * 40.0) : 20.0;
        if ($recommendation === '') { $warnings[] = 'no_recommendation'; }

        // ── confidence_score ── (blend of trend confidence + evidence confidence + source quality)
        $trend_conf = (float) ($trend['confidence_score'] ?? ($insight['confidence_score'] ?? 0));
        $confidence_score = self::clamp(0.45 * $trend_conf + 0.35 * $avg_conf + 0.20 * $source_score);

        // ── composite ──
        $quality_score = self::clamp(
            0.35 * $evidence_score + 0.15 * $source_score + 0.10 * $freshness_score
            + 0.15 * $specificity_score + 0.10 * $actionability_score + 0.15 * $confidence_score
        );

        $eligible_for_review = ($evidence_count >= $th['min_evidence_for_review'])
            && $evidence_count > 0
            && $quality_score >= $th['review_quality_min'];
        $eligible_for_approval = ($evidence_count >= $th['min_evidence_for_approval'])
            && $quality_score >= $th['approval_quality_min']
            && $confidence_score >= $th['approval_confidence_min'];

        if ($eligible_for_review) { $reasons[] = 'eligible_for_review'; }
        if ($eligible_for_approval) { $reasons[] = 'eligible_for_approval'; }

        return [
            'quality_score'        => round($quality_score, 2),
            'evidence_score'       => round($evidence_score, 2),
            'source_score'         => round($source_score, 2),
            'freshness_score'      => round($freshness_score, 2),
            'specificity_score'    => round($specificity_score, 2),
            'actionability_score'  => round($actionability_score, 2),
            'confidence_score'     => round($confidence_score, 2),
            'eligible_for_review'  => (bool) $eligible_for_review,
            'eligible_for_approval'=> (bool) $eligible_for_approval,
            'reasons'              => $reasons,
            'warnings'             => $warnings,
            'metadata'             => [
                'evidence_count' => $evidence_count,
                'source_count'   => $source_count,
                'providers'      => count($providers),
                'avg_evidence_confidence' => round($avg_conf, 2),
            ],
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function avg(array $rows, string $col): float {
        $sum = 0.0; $n = 0;
        foreach ($rows as $r) { if (isset($r[$col]) && is_numeric($r[$col])) { $sum += (float) $r[$col]; $n++; } }
        return $n > 0 ? $sum / $n : 0.0;
    }
    private static function avg_len(array $rows, string $col): float {
        $sum = 0; $n = 0;
        foreach ($rows as $r) { if (isset($r[$col])) { $sum += strlen((string) $r[$col]); $n++; } }
        return $n > 0 ? $sum / $n : 0.0;
    }
    private static function clamp($v): float {
        $v = (float) $v; if ($v < 0) { return 0.0; } if ($v > 100) { return 100.0; } return $v;
    }
}
