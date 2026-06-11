<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Scorer — Phase 4 deterministic trend scoring + classification.
 *
 * No LLM, no external calls. Scores a series (from VES_Trend_Series_Builder) on
 * momentum / consistency / freshness / volatility / spike, derives a composite
 * trend_score + confidence_score, and classifies it as one of:
 *   emerging | sustained | peaking | fading | spike | noise | insufficient_data
 *
 * "Volatility" for scoring/classification is DETRENDED (residuals from the linear
 * fit, normalized by mean) so a clean rise is not mistaken for noise. All
 * thresholds are class constants (no scattered magic numbers).
 */
final class VES_Trend_Scorer {

    const MIN_POINTS        = 3;     // below this → insufficient_data
    const SPIKE_Z           = 2.0;   // max-bucket z-score to flag a spike
    const SPIKE_Z_MAX       = 3.0;   // z mapped to spike_score=100
    const SPIKE_CONSISTENCY_MAX = 50;// spike requires low consistency
    const EMERGE_GROWTH     = 0.10;  // min growth_rate for emerging
    const FADE_GROWTH       = 0.10;  // min |growth_rate| (negative) for fading
    const STABLE_GROWTH     = 0.15;  // |growth_rate| under this is "stable"
    const PEAK_RISE         = 0.25;  // peak must exceed baseline by this fraction
    const PEAK_DROP         = 0.12;  // last must fall this fraction below the peak
    const CLEAN_VOL_SCORE   = 55;    // emerging/peaking require volatility_score under this
    const SUSTAINED_VOL_SCORE = 60;  // sustained requires volatility_score under this
    const RECENCY_DAYS      = 30;    // freshness decay window
    const MIN_SCORE_FOR_INSIGHT      = 60; // DTF draft-insight gate (trend_score)
    const MIN_CONFIDENCE_FOR_INSIGHT = 60; // DTF draft-insight gate (confidence_score)

    /** Default thresholds (from constants). The single source of magic numbers. */
    private static function default_thresholds(): array {
        return [
            'min_points'                 => self::MIN_POINTS,
            'spike_z'                    => self::SPIKE_Z,
            'spike_z_max'                => self::SPIKE_Z_MAX,
            'spike_consistency_max'      => self::SPIKE_CONSISTENCY_MAX,
            'emerge_growth'              => self::EMERGE_GROWTH,
            'fade_growth'                => self::FADE_GROWTH,
            'stable_growth'              => self::STABLE_GROWTH,
            'peak_rise'                  => self::PEAK_RISE,
            'peak_drop'                  => self::PEAK_DROP,
            'noise_volatility'           => self::CLEAN_VOL_SCORE,     // emerging/peaking require vol under this
            'sustained_volatility'       => self::SUSTAINED_VOL_SCORE,
            'recency_days'               => self::RECENCY_DAYS,
            'min_score_for_insight'      => self::MIN_SCORE_FOR_INSIGHT,
            'min_confidence_for_insight' => self::MIN_CONFIDENCE_FOR_INSIGHT,
        ];
    }

    /**
     * Calibration config (Part D). Returns validated/clamped thresholds: defaults →
     * `ves_trend_scorer_thresholds` / `fidtf_trend_scorer_thresholds` filters →
     * per-call overrides. Invalid values are ignored or clamped, never fatal.
     */
    public static function get_thresholds(array $overrides = []): array {
        $th = self::default_thresholds();
        if (function_exists('apply_filters')) {
            foreach (['ves_trend_scorer_thresholds', 'fidtf_trend_scorer_thresholds'] as $hook) {
                $f = apply_filters($hook, $th);
                if (is_array($f)) { $th = self::validate_thresholds($th, $f); }
            }
        }
        if (!empty($overrides)) { $th = self::validate_thresholds($th, $overrides); }
        return $th;
    }

    /** Merge candidate overrides into base, validating + clamping each field. */
    private static function validate_thresholds(array $base, array $candidate): array {
        $bounds = [
            'min_points'                 => ['min' => 2,   'max' => 1000, 'int' => true],
            'spike_z'                    => ['min' => 0.0, 'max' => 10.0],
            'spike_z_max'                => ['min' => 0.1, 'max' => 20.0],
            'spike_consistency_max'      => ['min' => 0.0, 'max' => 100.0],
            'emerge_growth'              => ['min' => 0.0, 'max' => 100.0],
            'fade_growth'                => ['min' => 0.0, 'max' => 100.0],
            'stable_growth'              => ['min' => 0.0, 'max' => 100.0],
            'peak_rise'                  => ['min' => 0.0, 'max' => 100.0],
            'peak_drop'                  => ['min' => 0.0, 'max' => 1.0],
            'noise_volatility'           => ['min' => 0.0, 'max' => 100.0],
            'sustained_volatility'       => ['min' => 0.0, 'max' => 100.0],
            'recency_days'               => ['min' => 1,   'max' => 3650, 'int' => true],
            'min_score_for_insight'      => ['min' => 0.0, 'max' => 100.0],
            'min_confidence_for_insight' => ['min' => 0.0, 'max' => 100.0],
        ];
        foreach ($candidate as $k => $v) {
            if (!isset($bounds[$k]) || !is_numeric($v)) { continue; } // ignore unknown/non-numeric
            $val = (float) $v;
            if ($val < $bounds[$k]['min']) { $val = $bounds[$k]['min']; }
            if ($val > $bounds[$k]['max']) { $val = $bounds[$k]['max']; }
            $base[$k] = !empty($bounds[$k]['int']) ? (int) round($val) : $val;
        }
        return $base;
    }

