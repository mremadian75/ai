<?php
/**
 * Phase 0 unit tests — VES_Health_Check + VES_Log.
 *
 * Verifies the health check never leaks the raw OpenAI key, and the logger is
 * debug-gated, scrubs secrets, and drops raw prompts. No network/API calls.
 *
 * Run: php tests/test-ves-health-and-log.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.4.1'); }
if (!defined('VES_PLUGIN_VERSION')) { define('VES_PLUGIN_VERSION', '1.4.1'); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.3.53'); }

if (!class_exists('WP_Error')) {
    class WP_Error { public $code; public $message; public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $o = 0) { return json_encode($d, $o); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-06-06 12:00:00'; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
$GLOBALS['__opts'] = ['ves_apify_token' => '']; // apify deliberately unconfigured

// Minimal VES_Config with a REAL-looking secret so we can prove it is never emitted.
if (!class_exists('VES_Config')) {
    final class VES_Config { public static function get_openai_api_key() { return 'sk-REALSECRET1234567890'; } }
}
class MockWpdb { public $prefix = 'wp_'; public function get_var($sql) { return null; } public function prepare($sql, $a = []) { return $sql; } }
$GLOBALS['wpdb'] = new MockWpdb();

require_once dirname(__DIR__) . '/includes/class-ves-ai-usage-tracker.php'; // for scrub_secrets reuse
require_once dirname(__DIR__) . '/includes/class-ves-health-check.php';
require_once dirname(__DIR__) . '/includes/class-ves-log.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// ── VES_Health_Check ─────────────────────────────────────────────────────────
$health = VES_Health_Check::run();
$ok(is_array($health) && in_array($health['status'] ?? '', ['ok', 'warning', 'error'], true), 'health run() returns a valid overall status');
$ok(!empty($health['checks']) && is_array($health['checks']), 'health run() returns a non-empty checks array');

$by_key = [];
foreach ($health['checks'] as $c) { $by_key[$c['key']] = $c; }
$ok(isset($by_key['plugin_version']) && isset($by_key['openai_api_key']) && isset($by_key['billing_system']), 'health includes plugin_version, openai_api_key, billing_system checks');
$ok(($by_key['openai_api_key']['details']['configured'] ?? null) === true, 'openai_api_key check reports configured=true');

$serialized = json_encode($health);
$ok(strpos($serialized, 'REALSECRET') === false, 'health output NEVER contains the raw OpenAI key (masked only)');
$ok(strpos((string) ($by_key['openai_api_key']['details']['masked'] ?? ''), 'chars)') !== false, 'openai key is reported as a masked hint, not the value');

// ── VES_Log gating ───────────────────────────────────────────────────────────
$GLOBALS['__opts']['ves_debug_logging'] = false;
$ok(VES_Log::debug_enabled() === false, 'logger is disabled by default (no debug)');
$GLOBALS['__opts']['ves_debug_logging'] = true;
$ok(VES_Log::debug_enabled() === true, 'logger enables when ves_debug_logging option is on');

// ── VES_Log redaction + prompt-drop (capture error_log) ──────────────────────
$tmp = tempnam(sys_get_temp_dir(), 'veslog');
$old = ini_get('error_log');
ini_set('error_log', $tmp);
VES_Log::info('ai_usage', 'call with secret sk-ABCDEFGH12345678 inside', [
    'model' => 'gpt-4.1-mini', 'input_tokens' => 5, 'provider' => 'openai',
    'prompt' => 'THIS RAW PROMPT MUST NOT BE LOGGED',
    'error_code' => 'none',
]);
ini_set('error_log', $old ?: '');
$logged = (string) @file_get_contents($tmp);
@unlink($tmp);

$ok(strpos($logged, '[VES]') !== false, 'logger writes a structured [VES] line when enabled');
$ok(strpos($logged, 'sk-ABCDEFGH12345678') === false, 'logger redacts inline API secrets');
$ok(strpos($logged, 'THIS RAW PROMPT MUST NOT BE LOGGED') === false, 'logger never logs raw prompt content');
$ok(strpos($logged, 'gpt-4.1-mini') !== false && strpos($logged, 'input_tokens') !== false, 'logger keeps whitelisted cost-spine metadata');

echo "\nVES health + log tests: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
