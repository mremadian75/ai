<?php
/**
 * v0.1 RC — insight lifecycle TRANSITION MATRIX contract.
 *
 * Protects the review lifecycle:
 *  - candidate(draft)/reviewed -> approved/rejected work (with evidence)
 *  - approved -> archived works; approved can NEVER fall back to draft
 *  - rejected/archived are TERMINAL: no silent path back to approved
 *  - explicit reopen/restore overrides ONLY lead back to draft (review),
 *    require a reason, and never unlock rejected->approved
 *  - same-status writes stay allowed (idempotent retries)
 *
 * Self-contained: WP shims + in-memory MockWpdb. No DB, no network.
 * Run: php tests/test-ves-insight-lifecycle-transition-matrix.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function esc_url_raw($s) { return trim((string) $s); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function current_time($t = 'mysql', $g = 0) { return '2026-06-11 10:00:00'; }
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function get_current_user_id() { return 7; }
function dbDelta($sql) { return []; }
$GLOBALS['__opts'] = [];

class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $data = [];
    public function get_charset_collate() { return ''; }
    public function query($sql) { return true; }
    public function insert($table, $row, $formats = null) {
        $this->insert_id++; $row['id'] = $this->insert_id;
        $this->data[$table][$this->insert_id] = $row; return 1;
    }
    public function update($table, $row, $where, $f = null, $wf = null) {
        $id = (int) ($where['id'] ?? 0);
        if (isset($this->data[$table][$id])) { $this->data[$table][$id] = array_merge($this->data[$table][$id], $row); return 1; }
        return 0;
    }
    public function prepare($sql, $args = []) {
        if (!is_array($args)) { $args = array_slice(func_get_args(), 1); }
        foreach ($args as $a) {
            $rep = is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%d|%s|%f/', $rep, $sql, 1);
        }
        return $sql;
    }
    public function get_row($sql, $output = OBJECT) {
        if (preg_match('/FROM (\S+) WHERE id = (\d+)/', $sql, $m)) {
            return $this->data[$m[1]][(int) $m[2]] ?? null;
        }
        return null;
    }
    public function get_var($sql) { return null; }
    public function get_results($sql, $output = OBJECT) { return []; }
}
$GLOBALS['wpdb'] = new MockWpdb();

require_once dirname(__DIR__) . '/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; }
    else { $fail++; fwrite(STDERR, "FAIL: {$label}\n"); }
};

/** Seed an insight row with a given status (+ evidence unless $no_evidence). */
$seed = function ($status, $no_evidence = false) {
    global $wpdb;
    $wpdb->insert_id++;
    $id = $wpdb->insert_id;
    $wpdb->data['wp_ves_intel_insights'][$id] = [
        'id' => $id, 'workspace_id' => 1, 'insight_type' => 'trend', 'status' => $status,
        'title' => 'matrix probe', 'summary' => 'probe', 'recommendation' => '',
        'evidence_ids_json' => $no_evidence ? '[]' : '[11]',
        'signal_ids_json' => '[]', 'metadata' => '{}',
        'created_at' => '2026-06-11 09:00:00', 'updated_at' => '2026-06-11 09:00:00',
    ];
    return $id;
};

// ── 1. Pure matrix semantics ────────────────────────────────────────────────
$ok(VES_Intelligence_Store::insight_transition_allowed('draft', 'reviewed'), 'draft -> reviewed allowed');
$ok(VES_Intelligence_Store::insight_transition_allowed('draft', 'approved'), 'draft -> approved allowed (gated by evidence/quality elsewhere)');
$ok(VES_Intelligence_Store::insight_transition_allowed('draft', 'rejected'), 'draft -> rejected allowed');
$ok(VES_Intelligence_Store::insight_transition_allowed('reviewed', 'approved'), 'reviewed -> approved allowed');
$ok(VES_Intelligence_Store::insight_transition_allowed('reviewed', 'rejected'), 'reviewed -> rejected allowed');
$ok(VES_Intelligence_Store::insight_transition_allowed('approved', 'archived'), 'approved -> archived allowed');
$ok(VES_Intelligence_Store::insight_transition_allowed('approved', 'approved'), 'same-status write stays allowed (idempotent)');

$ok(!VES_Intelligence_Store::insight_transition_allowed('approved', 'draft'), 'approved CANNOT become draft/candidate again');
$ok(!VES_Intelligence_Store::insight_transition_allowed('approved', 'reviewed'), 'approved cannot demote to reviewed');
$ok(!VES_Intelligence_Store::insight_transition_allowed('approved', 'rejected'), 'approved cannot jump to rejected (archive instead)');
$ok(!VES_Intelligence_Store::insight_transition_allowed('rejected', 'approved'), 'rejected CANNOT silently become approved');
$ok(!VES_Intelligence_Store::insight_transition_allowed('rejected', 'reviewed'), 'rejected cannot silently become reviewed');
$ok(!VES_Intelligence_Store::insight_transition_allowed('archived', 'approved'), 'archived CANNOT silently become approved');
$ok(!VES_Intelligence_Store::insight_transition_allowed('rejected', 'draft'), 'rejected -> draft blocked without explicit reopen');
$ok(!VES_Intelligence_Store::insight_transition_allowed('archived', 'draft'), 'archived -> draft blocked without explicit restore');

