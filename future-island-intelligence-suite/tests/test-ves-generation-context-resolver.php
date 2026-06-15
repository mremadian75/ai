<?php
/** Phase 6B-A (+corrective) resolver tests — disabled by default, trusted-only,
 * read-time redaction. No AI/mutation. Run: php tests/test-ves-generation-context-resolver.php */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/fiis_gcr_' . getmypid() . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d,$o=0){ return json_encode($d,$o); } }
if (!function_exists('current_time')) { function current_time($t='mysql',$g=0){ return '2026-06-08 12:00:00'; } }
$GLOBALS['__opts']=[];
if (!function_exists('get_option')) { function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['__opts'])?$GLOBALS['__opts'][$k]:$d; } }
if (!function_exists('update_option')) { function update_option($k,$v,$a=null){ $GLOBALS['__opts'][$k]=$v; return true; } }
require_once dirname(__DIR__).'/includes/class-ves-brand-context-service.php';
require_once dirname(__DIR__).'/includes/class-ves-generation-context-resolver.php';
$pass=0;$fail=0; $ok=function($c,$l)use(&$pass,&$fail){ if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} };
$GLOBALS['ves_brand_context_now']=strtotime('2026-06-08 12:00:00');
class GCRr_MockWpdb { public $prefix='wp_'; public $insert_id=0; public $data=['wp_ves_memory_records'=>[]]; private $a=0;
  public function get_charset_collate(){return '';} public function prepare($s,$ar=[]){if(!is_array($ar)){$ar=array_slice(func_get_args(),1);}foreach($ar as $x){$r=is_int($x)?(string)$x:"'".addslashes((string)$x)."'";$s=preg_replace('/%d|%s|%f/',$r,$s,1);}return $s;}
  public function query($s){return true;} public function get_results($s,$o=null){$rows=array_values($this->data['wp_ves_memory_records']);if(preg_match('/workspace_id = (\d+)/',$s,$m)){$rows=array_values(array_filter($rows,function($r)use($m){return (int)$r['workspace_id']===(int)$m[1];}));}return $rows;}
  public function get_row($s,$o=null){return null;} public function get_var($s){return null;} public function get_col($s,$i=0){return ['id'];}
  public function insert($t,$row,$f=null){$this->a++;$row['id']=$this->a;$this->data[$t][$this->a]=$row;$this->insert_id=$this->a;return 1;} public function update($t,$r,$w,$f=null,$wf=null){return 1;} }
if (!class_exists('VES_Memory_Records')) { eval('class VES_Memory_Records { public static function table_name(){return "wp_ves_memory_records";} public static function save_record($a){return 0;} }'); }
$GLOBALS['wpdb']=new GCRr_MockWpdb();
function gcrr_seed($ws,$type,$status,$sum,$opts=[]){global $wpdb;$row=array_merge(['id'=>0,'workspace_id'=>$ws,'memory_type'=>$type,'summary'=>$sum,'importance_score'=>70,'is_pinned'=>0,'expires_at'=>null,'content_json'=>json_encode(['brand_context'=>['status'=>$status]]),'source_type'=>'draft','source_id'=>1,'updated_at'=>'2026-06-01','created_at'=>'2026-06-01'],$opts);$wpdb->insert('wp_ves_memory_records',$row);}

$ok(VES_Generation_Context_Resolver::is_enabled()===false,'flag defaults OFF');
gcrr_seed(1,'voice_rule','active','tone');
$e=VES_Generation_Context_Resolver::resolve(['workspace_id'=>1,'use_case'=>'brief_generation']);
$ok($e['enabled']===false&&$e['applied']===false&&$e['reason']==='feature_disabled'&&$e['context_package']['items']===[],'flag off ⇒ no context, feature_disabled');
update_option('ves_brand_context_generation_enabled',true);
$GLOBALS['wpdb']=new GCRr_MockWpdb(); gcrr_seed(1,'voice_rule','active','a'); gcrr_seed(1,'preferred_term','pinned','b',['is_pinned'=>1]);
$e=VES_Generation_Context_Resolver::resolve(['workspace_id'=>1,'use_case'=>'brief_generation']);
$ok($e['applied']===true&&count($e['context_package']['items'])===2,'enabled+trusted ⇒ active+pinned included');
$GLOBALS['wpdb']=new GCRr_MockWpdb();
gcrr_seed(2,'voice_rule','active','x');gcrr_seed(2,'voice_rule','candidate','x');gcrr_seed(2,'preferred_term','rejected','x');gcrr_seed(2,'brand_fact','archived','x');gcrr_seed(2,'brand_fact','active','x',['expires_at'=>'2026-01-01']);
$e2=VES_Generation_Context_Resolver::resolve(['workspace_id'=>2,'use_case'=>'brief_generation']);
$ok(count($e2['context_package']['items'])===1,'candidate/rejected/archived/expired excluded');
$ok(VES_Generation_Context_Resolver::resolve(['workspace_id'=>0,'use_case'=>'brief_generation'])['reason']==='no_workspace','no_workspace reason');
$ok(VES_Generation_Context_Resolver::resolve(['workspace_id'=>1,'use_case'=>'nope'])['reason']==='invalid_use_case','invalid_use_case reason');
// read-time redaction
$GLOBALS['wpdb']=new GCRr_MockWpdb(); gcrr_seed(6,'voice_rule','active','leak sk-OLDLEAKEDVALUE12345 x'); gcrr_seed(6,'brand_fact','active','Warm voice.');
$cenv=VES_Generation_Context_Resolver::resolve(['workspace_id'=>6,'use_case'=>'brief_generation']);
$blob=implode(' ',array_map(function($i){return $i['summary'];},$cenv['context_package']['items']));
$ok(strpos($blob,'sk-OLDLEAKEDVALUE12345')===false,'resolve() redacts summaries at read time');
$ok(isset($cenv['context_package']['items'][0]['memory_id']),'traceability preserved after redaction');
$sec=VES_Generation_Context_Resolver::section_from_items([['record_type'=>'voice_rule','summary'=>'sk-LEAK1234567890ab','memory_id'=>1]]);
$ok(strpos(json_encode($sec),'sk-LEAK1234567890ab')===false,'adapter section redacts');
$ok($sec['policy']['memory_is_not_evidence']===true,'memory_is_not_evidence policy');
$prev=VES_Generation_Context_Resolver::preview(['workspace_id'=>6,'use_case'=>'brief_generation']);
$ok(strpos(json_encode($prev),'summary')===false,'preview exposes no summaries');
$src=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-generation-context-resolver.php');
$ok(!preg_match('/\bmatch\s*\(|\bstr_contains\(|\?->/',$src),'resolver 7.4-clean');
echo "\nGeneration context resolver: {$pass} passed, {$fail} failed\n"; if($fail>0){exit(1);}
