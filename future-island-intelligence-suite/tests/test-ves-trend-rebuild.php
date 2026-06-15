<?php
/**
 * Phase 4B Part C — VES_Trend_Rebuild_Service: rebuild records from observations.
 * Self-contained; MockWpdb backs observations + records + multi-column GROUP BY.
 *
 * Run: php tests/test-ves-trend-rebuild.php
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
function apply_filters($tag,$v){return $v;}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

class RebuildWpdb {
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
        $where=preg_split('/ GROUP BY| ORDER BY| LIMIT/',$where)[0];
        preg_match_all("/(\w+)\s*=\s*(?:'([^']*)'|(-?\d+(?:\.\d+)?))/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){if(ctype_digit($p[1]))continue;$conds[$p[1]]=($p[2]!=='')?$p[2]:($p[3]??'');}
        $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}if($ok)$out[]=$r;}
        return [$table,$out];
    }
    public function get_var($sql){if(preg_match('/COUNT\(\*\) FROM/',$sql)){[$t,$rows]=$this->rows($sql);return (string)count($rows);}if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows($sql);return $rows?($rows[0][$sm[1]]??null):null;}return null;}
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){
        [$t,$rows]=$this->rows($sql);
        if(preg_match('/GROUP BY workspace_id, normalized_term, observation_type, platform, provider/',$sql)){
            $g=[];
            foreach($rows as $r){
                $k=implode('|',[$r['workspace_id']??0,$r['normalized_term']??'',$r['observation_type']??'',$r['platform']??'',$r['provider']??'']);
                if(!isset($g[$k])){$g[$k]=['workspace_id'=>(int)($r['workspace_id']??0),'normalized_term'=>$r['normalized_term']??'','observation_type'=>$r['observation_type']??'','platform'=>$r['platform']??'','provider'=>$r['provider']??'','point_count'=>0,'first_seen_at'=>$r['observed_at']??'','last_seen_at'=>$r['observed_at']??''];}
                $g[$k]['point_count']++;
                if(($r['observed_at']??'')<$g[$k]['first_seen_at'])$g[$k]['first_seen_at']=$r['observed_at'];
                if(($r['observed_at']??'')>$g[$k]['last_seen_at'])$g[$k]['last_seen_at']=$r['observed_at'];
            }
            usort($g,function($a,$b){return $b['point_count']-$a['point_count'];});
            return array_values($g);
        }
        return array_values($rows);
    }
}
$GLOBALS['wpdb']=new RebuildWpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-observation-store.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-series-builder.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-scorer.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-record-store.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-rebuild-service.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

// Seed: 'ai agents' with 6 daily observations (rising), source_id=10; 'rare' with 1.
$start=strtotime('2026-05-01');
foreach([2,3,5,8,12,18] as $i=>$v){
    VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>9,'observation_type'=>'keyword','term'=>'ai agents','provider'=>'apify','value_number'=>$v,'raw_count'=>$v,'observed_at'=>gmdate('Y-m-d H:i:s',$start+$i*86400),'source_id'=>10]);
}
VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>9,'observation_type'=>'keyword','term'=>'rare','provider'=>'apify','value_number'=>1,'observed_at'=>'2026-05-01 10:00:00']);

$terms=VES_Trend_Rebuild_Service::list_rebuild_terms(['workspace_id'=>9]);
$ok(count($terms)===2, 'list_rebuild_terms returns 2 distinct term groups');

// First rebuild.
$r=VES_Trend_Rebuild_Service::rebuild_trends(['workspace_id'=>9,'min_points'=>3]);
$ok($r['terms_seen']===2 && $r['records_created']>=1 && $r['errors']===0, 'rebuild creates trend records (per-term, no errors)');
$ok($r['insufficient_data']>=1, 'sparse term counted as insufficient_data (min_points gate)');

// The 'ai agents' record exists with links + classification.
$rid=VES_Trend_Record_Store::find_record(9,'ai agents','keyword','apify');
$rid=$rid>0?$rid:VES_Trend_Record_Store::find_record(9,'ai agents','keyword','','apify');
$rec=VES_Trend_Record_Store::get_trend_record($rid>0?$rid:1);
$ok(is_array($rec) && ($rec['classification']??'')==='emerging', 'rebuilt record classifies the rising series as emerging');
$ok(in_array(10,$rec['source_ids']??[],true), 'rebuild preserves source link from observations');

// Second rebuild → updates.
$r2=VES_Trend_Rebuild_Service::rebuild_trends(['workspace_id'=>9,'min_points'=>3]);
$ok($r2['records_updated']>=1, 'second rebuild updates existing record (idempotent upsert)');

// Single rebuild_term on the sparse term reports insufficient (failure isolation: no throw).
$one=VES_Trend_Rebuild_Service::rebuild_term(['workspace_id'=>9,'normalized_term'=>'rare','observation_type'=>'keyword','provider'=>'apify','min_points'=>3]);
$ok($one['result']==='insufficient', 'rebuild_term reports insufficient for sparse term');

echo "\nVES trend rebuild tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
