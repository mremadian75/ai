<?php
/**
 * v0.4.0 — module registry regression tests (executed, not grep).
 *
 * Locks: module registration + duplicate-id rejection, required metadata,
 * exactly ONE Trend Finder in navigation, working page render callbacks,
 * honest not-runnable states, unified menu registration, legacy Tools menu
 * dedupe, and the workspace (non-dashboard) page anatomy.
 */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data($code = '') { return $this->data; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s))); }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function current_user_can($cap) { return true; }
function get_current_user_id() { return 7; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $echo = true) { return '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr($action) . '">'; }
function wp_unslash($v) { return $v; }

$GLOBALS['fi_test_hooks'] = [];
function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['fi_test_hooks'][] = [$hook, $cb, $priority]; return true; }

$GLOBALS['fi_test_menus'] = ['top' => [], 'sub' => [], 'removed' => []];
function add_menu_page($page_title, $menu_title, $cap, $slug, $cb = null, $icon = '', $pos = null) {
    $GLOBALS['fi_test_menus']['top'][] = ['title' => $menu_title, 'slug' => $slug, 'cb' => $cb];
    return 'hook_' . $slug;
}
function add_submenu_page($parent, $page_title, $menu_title, $cap, $slug, $cb = null) {
    $GLOBALS['fi_test_menus']['sub'][] = ['parent' => $parent, 'title' => $menu_title, 'slug' => $slug, 'cb' => $cb];
    return 'hook_' . $slug;
}
function remove_submenu_page($parent, $slug) { $GLOBALS['fi_test_menus']['removed'][] = [$parent, $slug]; return true; }

$root = dirname(__DIR__);
require_once $root . '/includes/modules/class-fi-abstract-module.php';
require_once $root . '/includes/modules/class-fi-module-registry.php';
foreach ([
    'signal-room/class-fi-signal-room-module.php',
    'trend-finder/class-fi-trend-finder-service.php',
    'trend-finder/class-fi-trend-finder-renderer.php',
    'trend-finder/class-fi-trend-finder-module.php',
    'source-intelligence/class-fi-source-intelligence-service.php',
    'source-intelligence/class-fi-source-intelligence-renderer.php',
    'source-intelligence/class-fi-source-intelligence-module.php',
    'signal-workbench/class-fi-signal-workbench-module.php',
    'brief-builder/class-fi-brief-builder-module.php',
    'asset-studio/class-fi-asset-studio-service.php',
    'asset-studio/class-fi-asset-studio-renderer.php',
    'asset-studio/class-fi-asset-studio-module.php',
    'memory/class-fi-memory-service.php',
    'memory/class-fi-memory-renderer.php',
    'memory/class-fi-memory-module.php',
    'usage-ledger/class-fi-usage-ledger-service.php',
    'usage-ledger/class-fi-usage-ledger-renderer.php',
    'usage-ledger/class-fi-usage-ledger-module.php',
    'readiness/class-fi-readiness-module.php',
    'settings/class-fi-settings-module.php',
] as $file) {
    require_once $root . '/includes/modules/' . $file;
}

$checks = 0; $failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

// ── registration + duplicate rejection ───────────────────────────────────────
FI_Module_Registry::reset();
$module_classes = [
    'FI_Signal_Room_Module', 'FI_Trend_Finder_Module', 'FI_Source_Intelligence_Module',
    'FI_Signal_Workbench_Module', 'FI_Brief_Builder_Module', 'FI_Asset_Studio_Module',
    'FI_Memory_Module', 'FI_Usage_Ledger_Module', 'FI_Readiness_Module', 'FI_Settings_Module',
];
foreach ($module_classes as $class) {
    ok(FI_Module_Registry::register(new $class()) === true, "module {$class} registers");
}
ok(count(FI_Module_Registry::modules()) === 10, 'registry holds all 10 modules');
$dup = FI_Module_Registry::register(new FI_Trend_Finder_Module());
ok(is_wp_error($dup) && $dup->get_error_code() === 'fi_module_duplicate', 'duplicate module id is rejected');

// ── required metadata ────────────────────────────────────────────────────────
$allowed_states = ['available', 'configuration_needed', 'read_only', 'unavailable'];
foreach (FI_Module_Registry::modules() as $module) {
    $meta = $module->describe();
    ok($meta['id'] !== '' && $meta['label'] !== '' && $meta['description'] !== '', "module {$meta['id']} has id/label/description");
    ok($meta['capability'] === 'manage_options', "module {$meta['id']} declares a capability");
    $nav = $meta['nav'];
    $nav_ok = (($nav['type'] ?? '') === 'page' && ($nav['slug'] ?? '') !== '')
        || (($nav['type'] ?? '') === 'link' && ($nav['url'] ?? '') !== '');
    ok($nav_ok, "module {$meta['id']} has a usable navigation entry");
    ok(in_array((string) ($meta['status']['state'] ?? ''), $allowed_states, true), "module {$meta['id']} reports an honest status state");
}

