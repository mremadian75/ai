<?php
/**
 * Phase 2 — memory adapter test. Proves create_memory_record() DELEGATES to the
 * existing VES_Memory_Records store (extend, not duplicate) when it is available,
 * mapping canonical fields to that store's save_record() contract.
 *
 * Run: php tests/test-ves-intelligence-memory-adapter.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class WP_Error { public $code; public $message; public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function esc_url_raw($s) { return trim((string) $s); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function current_time($t = 'mysql', $g = 0) { return '2026-06-07 10:00:00'; }
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function get_current_user_id() { return 0; }
$GLOBALS['__opts'] = [];
$GLOBALS['wpdb'] = null; // force: if the adapter tried the fallback table it would fail loudly.

/** Stub of the existing memory store; captures the mapped args. */
final class VES_Memory_Records {
    public static $last = null;
    public static function table_name() { return 'wp_ves_memory_records'; }
    public static function save_record($args) { self::$last = $args; return 4242; }
}

require_once dirname(__DIR__) . '/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; }
};

$id = VES_Intelligence_Store::create_memory_record([
    'workspace_id' => 9, 'memory_type' => 'audience', 'text' => 'Buyers skew 25-34',
    'importance_score' => 77, 'status' => 'active', 'source_entity_type' => 'insight', 'source_entity_id' => '101',
]);
$ok($id === 4242, 'create_memory_record delegates to VES_Memory_Records::save_record (returns its id)');
$args = VES_Memory_Records::$last;
$ok(is_array($args) && (int) $args['workspace_id'] === 9, 'adapter maps workspace_id');
$ok(($args['memory_type'] ?? '') === 'audience' && ($args['summary'] ?? '') === 'Buyers skew 25-34', 'adapter maps memory_type + text->summary');
$ok(($args['source_type'] ?? '') === 'insight' && ($args['source_id'] ?? '') === '101', 'adapter maps source_entity_type/id -> source_type/source_id');
$ok((float) ($args['importance_score'] ?? 0) === 77.0, 'adapter maps importance_score');
$ok(isset($args['content']['_canonical_status']) && $args['content']['_canonical_status'] === 'active', 'adapter carries canonical status in content (no status column in existing store)');

// ── Fix 1: get/list delegation reads the EXISTING memory table + normalizes ──
class MemAdapterWpdb {
    public $prefix = 'wp_';
    public $rows = []; // table => [id=>row]
    public function prepare($sql, $args = []) {
        if (!is_array($args)) { $args = array_slice(func_get_args(), 1); }
        foreach ($args as $a) { $rep = is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'"; $sql = preg_replace('/%d|%s|%f/', $rep, $sql, 1); }
        return $sql;
    }
    public function get_row($sql, $o = null) {
        if (preg_match('/FROM (\S+) WHERE id = (\d+)/', $sql, $m)) { return $this->rows[$m[1]][(int) $m[2]] ?? null; }
        return null;
    }
    public function get_results($sql, $o = null) {
        if (preg_match('/FROM (\S+)/', $sql, $m)) { return array_reverse(array_values($this->rows[$m[1]] ?? [])); }
        return [];
    }
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
$mw = new MemAdapterWpdb();
$mw->rows['wp_ves_memory_records'][7] = [
    'id' => 7, 'workspace_id' => 9, 'memory_type' => 'audience', 'summary' => 'Buyers skew 25-34',
    'source_type' => 'insight', 'source_id' => '101', 'importance_score' => 77,
    'content_json' => json_encode(['_canonical_status' => 'active']), 'created_at' => '2026-06-07 10:00:00', 'updated_at' => '2026-06-07 10:00:00',
];
$GLOBALS['wpdb'] = $mw;

$g = VES_Intelligence_Store::get_memory_record(7);
$ok(is_array($g) && ($g['backing'] ?? '') === 'memory_records', 'Fix1: get_memory_record delegates to the existing memory table');
$ok(($g['text'] ?? '') === 'Buyers skew 25-34' && ($g['memory_type'] ?? '') === 'audience', 'Fix1: delegated read maps summary->text + memory_type');
$ok(($g['status'] ?? '') === 'active' && ($g['source_entity_type'] ?? '') === 'insight' && ($g['source_entity_id'] ?? '') === '101', 'Fix1: delegated read recovers canonical status + source entity');
$lst = VES_Intelligence_Store::list_memory_records(['workspace_id' => 9, 'limit' => 5]);
$ok(is_array($lst) && !empty($lst) && ($lst[0]['backing'] ?? '') === 'memory_records', 'Fix1: list_memory_records delegates + normalizes from existing store');

echo "\nVES intelligence memory-adapter tests: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
