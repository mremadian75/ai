<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Series_Builder — Phase 4 deterministic series aggregation.
 *
 * Turns trend observations into a time-bucketed series (day/week/month) plus a
 * summary of simple statistics (mean/median/min/max/slope/growth/volatility).
 * Pure PHP, no LLM, no external calls; handles empty/sparse data gracefully.
 */
final class VES_Trend_Series_Builder {

    const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * Build a series from stored observations (reads VES_Trend_Observation_Store).
     * @param array $filters workspace_id, normalized_term, observation_type, platform,
     *                       provider, from, to, granularity, min_points
     */
    public static function build_series(array $filters): array {
        $granularity = in_array(($filters['granularity'] ?? 'day'), self::GRANULARITIES, true) ? $filters['granularity'] : 'day';
        $observations = class_exists('VES_Trend_Observation_Store')
            ? VES_Trend_Observation_Store::list_observations($filters) : [];
        $points = self::bucket_observations($observations, $granularity);
        return [
            'term'             => (string) ($filters['normalized_term'] ?? ''),
            'observation_type' => (string) ($filters['observation_type'] ?? ''),
            'platform'         => (string) ($filters['platform'] ?? ''),
            'provider'         => (string) ($filters['provider'] ?? ''),
            'granularity'      => $granularity,
            'points'           => $points,
            'summary'          => self::summarize_series($points),
        ];
    }

    /**
     * Bucket observations by a calendar granularity, summing value_number per bucket.
     * Returns a date-ascending list of ['date' => 'Y-m-d', 'value' => float].
     */
    public static function bucket_observations(array $observations, string $granularity = 'day'): array {
        if (!in_array($granularity, self::GRANULARITIES, true)) { $granularity = 'day'; }
        $buckets = [];
        foreach ($observations as $o) {
            $o = is_array($o) ? $o : [];
            $when = (string) ($o['observed_at'] ?? '');
            $ts = $when !== '' ? strtotime($when . ' UTC') : false;
            if ($ts === false) { continue; }
            $key = self::bucket_key($ts, $granularity);
            $val = (float) ($o['value_number'] ?? $o['normalized_value'] ?? 0);
            if (!isset($buckets[$key])) { $buckets[$key] = 0.0; }
            $buckets[$key] += $val;
        }
        ksort($buckets);
        $points = [];
        foreach ($buckets as $date => $value) { $points[] = ['date' => $date, 'value' => round($value, 4)]; }
        return $points;
    }

    private static function bucket_key(int $ts, string $granularity): string {
        if ($granularity === 'month') { return gmdate('Y-m-01', $ts); }
        if ($granularity === 'week') {
            // ISO week → Monday of that week.
            $dow = (int) gmdate('N', $ts); // 1..7
            return gmdate('Y-m-d', $ts - ($dow - 1) * 86400);
        }
        return gmdate('Y-m-d', $ts);
    }

    /**
     * Summary statistics for a list of points. Division-by-zero safe.
     * slope = least-squares slope over bucket index; growth_rate vs first non-zero
     * baseline; volatility = coefficient of variation (stddev/mean).
     */
    public static function summarize_series(array $points): array {
        $values = [];
        foreach ($points as $p) { if (is_array($p) && isset($p['value'])) { $values[] = (float) $p['value']; } }
        $n = count($values);
        $base = [
            'point_count' => $n, 'total' => 0.0, 'mean' => 0.0, 'median' => 0.0,
            'min' => 0.0, 'max' => 0.0, 'first' => 0.0, 'last' => 0.0,
            'slope' => 0.0, 'growth_rate' => 0.0, 'volatility' => 0.0, 'stddev' => 0.0,
        ];
        if ($n === 0) { return $base; }

        $total = array_sum($values);
        $mean = $total / $n;
        $sorted = $values; sort($sorted);
        $mid = (int) floor(($n - 1) / 2);
        $median = ($n % 2 === 1) ? $sorted[$mid] : (($sorted[$mid] + $sorted[$mid + 1]) / 2);
        $first = $values[0];
        $last = $values[$n - 1];

        // Least-squares slope over x = 0..n-1.
        $slope = 0.0;
        if ($n >= 2) {
            $mean_x = ($n - 1) / 2.0;
            $num = 0.0; $den = 0.0;
            foreach ($values as $i => $v) { $num += ($i - $mean_x) * ($v - $mean); $den += ($i - $mean_x) * ($i - $mean_x); }
            $slope = $den > 0 ? $num / $den : 0.0;
        }

        // Variance / stddev / volatility (coefficient of variation).
        $var = 0.0;
        foreach ($values as $v) { $var += ($v - $mean) * ($v - $mean); }
        $var = $n > 0 ? $var / $n : 0.0;
        $stddev = sqrt($var);
        $volatility = $mean > 0 ? $stddev / $mean : 0.0;

        // Growth rate vs first non-zero baseline (fallback to mean).
        $baseline = 0.0;
        foreach ($values as $v) { if ($v != 0.0) { $baseline = $v; break; } }
        if ($baseline == 0.0) { $baseline = $mean; }
        $growth_rate = $baseline != 0.0 ? ($last - $baseline) / abs($baseline) : 0.0;

        return [
            'point_count' => $n,
            'total'       => round($total, 4),
            'mean'        => round($mean, 4),
            'median'      => round($median, 4),
            'min'         => round(min($values), 4),
            'max'         => round(max($values), 4),
            'first'       => round($first, 4),
            'last'        => round($last, 4),
            'slope'       => round($slope, 4),
            'growth_rate' => round($growth_rate, 4),
            'volatility'  => round($volatility, 4),
            'stddev'      => round($stddev, 4),
        ];
    }
}
