<?php
/**
 * v0.9.30.21 — minimal WordPress shim layer for REAL functional tests.
 *
 * The pre-existing tests/*.php are static string-grep checks; they never execute
 * plugin code. This bootstrap provides just enough of the WordPress API to load
 * and run targeted classes (e.g. VES_Apify_Client) against an injectable HTTP
 * mock queue, so dispatch/classification logic is verified by execution, not grep.
 *
 * Usage:
 *   require __DIR__ . '/bootstrap-wp-shims.php';
 *   VES_Test_HTTP::$queue[] = ['code' => 201, 'body' => ['data' => ['id' => 'RUN1']]];
 *   $r = VES_Apify_Client::request('POST', 'https://api.apify.com/v2/acts/x~y/runs', ['k'=>'v']);
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }

/** Captures outbound HTTP and serves queued mock responses. */
final class VES_Test_HTTP {
    /** @var array<int,array> queued responses: ['code'=>int,'body'=>array|string] or ['wp_error'=>['code'=>..,'message'=>..]] */
    public static $queue = [];
    /** @var array<int,array> every request made: ['method'=>..,'url'=>..,'args'=>..] */
    public static $calls = [];
    public static function reset() { self::$queue = []; self::$calls = []; }
    public static function next(array $request) {
        self::$calls[] = $request;
        if (empty(self::$queue)) {
            return new WP_Error('test_no_mock', 'No mock HTTP response queued for: ' . $request['url']);
        }
        $resp = array_shift(self::$queue);
        if (isset($resp['wp_error'])) {
            return new WP_Error($resp['wp_error']['code'] ?? 'test_error', $resp['wp_error']['message'] ?? 'mock error');
        }
        $body = $resp['body'] ?? [];
        return [
            '__mock' => true,
            'code' => (int) ($resp['code'] ?? 200),
            'body' => is_string($body) ? $body : json_encode($body),
        ];
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        private $code = '';
        public function __construct($code = '', $message = '', $data = '') {
            if ($code === '') { return; }
            $this->code = $code;
            $this->errors[$code][] = $message;
            if ($data !== '') { $this->error_data[$code] = $data; }
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { $m = $this->errors[$this->code] ?? ['']; return $m[0] ?? ''; }
        public function get_error_data($code = '') { $code = $code ?: $this->code; return $this->error_data[$code] ?? null; }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) { return VES_Test_HTTP::next(['method' => $args['method'] ?? 'GET', 'url' => $url, 'args' => $args]); }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) { $args['method'] = 'POST'; return wp_remote_request($url, $args); }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) { $args['method'] = 'GET'; return wp_remote_request($url, $args); }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return is_array($r) ? (int) ($r['code'] ?? 0) : 0; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return is_array($r) ? (string) ($r['body'] ?? '') : ''; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        $sep = strpos($url, '?') === false ? '?' : '&';
        return $url . $sep . http_build_query($args);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0) { return json_encode($data, $options); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { $s = (string) $s; $s = preg_replace('/[\r\n\t]+/', ' ', $s); return trim(preg_replace('/\s{2,}/', ' ', strip_tags($s))); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = PHP_INT_MAX) { return random_int($min, $max); }
}

