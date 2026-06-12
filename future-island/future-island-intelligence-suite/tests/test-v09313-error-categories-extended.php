<?php
/**
 * v0.9.31.6 — Extended error categories: provider_empty and local_filters_removed_all.
 * Run: php tests/test-v09313-error-categories-extended.php
 * Grep-based structural checks + static analysis; no external calls.
 */
$root = dirname(__DIR__);
$src = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// 1. classify_error_category must contain provider_empty category.
$assert(strpos($src, "'provider_empty'") !== false, "classify_error_category must return 'provider_empty'");
$assert(strpos($src, 'provider_result_empty') !== false, "classify_error_category must detect stage 'provider_result_empty'");

// 2. classify_error_category must contain local_filters_removed_all category.
$assert(strpos($src, "'local_filters_removed_all'") !== false, "classify_error_category must return 'local_filters_removed_all'");
$assert(strpos($src, 'post_filter_empty') !== false, "classify_error_category must detect stage 'post_filter_empty'");
$assert(strpos($src, 'pre_filter_count') !== false, "classify_error_category must check pre_filter_count context");
$assert(strpos($src, 'post_filter_count') !== false, "classify_error_category must check post_filter_count context");

// 3. public_message_for_error_category must have messages for both new categories.
$provider_empty_msg = 'The source returned no results for this query.';
$filter_removed_msg = 'Results were collected but all were removed by your active filters';
$assert(strpos($src, $provider_empty_msg) !== false, 'public message for provider_empty must be present');
$assert(strpos($src, $filter_removed_msg) !== false, 'public message for local_filters_removed_all must be present');

// 4. Frontend JS must handle local_filters_removed_all non-error state.
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$assert(strpos($js, 'local_filters_removed_all') !== false, 'ves-frontend.js must handle local_filters_removed_all category');
$assert(strpos($js, 'ves-filter-warning') !== false, 'ves-frontend.js must create .ves-filter-warning element');
$assert(strpos($js, 'showFilterWarningIfNeeded') !== false || strpos($js, 'filterWarning') !== false, 'ves-frontend.js must have filter-warning display logic');

// 5. The filter-warning must not display as a hard error state.
// It should NOT use setStatus with 'error' for local_filters_removed_all.
// Instead it should use 'info' or a neutral state.
$filter_all_block = '';
$fc = strpos($js, 'local_filters_removed_all');
if ($fc !== false) {
    $filter_all_block = substr($js, $fc, 400);
}
$assert(strpos($filter_all_block, "'error'") === false, "local_filters_removed_all handler must NOT use 'error' status class");
$assert(strpos($filter_all_block, "'info'") !== false || strpos($filter_all_block, 'ves-filter-warning') !== false,
    "local_filters_removed_all handler must use 'info' status or filter-warning banner");

// 6. Version check.
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$assert(strpos($main, 'Version: 1.2.8') !== false, 'Plugin header must declare Version: 1.2.8');
$assert(strpos($main, "VES_PLUGIN_VERSION',         '1.2.8'") !== false, 'VES_PLUGIN_VERSION must be 1.1.0');

if ($fail === 0) {
    echo "OK v0.9.31.6 extended error categories — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 extended error categories — $fail failures, $pass passed\n";
    exit(1);
}
