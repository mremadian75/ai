<?php
/**
 * Phase 4B Part B — VES_Trend_Backfill_Service: discovery, dry-run, idempotent apply.
 * Self-contained; MockWpdb handles the reports⋈runs join + the observation store.
 * No DB, no API calls.
 *
 * Run: php tests/test-ves-trend-backfill.php
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
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

// Stub the DTF table-name resolver (the real FIDTF_DB::table).
final class FIDTF_DB { public static function table($k){ return 'wp_fi_dtf_' . ($k==='runs'?'runs':'reports'); } }

class BackfillWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function insert($t,$r,$f=null){$this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;}
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows($sql){
        if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]];
        $table=$m[1];$rows=$this->data[$table]??[];
        $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql));
        $where=preg_split('/ORDER BY| LIMIT| GROUP BY/',$where)[0];
        preg_match_all("/(\w+)\s*=\s*(?:'([^']*)'|(-?\d+(?:\.\d+)?))/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){if(ctype_digit($p[1]))continue;$conds[$p[1]]=($p[2]!=='')?$p[2]:($p[3]??'');}
        $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}if($ok)$out[]=$r;}
        return [$table,$out];
    }
    public function get_var($sql){if(preg_match('/COUNT\(\*\) FROM/',$sql)){[$t,$rows]=$this->rows($sql);return (string)count($rows);}if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows($sql);return $rows?($rows[0][$sm[1]]??null):null;}return null;}
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){
        if(strpos($sql,'INNER JOIN')!==false){
            // reports rp JOIN runs r ON r.id = rp.run_id
            $reports=$this->data['wp_fi_dtf_reports']??[]; $runs=$this->data['wp_fi_dtf_runs']??[];
            $wsFilter=preg_match('/r\.workspace_id = (\d+)/',$sql,$wf)?(int)$wf[1]:null;
            $out=[];
            foreach($reports as $rp){
                if(($rp['report_type']??'')!=='final_synthesis')continue;
                $run=$runs[(int)($rp['run_id']??0)]??[];
                if($wsFilter!==null && (int)($run['workspace_id']??0)!==$wsFilter)continue;
                $out[]=['report_id'=>(int)$rp['id'],'run_id'=>(int)($rp['run_id']??0),'payload'=>$rp['final_synthesis_json']??'','report_created_at'=>$rp['created_at']??'','workspace_id'=>(int)($run['workspace_id']??0),'user_id'=>(int)($run['user_id']??0)];
            }
            usort($out,function($a,$b){return $a['report_id']-$b['report_id'];});
            return $out;
        }
        [$t,$rows]=$this->rows($sql);
        if(preg_match('/GROUP BY/',$sql)){return array_values($rows);}
        return array_values($rows);
    }
}
$wpdb=new BackfillWpdb(); $GLOBALS['wpdb']=$wpdb;

// Seed runs + reports.
$wpdb->data['wp_fi_dtf_runs'][1]=['id'=>1,'workspace_id'=>9,'user_id'=>3,'created_at'=>'2026-05-01 10:00:00'];
$wpdb->data['wp_fi_dtf_runs'][2]=['id'=>2,'workspace_id'=>9,'user_id'=>3,'created_at'=>'2026-05-08 10:00:00'];
$wpdb->data['wp_fi_dtf_runs'][3]=['id'=>3,'workspace_id'=>9,'user_id'=>3,'created_at'=>'2026-05-15 10:00:00'];
$wpdb->data['wp_fi_dtf_reports'][1]=['id'=>1,'run_id'=>1,'report_type'=>'final_synthesis','created_at'=>'2026-05-01 11:00:00','final_synthesis_json'=>json_encode(['top_terms'=>[['label'=>'ai agents','count'=>5],['label'=>'rag','count'=>3]],'top_hashtags'=>[['label'=>'#ai','count'=>4]]])];
$wpdb->data['wp_fi_dtf_reports'][2]=['id'=>2,'run_id'=>2,'report_type'=>'final_synthesis','created_at'=>'2026-05-08 11:00:00','final_synthesis_json'=>json_encode(['top_terms'=>[['label'=>'ai agents','count'=>8]]])];
$wpdb->data['wp_fi_dtf_reports'][3]=['id'=>3,'run_id'=>3,'report_type'=>'final_synthesis','created_at'=>'2026-05-15 11:00:00','final_synthesis_json'=>'NOT JSON']; // malformed

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-observation-store.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-backfill-service.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

// Discovery.
$cands = VES_Trend_Backfill_Service::discover_backfill_candidates([]);
$ok(count($cands)===3, 'discovery enumerates all reports (joined to runs)');
$byId=[];foreach($cands as $c){$byId[$c['report_id']]=$c;}
$ok($byId[1]['reason']==='eligible' && $byId[1]['estimated_observations']===3 && $byId[1]['workspace_id']===9, 'eligible report has workspace + estimated observations (2 terms + 1 hashtag)');
$ok($byId[3]['reason']==='missing_payload', 'malformed report flagged missing_payload');

// extract_observations_from_report (pure).
$payload=json_decode($wpdb->data['wp_fi_dtf_reports'][1]['final_synthesis_json'],true);
$obs=VES_Trend_Backfill_Service::extract_observations_from_report($payload,['workspace_id'=>9,'run_id'=>'1','report_id'=>1,'observed_at'=>'2026-05-01 11:00:00','backfill_run_id'=>'bk1']);
$ok(count($obs)===3 && $obs[0]['observation_type']==='keyword' && ($obs[0]['metadata']['backfilled']??false)===true && ($obs[0]['metadata']['original_report_id']??0)===1, 'extract_observations returns 3 observations with backfill metadata');

// Dry-run writes nothing.
$prev=VES_Trend_Backfill_Service::preview_backfill([]);
$ok($prev['dry_run']===true && $prev['eligible']===2 && $prev['missing_payload']===1, 'preview: 2 eligible, 1 missing_payload (dry-run)');
$ok(VES_Trend_Observation_Store::total(0)===0, 'dry-run wrote NO observations');

// Apply.
$r=VES_Trend_Backfill_Service::run_backfill(['apply'=>true]);
$ok($r['dry_run']===false && $r['reports_processed']===2 && $r['observations_created']===4, 'apply: 2 reports processed, 4 observations created (3+1)');
$ok(VES_Trend_Observation_Store::total(0)===4, 'apply wrote 4 observations');
$first=$wpdb->data['wp_ves_trend_observations'][1]??[];
$ok(strpos((string)($first['metadata']??''),'historical_dtf_report')!==false, 'observation metadata records historical source');
$last=VES_Trend_Backfill_Service::last_backfill();
$ok(isset($last['observations_created']) && (int)$last['observations_created']===4, 'last_backfill summary recorded after apply');

// Idempotent second apply.
$r2=VES_Trend_Backfill_Service::run_backfill(['apply'=>true]);
$ok($r2['reports_processed']===0 && VES_Trend_Observation_Store::total(0)===4, 'second apply is idempotent (no new observations, reports already backfilled)');

echo "\nVES trend backfill tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
