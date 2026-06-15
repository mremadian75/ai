<?php
/** Phase 8A — Evidence Binder + Brief/Draft Workbench tests. Read-only, evidence
 * separated from memory, missing evidence blocks brief, non-approved brief blocks
 * draft, disabled review controls with reason, no Generate/Publish, escaped output.
 * Run: php tests/test-ves-workbench-8a.php */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir().'/fiis_8a_'.getmypid().'/'); }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('is_wp_error')) { function is_wp_error($t){ return $t instanceof WP_Error; } }
if (!class_exists('WP_Error')) { class WP_Error { public $code;public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} } }
if (!function_exists('get_option')) { function get_option($k,$d=false){ return false; } }
if (!function_exists('current_time')) { function current_time($t='mysql',$g=0){ return '2026-06-08'; } }
require_once dirname(__DIR__).'/includes/class-ves-review-state.php';
require_once dirname(__DIR__).'/includes/class-ves-evidence-binder.php';
require_once dirname(__DIR__).'/includes/class-ves-workbench.php';
$pass=0;$fail=0; $ok=function($c,$l)use(&$pass,&$fail){ if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} };

// ── Evidence Binder ──────────────────────────────────────────────────────────
$mNone=VES_Evidence_Binder::model(['evidence_ids'=>[]]);
$ok($mNone['evidence_status']==='missing'&&$mNone['evidence_count']===0,'no evidence ⇒ status missing');
$mOne=VES_Evidence_Binder::model(['evidence_ids'=>[5]]); $ok($mOne['evidence_status']==='limited','1 ⇒ limited');
$mMany=VES_Evidence_Binder::model(['evidence_ids'=>[5,6,7]]); $ok($mMany['evidence_status']==='strong','3 ⇒ strong');
$ok($mNone['policy']['memory_is_not_evidence']===true,'binder policy memory_is_not_evidence');
$hNone=VES_Evidence_Binder::render_html(['evidence_ids'=>[]]);
$ok(strpos($hNone,'No evidence attached')!==false,'binder renders missing evidence honestly');
$ok(strpos($hNone,'Memory is not evidence')!==false,'binder shows memory-is-not-evidence; memory not displayed as evidence');
$hLeak=VES_Evidence_Binder::render_html(['evidence_ids'=>[1],'limitations'=>['key sk-LEAK1234567890ab here']]);
$ok(strpos($hLeak,'sk-LEAK1234567890ab')===false,'binder redacts secrets in limitations');
$ok(strpos(VES_Evidence_Binder::render_html(['evidence_ids'=>['<script>']]),'<script>')===false,'binder escapes output');

// ── Brief Workbench ──────────────────────────────────────────────────────────
$GLOBALS['__ins']=[
  10=>['id'=>10,'workspace_id'=>5,'status'=>'approved','title'=>'has ev','evidence_ids'=>[1,2]],
  11=>['id'=>11,'workspace_id'=>5,'status'=>'approved','title'=>'no ev','evidence_ids'=>[]],
];
if (!class_exists('VES_Intelligence_Store')) { eval('class VES_Intelligence_Store { public static function get_insight($id){return $GLOBALS["__ins"][(int)$id]??null;} public static function get_brief($id){return $GLOBALS["__br"][(int)$id]??null;} public static function get_draft($id){return null;} }'); }
$bwNoTarget=VES_Workbench::render_brief(['workspace_id'=>5,'insight_id'=>0]);
$ok(strpos($bwNoTarget,'insight_id')!==false && strpos($bwNoTarget,'no records are written')!==false,'no target ⇒ safe prompt, no write');
$bwBad=VES_Workbench::render_brief(['workspace_id'=>5,'insight_id'=>999]);
$ok(strpos($bwBad,'Insight not found')!==false,'invalid target id ⇒ safe error');
$bwScope=VES_Workbench::render_brief(['workspace_id'=>9,'insight_id'=>10]);
$ok(strpos($bwScope,'outside this workspace')!==false,'cross-workspace ⇒ safe error');
$bwOk=VES_Workbench::render_brief(['workspace_id'=>5,'insight_id'=>10]);
$ok(strpos($bwOk,'Brief Workbench')!==false && strpos($bwOk,'Evidence Binder')!==false,'brief workbench renders with evidence binder');
$ok(strpos($bwOk,'Future Island')!==false,'workbench product identity');
$ok(stripos($bwOk,'Generate with AI')===false && stripos($bwOk,'>Generate<')===false && stripos($bwOk,'Publish now')===false,'no Generate/Publish in brief workbench');
$ok(strpos($bwOk,'disabled')!==false && strpos($bwOk,'No reviewed-brief transition handler')!==false,'disabled review controls show reason');

// ── Draft Workbench ──────────────────────────────────────────────────────────
$GLOBALS['__br']=[
  20=>['id'=>20,'workspace_id'=>5,'status'=>'approved','objective'=>'go','evidence_ids'=>[1]],
  21=>['id'=>21,'workspace_id'=>5,'status'=>'draft','objective'=>'go','evidence_ids'=>[1]],
];
$dwBlocked=VES_Workbench::render_draft(['workspace_id'=>5,'brief_id'=>21]);
$ok(strpos($dwBlocked,'requires an approved/ready brief')!==false,'draft workbench blocks non-approved brief');
$dwOk=VES_Workbench::render_draft(['workspace_id'=>5,'brief_id'=>20]);
$ok(strpos($dwOk,'Brief is approved/ready')!==false && strpos($dwOk,'Output slots')!==false,'draft workbench ready w/ guidance slots');
$ok(stripos($dwOk,'Generate with AI')===false && stripos($dwOk,'Auto approve')===false,'no Generate/Auto-approve in draft workbench');

// ── Source contracts ─────────────────────────────────────────────────────────
$admin=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-admin.php');
$ok(strpos($admin,"'fi-brief-workbench'")!==false && strpos($admin,"'fi-draft-workbench'")!==false,'workbench admin pages registered');
$ok(strpos($admin,'function render_brief_workbench_page')!==false && strpos($admin,'function render_draft_workbench_page')!==false,'workbench render methods exist');
$src=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-workbench.php');
$ok(stripos($src,'wp_remote')===false && stripos($src,'curl_')===false,'workbench makes no network/AI call');
$ok(!preg_match('/\bmatch\s*\(|\bstr_contains\(|\?->/',$src),'workbench 7.4-clean');
$ok(!preg_match('/sk-[A-Za-z0-9]{16}|OPENAI_API_KEY/',$src.(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-evidence-binder.php')),'no secrets in workbench/binder');
echo "\nWorkbench 8A: {$pass} passed, {$fail} failed\n"; if($fail>0){exit(1);}
