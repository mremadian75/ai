<?php
/** Phase 7B — Operator Queue + Review State + Signal Room tests.
 * Read-only, honest, bounded; no fake metrics; product identity Future Island;
 * no Generate button. Run: php tests/test-ves-operator-console-7b.php */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir().'/fiis_7b_'.getmypid().'/'); }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } }
if (!function_exists('current_time')) { function current_time($t='mysql',$g=0){ return '2026-06-08 12:00:00'; } }
require_once dirname(__DIR__).'/includes/class-ves-review-state.php';
require_once dirname(__DIR__).'/includes/class-ves-operator-queue-service.php';
require_once dirname(__DIR__).'/includes/class-ves-signal-room.php';
$pass=0;$fail=0; $ok=function($c,$l)use(&$pass,&$fail){ if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} };

// ── Review state tokens ──────────────────────────────────────────────────────
$ok(VES_Review_State::normalize('proposed')==='needs_review' && VES_Review_State::normalize('active')==='approved','review-state normalize maps aliases');
$ok(VES_Review_State::normalize('totally_unknown')==='unknown','unknown status maps to unknown (never invented)');
foreach(['candidate','needs_review','approved','rejected','archived','expired','blocked','warning','ready','disabled','missing','recorded'] as $st){
  $m=VES_Review_State::meta($st); $ok($m['state']===$st && $m['label']!=='' && $m['class']!=='','review state defined: '.$st);
}
$b=VES_Review_State::badge('blocked'); $ok(strpos($b,'fiis-badge-blocked')!==false && strpos($b,'<script')===false,'badge renders escaped class');
$ok(strpos(VES_Review_State::badge('warning','<x>'),'&lt;x&gt;')!==false,'badge escapes override label');

// ── Operator queue (no stores) ⇒ honest unavailable ─────────────────────────
$q=VES_Operator_Queue_Service::build(['workspace_id'=>0,'limit'=>5]);
$ok(isset($q['queues'],$q['warnings'],$q['generated_at']),'operator queue returns structured output');
foreach(VES_Operator_Queue_Service::QUEUE_TYPES as $t){ $ok(isset($q['queues'][$t]),'queue present: '.$t); }
$ok($q['queues']['candidate_memory']['available']===false,'no memory service ⇒ candidate_memory unavailable (honest)');
$ok($q['queues']['missing_usage']['available']===false,'no usage service ⇒ missing_usage unavailable (no fake cost)');

// ── Candidate queue with a mock brand-context service ────────────────────────
eval('class VES_Brand_Context_Service_Mock { }');
if (!class_exists('VES_Brand_Context_Service')) {
  eval('class VES_Brand_Context_Service { public static function summary($ws=0){return ["candidates"=>2];} public static function load_rows($ws=0){return [["id"=>1,"memory_type"=>"voice_rule","summary"=>"a","content_json"=>json_encode(["brand_context"=>["status"=>"candidate"]])],["id"=>2,"memory_type"=>"brand_fact","summary"=>"b","content_json"=>json_encode(["brand_context"=>["status"=>"rejected"]])],["id"=>3,"memory_type"=>"voice_rule","summary"=>"c","content_json"=>json_encode(["brand_context"=>["status"=>"active"]])]];} public static function status_of($r){$c=json_decode($r["content_json"]??"[]",true);return $c["brand_context"]["status"]??"candidate";} }');
}
$q2=VES_Operator_Queue_Service::build(['workspace_id'=>5,'limit'=>10]);
$cm=$q2['queues']['candidate_memory'];
$ok($cm['available']===true,'candidate_memory available with service');
$only_candidates=true; foreach($cm['items'] as $it){ if(($it['status']??'')!=='candidate'){$only_candidates=false;} }
$ok($only_candidates && count($cm['items'])===1,'only candidate memory in queue (rejected/active excluded)');

// ── Queue limit enforcement ──────────────────────────────────────────────────
$qLimited=VES_Operator_Queue_Service::build(['workspace_id'=>5,'queue_type'=>'candidate_memory','limit'=>1]);
$ok(count($qLimited['queues']['candidate_memory']['items'])<=1,'queue limit enforced');

// ── Signal Room render ───────────────────────────────────────────────────────
$html=VES_Signal_Room::render_html(0);
$ok(is_string($html)&&strlen($html)>400,'Signal Room renders non-empty');
$ok(strpos($html,'Future Island')!==false,'root product identity is Future Island');
foreach(['Source','Signal','Insight','Brief','Draft','Memory','Usage'] as $s){ $ok(strpos($html,'>'.$s.'<')!==false,'spine shows '.$s); }
$ok(strpos($html,'Operator queue')!==false,'Signal Room shows operator queue');
$ok(strpos($html,'Generation execution')!==false && strpos($html,'disabled')!==false,'generation execution displayed as disabled');
$ok(strpos($html,'Live staging validation')!==false && strpos($html,'UNRUN')!==false,'live staging shows the REAL classification (UNRUN here — no recorded evidence pack), not a hardcoded pending');
$ok(stripos($html,'>Generate<')===false && stripos($html,'Generate with AI')===false,'no Generate button in Signal Room');
$ok(strpos($html,'Not available')!==false || strpos($html,'—')!==false,'honest empty/not-available states');
$ok(strpos($html,'<script>')===false,'no unescaped script in output');

// ── Source contracts ─────────────────────────────────────────────────────────
$admin=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-admin.php');
$ok(strpos($admin,"add_management_page('Future Island — Signal Room'")!==false,'Signal Room admin page registered');
$ok(strpos($admin,'function render_signal_room_page')!==false && strpos($admin,"current_user_can('manage_options')")!==false,'Signal Room capability check');
$cli=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-cli-brand-context.php');
$ok(strpos($cli,"'ves operator-queue'")!==false && strpos($cli,'no mutation')!==false,'operator-queue CLI registered + read-only');
$src=(string)@file_get_contents(dirname(__DIR__).'/includes/class-ves-operator-queue-service.php');
$ok(!preg_match('/\bmatch\s*\(|\bstr_contains\(|\?->/',$src),'operator queue 7.4-clean');
echo "\nOperator console 7B: {$pass} passed, {$fail} failed\n"; if($fail>0){exit(1);}
