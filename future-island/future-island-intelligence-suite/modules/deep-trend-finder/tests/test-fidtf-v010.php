<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIDTF_PLUGIN_DIR')) { define('FIDTF_PLUGIN_DIR', dirname(__DIR__) . '/'); }
if (!defined('FIDTF_PLUGIN_URL')) { define('FIDTF_PLUGIN_URL', 'https://example.test/wp-content/plugins/future-island-deep-trend-finder-addon/'); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.1.0'); }
if (!defined('FI_DTF_ENABLE_DEEP_VIDEO')) { define('FI_DTF_ENABLE_DEEP_VIDEO', false); }

class WP_Error {
    private $code;
    private $message;
    private $data;
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
function current_time($type) { return '2026-05-24 09:00:00'; }
function apply_filters($tag, $value) { return $value; }
function do_action() { return null; }
function get_option($key, $default = false) { global $fidtf_options; return $fidtf_options[$key] ?? $default; }
function add_option($key, $value) { global $fidtf_options; $fidtf_options[$key] = $value; return true; }

class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function insert($table, $row) { $this->insert_id++; return true; }
    public function update($table, $row, $where) { return true; }
    public function prepare($sql, ...$args) { foreach ($args as $arg) { $sql = preg_replace('/%d/', (string) (int) $arg, $sql, 1); } return $sql; }
    public function get_results($sql, $type) { return []; }
    public function get_row($sql, $type) { return []; }
}
$wpdb = new MockWpdb();
$fidtf_options = [];

foreach ([
    'includes/class-fidtf-db.php',
    'includes/class-fidtf-settings.php',
    'includes/class-fidtf-dependency.php',
    'includes/class-fidtf-credit-service.php',
    'includes/class-fidtf-ai-planner.php',
    'includes/class-fidtf-normalizer.php',
    'includes/class-fidtf-relevance-filter.php',
    'includes/class-fidtf-memory-bridge.php',
    'includes/providers/class-fidtf-provider-apify.php',
    'includes/providers/class-fidtf-provider-ai.php',
    'includes/providers/class-fidtf-core-apify-client-adapter.php',
    'includes/providers/class-fidtf-tiktok-live-adapter.php',
    'includes/providers/class-fidtf-provider-tiktok.php',
    'includes/providers/class-fidtf-provider-instagram.php',
    'includes/providers/class-fidtf-provider-reddit.php',
    'includes/providers/class-fidtf-provider-google-trends.php',
    'includes/providers/class-fidtf-provider-google-news.php',
    'includes/class-fidtf-source-dispatcher.php',
    'includes/class-fidtf-source-job-service.php',
    'includes/class-fidtf-report-service.php',
    'includes/class-fidtf-run-service.php',
] as $file) {
    require_once dirname(__DIR__) . '/' . $file;
}

$checks = 0;
function ok($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

FIDTF_Settings::ensure_defaults();
$settings = FIDTF_Settings::get();
ok(in_array('tiktok', $settings['enabled_sources'], true), 'tiktok source enabled by default');
ok(FIDTF_Settings::source_limit('tiktok') === 50, 'tiktok default limit');
ok(FIDTF_Settings::deep_video_enabled() === false, 'deep video hard gate disabled');
ok(FIDTF_Settings::live_dispatch_enabled() === false, 'live dispatch disabled by default');

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Quiet summer travel in Spain for cultural audiences',
    'objective' => 'Find campaign angles',
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => 'verano silencioso, turismo cultural',
    'channels' => ['tiktok', 'google_trends', 'unknown']
]);
ok(count($request['channels']) === 2, 'sanitize request removes unknown channel');
ok(count($request['keywords']) === 2, 'sanitize request splits keyword string');

$plan = FIDTF_AI_Planner::build_plan($request);
ok($plan['planner_mode'] === 'local_fallback', 'planner uses honest local fallback');
ok(isset($plan['sources']['tiktok']), 'planner includes selected tiktok source');
ok(!isset($plan['sources']['unknown']), 'planner excludes unknown source');
ok(($plan['sources']['tiktok']['max_items'] ?? 0) === 50, 'planner clamps tiktok max items');

$raw = [
    'id' => 'abc123',
    'url' => 'https://example.com/video/abc123',
    'username' => 'creator',
    'desc' => 'Verano silencioso en Madrid y turismo cultural',
    'views' => 120000,
    'likes' => 8000,
    'commentCount' => 120,
    'created_at' => '2026-05-20T10:00:00Z',
];
$item = FIDTF_Normalizer::normalize('tiktok', $raw);
ok($item['external_id'] === 'abc123', 'normalizer keeps external id');
ok($item['author'] === 'creator', 'normalizer extracts author');
ok(strpos($item['caption_or_text'], 'Verano silencioso') !== false, 'normalizer extracts tiktok text');
$quality = FIDTF_Normalizer::evidence_quality($item);
ok($quality['has_transcript'] === false, 'normalizer does not claim transcript');
ok(in_array('No transcript or deep video understanding was available.', $quality['limitations'], true), 'quality states video limitation');

$score = FIDTF_Relevance_Filter::score($item, $request, $plan['sources']['tiktok']);
ok($score['score'] > 45, 'relevance score accepts matching item');
ok($score['is_relevant'] === true, 'matching item relevant');
ok(in_array('verano silencioso', $score['matched_keywords'], true), 'matched keyword recorded');

$bad = FIDTF_Normalizer::normalize('tiktok', ['id' => 'x', 'desc' => 'Unrelated generic dance clip']);
$bad_score = FIDTF_Relevance_Filter::score($bad, $request, $plan['sources']['tiktok']);
ok($bad_score['is_relevant'] === false, 'unrelated item filtered out');

$estimate = FIDTF_Credit_Service::estimate($request, array_keys($plan['sources']));
ok($estimate['estimated_credits'] > 0, 'credit estimate created');
ok($estimate['breakdown']['deep_video'] == 0.0, 'credit estimate excludes deep video when disabled');
ok(strpos($estimate['charging_policy'], 'No final source charge') !== false, 'credit policy explains failed source behavior');

$provider = new FIDTF_Provider_TikTok();
$result = $provider->dispatch($plan['sources']['tiktok'], $request);
ok(is_wp_error($result), 'provider returns WP_Error when live dispatch disabled');
ok($result->get_error_code() === 'live_dispatch_disabled', 'provider error is live dispatch disabled');

$schemas = FIDTF_DB::schema_sql();
ok(count($schemas) === 4, 'db schema has four tables');
ok(strpos(implode("\n", $schemas), 'fi_dtf_runs') !== false, 'schema includes runs table');
ok(strpos(implode("\n", $schemas), 'fi_dtf_items') !== false, 'schema includes items table');

$created = FIDTF_Run_Service::create_run($request, 12);
ok(!is_wp_error($created), 'create_run succeeds with mock db');
ok(($created['status'] ?? '') === 'planned_waiting_for_sources', 'created run waits for sources by default');
ok(($created['credit_reservation']['reserved'] ?? true) === false, 'credit reservation disabled by default');

fwrite(STDOUT, "FIDTF v0.1.0 checks passed: {$checks} / {$checks}\n");
