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

$root = dirname(__DIR__);
require_once $root.'/includes/class-ves-ai-usage-tracker.php';
require_once $root.'/includes/class-ves-waterfall-sourcing.php';
require_once $root.'/includes/class-ves-intelligence-store.php';
require_once $root.'/includes/class-ves-review-state.php';
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

// ── 6. Reference-URL validator unit checks ────────────────────────────────────
$ok(VES_Source_Intake::valid_reference_url('https://example.com/a?b=1') === true, 'plain https accepted');
$ok(VES_Source_Intake::valid_reference_url('http://example.com') === true, 'plain http accepted');
foreach (['ftp://x/y','javascript:alert(1)','https://user:pw@example.com/','//example.com/x','https://'] as $bad) {
    $ok(VES_Source_Intake::valid_reference_url($bad) === false, "refused: {$bad}");
}
$ok(VES_Source_Intake::valid_reference_url('https://example.com/'.str_repeat('a',2100)) === false, 'over-long URL refused');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