// ── exactly one Trend Finder in navigation ───────────────────────────────────
$nav = FI_Module_Registry::nav();
$trend_entries = array_values(array_filter($nav, static function ($row) {
    return stripos((string) $row['label'], 'trend finder') !== false || strpos((string) $row['id'], 'trend') !== false;
}));
ok(count($trend_entries) === 1, 'navigation contains exactly one Trend Finder entry');
ok($trend_entries[0]['id'] === 'trend_finder' && $trend_entries[0]['target'] === 'fi-trend-finder', 'the one Trend Finder entry is the canonical module page');

// ── unified menu registration ────────────────────────────────────────────────
FI_Module_Registry::register_menu();
ok(count($GLOBALS['fi_test_menus']['top']) === 1 && $GLOBALS['fi_test_menus']['top'][0]['slug'] === 'future-island', 'one Future Island top-level menu');
$sub = $GLOBALS['fi_test_menus']['sub'];
ok(count($sub) === 11, 'overview + 10 module submenu entries registered');
$sub_trend = array_values(array_filter($sub, static function ($row) { return stripos((string) $row['title'], 'trend finder') !== false; }));
ok(count($sub_trend) === 1, 'exactly one Trend Finder submenu entry');
ok(is_array($sub_trend[0]['cb']) && is_callable($sub_trend[0]['cb']), 'Trend Finder page callback is callable');
foreach ($sub as $row) {
    if (is_array($row['cb'])) { ok(is_callable($row['cb']), "submenu '{$row['title']}' callback is callable"); }
}
FI_Module_Registry::dedupe_legacy_menu_items();
$removed = array_map(static function ($r) { return $r[1]; }, $GLOBALS['fi_test_menus']['removed']);
foreach (['fi-intake', 'fi-signal-room', 'fi-brief-workbench', 'fi-draft-workbench'] as $legacy) {
    ok(in_array($legacy, $removed, true), "legacy Tools menu item '{$legacy}' deduped (page itself stays registered)");
}

// ── unavailable modules are not runnable on the index ────────────────────────
class FI_Test_Unavailable_Module extends FI_Abstract_Module {
    public function id(): string { return 'test_unavailable'; }
    public function label(): string { return 'Test Unavailable'; }
    public function description(): string { return 'A module whose engine is missing.'; }
    public function nav(): array { return ['type' => 'page', 'slug' => 'fi-test-unavailable']; }
    public function status(): array { return ['state' => 'unavailable', 'detail' => 'Engine missing.']; }
}
FI_Module_Registry::register(new FI_Test_Unavailable_Module());
ob_start();
FI_Module_Registry::render_index();
$index = (string) ob_get_clean();
ok(strpos($index, 'fi-module-list') !== false, 'index renders the module registry list');
ok(strpos($index, 'Not runnable') !== false, 'unavailable module shows Not runnable (no fake Open action)');
ok(preg_match('/is-unavailable[\s\S]*?Not runnable/', $index) === 1, 'the not-runnable state belongs to the unavailable module row');
ok(strpos($index, 'fi-room-context') !== false, 'index uses the workspace context header, not a metric dashboard');
ok(strpos($index, 'ves-stat-card') === false && strpos($index, 'fidtf-metric-grid') === false, 'index has no metric tile grid');

// ── module pages render with workspace anatomy + collapsed diagnostics ──────
foreach (['FI_Trend_Finder_Module' => 'fi-trend-finder', 'FI_Source_Intelligence_Module' => 'fi-source-intelligence', 'FI_Asset_Studio_Module' => 'fi-asset-studio', 'FI_Memory_Module' => 'fi-memory', 'FI_Usage_Ledger_Module' => 'fi-usage-ledger'] as $class => $anchor) {
    $module = FI_Module_Registry::get((new $class())->id());
    ob_start();
    $module->render();
    $html = (string) ob_get_clean();
    ok(strpos($html, 'fi-room-context') !== false && strpos($html, '<h1>') !== false, "{$class} page has a dedicated title/context header");
    ok(strpos($html, 'fi-room-rail') !== false, "{$class} page has a decision/next-action rail");
    ok(strpos($html, 'id="' . $anchor . '"') !== false, "{$class} page carries its module anchor");
}
$trend_module = FI_Module_Registry::get('trend_finder');
ob_start(); $trend_module->render(); $trend_html = (string) ob_get_clean();
ok(strpos($trend_html, '<details class="fidtf-diagnostics">') !== false, 'diagnostics render collapsed by default');
ok(strpos($trend_html, 'Open Trend Finder workspace') !== false || strpos($trend_html, 'configuration-needed, not runnable') !== false, 'Trend Finder page offers the next action or says it is not runnable');

// No raw slugs as primary labels in module nav.
foreach (FI_Module_Registry::nav() as $row) {
    ok(preg_match('/^[a-z0-9_\-]+$/', (string) $row['label']) !== 1, "nav label '{$row['label']}' is human text, not a raw slug");
}

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.4.0 module registry checks: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.4.0 module registry checks passed: {$checks} / {$checks}\n");
exit(0);
