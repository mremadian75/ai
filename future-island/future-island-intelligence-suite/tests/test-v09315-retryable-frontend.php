<?php
/**
 * v0.9.31.6 — BUG-01: frontend consumes safe_diagnostic.retryable.
 * Asserts JS gained Reintentar button + delegated click handler and that the
 * PHP retryable taxonomy is intact.
 * Run: php tests/test-v09315-retryable-frontend.php
 */
$root = dirname(__DIR__);
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.4.1') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.4.1'") !== false, 'Version is 1.1.0');

// JS reads safeDiagnostic.retryable
$assert(strpos($js, 'sd.retryable === true') !== false, 'JS branches on safeDiagnostic.retryable === true');
$assert(strpos($js, 'sd.retryable === false') !== false, 'JS branches on safeDiagnostic.retryable === false');

// Manual Reintentar button is emitted
$assert(strpos($js, 'ves-retry-run') !== false, 'JS emits ves-retry-run class for the manual retry button');
$assert(strpos($js, '>Reintentar<') !== false, 'JS renders Reintentar button label');

// Non-retryable footnote
$assert(strpos($js, 'Se requiere acci') !== false, 'JS renders admin-action note for non-retryable failures');

// Delegated click handler exists
$assert(strpos($js, "e.target.closest('.ves-retry-run')") !== false, 'JS delegates clicks on .ves-retry-run');
$assert(strpos($js, "e.target.closest('.ves-cancel-retry')") !== false, 'JS delegates clicks on .ves-cancel-retry (DEBT-04)');

// Auto-retry budget
$assert(strpos($js, 'VES_AUTO_RETRY_MAX') !== false, 'DEBT-04: auto-retry budget constant declared');
$assert(strpos($js, 'vesScheduleAutoRetry') !== false, 'DEBT-04: vesScheduleAutoRetry() exists');
$assert(strpos($js, 'vesResetAutoRetryCount') !== false, 'DEBT-04: vesResetAutoRetryCount() exists');

// PHP retryable taxonomy still emits the flag (regression guard)
$assert(strpos($ajax, 'private static function classify_retryable(') !== false, 'PHP classify_retryable() helper still present');
$assert(strpos($ajax, "'retryable' => self::classify_retryable(") !== false, 'build_safe_diagnostic() still emits retryable key');

if ($fail === 0) {
    echo "OK v0.9.31.6 retryable frontend — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 retryable frontend — $fail failures, $pass passed\n";
    exit(1);
}
