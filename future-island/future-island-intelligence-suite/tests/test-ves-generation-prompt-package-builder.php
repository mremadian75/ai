<?php
/** Phase 6B-B + 6C prompt-package builder tests. No AI call; evidence/memory
 * separated; memory can't unblock; status model; limits; provider_execution flag;
 * safe export. Run: php tests/test-ves-generation-prompt-package-builder.php */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir().'/fiis_pp_'.getmypid().'/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A','ARRAY_A'); }
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d,$o=0){ return json_encode($d,$o); } }
if (!function_exists('current_time')) { function current_time($t='mysql',$g=0){ return '2026-06-08 12:00:00'; } }
$GLOBALS['__opts']=[];
if (!function_exists('get_option')) { function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['__opts'])?$GLOBALS['__opts'][$k]:$d; } }
if (!function_exists('update_option')) { function update_option($k,$v,$a=null){ $GLOBALS['__opts'][$k]=$v; return true; } }
require_once dirname(__DIR__).'/includes/class-ves-brand-context-service.php';
require_once dirname(__DIR__).'/includes/class-ves-generation-context-resolver.php';
require_once dirname(__DIR__).'/includes/class-ves-generation-prompt-package-builder.php';
$pass=0;$fail=0; $ok=function($c,$l)use(&$pass,&$fail){ if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} };
$GLOBALS['ves_brand_context_now']=strtotime('2026-06-08 12:00:00');
$GLOBALS['__insights']=[]; $GLOBALS['__briefs']=[];
if (!class_exists('VES_Intelligence_Store')) { eval('class VES_Intelligence_Store { public static function get_insight($id){return $GLOBALS["__insights"][(int)$id]??null;} public static function get_brief($id){return $GLOBALS["__briefs"][(int)$id]??null;} public static function get_draft($id){return null;} }'); }
$GLOBALS['__insights'][100]=['id'=>100,'workspace_id'=>5,'status'=>'approved','recommendation'=>'go','evidence_ids'=>[10,11]];
$GLOBALS['__insights'][101]=['id'=>101,'workspace_id'=>5,'status'=>'approved','evidence_ids'=>[]];
$GLOBALS['__briefs'][200]=['id'=>200,'workspace_id'=>5,'status'=>'approved','objective'=>'o','evidence_ids'=>[10]];
$GLOBALS['__briefs'][201]=['id'=>201,'workspace_id'=>5,'status'=>'draft','objective'=>'o','evidence_ids'=>[10]];
class PPb_MockWpdb { public $prefix='wp_'; public $insert_id=0; public $data=['wp_ves_memory_records'=>[]]; private $a=0;
  public function get_charset_collate(){return '';} public function prepare($s,$ar=[]){if(!is_array($ar)){$ar=array_slice(func_get_args(),1);}foreach($ar as $x){$r=is_int($x)?(string)$x:"'".addslashes((string)$x)."'";$s=preg_replace('/%d|%s|%f/',$r,$s,1);}return $s;}
  public function query($s){return true;} public function get_results($s,$o=null){$rows=array_values($this->data['wp_ves_memory_records']);if(preg_match('/workspace_id = (\d+)/',$s,$m)){$rows=array_values(array_filter($rows,function($r)use($m){return (int)$r['workspace_id']===(int)$m[1];}));}return $rows;}
  public function get_row($s,$o=null){return null;} public function get_var($s){return null;} public function get_col($s,$i=0){return ['id'];}
  public function insert($t,$row,$f=null){$this->a++;$row['id']=$this->a;$this->data[$t][$this->a]=$row;return 1;} public function update($t,$r,$w,$f=null,$wf=null){return 1;} }
if (!class_exists('VES_Memory_Records')) { eval('class VES_Memory_Records { public static function table_name(){return "wp_ves_memory_records";} public static function save_record($a){return 0;} }'); }
function ppb_seed($ws,$type,$status,$sum,$opts=[]){global $wpdb;$row=array_merge(['id'=>0,'workspace_id'=>$ws,'memory_type'=>$type,'summary'=>$sum,'importance_score'=>60,'is_pinned'=>0,'expires_at'=>null,'content_json'=>json_encode(['brand_context'=>['status'=>$status]]),'source_type'=>'draft','source_id'=>1,'updated_at'=>'2026-06-01','created_at'=>'2026-06-01'],$opts);$wpdb->insert('wp_ves_memory_records',$row);}
$GLOBALS['wpdb']=new PPb_MockWpdb();
$B='VES_Generation_Prompt_Package_Builder';

