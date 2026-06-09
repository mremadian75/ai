<?php
/**
 * Phase 4 Part E — VES_Trend_Record_Store: upsert, links, filters, scrub.
 * Self-contained; in-memory MockWpdb. No DB, no API calls.
 *
 * Run: php tests/test-ves-trend-record-store.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

class TRWpdb {
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
        $conds=[];foreach($mm as $p){if(ctype_digit($p[1]))continue;$conds[$p[1]]=($p[2]!==''||(isset($p[2])&&$p[2]==='0'))?$p[2]:($p[3]??'');if(($p[2]??'')===''&&isset($p[3])){$conds[$p[1]]=$p[3];}}
        $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}if($ok)$out[]=$r;}
        // crude >= filters for trend_score/confidence_score
        if(preg_match("/trend_score >= '?([0-9.]+)'?/",$sql,$g)){$out=array_values(array_filter($out,function($r)use($g){return (float)($r['trend_score']??0)>=(float)$g[1];}));}
        if(preg_match("/confidence_score >= '?([0-9.]+)'?/",$sql,$g)){$out=array_values(array_filter($out,function($r)use($g){return (float)($r['confidence_score']??0)>=(float)$g[1];}));}
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
$GLOBALS['wpdb']=new TRWpdb();

require_once dirname(__DIR__).'/includes/class-ves-ai-usage-tracker.php';
require_once dirname(__DIR__).'/includes/class-ves-trend-record-store.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

$base=['workspace_id'=>5,'normalized_term'=>'ai agents','observation_type'=>'keyword','provider'=>'apify','classification'=>'emerging','trend_score'=>72,'confidence_score'=>80,'point_count'=>6];

// Missing workspace + invalid classification.
$ok(is_wp_error(VES_Trend_Record_Store::create_or_update_trend_record(array_merge($base,['workspace_id'=>0]))), 'missing workspace rejected');
$ok(is_wp_error(VES_Trend_Record_Store::create_or_update_trend_record(array_merge($base,['classification'=>'bogus']))), 'invalid classification rejected');

// Create.
$id1 = VES_Trend_Record_Store::create_or_update_trend_record(array_merge($base,['source_ids'=>[10],'evidence_ids'=>[100]]));
$ok(is_int($id1)&&$id1>0, 'create_or_update_trend_record creates a record');

// Update (same key) → same id, scores updated, links preserved + merged.
$id2 = VES_Trend_Record_Store::create_or_update_trend_record(array_merge($base,['classification'=>'sustained','trend_score'=>65,'evidence_ids'=>[101],'source_ids'=>[11]]));
$ok($id1===$id2, 'upsert by (workspace,term,type,platform,provider) returns same id');
$rec = VES_Trend_Record_Store::get_trend_record($id1);
$ok(($rec['classification']??'')==='sustained' && (float)($rec['trend_score']??0)==65, 'upsert updates scores/classification');
$ok(count($rec['evidence_ids']??[])===2 && count($rec['source_ids']??[])===2, 'upsert MERGES links (preserves existing, no destructive overwrite)');

// Link helpers dedup.
VES_Trend_Record_Store::link_evidence($id1, 100); // already present
VES_Trend_Record_Store::link_signal($id1, 200);
VES_Trend_Record_Store::link_source($id1, 11);    // already present
$rec2 = VES_Trend_Record_Store::get_trend_record($id1);
$ok(count($rec2['evidence_ids']??[])===2 && in_array(200,$rec2['signal_ids']??[],true) && count($rec2['source_ids']??[])===2, 'link helpers append + dedup');

// A second distinct term.
VES_Trend_Record_Store::create_or_update_trend_record(array_merge($base,['normalized_term'=>'noisy term','classification'=>'noise','trend_score'=>30,'confidence_score'=>40]));

// Filters.
$byClass = VES_Trend_Record_Store::list_trend_records(['classification'=>'sustained']);
$ok(count($byClass)===1 && ($byClass[0]['normalized_term']??'')==='ai agents', 'filter by classification');
$byScore = VES_Trend_Record_Store::list_trend_records(['min_score'=>50]);
$ok(count($byScore)===1, 'filter by min_score');
$counts = VES_Trend_Record_Store::count_by_classification(0);
$ok(($counts['sustained']??0)===1 && ($counts['noise']??0)===1, 'count_by_classification structured');

// Metadata scrub.
$id3 = VES_Trend_Record_Store::create_or_update_trend_record(array_merge($base,['normalized_term'=>'scrub me','metadata'=>['api_key'=>'sk-LEAK123456789','reasons'=>'ok']]));
$rec3 = VES_Trend_Record_Store::get_trend_record($id3);
$ok(!isset($rec3['metadata']['api_key']) && ($rec3['metadata']['reasons']??'')==='ok', 'metadata scrub drops api_key, keeps safe keys');

echo "\nVES trend record store tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
