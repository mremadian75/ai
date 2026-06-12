<?php
$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$assets = file_get_contents($root . '/includes/class-ves-assets.php');
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

$add('version bumped to 1.3.0 in main plugin', strpos(file_get_contents($root . '/future-island-intelligence-suite.php'), '1.3.0') !== false);
$add('platform input validation throwable is degraded instead of ajax fatal', strpos($ajax, 'platform_input_validation_throwable_degraded') !== false && strpos($ajax, '$platform_input_valid = is_array($input) && !empty($input);') !== false);
$add('duplicate guard throwable continues to provider dispatch', strpos($ajax, 'duplicate_guard_throwable_degraded') !== false && strpos($ajax, 'duplicate_guard_degraded_continue_dispatch') !== false);
$add('provider cost guard throwable is non-blocking for admins', strpos($ajax, 'provider_cost_guard_throwable_degraded') !== false && strpos($ajax, "if (!current_user_can('manage_options'))") !== false);
$add('start gate throwable is non-blocking for admins', strpos($ajax, 'start_gate_throwable_degraded') !== false && strpos($ajax, '$start_gate_key = \'\';') !== false);
$add('dispatch slot throwable is non-blocking for admins', strpos($ajax, 'dispatch_slot_throwable_degraded') !== false && strpos($ajax, '$dispatch_slot = \'\';') !== false);
$add('provider request throwable becomes WP_Error with provider_call_attempted true', strpos($ajax, 'provider_dispatch_throwable') !== false && strpos($ajax, "'provider_call_attempted' => true") !== false);
$add('admin flag is localized for frontend diagnostics', strpos($assets, 'window.VES_IS_ADMIN') !== false);
// v0.9.30.21: adminDetail raw JSON blob replaced by renderAdminDetail() structured helper
$add('frontend renders admin diagnostics for admins even without debug query param', strpos($js, 'window.VES_IS_ADMIN') !== false && strpos($js, 'renderAdminDetail(err)') !== false);

$failed = array_filter($checks, fn($c) => !$c[1]);
foreach ($checks as [$name, $ok]) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . PHP_EOL;
}
if ($failed) {
    echo "\nFailed: " . count($failed) . " / " . count($checks) . PHP_EOL;
    exit(1);
}
echo "\nv0.9.30.21 pre-dispatch degradation checks passed: " . count($checks) . " / " . count($checks) . PHP_EOL;
