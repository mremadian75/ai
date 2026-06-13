<?php
/**
 * v0.9.30.22 — Credits pill must show ∞ for admin users even when billing throws,
 * and the JS NaN path must explicitly call updateCreditPill(null) so the pill is
 * styled rather than silently ignored.
 */

$root = dirname(__DIR__);
$php  = file_get_contents($root . '/includes/class-ves-billing.php');
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// 1. render_account_summary fallback uses current_user_can('manage_options') for balance display
$catch_pos = strpos($php, 'Throwable $summary_error');
$catch_window = ($catch_pos !== false) ? substr($php, $catch_pos, 600) : '';
$add('render_account_summary fallback checks manage_options', strpos($catch_window, "current_user_can('manage_options')") !== false);

// 2. The fallback balance variable is used in the return HTML (not a hardcoded —)
$add('render_account_summary fallback uses $fallback_balance variable', strpos($catch_window, 'fallback_balance') !== false);

// 3. __vesPatchUsageRefresh calls updateCreditPill(widget, null) on NaN path.
// [v1.1.0] The function grew (v0.9.31.1 added a window.VES_CREDIT preference path), so the
// NaN/null fallback call now sits ~2082 chars into the body. Widened the scan window from 1800
// to 3200 so the assertion still scopes to this function without a brittle byte offset. The
// product behavior is unchanged; ves-frontend.js was NOT edited.
$patch_pos = strpos($js, '__vesPatchUsageRefresh');
$patch_window = ($patch_pos !== false) ? substr($js, $patch_pos, 3200) : '';
$add('__vesPatchUsageRefresh calls updateCreditPill(widget, null) on NaN', strpos($patch_window, 'updateCreditPill(widget, null)') !== false);

// 4. ajaxStart guards form?.dataset?.ajax before calling postAjax
$ajax_start_pos = strpos($js, 'const ajaxStart = async');
$ajax_start_window = ($ajax_start_pos !== false) ? substr($js, $ajax_start_pos, 400) : '';
$add('ajaxStart guards form?.dataset?.ajax', strpos($ajax_start_window, 'form?.dataset?.ajax') !== false);

// 5. safeUserMessage has jsTypeError branch — v0.9.31.6: message is render-safe (not false F5)
$add('safeUserMessage has jsTypeError branch', strpos($js, 'jsTypeError') !== false);
$add('safeUserMessage returns render-safe message for TypeError (not false F5)', strpos($js, 'No pudimos mostrar el resultado en pantalla') !== false);

$bad = array_column(array_filter($checks, fn($c) => !$c[1]), 0);
if (!empty($bad)) {
    fwrite(STDERR, "\nv0.9.30.22 credits-display-fix check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.30.22 credits-display-fix checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
