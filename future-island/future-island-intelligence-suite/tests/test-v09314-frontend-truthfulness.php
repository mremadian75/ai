<?php
/**
 * v0.9.31.6 — Frontend truthfulness regression checks.
 * - No generic TypeError → F5 message (3A)
 * - Métricas grid uses metricCell (3B)
 * - nfmt guards Number.isFinite (3H)
 * - credit isUnlimited finite-guarded (3H)
 * - Ads archive-id-only degraded note (3G)
 * Run: php tests/test-v09314-frontend-truthfulness.php
 */
$root = dirname(__DIR__);
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Version
$assert(strpos($main, 'Version: 1.4.1') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.4.1'") !== false, 'Version is 1.1.0');

// 3A: jsTypeError branch no longer emits F5 instruction
$assert(strpos($js, 'jsTypeError') !== false, '3A: jsTypeError branch still exists');
$assert(strpos($js, 'La página necesita refrescarse — presiona F5') === false, '3A: False F5 message removed from codebase');
$assert(strpos($js, 'No pudimos mostrar el resultado en pantalla') !== false, '3A: Render-safe message added for jsTypeError');

// 3A: Session-expired branch added
$assert(strpos($js, 'sesión caducada|session expired|nonce') !== false, '3A: Session-expired detection regex added');
$assert(strpos($js, 'La sesión ha expirado') !== false, '3A: Session-expired message present');

// 3A: fetch TypeError → network message (not F5)
$assert(strpos($js, 'No pudimos conectar con el servidor') !== false, '3A: Fetch TypeError maps to network error message');

// 3B: metricCell helper exists
$assert(strpos($js, 'const metricCell =') !== false, '3B: metricCell helper declared');

// 3B: Métricas grid no longer uses || 0 coercion for metric values
$assert(strpos($js, 'nfmt(metrics.views || 0)') === false, '3B: Métricas Views cell removed || 0 coercion');
$assert(strpos($js, 'nfmt(metrics.likes || 0)') === false, '3B: Métricas Likes cell removed || 0 coercion');
$assert(strpos($js, 'metricCell(metrics.views') !== false, '3B: Métricas Views uses metricCell');
$assert(strpos($js, 'metricCell(metrics.likes') !== false, '3B: Métricas Likes uses metricCell');

// 3B: buildAnalysisPayload passes hasPrimaryMetric and hasLikeMetric
$assert(strpos($js, 'hasPrimaryMetric:card.hasPrimaryMetric') !== false, '3B: buildAnalysisPayload includes hasPrimaryMetric');
$assert(strpos($js, 'hasLikeMetric:card.hasLikeMetric') !== false, '3B: buildAnalysisPayload includes hasLikeMetric');

// 3H: nfmt has Number.isFinite guard
$nfmt_pos = strpos($js, 'const nfmt =');
$nfmt_body = $nfmt_pos !== false ? substr($js, $nfmt_pos, 300) : '';
$assert(strpos($nfmt_body, 'Number.isFinite') !== false, '3H: nfmt has Number.isFinite guard (no Infinity display)');

// 3H: isUnlimited is finite-guarded
$assert(strpos($js, 'Number.isFinite(num) && num >= 100000000') !== false, '3H: isUnlimited uses Number.isFinite (not just !isNaN)');

// 3G: archive-id-only degraded note
$assert(strpos($js, 'isArchiveIdOnly') !== false, '3G: isArchiveIdOnly detection variable exists');
$assert(strpos($js, "startsWith('Archivar ID:')") !== false, '3G: archive-id-only detection uses Archivar ID: prefix');
$assert(strpos($js, 'Datos limitados: solo se recibió identificador') !== false, '3G: Archive-id-only degraded note text present');

// 3C: fallback detection in renderAnalysisPanel
$assert(strpos($js, "meta.analysis_source === 'local_fallback'") !== false, '3C: renderAnalysisPanel checks analysis_source for fallback detection');
$assert(strpos($js, 'fallbackDisclaimer') !== false, '3C: fallback disclaimer variable exists');

if ($fail === 0) {
    echo "OK v0.9.31.6 frontend truthfulness — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 frontend truthfulness — $fail failures, $pass passed\n";
    exit(1);
}
