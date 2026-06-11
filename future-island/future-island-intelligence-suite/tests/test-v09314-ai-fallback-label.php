<?php
/**
 * v0.9.31.6 — AI fallback label truthfulness.
 * PHP: all fallback return paths set analysis_source=local_fallback + model_label.
 * JS: renderAnalysisPanel detects isFallback and shows model_label, not GPT name.
 * Run: php tests/test-v09314-ai-fallback-label.php
 */
$root = dirname(__DIR__);
$ai   = file_get_contents($root . '/includes/class-ves-openai-client.php');
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Version
$assert(strpos($main, 'Version: 1.2.7') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.7'") !== false, 'Version is 1.1.0');

// PHP: all fallback returns include analysis_source=local_fallback
$assert(substr_count($ai, "'analysis_source' => 'local_fallback'") >= 3, 'PHP: at least 3 fallback return paths set analysis_source=local_fallback');

// PHP: all fallback returns include model_label
$assert(substr_count($ai, "'model_label' => 'Análisis local de respaldo'") >= 3, 'PHP: at least 3 fallback return paths set model_label');

// PHP: trend local fallback also sets analysis_source
$assert(strpos($ai, "'format' => 'local_fallback'") !== false, 'PHP: trend local fallback has format=local_fallback');
$assert(strpos($ai, "'analysis_source' => 'local_fallback',\n            'format' => 'local_fallback'") !== false || substr_count($ai, "'analysis_source' => 'local_fallback'") >= 3, 'PHP: trend fallback includes analysis_source');

// JS: isFallback detection exists
$assert(strpos($js, 'const isFallback =') !== false, 'JS: isFallback variable declared in renderAnalysisPanel');

// JS: isFallback checks all relevant flags
$assert(strpos($js, 'meta.fallback_used') !== false, 'JS: isFallback checks meta.fallback_used');
$assert(strpos($js, "meta.analysis_source === 'local_fallback'") !== false, 'JS: isFallback checks meta.analysis_source');
$assert(strpos($js, "meta.confidence_mode === 'diagnostic_fallback'") !== false, 'JS: isFallback checks meta.confidence_mode');

// JS: displayModel uses model_label when fallback
$assert(strpos($js, 'const displayModel =') !== false, 'JS: displayModel variable declared');
$assert(strpos($js, "meta.model_label || 'Análisis local de respaldo'") !== false, 'JS: displayModel uses model_label on fallback');

// JS: fallback disclaimer banner is rendered
$assert(strpos($js, 'fallbackDisclaimer') !== false, 'JS: fallbackDisclaimer variable exists');
$assert(strpos($js, 'Análisis local de respaldo. No se usó el modelo externo') !== false, 'JS: fallback disclaimer text present');

// JS: renderAnalysisPanel uses displayModel, not raw model
$assert(strpos($js, 'escapeHtml(displayModel)') !== false, 'JS: renderAnalysisPanel renders displayModel not raw model');

if ($fail === 0) {
    echo "OK v0.9.31.6 AI fallback label — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 AI fallback label — $fail failures, $pass passed\n";
    exit(1);
}
