<?php
/**
 * Phase 9B.1 — unified review decision ledger contract (real classes).
 *
 * Proves:
 *  1. an ALLOWED insight transition appends exactly one ledger decision
 *  2. a BLOCKED transition appends none (it becomes a security event instead)
 *  3. brief/draft status updates use the shared matrix and write the ledger
 *  4. memory approve/reject/pin/unpin writes decisions; resurrection is blocked
 *  5. duplicate idempotency keys collapse to the same row (DB unique + race)
 *  6. the ledger is append-only (no update/delete API) and scrubs secrets
 *  7. reopen/restore decisions are labeled and audited
 *
 * Run: php tests/test-ves-review-decision-ledger-9b.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$__abs = sys_get_temp_dir() . '/fiis_ledger_9b_' . getmypid() . '/';
@mkdir($__abs . 'wp-admin/includes', 0777, true);
@file_put_contents($__abs . 'wp-admin/includes/upgrade.php', "<?php\n");
if (!defined('ABSPATH')) { define('ABSPATH', $__abs); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

class WP_Error { public $code; public $message; public $data; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$s)));}
function sanitize_textarea_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function esc_url_raw($s){return trim((string)$s);}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return '2026-06-14 11:00:00';}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function dbDelta($s){return [];}
function get_current_user_id(){return 9;}
$GLOBALS['__o']=[];

class LedgerWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function insert($t,$r,$f=null){
        if (strpos($t, 'review_decisions') !== false) {
            foreach (($this->data[$t] ?? []) as $row) {
                if ((string)($row['idempotency_key'] ?? '') === (string)($r['idempotency_key'] ?? '')) { return false; } // unique key
            }
        }
        $this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;
    }
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows($sql){
        if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]];
        $table=$m[1];$rows=$this->data[$table]??[];
        if(strpos($sql,'WHERE')===false)return [$table,array_values($rows)];
        $where=preg_split('/ORDER BY| LIMIT/',substr($sql,strpos($sql,'WHERE')+5))[0];
        preg_match_all("/(\w+)\s*=\s*'([^']*)'|(\w+)\s*=\s*(\d+)/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){ if(isset($p[3])&&$p[3]!==''){$conds[$p[3]]=$p[4];} else {$conds[$p[1]]=$p[2];} }
        $out=[];foreach($rows as $r){$okk=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$okk=false;break;}}if($okk)$out[]=$r;}
        return [$table,$out];
    }
    public function get_var($sql){
        if(preg_match('/COUNT\(\*\)/',$sql)){[$t,$rows]=$this->rows($sql);return (string)count($rows);}
        if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows($sql);return $rows?($rows[0][$sm[1]]??null):null;}
        return null;
    }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){[$t,$rows]=$this->rows($sql);if(stripos($sql,'ORDER BY id DESC')!==false){usort($rows,function($a,$b){return (int)$b['id']<=>(int)$a['id'];});}return array_values($rows);}
}
$GLOBALS['wpdb']=new LedgerWpdb();

require_once dirname(__DIR__).'/includes/class-ves-security-event-log.php';
require_once dirname(__DIR__).'/includes/class-ves-review-decision-ledger.php';
require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-intelligence-store.php';
require_once dirname(__DIR__).'/includes/class-ves-insight-lifecycle-service.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$ledger_table = 'wp_ves_review_decisions';
$ledger_count = function () use ($ledger_table) { return count($GLOBALS['wpdb']->data[$ledger_table] ?? []); };

$seed_insight = function ($status, $evidence = '[11]') {
    global $wpdb;
    static $n = 100;
    $n++;
    $wpdb->data['wp_ves_intel_insights'][$n] = [
        'id' => $n, 'workspace_id' => 3, 'insight_type' => 'trend', 'status' => $status,
        'title' => 'ledger probe', 'summary' => 's', 'recommendation' => '',
        'evidence_ids_json' => $evidence, 'signal_ids_json' => '[]', 'metadata' => '{}',
        'created_at' => '2026-06-14 09:00:00', 'updated_at' => '2026-06-14 09:00:00',
    ];
    return $n;
};

// ── 1. Allowed insight transition writes exactly one decision ────────────────
$iid = $seed_insight('draft');
$res = VES_Intelligence_Store::update_insight_status($iid, 'approved', ['approved_by' => 9]);
$ok(!is_wp_error($res), 'draft -> approved succeeds');
$ok($ledger_count() === 1, 'exactly one ledger decision appended');
$row = array_values($GLOBALS['wpdb']->data[$ledger_table])[0];
$ok($row['object_type'] === 'insight' && (int)$row['object_id'] === $iid, 'decision references the insight');
$ok($row['from_status'] === 'draft' && $row['to_status'] === 'approved' && $row['decision'] === 'approve', 'decision captures from/to/approve');
$ok((int)$row['workspace_id'] === 3 && (int)$row['actor_user_id'] === 9, 'decision captures workspace + actor');
$ok(preg_match('/^[a-f0-9]{64}$/', (string)$row['evidence_snapshot_hash']) === 1, 'evidence snapshot hash recorded');

// ── 2. Blocked transition writes NO decision, but a security event ───────────
$rejected = $seed_insight('rejected');
$before = $ledger_count();
$res = VES_Intelligence_Store::update_insight_status($rejected, 'approved');
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_intel_transition_blocked', 'rejected -> approved still blocked');
$ok($ledger_count() === $before, 'blocked transition appended NOTHING to the ledger');
$sec = json_encode(get_option('ves_security_event_log', []));
$ok(strpos($sec, 'lifecycle_transition_blocked') !== false, 'blocked transition recorded as security event');

// ── 3. Reopen is audited as a reopen decision ────────────────────────────────
$res = VES_Insight_Lifecycle_Service::reopen_insight($rejected, 'new evidence arrived');
$ok(!is_wp_error($res), 'explicit reopen succeeds');
$decisions = array_values($GLOBALS['wpdb']->data[$ledger_table]);
$last = end($decisions);
$ok($last['decision'] === 'reopen' && $last['to_status'] === 'draft' && strpos((string)$last['reason'], 'new evidence') !== false, 'reopen decision labeled with reason');

// ── 4. Brief/draft updaters share the matrix + ledger ────────────────────────
$GLOBALS['wpdb']->data['wp_ves_intel_briefs'][7] = ['id'=>7,'workspace_id'=>3,'brief_type'=>'content','status'=>'draft','title'=>'b','evidence_ids_json'=>'[2]','metadata'=>'{}','created_at'=>'2026-06-14 09:00:00','updated_at'=>'2026-06-14 09:00:00'];
$before = $ledger_count();
$res = VES_Intelligence_Store::update_brief_status(7, 'approved');
$ok(!is_wp_error($res), 'brief draft -> approved succeeds');
$ok($ledger_count() === $before + 1, 'brief transition wrote a ledger decision');
$res = VES_Intelligence_Store::update_brief_status(7, 'draft');
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_intel_transition_blocked', 'brief approved -> draft blocked (no demotion)');

$GLOBALS['wpdb']->data['wp_ves_intel_drafts'][8] = ['id'=>8,'workspace_id'=>3,'draft_type'=>'post','status'=>'generated','title'=>'d','evidence_ids_json'=>'[2]','metadata'=>'{}','created_at'=>'2026-06-14 09:00:00','updated_at'=>'2026-06-14 09:00:00'];
$res = VES_Intelligence_Store::update_draft_status(8, 'rejected');
$ok(!is_wp_error($res), 'draft generated -> rejected succeeds');
$res = VES_Intelligence_Store::update_draft_status(8, 'approved');
$ok(is_wp_error($res), 'draft rejected -> approved blocked (terminal)');

// ── 5. Duplicate idempotency key collapses (DB unique + race re-select) ─────
$before = $ledger_count();
$first = VES_Review_Decision_Ledger::record(['workspace_id'=>3,'object_type'=>'insight','object_id'=>55,'from_status'=>'draft','to_status'=>'approved','decision'=>'approve','actor_user_id'=>9,'idempotency_key'=>hash('sha256','dup-probe')]);
$second = VES_Review_Decision_Ledger::record(['workspace_id'=>3,'object_type'=>'insight','object_id'=>55,'from_status'=>'draft','to_status'=>'approved','decision'=>'approve','actor_user_id'=>9,'idempotency_key'=>hash('sha256','dup-probe')]);
$ok(!is_wp_error($first) && !is_wp_error($second) && (int)$first === (int)$second, 'duplicate idempotency key returns the SAME row id');
$ok($ledger_count() === $before + 1, 'duplicate decision created no extra row');

// ── 6. Append-only + scrubbed ────────────────────────────────────────────────
$ok(!method_exists('VES_Review_Decision_Ledger', 'update') && !method_exists('VES_Review_Decision_Ledger', 'delete'), 'ledger exposes no update/delete API');
$ok(VES_Review_Decision_Ledger::ledger_active() === true, 'ledger_active probe passes');
$rid = VES_Review_Decision_Ledger::record(['workspace_id'=>3,'object_type'=>'memory_record','object_id'=>77,'from_status'=>'candidate','to_status'=>'active','decision'=>'approve','reason'=>'token apify_api_SECRETSECRETSECRET123 must vanish','metadata'=>['note'=>'Bearer abc.def-ghi_jkl','api_key'=>'sk-LEAKLEAKLEAK']]);
$ok(!is_wp_error($rid), 'memory decision recorded');
$stored = json_encode($GLOBALS['wpdb']->data[$ledger_table]);
$ok(strpos($stored, 'apify_api_SECRETSECRETSECRET123') === false && strpos($stored, 'sk-LEAKLEAKLEAK') === false, 'ledger scrubber removed secrets');
$ok(strpos($stored, '[redacted]') !== false, 'redaction placeholder present');

// ── 7. Memory matrix: resurrection impossible ────────────────────────────────
$ok(VES_Review_Decision_Ledger::transition_allowed('memory_record', 'candidate', 'active'), 'memory candidate -> active allowed');
$ok(VES_Review_Decision_Ledger::transition_allowed('memory_record', 'pinned', 'rejected'), 'memory pinned -> rejected (downgrade) allowed');
$ok(!VES_Review_Decision_Ledger::transition_allowed('memory_record', 'rejected', 'active'), 'memory rejected -> active impossible');
$ok(!VES_Review_Decision_Ledger::transition_allowed('memory_record', 'expired', 'pinned'), 'memory expired -> pinned impossible');
$ok(!VES_Review_Decision_Ledger::transition_allowed('memory_record', 'archived', 'candidate'), 'memory archived -> candidate impossible');
$ok(!VES_Review_Decision_Ledger::transition_allowed('prompt_package', 'draft', 'approved'), 'prompt packages are preview-only (no approval transitions)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
