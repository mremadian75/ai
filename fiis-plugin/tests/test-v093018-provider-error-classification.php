<?php
/**
 * v0.9.30.21 — provider runtime errors must keep their real categories.
 */
$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$checks = [];
$add = function($label, $condition) use (&$checks) { $checks[$label] = (bool) $condition; };
$add('version bumped to 1.1.0', strpos($main, 'Version: 1.2.4') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.4'") !== false);
$add('token classification is not broad token substring', strpos($ajax, 'strpos($haystack, \'token\') !== false) { return \'missing_backend_token\'; }') === false);
$add('public run tokens classified as module access', strpos($ajax, "public_run_token") !== false && strpos($ajax, "return 'module_access_denied';") !== false);
$add('provider runtime includes poll errors', strpos($ajax, 'strpos($source, \'actor_poll\') !== false') !== false);
$add('provider runtime includes abort errors', strpos($ajax, 'strpos($source, \'actor_abort\') !== false') !== false);
$add('provider runtime includes google actor errors', strpos($ajax, 'strpos($source, \'google_actor\') !== false') !== false);
$add('dispatch wp_error forwards error code', strpos($ajax, "'error_code' => \$response->get_error_code()") !== false);
$add('poll wp_error forwards error data', strpos($ajax, '$poll_error_data = $response->get_error_data();') !== false);
$add('abort wp_error forwards error data', strpos($ajax, '$abort_error_data = $response->get_error_data();') !== false);
$add('google actor start forwards error data', strpos($ajax, '$google_run_error_data = $run->get_error_data();') !== false);
$bad = array_keys(array_filter($checks, static fn($ok) => !$ok));
if ($bad) {
    fwrite(STDERR, "\nv0.9.30.21 provider classification check failed:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "\nv0.9.30.21 provider classification checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
