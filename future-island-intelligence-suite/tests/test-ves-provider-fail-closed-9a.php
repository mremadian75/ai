<?php
/**
 * Phase 9A.1 — provider dispatch FAIL-CLOSED contract.
 *
 * The client is loaded WITHOUT the actor registry to prove:
 *  1. missing allowlist service => run-start blocked, ZERO HTTP calls
 *  2. read-style requests still work without the registry
 *  3. the unsafe local-tests-only bypass defaults to FALSE
 *  4. the bypass is inert on a non-local siteurl (refused + security event)
 *  5. the bypass works only on a local siteurl
 *
 * Run: php tests/test-ves-provider-fail-closed-9a.php
 */

require __DIR__ . '/bootstrap-wp-shims.php';

// Intentionally NOT loading VES_Apify_Actor_Registry — that absence is the test.
require dirname(__DIR__) . '/includes/class-ves-security-event-log.php';
require dirname(__DIR__) . '/includes/class-ves-apify-client.php';

$state = ['total' => 0, 'pass' => 0, 'fail' => []];
$run_url = 'https://api.apify.com/v2/acts/apidojo~tiktok-scraper/runs?maxTotalChargeUsd=3';
VES_Config::$token = 'apify_api_TESTTOKEN1234567890';

// ── 1. Missing registry => fail closed, no HTTP ─────────────────────────────
VES_Test_HTTP::reset();
$r = VES_Apify_Client::request('POST', $run_url, ['q' => 'x']);
ves_test_ok('missing registry blocks dispatch (WP_Error)', is_wp_error($r), $state);
ves_test_ok('blocked with ves_allowlist_unavailable', is_wp_error($r) && $r->get_error_code() === 'ves_allowlist_unavailable', $state);
ves_test_ok('ZERO HTTP calls were made', count(VES_Test_HTTP::$calls) === 0, $state);
ves_test_ok('blocked reason is honest and scrubbed', is_wp_error($r) && stripos($r->get_error_message(), 'allowlist') !== false && strpos($r->get_error_message(), 'apify_api_') === false, $state);
$sec = get_option('ves_security_event_log', []);
$sec_json = json_encode($sec);
ves_test_ok('fail-closed refusal recorded as security event', strpos($sec_json, 'allowlist_unavailable') !== false, $state);
ves_test_ok('security event leaks no token', strpos($sec_json, 'apify_api_TESTTOKEN1234567890') === false, $state);

// ── 2. Read-style requests survive without the registry ─────────────────────
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 200, 'body' => ['data' => ['id' => 'RUN1', 'status' => 'SUCCEEDED']]];
$read = VES_Apify_Client::request('GET', 'https://api.apify.com/v2/acts/apidojo~tiktok-scraper/runs/RUN1');
ves_test_ok('GET run status is not gated by the missing registry', is_array($read) && count(VES_Test_HTTP::$calls) === 1, $state);

// ── 3. Unsafe bypass: default FALSE ──────────────────────────────────────────
ves_test_ok('unsafe bypass constant is not defined by default', !defined('VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY'), $state);
$src = file_get_contents(dirname(__DIR__) . '/includes/class-ves-apify-client.php');
ves_test_ok('bypass requires the scary constant AND a local siteurl in source', strpos($src, 'VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY') !== false && strpos($src, "'.test'") !== false, $state);

// ── 4. Bypass defined TRUE but siteurl is NOT local => still refused ────────
define('VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY', true);
$GLOBALS['__ves_options']['siteurl'] = 'https://staging.futureisland.example.com';
$GLOBALS['__ves_options']['ves_security_event_log'] = [];
VES_Test_HTTP::reset();
$r = VES_Apify_Client::request('POST', $run_url, ['q' => 'x']);
ves_test_ok('bypass on non-local siteurl is refused (still fail-closed)', is_wp_error($r) && $r->get_error_code() === 'ves_allowlist_unavailable', $state);
ves_test_ok('non-local bypass made ZERO HTTP calls', count(VES_Test_HTTP::$calls) === 0, $state);
$sec_json = json_encode(get_option('ves_security_event_log', []));
ves_test_ok('non-local bypass attempt recorded as unsafe_bypass_attempt', strpos($sec_json, 'unsafe_bypass_attempt') !== false, $state);

// ── 5. Bypass on a LOCAL siteurl works (dev-only escape hatch) ───────────────
$GLOBALS['__ves_options']['siteurl'] = 'http://localhost:8889';
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN-LOCAL']]];
$r = VES_Apify_Client::request('POST', $run_url, ['q' => 'x']);
ves_test_ok('bypass on local siteurl allows dispatch', is_array($r) && count(VES_Test_HTTP::$calls) === 1, $state);
ves_test_ok('local bypass still keeps the token out of the URL', strpos((string) (VES_Test_HTTP::$calls[0]['url'] ?? ''), 'token=') === false, $state);

ves_test_finish('Phase 9A.1 provider fail-closed', $state);
