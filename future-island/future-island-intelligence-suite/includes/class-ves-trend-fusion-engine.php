<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Fusion_Engine {
    private static $weights = [
        'semantic_match' => 0.22,
        'freshness' => 0.14,
        'growth_or_velocity' => 0.14,
        'metric_strength' => 0.12,
        'geo_fit' => 0.10,
        'language_fit' => 0.08,
        'source_diversity' => 0.10,
        'actionability' => 0.07,
        'cost_efficiency' => 0.03,
    ];

    private static function clamp($value) { return max(0.0, min(1.0, (float) $value)); }
    private static function key_for_signal($signal) {
        $terms = (array) ($signal['matched_terms'] ?? []);
        $base = trim((string) ($signal['canonical_topic'] ?? ($terms[0] ?? $signal['raw_title'] ?? 'unknown')));
        $base = strtolower(preg_replace('/[^a-z0-9\p{L}\p{N}]+/u', '-', $base));
        return trim($base, '-') ?: 'unknown';
    }

    private static function metric_growth($signal) {
        $metrics = is_array($signal['metrics'] ?? null) ? $signal['metrics'] : [];
        $growth = isset($metrics['growth']) && is_numeric($metrics['growth']) ? (float) $metrics['growth'] : null;
        $velocity = isset($metrics['velocity']) && is_numeric($metrics['velocity']) ? (float) $metrics['velocity'] : null;
        $best = max($growth ?? 0, $velocity ?? 0);
        if ($best <= 0) { return 0.0; }
        if ($best <= 1) { return self::clamp($best); }
        return self::clamp(log10($best + 1) / 3);
    }

    private static function metric_strength($signal) {
        if (isset($signal['metric_strength_score'])) { return self::clamp($signal['metric_strength_score']); }
        $metrics = is_array($signal['metrics'] ?? null) ? $signal['metrics'] : [];
        $vals = [];
        foreach (['views','likes','comments','shares','saves','upvotes','retweets','search_interest','growth','velocity'] as $k) {
            if (isset($metrics[$k]) && is_numeric($metrics[$k])) { $vals[] = (float) $metrics[$k]; }
        }
        if (!$vals) { return 0.0; }
        return self::clamp(log10(max($vals) + 1) / 6);
    }

    private static function actionability($signals) {
        $direct = 0; $adjacent = 0; $context = 0;
        foreach ($signals as $s) {
            $e = sanitize_key((string) ($s['evidence_type'] ?? 'noise'));
            if ($e === 'direct') { $direct++; }
            elseif ($e === 'adjacent') { $adjacent++; }
            elseif (in_array($e, ['format_signal','pain_point_signal'], true)) { $context++; }
        }
        return self::clamp(($direct * 0.35) + ($adjacent * 0.18) + ($context * 0.10));
    }

    private static function cost_efficiency($signals) {
        $count = max(1, count($signals));
        $high = 0;
        foreach ($signals as $s) {
            $src = sanitize_key((string) ($s['source_key'] ?? ''));
            if (strpos($src, 'tiktok') === 0 || strpos($src, 'youtube') === 0 || strpos($src, 'ads') !== false) { $high++; }
        }
        return self::clamp(1 - ($high / ($count * 1.5)));
    }

    private static function score_candidate($signals) {
        $families = [];
        $semantic = $fresh = $growth = $metric = $geo = $lang = 0.0;
        foreach ($signals as $s) {
            $families[sanitize_key((string) ($s['source_family'] ?? 'unknown'))] = true;
            $semantic += (float) ($s['semantic_match_score'] ?? 0);
            $fresh += (float) ($s['freshness_score'] ?? 0.55);
            $growth += self::metric_growth($s);
            $metric += self::metric_strength($s);
            $geo += (float) ($s['geo_fit_score'] ?? 0.55);
            $lang += (float) ($s['language_fit_score'] ?? 0.55);
        }
        $n = max(1, count($signals));
        $source_diversity = self::clamp(count($families) / 3);
        $score =
            self::$weights['semantic_match'] * self::clamp($semantic / $n) +
            self::$weights['freshness'] * self::clamp($fresh / $n) +
            self::$weights['growth_or_velocity'] * self::clamp($growth / $n) +
            self::$weights['metric_strength'] * self::clamp($metric / $n) +
            self::$weights['geo_fit'] * self::clamp($geo / $n) +
            self::$weights['language_fit'] * self::clamp($lang / $n) +
            self::$weights['source_diversity'] * $source_diversity +
            self::$weights['actionability'] * self::actionability($signals) +
            self::$weights['cost_efficiency'] * self::cost_efficiency($signals);
        return round(self::clamp($score), 3);
    }

    private static function report_mode($candidates, $summary) {
        $usable_sources = (int) ($summary['usable_sources'] ?? 0);
        $partial_sources = (int) ($summary['partial_sources'] ?? 0);
        $failed_sources = (int) ($summary['failed_sources'] ?? 0);
        if (($usable_sources + $partial_sources) < 1) { return $failed_sources > 0 ? 'source_failure_partial_report' : 'no_actionable_trend_found'; }
        if ($failed_sources > ($usable_sources + $partial_sources) && $usable_sources < 1) { return 'source_failure_partial_report'; }
        foreach ($candidates as $candidate) {
            $direct_families = $candidate['direct_source_families'] ?? 0;
            $direct_count = $candidate['direct_signal_count'] ?? 0;
            $adjacent_count = $candidate['adjacent_signal_count'] ?? 0;
            $score = (float) ($candidate['score'] ?? 0);
            if ($direct_families >= 2 && $direct_count >= 2 && $score >= 0.62) { return 'trend_confirmed'; }
            if (($direct_count >= 1 && $adjacent_count >= 1 && $score >= 0.48) || ($direct_families >= 1 && $score >= 0.55)) { return 'trend_emerging'; }
        }
        return ((int) ($summary['usable_records'] ?? 0)) > 0 ? 'weak_signal_validation_needed' : 'no_actionable_trend_found';
    }

    private static function brief_readiness($mode) {
        if ($mode === 'trend_confirmed') { return 'ready_for_campaign_brief'; }
        if ($mode === 'trend_emerging') { return 'ready_for_test_brief'; }
        if ($mode === 'weak_signal_validation_needed' || $mode === 'source_failure_partial_report') { return 'validation_needed'; }
        return 'locked_no_insights';
    }

    public static function fuse($sources, $summary, $request = []) {
        $sources = is_array($sources) ? $sources : [];
        $summary = is_array($summary) ? $summary : [];
        $all = []; $noise = [];
        foreach ($sources as $source) {
            foreach ((array) ($source['trend_signals'] ?? []) as $sig) {
                $etype = sanitize_key((string) ($sig['evidence_type'] ?? 'noise'));
                if ($etype === 'noise') { $noise[] = $sig; }
                else { $all[] = $sig; }
            }
        }
        $groups = [];
        foreach ($all as $signal) {
            $groups[self::key_for_signal($signal)][] = $signal;
        }
        $candidates = [];
        foreach ($groups as $key => $signals) {
            $direct_families = []; $source_families = []; $direct = 0; $adjacent = 0; $ambient = 0; $format = 0; $pain = 0;
            foreach ($signals as $signal) {
                $family = sanitize_key((string) ($signal['source_family'] ?? 'unknown'));
                $source_families[$family] = true;
                $e = sanitize_key((string) ($signal['evidence_type'] ?? 'noise'));
                if ($e === 'direct') { $direct++; $direct_families[$family] = true; }
                elseif ($e === 'adjacent') { $adjacent++; }
                elseif ($e === 'ambient') { $ambient++; }
                elseif ($e === 'format_signal') { $format++; }
                elseif ($e === 'pain_point_signal') { $pain++; }
            }
            $score = self::score_candidate($signals);
            $first = $signals[0] ?? [];
            $candidates[] = [
                'candidate_key' => $key,
                'canonical_topic' => sanitize_text_field((string) ($first['canonical_topic'] ?? $key)),
                'score' => $score,
                'source_family_count' => count($source_families),
                'direct_source_families' => count($direct_families),
                'direct_signal_count' => $direct,
                'adjacent_signal_count' => $adjacent,
                'ambient_signal_count' => $ambient,
                'format_signal_count' => $format,
                'pain_point_signal_count' => $pain,
                'evidence' => array_slice($signals, 0, 8),
            ];
        }
        usort($candidates, static function($a, $b) { return ($b['score'] <=> $a['score']); });
        $mode = self::report_mode($candidates, $summary);
        $readiness = self::brief_readiness($mode);
        $direct_trends = [];
        $emerging = [];
        $adjacent = [];
        $ambient = [];
        foreach ($candidates as $candidate) {
            if (($candidate['direct_source_families'] ?? 0) >= 2 && ($candidate['score'] ?? 0) >= 0.62) { $direct_trends[] = $candidate; }
            elseif (($candidate['direct_signal_count'] ?? 0) >= 1 || ($candidate['adjacent_signal_count'] ?? 0) >= 1) { $emerging[] = $candidate; }
            else { $ambient[] = $candidate; }
        }
        foreach ($all as $sig) {
            if (($sig['evidence_type'] ?? '') === 'adjacent') { $adjacent[] = ['canonical_topic' => $sig['canonical_topic'] ?? '', 'score' => $sig['confidence'] ?? 0, 'evidence' => [$sig]]; }
            elseif (in_array(($sig['evidence_type'] ?? ''), ['ambient','format_signal','pain_point_signal'], true)) { $ambient[] = ['canonical_topic' => $sig['canonical_topic'] ?? '', 'score' => $sig['confidence'] ?? 0, 'evidence' => [$sig]]; }
        }
        $confidence = count($candidates) ? round(array_sum(array_column($candidates, 'score')) / max(1, count($candidates)), 3) : 0.0;
        return [
            'report_mode' => $mode,
            'confidence_score' => $confidence,
            'brief_readiness_status' => $readiness,
            'scoring_weights' => self::$weights,
            'trend_candidates' => array_slice($candidates, 0, 20),
            'direct_trends' => $mode === 'trend_confirmed' ? array_slice($direct_trends, 0, 10) : [],
            'emerging_trends' => in_array($mode, ['trend_confirmed','trend_emerging'], true) ? array_slice($emerging ?: $direct_trends, 0, 10) : [],
            'adjacent_opportunities' => array_slice($adjacent, 0, 10),
            'ambient_market_signals' => array_slice($ambient, 0, 10),
            'validation_needs' => self::validation_needs($mode, $summary),
            'noise_summary' => [
                'discarded_signals' => count($noise),
                'top_reasons' => self::noise_reasons($noise),
            ],
        ];
    }

    private static function validation_needs($mode, $summary) {
        if (in_array($mode, ['trend_confirmed','trend_emerging'], true)) { return []; }
        $needs = [];
        if ((int) ($summary['direct_matches'] ?? 0) < 1) { $needs[] = 'Add at least one topic-specific social conversation or search/news source.'; }
        if ((int) ($summary['usable_sources'] ?? 0) < 2) { $needs[] = 'Validate the topic across a second source family before campaign decisions.'; }
        if ((int) ($summary['ambient_signals'] ?? 0) > 0) { $needs[] = 'Use ambient trend lists only as context until semantic topic matches appear.'; }
        if (empty($needs)) { $needs[] = 'Run a narrower validation pass with specific terms and market filters.'; }
        return $needs;
    }

    private static function noise_reasons($noise) {
        $counts = [];
        foreach ((array) $noise as $sig) {
            $reason = sanitize_key((string) ($sig['discard_reason'] ?? 'semantic_mismatch'));
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts ?: ['semantic_mismatch' => 1]), 0, 5);
    }
}