// Overrides are narrow: ONLY back to draft, never to approved.
$ok(VES_Intelligence_Store::insight_transition_allowed('rejected', 'draft', ['allow_reopen' => true]), 'explicit reopen: rejected -> draft allowed');
$ok(VES_Intelligence_Store::insight_transition_allowed('archived', 'draft', ['allow_restore' => true]), 'explicit restore: archived -> draft allowed');
$ok(!VES_Intelligence_Store::insight_transition_allowed('rejected', 'approved', ['allow_reopen' => true]), 'reopen flag can NEVER unlock rejected -> approved');
$ok(!VES_Intelligence_Store::insight_transition_allowed('archived', 'approved', ['allow_restore' => true]), 'restore flag can NEVER unlock archived -> approved');
$ok(!VES_Intelligence_Store::insight_transition_allowed('rejected', 'draft', ['allow_restore' => true]), 'restore flag does not unlock rejected (wrong override)');
$ok(!VES_Intelligence_Store::insight_transition_allowed('archived', 'draft', ['allow_reopen' => true]), 'reopen flag does not unlock archived (wrong override)');

// Unknown/legacy stored statuses may re-enter review but never approval directly.
$ok(VES_Intelligence_Store::insight_transition_allowed('legacy_weird', 'draft'), 'unknown stored status may move to draft');
$ok(!VES_Intelligence_Store::insight_transition_allowed('legacy_weird', 'approved'), 'unknown stored status cannot jump straight to approved');

// Readiness probe.
$ok(VES_Intelligence_Store::insight_transition_matrix_active() === true, 'transition matrix reports active');

// ── 2. Store-level enforcement (DB path) ───────────────────────────────────
$rejected = $seed('rejected');
$res = VES_Intelligence_Store::update_insight_status($rejected, 'approved');
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_intel_transition_blocked', 'store blocks rejected -> approved with ves_intel_transition_blocked');
$ok(($GLOBALS['wpdb']->data['wp_ves_intel_insights'][$rejected]['status'] ?? '') === 'rejected', 'blocked transition leaves stored status untouched');

$approved = $seed('approved');
$res = VES_Intelligence_Store::update_insight_status($approved, 'draft');
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_intel_transition_blocked', 'store blocks approved -> draft');

$res = VES_Intelligence_Store::update_insight_status($approved, 'archived');
$ok(!is_wp_error($res), 'store allows approved -> archived');

$draft = $seed('draft');
$res = VES_Intelligence_Store::update_insight_status($draft, 'approved');
$ok(!is_wp_error($res), 'store allows draft -> approved when evidence present');

$draft_no_evidence = $seed('draft', true);
$res = VES_Intelligence_Store::update_insight_status($draft_no_evidence, 'approved');
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_intel_evidence_required', 'evidence gate still blocks promotion without evidence (matrix did not weaken it)');

$rejected2 = $seed('rejected');
$res = VES_Intelligence_Store::update_insight_status($rejected2, 'draft', ['reopened_reason' => 'new data'], ['allow_reopen' => true]);
$ok(!is_wp_error($res), 'store allows explicit reopen rejected -> draft');
$ok(($GLOBALS['wpdb']->data['wp_ves_intel_insights'][$rejected2]['status'] ?? '') === 'draft', 'reopened insight is back in draft (review), not approved');

// ── 3. Lifecycle service wrappers ───────────────────────────────────────────
require_once dirname(__DIR__) . '/includes/class-ves-insight-lifecycle-service.php';

$rejected3 = $seed('rejected');
$res = VES_Insight_Lifecycle_Service::reopen_insight($rejected3, '');
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_insight_reason_required', 'reopen without a reason is refused');

$res = VES_Insight_Lifecycle_Service::reopen_insight($rejected3, 'reviewer asked for second look');
$ok(!is_wp_error($res), 'reopen with reason succeeds');
$row = $GLOBALS['wpdb']->data['wp_ves_intel_insights'][$rejected3];
$ok($row['status'] === 'draft', 'service reopen lands on draft');
$meta = json_decode((string) $row['metadata'], true);
$ok(is_array($meta) && ($meta['reopened_reason'] ?? '') !== '' && isset($meta['reopened_by']), 'reopen is audited (reason + actor recorded)');

$archived2 = $seed('archived');
$res = VES_Insight_Lifecycle_Service::restore_insight($archived2, 'still relevant for Q3');
$ok(!is_wp_error($res), 'restore with reason succeeds');
$ok(($GLOBALS['wpdb']->data['wp_ves_intel_insights'][$archived2]['status'] ?? '') === 'draft', 'service restore lands on draft');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