    /** Composite scoring + classification for a series. */
    public static function score_series(array $series, array $overrides = []): array {
        $th = self::get_thresholds($overrides);
        $points  = is_array($series['points'] ?? null) ? $series['points'] : [];
        $summary = is_array($series['summary'] ?? null) ? $series['summary'] : (class_exists('VES_Trend_Series_Builder') ? VES_Trend_Series_Builder::summarize_series($points) : []);
        $values  = self::values($points);
        $n = count($values);

        $momentum    = self::calculate_momentum($series);
        $detrended   = self::detrended_volatility($values, (float) ($summary['slope'] ?? 0), (float) ($summary['mean'] ?? 0));
        $volatility  = self::clamp(min(1.0, $detrended) * 100);
        $consistency = self::clamp(100 - $volatility);
        $freshness   = self::freshness_score($points, (int) $th['recency_days']);
        $spike       = self::detect_spike($series, $overrides);
        $spike_score = (float) ($spike['spike_score'] ?? 0);

        $trend_score = self::clamp(0.40 * $momentum + 0.25 * $consistency + 0.20 * $freshness + 0.15 * (100 - $volatility));
        $confidence  = self::clamp(min(60.0, ($n / 6.0) * 60.0) + 0.4 * (100 - $volatility));
        if (($summary['total'] ?? 0) <= 0 || $n < (int) $th['min_points']) { $confidence = self::clamp($confidence * 0.3); }

        $classification = self::classify_from_features($summary, $values, $volatility, $consistency, $spike, $th);
        $reasons = self::reasons($classification, $summary, $volatility, $spike, $n);

        return [
            'trend_score'       => round($trend_score, 2),
            'momentum_score'    => round($momentum, 2),
            'consistency_score' => round($consistency, 2),
            'freshness_score'   => round($freshness, 2),
            'volatility_score'  => round($volatility, 2),
            'spike_score'       => round($spike_score, 2),
            'confidence_score'  => round($confidence, 2),
            'classification'    => $classification,
            'reasons'           => $reasons,
            'metadata'          => [
                'point_count'      => $n,
                'slope'            => (float) ($summary['slope'] ?? 0),
                'growth_rate'      => (float) ($summary['growth_rate'] ?? 0),
                'detrended_volatility' => round($detrended, 4),
                'spike_z'          => round((float) ($spike['z_score'] ?? 0), 3),
            ],
        ];
    }

    public static function classify_series(array $series, array $overrides = []): string {
        return (string) (self::score_series($series, $overrides)['classification'] ?? 'insufficient_data');
    }

    /** momentum from growth_rate (tanh-mapped to 0..100, 50 = flat). */
    public static function calculate_momentum(array $series): float {
        $summary = is_array($series['summary'] ?? null) ? $series['summary'] : [];
        $growth = (float) ($summary['growth_rate'] ?? 0);
        return self::clamp(50 + 50 * tanh($growth));
    }

    /** consistency = 100 - detrended-volatility-score (steadier → higher). */
    public static function calculate_consistency(array $series): float {
        $points  = is_array($series['points'] ?? null) ? $series['points'] : [];
        $summary = is_array($series['summary'] ?? null) ? $series['summary'] : [];
        $values  = self::values($points);
        $d = self::detrended_volatility($values, (float) ($summary['slope'] ?? 0), (float) ($summary['mean'] ?? 0));
        return self::clamp(100 - min(1.0, $d) * 100);
    }

    /** volatility score 0..100 from detrended residual dispersion. */
    public static function calculate_volatility(array $series): float {
        $points  = is_array($series['points'] ?? null) ? $series['points'] : [];
        $summary = is_array($series['summary'] ?? null) ? $series['summary'] : [];
        $values  = self::values($points);
        $d = self::detrended_volatility($values, (float) ($summary['slope'] ?? 0), (float) ($summary['mean'] ?? 0));
        return self::clamp(min(1.0, $d) * 100);
    }

    /** Spike detection from the max bucket's z-score (raw stddev). */
    public static function detect_spike(array $series, array $overrides = []): array {
        $th = self::get_thresholds($overrides);
        $points  = is_array($series['points'] ?? null) ? $series['points'] : [];
        $summary = is_array($series['summary'] ?? null) ? $series['summary'] : (class_exists('VES_Trend_Series_Builder') ? VES_Trend_Series_Builder::summarize_series($points) : []);
        $values  = self::values($points);
        $n = count($values);
        $out = ['is_spike' => false, 'spike_index' => -1, 'z_score' => 0.0, 'spike_score' => 0.0];
        if ($n < 2) { return $out; }
        $mean = (float) ($summary['mean'] ?? 0);
        $stddev = (float) ($summary['stddev'] ?? 0);
        if ($stddev <= 0) { return $out; }
        $max = max($values); $idx = array_search($max, $values, true);
        $z = ($max - $mean) / $stddev;
        $out['z_score'] = $z;
        $out['spike_index'] = (int) $idx;
        $out['spike_score'] = self::clamp(($z / (float) $th['spike_z_max']) * 100);
        $out['is_spike'] = ($z >= (float) $th['spike_z']);
        return $out;
    }

