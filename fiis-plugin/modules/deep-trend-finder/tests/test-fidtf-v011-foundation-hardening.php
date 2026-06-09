<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('FIDTF_PLUGIN_DIR')) { define('FIDTF_PLUGIN_DIR', dirname(__DIR__) . '/'); }
if (!defined('FIDTF_PLUGIN_URL')) { define('FIDTF_PLUGIN_URL', 'https://example.test/wp-content/plugins/future-island-deep-trend-finder-addon/'); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.1.1-foundation-hardening'); }
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
function esc_html__($str, $domain = null) { return $str; }
function esc_attr__($str, $domain = null) { return $str; }
function __($str, $domain = null) { return $str; }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type) { return '2026-05-24 09:00:00'; }
function get_current_user_id() { global $fidtf_current_user_id; return (int) $fidtf_current_user_id; }
function current_user_can($cap) { global $fidtf_is_admin; return !empty($fidtf_is_admin) && $cap === 'manage_options'; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function apply_filters($tag, $value) { return $value; }
function do_action($tag) { global $fidtf_memory_count; if ($tag === 'fidtf_report_ready_for_memory') { $fidtf_memory_count++; } }
function get_option($key, $default = false) { global $fidtf_options; return $fidtf_options[$key] ?? $default; }
function add_option($key, $value) { global $fidtf_options; $fidtf_options[$key] = $value; return true; }

class VES_Workspace_Context {
    public static function current_workspace_id() { global $fidtf_workspace_id; return (int) $fidtf_workspace_id; }
}

class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $tables = [];
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function insert($table, $row) {
        $key = $this->key($table);
        $this->insert_id++;
        $row['id'] = $this->insert_id;
        $this->tables[$key][$this->insert_id] = $row;
        return true;
    }
    public function update($table, $row, $where) {
        $key = $this->key($table);
        $id = (int) ($where['id'] ?? 0);
        if ($id > 0 && isset($this->tables[$key][$id])) {
            $this->tables[$key][$id] = array_merge($this->tables[$key][$id], $row);
            return true;
        }
        return false;
    }
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $next_d = strpos($sql, '%d');
            $next_s = strpos($sql, '%s');
            if ($next_d === false && $next_s === false) { break; }
            if ($next_s === false || ($next_d !== false && $next_d < $next_s)) {
                $sql = preg_replace('/%d/', (string) (int) $arg, $sql, 1);
            } else {
                $sql = preg_replace('/%s/', "'" . str_replace("'", "''", (string) $arg) . "'", $sql, 1);
            }
        }
        return $sql;
    }
    public function get_row($sql, $type) {
        if (preg_match('/FROM wp_fi_dtf_runs WHERE id = (\d+)/', $sql, $m)) {
            return $this->tables['runs'][(int) $m[1]] ?? [];
        }
        if (preg_match('/FROM wp_fi_dtf_source_jobs WHERE id = (\d+)/', $sql, $m)) {
            return $this->tables['source_jobs'][(int) $m[1]] ?? [];
        }
        if (preg_match('/FROM wp_fi_dtf_reports WHERE run_id = (\d+)/', $sql, $m)) {
            $run_id = (int) $m[1];
            $rows = array_values(array_filter($this->tables['reports'] ?? [], function($row) use ($run_id) {
                return (int) ($row['run_id'] ?? 0) === $run_id && ($row['report_type'] ?? '') === 'final_synthesis';
            }));
            usort($rows, function($a, $b) { return ($b['id'] ?? 0) <=> ($a['id'] ?? 0); });
            return $rows[0] ?? [];
        }
        return [];
    }
    public function get_results($sql, $type) {
        if (preg_match('/FROM wp_fi_dtf_source_jobs WHERE run_id = (\d+)/', $sql, $m)) {
            $run_id = (int) $m[1];
            $rows = array_values(array_filter($this->tables['source_jobs'] ?? [], function($row) use ($run_id) { return (int) ($row['run_id'] ?? 0) === $run_id; }));
            usort($rows, function($a, $b) { return ($a['id'] ?? 0) <=> ($b['id'] ?? 0); });
            return $rows;
        }
        if (preg_match('/FROM wp_fi_dtf_items WHERE run_id = (\d+)/', $sql, $m)) {
            $run_id = (int) $m[1];
            $rows = array_values(array_filter($this->tables['items'] ?? [], function($row) use ($run_id, $sql) {
                if ((int) ($row['run_id'] ?? 0) !== $run_id) { return false; }
                if (strpos($sql, 'source_key =') !== false && preg_match("/source_key = '([^']+)'/", $sql, $sm) && ($row['source_key'] ?? '') !== $sm[1]) { return false; }
                return true;
            }));
            usort($rows, function($a, $b) { return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0); });
            return $rows;
        }
        return [];
    }
    private function key($table) {
        if (strpos($table, 'fi_dtf_source_jobs') !== false) { return 'source_jobs'; }
        if (strpos($table, 'fi_dtf_runs') !== false) { return 'runs'; }
        if (strpos($table, 'fi_dtf_items') !== false) { return 'items'; }
        if (strpos($table, 'fi_dtf_reports') !== false) { return 'reports'; }
        return $table;
    }
}

$wpdb = new MockWpdb();
$fidtf_options = [];
$fidtf_current_user_id = 12;
$fidtf_workspace_id = 77;
$fidtf_is_admin = false;
$fidtf_memory_count = 0;

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
$request = [
    'user_brief' => 'Verano silencioso y turismo cultural en Madrid',
    'objective' => 'Find campaign angles',
    'market' => 'Madrid Spain',
    'language' => 'es',
    'keywords' => ['verano silencioso', 'turismo cultural'],
    'channels' => ['tiktok', 'instagram']
];

