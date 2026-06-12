<?php
/**
 * Admin Console (additive, fail-safe) regression test.
 *
 * The earlier sprint white-screened wp-admin with a "Call to undefined
 * function/method" on a hot admin hook, which CLI/shim tests could not catch
 * because they defined every WordPress function. This test does the opposite:
 * it runs the console's hot paths in a HOSTILE environment — a key WP function
 * (menu_page_url) is intentionally NOT defined, the access-control class is
 * absent in one case, and rendering is exercised — and asserts that:
 *
 *   - register_menu() never throws and never duplicates the top-level menu
 *   - render() never throws even when symbols/classes are missing
 *   - missing pages are shown as "Not installed", never fatally linked
 *   - enqueue() is scoped and never throws
 *   - the access matrix degrades gracefully and never leaks raw option keys
 *
 * NOTE: menu_page_url is deliberately undefined here to prove the function_exists
 * guard tolerates a missing symbol. In real wp-admin it exists, so links resolve.
 *
 * Run: php tests/test-ves-admin-console.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('VES_PLUGIN_VERSION')) { define('VES_PLUGIN_VERSION', 'test'); }
if (!defined('VES_PLUGIN_URL')) { define('VES_PLUGIN_URL', 'http://t/wp-content/plugins/fiis/'); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }

// Minimal, REALISTIC shim — note: menu_page_url is intentionally absent.
$GLOBALS['menu'] = []; $GLOBALS['submenu'] = []; $GLOBALS['admin_page_hooks'] = [];
function add_menu_page($pt, $mt, $c, $s, $cb = '', $i = '', $p = null) {
    $GLOBALS['menu'][] = [$mt, $c, $s]; $GLOBALS['admin_page_hooks'][$s] = sanitize_title($mt); return 'toplevel_page_' . $s;
}
function add_submenu_page($par, $pt, $mt, $c, $s, $cb = '') {
    $GLOBALS['submenu'][$par][] = [$mt, $c, $s]; return sanitize_title($par) . '_page_' . $s;
}
function sanitize_title($t) { return trim(preg_replace('/[^a-z0-9_]+/', '-', strtolower((string) $t)), '-'); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function current_user_can($c) { return true; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return (string) $v; }
function admin_url($p = '') { return 'http://t/wp-admin/' . ltrim((string) $p, '/'); }
$GLOBALS['__enq'] = [];
function wp_enqueue_style($h, $src = '', $d = [], $v = false) { $GLOBALS['__enq'][] = $h; return true; }

require_once dirname(__DIR__) . '/includes/class-ves-admin-console.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$threw = function (callable $fn) { try { $fn(); return false; } catch (\Throwable $e) { return $e->getMessage(); } };

// ── 1. register_menu reuses an EXISTING top-level (no duplicate) ─────────────
$GLOBALS['menu'] = []; $GLOBALS['submenu'] = []; $GLOBALS['admin_page_hooks'] = [];
add_menu_page('Intelligence Suite', 'Intelligence Suite', 'manage_options', 'ves-intelligence-suite'); // simulate access-control
$before_top = count($GLOBALS['menu']);
$err = $threw(['VES_Admin_Console', 'register_menu']);
$ok($err === false, '1: register_menu() does not throw when parent exists' . ($err ? " ($err)" : ''));
$ok(count($GLOBALS['menu']) === $before_top, '1: no duplicate top-level menu created');
$sub = array_map(function ($i) { return $i[2]; }, $GLOBALS['submenu']['ves-intelligence-suite'] ?? []);
$ok(in_array('ves-fiis-console', $sub, true), '1: Dashboard submenu added under existing Intelligence Suite');

// ── 2. register_menu creates the top-level only if absent ────────────────────
$GLOBALS['menu'] = []; $GLOBALS['submenu'] = []; $GLOBALS['admin_page_hooks'] = [];
$err = $threw(['VES_Admin_Console', 'register_menu']);
$ok($err === false, '2: register_menu() does not throw when parent is absent');
$tops = array_filter($GLOBALS['menu'], function ($m) { return ($m[2] ?? '') === 'ves-intelligence-suite'; });
$ok(count($tops) === 1, '2: exactly one top-level created as fallback');

// ── 3. render() NEVER throws — even with menu_page_url undefined ──────────────
$ok(!function_exists('menu_page_url'), '3: (precondition) menu_page_url is undefined in this hostile env');
foreach (['overview', 'ai', 'sources', 'access', 'modules', 'reports', 'advanced', 'bogus'] as $tab) {
    $_GET['tab'] = $tab;
    ob_start();
    $err = $threw(['VES_Admin_Console', 'render']);
    $out = ob_get_clean();
    $ok($err === false, "3: render() tab=$tab does not throw" . ($err ? " ($err)" : ''));
    $ok(strpos((string) $out, 'fiis-console') !== false, "3: render() tab=$tab produced console markup");
}

// ── 4. Missing pages render as "Not installed", never a fatal link ───────────
$_GET['tab'] = 'modules';
ob_start(); VES_Admin_Console::render(); $out = ob_get_clean();
$ok(strpos($out, 'Not installed') !== false, '4: missing pages shown as Not installed (menu_page_url absent)');
$ok(strpos($out, 'Deep Trend Finder') !== false, '4: module labels still render');

// ── 5. enqueue() is scoped and never throws ──────────────────────────────────
$GLOBALS['__enq'] = [];
$err = $threw(function () { VES_Admin_Console::enqueue('some-other-page'); });
$ok($err === false && $GLOBALS['__enq'] === [], '5: enqueue() ignores non-console hooks without throwing');

// ── 6. Access matrix: graceful + no raw option-key leak ──────────────────────
// 6a. Access control absent -> graceful message, no throw.
$err = $threw(['VES_Admin_Console', 'access_matrix_html']);
$ok($err === false, '6: access_matrix_html() does not throw when access control absent');
$html_absent = VES_Admin_Console::access_matrix_html();
$ok(strpos($html_absent, 'not available') !== false, '6: graceful message when access control is absent');

// 6b. With a fake access control -> renders matrix, no raw keys.
eval('class VES_Access_Control { const MODULES = ["social","seo","creative"]; public static function get_access_map(){ return ["free"=>["modules"=>["social"=>"enabled","seo"=>"disabled_locked_visible"]],"pro"=>["modules"=>["social"=>"enabled","seo"=>"enabled","creative"=>"enabled"]]]; } }');
$html = VES_Admin_Console::access_matrix_html();
$ok(strpos($html, 'fiis-matrix') !== false, '6: matrix renders with data');
$ok(strpos($html, 'Free') !== false && strpos($html, 'Pro') !== false, '6: plan/role rows present');
$ok(strpos($html, 'disabled_locked_visible') === false && strpos($html, 'ves_plan_access') === false, '6: no raw option keys / raw state values leaked');
$ok(strpos($html, 'Read-only') !== false, '6: matrix labeled read-only');

// ── 7. Additive guarantee: console registers exactly ONE new page slug ───────
$GLOBALS['menu'] = []; $GLOBALS['submenu'] = []; $GLOBALS['admin_page_hooks'] = [];
add_menu_page('Intelligence Suite', 'Intelligence Suite', 'manage_options', 'ves-intelligence-suite');
VES_Admin_Console::register_menu();
$all_sub = [];
foreach ($GLOBALS['submenu'] as $p => $items) { foreach ($items as $it) { $all_sub[] = $it[2]; } }
$fiis_new = array_values(array_filter($all_sub, function ($s) { return strpos($s, 'ves-fiis-') === 0; }));
$ok($fiis_new === ['ves-fiis-console'], '7: console adds exactly one new page (ves-fiis-console) and relocates nothing');

echo "\nAdmin Console (additive, fail-safe): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
