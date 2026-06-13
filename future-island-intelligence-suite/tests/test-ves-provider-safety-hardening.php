<?php
/**
 * v0.1 RC — provider safety hardening contract (Apify).
 *
 * Proves by EXECUTION against a mocked HTTP layer (no live network):
 *  1. run URLs carry the maxTotalChargeUsd ceiling and never a token
 *  2. the token travels ONLY in the Authorization header
 *  3. a run-start for a non-allowlisted actor is refused BEFORE any HTTP
 *  4. allowlisted actors (registry, legacy map form, option extras) dispatch
 *  5. read-style requests are never gated
 *  6. a run-start URL missing the ceiling logs a config warning diagnostic
 *
 * Run: php tests/test-ves-provider-safety-hardening.php
 */

require __DIR__ . '/bootstrap-wp-shims.php';

// platform-input needs the hard-ceiling helper (normally from legacy-config).
if (!function_exists('ves_hard_max_charge_usd')) {
    function ves_hard_max_charge_usd() { return 3.0; }
}

require dirname(__DIR__) . '/includes/class-ves-apify-actor-registry.php';
require dirname(__DIR__) . '/includes/class-ves-apify-client.php';
require dirname(__DIR__) . '/includes/platform-input.php';

$state = ['total' => 0, 'pass' => 0, 'fail' => []];

// ── 1. Run URL: ceiling present, token absent ───────────────────────────────
$run_url = ves_make_run_url('apify/web-scraper', ves_prepare_run_options());
ves_test_ok('run URL includes maxTotalChargeUsd ceiling', strpos($run_url, 'maxTotalChargeUsd=3') !== false, $state);
ves_test_ok('run URL contains no token param', strpos($run_url, 'token=') === false, $state);
ves_test_ok('run URL targets normalized ~ slug', strpos($run_url, '/v2/acts/apify~web-scraper/runs') !== false, $state);
ves_test_ok('hard ceiling helper is positive', ves_hard_max_charge_usd() > 0, $state);

// ── 2. Allowlist membership ─────────────────────────────────────────────────
ves_test_ok('registry actor allowed (slash form)', VES_Apify_Actor_Registry::is_allowed_slug('apify/web-scraper'), $state);
ves_test_ok('registry actor allowed (~ form)', VES_Apify_Actor_Registry::is_allowed_slug('apify~website-content-crawler'), $state);
ves_test_ok('unknown actor is NOT allowed', !VES_Apify_Actor_Registry::is_allowed_slug('evil-actor/data-exfil'), $state);
ves_test_ok('empty slug is NOT allowed', !VES_Apify_Actor_Registry::is_allowed_slug(''), $state);

$GLOBALS['__ves_options']['ves_apify_actor_allowlist_extra'] = ['custom/approved-actor'];
ves_test_ok('option extra extends the allowlist', VES_Apify_Actor_Registry::is_allowed_slug('custom~approved-actor'), $state);
$GLOBALS['__ves_options']['ves_apify_actor_allowlist_extra'] = [];

// ── 3. Dispatch gate: non-allowlisted actor refused before HTTP ─────────────
VES_Test_HTTP::reset();
VES_Admin::$diagnostics = [];
VES_Config::$token = 'apify_api_TESTTOKEN1234567890';
$blocked = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/evil-actor~data-exfil/runs?build=latest&maxTotalChargeUsd=3', ['q' => 'x']);
ves_test_ok('non-allowlisted run-start returns WP_Error', is_wp_error($blocked), $state);
ves_test_ok('error code is ves_actor_not_allowlisted', is_wp_error($blocked) && $blocked->get_error_code() === 'ves_actor_not_allowlisted', $state);
ves_test_ok('non-allowlisted dispatch makes ZERO HTTP calls', count(VES_Test_HTTP::$calls) === 0, $state);
ves_test_ok('refusal message leaks no token', is_wp_error($blocked) && strpos($blocked->get_error_message(), 'apify_api_') === false, $state);
$diag = json_encode(VES_Admin::$diagnostics);
ves_test_ok('refusal recorded as allowlist diagnostic', strpos($diag, 'apify_actor_not_allowlisted') !== false, $state);
ves_test_ok('diagnostic leaks no token', strpos($diag, 'apify_api_TESTTOKEN1234567890') === false, $state);

// run-sync variants are gated too.
VES_Test_HTTP::reset();
$blocked2 = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/evil-actor~data-exfil/run-sync-get-dataset-items?maxTotalChargeUsd=3', ['q' => 'x']);
ves_test_ok('run-sync dispatch is gated as well', is_wp_error($blocked2) && $blocked2->get_error_code() === 'ves_actor_not_allowlisted' && count(VES_Test_HTTP::$calls) === 0, $state);

// ── 4. Allowlisted actor dispatches; token only in Authorization header ─────
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN999', 'status' => 'READY']]];
$url = ves_make_run_url('apify/web-scraper', ves_prepare_run_options());
$okrun = VES_Apify_Client::request('POST', $url, ['startUrls' => [['url' => 'https://example.com']]]);
ves_test_ok('allowlisted dispatch succeeds', is_array($okrun) && (int) ($okrun['code'] ?? 0) === 201, $state);
$call = VES_Test_HTTP::$calls[0] ?? [];
ves_test_ok('exactly one HTTP call made', count(VES_Test_HTTP::$calls) === 1, $state);
ves_test_ok('Authorization header carries Bearer token', ($call['args']['headers']['Authorization'] ?? '') === 'Bearer apify_api_TESTTOKEN1234567890', $state);
ves_test_ok('dispatched URL carries no token', strpos((string) ($call['url'] ?? ''), 'apify_api_') === false && strpos((string) ($call['url'] ?? ''), 'token=') === false, $state);
ves_test_ok('dispatched URL carries the charge ceiling', strpos((string) ($call['url'] ?? ''), 'maxTotalChargeUsd=') !== false, $state);

