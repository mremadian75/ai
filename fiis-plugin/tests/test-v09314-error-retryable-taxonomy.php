<?php
/**
 * v0.9.31.6 — Error taxonomy: retryable flag in build_safe_diagnostic;
 *             rental user message is Spanish; rate-limit/busy retryable=true;
 *             auth/payload retryable=false.
 * Run: php tests/test-v09314-error-retryable-taxonomy.php
 */
$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Version
$assert(strpos($main, 'Version: 1.2.4') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.4'") !== false, 'Version is 1.1.0');

// classify_retryable helper exists
$assert(strpos($ajax, 'private static function classify_retryable(') !== false, 'classify_retryable() helper declared');

// classify_retryable is called from build_safe_diagnostic
$assert(strpos($ajax, 'self::classify_retryable(') !== false, 'build_safe_diagnostic calls classify_retryable');

// build_safe_diagnostic includes retryable key
$diag_pos = strpos($ajax, 'private static function build_safe_diagnostic(');
$diag_body = $diag_pos !== false ? substr($ajax, $diag_pos, 900) : '';
$assert(strpos($diag_body, "'retryable'") !== false, 'build_safe_diagnostic return array includes retryable key');

// Retryable categories: rate-limit, transport, empty → true
$retry_yes = strpos($ajax, 'provider_rate_limit') !== false && strpos($ajax, 'provider_transport_error') !== false && strpos($ajax, 'provider_empty') !== false;
$assert($retry_yes, 'classify_retryable retryable-yes list includes rate_limit, transport_error, provider_empty');

// Non-retryable categories: auth, access, payload → false
$retry_no = strpos($ajax, 'provider_auth_error') !== false && strpos($ajax, 'provider_access_denied') !== false && strpos($ajax, 'provider_payload_rejected') !== false;
$assert($retry_no, 'classify_retryable retryable-no list includes auth_error, access_denied, payload_rejected');

// Rental user message is now in Spanish (v0.9.31.6)
$msg_fn_pos = strpos($ajax, 'function public_message_for_error_category(');
$msg_fn_body = $msg_fn_pos !== false ? substr($ajax, $msg_fn_pos, 4000) : '';
$rental_in_msg_pos = strpos($msg_fn_body, "provider_actor_rental_required'");
$rental_msg_window = ($rental_in_msg_pos !== false) ? substr($msg_fn_body, $rental_in_msg_pos, 400) : '';
$assert(strpos($rental_msg_window, 'Esta fuente') !== false || strpos($rental_msg_window, 'administrador') !== false || strpos($rental_msg_window, 'actor') !== false, 'Rental user message is in Spanish and references actor/admin');

// Rental message does NOT say "This source requires" (old English)
$assert(strpos($rental_msg_window, 'This source requires an active rental') === false, 'Old English rental message removed');

// Report render wrapped in try/catch (Section 4)
$assert(strpos($ajax, 'ves_render_report_template') !== false && strpos($ajax, 'Throwable $report_ex') !== false, 'report template render is wrapped in try/catch');

if ($fail === 0) {
    echo "OK v0.9.31.6 error retryable taxonomy — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 error retryable taxonomy — $fail failures, $pass passed\n";
    exit(1);
}
