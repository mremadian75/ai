<?php
/**
 * v0.4.0 — Trend Finder consolidation regression tests.
 *
 * Duplicate being removed: the SaaS app shell exposed TWO Trend Finder
 * entries — the legacy 'trend' page (old core implementation, later a
 * duplicate launcher) and the 'deep-trend' page (canonical Deep Trend Finder
 * engine). This locks: one route, one nav entry, one page section, alias
 * compatibility for old links, existing run data still reachable, and the
 * legacy backend kept for compatibility (documented, not navigated).
 */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$root = dirname(__DIR__);
require_once $root . '/includes/class-ves-app-router.php';

$checks = 0; $failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

// ── router: one canonical route, aliases for every legacy spelling ──────────
ok(!VES_App_Router::is_route('trend'), "'trend' is no longer a standalone route");
ok(VES_App_Router::is_route('deep-trend'), "'deep-trend' is the canonical route");
foreach (['trend', 'trend_finder', 'trend-finder', 'deeptrend', 'deep_trend'] as $legacy) {
    ok(VES_App_Router::normalize($legacy) === 'deep-trend', "legacy route '{$legacy}' normalizes to the canonical Trend Finder");
}
$labels = array_map(static function ($cfg) { return (string) $cfg['label']; }, VES_App_Router::ROUTES);
ok(count(array_keys($labels, 'Trend Finder', true)) === 1, 'route map has exactly one Trend Finder label');
ok(!in_array('Deep Trend Finder', $labels, true), 'no separate Deep Trend Finder label remains in the route map');

// ── app shell: one nav entry, one page section, dashboard card points home ──
$tpl = (string) file_get_contents($root . '/templates/shortcode.php');
ok(substr_count($tpl, 'data-page="trend"') === 0, 'legacy trend page section removed from the shell');
ok(substr_count($tpl, 'data-page="deep-trend"') === 1, 'exactly one canonical Trend Finder page section');
ok(substr_count($tpl, "\$ves_nav('deeptrend', 'Trend Finder', 'deep-trend');") === 1, 'exactly one Trend Finder nav entry');
ok(strpos($tpl, "\$ves_nav('trend',") === false, 'duplicate trend nav entry is gone');
ok(strpos($tpl, "['trend',       'deep-trend',  'Trend Finder'") !== false, 'dashboard tool card routes to the canonical page');
ok(preg_match('/data-eyebrow="Search &amp; Discovery">Trend Finder</', $tpl) === 1, 'canonical page is titled Trend Finder');

// ── JS: keyboard shortcut targets the canonical page ─────────────────────────
$js = (string) file_get_contents($root . '/assets/js/ves-frontend.js');
ok(strpos($js, "'6': 'deep-trend'") !== false && strpos($js, "'6': 'trend'") === false, 'keyboard shortcut 6 targets the canonical Trend Finder');

// ── module layer: one canonical module, engine service wired ────────────────
$main = (string) file_get_contents($root . '/future-island-intelligence-suite.php');
ok(strpos($main, 'trend-finder/class-fi-trend-finder-module.php') !== false, 'bootstrap loads the canonical Trend Finder module file');
ok(substr_count($main, "'FI_Trend_Finder_Module'") === 1, 'bootstrap registers the canonical Trend Finder module exactly once');
$module_src = (string) file_get_contents($root . '/includes/modules/trend-finder/class-fi-trend-finder-service.php');
ok(strpos($module_src, 'FIDTF_Run_Service') !== false && strpos($module_src, 'list_recent_runs') !== false, 'canonical module reads run history from the FIDTF engine (old data stays accessible)');
ok(strpos($module_src, 'live_preflight_status') !== false, 'canonical module surfaces provider/source status');

// ── old run data accessible: list_recent_runs degrades safely, reads runs ───
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s))); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function esc_url_raw($s) { return trim((string) $s); }
function __($s, $d = null) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function current_time($t) { return $t === 'timestamp' ? 1750000000 : '2026-06-13 09:00:00'; }
function apply_filters($t, $v) { return $v; }
function do_action() { return null; }
function get_option($k, $default = false) { return $default; }
function add_option($k, $v) { return true; }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
class FI_Test_Runs_Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows = [];
    public function get_charset_collate() { return ''; }
    public function insert($t, $r) { return true; }
    public function update($t, $r, $w) { return true; }
    public function prepare($sql, ...$args) { foreach ($args as $a) { $sql = preg_replace('/%[ds]/', (string) $a, $sql, 1); } return $sql; }
    public function get_results($sql, $type) { return $this->rows; }
    public function get_row($sql, $type) { return $this->rows[0] ?? null; }
}
$wpdb = new FI_Test_Runs_Wpdb();
$wpdb->rows = [
    ['id' => 42, 'status' => 'completed', 'user_brief' => 'Legacy run from before the consolidation', 'market' => 'Spain', 'created_at' => '2026-06-01 10:00:00'],
];
require_once $root . '/modules/deep-trend-finder/includes/class-fidtf-db.php';
require_once $root . '/modules/deep-trend-finder/includes/class-fidtf-settings.php';
require_once $root . '/modules/deep-trend-finder/includes/class-fidtf-run-service.php';
$runs = FIDTF_Run_Service::list_recent_runs(5);
ok(count($runs) === 1 && (int) $runs[0]['id'] === 42, 'pre-consolidation run rows remain readable via the canonical service');

// ── legacy backend kept for compatibility (no data loss), documented ─────────
ok(file_exists($root . '/includes/trend-finder.php'), 'legacy core trend backend retained for compatibility (no destructive removal)');
ok(file_exists($root . '/FUTUREISLAND_MIGRATION_NOTES.md'), 'old → new mapping documented in FUTUREISLAND_MIGRATION_NOTES.md');
$notes = (string) file_get_contents($root . '/FUTUREISLAND_MIGRATION_NOTES.md');
ok(strpos($notes, 'deep-trend') !== false && stripos($notes, 'alias') !== false, 'migration notes describe the route alias mapping');

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.4.0 Trend Finder consolidation checks: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.4.0 Trend Finder consolidation checks passed: {$checks} / {$checks}\n");
exit(0);
