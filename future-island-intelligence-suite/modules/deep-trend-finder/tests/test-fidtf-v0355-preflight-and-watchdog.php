<?php
/**
 * v0.3.55 — DTF preflight + stuck-job watchdog regression tests (executed, not grep).
 *
 * Live failures locked down:
 *  1. Google News shipped as runnable but its actor was not allowlisted, so the
 *     run died at dispatch (FI-54xxxx). Now: preflight excludes the source
 *     BEFORE dispatch (skipped + reason) and the UI never offers it as live.
 *  2. A source job could sit in "running" forever (live screenshot: Reddit,
 *     0 rows). Now: a watchdog finalizes stale queued/running jobs that no
 *     refresh path can progress.
 */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIDTF_PLUGIN_DIR')) { define('FIDTF_PLUGIN_DIR', dirname(__DIR__) . '/'); }
if (!defined('FIDTF_PLUGIN_URL')) { define('FIDTF_PLUGIN_URL', 'https://example.test/wp-content/plugins/fidtf/'); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.3.55'); }
if (!defined('FI_DTF_ENABLE_DEEP_VIDEO')) { define('FI_DTF_ENABLE_DEEP_VIDEO', false); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key)); }
function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }
function sanitize_textarea_field($str) { return trim(strip_tags((string) $str)); }
function wp_strip_all_tags($str) { return strip_tags((string) $str); }
function esc_url_raw($str) { return trim((string) $str); }
function esc_html($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); }
function esc_attr($str) { return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8'); }
function __($str, $domain = null) { return $str; }
function wp_json_encode($value) { return json_encode($value); }
function apply_filters($tag, $value) { return $value; }
function do_action() { return null; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }

// Working clock: current_time must honor 'timestamp' for the watchdog.
$GLOBALS['fidtf_test_now'] = strtotime('2026-05-24 09:00:00');
function current_time($type) {
    $now = (int) $GLOBALS['fidtf_test_now'];
    return $type === 'timestamp' ? $now : date('Y-m-d H:i:s', $now);
}

function get_option($key, $default = false) { global $fidtf_options; return $fidtf_options[$key] ?? $default; }
function add_option($key, $value) { global $fidtf_options; $fidtf_options[$key] = $value; return true; }
function update_option($key, $value, $autoload = null) { global $fidtf_options; $fidtf_options[$key] = $value; return true; }
function get_transient($key) { global $fidtf_transients; return $fidtf_transients[$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { global $fidtf_transients; $fidtf_transients[$key] = $value; return true; }
function delete_transient($key) { global $fidtf_transients; unset($fidtf_transients[$key]); return true; }

/** wpdb stub whose source_jobs rows are test-controllable and update-observable. */
class FIDTF_Watchdog_Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows = [];          // job rows returned by get_results
    public $updates = [];       // every update($table,$row,$where) call
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function insert($table, $row) { $this->insert_id++; return true; }
    public function update($table, $row, $where) { $this->updates[] = ['table' => $table, 'row' => $row, 'where' => $where]; return true; }
    public function prepare($sql, ...$args) { foreach ($args as $arg) { $sql = preg_replace('/%[ds]/', (string) $arg, $sql, 1); } return $sql; }
    public function get_results($sql, $type) { return $this->rows; }
    public function get_row($sql, $type) { return $this->rows[0] ?? []; }
}
$wpdb = new FIDTF_Watchdog_Wpdb();
$fidtf_options = [];
$fidtf_transients = [];

// Core-side stubs: a guarded client (records every dispatch attempt) and a
// registry with a controllable allowlist, so the core gate is ACTIVE.
class VES_Apify_Client {
    public static $calls = [];
    public static function request($method, $url, $body = null) { self::$calls[] = ['request', $method, $url]; return new WP_Error('test_unexpected_dispatch', 'No dispatch expected in this test.'); }
    public static function fetch_run($run_id, $wait = 0) { self::$calls[] = ['fetch_run', $run_id]; return new WP_Error('test_unexpected_dispatch', 'No fetch expected.'); }
    public static function fetch_items($run_id, $limit = 20) { self::$calls[] = ['fetch_items', $run_id]; return []; }
}
class VES_Apify_Actor_Registry {
    public static $allowed = [];
    public static function normalize_slug($slug) { return strtolower(str_replace('/', '~', trim((string) $slug))); }
    public static function is_allowed_slug($slug) { return in_array(self::normalize_slug($slug), array_map([__CLASS__, 'normalize_slug'], self::$allowed), true); }
    public static function preflight_actor_slug($slug) {
        $raw = trim((string) $slug);
        if ($raw === '') { return ['ok' => false, 'reason' => 'actor_not_configured', 'detail' => 'No provider actor is configured for this source.']; }
        if (!self::is_allowed_slug($raw)) { return ['ok' => false, 'reason' => 'actor_not_allowlisted', 'detail' => 'Actor "' . $raw . '" is not registered in the server actor registry/allowlist.']; }
        return ['ok' => true, 'reason' => 'ok', 'detail' => ''];
    }
}

foreach ([
    'includes/class-fidtf-db.php',
    'includes/class-fidtf-settings.php',
    'includes/class-fidtf-dependency.php',
    'includes/class-fidtf-credit-service.php',
    'includes/class-fidtf-ai-planner.php',
    'includes/class-fidtf-normalizer.php',
    'includes/class-fidtf-relevance-filter.php',
    'includes/providers/class-fidtf-provider-apify.php',
    'includes/providers/class-fidtf-provider-ai.php',
    'includes/providers/class-fidtf-core-apify-client-adapter.php',
    'includes/providers/class-fidtf-generic-apify-live-adapter.php',
    'includes/providers/class-fidtf-tiktok-live-adapter.php',
    'includes/providers/class-fidtf-provider-tiktok.php',
    'includes/providers/class-fidtf-provider-instagram.php',
    'includes/providers/class-fidtf-provider-reddit.php',
    'includes/providers/class-fidtf-provider-google-trends.php',
    'includes/providers/class-fidtf-provider-google-news.php',
    'includes/class-fidtf-source-dispatcher.php',
    'includes/class-fidtf-source-job-service.php',
] as $file) {
    require_once dirname(__DIR__) . '/' . $file;
}

$checks = 0; $failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

FIDTF_Settings::ensure_defaults();
ok(FIDTF_Core_Apify_Client_Adapter::available(), 'core client gate is active in this scenario');

// ── 1. actor preflight blocks a not-allowlisted source ──────────────────────
VES_Apify_Actor_Registry::$allowed = ['clockworks/tiktok-scraper', 'apify/instagram-scraper', 'trudax/reddit-scraper-lite', 'data_xplorer/google-trends-fast-scraper'];
// (deliberately NOT the google_news default — the live failure scenario)

$pf = FIDTF_Settings::actor_preflight('google_news');
ok($pf['ok'] === false && $pf['reason'] === 'actor_not_allowlisted', 'actor_preflight: google_news blocked when its actor is not allowlisted');
$pf = FIDTF_Settings::actor_preflight('instagram');
ok($pf['ok'] === true, 'actor_preflight: instagram passes with an allowlisted actor');
ok(FIDTF_Settings::source_live_ready('google_news') === false, 'source_live_ready: blocked source is never live-ready');

$preflight = FIDTF_Settings::live_preflight_status(['instagram', 'google_news']);
ok(in_array('google_news', $preflight['planned_only_sources'], true), 'live preflight: blocked source is planned-only, not live');
ok(!in_array('google_news', $preflight['live_sources'], true), 'live preflight: blocked source is not offered as live');
ok(isset($preflight['unavailable_sources']['google_news']['reason']) && $preflight['unavailable_sources']['google_news']['reason'] === 'actor_not_allowlisted', 'live preflight: unavailable_sources carries the real reason');
ok(in_array('google_news:actor_not_allowlisted', $preflight['blocking_reasons'], true), 'live preflight: blocking reason names the source and cause');

// ── 2. dispatch loop skips the blocked source BEFORE any provider call ──────
VES_Apify_Client::$calls = [];
$jobs = [[ 'id' => 11, 'source_key' => 'google_news' ]];
$plan = ['planner_mode' => 'local_fallback', 'sources' => ['google_news' => ['queries' => ['world cup viewing spain']]]];
$results = FIDTF_Source_Job_Service::maybe_dispatch_jobs(7, $jobs, $plan, ['user_brief' => 'World Cup Spain', 'market' => 'Spain']);
ok(count($results) === 1, 'dispatch loop returns a result for the blocked job');
ok(($results[0]['job_status'] ?? '') === 'skipped', 'blocked job is finalized as skipped (no stuck state)');
ok(($results[0]['error_code'] ?? '') === 'source_actor_not_allowlisted', 'skip carries the allowlist error code');
ok(empty($results[0]['retryable']), 'allowlist skip is not retryable');
ok(strpos((string) ($results[0]['message'] ?? ''), 'actor registry') !== false, 'skip message tells the admin what to fix');
ok(empty(VES_Apify_Client::$calls), 'NO provider dispatch was attempted for the blocked source');
$skip_update = null;
foreach ($wpdb->updates as $u) { if (($u['where']['id'] ?? 0) === 11 && isset($u['row']['status'])) { $skip_update = $u['row']; } }
ok(is_array($skip_update) && ($skip_update['status'] ?? '') === 'skipped' && !empty($skip_update['completed_at']), 'job row is closed (skipped + completed_at) so the UI cannot spin');

// The generic adapter also self-protects (defense in depth for refresh paths).
$adapter = new FIDTF_Generic_Apify_Live_Adapter('google_news');
$start = $adapter->start(['source_key' => 'google_news', 'source_plan' => ['queries' => ['x']], 'request_context' => [], 'limits' => ['max_items' => 5]]);
ok(is_wp_error($start) && $start->get_error_code() === 'source_actor_not_allowlisted', 'generic adapter start() preflights the actor');
$start_data = (array) $start->get_error_data();
ok(empty($start_data['request_attempted']), 'adapter preflight failure marks request_attempted=false');

// An allowlisted source must NOT be skipped by preflight (it may still wait on
// live-dispatch toggles, which is a different, honest state).
$wpdb->updates = [];
$results = FIDTF_Source_Job_Service::maybe_dispatch_jobs(7, [['id' => 12, 'source_key' => 'instagram']], ['planner_mode' => 'local_fallback', 'sources' => ['instagram' => ['queries' => ['world cup']]]], ['user_brief' => 'World Cup Spain']);
ok(($results[0]['error_code'] ?? '') !== 'source_actor_not_allowlisted', 'allowlisted source is not blocked by preflight');

// ── 3. watchdog finalizes stuck queued/running jobs ─────────────────────────
$now = (int) $GLOBALS['fidtf_test_now'];
ok(FIDTF_Source_Job_Service::job_is_stale(['status' => 'running', 'updated_at' => date('Y-m-d H:i:s', $now - 3600)], $now) === true, 'job_is_stale: running + 1h untouched -> stale');
ok(FIDTF_Source_Job_Service::job_is_stale(['status' => 'running', 'updated_at' => date('Y-m-d H:i:s', $now - 60)], $now) === false, 'job_is_stale: recently touched running job is not stale');
ok(FIDTF_Source_Job_Service::job_is_stale(['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s', $now - 7200)], $now) === false, 'job_is_stale: completed job is never stale');
ok(FIDTF_Source_Job_Service::job_is_stale(['status' => 'waiting_for_provider', 'updated_at' => date('Y-m-d H:i:s', $now - 7200)], $now) === false, 'job_is_stale: config-paused job is not watchdogged');

// Live screenshot case: Reddit pinned at running, 0 rows, no provider run id,
// source not refreshable -> watchdog must close it on the next poll.
$wpdb->rows = [[
    'id' => 21,
    'run_id' => 7,
    'source_key' => 'reddit',
    'status' => 'running',
    'provider_run_id' => '',
    'raw_count' => 0,
    'normalized_count' => 0,
    'relevant_count' => 0,
    'updated_at' => date('Y-m-d H:i:s', $now - 3600),
]];
$wpdb->updates = [];
$results = FIDTF_Source_Job_Service::maybe_refresh_running_jobs(7, []);
ok(count($results) === 1, 'watchdog produced a result for the stuck job');
ok(($results[0]['job_status'] ?? '') === 'failed' && ($results[0]['error_code'] ?? '') === 'provider_timeout_stale', 'stuck running job is finalized as provider_timeout_stale');
ok(($results[0]['retryable'] ?? false) === true, 'watchdog timeout is retryable (a fresh retry CAN succeed)');
$watchdog_update = null;
foreach ($wpdb->updates as $u) { if (($u['where']['id'] ?? 0) === 21 && isset($u['row']['status'])) { $watchdog_update = $u['row']; } }
ok(is_array($watchdog_update) && ($watchdog_update['status'] ?? '') === 'failed' && !empty($watchdog_update['completed_at']), 'stuck job row is closed with completed_at (spinner cannot persist)');

// A fresh running job (not stale) without a provider run id must be left alone.
$wpdb->rows[0]['updated_at'] = date('Y-m-d H:i:s', $now - 60);
$wpdb->updates = [];
$results = FIDTF_Source_Job_Service::maybe_refresh_running_jobs(7, []);
ok(count($results) === 0 && count($wpdb->updates) === 0, 'fresh running job is not touched by the watchdog');

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.3.55 DTF preflight/watchdog checks: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.3.55 DTF preflight/watchdog checks passed: {$checks} / {$checks}\n");
exit(0);
