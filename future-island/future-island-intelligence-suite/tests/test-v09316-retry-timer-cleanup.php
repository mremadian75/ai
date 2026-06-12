<?php
/**
 * v0.9.31.6 — Retry timer cleanup helper.
 *   BUG-D: vesClearAutoRetryTimer must clear BOTH the setTimeout AND the
 *   setInterval used by the auto-retry countdown, regardless of which one
 *   is currently active.
 *   BUG-E: vesErrorStatusHtml helper must exist and must be used in both the
 *   polling error path and the start/dispatch catch.
 * Run: php tests/test-v09316-retry-timer-cleanup.php
 */
$root = dirname(__DIR__);
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.9') !== false, 'Version is 1.1.0');

// Find the body of vesClearAutoRetryTimer.
$start = strpos($js, 'const vesClearAutoRetryTimer = ');
$assert($start !== false, 'vesClearAutoRetryTimer is declared');
if ($start === false) {
    echo "FAIL v0.9.31.6 retry timer cleanup — helper missing\n";
    exit(1);
}
$body = substr($js, $start, 1200);

// Must clear both timeout and interval.
$assert(strpos($body, 'clearTimeout(widget._vesAutoRetryTimer)') !== false, 'helper calls clearTimeout on _vesAutoRetryTimer');
$assert(strpos($body, 'clearInterval(widget._vesAutoRetryTick)') !== false, 'helper calls clearInterval on _vesAutoRetryTick');
$assert(strpos($body, "widget._vesAutoRetryTimer = null") !== false, 'helper nullifies _vesAutoRetryTimer');
$assert(strpos($body, "widget._vesAutoRetryTick = null") !== false, 'helper nullifies _vesAutoRetryTick');
$assert(strpos($body, "widget._vesAutoRetryDeadline = null") !== false, 'helper nullifies _vesAutoRetryDeadline');

// Must NOT bail out before clearing when _vesAutoRetryTimer happens to be null
// (BUG-D regression: previous version had `if (!widget || !widget._vesAutoRetryTimer) return;`).
$assert(strpos($body, '!widget._vesAutoRetryTimer) return;') === false, 'helper does NOT early-return when only the timer is null');

// Cancel-retry handler no longer duplicates the interval cleanup inline.
$cancel_pos = strpos($js, "e.target.closest('.ves-cancel-retry')");
$assert($cancel_pos !== false, 'cancel-retry click handler exists');
$cancel_window = substr($js, $cancel_pos, 600);
$assert(strpos($cancel_window, 'vesClearAutoRetryTimer(widget)') !== false, 'cancel-retry handler delegates cleanup to vesClearAutoRetryTimer');
$assert(substr_count($cancel_window, 'clearInterval(widget._vesAutoRetryTick)') === 0, 'cancel-retry handler no longer manually calls clearInterval (delegated)');

// BUG-E: shared error-status helper exists and is used at both surfaces.
$assert(strpos($js, 'const vesErrorStatusHtml = ') !== false, 'vesErrorStatusHtml helper declared');
$assert(strpos($js, 'vesErrorStatusHtml(label') !== false, 'finishPollingAfterError uses vesErrorStatusHtml');
$assert(strpos($js, "vesErrorStatusHtml('Ejecución no iniciada'") !== false, 'start/dispatch catch uses vesErrorStatusHtml (BUG-E coverage)');

if ($fail === 0) {
    echo "OK v0.9.31.6 retry timer cleanup — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 retry timer cleanup — $fail failures, $pass passed\n";
    exit(1);
}
