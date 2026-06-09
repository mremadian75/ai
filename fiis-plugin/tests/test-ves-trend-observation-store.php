<?php
/**
 * Phase 4 Part B — VES_Trend_Observation_Store: normalization, dedup, scrub.
 * Self-contained; dedup-capable in-memory MockWpdb. No DB, no API calls.
 *
 * Run: php tests/test-ves-trend-observation-store.php
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

class TrendWpdb {
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
    public function get_results($sql,$o=null){
        [$t,$rows]=$this->rows($sql);
        if(preg_match('/GROUP BY (\w+)/',$sql,$gm)){$agg=[];foreach($rows as $r){$k=(string)($r[$gm[1]]??'');$agg[$k]=($agg[$k]??0)+1;}$out=[];foreach($agg as $k=>$c){$out[]=['k'=>$k,'c'=>$c];}return $out;}
        return array_values($rows);
    }
}
$GLOBALS['wpdb']=new TrendWpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-observation-store.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

// Normalization.
$ok(VES_Trend_Observation_Store::normalize_term('#FooBar', 'hashtag')==='foobar', 'hashtag normalization strips # + lowercases');
$ok(VES_Trend_Observation_Store::normalize_term('  Hello   World ', 'keyword')==='hello world', 'keyword normalization trims + collapses whitespace');

$n = VES_Trend_Observation_Store::normalize_observation(['workspace_id'=>5,'observation_type'=>'hashtag','term'=>'#Trend','value_number'=>3]);
$ok(($n['normalized_term']??'')==='trend' && !empty($n['canonical_hash']), 'normalize_observation computes normalized_term + canonical_hash');

// Invalid type + missing workspace.
$ok(is_wp_error(VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>5,'observation_type'=>'bogus','term'=>'x'])), 'invalid observation_type rejected');
$ok(is_wp_error(VES_Trend_Observation_Store::create_or_get_observation(['observation_type'=>'keyword','term'=>'x'])), 'missing workspace_id rejected');

// Dedup (same workspace+type+term+platform+day) accumulates + returns same id.
$o1 = VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>5,'observation_type'=>'keyword','term'=>'ai','value_number'=>4,'raw_count'=>4,'observed_at'=>'2026-06-01 10:00:00']);
$o2 = VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>5,'observation_type'=>'keyword','term'=>'AI','value_number'=>2,'raw_count'=>2,'observed_at'=>'2026-06-01 22:00:00']);
$ok(is_int($o1)&&$o1>0, 'create_or_get_observation creates an observation');
$ok($o1===$o2, 'same-day same-term observation dedups to one row');
$row = $GLOBALS['wpdb']->data['wp_ves_trend_observations'][$o1] ?? [];
$ok(abs((float)($row['value_number']??0)-6.0)<0.001, 'dedup accumulates value_number (4+2=6)');

// Different platform does NOT dedup.
$o3 = VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>5,'observation_type'=>'keyword','term'=>'ai','platform'=>'tiktok','observed_at'=>'2026-06-01 10:00:00','value_number'=>1]);
$ok($o3!==$o1, 'different platform creates a distinct observation (no false dedup)');

// Different day does NOT dedup.
$o4 = VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>5,'observation_type'=>'keyword','term'=>'ai','observed_at'=>'2026-06-02 10:00:00','value_number'=>1]);
$ok($o4!==$o1, 'different day creates a distinct observation');

// Cross-workspace isolation.
$o5 = VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>6,'observation_type'=>'keyword','term'=>'ai','observed_at'=>'2026-06-01 10:00:00','value_number'=>1]);
$ok($o5!==$o1, 'same term in a different workspace is not deduped (tenant isolation)');

// Metadata scrub.
$o6 = VES_Trend_Observation_Store::create_or_get_observation(['workspace_id'=>5,'observation_type'=>'mention','term'=>'brand','metadata'=>['api_key'=>'sk-LEAK123456789','prompt'=>'secret','note'=>'ok'],'observed_at'=>'2026-06-03']);
$mrow = $GLOBALS['wpdb']->data['wp_ves_trend_observations'][$o6] ?? [];
$ok(strpos((string)($mrow['metadata']??''),'sk-LEAK')===false && strpos((string)($mrow['metadata']??''),'secret')===false, 'observation metadata scrubs api_key/prompt');

echo "\nVES trend observation store tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
