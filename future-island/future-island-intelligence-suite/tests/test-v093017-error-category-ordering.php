<?php
/**
 * v0.9.30.21 — explicit provider/runtime errors must not be swallowed by the
 * generic post-dispatch fatal bucket. Only caught/shutdown PHP fatals should use
 * provider_call_attempted/provider_run_created to change ajax_fatal wording.
 */
$root = dirname(__DIR__);
$controller = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$checks = [];
$add = function($name, $ok) use (&$checks) {
    $checks[] = [$name, (bool) $ok];
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};
$add('version bumped to 1.1.0', strpos($main, 'Version: 1.2.8') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.8'") !== false);
$add('classification uses fatal-only context gate', strpos($controller, '$is_fatal_context = in_array($source') !== false && strpos($controller, "'ajax_start_fatal'") !== false && strpos($controller, "'ajax_shutdown_fatal'") !== false);
$add('provider flags are only checked inside fatal context', preg_match('/if \(\$is_fatal_context\) \{\s*if \(!empty\(\$context\[\'provider_run_created\'\]\)\)/s', $controller) === 1);
$add('actor_dispatch_error branch remains after fatal gate', strpos($controller, "$source === 'actor_dispatch_error'") !== false && strpos($controller, '$is_provider_runtime_error') !== false && strpos($controller, 'provider_run_id_missing') !== false);
$add('dataset_fetch_failed branch remains reachable after fatal gate', strpos($controller, 'if ($source === \'dataset_fetch_failed\'') !== false);
$add('post-dispatch fatal wording still exists for real PHP fatals', strpos($controller, 'post_dispatch_fatal') !== false && strpos($controller, 'post_provider_run_fatal') !== false);
$bad = [];
foreach ($checks as [$name, $ok]) { if (!$ok) { $bad[] = $name; } }
if ($bad) {
    fwrite(STDERR, "\nv0.9.30.21 error category ordering check failed:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "\nv0.9.30.21 error category ordering checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
