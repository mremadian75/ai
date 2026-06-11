<?php
/**
 * Legacy insight evidence gate — VES_Insight_Records::has_supporting_evidence() /
 * can_approve_legacy_insight() / legacy_approval_health(). Deterministic, no DB for
 * the pure gate; a tiny MockWpdb backs the by-id + health paths. No API, no LLM.
 *
 * Run: php tests/test-ves-legacy-insight-evidence-gate.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
// Point ABSPATH at a temp dir with an empty wp-admin/includes/upgrade.php stub so
// the store's create_table() require_once resolves (dbDelta is shimmed below).
$__abs = sys_get_temp_dir() . '/fiis_legacy_gate_' . getmypid() . '/';
@mkdir($__abs . 'wp-admin/includes', 0777, true);
@file_put_contents($__abs . 'wp-admin/includes/upgrade.php', "<?php\n");
if (!defined('ABSPATH')) { define('ABSPATH', $__abs); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim((string) $s); }
function sanitize_textarea_field($s) { return trim((string) $s); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function current_time($t = 'mysql', $g = 0) { return '2026-06-08 12:00:00'; }
function get_option($k, $d = false) { return $GLOBALS['__o'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__o'][$k] = $v; return true; }
function dbDelta($s) { return []; }
$GLOBALS['__o'] = [];

class MockWpdb {
    public $prefix = 'wp_'; public $insert_id = 0; private $auto = 0; public $data = [];
    public function get_charset_collate() { return ''; }
    public function query($s) { return true; }
    public function prepare($sql, $args = []) { if (!is_array($args)) { $args = array_slice(func_get_args(), 1); } foreach ($args as $a) { $rep = is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'"; $sql = preg_replace('/%d|%s|%f/', $rep, $sql, 1); } return $sql; }
    public function get_row($sql, $o = null) {
        if (preg_match('/WHERE id = (\d+)/', $sql, $m)) { return $this->data['wp_ves_insight_records'][(int) $m[1]] ?? null; }
        return null;
    }
    public function get_var($sql) {
        $rows = $this->data['wp_ves_insight_records'] ?? [];
        if (preg_match("/status = '([a-z]+)'/", $sql, $sm)) { $rows = array_filter($rows, function ($r) use ($sm) { return (string) ($r['status'] ?? '') === $sm[1]; }); }
        if (preg_match('/workspace_id = (\d+)/', $sql, $wm)) { $rows = array_filter($rows, function ($r) use ($wm) { return (int) ($r['workspace_id'] ?? 0) === (int) $wm[1]; }); }
        if (strpos($sql, 'COUNT(*)') !== false) { return (string) count($rows); }
        return null;
    }
    public function get_results($sql, $o = null) {
        $rows = array_values($this->data['wp_ves_insight_records'] ?? []);
        if (preg_match("/status = '([a-z]+)'/", $sql, $sm)) { $rows = array_values(array_filter($rows, function ($r) use ($sm) { return (string) ($r['status'] ?? '') === $sm[1]; })); }
        if (preg_match('/workspace_id = (\d+)/', $sql, $wm)) { $rows = array_values(array_filter($rows, function ($r) use ($wm) { return (int) ($r['workspace_id'] ?? 0) === (int) $wm[1]; })); }
        return $rows;
    }
    public function update($t, $r, $w, $f = null, $wf = null) { $id = (int) ($w['id'] ?? 0); if (isset($this->data[$t][$id])) { $this->data[$t][$id] = array_merge($this->data[$t][$id], $r); return 1; } return 0; }
}
$wpdb = new MockWpdb(); $GLOBALS['wpdb'] = $wpdb;

require_once dirname(__DIR__) . '/includes/class-ves-insight-records.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };

// Seed legacy rows directly (column shape matches the legacy schema).
$wpdb->data['wp_ves_insight_records'] = [
    1 => ['id' => 1, 'workspace_id' => 5, 'status' => 'approved', 'title' => 'Backed', 'evidence_refs_json' => json_encode(['ev_a', 'ev_b']), 'meta_json' => json_encode(['evidence_ref_count' => 2, 'evidence_validation_status' => 'supported'])],
    2 => ['id' => 2, 'workspace_id' => 5, 'status' => 'approved', 'title' => 'No evidence', 'evidence_refs_json' => json_encode([]), 'meta_json' => json_encode(['evidence_ref_count' => 0, 'evidence_validation_status' => 'unsupported', 'unsupported_by_evidence' => true])],
    3 => ['id' => 3, 'workspace_id' => 5, 'status' => 'draft', 'title' => 'Weak but has 1 ref', 'evidence_refs_json' => json_encode(['ev_x']), 'meta_json' => json_encode(['evidence_ref_count' => 1, 'evidence_validation_status' => 'weak'])],
];

// ── 1. Structured result shape ───────────────────────────────────────────────
$g1 = VES_Insight_Records::can_approve_legacy_insight(1);
$ok(array_key_exists('allowed', $g1) && array_key_exists('reason', $g1) && array_key_exists('evidence_count', $g1) && array_key_exists('details', $g1), '1: gate returns structured {allowed,reason,evidence_count,details}');

// ── 2. Evidence-backed legacy insight CAN be approved ────────────────────────
$ok($g1['allowed'] === true && $g1['evidence_count'] === 2 && $g1['reason'] === 'evidence_present', '2: evidence-backed insight is approvable');

// ── 3. No-evidence / unsupported legacy insight CANNOT be approved ───────────
$g2 = VES_Insight_Records::can_approve_legacy_insight(2);
$ok($g2['allowed'] === false && $g2['evidence_count'] === 0, '3: no-evidence insight is blocked');
$ok(in_array($g2['reason'], ['no_evidence_refs', 'evidence_marked_unsupported'], true), '3: block carries a structured reason');

// ── 4. Weak (>=1 ref, not flagged unsupported) is approvable ─────────────────
$g3 = VES_Insight_Records::can_approve_legacy_insight(3);
$ok($g3['allowed'] === true && $g3['evidence_count'] === 1, '4: weak-but-present evidence is approvable (>=1 ref)');

// ── 5. unsupported_by_evidence flag overrides a non-zero count ───────────────
$flagged = ['evidence_refs' => ['ev_q'], 'evidence_ref_count' => 1, 'evidence_validation_status' => 'unsupported', 'unsupported_by_evidence' => true];
$gf = VES_Insight_Records::has_supporting_evidence($flagged);
$ok($gf['allowed'] === false && $gf['reason'] === 'evidence_marked_unsupported', '5: unsupported flag blocks even with a ref present');

// ── 6. Missing record / invalid input is blocked (never approves) ────────────
$gm = VES_Insight_Records::can_approve_legacy_insight(999);
$ok($gm['allowed'] === false && $gm['reason'] === 'record_not_found', '6: missing record is blocked');
$gi = VES_Insight_Records::has_supporting_evidence('not-an-array');
$ok($gi['allowed'] === false, '6: non-array input is blocked safely');

// ── 7. legacy_approval_health counts (read-only) ─────────────────────────────
$h = VES_Insight_Records::legacy_approval_health(5);
$ok($h['legacy_total'] === 3 && $h['legacy_approved'] === 2, '7: health counts total + approved');
$ok($h['legacy_approved_without_evidence'] === 1 && $h['gate'] === 'active', '7: health flags the 1 approved-without-evidence row, gate active');

echo "\nLegacy insight evidence gate: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
