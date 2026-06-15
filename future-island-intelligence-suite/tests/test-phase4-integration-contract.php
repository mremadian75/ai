<?php
/**
 * Phase 4 — DTF V2 integration: behavioral end-to-end pipeline + source contracts.
 * No DB (in-memory MockWpdb), no API calls, no LLM.
 *
 * Run: php tests/test-phase4-integration-contract.php
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
function apply_filters($tag,$v){return $v;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

class P4Wpdb {
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
    public function get_results($sql,$o=null){[$t,$rows]=$this->rows($sql);if(preg_match('/GROUP BY (\w+)/',$sql,$gm)){$agg=[];foreach($rows as $r){$k=(string)($r[$gm[1]]??'');$agg[$k]=($agg[$k]??0)+1;}$o2=[];foreach($agg as $k=>$c){$o2[]=['k'=>$k,'c'=>$c];}return $o2;}return array_values($rows);}
}
$GLOBALS['wpdb']=new P4Wpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-observation-store.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-series-builder.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-scorer.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-record-store.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

// ── Behavioral end-to-end: observations → series → score → trend record ──────
$ws=7; $term='ai agents';
$start=time() - 5*86400;
foreach ([2,3,5,8,12,18] as $i=>$v) {
    VES_Trend_Observation_Store::create_or_get_observation([
        'workspace_id'=>$ws,'observation_type'=>'keyword','term'=>$term,'provider'=>'apify',
        'value_number'=>$v,'raw_count'=>$v,'observed_at'=>gmdate('Y-m-d H:i:s',$start+$i*86400),
    ]);
}
$normalized = VES_Trend_Observation_Store::normalize_term($term,'keyword');
$series = VES_Trend_Series_Builder::build_series(['workspace_id'=>$ws,'normalized_term'=>$normalized,'observation_type'=>'keyword','granularity'=>'day']);
$ok(count($series['points'])===6, 'E2E: series builder reads 6 stored daily observations');
$score = VES_Trend_Scorer::score_series($series);
$ok($score['classification']==='emerging', 'E2E: rising stored series classifies as emerging');
$rec_id = VES_Trend_Record_Store::create_or_update_trend_record(array_merge($score, [
    'workspace_id'=>$ws,'normalized_term'=>$normalized,'display_term'=>$term,'observation_type'=>'keyword','provider'=>'apify',
    'point_count'=>(int)($score['metadata']['point_count']??0),'source_ids'=>[10],'evidence_ids'=>[100],
]));
$rec = VES_Trend_Record_Store::get_trend_record((int)$rec_id);
$ok(is_int($rec_id)&&$rec_id>0 && ($rec['classification']??'')==='emerging' && (float)($rec['trend_score']??0)>0, 'E2E: trend record persisted with score + classification');
$ok(count($rec['evidence_ids']??[])===1 && count($rec['source_ids']??[])===1, 'E2E: trend record links source + evidence');

// Re-running the same run-day is idempotent (observation dedups; record upserts).
VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>$ws,'observation_type'=>'keyword','term'=>$term,'value_number'=>18,'observed_at'=>gmdate('Y-m-d H:i:s',$start+5*86400)]);
$series2 = VES_Trend_Series_Builder::build_series(['workspace_id'=>$ws,'normalized_term'=>$normalized,'observation_type'=>'keyword','granularity'=>'day']);
$ok(count($series2['points'])===6, 'E2E: re-observing the same day does not add a new bucket (dedup)');

// ── Source contracts ─────────────────────────────────────────────────────────
$src=function($rel){$p=dirname(__DIR__).'/'.$rel;return is_file($p)?(string)file_get_contents($p):'';};
$boot=$src('future-island-intelligence-suite.php');
$ok(strpos($boot,'class-ves-trend-observation-store.php')!==false && strpos($boot,'class-ves-trend-scorer.php')!==false && strpos($boot,'class-ves-trend-record-store.php')!==false, 'bootstrap loads the trend engine classes');
$mig=$src('includes/class-ves-migrations.php');
$ok(strpos($mig,"'VES_Trend_Observation_Store'")!==false && strpos($mig,"'VES_Trend_Record_Store'")!==false, 'migration runner registers the trend tables');

$dtf=$src('modules/deep-trend-finder/includes/class-fidtf-report-service.php');
$ok(strpos($dtf,'function write_trend_intelligence(')!==false, 'F: DTF report has the trend pipeline method');
$ok(strpos($dtf,'VES_Trend_Observation_Store::create_or_get_observation(')!==false && strpos($dtf,'VES_Trend_Scorer::score_series(')!==false && strpos($dtf,'VES_Trend_Record_Store::create_or_update_trend_record(')!==false, 'F: DTF calls observation store + scorer + record store');
$ok(strpos($dtf,'self::write_trend_intelligence(')!==false, 'F: trend pipeline is invoked from write_intelligence_records');
$ok(strpos($dtf,'create_insight(')!==false && strpos($dtf,"'status' => 'draft'")!==false, 'F: high-confidence trend creates a DRAFT insight (not reviewed/approved)');
$ok(preg_match('/function write_trend_intelligence\([^)]*\): void/',$dtf)===1, 'F: trend pipeline returns void (does not change report shape)');
$ok(substr_count($dtf,'catch (\\Throwable')>=2, 'F: trend pipeline is isolated in try/catch (failure cannot break the report)');

// No LLM / no network in the deterministic engine.
foreach (['includes/class-ves-trend-scorer.php','includes/class-ves-trend-series-builder.php','includes/class-ves-trend-observation-store.php','includes/class-ves-trend-record-store.php'] as $f) {
    $c=$src($f);
    $ok(stripos($c,'openai')===false && stripos($c,'wp_remote')===false && stripos($c,'api.apify')===false, "no LLM/network in {$f}");
}

// Admin diagnostics + CLI.
$ac=$src('includes/class-ves-admin-console.php');
$ok(strpos($ac,'function render_trend_engine(')!==false && strpos($ac,'render_trend_engine()')!==false, 'G: admin renders trend-engine diagnostics');
$ok(strpos($ac,'count_by_classification')!==false && strpos($ac,'api_key')===false, 'G: trend diagnostics use structured providers + no secrets');
$cli=$src('includes/class-ves-cli-trends.php');
$ok(strpos($cli,"add_command('ves trend-summary'")!==false && strpos($cli,'manage_options')!==false, 'H: read-only WP-CLI wp ves trend-summary (capability-gated)');

echo "\nPhase 4 integration contract: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
