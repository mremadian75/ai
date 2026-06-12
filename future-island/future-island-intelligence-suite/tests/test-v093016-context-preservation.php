<?php
/**
 * v0.9.30.21 — context preservation for explicit errors and PHP shutdown fatals.
 */
$root = dirname(__DIR__);
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$checks = [];
$add = function($name, $ok) use (&$checks) {
    $checks[] = [$name, (bool) $ok];
};
$shutdown = '';
if (preg_match('/private static function register_ajax_shutdown_handler\([\s\S]*?private static function fatal\(/', $ajax, $m)) {
    $shutdown = $m[0];
}
$error_fn = '';
if (preg_match('/private static function error\([\s\S]*?private static function success\(/', $ajax, $m)) {
    $error_fn = $m[0];
}

$add('version bumped to 1.1.0', strpos($main, 'Version: 1.4.1') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.4.1'") !== false);
$add('error() merges active run context before classification', strpos($error_fn, '$active_context = is_array(self::$active_run_context) ? self::$active_run_context : [];') !== false && strpos($error_fn, '$context = array_merge($active_context, $context);') !== false && strpos($error_fn, 'last_successful_stage') !== false);
$add('explicit error() context preserves post-dispatch state', strpos($error_fn, 'post-dispatch failures') !== false && strpos($error_fn, 'provider_call_attempted/provider_run_created') !== false);
$add('shutdown handler classifies with active context', strpos($shutdown, "self::classify_error_category('ajax_shutdown_fatal'") !== false && strpos($shutdown, '$fatal_context = array_merge($active_context') !== false);
$add('shutdown handler does not hardcode provider flags false', strpos($shutdown, "'provider_call_attempted' => false") === false && strpos($shutdown, "'provider_run_created' => false") === false);
$add('shutdown handler returns category-aware public message', strpos($shutdown, 'self::public_message_for_error_category($category)') !== false);
$add('admin shutdown response exposes last successful stage and provider flags', strpos($shutdown, "'last_successful_stage' => " . "$" . "last_stage") !== false && strpos($shutdown, "'source' => 'ajax_shutdown_fatal'") !== false);

$failures = array_values(array_filter($checks, fn($c) => !$c[1]));
foreach ($checks as [$name, $ok]) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
if ($failures) {
    fwrite(STDERR, "\nv0.9.30.21 context preservation check failed.\n");
    exit(1);
}
echo "\nv0.9.30.21 context preservation checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
