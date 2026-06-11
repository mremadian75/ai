<?php
/**
 * v0.9.30.21 — REAL functional test: executes VES_Apify_Client against a mocked
 * HTTP layer (no live network). Proves the empty-token guard, Bearer header,
 * dispatch success, and error classification by EXECUTION, not string-grep.
 */
require __DIR__ . '/bootstrap-wp-shims.php';

// Phase 9A made dispatch FAIL CLOSED (allowlist + hard charge ceiling). This test
// is about HTTP mechanics/classification, so satisfy the gates with a permissive
// registry stub and a ceiling on the run URL; the gates themselves are covered by
// test-ves-provider-fail-closed-9a.php and test-ves-provider-safety-hardening.php.
final class VES_Apify_Actor_Registry {
    public static function normalize_slug($slug) { return strtolower(str_replace('/', '~', trim((string) $slug))); }
    public static function is_allowed_slug($slug) { return self::normalize_slug($slug) === 'apidojo~tiktok-scraper'; }
    public static function is_zero_cost_slug($slug) { return false; }
}

require dirname(__DIR__) . '/includes/class-ves-apify-client.php';

$state = ['total' => 0, 'pass' => 0, 'fail' => []];
$url = 'https://api.apify.com/v2/acts/apidojo~tiktok-scraper/runs?maxTotalChargeUsd=3';

// --- T1: empty token guard fires BEFORE any HTTP call -----------------------
VES_Test_HTTP::reset();
VES_Config::$token = '';
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('empty token returns WP_Error', is_wp_error($r), $state);
ves_test_ok('empty token classified as missing_backend_token', is_wp_error($r) && $r->get_error_code() === 'missing_backend_token', $state);
ves_test_ok('empty token makes ZERO outbound HTTP calls', count(VES_Test_HTTP::$calls) === 0, $state);
ves_test_ok('empty-token error message leaks no token/slug', is_wp_error($r) && strpos($r->get_error_message(), 'apify_api_') === false && strpos($r->get_error_message(), 'apidojo') === false, $state);

// --- T2: valid token + 201 → success array, correct Bearer header ----------
VES_Test_HTTP::reset();
VES_Config::$token = 'apify_api_TESTTOKEN1234567890';
VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN123', 'status' => 'READY']]];
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('valid dispatch returns array (not WP_Error)', is_array($r) && !is_wp_error($r), $state);
ves_test_ok('dispatch returns HTTP 201', is_array($r) && (int) $r['code'] === 201, $state);
ves_test_ok('run id parsed from body', is_array($r) && ($r['body']['data']['id'] ?? '') === 'RUN123', $state);
$call = VES_Test_HTTP::$calls[0] ?? [];
$auth = $call['args']['headers']['Authorization'] ?? '';
ves_test_ok('Authorization is Bearer <token>', $auth === 'Bearer apify_api_TESTTOKEN1234567890', $state);
ves_test_ok('request body is JSON-encoded', isset($call['args']['body']) && json_decode($call['args']['body'], true) === ['foo' => 'bar'], $state);

// --- T3: 401 → provider HTTP error, body preserved in diagnostics, message safe
VES_Test_HTTP::reset();
VES_Admin::$diagnostics = [];
VES_Test_HTTP::$queue[] = ['code' => 401, 'body' => ['error' => ['message' => 'token apify_api_SECRETLEAK is invalid']]];
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('401 returns WP_Error', is_wp_error($r), $state);
ves_test_ok('401 public message does not leak raw token', is_wp_error($r) && strpos($r->get_error_message(), 'apify_api_SECRETLEAK') === false, $state);
$diag_text = json_encode(VES_Admin::$diagnostics);
ves_test_ok('401 upstream body recorded in diagnostics (scrubbed)', strpos($diag_text, 'apify_http') !== false && strpos($diag_text, 'apify_api_SECRETLEAK') === false, $state);

// --- T4: 429 twice → retried then rate-limited classification --------------
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 429, 'body' => ['error' => ['message' => 'rate limited']]];
VES_Test_HTTP::$queue[] = ['code' => 429, 'body' => ['error' => ['message' => 'rate limited']]];
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('429 retried (2 HTTP calls made)', count(VES_Test_HTTP::$calls) === 2, $state);
ves_test_ok('429 classified as apify_rate_limited', is_wp_error($r) && $r->get_error_code() === 'apify_rate_limited', $state);

// --- T5: paid-actor rental message → rental-required classification --------
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 403, 'body' => ['error' => ['message' => 'You need to rent a paid actor to run it.']]];
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('rental-required classified', is_wp_error($r) && $r->get_error_code() === 'apify_actor_rental_required', $state);

// --- T6: 200 but non-JSON body → invalid JSON classification ---------------
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['code' => 200, 'body' => '<html>not json</html>'];
$r = VES_Apify_Client::request('GET', $url);
ves_test_ok('non-JSON 200 classified as ves_invalid_json', is_wp_error($r) && $r->get_error_code() === 'ves_invalid_json', $state);

// --- T7: transport failure (WP_Error from HTTP) → safe transport error -----
VES_Test_HTTP::reset();
VES_Test_HTTP::$queue[] = ['wp_error' => ['code' => 'http_request_failed', 'message' => 'cURL error 28: timeout']];
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('transport failure returns apify_transport', is_wp_error($r) && $r->get_error_code() === 'apify_transport', $state);

// --- T8: whitespace-only token also blocked --------------------------------
VES_Test_HTTP::reset();
VES_Config::$token = "   \t  ";
$r = VES_Apify_Client::request('POST', $url, ['foo' => 'bar']);
ves_test_ok('whitespace token blocked pre-dispatch', is_wp_error($r) && $r->get_error_code() === 'missing_backend_token' && count(VES_Test_HTTP::$calls) === 0, $state);

ves_test_finish('v0.9.30.21 Apify client functional test', $state);
