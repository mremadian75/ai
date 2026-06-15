<?php
/**
 * Phase 2 unit tests — VES_Intelligence_Store canonical data contract.
 *
 * Self-contained: WP shims + an in-memory MockWpdb that understands the store's
 * query shapes (insert/update/get_row/get_var/get_results/prepare). No real DB,
 * no API calls. VES_Memory_Records is intentionally NOT defined here so the
 * memory FALLBACK path is exercised (adapter delegation is covered separately in
 * test-ves-intelligence-memory-adapter.php).
 *
 * Run: php tests/test-ves-intelligence-store.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class WP_Error {
    public $code; public $message;
    public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function esc_url_raw($s) { return trim((string) $s); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function current_time($t = 'mysql', $g = 0) { return '2026-06-07 10:00:00'; }
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function get_current_user_id() { return 0; }
function dbDelta($sql) { $GLOBALS['__ddl'][] = $sql; return []; }
$GLOBALS['__opts'] = [];

/** Minimal in-memory wpdb supporting exactly the store's query shapes. */
class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    private $auto = 0;
    public $data = []; // table => [id => row]
    public function get_charset_collate() { return ''; }
    public function query($sql) { return true; }
    public function insert($table, $row, $formats = null) {
        $this->auto++; $row['id'] = $this->auto;
        $this->data[$table][$this->auto] = $row; $this->insert_id = $this->auto; return 1;
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
    public function get_var($sql) {
        if (preg_match('/COUNT\(\*\) FROM (\S+)/', $sql, $m)) {
            $table = $m[1]; $rows = $this->data[$table] ?? [];
            if (preg_match('/workspace_id = (\d+)/', $sql, $w)) {
                $rows = array_filter($rows, function ($r) use ($w) { return (int) ($r['workspace_id'] ?? 0) === (int) $w[1]; });
            }
            return (string) count($rows);
        }
        if (preg_match('/SELECT (\w+) FROM (\S+) WHERE id = (\d+)/', $sql, $m)) {
            return $this->data[$m[2]][(int) $m[3]][$m[1]] ?? null;
        }
        return null;
    }
    public function get_row($sql, $output = OBJECT) {
        if (preg_match('/FROM (\S+) WHERE id = (\d+)/', $sql, $m)) {
            return $this->data[$m[1]][(int) $m[2]] ?? null;
        }
        return null;
    }
    public $last_sql = '';
    public function get_results($sql, $output = OBJECT) {
        $this->last_sql = $sql;
        if (!preg_match('/FROM (\S+)/', $sql, $m)) { return []; }
        $rows = array_values($this->data[$m[1]] ?? []);
        if (preg_match('/workspace_id = (\d+)/', $sql, $w)) {
            $rows = array_values(array_filter($rows, function ($r) use ($w) { return (int) ($r['workspace_id'] ?? 0) === (int) $w[1]; }));
        }
        return array_reverse($rows);
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
$GLOBALS['wpdb'] = new MockWpdb();

require_once dirname(__DIR__) . '/includes/class-ves-ai-usage-tracker.php'; // scrub_secrets reuse
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// 1. Migrations idempotent.
VES_Intelligence_Store::create_tables();
$v1 = get_option(VES_Intelligence_Store::VERSION_OPT);
VES_Intelligence_Store::create_tables();
$ok($v1 === VES_Intelligence_Store::DB_VERSION, 'create_tables sets schema version option (idempotent)');

// 13a. workspace required.
$ok(is_wp_error(VES_Intelligence_Store::create_source(['source_type' => 'web', 'provider' => 'manual'])), 'workspace_id is required (WP_Error when missing)');

// 2. source creation sanitizes + stores.
$sid = VES_Intelligence_Store::create_source([
    'workspace_id' => 5, 'source_type' => 'web', 'provider' => 'google',
    'source_url' => 'https://example.com/a', 'source_title' => '  Hello <b>World</b> ', 'raw_hash' => 'ABC123zz!!',
]);
$ok(is_int($sid) && $sid > 0, 'source created (returns int id)');
$src = VES_Intelligence_Store::get_source($sid);
$ok(($src['source_title'] ?? '') === 'Hello World' && ($src['source_type'] ?? '') === 'web', 'source fields sanitized + stored');
$ok(preg_match('/^[a-f0-9]+$/', (string) ($src['raw_hash'] ?? '')) === 1, 'raw_hash is hex-sanitized');

// 3. signal enum validation.
$ok(is_wp_error(VES_Intelligence_Store::create_signal(['workspace_id' => 5, 'signal_type' => 'not_a_type', 'title' => 'x'])), 'signal creation validates enum (invalid signal_type rejected)');
$sigid = VES_Intelligence_Store::create_signal(['workspace_id' => 5, 'source_id' => $sid, 'signal_type' => 'trend', 'title' => 'Rising topic', 'confidence_score' => 80]);
$ok(is_int($sigid) && $sigid > 0, 'valid signal created');

// 4. evidence stores citable text.
$eid = VES_Intelligence_Store::create_evidence(['workspace_id' => 5, 'source_id' => $sid, 'signal_id' => $sigid, 'evidence_type' => 'quote', 'text' => 'Customers love X', 'source_url' => 'https://example.com/a']);
$ev = VES_Intelligence_Store::get_evidence($eid);
$ok(is_int($eid) && $eid > 0 && ($ev['text'] ?? '') === 'Customers love X', 'evidence stores citable text');
$eid2 = VES_Intelligence_Store::create_evidence(['workspace_id' => 5, 'evidence_type' => 'metric', 'text' => '42% lift']);
$ok(is_wp_error(VES_Intelligence_Store::create_evidence(['workspace_id' => 5, 'evidence_type' => 'quote'])), 'evidence requires text (WP_Error when missing)');

// 5. reviewed/approved insight without evidence rejected.
$ok(is_wp_error(VES_Intelligence_Store::create_insight(['workspace_id' => 5, 'insight_type' => 'opportunity', 'title' => 'No evidence', 'status' => 'approved'])), 'reviewed/approved insight without evidence is rejected (B5)');

// 6. draft insight without evidence allowed.
$draftInsight = VES_Intelligence_Store::create_insight(['workspace_id' => 5, 'insight_type' => 'trend', 'title' => 'Draft idea', 'status' => 'draft']);
$ok(is_int($draftInsight) && $draftInsight > 0, 'draft insight without evidence is allowed (B5)');

// 7. insight with evidence allowed (approved).
$insight = VES_Intelligence_Store::create_insight(['workspace_id' => 5, 'insight_type' => 'opportunity', 'title' => 'Backed', 'status' => 'approved', 'evidence_ids' => [$eid], 'signal_ids' => [$sigid], 'confidence_score' => 70]);
$ok(is_int($insight) && $insight > 0, 'approved insight WITH evidence is allowed');

// B5 validator + link helper.
$val = VES_Intelligence_Store::validate_insight_evidence($insight);
$ok($val['has_evidence'] === true && $val['evidence_count'] === 1, 'validate_insight_evidence reports evidence presence/count');
VES_Intelligence_Store::link_evidence_to_insight($eid2, $insight);
$val2 = VES_Intelligence_Store::validate_insight_evidence($insight);
$ok($val2['evidence_count'] === 2, 'link_evidence_to_insight appends to JSON array');

// 8. brief links insight + evidence.
$brief = VES_Intelligence_Store::create_brief(['workspace_id' => 5, 'insight_id' => $insight, 'brief_type' => 'content', 'title' => 'Brief A', 'objective' => 'Win Q3', 'evidence_ids' => [$eid]]);
$ok(is_int($brief) && $brief > 0, 'brief created linked to insight');
$ok(VES_Intelligence_Store::link_evidence_to_brief($eid2, $brief) === true, 'link_evidence_to_brief works');
$briefRow = VES_Intelligence_Store::get_brief($brief);
$ok((int) ($briefRow['insight_id'] ?? 0) === $insight && count($briefRow['evidence_ids'] ?? []) === 2, 'brief stores insight_id + linked evidence');

// 9. draft links brief + evidence.
$draft = VES_Intelligence_Store::create_draft(['workspace_id' => 5, 'brief_id' => $brief, 'draft_type' => 'post', 'body' => 'Draft body text', 'model' => 'gpt-4.1-mini', 'evidence_ids' => [$eid]]);
$ok(is_int($draft) && $draft > 0, 'draft created linked to brief');
$drow = VES_Intelligence_Store::get_draft($draft);
$ok((int) ($drow['brief_id'] ?? 0) === $brief && ($drow['body'] ?? '') === 'Draft body text', 'draft stores brief_id + body');

// 10. memory fallback path (VES_Memory_Records not defined here).
$mem = VES_Intelligence_Store::create_memory_record(['workspace_id' => 5, 'memory_type' => 'brand', 'text' => 'Tone is playful', 'importance_score' => 80, 'status' => 'active', 'source_entity_type' => 'insight', 'source_entity_id' => (string) $insight]);
$ok(is_int($mem) && $mem > 0, 'memory record created via canonical fallback');
$ok(is_wp_error(VES_Intelligence_Store::create_memory_record(['workspace_id' => 5, 'memory_type' => 'bogus', 'text' => 'x'])), 'memory validates memory_type enum');

// 11. metadata scrubbing.
$sid2 = VES_Intelligence_Store::create_source(['workspace_id' => 5, 'source_type' => 'api', 'provider' => 'openai', 'metadata' => ['api_key' => 'sk-LEAK1234567890', 'prompt' => 'secret prompt', 'note' => 'token Bearer abc.def', 'ok_field' => 'fine']]);
$srcMeta = VES_Intelligence_Store::get_source($sid2);
$mj = json_encode($srcMeta['metadata'] ?? []);
$ok(strpos($mj, 'sk-LEAK') === false && strpos($mj, 'secret prompt') === false, 'metadata scrubbing removes secrets + raw prompt');
$ok(!isset($srcMeta['metadata']['api_key']) && !isset($srcMeta['metadata']['prompt']) && ($srcMeta['metadata']['ok_field'] ?? '') === 'fine', 'forbidden metadata keys dropped, safe keys kept');

// 12. counts structure.
$counts = VES_Intelligence_Store::counts(5);
$ok(isset($counts['sources'], $counts['signals'], $counts['evidences'], $counts['insights'], $counts['briefs'], $counts['drafts'], $counts['memory_records']), 'counts() returns expected entity keys');
$ok($counts['insights'] >= 2 && $counts['drafts'] >= 1, 'counts() reflects created records');

// recent insights structure.
$recent = VES_Intelligence_Store::recent_insights(10, 5);
$ok(!empty($recent) && isset($recent[0]['evidence_count'], $recent[0]['status'], $recent[0]['title']), 'recent_insights returns admin-ready rows');

// B6 projector: ingest_bundle wires the whole chain in one call.
$bundle = VES_Intelligence_Store::ingest_bundle([
    'workspace_id' => 5, 'user_id' => 3,
    'source'   => ['source_type' => 'social', 'provider' => 'tiktok', 'source_title' => 'Run 99'],
    'signals'  => [['signal_type' => 'trend', 'title' => 'Spike in #x', 'confidence_score' => 60]],
    'evidence' => [['evidence_type' => 'metric', 'text' => 'views +120%']],
    'insight'  => ['insight_type' => 'trend', 'title' => 'X is rising', 'status' => 'reviewed', 'confidence_score' => 65],
    'draft'    => ['draft_type' => 'report_section', 'body' => 'X is rising because...'],
]);
$ok($bundle['source_id'] > 0 && count($bundle['signal_ids']) === 1 && count($bundle['evidence_ids']) === 1, 'ingest_bundle creates source/signal/evidence');
$ok($bundle['insight_id'] > 0 && empty($bundle['errors']), 'ingest_bundle creates a reviewed insight WITH auto-linked evidence (enforcement satisfied)');
$ok($bundle['draft_id'] > 0, 'ingest_bundle creates a draft');
$insRow = VES_Intelligence_Store::get_insight($bundle['insight_id']);
$ok(count($insRow['evidence_ids'] ?? []) === 1 && count($insRow['signal_ids'] ?? []) === 1, 'ingest_bundle auto-links evidence_ids + signal_ids onto the insight');
// Missing workspace returns errors, never throws.
$bad = VES_Intelligence_Store::ingest_bundle(['source' => ['source_type' => 'web', 'provider' => 'manual']]);
$ok($bad['source_id'] === 0 && in_array('missing_workspace', $bad['errors'], true), 'ingest_bundle without workspace returns errors (no throw)');

// ── Fix 3: status filter guard for no-status tables ──────────────────────────
$wp = $GLOBALS['wpdb'];
VES_Intelligence_Store::list_sources(['status' => 'draft', 'workspace_id' => 5]);
$ok(strpos($wp->last_sql, 'status =') === false, 'Fix3: list_sources(status) does NOT add a status clause (no SQL error)');
VES_Intelligence_Store::list_signals(['status' => 'draft']);
$ok(strpos($wp->last_sql, 'status =') === false, 'Fix3: list_signals(status) does NOT add a status clause');
VES_Intelligence_Store::list_evidence(['status' => 'draft']);
$ok(strpos($wp->last_sql, 'status =') === false, 'Fix3: list_evidence(status) does NOT add a status clause');
VES_Intelligence_Store::list_insights(['status' => 'approved']);
$ok(strpos($wp->last_sql, 'status =') !== false, 'Fix3: list_insights(status) STILL applies the status clause');
VES_Intelligence_Store::list_briefs(['status' => 'draft']);
$ok(strpos($wp->last_sql, 'status =') !== false, 'Fix3: list_briefs(status) still applies the status clause');
VES_Intelligence_Store::list_drafts(['status' => 'generated']);
$ok(strpos($wp->last_sql, 'status =') !== false, 'Fix3: list_drafts(status) still applies the status clause');

// ── Fix 1: memory facade get/list (fallback path; VES_Memory_Records not defined) ──
$m1 = VES_Intelligence_Store::create_memory_record(['workspace_id' => 5, 'memory_type' => 'brand', 'text' => 'Voice is bold', 'status' => 'active']);
$m2 = VES_Intelligence_Store::create_memory_record(['workspace_id' => 5, 'memory_type' => 'audience', 'text' => 'Gen Z core', 'status' => 'superseded']);
$got = VES_Intelligence_Store::get_memory_record((int) $m1);
$ok(is_array($got) && ($got['text'] ?? '') === 'Voice is bold' && ($got['memory_type'] ?? '') === 'brand' && ($got['status'] ?? '') === 'active', 'Fix1: get_memory_record returns normalized canonical shape');
$ok($got['backing'] === 'intel_memory', 'Fix1: get_memory_record reports fallback backing when VES_Memory_Records absent');
$ok(VES_Intelligence_Store::get_memory_record(999999) === null, 'Fix1: get_memory_record returns null for missing id');
$mlist = VES_Intelligence_Store::list_memory_records(['workspace_id' => 5, 'limit' => 10]);
$ok(is_array($mlist) && count($mlist) >= 2 && isset($mlist[0]['text'], $mlist[0]['memory_type'], $mlist[0]['status']), 'Fix1: list_memory_records returns normalized rows');
// Filters must not error.
$flt = VES_Intelligence_Store::list_memory_records(['workspace_id' => 5, 'memory_type' => 'brand', 'status' => 'active', 'source_entity_type' => 'insight', 'limit' => 5, 'offset' => 0]);
$ok(is_array($flt), 'Fix1: list_memory_records with all filters does not error');

echo "\nVES intelligence store tests: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
