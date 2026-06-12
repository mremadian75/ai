<?php
$root = dirname(__DIR__);
$files = [
    'main' => $root . '/future-island-intelligence-suite.php',
    'admin' => $root . '/includes/class-ves-admin.php',
    'ajax' => $root . '/includes/class-ves-ajax-controller.php',
    'apify' => $root . '/includes/class-ves-apify-client.php',
];
$src = [];
foreach ($files as $k => $f) { $src[$k] = file_get_contents($f); }
$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

$add('version bumped to 1.1.0', strpos($src['main'], 'Version: 1.2.8') !== false && strpos($src['main'], "VES_PLUGIN_VERSION',         '1.2.8'") !== false);
$add('settings page has top visible save actionbar', strpos($src['admin'], 'ves-settings-actionbar') !== false && strpos($src['admin'], "submit_button('Guardar cambios', 'primary', 'submit', false") !== false);
$add('settings page keeps bottom save button', substr_count($src['admin'], "submit_button('Guardar cambios'") >= 2);
$add('runtime lock reset admin action registered', strpos($src['admin'], "admin_post_ves_clear_runtime_locks") !== false && strpos($src['admin'], 'handle_clear_runtime_locks') !== false);
$add('apify connection test admin action registered', strpos($src['admin'], "admin_post_ves_test_apify_connection") !== false && strpos($src['admin'], 'handle_test_apify_connection') !== false);
$add('runtime lock reset clears start gate transients', strpos($src['admin'], "_transient_ves_start_gate_") !== false && strpos($src['admin'], "_transient_timeout_ves_start_gate_") !== false);
$add('runtime lock reset clears dispatch slots', strpos($src['admin'], "update_option('ves_apify_active_slots', [], false)") !== false);
$add('runtime lock reset clears duplicate source execution guard', strpos($src['admin'], "update_option('ves_source_execution_index_v2', [], false)") !== false);
$add('apify connection test uses Authorization bearer header', strpos($src['admin'], "'Authorization' => 'Bearer ' . \$token") !== false);
$add('apify connection test does not print token', strpos($src['admin'], 'apify_api_') === false || strpos($src['admin'], 'placeholder') !== false);
$add('apify client exposes clear dispatch slots helper', strpos($src['apify'], 'public static function clear_dispatch_slots()') !== false);
$add('apify slot acquisition cleans stale slots first', strpos($src['apify'], 'self::cleanup_stale_dispatch_slots();') !== false);
$add('standard start gate ttl reduced from 10 minutes', strpos($src['ajax'], "'rid' => \$request_id], 90)") !== false);
$add('duplicate start gate categorized before provider call', strpos($src['ajax'], "'error_category' => 'duplicate_dispatch_blocked'") !== false && strpos($src['ajax'], "'provider_call_attempted' => false") !== false);
$add('missing token technical message points to settings', strpos($src['ajax'], 'Backend token is not configured. Save the Backend / Apify token') !== false);
$add('normal public support code remains enabled', strpos($src['ajax'], 'Diagnostic code: ') !== false && strpos($src['ajax'], 'public_support_code') !== false);
$add('admin diagnostics preserve support code', strpos($src['ajax'], 'public_support_code') !== false && strpos($src['admin'], 'record_diagnostic') !== false);
$add('settings actionbar includes reset locks control', strpos($src['admin'], 'Reset runtime locks') !== false);
$add('settings actionbar includes apify test control', strpos($src['admin'], 'Test Apify connection') !== false);
$add('connection test records diagnostics', strpos($src['admin'], "record_diagnostic('apify_connection_test'") !== false);
$add('runtime reset records diagnostics', strpos($src['admin'], "record_diagnostic('runtime_locks_reset'") !== false);
$add('clear runtime locks requires manage_options', strpos($src['admin'], "current_user_can('manage_options')") !== false && strpos($src['admin'], "check_admin_referer('ves_clear_runtime_locks')") !== false);
$add('test apify requires manage_options', strpos($src['admin'], "check_admin_referer('ves_test_apify_connection')") !== false);

$failed = array_filter($checks, fn($c) => !$c[1]);
foreach ($checks as [$name, $ok]) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . PHP_EOL;
}
if ($failed) {
    echo "\nFailed: " . count($failed) . " / " . count($checks) . PHP_EOL;
    exit(1);
}
echo "\nAll settings/runtime dispatch hardening checks passed: " . count($checks) . " / " . count($checks) . PHP_EOL;