    // ── classification ──────────────────────────────────────────────────────────

    private static function classify_from_features(array $summary, array $values, float $volatility, float $consistency, array $spike, array $th): string {
        $n = count($values);
        $total = (float) ($summary['total'] ?? 0);
        if ($n < (int) $th['min_points'] || $total <= 0) { return 'insufficient_data'; }

        $slope  = (float) ($summary['slope'] ?? 0);
        $growth = (float) ($summary['growth_rate'] ?? 0);
        $last   = (float) ($summary['last'] ?? 0);
        $max    = (float) ($summary['max'] ?? 0);

        $half = (int) floor($n / 2);
        $baseline = $half > 0 ? array_sum(array_slice($values, 0, $half)) / $half : ($values[0] ?? 0);
        $recent   = ($n - $half) > 0 ? array_sum(array_slice($values, $half)) / ($n - $half) : $last;
        $max_index = (int) array_search($max, $values, true);

        // 1) Spike — one/two buckets far above baseline with low consistency.
        if (!empty($spike['is_spike']) && $consistency < (float) $th['spike_consistency_max']) { return 'spike'; }

        // 2) Fading — clear decline.
        if ($growth <= -(float) $th['fade_growth'] && $slope < 0 && $last < $baseline) { return 'fading'; }

        // 3) Peaking — a MEANINGFUL rise then flatten/decline near the end (clean).
        $peak_above_baseline = ($baseline > 0) ? ($max >= $baseline * (1 + (float) $th['peak_rise'])) : ($max > 0);
        $declined_from_peak  = ($max > 0) ? ($last <= $max * (1 - (float) $th['peak_drop'])) : false;
        if ($volatility < (float) $th['noise_volatility'] && $max_index >= $half && $declined_from_peak
            && $recent >= $baseline && $peak_above_baseline) {
            return 'peaking';
        }

        // 4) Emerging — clean upward trend.
        if ($volatility < (float) $th['noise_volatility'] && $growth >= (float) $th['emerge_growth'] && $slope > 0 && $recent > $baseline) {
            return 'emerging';
        }

        // 5) Sustained — steady, low/moderate volatility, no strong direction.
        if ($volatility < (float) $th['sustained_volatility'] && abs($growth) < (float) $th['stable_growth']) { return 'sustained'; }

        // 6) Noise — anything else (high volatility / no clear pattern).
        return 'noise';
    }

    private static function reasons(string $classification, array $summary, float $volatility, array $spike, int $n): array {
        $r = ['class=' . $classification, 'points=' . $n];
        $r[] = 'growth=' . round((float) ($summary['growth_rate'] ?? 0), 3);
        $r[] = 'slope=' . round((float) ($summary['slope'] ?? 0), 3);
        $r[] = 'volatility_score=' . round($volatility, 1);
        if (!empty($spike['is_spike'])) { $r[] = 'spike_z=' . round((float) $spike['z_score'], 2); }
        return $r;
    }

    // ── math helpers ──────────────────────────────────────────────────────────

    private static function values(array $points): array {
        $v = [];
        foreach ($points as $p) { if (is_array($p) && isset($p['value'])) { $v[] = (float) $p['value']; } }
        return $v;
    }

    /** Residual stddev from the linear fit, normalized by mean (0 if degenerate). */
    private static function detrended_volatility(array $values, float $slope, float $mean): float {
        $n = count($values);
        if ($n < 2 || $mean <= 0) { return 0.0; }
        $mean_x = ($n - 1) / 2.0;
        $intercept = $mean - $slope * $mean_x;
        $ss = 0.0;
        foreach ($values as $i => $v) { $pred = $intercept + $slope * $i; $ss += ($v - $pred) * ($v - $pred); }
        $resid_std = sqrt($ss / $n);
        return $resid_std / $mean;
    }

    private static function freshness_score(array $points, int $recency_days = self::RECENCY_DAYS): float {
        $last_date = '';
        foreach ($points as $p) { if (is_array($p) && !empty($p['date'])) { $last_date = (string) $p['date']; } }
        if ($last_date === '') { return 50.0; }
        $ts = strtotime($last_date . ' 00:00:00 UTC');
        if ($ts === false) { return 50.0; }
        $recency_days = max(1, $recency_days);
        $age_days = max(0, (time() - $ts) / 86400);
        return self::clamp(100 - ($age_days / $recency_days) * 100);
    }

    private static function clamp($v): float {
        $v = (float) $v;
        if ($v < 0) { return 0.0; }
        if ($v > 100) { return 100.0; }
        return $v;
    }
}
