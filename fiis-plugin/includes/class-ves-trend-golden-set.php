<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Golden_Set — Phase 4B deterministic validation of the trend classifier.
 *
 * A fixed set of labeled example series; `evaluate()` runs each through
 * VES_Trend_Scorer and checks the classification matches the expected label. No
 * LLM, no DB, no external calls — usable from tests, admin diagnostics, and CLI.
 */
final class VES_Trend_Golden_Set {

    /** Canonical labeled examples covering all 7 classifications. */
    public static function default_examples(): array {
        return [
            ['name' => 'clean emerging',     'expected_classification' => 'emerging',          'values' => [2, 3, 4, 6, 9, 13]],
            ['name' => 'steady sustained',   'expected_classification' => 'sustained',         'values' => [20, 21, 20, 21, 20, 21]],
            ['name' => 'rise then peak',     'expected_classification' => 'peaking',           'values' => [2, 5, 9, 12, 11, 8]],
            ['name' => 'clear fade',         'expected_classification' => 'fading',            'values' => [12, 10, 8, 5, 3, 1]],
            ['name' => 'single spike',       'expected_classification' => 'spike',             'values' => [1, 1, 1, 12, 1, 1]],
            ['name' => 'jagged noise',       'expected_classification' => 'noise',             'values' => [3, 9, 2, 11, 1, 8]],
            ['name' => 'too few points',     'expected_classification' => 'insufficient_data', 'values' => [5]],
        ];
    }

    /**
     * Evaluate a set of examples (defaults if none given).
     * @return array{total:int,passed:int,failed:int,pass_rate:float,failures:array}
     */
    public static function evaluate(array $examples = []): array {
        if (empty($examples)) { $examples = self::default_examples(); }
        $total = 0; $passed = 0; $failures = [];
        foreach ($examples as $ex) {
            if (!is_array($ex)) { continue; }
            $total++;
            $res = self::evaluate_example($ex);
            if (!empty($res['passed'])) { $passed++; }
            else {
                $failures[] = [
                    'name'     => (string) ($res['name'] ?? ''),
                    'expected' => (string) ($res['expected'] ?? ''),
                    'actual'   => (string) ($res['actual'] ?? ''),
                    'scores'   => is_array($res['scores'] ?? null) ? $res['scores'] : [],
                ];
            }
        }
        return [
            'total'     => $total,
            'passed'    => $passed,
            'failed'    => $total - $passed,
            'pass_rate' => $total > 0 ? round($passed / $total * 100, 1) : 0.0,
            'failures'  => $failures,
        ];
    }

    /**
     * Evaluate a single example. Accepts either 'points' ([{date,value}]) or a
     * 'values' shorthand (daily values ending today). @return array
     */
    public static function evaluate_example(array $example): array {
        $expected = (string) ($example['expected_classification'] ?? '');
        $points = self::points_for($example);
        $series = [
            'points'  => $points,
            'summary' => class_exists('VES_Trend_Series_Builder') ? VES_Trend_Series_Builder::summarize_series($points) : [],
        ];
        $overrides = is_array($example['thresholds'] ?? null) ? $example['thresholds'] : [];
        $score = class_exists('VES_Trend_Scorer') ? VES_Trend_Scorer::score_series($series, $overrides) : ['classification' => 'insufficient_data'];
        $actual = (string) ($score['classification'] ?? '');
        return [
            'name'     => (string) ($example['name'] ?? ''),
            'expected' => $expected,
            'actual'   => $actual,
            'passed'   => ($actual === $expected),
            'scores'   => [
                'trend_score'      => $score['trend_score'] ?? null,
                'confidence_score' => $score['confidence_score'] ?? null,
                'volatility_score' => $score['volatility_score'] ?? null,
                'spike_score'      => $score['spike_score'] ?? null,
            ],
        ];
    }

    /** Build points from explicit 'points' or a 'values' shorthand (recent daily dates). */
    private static function points_for(array $example): array {
        if (!empty($example['points']) && is_array($example['points'])) {
            $out = [];
            foreach ($example['points'] as $p) {
                if (is_array($p) && isset($p['value'])) { $out[] = ['date' => (string) ($p['date'] ?? ''), 'value' => (float) $p['value']]; }
            }
            return $out;
        }
        $vals = is_array($example['values'] ?? null) ? array_values($example['values']) : [];
        $n = count($vals);
        $start = time() - ($n - 1) * 86400;
        $out = [];
        foreach ($vals as $i => $v) { $out[] = ['date' => gmdate('Y-m-d', $start + $i * 86400), 'value' => (float) $v]; }
        return $out;
    }
}
