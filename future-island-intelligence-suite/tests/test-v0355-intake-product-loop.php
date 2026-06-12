<?php
/**
 * v0.3.55 — intake product-loop regression tests (executed with store stubs).
 *
 * Locked-down failures:
 *  1. The intake surface could create draft insights but never approve them —
 *     Insight → Brief was unreachable without leaving the loop. Approve/Reject
 *     now exist here and still go through the audited lifecycle service.
 *  2. Drafts could never become approved from intake, so Memory/Usage steps
 *     were dead ends. Approve-output now exists via the store transition ledger.
 *  3. Memory candidates were recorded as plain 'active' with no approval state.
 *     They now carry approval_status=pending_review in metadata.
 *  4. The normal path required typing object ids; id forms are now a collapsed
 *     debug panel and every pipeline row carries its own next action.
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
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s))); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($s) { return trim((string) $s); }
function get_current_user_id() { return 7; }
function get_transient($k) { global $t_store; return $t_store[$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { global $t_store; $t_store[$k] = $v; return true; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $echo = true) { return '<input type="hidden" name="_wpnonce" value="test-nonce-' . esc_attr($action) . '">'; }
function wp_parse_url($u) { return parse_url($u); }
$t_store = [];

/** Store stub capturing every write so assertions read real call payloads. */
class VES_Intelligence_Store {
    const INSIGHT_TYPES = ['opportunity', 'risk', 'content_pattern', 'other'];
    public static $insights = [];
    public static $drafts = [];
    public static $memory_payloads = [];
    public static $draft_status_calls = [];
    public static function sanitize_insight_type($t) { return in_array($t, self::INSIGHT_TYPES, true) ? $t : 'other'; }
    public static function sanitize_opportunity_score($v) { return max(0, min(100, (int) $v)); }
    public static function get_insight($id) { return self::$insights[$id] ?? null; }
    public static function get_draft($id) { return self::$drafts[$id] ?? null; }
    public static function get_source($id) { return ['id' => $id, 'workspace_id' => 1, 'source_url' => '', 'source_title' => 'Src', 'provider' => 'operator']; }
    public static function get_signal($id) { return null; }
    public static function get_brief($id) { return null; }
    public static function list_memory_records($args) { return []; }
    public static function list_drafts($args) { return array_values(self::$drafts); }
    public static function list_sources($a) { return []; }
    public static function list_signals($a) { return []; }
    public static function list_insights($a) { return array_values(self::$insights); }
    public static function list_briefs($a) { return []; }
    public static function create_memory_record($payload) { self::$memory_payloads[] = $payload; return 901; }
    public static function update_draft_status($id, $status, $meta = []) {
        self::$draft_status_calls[] = ['id' => $id, 'status' => $status, 'meta' => $meta];
        if (!isset(self::$drafts[$id])) { return new WP_Error('ves_intel_not_found', 'Draft not found.'); }
        $from = (string) self::$drafts[$id]['status'];
        $matrix = ['generated' => ['approved', 'rejected', 'edited'], 'edited' => ['approved', 'rejected'], 'approved' => [], 'rejected' => []];
        if (!in_array($status, $matrix[$from] ?? [], true)) { return new WP_Error('ves_intel_transition_blocked', 'blocked'); }
        self::$drafts[$id]['status'] = $status;
        return true;
    }
    public static function insight_presentation_state($i) { return (string) ($i['status'] ?? 'draft'); }
}

/** Lifecycle stub: approve enforces an evidence gate exactly like production. */
class VES_Insight_Lifecycle_Service {
    public static $approve_calls = [];
    public static $reject_calls = [];
    public static function approve_insight($id, $args = []) {
        self::$approve_calls[] = ['id' => $id, 'args' => $args];
        $insight = VES_Intelligence_Store::$insights[$id] ?? null;
        if (!$insight || count((array) ($insight['evidence_ids'] ?? [])) === 0) {
            return new WP_Error('ves_insight_evidence_required', 'Cannot approve: minimum evidence requirements not met.');
        }
        VES_Intelligence_Store::$insights[$id]['status'] = 'approved';
        return true;
    }
    public static function reject_insight($id, $reason = '') {
        self::$reject_calls[] = ['id' => $id, 'reason' => $reason];
        if (!isset(VES_Intelligence_Store::$insights[$id])) { return new WP_Error('ves_intel_not_found', 'missing'); }
        VES_Intelligence_Store::$insights[$id]['status'] = 'rejected';
        return true;
    }
}

class VES_AI_Usage_Tracker {
    public static $records = [];
    public static function record($payload) { self::$records[] = $payload; return count(self::$records); }
}

require_once __DIR__ . '/../includes/class-ves-source-intake.php';

$checks = 0; $failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

// ── 1. insight approval doorway (gates still enforced) ──────────────────────
VES_Intelligence_Store::$insights = [
    41 => ['id' => 41, 'workspace_id' => 1, 'status' => 'draft', 'insight_type' => 'opportunity', 'title' => 'No-evidence insight', 'evidence_ids' => []],
    42 => ['id' => 42, 'workspace_id' => 1, 'status' => 'draft', 'insight_type' => 'opportunity', 'title' => 'Evidence-backed insight', 'evidence_ids' => [9]],
];
$res = VES_Source_Intake::process_approve_insight(['workspace_id' => 1, 'insight_id' => 41]);
ok(is_wp_error($res) && $res->get_error_code() === 'ves_insight_evidence_required', 'approval without evidence is refused by the lifecycle gate');
ok(VES_Intelligence_Store::$insights[41]['status'] === 'draft', 'refused insight stays draft (no fake success)');

