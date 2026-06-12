<?php
/**
 * v0.9.30.21 — Trend Finder start gate must be released in a try/finally block
 * so that an exception escaping start_queued_sources cannot leave the gate locked.
 * Also checks stale dispatch slot cleanup at trends_start() entry.
 */

$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// 1. cleanup_stale_dispatch_slots called in trends_start context
$trends_start_pos = strpos($ajax, 'public static function trends_start');
if ($trends_start_pos === false) {
    $trends_start_pos = strpos($ajax, 'function trends_start');
}
if ($trends_start_pos === false) {
    $trends_start_pos = strpos($ajax, "'ves_trends_start'");
}
// Search for cleanup_stale_dispatch_slots within a reasonable window after the trends start marker
$window = ($trends_start_pos !== false) ? substr($ajax, $trends_start_pos, 6000) : '';
$add('cleanup_stale_dispatch_slots called near trends_start', strpos($window, 'cleanup_stale_dispatch_slots') !== false);

// 2. try/finally pattern wraps start_queued_sources in trends_start
// Search for the actual dispatch call (with :: to skip comment references)
$try_pos     = strpos($window, 'try {');
$finally_pos = strpos($window, '} finally {');
$start_qs    = strpos($window, '::start_queued_sources('); // actual dispatch, not comment
$add('start_queued_sources dispatch is inside a try block', $try_pos !== false && $start_qs !== false && $try_pos < $start_qs);
$add('finally block present after start_queued_sources dispatch', $finally_pos !== false && $start_qs !== false && $finally_pos > $start_qs);

// 3. release_start_gate called inside the finally block
$finally_block = ($finally_pos !== false) ? substr($window, $finally_pos, 300) : '';
$add('release_start_gate called in finally block', strpos($finally_block, 'release_start_gate') !== false);

// 4. cleanup_stale_dispatch_slots exists in apify client
$apify = file_get_contents($root . '/includes/class-ves-apify-client.php');
$add('cleanup_stale_dispatch_slots defined in apify client', strpos($apify, 'cleanup_stale_dispatch_slots') !== false);

$bad = array_column(array_filter($checks, fn($c) => !$c[1]), 0);
if (!empty($bad)) {
    fwrite(STDERR, "\nv0.9.30.21 trend-start-gate-release check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.30.21 trend-start-gate-release checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
