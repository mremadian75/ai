<?php
/**
 * VES_Migrations — central schema guard + health reporter.
 *
 * Verifies the migration runner is isolated (one failing store can't block others),
 * forces a re-migration on demand, registers the full store list, and produces a
 * health snapshot — all without a real DB.
 *
 * Run: php tests/test-ves-migrations.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $t = 0) { return true; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('add_action')) { function add_action() { return true; } }
if (!function_exists('wp_using_ext_object_cache')) { function wp_using_ext_object_cache() { return false; } }

// Minimal $wpdb for health().
class _MWPDB { public $prefix = 'wp_';
    public function prepare($q, ...$a) { return $q; }
    public function get_col($q) { return []; }
    public function get_var($q) { return 0; }
}
$GLOBALS['wpdb'] = new _MWPDB();

// Fakes matching real store names in stores(): one succeeds, one throws.
class VES_Run_Log_Service { public static $calls = 0; public static function create_table($f = false) { self::$calls++; } }
class VES_Temp_Memory { public static function create_table($f = false) { throw new RuntimeException('boom'); } }

require_once dirname(__DIR__) . '/includes/class-ves-migrations.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// ── 1. run_all is isolated: a throwing store doesn't block others ────────────
$threw = false;
try { $res = VES_Migrations::run_all(true); } catch (\Throwable $e) { $threw = true; }
$ok($threw === false, '1: run_all() does not propagate a store exception');
$ok(VES_Run_Log_Service::$calls === 1, '1: a healthy store create method is invoked');
$ok(in_array('VES_Run_Log_Service::create_table', $res['ran'], true), '1: healthy store reported in ran[]');
$ok(in_array('VES_Temp_Memory::create_table', $res['failed'], true), '1: throwing store reported in failed[], not fatal');

// ── 2. Unloaded stores are skipped (guarded by class_exists/method_exists) ───
$ok(!in_array('VES_Insight_Records::create_table', $res['ran'], true), '2: stores whose class is absent are safely skipped');

// ── 3. stores() registry includes billing + key stores ───────────────────────
$reg = array_map(function ($p) { return $p[0] . '::' . $p[1]; }, VES_Migrations::stores());
$ok(in_array('VES_Usage_Billing::create_tables', $reg, true), '3: billing table is in the migration registry');
$ok(in_array('VES_Memory_Records::create_table', $reg, true) && in_array('VES_Evidence_Store::create_table', $reg, true), '3: core record stores are in the registry');

// ── 4. force_remigrate stamps the schema version ─────────────────────────────
$GLOBALS['__opts'] = [];
VES_Migrations::force_remigrate();
$ok(($GLOBALS['__opts']['ves_schema_version'] ?? '') === VES_Migrations::SCHEMA_VERSION, '4: force_remigrate() stamps the current schema version');

// ── 5. health() returns a structured, safe snapshot ──────────────────────────
$h = VES_Migrations::health();
$ok(is_array($h) && isset($h['schema_version_code'], $h['schema_in_sync'], $h['tables'], $h['object_cache']), '5: health() returns the expected keys');
$ok(is_array($h['tables']), '5: health tables is an array');
$ok($h['schema_in_sync'] === true, '5: schema_in_sync true after force_remigrate stamped the version');

echo "\nVES_Migrations (schema guard + health): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
