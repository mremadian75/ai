<?php
/**
 * Phase 4 — pilot feedback, seed/reset, readiness gates, loop trace.
 *
 * Proves:
 *  1. feedback: whitelisted types/decisions, clamped rating, sanitized text,
 *     reviewable, capability + nonce on the handler
 *  2. seed: three [DEMO] scenario chains at staggered stages, registry-recorded,
 *     re-seed refused until reset
 *  3. reset: removes EXACTLY the registered rows — non-demo data survives;
 *     explicit confirmation enforced server-side on the admin handler
 *  4. readiness: not_ready_for_pilot while live validation is UNRUN (honest),
 *     ready_for_controlled_pilot only when every gate is green
 *  5. trace: one complete source→signal→evidence→insight→brief trail, escaped,
 *     workspace-guarded
 *
 * Run: php tests/test-ves-pilot-readiness.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
define('VES_INTAKE_NO_EXIT', true);

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$s)));}
function sanitize_textarea_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function esc_url_raw($s){return trim((string)$s);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_url($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['__o'])?$GLOBALS['__o'][$k]:$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['__o'][$k]);return true;}
function get_current_user_id(){return 7;}
function dbDelta($s){return [];}
function apply_filters($t,$v){return $v;}
function current_user_can($c){return !empty($GLOBALS['__can']);}
function check_admin_referer($a){$GLOBALS['__nonce_checked'][]=$a;if(empty($GLOBALS['__nonce_ok'])){throw new RuntimeException('nonce failure: '.$a);}return true;}
function wp_nonce_field($a,$n='_wpnonce',$r=true,$e=true){return '<input type="hidden" name="'.$n.'" value="nonce-'.$a.'">';}
function wp_die($m='',$t='',$args=[]){throw new RuntimeException('wp_die:'.(is_array($args)&&isset($args['response'])?$args['response']:(string)$t));}
function wp_safe_redirect($u){$GLOBALS['__redirects'][]=$u;return true;}
function admin_url($p=''){return 'http://t/wp-admin/'.$p;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_unslash($v){return $v;}
$GLOBALS['__o']=[];$GLOBALS['__can']=true;$GLOBALS['__nonce_ok']=true;$GLOBALS['__nonce_checked']=[];$GLOBALS['__redirects']=[];

class PilotWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public function get_charset_collate(){return '';}
    public function insert($t,$r,$f=null){$this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;}
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function delete($t,$w,$f=null){$n=0;foreach(($this->data[$t]??[]) as $id=>$r){$m=true;foreach($w as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$m=false;break;}}if($m){unset($this->data[$t][$id]);$n++;}}return $n;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    public function query($sql){
        // Support the seed-reset DELETE … LIKE for demo usage events.
        if(preg_match("/DELETE FROM (\S+) WHERE (.+)/s",$sql,$m)){
            $t=$m[1];$where=$m[2];$n=0;
            preg_match_all("/(\w+)\s*=\s*'?([^'\s]+)'?/",$where,$mm,PREG_SET_ORDER);
            $conds=[];foreach($mm as $p){ if(ctype_digit($p[1]))continue; $conds[$p[1]]=trim($p[2],"'"); }
            $like=null; if(preg_match("/run_id LIKE '([^']*)'/",$where,$lm)){ $like=rtrim($lm[1],'%'); unset($conds['run_id']); }
            foreach(($this->data[$t]??[]) as $id=>$r){
                $ok=true;
                foreach($conds as $k=>$v){ if((string)($r[$k]??'')!==(string)$v){$ok=false;break;} }
                if($ok && $like!==null && strpos((string)($r['run_id']??''),$like)!==0){$ok=false;}
                if($ok){unset($this->data[$t][$id]);$n++;}
            }
            return $n;
        }
        return true;
    }
    private function rows_matching($sql){ if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]]; $table=$m[1];$rows=$this->data[$table]??[]; $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql)); $where=preg_split('/ORDER BY| LIMIT/',$where)[0]; preg_match_all("/(\w+)\s*=\s*'?([^'\s]+)'?/",$where,$mm,PREG_SET_ORDER); $conds=[];foreach($mm as $p){ if(ctype_digit($p[1]))continue; $conds[$p[1]]=$p[2]; } $like=null; if(preg_match("/run_id LIKE '([^']*)'/",$where,$lm)){$like=rtrim($lm[1],'%');unset($conds['run_id']);} $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)trim($v,"'")){$ok=false;break;}}if($ok&&$like!==null&&strpos((string)($r['run_id']??''),$like)!==0){$ok=false;}if($ok)$out[]=$r;} return [$table,$out]; }
    public function get_var($sql){ if(preg_match('/COUNT\(\*\)(?: AS \w+)? FROM (\S+)/',$sql,$m)){[$t,$rows]=$this->rows_matching($sql);return (string)count($rows);} if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows_matching($sql);return $rows?($rows[0][$sm[1]]??null):null;} return null; }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows_matching($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){
        [$t,$rows]=$this->rows_matching($sql);
        // GROUP BY col + COUNT(*) (insight status breakdown).
        if(preg_match('/GROUP BY (\w+)/',$sql,$gm) && stripos($sql,'COUNT(*)')!==false){
            $col=$gm[1];$agg=[];
            foreach($rows as $r){$k=(string)($r[$col]??'');$agg[$k]=($agg[$k]??0)+1;}
            $out=[];foreach($agg as $k=>$c){$out[]=['k'=>$k,'c'=>$c];}
            usort($out,function($a,$b){return $b['c']-$a['c'];});
            return $out;
        }
        return array_reverse($rows);
    }
}
$GLOBALS['wpdb']=new PilotWpdb();

// Memory records: writes into the mock table so reset deletion is REAL here.
final class VES_Memory_Records {
    public static function table_name(){ global $wpdb; return $wpdb->prefix.'ves_memory_records'; }
    public static function save_record(array $args){
        global $wpdb;
        $wpdb->insert(self::table_name(), [
            'workspace_id'=>(int)($args['workspace_id']??0),'memory_type'=>(string)($args['memory_type']??''),
            'source_type'=>(string)($args['source_type']??''),'source_id'=>(string)($args['source_id']??''),
            'summary'=>(string)($args['summary']??''),
        ]);
        return (int)$wpdb->insert_id;
    }
    public static function workspace_id_for_user($uid=0){ return 3; }
}
// Controllable release/validation stubs for the readiness gates.
final class VES_RC_Readiness_Service {
    public static $status = 'ready_for_staging';
    public static function report(array $a=[]){ return ['status'=>self::$status,'blockers'=>[],'live_validation'=>['status'=>'unrun'],'plugin_version'=>'test','rc_label'=>'t']; }
}
final class VES_RC_Evidence_Pack {
    public static $state = ['status'=>'unrun'];
    public static function live_validation_state(){ return self::$state; }
}

$root = dirname(__DIR__);
require_once $root.'/includes/class-ves-ai-usage-tracker.php';
require_once $root.'/includes/class-ves-waterfall-sourcing.php';
require_once $root.'/includes/class-ves-intelligence-store.php';
require_once $root.'/includes/class-ves-review-decision-ledger.php';
require_once $root.'/includes/class-ves-review-state.php';
require_once $root.'/includes/class-ves-insight-brief-builder.php';
require_once $root.'/includes/class-ves-generation-prompt-package-builder.php';
require_once $root.'/includes/class-ves-brand-context-service.php';
require_once $root.'/includes/class-ves-source-intake.php';
require_once $root.'/includes/class-ves-pilot-feedback.php';
require_once $root.'/includes/class-ves-pilot-seed.php';
require_once $root.'/includes/class-ves-pilot-readiness.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};
$WS = 3;

// ── 1. Feedback contract ──────────────────────────────────────────────────────
VES_Pilot_Feedback::create_table();
$fid = VES_Pilot_Feedback::save(['workspace_id'=>$WS,'object_type'=>'insight','object_id'=>4,'rating'=>9,'decision'=>'accepted','comment'=>'<script>x</script>useful finding','next_action'=>'brief it']);
$ok(is_int($fid) && $fid > 0, 'feedback saves');
$rows = VES_Pilot_Feedback::recent($WS, 5);
$ok(count($rows) === 1 && (int)$rows[0]['rating'] === 5, 'rating clamped to 5');
$ok(strpos((string)$rows[0]['comment'], '<script>') === false && strpos((string)$rows[0]['comment'], 'useful finding') !== false, 'comment sanitized, content kept');
$e = VES_Pilot_Feedback::save(['workspace_id'=>$WS,'object_type'=>'weird','object_id'=>4]);
$ok(is_wp_error($e) && $e->get_error_code()==='ves_feedback_bad_type', 'unknown object type refused');
$e = VES_Pilot_Feedback::save(['workspace_id'=>0,'object_type'=>'insight','object_id'=>4]);
$ok(is_wp_error($e), 'workspace 0 refused');
$saved = VES_Pilot_Feedback::save(['workspace_id'=>$WS,'object_type'=>'brief','object_id'=>4,'decision'=>'evil-decision']);
$ok(is_int($saved) && (string)VES_Pilot_Feedback::recent($WS,1)[0]['decision'] === 'none', 'unknown decision falls back to none');
$ok(VES_Pilot_Feedback::count_for($WS) === 2, 'count_for counts workspace rows');

// Handler security.
$GLOBALS['__can']=false; $denied=false;
try { VES_Pilot_Feedback::handle(); } catch (RuntimeException $e) { $denied = strpos($e->getMessage(),'403')!==false; }
$ok($denied, 'feedback handler requires manage_options');
$GLOBALS['__can']=true; $GLOBALS['__nonce_ok']=false; $blocked=false;
$_POST=['workspace_id'=>$WS,'object_type'=>'insight','object_id'=>4];
try { VES_Pilot_Feedback::handle(); } catch (RuntimeException $e) { $blocked = strpos($e->getMessage(),'nonce failure')!==false; }
$ok($blocked && in_array('ves_pilot_feedback',$GLOBALS['__nonce_checked'],true), 'feedback handler enforces the nonce');
$GLOBALS['__nonce_ok']=true;
VES_Pilot_Feedback::handle();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_notice=feedback_recorded')!==false, 'valid feedback redirects with the notice');
$_POST=[];

// ── 2. Seed: three [DEMO] scenarios at staggered stages ──────────────────────
// A non-demo row that MUST survive reset.
$keep = VES_Source_Intake::process_source(['workspace_id'=>$WS,'intake_type'=>'manual','source_title'=>'Real operator note','notes'=>'not demo']);
$keep_id = (int)$keep['source_id'];

$reg = VES_Pilot_Seed::seed($WS);
$ok(is_array($reg) && count($reg['scenarios']) === 3, 'seed creates the three scenarios');
$ok(($reg['scenarios']['A']['stage'] ?? '') === 'brief_built' && ($reg['scenarios']['B']['stage'] ?? '') === 'insight_approved' && ($reg['scenarios']['C']['stage'] ?? '') === 'insight_draft',
    'scenarios staged at A=brief / B=approved insight / C=draft insight');
$ok(count($reg['source']) === 3 && count($reg['insight']) === 3 && count($reg['brief']) === 1 && count($reg['memory']) === 1,
    'registry records every created id');
$demo_titles_ok = true;
foreach ((array)VES_Intelligence_Store::list_insights(['workspace_id'=>$WS,'limit'=>20]) as $i) {
    if (in_array((int)$i['id'], array_map('intval',$reg['insight']), true) && strpos((string)$i['title'], '[DEMO]') !== 0) { $demo_titles_ok = false; }
}
$ok($demo_titles_ok, 'every seeded insight title is [DEMO]-prefixed');
$c_row = VES_Intelligence_Store::get_insight((int)$reg['scenarios']['C']['insight_id']);
$ok(($c_row['status'] ?? '') === 'draft' && VES_Intelligence_Store::insight_presentation_state($c_row) === 'ready_for_review',
    'scenario C waits for HUMAN review (draft, evidence-backed)');
$again = VES_Pilot_Seed::seed($WS);
$ok(is_wp_error($again) && $again->get_error_code()==='ves_seed_already_seeded', 're-seed refused until reset');
$st = VES_Pilot_Seed::status($WS);
$ok(!empty($st['seeded']) && (int)$st['counts']['insight'] === 3, 'status reports the seeded registry');

// ── 3. Readiness gates: honest while validation is UNRUN ─────────────────────
$r1 = VES_Pilot_Readiness::report($WS);
$ok($r1['classification'] === 'not_ready_for_pilot', 'UNRUN validation => NOT ready for pilot (never optimistic)');
$ok(strpos(implode(' ', $r1['missing']), 'live staging validation') !== false, 'missing list names the validation gate');
$html = VES_Pilot_Readiness::render_html($WS);
$ok(strpos($html, 'Not ready for a controlled pilot yet') !== false, 'page answers THE question honestly');
$ok(strpos($html, 'never look greener') !== false || strpos($html, 'can never look greener') !== false, 'page carries the honesty note');
$ok(strpos($html, 'nonce-ves_pilot_reset') !== false && strpos($html, 'confirm_reset') !== false, 'reset form is nonce-protected with explicit confirmation');
$ok(strpos($html, 'Scenario A') !== false && strpos($html, 'Scenario C') !== false, 'scenario gates listed');

// All gates green => pilot-ready (and only then).
VES_RC_Evidence_Pack::$state = ['status'=>'passed','files_verified'=>true,'evidence_pack_hash'=>str_repeat('ab',32)];
$r2 = VES_Pilot_Readiness::report($WS);
$ok($r2['classification'] === 'ready_for_controlled_pilot' && $r2['missing'] === [], 'file-backed pass + complete loop => ready_for_controlled_pilot');
$html2 = VES_Pilot_Readiness::render_html($WS);
$ok(strpos($html2, 'A controlled pilot can run now') !== false && strpos($html2, 'never a production claim') !== false,
    'pilot-ready verdict still refuses production language');
VES_RC_Evidence_Pack::$state = ['status'=>'unrun'];

// ── 4. Trace: complete trail for scenario A ──────────────────────────────────
$a_ins = (int)$reg['scenarios']['A']['insight_id'];
$trace = VES_Pilot_Readiness::trace_insight($WS, $a_ins);
foreach (['Source','Signal','Evidence','Insight','Brief','Usage','Memory','Feedback'] as $step) {
    $ok(strpos($trace, '>' . $step . '<') !== false, "trace carries the {$step} step");
}
$ok(strpos($trace, '[DEMO] [Competitor] spring launch announcement') !== false, 'trace resolves back to the exact source');
$ok(strpos($trace, '1 memory record(s)') !== false, 'trace counts the memory candidate');
$trace_bad = VES_Pilot_Readiness::trace_insight(9, $a_ins);
$ok(strpos($trace_bad, 'different workspace') !== false, 'trace refuses cross-workspace reads');

// ── 5. Reset: exact, demo-only removal ───────────────────────────────────────
// Handler requires explicit confirmation.
$_POST=['workspace_id'=>$WS];
VES_Pilot_Readiness::handle_reset();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_err=ves_seed_confirm_required')!==false, 'reset WITHOUT confirmation is refused server-side');
$st = VES_Pilot_Seed::status($WS);
$ok(!empty($st['seeded']), 'nothing was deleted by the refused reset');
$_POST=['workspace_id'=>$WS,'confirm_reset'=>'1'];
VES_Pilot_Readiness::handle_reset();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_notice=pilot_reset')!==false, 'confirmed reset succeeds');
$_POST=[];
$st = VES_Pilot_Seed::status($WS);
$ok(empty($st['seeded']), 'registry removed after reset');
$srcs = (array)VES_Intelligence_Store::list_sources(['workspace_id'=>$WS,'limit'=>20]);
$ids = array_map(function($r){return (int)$r['id'];}, $srcs);
$ok(in_array($keep_id, $ids, true), 'NON-demo source survives the reset');
$demo_gone = true;
foreach ((array)$reg['source'] as $sid) { if (in_array((int)$sid, $ids, true)) { $demo_gone = false; } }
$ok($demo_gone, 'every demo source is gone');
$ok(count((array)VES_Intelligence_Store::list_insights(['workspace_id'=>$WS,'limit'=>20])) === 0, 'demo insights gone (none others existed)');
$mem_left = $GLOBALS['wpdb']->data['wp_ves_memory_records'] ?? [];
$ok(count($mem_left) === 0, 'demo memory candidate removed');
$reset_again = VES_Pilot_Seed::reset($WS);
$ok(is_wp_error($reset_again) && $reset_again->get_error_code()==='ves_seed_not_seeded', 'second reset refused (nothing registered)');

// Seed handler security.
$GLOBALS['__can']=false; $denied=false;
try { VES_Pilot_Readiness::handle_seed(); } catch (RuntimeException $e) { $denied = strpos($e->getMessage(),'403')!==false; }
$ok($denied, 'seed handler requires manage_options');
$GLOBALS['__can']=true;

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
