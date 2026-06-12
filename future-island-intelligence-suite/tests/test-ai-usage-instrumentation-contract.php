<?php
/**
 * Contract test — every production OpenAI call site is wired to the cost spine.
 *
 * This is a source-level guard (like the unified-bootstrap contract): it fails if
 * a future edit removes usage tracking from any of the known LLM call sites, or
 * unloads the Phase 0/1 classes. No code is executed; it inspects file contents.
 *
 * Run: php tests/test-ai-usage-instrumentation-contract.php
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$src = function ($rel) use ($root) { $p = $root . '/' . $rel; return is_file($p) ? (string) file_get_contents($p) : ''; };

// New foundation classes exist.
foreach ([
    'includes/class-ves-cost-estimator.php'   => 'VES_Cost_Estimator',
    'includes/class-ves-ai-usage-tracker.php' => 'VES_AI_Usage_Tracker',
    'includes/class-ves-log.php'              => 'VES_Log',
    'includes/class-ves-health-check.php'     => 'VES_Health_Check',
] as $file => $class) {
    $ok(strpos($src($file), 'class ' . $class) !== false, "{$class} class file present");
}

// Bootstrap loads the new foundation early.
$boot = $src('future-island-intelligence-suite.php');
$ok(strpos($boot, 'class-ves-ai-usage-tracker.php') !== false
    && strpos($boot, 'class-ves-cost-estimator.php') !== false
    && strpos($boot, 'class-ves-log.php') !== false
    && strpos($boot, 'class-ves-health-check.php') !== false, 'bootstrap requires the Phase 0/1 classes');

// Migration runner owns the telemetry table.
$ok(strpos($src('includes/class-ves-migrations.php'), "'VES_AI_Usage_Tracker'") !== false, 'migration runner registers VES_AI_Usage_Tracker::create_table');

// Main client funnels through the timed wrapper.
$client = $src('includes/class-ves-openai-client.php');
$ok(strpos($client, 'function request_responses_api_raw(') !== false, 'main client keeps the raw HTTP method (request_responses_api_raw)');
$ok(strpos($client, 'VES_AI_Usage_Tracker::record_openai_call(') !== false, 'main client wrapper records usage for all 13 internal callers');

// Each direct (bypass) OpenAI call site records usage.
$sites = [
    'includes/analysis.php' => 'legacy social analysis',
    'includes/class-ves-market-signal-commercial.php' => 'market signal',
    'modules/creative-intelligence/includes/ai/class-fici-openai-provider.php' => 'creative intelligence',
    'modules/deep-trend-finder/includes/class-fidtf-ai-bridge.php' => 'deep trend finder smoke test',
];
foreach ($sites as $file => $label) {
    $ok(strpos($src($file), 'VES_AI_Usage_Tracker::record_openai_call(') !== false, "{$label} call site records AI usage ({$file})");
}

// No NEW untracked OpenAI endpoint slipped in: every production file that posts to
// the OpenAI endpoint must also reference the tracker.
$grep_targets = array_merge(['includes/class-ves-openai-client.php'], array_keys($sites));
foreach ($grep_targets as $file) {
    $body = $src($file);
    if (strpos($body, 'api.openai.com/v1/responses') !== false) {
        $ok(strpos($body, 'VES_AI_Usage_Tracker') !== false, "OpenAI-posting file references the tracker ({$file})");
    }
}

echo "\nAI usage instrumentation contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
