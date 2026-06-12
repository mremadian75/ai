<?php
/**
 * Phase 0 — AI-execution truth alignment across UI surfaces.
 *
 * The truth owner is VES_Generation_Prompt_Package_Builder::execution_enabled()
 * (raw option + the ves_generation_execution_enabled filter). Every UI surface
 * (admin Console strip, Signal Room snapshot/strip, RC page flags) must report
 * THAT truth — never the raw option alone — with a safe fallback chain:
 *
 *   1. raw option false + filter forces true  => every surface shows ENABLED
 *   2. raw option true (no filter)            => every surface shows ENABLED
 *   3. builder class unavailable              => fall back to the RAW OPTION
 *      (an ON option can never be reported OFF just because the builder is gone)
 *   4. builder throws                         => NO fatal; raw option fallback
 *   5. Console + Signal Room + RC page are CONSISTENT in every scenario
 *
 * Scenarios run in subprocesses because "real builder", "no builder" and
 * "throwing builder" cannot coexist in one PHP process (one-way class loading).
 *
 * Run: php tests/test-ves-execution-truth-alignment.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

// ── Parent mode: spawn one subprocess per scenario ────────────────────────────
if ($argc < 2) {
    $scenarios = [
        'real_builder_filter_forces_true',  // raw false, filter => true  → ENABLED everywhere
        'real_builder_filter_forces_false', // raw true,  filter => false → OFF everywhere (builder truth wins)
        'real_builder_raw_true',            // raw true, identity filter  → ENABLED everywhere
        'real_builder_raw_false',           // raw false, identity filter → OFF everywhere
        'no_builder_raw_true',              // builder absent, raw true   → ENABLED everywhere (raw-option fallback)
        'no_builder_raw_false',             // builder absent, raw false  → OFF everywhere
        'builder_throws_raw_true',          // builder throws, raw true   → no fatal, ENABLED everywhere
        'builder_throws_raw_false',         // builder throws, raw false  → no fatal, OFF everywhere
    ];
    $pass = 0; $fail = 0;
    foreach ($scenarios as $s) {
        $out = []; $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($s) . ' 2>&1', $out, $code);
        if ($code === 0) { $pass++; }
        else { $fail++; fwrite(STDERR, "FAIL: scenario {$s}\n" . implode("\n", $out) . "\n"); }
    }
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

// ── Worker mode: one scenario per process ─────────────────────────────────────
$scenario = (string) $argv[1];
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', 'test'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-test'); }
if (!defined('VES_PLUGIN_VERSION')) { define('VES_PLUGIN_VERSION', 'test'); }
if (!defined('VES_PLUGIN_URL')) { define('VES_PLUGIN_URL', 'http://t/wp-content/plugins/fiis/'); }
$root = dirname(__DIR__);

function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function get_option($k, $d = false) { return $GLOBALS['__o'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__o'][$k] = $v; return true; }
function current_time($t = 'mysql', $g = 0) { return '2026-06-12 12:00:00'; }
function apply_filters($tag, $value) {
    if ($tag === 'ves_generation_execution_enabled' && array_key_exists('__filter_force', $GLOBALS)) {
        return $GLOBALS['__filter_force'];
    }
    return $value;
}
$GLOBALS['__o'] = [];

// Scenario setup: option, filter, builder flavor, expectation.
$raw_on = strpos($scenario, 'raw_true') !== false || $scenario === 'real_builder_filter_forces_false';
$GLOBALS['__o']['ves_generation_execution_enabled'] = $raw_on;
if ($scenario === 'real_builder_filter_forces_true') { $GLOBALS['__filter_force'] = true; }
if ($scenario === 'real_builder_filter_forces_false') { $GLOBALS['__filter_force'] = false; }

if (strpos($scenario, 'real_builder') === 0) {
    require_once $root . '/includes/class-ves-generation-prompt-package-builder.php';
} elseif (strpos($scenario, 'builder_throws') === 0) {
    final class VES_Generation_Prompt_Package_Builder {
        public static function execution_enabled() { throw new RuntimeException('builder exploded'); }
    }
} // no_builder_*: the class is simply never defined.

$expected = false;
switch ($scenario) {
    case 'real_builder_filter_forces_true':  $expected = true;  break; // builder truth (filter) wins over raw false
    case 'real_builder_filter_forces_false': $expected = false; break; // builder truth (filter) wins over raw true
    case 'real_builder_raw_true':            $expected = true;  break;
    case 'real_builder_raw_false':           $expected = false; break;
    case 'no_builder_raw_true':              $expected = true;  break; // raw-option fallback
    case 'no_builder_raw_false':             $expected = false; break;
    case 'builder_throws_raw_true':          $expected = true;  break; // throw-safe fallback
    case 'builder_throws_raw_false':         $expected = false; break;
}

// Surfaces under test (REAL files).
final class VES_RC_Readiness_Service {
    public static function report(array $a = []) {
        return ['status' => 'blocked', 'live_validation' => ['status' => 'unrun'], 'plugin_version' => 'test', 'rc_label' => 'v0.1-test', 'blockers' => []];
    }
}
require_once $root . '/includes/class-ves-admin-console.php';
require_once $root . '/includes/class-ves-signal-room.php';
require_once $root . '/includes/class-ves-release-candidate-page.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; } else { $fail++; fwrite(STDERR, "FAIL: $l\n"); } };

// 1. Signal Room snapshot flag (public API).
$flag = null;
try { $flag = (bool) (VES_Signal_Room::snapshot(1)['flags']['generation_execution_enabled'] ?? null); }
catch (\Throwable $e) { $ok(false, "Signal Room snapshot threw: " . $e->getMessage()); }
$ok($flag === $expected, "Signal Room flag is " . var_export($expected, true) . " in {$scenario}");

// 2. RC page flags row (private — reflection).
$m = new ReflectionMethod('VES_Release_Candidate_Page', 'flags');
$m->setAccessible(true);
$rc_on = null;
try {
    foreach ((array) $m->invoke(null) as $row) {
        if (($row['name'] ?? '') === 'ves_generation_execution_enabled') { $rc_on = (bool) $row['on']; }
    }
} catch (\Throwable $e) { $ok(false, "RC page flags threw: " . $e->getMessage()); }
$ok($rc_on === $expected, "RC page flag is " . var_export($expected, true) . " in {$scenario}");

// 3. Console status strip (private — reflection); ON/OFF badge text.
$m2 = new ReflectionMethod('VES_Admin_Console', 'status_strip');
$m2->setAccessible(true);
$strip = '';
try { $strip = (string) $m2->invoke(null); }
catch (\Throwable $e) { $ok(false, "Console strip threw: " . $e->getMessage()); }
$ok($strip !== '', 'Console strip rendered (fail-safe path not falsely triggered)');
$console_on = strpos($strip, '>ON<') !== false && strpos($strip, '>OFF<') === false;
$console_off = strpos($strip, '>OFF<') !== false && strpos($strip, '>ON<') === false;
$ok($expected ? $console_on : $console_off, "Console strip shows " . ($expected ? 'ON' : 'OFF') . " in {$scenario}");

// 4. Consistency: the three surfaces agree with each other.
$ok($flag === $rc_on && $rc_on === ($console_on && !$console_off), "Console, Signal Room and RC page agree in {$scenario}");

exit($fail === 0 ? 0 : 1);
