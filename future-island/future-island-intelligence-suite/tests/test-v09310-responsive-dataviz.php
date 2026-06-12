<?php
/**
 * Test: v0.9.31.0 — Responsive layout + data-viz improvements
 */
$root = dirname(__DIR__);
$css  = file_get_contents($root . '/assets/css/ves-frontend.css');
$ms   = file_get_contents($root . '/assets/js/ves-market-signal.js');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// Version
$add('version is 1.4.0', strpos(file_get_contents($root . '/future-island-intelligence-suite.php'), '1.4.0') !== false);

// Responsive SEO table: stacked card view
$add('SEO table thead hidden on mobile in ves-frontend.css', strpos($css, '.ves-seo-table thead') !== false && strpos($css, 'display: none') !== false);
$add('SEO table tbody stacked on mobile in ves-frontend.css', strpos($css, '.ves-seo-table tbody tr') !== false && strpos($css, 'display: grid') !== false);
$add('SEO table column labels via nth-child in ves-frontend.css', strpos($css, "nth-child(3)::before") !== false && strpos($css, "content: 'Cluster'") !== false);

// Responsive breakpoints
$add('820px SEO table breakpoint in ves-frontend.css', strpos($css, 'max-width: 820px') !== false || strpos($css, 'max-width:820px') !== false);
$add('760px KPI grid breakpoint in ves-frontend.css', strpos($css, 'max-width: 760px') !== false || strpos($css, 'max-width:760px') !== false);
$add('460px KPI single-col breakpoint in ves-frontend.css', strpos($css, 'max-width: 460px') !== false || strpos($css, 'max-width:460px') !== false);
$add('600px modal full-width breakpoint in ves-frontend.css', strpos($css, 'max-width: 600px') !== false || strpos($css, 'max-width:600px') !== false);

// Progress bar utility
$add('ves-progress-bar utility in ves-frontend.css', strpos($css, 'ves-progress-bar') !== false);
$add('ves-progress-fill utility in ves-frontend.css', strpos($css, 'ves-progress-fill') !== false);

// Trend delta utilities
$add('ves-trend-up class in ves-frontend.css', strpos($css, 'ves-trend-up') !== false);
$add('ves-trend-down class in ves-frontend.css', strpos($css, 'ves-trend-down') !== false);

// MarketSignal: responsive
$add('640px full-bleed in ves-market-signal.js', strpos($ms, 'max-width:640px') !== false && strpos($ms, 'border:none') !== false);
$add('900px 2-col grid in ves-market-signal.js', strpos($ms, 'max-width:900px') !== false);

// MarketSignal: mini-bar and trend-delta CSS
$add('mini-bar-wrap class in ves-market-signal.js', strpos($ms, 'mini-bar-wrap') !== false);
$add('trend-up class in ves-market-signal.js', strpos($ms, 'trend-up') !== false);

// MarketSignal: MiniBar wired into Dashboard
$add('MiniBar used in Dashboard stat card', strpos($ms, 'React.createElement(MiniBar') !== false);

// Empty state FI styling
$add('F.I/UI/EMPTY eyebrow in ves-frontend.css', strpos($css, 'F.I/UI/EMPTY') !== false);
$add('F.I/UI/EMPTY eyebrow in ves-market-signal.js', strpos($ms, 'F.I/UI/EMPTY') !== false);

// Print results
$pass = $fail = 0;
foreach ($checks as [$name, $ok]) {
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name\n";
    $ok ? $pass++ : $fail++;
}
echo "\n$pass passed, $fail failed.\n";
if ($fail > 0) exit(1);