// ── 5. Read-style requests are never gated ──────────────────────────────────
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 200, 'body' => ['data' => ['id' => 'RUN1', 'status' => 'SUCCEEDED']]];
$read = VES_Apify_Client::request('GET', 'https://api.apify.com/v2/acts/evil-actor~data-exfil/runs/RUN1');
ves_test_ok('GET run status for any actor is not gated', is_array($read) && count(VES_Test_HTTP::$calls) === 1, $state);

VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 200, 'body' => ['data' => ['items' => []]]];
$abort = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/actor-runs/RUN1/abort');
ves_test_ok('abort (non run-start POST) is not gated', is_array($abort) && count(VES_Test_HTTP::$calls) === 1, $state);

// ── 6. Phase 9A.2 — missing/invalid ceiling is a HARD BLOCK ──────────────────
VES_Test_HTTP::reset();
VES_Admin::$diagnostics = [];
$no_ceiling = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/apify~web-scraper/runs?build=latest', ['q' => 'x']);
ves_test_ok('missing-ceiling dispatch is BLOCKED (fail closed)', is_wp_error($no_ceiling) && $no_ceiling->get_error_code() === 'ves_charge_ceiling_required', $state);
ves_test_ok('missing-ceiling block makes ZERO HTTP calls', count(VES_Test_HTTP::$calls) === 0, $state);
$diag2 = json_encode(VES_Admin::$diagnostics);
ves_test_ok('missing ceiling recorded as apify_charge_ceiling_missing', strpos($diag2, 'apify_charge_ceiling_missing') !== false, $state);

VES_Test_HTTP::reset();
$low = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/apify~web-scraper/runs?maxTotalChargeUsd=0.01', ['q' => 'x']);
ves_test_ok('ceiling below the 0.10 floor is blocked', is_wp_error($low) && $low->get_error_code() === 'ves_charge_ceiling_too_low' && count(VES_Test_HTTP::$calls) === 0, $state);

VES_Test_HTTP::reset();
$high = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/apify~web-scraper/runs?maxTotalChargeUsd=999', ['q' => 'x']);
ves_test_ok('ceiling above the maximum is blocked', is_wp_error($high) && $high->get_error_code() === 'ves_charge_ceiling_too_high' && count(VES_Test_HTTP::$calls) === 0, $state);

// Zero-cost exception: only an allowlisted actor explicitly registered zero_cost
// may dispatch without a ceiling.
$GLOBALS['__ves_options']['ves_apify_actor_registry_overrides'] = [
    'web_scraper' => ['actor_id' => 'apify/web-scraper', 'zero_cost' => true],
];
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN-ZC']]];
$zc = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/apify~web-scraper/runs?build=latest', ['q' => 'x']);
ves_test_ok('zero-cost registered actor may dispatch without ceiling', is_array($zc) && count(VES_Test_HTTP::$calls) === 1, $state);
$GLOBALS['__ves_options']['ves_apify_actor_registry_overrides'] = [];
ves_test_ok('unknown actors are never zero-cost', !VES_Apify_Actor_Registry::is_zero_cost_slug('evil-actor/data-exfil'), $state);

// ── 6b. Deep-review hardening: POST run-start is NOT retried on 5xx ─────────
VES_Test_HTTP::reset();
VES_Admin::$diagnostics = [];
VES_Test_HTTP::$queue[] = ['code' => 500, 'body' => ['error' => ['message' => 'upstream exploded']]];
VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN-NEVER']]];
$r5xx = VES_Apify_Client::request('POST', ves_make_run_url('apify/web-scraper', ves_prepare_run_options()), ['q' => 'x']);
ves_test_ok('run-start 5xx returns an error (no silent retry)', is_wp_error($r5xx), $state);
ves_test_ok('run-start 5xx made EXACTLY ONE HTTP call (no double dispatch/charge)', count(VES_Test_HTTP::$calls) === 1, $state);
ves_test_ok('no-retry decision recorded as diagnostic', strpos(json_encode(VES_Admin::$diagnostics), 'apify_run_start_no_retry') !== false, $state);

// 429 on a run-start stays retryable (run was rate-limited away, not started).
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 429, 'body' => ['error' => ['message' => 'rate limited']]];
VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN-RETRIED']]];
$r429 = VES_Apify_Client::request('POST', ves_make_run_url('apify/web-scraper', ves_prepare_run_options()), ['q' => 'x']);
ves_test_ok('run-start 429 is retried and succeeds', is_array($r429) && count(VES_Test_HTTP::$calls) === 2, $state);

// GET reads keep the original transient-retry behavior on 5xx.
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 500, 'body' => ['error' => ['message' => 'flaky']]];
VES_Test_HTTP::$queue[] = ['code' => 200, 'body' => ['data' => ['id' => 'RUN1', 'status' => 'SUCCEEDED']]];
$rget = VES_Apify_Client::request('GET', 'https://api.apify.com/v2/acts/apify~web-scraper/runs/RUN1');
ves_test_ok('GET read retries 5xx and succeeds (2 calls)', is_array($rget) && count(VES_Test_HTTP::$calls) === 2, $state);

// ── 7. Source-level guarantees ──────────────────────────────────────────────
$pi_src = file_get_contents(dirname(__DIR__) . '/includes/platform-input.php');
ves_test_ok('run URL builder never injects a token param', strpos($pi_src, "query['token']") === false && !preg_match('/[?&]token=/', $pi_src), $state);

ves_test_finish('v0.1 RC provider-safety hardening', $state);
