<?php
/**
 * v0.9.31.3 — Trend Finder gate order: input validation before acquire_start_gate.
 * Run: php tests/test-v09313-trend-gate-validation-order.php
 * Grep-based structural check; no external calls.
 */
$root = dirname(__DIR__);
$src = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Find the trends_start method block.
$trends_start_pos = strpos($src, 'public static function trends_start()');
$assert($trends_start_pos !== false, 'trends_start() method must exist in ajax controller');

if ($trends_start_pos !== false) {
    // Extract the method body up to the next public static function.
    $method_end = strpos($src, 'public static function trends_poll()', $trends_start_pos);
    $method_body = $method_end !== false
        ? substr($src, $trends_start_pos, $method_end - $trends_start_pos)
        : substr($src, $trends_start_pos, 3000);

    // Confirm nonce validation happens before gate acquisition.
    $nonce_pos = strpos($method_body, 'check_ajax_referer');
    $gate_pos  = strpos($method_body, 'acquire_start_gate');
    $assert($nonce_pos !== false, 'trends_start() must call check_ajax_referer');
    $assert($gate_pos !== false,  'trends_start() must call acquire_start_gate');
    if ($nonce_pos !== false && $gate_pos !== false) {
        $assert($nonce_pos < $gate_pos, 'Nonce check must occur BEFORE acquire_start_gate');
    }

    // Confirm topic validation (empty check / input sanitization) happens before gate.
    $topic_pos = strpos($method_body, "'topic'");
    if ($topic_pos === false) { $topic_pos = strpos($method_body, '"topic"'); }
    $assert($topic_pos !== false, 'trends_start() must validate topic input');
    if ($topic_pos !== false && $gate_pos !== false) {
        $assert($topic_pos < $gate_pos, 'Topic input validation must occur BEFORE acquire_start_gate');
    }

    // Confirm access control (module check) happens before gate.
    $access_pos = strpos($method_body, 'user_can_access_module');
    if ($access_pos === false) { $access_pos = strpos($method_body, 'require_panel_access'); }
    $assert($access_pos !== false, 'trends_start() must check module access');
    if ($access_pos !== false && $gate_pos !== false) {
        $assert($access_pos < $gate_pos, 'Module access check must occur BEFORE acquire_start_gate');
    }

    // Confirm gate is eventually released in the finally/release block.
    $release_pos = strpos($method_body, 'release_start_gate');
    $assert($release_pos !== false, 'trends_start() must call release_start_gate');
}

if ($fail === 0) {
    echo "OK v0.9.31.3 Trend Finder gate/validation order — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.3 Trend Finder gate/validation order — $fail failures, $pass passed\n";
    exit(1);
}
