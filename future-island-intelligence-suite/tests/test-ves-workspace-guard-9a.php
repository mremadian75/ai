<?php
/**
 * Phase 9A.3 — workspace/tenant isolation contract.
 *
 * Proves with the REAL guard + REAL prompt package builder + REAL workbench:
 *  1. cross-workspace objects are refused with safe errors (no fatals)
 *  2. workspace-less objects are 'unknown' and refused without explicit opt-in
 *  3. an insight from workspace 2 cannot feed a workspace-1 prompt package
 *  4. the workbench refuses a cross-workspace insight
 *  5. mismatches are recorded as scrubbed security events
 *  6. list filtering drops foreign rows
 *
 * Run: php tests/test-ves-workspace-guard-9a.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function current_time($t = 'mysql', $g = 0) { return '2026-06-14 10:00:00'; }
function get_current_user_id() { return 5; }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function apply_filters($t, $v) { return $v; }
$GLOBALS['__opts'] = [];

// In-memory intelligence store stub exposing only the getters the guard/builder use.
final class VES_Intelligence_Store {
    public static $rows = [];
    public static function get_insight($id) { return self::$rows['insight'][(int) $id] ?? null; }
    public static function get_brief($id) { return self::$rows['brief'][(int) $id] ?? null; }
    public static function get_draft($id) { return self::$rows['draft'][(int) $id] ?? null; }
    public static function get_source($id) { return null; }
    public static function get_signal($id) { return null; }
    public static function get_evidence($id) { return null; }
    public static function get_memory_record($id) { return self::$rows['memory_record'][(int) $id] ?? null; }
}

require_once dirname(__DIR__) . '/includes/class-ves-security-event-log.php';
require_once dirname(__DIR__) . '/includes/class-ves-workspace-guard.php';
require_once dirname(__DIR__) . '/includes/class-ves-generation-prompt-package-builder.php';
require_once dirname(__DIR__) . '/includes/class-ves-review-state.php';
require_once dirname(__DIR__) . '/includes/class-ves-workbench.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; } else { $fail++; fwrite(STDERR, "FAIL: $l\n"); } };

VES_Intelligence_Store::$rows = [
    'insight' => [
        10 => ['id' => 10, 'workspace_id' => 2, 'status' => 'approved', 'title' => 'ws2 insight', 'summary' => 's', 'recommendation' => 'r', 'evidence_ids' => [7]],
        11 => ['id' => 11, 'workspace_id' => 1, 'status' => 'approved', 'title' => 'ws1 insight', 'summary' => 's', 'recommendation' => 'r', 'evidence_ids' => [8]],
        12 => ['id' => 12, 'status' => 'approved', 'title' => 'no-ws insight', 'summary' => 's', 'evidence_ids' => [9]],
    ],
    'brief' => [
        20 => ['id' => 20, 'workspace_id' => 2, 'status' => 'approved', 'objective' => 'o', 'evidence_ids' => [7]],
    ],
];

// ── 1. Guard semantics ───────────────────────────────────────────────────────
$ok(VES_Workspace_Guard::assert_object_in_workspace('insight', 11, 1) === true, 'same-workspace insight passes');
$res = VES_Workspace_Guard::assert_object_in_workspace('insight', 10, 1);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_mismatch', 'cross-workspace insight refused with safe error');
$res = VES_Workspace_Guard::assert_object_in_workspace('insight', 12, 1);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_unknown', 'workspace-less insight is unknown and refused');
$ok(VES_Workspace_Guard::assert_object_in_workspace('insight', 12, 1, ['allow_unknown' => true]) === true, 'unknown workspace usable ONLY with explicit allow_unknown');
$res = VES_Workspace_Guard::assert_object_in_workspace('insight', 999, 1);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_object_missing', 'missing object yields safe error, no fatal');
$res = VES_Workspace_Guard::validate_workspace_id(0);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_invalid', 'workspace 0 is invalid');
$ok(VES_Workspace_Guard::validate_workspace_id('7') === 7, 'numeric string workspace id normalizes');
$res = VES_Workspace_Guard::assert_object_in_workspace('weird_type', 11, 1);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_bad_type', 'unknown object type refused');

// ── 2. Security events on mismatch ──────────────────────────────────────────
$sec = json_encode(get_option('ves_security_event_log', []));
$ok(strpos($sec, 'workspace_mismatch') !== false, 'mismatch recorded as workspace_mismatch security event');
$ok(strpos($sec, 'cross_workspace') !== false && strpos($sec, 'unknown_workspace') !== false, 'both cross and unknown kinds are logged');

// ── 3. Prompt package builder refuses cross-workspace targets ───────────────
$pkg = VES_Generation_Prompt_Package_Builder::build(['use_case' => 'brief_generation', 'target_type' => 'insight', 'target_id' => 10, 'workspace_id' => 1]);
$ok(($pkg['build_status'] ?? '') === 'blocked' && ($pkg['reason'] ?? '') === 'workspace_mismatch', 'ws2 insight cannot feed a ws1 brief package');
$pkg = VES_Generation_Prompt_Package_Builder::build(['use_case' => 'draft_generation', 'target_type' => 'brief', 'target_id' => 20, 'workspace_id' => 1]);
$ok(($pkg['build_status'] ?? '') === 'blocked' && ($pkg['reason'] ?? '') === 'workspace_mismatch', 'ws2 brief cannot feed a ws1 draft package');
$pkg = VES_Generation_Prompt_Package_Builder::build(['use_case' => 'brief_generation', 'target_type' => 'insight', 'target_id' => 12, 'workspace_id' => 1]);
$ok(($pkg['build_status'] ?? '') === 'blocked' && ($pkg['reason'] ?? '') === 'workspace_mismatch', 'workspace-less insight is not silently adopted into ws1');
$pkg = VES_Generation_Prompt_Package_Builder::build(['use_case' => 'brief_generation', 'target_type' => 'insight', 'target_id' => 11, 'workspace_id' => 1]);
$ok(($pkg['build_status'] ?? '') === 'ready', 'same-workspace insight still builds');

// ── 4. Workbench refuses cross-workspace insight ─────────────────────────────
$html = VES_Workbench::render_brief(['workspace_id' => 1, 'insight_id' => 10]);
$ok(strpos($html, 'outside this workspace') !== false, 'workbench refuses ws2 insight in ws1 view');
$html = VES_Workbench::render_brief(['workspace_id' => 1, 'insight_id' => 11]);
$ok(strpos($html, 'outside this workspace') === false, 'workbench renders same-workspace insight');

// ── 5. Row filtering ─────────────────────────────────────────────────────────
$rows = [['id' => 1, 'workspace_id' => 1], ['id' => 2, 'workspace_id' => 2], ['id' => 3]];
$filtered = VES_Workspace_Guard::filter_rows_to_workspace($rows, 1);
$ok(count($filtered) === 1 && (int) $filtered[0]['id'] === 1, 'filter keeps only same-workspace rows (unknown dropped)');

// ── 6. Readiness probe ───────────────────────────────────────────────────────
$ok(VES_Workspace_Guard::guard_active() === true, 'guard_active probe passes');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