// ---------------------------------------------------------------------------
// i18n + escaping helpers.
// Templates (e.g. the Deep Trend Finder report/shortcode views) and several
// classes render through WordPress' translation/escaping API. The shims are
// pass-through/deterministic so tests can include those templates without a
// real WordPress runtime. Translation is identity; escaping uses
// htmlspecialchars with ENT_QUOTES to mirror esc_html()/esc_attr() closely
// enough for string-grep and render-smoke assertions.
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return (string) $text; }
}
if (!function_exists('_e')) {
    function _e($text, $domain = 'default') { echo (string) $text; }
}
if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') { return (string) $text; }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') { return (int) $number === 1 ? (string) $single : (string) $plural; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return esc_html(__($text, $domain)); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') { return esc_attr(__($text, $domain)); }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') { echo esc_html(__($text, $domain)); }
}
if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default') { echo esc_attr(__($text, $domain)); }
}
if (!function_exists('esc_html_x')) {
    function esc_html_x($text, $context, $domain = 'default') { return esc_html(_x($text, $context, $domain)); }
}
if (!function_exists('esc_attr_x')) {
    function esc_attr_x($text, $context, $domain = 'default') { return esc_attr(_x($text, $context, $domain)); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        $r = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
        if ($echo) { echo $r; }
        return $r;
    }
}
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        $r = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
        if ($echo) { echo $r; }
        return $r;
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($s) { $s = (string) $s; $s = strip_tags($s); return trim($s); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s, $remove_breaks = false) {
        $s = strip_tags((string) $s);
        if ($remove_breaks) { $s = preg_replace('/[\r\n\t ]+/', ' ', $s); }
        return trim($s);
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['__ves_test_is_admin']); }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return !empty($GLOBALS['__ves_test_logged_in']); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return (int) ($GLOBALS['__ves_test_user_id'] ?? 0); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('error_log')) {
    // built-in exists; placeholder for completeness
}

// Transient + option storage (in-memory).
$GLOBALS['__ves_transients'] = [];
$GLOBALS['__ves_options'] = [];
if (!function_exists('get_transient')) {
    function get_transient($k) { return $GLOBALS['__ves_transients'][$k] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($k, $v, $ttl = 0) { $GLOBALS['__ves_transients'][$k] = $v; return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($k) { unset($GLOBALS['__ves_transients'][$k]); return true; }
}
if (!function_exists('get_option')) {
    function get_option($k, $default = false) { return $GLOBALS['__ves_options'][$k] ?? $default; }
}
if (!function_exists('update_option')) {
    function update_option($k, $v, $autoload = null) { $GLOBALS['__ves_options'][$k] = $v; return true; }
}
if (!function_exists('delete_option')) {
    function delete_option($k) { unset($GLOBALS['__ves_options'][$k]); return true; }
}

/** Minimal VES_Config stub: token + limits are test-controllable. */
if (!class_exists('VES_Config')) {
    final class VES_Config {
        public static $token = '';
        public static function get_token() { return trim((string) self::$token); }
        public static function apify_max_active_runs() { return 6; }
        public static function apify_max_heavy_runs() { return 2; }
        public static function apify_max_active_runs_per_user() { return 2; }
        public static function max_external_provider_cost_per_run() { return 5.0; }
        public static function apify_cost_warning_threshold_usd() { return 1.0; }
        public static function trend_youtube_trending_enabled() { return false; }
        public static function trend_twitter_trends_enabled() { return false; }
        public static function trend_max_twitter_trend_items() { return 0; }
        public static function trend_max_youtube_trending_cost() { return 0; }
        public static function max_items() { return 150; }
        public static function admin_error_details() { return false; }
    }
}

/** Collects diagnostics so tests can assert what was recorded (and that it is leak-free). */
if (!class_exists('VES_Admin')) {
    final class VES_Admin {
        public static $diagnostics = [];
        public static function record_diagnostic($source, $message, $context = []) {
            self::$diagnostics[] = ['source' => $source, 'message' => $message, 'context' => $context];
        }
    }
}

/** Tiny assertion helpers shared by the v0.9.30.21 functional tests. */
function ves_test_ok($label, $cond, &$state) {
    $state['total']++;
    if ($cond) { $state['pass']++; echo "  PASS  $label\n"; }
    else { $state['fail'][] = $label; echo "  FAIL  $label\n"; }
}
function ves_test_finish($title, $state) {
    echo "\n$title: {$state['pass']}/{$state['total']} passed\n";
    if (!empty($state['fail'])) { fwrite(STDERR, "FAILURES:\n - " . implode("\n - ", $state['fail']) . "\n"); exit(1); }
}
