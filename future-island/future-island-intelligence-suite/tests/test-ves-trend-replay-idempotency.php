<?php
/**
 * v0.1 RC — trend replay / backfill IDEMPOTENCY contract (real classes).
 *
 * Exercises the REAL VES_Trend_Observation_Store + VES_Trend_Backfill_Service
 * (no stubs of the code under test) against an in-memory wpdb to prove:
 *  1. dry-run writes NOTHING (no rows, no done-marker)
 *  2. apply persists observations and marks the report done
 *  3. REPLAYING the same report is skipped (already_backfilled) — observation
 *     values are NOT double-counted
 *  4. observation row dedup: same canonical observation maps to the same row id
 *  5. a failed-insert run leaves the report unmarked so a later run can recover,
 *     and that later successful run still ends idempotent
 *
 * Run: php tests/test-ves-trend-replay-idempotency.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$s)));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function esc_url_raw($s){return trim((string)$s);}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return '2026-06-11 09:00:00';}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

final class FIDTF_DB { public static function table($k) { return 'wp_fi_dtf_' . ($k === 'runs' ? 'runs' : 'reports'); } }

class TrendWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public $fail_inserts=false; // simulate transient insert failures
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function insert($t,$r,$f=null){if($this->fail_inserts)return false;$this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;}
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows($sql){
        if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]];
        $table=$m[1];$rows=$this->data[$table]??[];
        $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql));
        $where=preg_split('/ORDER BY| LIMIT| GROUP BY/',$where)[0];
        preg_match_all("/(\w+)\s*=\s*'?([^'\s]+)'?/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){if(ctype_digit($p[1]))continue;$conds[$p[1]]=$p[2];}
        $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}if($ok)$out[]=$r;}
        return [$table,$out];
    }
    public function get_var($sql){
        if(preg_match('/COUNT\(\*\) FROM/',$sql)){[$t,$rows]=$this->rows($sql);return (string)count($rows);}
        if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows($sql);return $rows?($rows[0][$sm[1]]??null):null;}
        return null;
    }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){[$t,$rows]=$this->rows($sql);return array_values($rows);}
}
$GLOBALS['wpdb']=new TrendWpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-observation-store.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-golden-set.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-backfill-service.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$obs_table = 'wp_ves_trend_observations';
$obs_total = function () use ($obs_table) { return count($GLOBALS['wpdb']->data[$obs_table] ?? []); };
$obs_value_sum = function () use ($obs_table) {
    $sum = 0.0;
    foreach (($GLOBALS['wpdb']->data[$obs_table] ?? []) as $r) { $sum += (float) ($r['value_number'] ?? 0); }
    return $sum;
};

// Seed one historical DTF report: 2 keyword terms (counts 5 and 3).
$GLOBALS['wpdb']->data['wp_fi_dtf_reports'][101] = [
    'id' => 101,
    'final_synthesis_json' => json_encode(['top_terms' => [['label' => 'ai agents', 'count' => 5], ['label' => 'rag', 'count' => 3]]]),
];
$candidate = ['report_id' => 101, 'run_id' => 11, 'workspace_id' => 1, 'created_at' => '2026-05-01 00:00:00', 'reason' => 'eligible'];

// ── 1. Dry-run writes nothing ────────────────────────────────────────────────
$res = VES_Trend_Backfill_Service::backfill_report($candidate, ['dry_run' => true]);
$ok(($res['reason'] ?? '') === 'eligible' && (int) $res['created'] === 2, 'dry-run reports 2 extractable observations');
$ok($obs_total() === 0, 'dry-run persisted ZERO observation rows');
$ok(get_option('ves_trend_backfill_done', []) === [], 'dry-run did not mark the report done');

// ── 2. Apply persists + marks done ───────────────────────────────────────────
$res = VES_Trend_Backfill_Service::backfill_report($candidate, []);
$ok((int) $res['created'] === 2 && ($res['reason'] ?? '') === 'eligible', 'apply created 2 observations');
$ok($obs_total() === 2, 'two observation rows persisted');
$sum_after_apply = $obs_value_sum();
$ok(abs($sum_after_apply - 8.0) < 0.0001, 'observation values total 8.0 (5 + 3)');
$ok(in_array(101, (array) get_option('ves_trend_backfill_done', []), true), 'report marked done after apply');

// ── 3. REPLAY is skipped — no double count ───────────────────────────────────
$res = VES_Trend_Backfill_Service::backfill_report($candidate, []);
$ok(($res['reason'] ?? '') === 'already_backfilled', 'replay of the same report is skipped (already_backfilled)');
$ok((int) $res['created'] === 0, 'replay created zero observations');
$ok($obs_total() === 2, 'replay added no rows');
$ok(abs($obs_value_sum() - $sum_after_apply) < 0.0001, 'replay did NOT double-count observation values');

// ── 4. Observation row dedup: same canonical observation → same row id ──────
$obs = ['workspace_id' => 9, 'observation_type' => 'keyword', 'term' => 'island marketing', 'platform' => 'tiktok', 'provider' => 'apify', 'observed_at' => '2026-06-01 12:00:00', 'value_number' => 2.0, 'raw_count' => 2];
$id1 = VES_Trend_Observation_Store::create_or_get_observation($obs);
$id2 = VES_Trend_Observation_Store::create_or_get_observation($obs);
$ok(!is_wp_error($id1) && !is_wp_error($id2) && (int) $id1 === (int) $id2, 'same canonical observation resolves to the SAME row (no duplicate rows)');
$dup_rows = 0;
foreach (($GLOBALS['wpdb']->data[$obs_table] ?? []) as $r) { if (($r['term'] ?? '') === 'island marketing') { $dup_rows++; } }
$ok($dup_rows === 1, 'exactly one row exists for the deduped observation');

// ── 5. Failed inserts leave the report recoverable, then converge ───────────
$GLOBALS['wpdb']->data['wp_fi_dtf_reports'][102] = [
    'id' => 102,
    'final_synthesis_json' => json_encode(['top_terms' => [['label' => 'beach clubs', 'count' => 4]]]),
];
$candidate2 = ['report_id' => 102, 'run_id' => 12, 'workspace_id' => 1, 'created_at' => '2026-05-02 00:00:00', 'reason' => 'eligible'];
$GLOBALS['wpdb']->fail_inserts = true;
$res = VES_Trend_Backfill_Service::backfill_report($candidate2, []);
$ok(($res['reason'] ?? '') === 'insert_failed' && (int) $res['created'] === 0, 'all-inserts-failed run reports insert_failed');
$ok(!in_array(102, (array) get_option('ves_trend_backfill_done', []), true), 'failed run does NOT mark the report done (recoverable)');
$GLOBALS['wpdb']->fail_inserts = false;
$res = VES_Trend_Backfill_Service::backfill_report($candidate2, []);
$ok((int) $res['created'] === 1, 'recovery run persists the observation');
$res = VES_Trend_Backfill_Service::backfill_report($candidate2, []);
$ok(($res['reason'] ?? '') === 'already_backfilled', 'post-recovery replay converges to already_backfilled');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
