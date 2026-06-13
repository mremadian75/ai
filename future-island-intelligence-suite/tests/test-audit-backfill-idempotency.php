<?php
/**
 * Audit regression — VES_Trend_Backfill_Service must NOT mark a report "done" when
 * every observation insert failed (e.g. a transient DB error). Otherwise the report
 * is skipped forever and the backfill can never recover. A later successful run must
 * still be able to mark it done.
 *
 * Run: php tests/test-audit-backfill-idempotency.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c = '', $m = '', $d = []) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_text_field($s) { return trim((string) $s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function current_time($t = 'mysql', $g = 0) { return gmdate('Y-m-d H:i:s'); }
function get_option($k, $d = false) { return $GLOBALS['__o'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__o'][$k] = $v; return true; }
$GLOBALS['__o'] = [];

final class FIDTF_DB { public static function table($k) { return 'wp_fi_dtf_' . ($k === 'runs' ? 'runs' : 'reports'); } }

// Toggleable observation store: when $fail is true, every insert returns WP_Error.
final class VES_Trend_Observation_Store {
    public static $fail = false;
    public static $created = 0;
    public static function create_or_get_observation($obs) {
        if (self::$fail) { return new WP_Error('db_fail', 'transient insert failure'); }
        self::$created++;
        return self::$created;
    }
    public static function total($ws = 0) { return self::$created; }
}

class MiniWpdb {
    public $prefix = 'wp_';
    public $data = [];
    public function prepare($sql, $args = []) { if (!is_array($args)) { $args = array_slice(func_get_args(), 1); } foreach ($args as $a) { $rep = is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'"; $sql = preg_replace('/%d|%s|%f/', $rep, $sql, 1); } return $sql; }
    public function get_var($sql) {
        if (preg_match('/final_synthesis_json FROM \S+ WHERE id = (\d+)/', $sql, $m)) {
            return $this->data['wp_fi_dtf_reports'][(int) $m[1]]['final_synthesis_json'] ?? null;
        }
        return null;
    }
}
$wpdb = new MiniWpdb(); $GLOBALS['wpdb'] = $wpdb;
$wpdb->data['wp_fi_dtf_reports'][1] = ['id' => 1, 'final_synthesis_json' => json_encode(['top_terms' => [['label' => 'ai agents', 'count' => 5], ['label' => 'rag', 'count' => 3]]])];

require_once dirname(__DIR__) . '/includes/class-ves-trend-backfill-service.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };

$candidate = ['report_id' => 1, 'run_id' => 1, 'reason' => 'eligible', 'workspace_id' => 9, 'created_at' => '2026-05-01 11:00:00'];

// 1. All inserts fail → report must NOT be marked done.
VES_Trend_Observation_Store::$fail = true;
$r1 = VES_Trend_Backfill_Service::backfill_report($candidate, ['dry_run' => false, 'backfill_run_id' => 'bk1']);
$ok(($r1['reason'] ?? '') === 'insert_failed' && (int) ($r1['created'] ?? -1) === 0, '1: total insert failure reports insert_failed with 0 created');
$ok(!in_array(1, $GLOBALS['__o']['ves_trend_backfill_done'] ?? [], true), '1: report is NOT marked done after total failure (can retry)');

// 2. Retry now succeeds → report IS marked done and observations persist.
VES_Trend_Observation_Store::$fail = false;
$r2 = VES_Trend_Backfill_Service::backfill_report($candidate, ['dry_run' => false, 'backfill_run_id' => 'bk2']);
$ok(($r2['reason'] ?? '') === 'eligible' && (int) $r2['created'] === 2, '2: retry succeeds and creates the 2 observations');
$ok(in_array(1, $GLOBALS['__o']['ves_trend_backfill_done'] ?? [], true), '2: report IS marked done after a successful run');

// 3. Third run is idempotent (already_backfilled, no new inserts).
$before = VES_Trend_Observation_Store::$created;
$r3 = VES_Trend_Backfill_Service::backfill_report($candidate, ['dry_run' => false]);
$ok(($r3['reason'] ?? '') === 'already_backfilled' && VES_Trend_Observation_Store::$created === $before, '3: subsequent run is idempotent (no new observations)');

echo "\nBackfill idempotency audit: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
