<?php
/**
 * v0.9.31.6 — BUG-03: public_support_code() hardened against FI-000000.
 * Asserts the entropy + diagnostic + all-zero guard are present.
 * Run: php tests/test-v09315-public-support-code-hardened.php
 */
$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.8') !== false, 'Version is 1.1.0');

$fn_pos = strpos($ajax, 'private static function public_support_code(');
$fn_body = $fn_pos !== false ? substr($ajax, $fn_pos, 2000) : '';

$assert(strpos($fn_body, 'wp_rand(100000, 999999)') !== false, 'Empty-seed branch adds wp_rand entropy');
$assert(strpos($fn_body, "support_code_empty_seed") !== false, 'Empty seed raises support_code_empty_seed diagnostic');
$assert(strpos($fn_body, "ltrim(\$num, '0')") !== false, 'All-zero result is guarded with ltrim check');
$assert(strpos($fn_body, 'wp_rand(10000000, 99999999)') !== false, 'All-zero recovery uses wp_rand fallback string');

// Sanity: the original microtime path is still there as the seed primer.
$assert(strpos($fn_body, "microtime(true)") !== false, 'microtime seed primer still present');

if ($fail === 0) {
    echo "OK v0.9.31.6 public_support_code hardened — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 public_support_code hardened — $fail failures, $pass passed\n";
    exit(1);
}
