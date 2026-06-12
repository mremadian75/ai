<?php
/**
 * v0.9.31.6 — BUG-05: OpenAI client fallback label audit.
 * Asserts the previously-missed Google intelligence local-fallback path now
 * carries analysis_source + model_label, and the existing labeled paths from
 * v0.9.31.4 still carry them.
 * Run: php tests/test-v09315-fallback-label-audit.php
 */
$root = dirname(__DIR__);
$ai   = file_get_contents($root . '/includes/class-ves-openai-client.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.8') !== false, 'Version is 1.1.0');

// Count of labels — must have grown from v0.9.31.4 (which had 4 labeled).
$source_count = substr_count($ai, "'analysis_source' => 'local_fallback'");
$label_count  = substr_count($ai, "'model_label' => 'Análisis local de respaldo'");
$assert($source_count >= 5, "analysis_source occurrences >= 5 (found $source_count)");
$assert($label_count  >= 5, "model_label occurrences >= 5 (found $label_count)");

// Audit comment marker is present. The marker uses the introduction version
// (v0.9.31.5) and is not rebranded in later patch releases.
$assert(strpos($ai, 'v0.9.31.5: fallback label audit') !== false, 'audit comment marker present');

// The specific path: analyze_google_intelligence fallback now labels.
// (Use the call site `self::fallback_google_intelligence_analysis(` so we
// land at line 1797 area, not the function definition at line 1425.)
$g_pos = strpos($ai, 'self::fallback_google_intelligence_analysis(');
$assert($g_pos !== false, 'analyze_google_intelligence still calls fallback_google_intelligence_analysis');
$g_window = $g_pos !== false ? substr($ai, $g_pos, 800) : '';
$assert(strpos($g_window, "'analysis_source' => 'local_fallback'") !== false, 'Google intelligence fallback path carries analysis_source');
$assert(strpos($g_window, "'model_label' => 'Análisis local de respaldo'") !== false, 'Google intelligence fallback path carries model_label');

// Regression guard: existing v0.9.31.4 fallback labels (analyze_item / analyze_items / build_local_trend_analysis_fallback) still present.
$assert(strpos($ai, 'build_local_trend_analysis_fallback') !== false, 'trend local fallback helper still present');
$assert(strpos($ai, "'analysis_source' => 'local_fallback'") !== false, 'analysis_source label still present in client');

if ($fail === 0) {
    echo "OK v0.9.31.6 fallback label audit — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 fallback label audit — $fail failures, $pass passed\n";
    exit(1);
}