$created = FIDTF_Run_Service::create_run($request, 12);
ok(!is_wp_error($created), 'run created');
$run_id = (int) $created['run_id'];
$jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
ok(count($jobs) === 2, 'source jobs created independently');
$first_job_id = (int) $jobs[0]['id'];
$first_job_source = $jobs[0]['source_key'];

$fidtf_current_user_id = 99;
$fidtf_workspace_id = 88;
ok(FIDTF_Run_Service::current_user_can_access_run($run_id, 99) === false, 'logged-in user cannot access another user run');
$forbidden = FIDTF_Run_Service::get_run_for_user($run_id, 99);
ok(is_wp_error($forbidden) && $forbidden->get_error_code() === 'fidtf_run_forbidden', 'get_run_for_user returns 403 error');
$fidtf_is_admin = true;
ok(FIDTF_Run_Service::current_user_can_access_run($run_id, 99) === true, 'admin can access any run');
$fidtf_is_admin = false;
$fidtf_current_user_id = 13;
$fidtf_workspace_id = 77;
ok(FIDTF_Run_Service::current_user_can_access_run($run_id, 13) === true, 'same workspace can access run');
$fidtf_current_user_id = 12;

$bad_job = FIDTF_Run_Service::ingest_source_results($run_id, 9999, $first_job_source, [['id' => 'x']]);
ok(is_wp_error($bad_job) && $bad_job->get_error_code() === 'fidtf_invalid_source_job_id', 'ingest rejects source_job_id not belonging to run');
$wrong_source = $first_job_source === 'tiktok' ? 'instagram' : 'tiktok';
$bad_source = FIDTF_Run_Service::ingest_source_results($run_id, $first_job_id, $wrong_source, [['id' => 'x']]);
ok(is_wp_error($bad_source) && $bad_source->get_error_code() === 'fidtf_source_mismatch', 'ingest rejects source mismatch');
$manual_nonadmin = FIDTF_Run_Service::ingest_source_results($run_id, 0, $first_job_source, [['id' => 'x']]);
ok(is_wp_error($manual_nonadmin) && $manual_nonadmin->get_error_code() === 'fidtf_manual_ingest_admin_only', 'manual ingest without source job remains admin-only');

$raw = [
    ['id' => 'abc123', 'url' => 'https://example.com/abc123', 'username' => 'creator', 'desc' => 'Verano silencioso en Madrid para turismo cultural', 'playCount' => 120000, 'diggCount' => 8000, 'collectCount' => 300, 'commentCount' => 120, 'createTimeISO' => '2026-05-20T10:00:00Z'],
    ['id' => 'abc123', 'url' => 'https://example.com/abc123', 'username' => 'creator', 'desc' => 'Verano silencioso en Madrid para turismo cultural', 'playCount' => 90000, 'diggCount' => 1000],
    ['id' => 'noise1', 'url' => 'https://example.com/noise1', 'desc' => 'Generic dance clip unrelated', 'playCount' => 50]
];
$ingested = FIDTF_Run_Service::ingest_source_results($run_id, $first_job_id, $first_job_source, $raw);
ok(!is_wp_error($ingested), 'valid ingest succeeds');
ok($ingested['raw_count'] === 3, 'raw count retained');
ok($ingested['deduped_count'] === 2, 'dedupe applied before storage');
ok($ingested['relevant_count'] === 1, 'only one relevant item remains after filtering');
ok(count($wpdb->tables['items'] ?? []) === 1, 'only relevant item stored');
$item_row = array_values($wpdb->tables['items'])[0];
$item = json_decode($item_row['normalized_json'], true);
ok(($item['metrics']['views'] ?? 0) == 120000, 'playCount mapped to canonical views');
ok(($item['metrics']['likes'] ?? 0) == 8000, 'diggCount mapped to canonical likes');
ok(($item['metrics']['saves'] ?? 0) == 300, 'collectCount mapped to canonical saves');
ok(($item['evidence_quality']['video_file'] ?? true) === false, 'video file not claimed');
ok(($item['evidence_quality']['video_frames'] ?? true) === false, 'video frames not claimed');
ok(($item['evidence_quality']['audio'] ?? true) === false, 'audio not claimed');
ok(($item['evidence_quality']['transcript'] ?? true) === false, 'transcript not claimed');
ok(($item['relevance_tier'] ?? '') !== '', 'relevance tier stored');
ok(($item['recommended_use'] ?? '') === 'show', 'relevant item recommended for show');

$report1 = FIDTF_Report_Service::build_or_get_report($run_id, FIDTF_Run_Service::get_run($run_id), false);
$report2 = FIDTF_Report_Service::build_or_get_report($run_id, FIDTF_Run_Service::get_run($run_id), false);
ok(count($wpdb->tables['reports'] ?? []) === 1, 'calling report twice creates one report only');
ok($fidtf_memory_count === 1, 'memory bridge triggered once only');
ok(!empty($report2['from_cache']), 'second report read came from cache');
$fidtf_is_admin = true;
$report3 = FIDTF_Report_Service::build_or_get_report($run_id, FIDTF_Run_Service::get_run($run_id), true);
ok(count($wpdb->tables['reports'] ?? []) === 2, 'admin force rebuild creates new report');
ok($fidtf_memory_count === 2, 'memory bridge triggered on force-created report');
$fidtf_is_admin = false;

$items_page = FIDTF_Source_Job_Service::get_items($run_id, true, ['page' => 1, 'per_page' => 20, 'source' => $first_job_source]);
ok(count($items_page) === 1, 'show-more item payload can fetch first page by source');
ok(($report3['statistical_summary']['total_relevant_items'] ?? 0) === 1, 'report stats use stored filtered evidence');

fwrite(STDOUT, "FIDTF v0.1.1 foundation-hardening checks passed: {$checks} / {$checks}\n");