$p=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>100]);
$ok($p['package_version']==='1.1'&&$p['safety']['no_provider_call']===true,'structured package, no provider call');
$ok($p['build_status']==='ready'&&$p['evidence']['count']===2&&$p['evidence']['items']===[['evidence_id'=>10],['evidence_id'=>11]],'valid evidence ⇒ ready, id-only refs');
$blk=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>101]);
$ok($blk['build_status']==='blocked'&&$blk['blocking_reason']==='missing_evidence','missing evidence ⇒ blocked');
update_option('ves_brand_context_generation_enabled',true);
$GLOBALS['wpdb']=new PPb_MockWpdb(); ppb_seed(5,'voice_rule','active','t');
$blk2=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>101]);
$ok($blk2['build_status']==='blocked','memory cannot unblock missing evidence');
$ok($blk2['brand_context']['items']===[]&&isset($blk2['brand_context']['items_count']),'blocked package minimizes brand_context.items by default');
$dbg=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>101,'include_debug'=>true]);
$ok(count($dbg['brand_context']['items'])>=1,'include_debug retains sanitized items on blocked');
$GLOBALS['wpdb']=new PPb_MockWpdb(); for($i=0;$i<8;$i++){ppb_seed(7,'brand_fact','active',str_repeat('y',80));}
$GLOBALS['__insights'][700]=['id'=>700,'workspace_id'=>7,'status'=>'approved','evidence_ids'=>[1]];
$lim=$B::build(['workspace_id'=>7,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>700,'max_context_items'=>3,'max_context_chars'=>2000]);
$ok(count($lim['brand_context']['items'])<=3&&$lim['diagnostics']['limits']['max_context_items']===3,'max_context_items enforced + in diagnostics');
update_option('ves_brand_context_generation_enabled',false);
$GLOBALS['wpdb']=new PPb_MockWpdb();
$rdy=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>100]);
$ok($rdy['safety']['provider_execution_allowed']===false,'provider_execution_allowed=false by default for ready');
$GLOBALS['__opts']['ves_generation_execution_enabled']=true;
$rdy2=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>100]);
$ok($rdy2['safety']['provider_execution_allowed']===true,'provider_execution_allowed=true only when ready+exec flag');
$GLOBALS['__opts']['ves_generation_execution_enabled']=false;
$ok($rdy['output_contract']['review_requirements']['human_review_required']===true&&in_array('no_secrets',$rdy['output_contract']['forbidden_output_behavior'],true),'output contract review+forbidden');
$d=$B::build(['workspace_id'=>5,'use_case'=>'draft_generation','target_type'=>'brief','target_id'=>200]);
$ok($d['build_status']==='ready'&&$d['task']['source_brief_id']===200,'draft uses approved brief');
$dw=$B::build(['workspace_id'=>5,'use_case'=>'draft_generation','target_type'=>'brief','target_id'=>201]);
$ok($dw['build_status']==='warning'&&$dw['reason']==='target_not_ready'&&in_array('non_ready_brief',$dw['diagnostics']['warnings'],true),'non-ready brief ⇒ warning slug');
$ok($B::build(['workspace_id'=>5,'use_case'=>'x','target_type'=>'insight','target_id'=>1])['reason']==='unsupported_use_case','unsupported_use_case');
$ok($B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'z','target_id'=>1])['reason']==='invalid_target','invalid_target (type)');
$ok($B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>0])['reason']==='invalid_target','invalid_target (id)');
$ok($B::build(['workspace_id'=>99,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>100])['reason']==='workspace_mismatch','workspace_mismatch');
$leak=$B::build(['workspace_id'=>5,'use_case'=>'brief_generation','target_type'=>'insight','target_id'=>100,'objective'=>'sk-LEAK1234567890ab']);
$ok(strpos(json_encode($leak),'sk-LEAK1234567890ab')===false,'input secret redacted');
$scan=$rdy; unset($scan['safety']); unset($scan['output_contract']);
$ok(strpos(json_encode($scan),'raw_prompt')===false&&strpos(json_encode($scan),'provider_response')===false,'no raw prompt/provider content (outside safety/contract declarations)');
$tmp=sys_get_temp_dir().'/fiis_pkg_'.getmypid().'.json'; @unlink($tmp);
$ex=$B::export_to_file($rdy,$tmp); $ok($ex['ok']===true&&file_exists($tmp),'export writes file');
$ok($B::export_to_file($rdy,$tmp)['reason']==='exists_use_force','export refuses overwrite without force');
$dec=json_decode((string)@file_get_contents($tmp),true);
$fk=function($d)use(&$fk){if(!is_array($d))return false;foreach($d as $k=>$v){if(in_array(strtolower((string)$k),['raw_prompt','prompt_text','raw_response','provider_response','api_key'],true))return true;if(is_array($v)&&$fk($v))return true;}return false;};
$ok($dec!==null&&!$fk($dec),'exported file has no forbidden keys'); @unlink($tmp);
$src=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-generation-prompt-package-builder.php');
$ok(stripos($src,'wp_remote')===false&&stripos($src,'curl_')===false&&stripos($src,'api.openai.com')===false,'builder makes no network/AI call');
$ok(!preg_match('/\bmatch\s*\(|\bstr_contains\(|\?->/',$src),'builder 7.4-clean');
echo "\nGeneration prompt package builder: {$pass} passed, {$fail} failed\n"; if($fail>0){exit(1);}
