<?php
/**
 * Phase 2 — Source Intake surface security + honesty contract.
 *
 * Proves:
 *  1. every mutation goes through capability + nonce (admin-post wrappers)
 *  2. rendering is escaped (hostile titles can't script the page)
 *  3. the page promises (and the forms enforce) NO fetching, NO generation,
 *     NO auto-approval — and contains no such affordances
 *  4. notices map error CODES to fixed copy (no raw query-string echo)
 *  5. the four loop forms (source / URL / signal / insight / brief) exist with
 *     their hidden action names + nonce fields
 *
 * Run: php tests/test-ves-source-intake-page.php
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
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function get_current_user_id(){return 7;}
function dbDelta($s){return [];}
function apply_filters($t,$v){return $v;}
function current_user_can($c){return !empty($GLOBALS['__can']);}
function check_admin_referer($a){$GLOBALS['__nonce_checked'][]=$a;if(empty($GLOBALS['__nonce_ok'])){throw new RuntimeException('nonce failure: '.$a);}return true;}
function wp_nonce_field($a,$n='_wpnonce',$r=true,$echo=true){return '<input type="hidden" name="'.$n.'" value="nonce-'.$a.'">';}
function wp_die($m='',$t='',$args=[]){throw new RuntimeException('wp_die:'.(is_array($args)&&isset($args['response'])?$args['response']:(string)$t));}
function wp_safe_redirect($u){$GLOBALS['__redirects'][]=$u;return true;}
function admin_url($p=''){return 'http://t/wp-admin/'.$p;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_unslash($v){return $v;}
$GLOBALS['__o']=[];$GLOBALS['__can']=true;$GLOBALS['__nonce_ok']=true;$GLOBALS['__nonce_checked']=[];$GLOBALS['__redirects']=[];

class IntakeWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function insert($t,$r,$f=null){$this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;}
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows_matching($sql){ if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]]; $table=$m[1];$rows=$this->data[$table]??[]; $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql)); $where=preg_split('/ORDER BY| LIMIT/',$where)[0]; preg_match_all("/(\w+)\s*=\s*'?([^'\s]+)'?/",$where,$mm,PREG_SET_ORDER); $conds=[];foreach($mm as $p){ if(ctype_digit($p[1]))continue; $conds[$p[1]]=$p[2]; } $out=[];foreach($rows as $r){$ok=true;foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}if($ok)$out[]=$r;} return [$table,$out]; }
    public function get_var($sql){ if(preg_match('/COUNT\(\*\)(?: AS \w+)? FROM (\S+)/',$sql,$m)){[$t,$rows]=$this->rows_matching($sql);return (string)count($rows);} if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows_matching($sql);return $rows?($rows[0][$sm[1]]??null):null;} return null; }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows_matching($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){[$t,$rows]=$this->rows_matching($sql);return array_reverse($rows);}
}
$GLOBALS['wpdb']=new IntakeWpdb();

// Memory records capture stub (the real records layer is proven elsewhere).
final class VES_Memory_Records {
    public static $saved = [];
    public static function save_record(array $args) { self::$saved[] = $args; return count(self::$saved); }
    public static function workspace_id_for_user($uid = 0) { return 3; }
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

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Render contract ────────────────────────────────────────────────────────
$html = VES_Source_Intake::render_html(3);
$ok(strpos($html,'Future Island · Intake')!==false, 'page carries the FI identity');
foreach (['ves_intake_source','ves_intake_signal','ves_intake_signal_to_insight','ves_insight_to_brief'] as $a) {
    $ok(substr_count($html,'value="'.$a.'"')>=1, "form posts hidden action {$a}");
    $ok(strpos($html,'nonce-'.$a)!==false, "form {$a} carries its nonce field");
}
$ok(strpos($html,'never fetched')!==false || strpos($html,'nothing is fetched')!==false, 'page states URLs are never fetched');
$ok(strpos($html,'No AI generation, no auto-approval, no publishing, no fetching')!==false, 'closing policy line present');
$ok(stripos($html,'Generate with AI')===false && stripos($html,'Publish now')===false && stripos($html,'Auto approve')===false, 'no generate/publish/auto-approve affordances');
$ok(strpos($html,'fi-skip-link')!==false, 'keyboard skip link present');
$ok(strpos($html,'name="opportunity_score" min="0" max="100"')!==false, 'opportunity score input is bounded 0–100');

// ── 2. Escaping: hostile titles can't script the page ────────────────────────
VES_Source_Intake::process_source(['workspace_id'=>3,'intake_type'=>'manual','source_title'=>'<script>alert(1)</script>Sneaky','notes'=>'x']);
$html2 = VES_Source_Intake::render_html(3);
$ok(strpos($html2,'<script>alert(1)</script>')===false, 'hostile source title never renders as markup');
$ok(strpos($html2,'Sneaky')!==false, 'sanitized title text still listed');

// ── 3. Presentation-state badges in the recent insights table ────────────────
$r = VES_Source_Intake::process_source(['workspace_id'=>3,'intake_type'=>'url','source_url'=>'https://ex.example/a','source_title'=>'Ref']);
$r = VES_Source_Intake::process_signal(['workspace_id'=>3,'source_id'=>$r['source_id'],'signal_type'=>'mention','title'=>'sig']);
$r = VES_Source_Intake::process_signal_to_insight(['workspace_id'=>3,'signal_id'=>$r['signal_id'],'insight_type'=>'opportunity','opportunity_score'=>40,'title'=>'finding']);
$html3 = VES_Source_Intake::render_html(3);
$ok(strpos($html3,'Ready for review')!==false, 'evidence-backed draft insight shows the ready_for_review badge');
$ok(strpos($html3,'1 linked')!==false, 'insight row shows its evidence linkage');

// ── 4. Notices: codes map to fixed copy, never raw echo ──────────────────────
$_GET = ['fi_notice'=>'source_created','fi_id'=>'12'];
$m = new ReflectionMethod('VES_Source_Intake','notice_html'); $m->setAccessible(true);
$n = (string) $m->invoke(null);
$ok(strpos($n,'Source recorded')!==false && strpos($n,'(id 12)')!==false, 'success notice renders fixed copy + id');
$_GET = ['fi_notice'=>'<img src=x onerror=alert(1)>'];
$ok((string) $m->invoke(null) === '', 'unknown notice key renders NOTHING (no echo of query input)');
$_GET = ['fi_err'=>'ves_intake_bad_url'];
$n = (string) $m->invoke(null);
$ok(strpos($n,'recorded as a reference only')!==false, 'error code maps to fixed copy');
$_GET = ['fi_err'=>'<script>x</script>weird_code'];
$n = (string) $m->invoke(null);
$ok(strpos($n,'<script>')===false, 'hostile error code is stripped, never echoed raw');
$_GET = [];

// ── 5. Handlers: capability + nonce enforced, then redirect with notice ──────
$GLOBALS['__can'] = false;
$denied = false;
try { VES_Source_Intake::handle_source(); } catch (RuntimeException $e) { $denied = strpos($e->getMessage(),'403')!==false; }
$ok($denied, 'no manage_options => wp_die 403, nothing processed');
$GLOBALS['__can'] = true;
$GLOBALS['__nonce_ok'] = false;
$blocked = false;
$_POST = ['workspace_id'=>3,'intake_type'=>'manual','source_title'=>'After nonce'];
try { VES_Source_Intake::handle_source(); } catch (RuntimeException $e) { $blocked = strpos($e->getMessage(),'nonce failure')!==false; }
$ok($blocked && in_array('ves_intake_source',$GLOBALS['__nonce_checked'],true), 'bad nonce blocks the action (check_admin_referer enforced)');
$GLOBALS['__nonce_ok'] = true;
VES_Source_Intake::handle_source();
$last = (string) end($GLOBALS['__redirects']);
$ok(strpos($last,'fi_notice=source_created')!==false && strpos($last,'page=fi-intake')!==false, 'valid request redirects back with a success notice');
$_POST = ['workspace_id'=>3,'intake_type'=>'url','source_url'=>'ftp://nope'];
VES_Source_Intake::handle_source();
$last = (string) end($GLOBALS['__redirects']);
$ok(strpos($last,'fi_err=ves_intake_bad_url')!==false, 'failed request redirects back with the error CODE only');
$_POST = [];

// ── 5b. Phase 4/5 action-based transitions (no ID copying on the normal path) ─
// Build a full chain to exercise the row actions + new processors.
$r = VES_Source_Intake::process_source(['workspace_id'=>3,'intake_type'=>'manual','source_title'=>'Action chain note','notes'=>'n']);
$chain_src = (int) $r['source_id'];
$r = VES_Source_Intake::process_signal(['workspace_id'=>3,'source_id'=>$chain_src,'signal_type'=>'trend','title'=>'chain signal']);
$chain_sig = (int) $r['signal_id'];
$r = VES_Source_Intake::process_signal_to_insight(['workspace_id'=>3,'signal_id'=>$chain_sig,'insight_type'=>'opportunity','title'=>'chain finding']);
$chain_ins = (int) $r['insight_id'];
VES_Intelligence_Store::update_insight_status($chain_ins, 'approved');

// Prefill: the "Record signal →" row action lands with the source pinned.
$_GET = ['prefill_source' => (string) $chain_src];
$hp = VES_Source_Intake::render_html(3);
$ok(strpos($hp, 'From source #' . $chain_src) !== false && strpos($hp, 'name="source_id" value="' . $chain_src . '"') !== false,
    'prefill_source pins the source into the signal form (no ID copying)');
$_GET = ['prefill_signal' => (string) $chain_sig];
$hp = VES_Source_Intake::render_html(3);
$ok(strpos($hp, 'From signal #' . $chain_sig) !== false && strpos($hp, 'name="signal_id" value="' . $chain_sig . '"') !== false,
    'prefill_signal pins the signal into the promotion form');
$_GET = ['prefill_source' => '999999'];
$hp = VES_Source_Intake::render_html(3);
$ok(strpos($hp, 'From source #') === false, 'unknown prefill id is ignored (falls back to the plain form)');
$_GET = [];

// Archive row actions: prefill links + nonce'd one-click forms + workbench links.
$ha = VES_Source_Intake::render_html(3);
$ok(strpos($ha, 'prefill_source=' . $chain_src) !== false && strpos($ha, 'Record signal') !== false, 'source rows carry the Record-signal action link');
$ok(strpos($ha, 'prefill_signal=' . $chain_sig) !== false, 'signal rows carry the Promote action link');
$ok(strpos($ha, 'page=fi-brief-workbench') !== false && strpos($ha, 'insight_id=' . $chain_ins) !== false, 'insight rows deep-link into the workbench');
$ok(strpos($ha, 'value="ves_intake_memory_candidate"') !== false && strpos($ha, 'nonce-ves_intake_memory_candidate') !== false, 'approved insight rows carry the nonce-protected memory-candidate action');
$ok(strpos($ha, 'fi-workflow-spine') !== false && strpos($ha, 'fi-intake-next') !== false, 'route spine + next-action panel render');
$ok(strpos($ha, 'value="ves_insight_to_brief"') !== false, 'approved insight rows carry the one-click Build-brief action');

// Brief + preview chain: build brief, approve it, then preview records ONE usage event.
$r = VES_Source_Intake::process_insight_to_brief(['workspace_id'=>3,'insight_id'=>$chain_ins]);
$chain_brief = (int) $r['brief_id'];
$blocked = VES_Source_Intake::process_prompt_preview(['workspace_id'=>3,'brief_id'=>$chain_brief]);
$ok(is_wp_error($blocked) && $blocked->get_error_code() === 'ves_intake_preview_blocked', 'preview of an unapproved brief is refused (builder gate holds)');
VES_Intelligence_Store::update_brief_status($chain_brief, 'approved');
$p1 = VES_Source_Intake::process_prompt_preview(['workspace_id'=>3,'brief_id'=>$chain_brief]);
$ok(is_array($p1) && $p1['usage_event_id'] > 0 && $p1['reused_event'] === false, 'approved brief: preview builds and ledgers a usage event');
$p2 = VES_Source_Intake::process_prompt_preview(['workspace_id'=>3,'brief_id'=>$chain_brief]);
$ok(is_array($p2) && (int)$p2['usage_event_id'] === (int)$p1['usage_event_id'] && $p2['reused_event'] === true, 'repeat preview REUSES the same usage event (idempotent ledger)');

// Memory candidate action: approved-only, forced candidate status.
$m_err = VES_Source_Intake::process_memory_candidate(['workspace_id'=>3,'insight_id'=>999999]);
$ok(is_wp_error($m_err), 'memory candidate for unknown insight refused');
$m = VES_Source_Intake::process_memory_candidate(['workspace_id'=>3,'insight_id'=>$chain_ins]);
$ok(is_array($m) && $m['memory_id'] > 0, 'memory candidate proposed from the approved insight');
$saved = end(VES_Memory_Records::$saved);
$ok(in_array('candidate', (array)($saved['tags'] ?? []), true) && (string)($saved['source_id'] ?? '') === (string)$chain_ins,
    'candidate status FORCED + traceable to the insight');

// Preview handler redirects to the draft workbench (the preview itself).
$_POST = ['workspace_id'=>3,'brief_id'=>$chain_brief];
VES_Source_Intake::handle_prompt_preview();
$last = (string) end($GLOBALS['__redirects']);
$ok(strpos($last,'page=fi-draft-workbench')!==false && strpos($last,'fi_notice=preview_recorded')!==false, 'preview action lands the operator ON the preview');
$GLOBALS['__can'] = false;
$denied = false;
try { VES_Source_Intake::handle_prompt_preview(); } catch (RuntimeException $e) { $denied = strpos($e->getMessage(),'403')!==false; }
$ok($denied, 'preview action requires manage_options');
$GLOBALS['__can'] = true;
$_POST = [];

// ── 5c. Workbench review handler (the audited decision surface) ──────────────
require_once $root.'/includes/class-ves-workbench.php';
$GLOBALS['__can'] = false; $denied = false;
try { VES_Workbench::handle_review(); } catch (RuntimeException $e) { $denied = strpos($e->getMessage(),'403')!==false; }
$ok($denied, 'review handler requires manage_options');
$GLOBALS['__can'] = true; $GLOBALS['__nonce_ok'] = false; $blocked = false;
$_POST = ['object_type'=>'insight','object_id'=>$chain_ins,'decision'=>'approve','workspace_id'=>3];
try { VES_Workbench::handle_review(); } catch (RuntimeException $e) { $blocked = strpos($e->getMessage(),'nonce failure')!==false; }
$ok($blocked && in_array('ves_workbench_review',$GLOBALS['__nonce_checked'],true), 'review handler enforces the nonce');
$GLOBALS['__nonce_ok'] = true;

// A real decision through the handler: a fresh draft insight WITH evidence approves.
$r = VES_Source_Intake::process_signal_to_insight(['workspace_id'=>3,'signal_id'=>$chain_sig,'insight_type'=>'trend','title'=>'handler-review finding']);
$rev_ins = (int) $r['insight_id'];
$_POST = ['object_type'=>'insight','object_id'=>$rev_ins,'decision'=>'approve','workspace_id'=>3];
VES_Workbench::handle_review();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_notice=review_approved')!==false, 'handler approves through the audited lifecycle');
$ok((VES_Intelligence_Store::get_insight($rev_ins)['status'] ?? '') === 'approved', 'decision persisted');
// The evidence gate surfaces through the handler, never silently.
$bare2 = VES_Intelligence_Store::create_insight(['workspace_id'=>3,'insight_type'=>'other','title'=>'no evidence here','status'=>'draft']);
$_POST = ['object_type'=>'insight','object_id'=>(int)$bare2,'decision'=>'approve','workspace_id'=>3];
VES_Workbench::handle_review();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_err=ves_intel_evidence_required')!==false, 'evidence gate refusal carried back as the error code');
// Cross-workspace decision refused.
$_POST = ['object_type'=>'insight','object_id'=>$rev_ins,'decision'=>'reject','workspace_id'=>8];
VES_Workbench::handle_review();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_err=ves_workspace_mismatch')!==false, 'cross-workspace decision refused');
// Hostile decision value rejected as malformed.
$_POST = ['object_type'=>'insight','object_id'=>$rev_ins,'decision'=>'archive-everything','workspace_id'=>3];
VES_Workbench::handle_review();
$ok(strpos((string)end($GLOBALS['__redirects']),'fi_err=ves_workbench_bad_request')!==false, 'non-whitelisted decision is a bad request');
$_POST = [];

// ── 6. Reference-URL validator unit checks ────────────────────────────────────
$ok(VES_Source_Intake::valid_reference_url('https://example.com/a?b=1') === true, 'plain https accepted');
$ok(VES_Source_Intake::valid_reference_url('http://example.com') === true, 'plain http accepted');
foreach (['ftp://x/y','javascript:alert(1)','https://user:pw@example.com/','//example.com/x','https://'] as $bad) {
    $ok(VES_Source_Intake::valid_reference_url($bad) === false, "refused: {$bad}");
}
$ok(VES_Source_Intake::valid_reference_url('https://example.com/'.str_repeat('a',2100)) === false, 'over-long URL refused');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
