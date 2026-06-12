<?php
/**
 * Frontend hotfix — a completed run must not show "Ejecución no iniciada".
 *
 * The social/standard run flow could complete and render results, then a
 * secondary step (e.g. a render TypeError) would bubble to the start catch and
 * overwrite the completed status with a misleading "Ejecución no iniciada /
 * No pudimos mostrar el resultado en pantalla" error banner. The fix only shows
 * that banner when NO results rendered.
 *
 * There is no JS test runner in this project, so this guards the shipped bundle
 * two ways: (1) it still parses (node --check, skipped if node is absent), and
 * (2) the start-failed catch contains the results-present guard and the error
 * setStatus is conditional on it.
 *
 * Run: php tests/test-ves-frontend-result-error-guard.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$pass = 0; $fail = 0; $skip = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$bundle = dirname(__DIR__) . '/assets/js/ves-frontend.js';
$ok(is_readable($bundle), '0: frontend bundle is present');
$src = (string) file_get_contents($bundle);

// ── 1. Bundle still parses as valid JS (syntax safety net) ───────────────────
$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $out = []; $code = 0;
    @exec('node --check ' . escapeshellarg($bundle) . ' 2>&1', $out, $code);
    $ok($code === 0, '1: node --check parses the bundle without syntax errors');
} else {
    $skip++;
    echo "  SKIP  1: node not available — cannot run node --check\n";
}

// ── 2. The "start failed" catch carries the results-present guard ────────────
$catch_pos = strpos($src, "VES_WARN('start failed', err);");
$ok($catch_pos !== false, '2: start-failed catch exists');
$region = $catch_pos !== false ? substr($src, $catch_pos, 1400) : '';
$ok(strpos($region, '__vesResultsShown') !== false, '2: results-present guard variable is present in the catch');
$ok(strpos($region, "querySelector('.ves-results')") !== false, '2: guard inspects the .ves-results container');

// ── 3. The misleading banner is now CONDITIONAL (not unconditional) ──────────
$ok(strpos($region, "if (!__vesResultsShown) {") !== false, '3: the error banner is gated behind !__vesResultsShown');
// The "Ejecución no iniciada" setStatus must appear AFTER the guard condition.
$guard_at = strpos($region, 'if (!__vesResultsShown)');
$banner_at = strpos($region, "vesErrorStatusHtml('Ejecución no iniciada'");
$ok($guard_at !== false && $banner_at !== false && $banner_at > $guard_at, '3: "Ejecución no iniciada" banner is inside the guarded branch');

echo "\nFrontend result-error guard: {$pass} passed, {$fail} failed, {$skip} skipped\n";
if ($fail > 0) { exit(1); }
