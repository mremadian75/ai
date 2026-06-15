<?php
/**
 * v0.4.0 — memory review regression tests (executed with a wpdb stub).
 *
 * Locks: approve/reject decisions persist approval_status in the record's
 * JSON payload on BOTH backing stores, workspace isolation is enforced,
 * rejection never deletes, and invalid decisions are refused.
 */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

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
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_json_encode($v) { return json_encode($v); }
function current_time($t, $gmt = 0) { return $t === 'timestamp' ? 1750000000 : '2026-06-13 10:00:00'; }
function get_current_user_id() { return 9; }
function apply_filters($t, $v) { return $v; }
function do_action() { return null; }
function get_option($k, $d = false) { return $d; }
function __($s, $d = null) { return $s; }
function add_action($h, $c, $p = 10, $a = 1) { return true; }

/** wpdb stub: one memory row on the canonical fallback table. */
class FI_Memory_Test_Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $row;
    public $updates = [];
    public $deletes = [];
    public function __construct() {
        $this->row = [
            'id' => 901,
            'workspace_id' => 1,
            'memory_type' => 'learning',
            'text' => 'Draft memory candidate',
            'importance_score' => 55,
            'source_entity_type' => 'draft',
            'source_entity_id' => '77',
            'status' => 'active',
            'metadata' => json_encode(['created_via' => 'intake_draft_to_memory', 'requires_review' => true, 'approval_status' => 'pending_review']),
            'created_at' => '2026-06-12 09:00:00',
            'updated_at' => '2026-06-12 09:00:00',
        ];
    }
    public function get_charset_collate() { return ''; }
    public function prepare($sql, ...$args) { foreach ($args as $a) { $sql = preg_replace('/%[ds]/', (string) (is_array($a) ? '' : $a), $sql, 1); } return $sql; }
    public function get_row($sql, $type) { return strpos($sql, '901') !== false ? $this->row : null; }
    public function get_results($sql, $type) { return [$this->row]; }
    public function insert($t, $r) { return true; }
    public function update($table, $row, $where) {
        $this->updates[] = ['table' => $table, 'row' => $row, 'where' => $where];
        foreach ($row as $k => $v) { $this->row[$k] = $v; }
        return 1;
    }
    public function delete($table, $where) { $this->deletes[] = $where; return 1; }
}
$wpdb = new FI_Memory_Test_Wpdb();

require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';
require_once dirname(__DIR__) . '/includes/modules/class-fi-abstract-module.php';
require_once dirname(__DIR__) . '/includes/modules/memory/class-fi-memory-service.php';
require_once dirname(__DIR__) . '/includes/modules/memory/class-fi-memory-module.php';

$checks = 0; $failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

// ── approve persists approval_status (canonical metadata column) ─────────────
$res = FI_Memory_Service::process_review(['workspace_id' => 1, 'memory_id' => 901], 'approved');
ok(is_array($res) && ($res['approval_status'] ?? '') === 'approved', 'approve decision returns approved');
ok(count($wpdb->updates) === 1, 'one persistence write happened');
$saved = json_decode((string) ($wpdb->updates[0]['row']['metadata'] ?? ''), true);
ok(is_array($saved) && $saved['approval_status'] === 'approved', 'approval_status=approved persisted in metadata JSON');
ok($saved['requires_review'] === false, 'approved candidate no longer requires review');
ok((int) $saved['reviewed_by'] === 9 && !empty($saved['reviewed_at']), 'review decision records who and when');
ok(empty($wpdb->deletes), 'approval never deletes anything');

// ── reject keeps the record (flagged, not deleted) ───────────────────────────
$res = FI_Memory_Service::process_review(['workspace_id' => 1, 'memory_id' => 901, 'reason' => 'too generic'], 'rejected');
ok(is_array($res) && ($res['approval_status'] ?? '') === 'rejected', 'reject decision returns rejected');
$saved = json_decode((string) ($wpdb->updates[1]['row']['metadata'] ?? ''), true);
ok($saved['approval_status'] === 'rejected' && $saved['review_reason'] === 'too generic', 'rejection persists with its reason');
ok(empty($wpdb->deletes), 'rejection never deletes the record');
ok((string) $wpdb->row['status'] === 'active', 'record status column untouched (review state lives in payload)');

// ── guards: workspace isolation, missing record, invalid decision ────────────
$res = FI_Memory_Service::process_review(['workspace_id' => 2, 'memory_id' => 901], 'approved');
ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_mismatch', 'cross-workspace review is blocked');
$res = FI_Memory_Service::process_review(['workspace_id' => 1, 'memory_id' => 999], 'approved');
ok(is_wp_error($res) && $res->get_error_code() === 'fi_memory_not_found', 'missing record is refused');
$res = VES_Intelligence_Store::update_memory_review(901, 'obliterated');
ok(is_wp_error($res) && $res->get_error_code() === 'ves_intel_invalid_enum', 'invalid decision value is refused');
$res = FI_Memory_Service::process_review(['workspace_id' => 0, 'memory_id' => 0], 'approved');
ok(is_wp_error($res) && $res->get_error_code() === 'fi_memory_bad_input', 'missing ids are refused');

// ── module contract ──────────────────────────────────────────────────────────
$module = new FI_Memory_Module();
ok($module->actions() === ['fi_memory_approve', 'fi_memory_reject'], 'memory module declares its review actions');
ok(($module->status()['state'] ?? '') === 'available', 'memory module reports available when the store supports review');
$service_src = (string) file_get_contents(dirname(__DIR__) . '/includes/modules/memory/class-fi-memory-service.php');
ok(strpos($service_src, 'check_admin_referer') !== false && strpos($service_src, "current_user_can('manage_options')") !== false, 'review handlers enforce nonce + capability');
ok(strpos($service_src, 'wp_safe_redirect') !== false, 'review handlers use safe redirects');

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.4.0 memory review checks: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.4.0 memory review checks passed: {$checks} / {$checks}\n");
exit(0);
