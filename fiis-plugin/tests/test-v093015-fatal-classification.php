<?php
/**
 * v0.9.30.21 — fatal classification must preserve provider-dispatch state.
 */
$root = dirname(__DIR__);
$controller = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$checks = [];
$add = function ($label, $ok) use (&$checks) { $checks[] = [$label, (bool) $ok]; };

$add('version bumped to 1.1.0', strpos($main, 'Version: 1.2.4') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.4'") !== false);
$add('caught Throwable after provider dispatch is not labeled ajax_fatal', strpos($controller, '$is_fatal_context = in_array($source') !== false && strpos($controller, "if (!empty(\$context['provider_call_attempted'])) { return 'post_dispatch_fatal'; }") !== false && strpos($controller, "return 'ajax_fatal';") !== false);
$add('caught Throwable after provider run receives post-provider category', strpos($controller, "if (!empty(\$context['provider_run_created'])) { return 'post_provider_run_fatal'; }") !== false);
$add('post-dispatch fatal has user-safe message', strpos($controller, "if (\$category === 'post_dispatch_fatal')") !== false && strpos($controller, 'The source request was attempted, but the response could not be processed') !== false);
$add('post-provider fatal has user-safe message', strpos($controller, "if (\$category === 'post_provider_run_fatal')") !== false && strpos($controller, 'The source started, but the result pipeline failed after dispatch') !== false);
$add('summarize_input cannot become an ajax fatal', strpos($controller, 'summary_degraded') !== false && strpos($controller, 'summarize_input_failed') !== false && strpos($controller, 'summarize_input_actor_slug_failed') !== false);
$add('fatal admin_detail includes source and last successful stage', strpos($controller, "'source' => sanitize_key((string) \$source)") !== false && strpos($controller, "'last_successful_stage' => sanitize_key") !== false);

$failures = array_filter($checks, static fn($c) => !$c[1]);
foreach ($checks as [$label, $ok]) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
}
if ($failures) {
    fwrite(STDERR, "\nv0.9.30.21 fatal classification check failed.\n");
    exit(1);
}
echo "\nv0.9.30.21 fatal classification checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
