<?php
/**
 * Phase 5 Parts D+E+G — behavioral E2E: trend → draft insight → lifecycle → brief.
 * Self-contained; MockWpdb backs the intelligence + trend record stores (incl.
 * metadata LIKE dedup and >= filters). No DB, no API, no LLM.
 *
 * Run: php tests/test-ves-insight-engine-e2e.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$s)));}
function sanitize_textarea_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function esc_url_raw($s){return trim((string)$s);}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function apply_filters($tag,$v){return $v;}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function get_current_user_id(){return 7;}
function dbDelta($s){return [];}
$GLOBALS['__o']=[];

class E2EWpdb {
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
        $where=preg_split('/ ORDER BY| LIMIT| GROUP BY/',$where)[0];
        // equality conditions
        preg_match_all("/(\w+)\s*=\s*(?:'([^']*)'|(-?\d+(?:\.\d+)?))/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){if(ctype_digit($p[1]))continue;$conds[$p[1]]=($p[2]!=='')?$p[2]:($p[3]??'');}
        // LIKE conditions (group by column; multiple same-column LIKEs are OR'd,
        // modelling "(col LIKE a OR col LIKE b)" boundary-match dedup queries).
        preg_match_all("/(\w+)\s+LIKE\s+'([^']*)'/",$where,$lm,PREG_SET_ORDER);
        $likes=[];foreach($lm as $p){$likes[$p[1]][]=trim(stripslashes($p[2]),'%');unset($conds[$p[1]]);}
        // >= filters
        preg_match_all("/(\w+)\s*>=\s*'?([0-9.]+)'?/",$where,$gm,PREG_SET_ORDER);
        $ge=[];foreach($gm as $p){$ge[$p[1]]=(float)$p[2];}
        $out=[];
        foreach($rows as $r){
            $ok=true;
            foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}
            if($ok)foreach($likes as $k=>$subs){$any=false;foreach((array)$subs as $sub){if(stripos((string)($r[$k]??''),$sub)!==false){$any=true;break;}}if(!$any){$ok=false;break;}}
            if($ok)foreach($ge as $k=>$v){if((float)($r[$k]??0)<$v){$ok=false;break;}}
            if($ok)$out[]=$r;
        }
        return [$table,$out];
    }
    public function get_var($sql){if(preg_match('/COUNT\(\*\) FROM/',$sql)){[$t,$rows]=$this->rows($sql);return (string)count($rows);}if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows($sql);return $rows?($rows[0][$sm[1]]??null):null;}return null;}
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){[$t,$rows]=$this->rows($sql);if(preg_match('/GROUP BY (\w+)/',$sql,$g)){$agg=[];foreach($rows as $r){$k=(string)($r[$g[1]]??'');$agg[$k]=($agg[$k]??0)+1;}$out=[];foreach($agg as $k=>$c){$out[]=['k'=>$k,'c'=>$c];}return $out;}
        if(preg_match('/ORDER BY trend_score DESC/',$sql)){usort($rows,function($a,$b){return ((float)($b['trend_score']??0))<=>((float)($a['trend_score']??0));});}
        else{usort($rows,function($a,$b){return ((int)($b['id']??0))<=>((int)($a['id']??0));});}
        return array_values($rows);}
}
$GLOBALS['wpdb']=new E2EWpdb();

foreach (['ai-usage-tracker','intelligence-store','trend-record-store','insight-quality-scorer','insight-evidence-validator','insight-lifecycle-service','trend-insight-builder','insight-brief-builder'] as $c) {
    require_once dirname(__DIR__).'/includes/class-ves-'.$c.'.php';
}

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

$recent = gmdate('Y-m-d H:i:s', time()-2*86400);
// Seed: source + 2 evidence + emerging trend record (evidence-backed, high score).
$sid = VES_Intelligence_Store::create_source(['workspace_id'=>5,'source_type'=>'social','provider'=>'apify','external_id'=>'run_1']);
$e1 = VES_Intelligence_Store::create_evidence(['workspace_id'=>5,'source_id'=>$sid,'evidence_type'=>'observation','text'=>'Buyers keep asking about ai agents in support tickets and forums.','observed_at'=>$recent,'confidence_score'=>72]);
$e2 = VES_Intelligence_Store::create_evidence(['workspace_id'=>5,'source_id'=>$sid,'evidence_type'=>'metric','text'=>'Engagement on ai agents content rose 120% across two platforms.','observed_at'=>$recent,'confidence_score'=>68]);
$tr = VES_Trend_Record_Store::create_or_update_trend_record(['workspace_id'=>5,'normalized_term'=>'ai agents','display_term'=>'ai agents','observation_type'=>'keyword','provider'=>'apify','classification'=>'emerging','trend_score'=>78,'confidence_score'=>70,'point_count'=>6,'evidence_ids'=>[$e1,$e2],'source_ids'=>[$sid]]);

// ── Part E: builder ──
$ins = VES_Trend_Insight_Builder::create_draft_insight_from_trend((int)$tr);
$ok(is_int($ins)&&$ins>0, 'E: builder creates a draft insight from an evidence-backed emerging trend');
$row = VES_Intelligence_Store::get_insight((int)$ins);
$ok(($row['status']??'')==='draft' && strpos((string)($row['recommendation']??''),'Explore')!==false, 'E: insight is DRAFT with an emerging action recommendation');
$ok(count($row['evidence_ids']??[])===2 && (int)($row['metadata']['trend_record_id']??0)===(int)$tr, 'E: insight links evidence + records trend_record_id');
// Duplicate prevention.
$ins2 = VES_Trend_Insight_Builder::create_draft_insight_from_trend((int)$tr);
$ok($ins2===$ins, 'E: duplicate trend insight is not created twice (dedup by trend_record_id)');

// Classification-shaped recommendations.
$ok(strpos(VES_Trend_Insight_Builder::build_recommendation(['classification'=>'spike','display_term'=>'x'])['recommended_action'],'Investigate')!==false, 'E: spike recommendation is cautious (investigate)');
$ok(strpos(VES_Trend_Insight_Builder::build_recommendation(['classification'=>'fading','display_term'=>'x'])['recommended_action'],'Reduce')!==false, 'E: fading recommendation reduces priority');
// Noise/insufficient do not create strong insight.
$trN = VES_Trend_Record_Store::create_or_update_trend_record(['workspace_id'=>5,'normalized_term'=>'noisy','observation_type'=>'keyword','provider'=>'apify','classification'=>'noise','trend_score'=>30,'confidence_score'=>30,'evidence_ids'=>[$e1]]);
$ok(is_wp_error(VES_Trend_Insight_Builder::create_draft_insight_from_trend((int)$trN)), 'E: noise trend does NOT create a strong insight');
// No-evidence trend → no insight.
$trE = VES_Trend_Record_Store::create_or_update_trend_record(['workspace_id'=>5,'normalized_term'=>'noevidence','observation_type'=>'keyword','provider'=>'apify','classification'=>'emerging','trend_score'=>80,'confidence_score'=>70]);
$ok(is_wp_error(VES_Trend_Insight_Builder::create_draft_insight_from_trend((int)$trE)), 'E: evidence-less trend does NOT create a strong insight');

// ── Part D: lifecycle ──
$assess = VES_Insight_Lifecycle_Service::reassess_insight((int)$ins);
$ok(is_array($assess) && $assess['validation']['has_evidence']===true && isset($assess['quality']['quality_score']), 'D: reassess returns validation + quality');
$rev = VES_Insight_Lifecycle_Service::promote_to_reviewed((int)$ins);
$ok(!is_wp_error($rev) && (VES_Intelligence_Store::get_insight((int)$ins)['status']??'')==='reviewed', 'D: evidence-backed quality insight can be reviewed');
$appr = VES_Insight_Lifecycle_Service::approve_insight((int)$ins);
$ok(!is_wp_error($appr) && (VES_Intelligence_Store::get_insight((int)$ins)['status']??'')==='approved', 'D: 2-evidence high-quality insight can be approved');

// Hard gate: a no-evidence draft cannot be reviewed/approved.
$bad = VES_Intelligence_Store::create_insight(['workspace_id'=>5,'insight_type'=>'trend','title'=>'Unsupported','status'=>'draft']);
$ok(is_wp_error(VES_Insight_Lifecycle_Service::promote_to_reviewed((int)$bad)), 'D: no-evidence insight CANNOT be reviewed (gate)');
$ok(is_wp_error(VES_Intelligence_Store::update_insight_status((int)$bad,'approved')), 'D: store HARD GATE blocks approving an evidence-less insight');

// Reject/archive store reasons.
VES_Insight_Lifecycle_Service::reject_insight((int)$bad,'not relevant to ICP');
$badrow = VES_Intelligence_Store::get_insight((int)$bad);
$ok(($badrow['status']??'')==='rejected' && strpos((string)($badrow['metadata']['rejected_reason']??''),'not relevant')!==false, 'D: reject stores status + reason');
$ev3 = VES_Intelligence_Store::create_evidence(['workspace_id'=>5,'source_id'=>$sid,'evidence_type'=>'quote','text'=>'archive me','confidence_score'=>60]);
$arch_ins = VES_Intelligence_Store::create_insight(['workspace_id'=>5,'insight_type'=>'trend','title'=>'Old','status'=>'draft','evidence_ids'=>[$ev3]]);
VES_Insight_Lifecycle_Service::archive_insight((int)$arch_ins,'superseded');
$ok((VES_Intelligence_Store::get_insight((int)$arch_ins)['metadata']['archived_reason']??'')==='superseded', 'D: archive stores reason');

// ── Part G: brief builder ──
$brief = VES_Insight_Brief_Builder::create_brief_from_insight((int)$ins);
$ok(is_int($brief)&&$brief>0, 'G: evidence-backed insight creates a draft brief');
$brow = VES_Intelligence_Store::get_brief((int)$brief);
$ok(($brow['status']??'')==='draft' && (int)($brow['metadata']['source_insight_id']??0)===(int)$ins && count($brow['evidence_ids']??[])>=1, 'G: brief is draft, links source insight + evidence');
$brief2 = VES_Insight_Brief_Builder::create_brief_from_insight((int)$ins);
$ok($brief2===$brief, 'G: duplicate brief is not created twice');
$ok(is_wp_error(VES_Insight_Brief_Builder::create_brief_from_insight((int)$bad)), 'G: unsupported insight cannot create a brief');

// ── Part H: diagnostics provider ──
$ov = VES_Insight_Lifecycle_Service::quality_overview(5);
$ok(isset($ov['totals'],$ov['missing_evidence'],$ov['eligible_review'],$ov['recent']) && is_array($ov['recent']), 'H: quality_overview returns structured diagnostics');

echo "\nVES insight engine E2E tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
