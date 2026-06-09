<?php
/**
 * Phase 2 + Phase 1-completion source contract.
 *
 * Source-level guards (no execution): fails if a future edit unwires the canonical
 * intelligence contract from the integrated production flows, or removes the
 * Phase 1-completion context/preflight wiring. Mirrors the bootstrap-contract style.
 *
 * Run: php tests/test-phase2-integration-contract.php
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; }
};
$src = function ($rel) use ($root) { $p = $root . '/' . $rel; return is_file($p) ? (string) file_get_contents($p) : ''; };

// Canonical store exists and is bootstrapped + migrated.
$ok(strpos($src('includes/class-ves-intelligence-store.php'), 'class VES_Intelligence_Store') !== false, 'VES_Intelligence_Store class file present');
$ok(strpos($src('future-island-intelligence-suite.php'), 'class-ves-intelligence-store.php') !== false, 'bootstrap loads the intelligence store');
$ok(strpos($src('includes/class-ves-migrations.php'), "'VES_Intelligence_Store'") !== false, 'migration runner registers the intelligence store');
$ok(strpos($src('includes/class-ves-plugin.php'), 'VES_Intelligence_Store::create_tables') !== false, 'activation creates the intelligence tables');

// B6 integration #1 — Deep Trend Finder report flow.
$dtf = $src('modules/deep-trend-finder/includes/class-fidtf-report-service.php');
$ok(strpos($dtf, 'VES_Intelligence_Store::ingest_bundle(') !== false, 'DTF report flow writes canonical records (ingest_bundle)');
$ok(strpos($dtf, 'VES_AI_Usage_Tracker::set_context(') !== false, 'DTF report flow binds ambient usage context (A2)');
$ok(strpos($dtf, 'VES_AI_Usage_Tracker::preflight(') !== false, 'DTF report flow runs an advisory preflight (A3)');

// B6 integration #2 — Creative Intelligence flow.
$fici = $src('modules/creative-intelligence/includes/services/class-fici-analysis-service.php');
$ok(strpos($fici, 'VES_Intelligence_Store::ingest_bundle(') !== false, 'Creative Intelligence flow writes canonical records (ingest_bundle)');
$ok(strpos($fici, 'VES_AI_Usage_Tracker::set_context(') !== false, 'Creative Intelligence flow binds ambient usage context (A2)');
$ok(strpos($fici, 'VES_AI_Usage_Tracker::preflight(') !== false, 'Creative Intelligence flow runs an advisory preflight (A3)');

// Phase 1 completion — tracker capabilities present.
$tracker = $src('includes/class-ves-ai-usage-tracker.php');
$ok(strpos($tracker, 'function set_context(') !== false && strpos($tracker, 'function clear_context(') !== false, 'A2: tracker exposes ambient context API');
$ok(strpos($tracker, 'actual_provider_cost') !== false && strpos($tracker, '$has_actual_usage') !== false, 'A1: tracker splits actual vs estimated provider cost');
$ok(strpos($tracker, 'function record_scrape(') !== false, 'A5: tracker exposes scrape (apify) groundwork');

// B7 — admin diagnostics surfaces the contract.
$ok(strpos($src('includes/class-ves-admin-console.php'), 'render_intelligence') !== false, 'admin console surfaces Phase 2 intelligence diagnostics');

// A4 — lint runner gained the new modes.
$lint = $src('bin/lint-php.sh');
$ok(strpos($lint, '--changed') !== false && strpos($lint, '--verbose') !== false, 'lint runner supports --changed and --verbose');

// ── Corrective patch contracts ───────────────────────────────────────────────
$store = $src('includes/class-ves-intelligence-store.php');

// Fix 1 — memory facade complete.
$ok(strpos($store, 'function get_memory_record(') !== false && strpos($store, 'function list_memory_records(') !== false, 'Fix1: store exposes get_memory_record + list_memory_records');

// Fix 3 — status filter guarded by capability map.
$ok(strpos($store, 'function supports_status(') !== false, 'Fix3: store has a supports_status capability map');
$ok(strpos($store, 'self::supports_status($entity)') !== false, 'Fix3: generic list() guards the status filter');

// Fix 2 — preflight persisted in each integrated flow.
$ok(strpos($dtf, "'record_estimate' => true") !== false, 'Fix2: DTF preflight passes record_estimate => true');
$ok(strpos($fici, "'record_estimate' => true") !== false, 'Fix2: Creative Intelligence preflight passes record_estimate => true');
$ms = $src('includes/class-ves-market-signal-commercial.php');
$ok(strpos($ms, "'record_estimate' => true") !== false, 'Fix2: MarketSignal preflight passes record_estimate => true');

// Fix 4 — ambient context wrapped in try/finally in each production flow.
$has_finally_clear = function ($code) {
    // crude but effective: clear_context must appear inside a `finally { ... }` block.
    return (bool) preg_match('/finally\s*\{[^}]*clear_context\s*\(/s', $code);
};
$ok(strpos($dtf, 'set_context(') !== false && $has_finally_clear($dtf), 'Fix4: DTF clears ambient context in a finally block');
$ok(strpos($fici, 'set_context(') !== false && $has_finally_clear($fici), 'Fix4: Creative Intelligence clears ambient context in a finally block');
$ok(strpos($ms, 'set_context(') !== false && $has_finally_clear($ms), 'Fix4: MarketSignal clears ambient context in a finally block');

// Fix 5 — lint runner timeout-per-file option.
$ok(strpos($lint, '--timeout-per-file=') !== false, 'Fix5: lint runner supports --timeout-per-file');

echo "\nPhase 2 integration contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
