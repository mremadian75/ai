<?php
/**
 * Phase 9B.2 — usage settlement semantics contract (real class).
 *
 * Proves the compatibility layer over the existing ledger:
 *  1. canonical state mapping: reserved/completed/failed/voided/
 *     settlement_required/not_chargeable/diagnostic_only
 *  2. zero-delivery posted rows read not_chargeable, never completed-delivery
 *  3. settlement_required markers are append-only + idempotent; resolution
 *     timestamps, never deletes
 *  4. settlement_health reports stale reservations + open markers
 *  5. unknown stored statuses surface as settlement_required (never hidden)
 *
 * Run: php tests/test-ves-usage-settlement-9b.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function current_user_can($c){return true;}
$GLOBALS['__o']=[];

final class VES_Usage_Billing {
    public static function table_name() { return 'wp_ves_usage_events'; }
}
class SettleWpdb {
    public $rows = 0;
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$sql=preg_replace('/%d|%s|%f/',"'".addslashes((string)$a)."'",$sql,1);}return $sql;}
    public function get_var($sql){ return (string) $this->rows; }
}
$GLOBALS['wpdb'] = new SettleWpdb();

require_once dirname(__DIR__) . '/includes/class-ves-security-event-log.php';
require_once dirname(__DIR__) . '/includes/class-ves-usage-settlement.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Canonical mapping ─────────────────────────────────────────────────────
$ok(VES_Usage_Settlement::canonical_state(['status'=>'pending']) === 'reserved', 'pending maps to reserved');
$ok(VES_Usage_Settlement::canonical_state(['status'=>'posted']) === 'completed', 'posted maps to completed');
$ok(VES_Usage_Settlement::canonical_state(['status'=>'void']) === 'voided', 'void maps to voided');
$ok(VES_Usage_Settlement::canonical_state(['status'=>'reversed']) === 'voided', 'reversed maps to voided (append-only refund already recorded)');
$ok(VES_Usage_Settlement::canonical_state(['status'=>'failed']) === 'failed', 'failed maps to failed');
$ok(VES_Usage_Settlement::canonical_state(['status'=>'guest']) === 'diagnostic_only', 'guest events are diagnostic_only');
$ok(VES_Usage_Settlement::canonical_state(['status'=>'posted','context'=>['unlimited'=>1]]) === 'diagnostic_only', 'unlimited-admin events are diagnostic_only');

// ── 2. Zero delivery is never a completed charged delivery ──────────────────
$zero = ['status'=>'posted','context'=>json_encode(['settlement_classification'=>'not_chargeable_zero_delivery'])];
$ok(VES_Usage_Settlement::canonical_state($zero) === 'not_chargeable', 'zero-delivery posted row reads not_chargeable');
$flagged = ['status'=>'posted','context'=>['settlement_required'=>true]];
$ok(VES_Usage_Settlement::canonical_state($flagged) === 'settlement_required', 'flagged posted row reads settlement_required');

// ── 3. Unknown statuses surface, never hide ──────────────────────────────────
$ok(VES_Usage_Settlement::canonical_state(['status'=>'mystery_state']) === 'settlement_required', 'unknown stored status surfaces as settlement_required');

// ── 4. Markers: append-only, idempotent, resolvable ─────────────────────────
$ok(VES_Usage_Settlement::mark_settlement_required('key-A', 'provider charged, delivery ambiguous, token apify_api_HIDEME12345678'), 'marker created');
$ok(VES_Usage_Settlement::mark_settlement_required('key-A', 'duplicate call'), 'duplicate marker call is idempotent (no error)');
$open = VES_Usage_Settlement::open_settlement_markers();
$ok(count($open) === 1, 'exactly one open marker for the key');
$ok(strpos(json_encode($open), 'apify_api_HIDEME12345678') === false, 'marker reason is scrubbed');
$ok(VES_Usage_Settlement::mark_settlement_required('key-B', 'second run'), 'second key marks independently');
$ok(count(VES_Usage_Settlement::open_settlement_markers()) === 2, 'two open markers');

$ok(VES_Usage_Settlement::resolve_settlement_marker('key-A', 'operator reviewed; voided in ledger'), 'marker resolved');
$open = VES_Usage_Settlement::open_settlement_markers();
$ok(count($open) === 1 && $open[0]['usage_key'] === 'key-B', 'resolution closes only the targeted marker');
$all = get_option('ves_usage_settlement_required', []);
$ok(count($all) === 2 && !empty($all[0]['resolved_at']), 'resolution is an appended timestamp, not a deletion');

// ── 5. Health report ─────────────────────────────────────────────────────────
$GLOBALS['wpdb']->rows = 0;
$health = VES_Usage_Settlement::settlement_health();
$ok((int)$health['settlement_required'] === 1 && (bool)$health['healthy'] === false, 'open marker makes health unhealthy');
VES_Usage_Settlement::resolve_settlement_marker('key-B', 'resolved');
$GLOBALS['wpdb']->rows = 0;
$health = VES_Usage_Settlement::settlement_health();
$ok((bool)$health['healthy'] === true, 'all-resolved + no stale reservations is healthy');
$GLOBALS['wpdb']->rows = 3;
$health = VES_Usage_Settlement::settlement_health();
$ok((int)$health['reserved_stale'] === 3 && (bool)$health['healthy'] === false, 'stale pending reservations surface and unhealthy the report');

// ── 6. Probe ─────────────────────────────────────────────────────────────────
$ok(VES_Usage_Settlement::semantics_active() === true, 'semantics_active probe passes');
$src = file_get_contents(dirname(__DIR__) . '/includes/class-ves-usage-settlement.php');
$ok(strpos($src, 'never fabricates') !== false && strpos($src, 'never mutates ledger rows') !== false, 'no-fabrication guarantee documented in the class contract');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
