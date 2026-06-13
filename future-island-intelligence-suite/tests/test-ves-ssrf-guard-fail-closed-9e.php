<?php
/**
 * Deep review — public-content fetch SSRF guard is FAIL-CLOSED.
 *
 * ves_remote_text_asset() fetches subtitle/transcript URLs from scraped items.
 * Proves:
 *  1. when the ves_is_public_http_url validator is NOT loaded, the fetch is
 *     REFUSED (returns '' with ZERO HTTP calls) — guard absence never fails open
 *  2. when the validator rejects a URL (private/loopback shapes), zero HTTP
 *  3. when the validator approves, the fetch proceeds (one HTTP call)
 *  4. invalid URLs are refused before any guard logic
 *
 * Run: php tests/test-ves-ssrf-guard-fail-closed-9e.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

function esc_url_raw($s) { return trim((string) $s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function get_option($k, $d = false) { return $d; }
function update_option($k, $v, $a = null) { return true; }
function apply_filters($t, $v) { return $v; }
function current_time($t = 'mysql', $g = 0) { return gmdate('Y-m-d H:i:s'); }
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t) { return $t instanceof WP_Error; }

$GLOBALS['__http_calls'] = [];
function wp_remote_get($url, $args = []) {
    $GLOBALS['__http_calls'][] = $url;
    return ['response' => ['code' => 200], 'body' => "WEBVTT\n"];
}
function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 0); }
function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); }
function wp_remote_retrieve_header($r, $h) { return 'text/vtt'; }
// Downstream parsers used only on the success path — stubbed pass-throughs.
function ves_extract_text_from_plain_subtitles($body) { return trim((string) $body); }
function ves_extract_text_from_json_payload($d) { return 'json'; }
function ves_extract_text_from_xml_subtitles($body) { return 'xml'; }

// Extract ONLY ves_remote_text_asset from analysis.php so the test stays focused
// (the full file pulls in the whole legacy analysis surface).
$src = (string) file_get_contents(dirname(__DIR__) . '/includes/analysis.php');
$start = strpos($src, "if (!function_exists('ves_remote_text_asset'))");
$depth = 0; $end = $start; $found_open = false;
for ($i = $start; $i < strlen($src); $i++) {
    if ($src[$i] === '{') { $depth++; $found_open = true; }
    if ($src[$i] === '}') { $depth--; if ($found_open && $depth === 0) { $end = $i + 1; break; } }
}
eval(substr($src, $start, $end - $start));

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$ok(function_exists('ves_remote_text_asset'), 'ves_remote_text_asset extracted and defined');

// ── 1. Validator missing => FAIL CLOSED, zero HTTP ───────────────────────────
$GLOBALS['__http_calls'] = [];
$res = ves_remote_text_asset('https://cdn.example.com/subtitles.vtt');
$ok($res === '' && count($GLOBALS['__http_calls']) === 0, 'missing validator REFUSES the fetch (fail-closed, zero HTTP)');

// ── 2. Validator rejects => zero HTTP ────────────────────────────────────────
if (!function_exists('ves_is_public_http_url')) {
    function ves_is_public_http_url($url) { return strpos((string) $url, 'cdn.example.com') !== false; }
}
$GLOBALS['__http_calls'] = [];
$res = ves_remote_text_asset('http://169.254.169.254/latest/meta-data');
$ok($res === '' && count($GLOBALS['__http_calls']) === 0, 'validator-rejected URL makes zero HTTP calls');

// ── 3. Validator approves => fetch proceeds ──────────────────────────────────
$GLOBALS['__http_calls'] = [];
$res = ves_remote_text_asset('https://cdn.example.com/subtitles.vtt');
$ok($res !== '' && count($GLOBALS['__http_calls']) === 1, 'approved public URL fetches (exactly one HTTP call)');

// ── 4. Invalid URL refused before everything ─────────────────────────────────
$GLOBALS['__http_calls'] = [];
$res = ves_remote_text_asset('not-a-url');
$ok($res === '' && count($GLOBALS['__http_calls']) === 0, 'invalid URL refused with zero HTTP calls');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