$res = VES_Source_Intake::process_approve_insight(['workspace_id' => 1, 'insight_id' => 42]);
ok(is_array($res) && $res['insight_id'] === 42, 'evidence-backed insight approves from intake');
ok(VES_Intelligence_Store::$insights[42]['status'] === 'approved', 'insight is approved through the audited lifecycle');
ok(count(VES_Insight_Lifecycle_Service::$approve_calls) === 2, 'approval goes through VES_Insight_Lifecycle_Service (never a direct status write)');

$res = VES_Source_Intake::process_reject_insight(['workspace_id' => 1, 'insight_id' => 41, 'reason' => 'weak']);
ok(is_array($res) && VES_Intelligence_Store::$insights[41]['status'] === 'rejected', 'reject works from intake');

// Workspace guard: a foreign-workspace insight cannot be approved.
VES_Intelligence_Store::$insights[43] = ['id' => 43, 'workspace_id' => 2, 'status' => 'draft', 'evidence_ids' => [4]];
$res = VES_Source_Intake::process_approve_insight(['workspace_id' => 1, 'insight_id' => 43]);
ok(is_wp_error($res) && $res->get_error_code() === 'ves_workspace_mismatch', 'cross-workspace approval is blocked');

// ── 2. draft approval unlocks memory/usage ───────────────────────────────────
VES_Intelligence_Store::$drafts = [
    77 => ['id' => 77, 'workspace_id' => 1, 'status' => 'generated', 'title' => 'World Cup content draft', 'body' => 'Body', 'brief_id' => 5],
];
$res = VES_Source_Intake::process_record_usage_event(['workspace_id' => 1, 'draft_id' => 77]);
ok(is_wp_error($res) && $res->get_error_code() === 'ves_intake_draft_not_approved', 'usage event refused while draft is unapproved');

$res = VES_Source_Intake::process_approve_draft(['workspace_id' => 1, 'draft_id' => 77]);
ok(is_array($res) && VES_Intelligence_Store::$drafts[77]['status'] === 'approved', 'draft approves from intake through the transition ledger');

$res = VES_Source_Intake::process_record_usage_event(['workspace_id' => 1, 'draft_id' => 77]);
ok(is_array($res) && $res['usage_event_id'] === 1, 'usage event records once the draft is approved');
$usage = VES_AI_Usage_Tracker::$records[0];
ok($usage['workspace_id'] === 1 && $usage['user_id'] === 7, 'usage event carries workspace and user');
ok($usage['metadata']['target_type'] === 'draft' && $usage['metadata']['target_id'] === 77, 'usage event is tied to the concrete object');
ok($usage['run_id'] === 'fi-intake-draft-77', 'usage event carries a trace reference');
$res2 = VES_Source_Intake::process_record_usage_event(['workspace_id' => 1, 'draft_id' => 77]);
ok(is_array($res2) && $res2['usage_event_id'] === 1 && count(VES_AI_Usage_Tracker::$records) === 1, 'usage event is idempotent on retry');

// ── 3. memory candidate carries an approval state ────────────────────────────
$res = VES_Source_Intake::process_draft_to_memory(['workspace_id' => 1, 'draft_id' => 77]);
ok(is_array($res) && $res['memory_id'] === 901, 'memory candidate created from draft');
$memory = VES_Intelligence_Store::$memory_payloads[0];
ok(($memory['metadata']['approval_status'] ?? '') === 'pending_review', 'memory candidate carries approval_status=pending_review');
ok(!empty($memory['metadata']['requires_review']), 'memory candidate is flagged for review');
ok($memory['source_entity_type'] === 'draft' && $memory['source_entity_id'] === '77', 'memory candidate references its source draft');

// ── 4. UI: id forms are debug-only; rows carry the action rail ───────────────
$html = VES_Source_Intake::render_html(1);
ok(strpos($html, 'fi-room-grid') !== false && strpos($html, 'fi-room-rail') !== false, 'intake renders the signal-room layout (pipeline + rail)');
ok(strpos($html, 'fi-intake-advanced') !== false, 'by-id forms are inside the advanced debug panel');
$advanced_pos = strpos($html, '<details class="fi-intake-advanced"');
$typed_id_field_pos = strpos($html, 'type="number" name="insight_id"');
ok($advanced_pos !== false && $typed_id_field_pos !== false && $typed_id_field_pos > $advanced_pos, 'typed insight-id input only exists inside the advanced debug panel');
ok(strpos($html, VES_Source_Intake::ACTION_APPROVE_INSIGHT) !== false, 'approve-insight action is wired in the page');
ok(strpos($html, VES_Source_Intake::ACTION_REJECT_INSIGHT) !== false, 'reject-insight action is wired in the page');
ok(strpos($html, 'fi-brief-workbench') !== false, 'insights link into the brief workbench');
ok(substr_count($html, 'name="_wpnonce"') >= 8, 'every state-changing form carries a nonce');

// Approved insight row offers Build brief; rejected row is terminal.
ok(strpos($html, 'Build brief') !== false, 'approved insight row offers Build brief');
ok(strpos($html, 'Rejected — terminal state') !== false, 'rejected insight row shows a terminal state, not a fake action');

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.3.55 intake product-loop checks: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.3.55 intake product-loop checks passed: {$checks} / {$checks}\n");
exit(0);
