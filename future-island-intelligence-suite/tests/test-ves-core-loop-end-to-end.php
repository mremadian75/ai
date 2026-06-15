<?php
/**
 * Phase 2 — MANDATORY core-loop end-to-end test.
 *
 * Walks the REAL classes through the whole product loop in one workspace:
 *   1. manual text source            (VES_Source_Intake::process_source)
 *   2. URL-record source             (recorded, NEVER fetched — proven)
 *   3. signal from the source        (normalized + deduped + scored)
 *   4. evidence + DRAFT insight      (traceable promotion; presentation states)
 *   5. human review                  (real lifecycle matrix; evidence gate)
 *   6. brief from the APPROVED insight (evidence ids carry through; idempotent)
 *   7. draft preview = prompt package (AI disabled; provider execution false;
 *      NO draft entity is fabricated)
 *   8. memory candidate from the approved insight (status forced candidate)
 *   9. usage event in the ledger     (zero tokens => no fabricated cost)
 * …then a full traceability walk back from the brief to the source.
 *
 * Self-contained: WP shims + in-memory wpdb. No DB, no network (asserted!).
 *
 * Run: php tests/test-ves-core-loop-end-to-end.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public $data; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
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
// The loop must NEVER fetch anything — every HTTP entry point counts calls.
function wp_remote_get($u,$a=[]){$GLOBALS['__http_calls']++;return new WP_Error('forbidden','no network in loop test');}
function wp_remote_post($u,$a=[]){$GLOBALS['__http_calls']++;return new WP_Error('forbidden','no network in loop test');}
function wp_remote_request($u,$a=[]){$GLOBALS['__http_calls']++;return new WP_Error('forbidden','no network in loop test');}
$GLOBALS['__o']=[];$GLOBALS['__http_calls']=0;

class LoopWpdb {
    public $prefix='wp_'; public $insert_id=0; private $auto=0; public $data=[];
    public function get_charset_collate(){return '';}
    public function query($s){return true;}
    public function insert($t,$r,$f=null){$this->auto++;$r['id']=$this->auto;$this->data[$t][$this->auto]=$r;$this->insert_id=$this->auto;return 1;}
    public function update($t,$r,$w,$f=null,$wf=null){$id=(int)($w['id']??0);if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$r);return 1;}return 0;}
    public function prepare($sql,$args=[]){if(!is_array($args))$args=array_slice(func_get_args(),1);foreach($args as $a){$rep=is_int($a)?(string)$a:"'".addslashes((string)$a)."'";$sql=preg_replace('/%d|%s|%f/',$rep,$sql,1);}return $sql;}
    private function rows_matching($sql){
        if(!preg_match('/FROM (\S+)/',$sql,$m))return [null,[]];
        $table=$m[1];$rows=$this->data[$table]??[];
        $where=substr($sql,strpos($sql,'WHERE')!==false?strpos($sql,'WHERE')+5:strlen($sql));
        $where=preg_split('/ORDER BY| LIMIT/',$where)[0];
        preg_match_all("/(\w+)\s*=\s*'?([^'\s]+)'?/",$where,$mm,PREG_SET_ORDER);
        $conds=[];foreach($mm as $p){ if(ctype_digit($p[1]))continue; $conds[$p[1]]=$p[2]; }
        // Honor metadata LIKE patterns so the legacy dedupe path stays honest here too.
        preg_match_all("/metadata LIKE '([^']*)'/",$where,$lk);
        $out=[];foreach($rows as $r){
            $ok=true;
            foreach($conds as $k=>$v){if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}}
            if($ok && !empty($lk[1])){
                $any=false;
                foreach($lk[1] as $pat){$needle=trim(stripslashes($pat),'%');if($needle!=='' && strpos((string)($r['metadata']??''),$needle)!==false){$any=true;break;}}
                if(!$any){$ok=false;}
            }
            if($ok)$out[]=$r;
        }
        return [$table,$out];
    }
    public function get_var($sql){
        if(preg_match('/COUNT\(\*\)(?: AS \w+)? FROM (\S+)/',$sql,$m)){[$t,$rows]=$this->rows_matching($sql);return (string)count($rows);}
        if(preg_match('/SELECT (\w+) FROM/',$sql,$sm)){[$t,$rows]=$this->rows_matching($sql);return $rows?($rows[0][$sm[1]]??null):null;}
        return null;
    }
    public function get_row($sql,$o=null){[$t,$rows]=$this->rows_matching($sql);return $rows?$rows[0]:null;}
    public function get_results($sql,$o=null){[$t,$rows]=$this->rows_matching($sql);return array_reverse($rows);}
}
$GLOBALS['wpdb']=new LoopWpdb();

// Memory records: light capture stub — the real records layer is proven in the
// memory test files; HERE we prove the brand-context service wires candidates
// from the approved insight with forced candidate status + dedupe.
final class VES_Memory_Records {
    public static $saved = [];
    public static function save_record(array $args) { self::$saved[] = $args; return count(self::$saved); }
    public static function workspace_id_for_user($uid = 0) { return 3; }
}

$root = dirname(__DIR__);
require_once $root.'/includes/class-ves-ai-usage-tracker.php';
require_once $root.'/includes/class-ves-waterfall-sourcing.php';
require_once $root.'/includes/class-ves-intelligence-store.php';
require_once $root.'/includes/class-ves-workspace-guard.php';
require_once $root.'/includes/class-ves-review-decision-ledger.php'; // real transition matrix + append-only decisions
require_once $root.'/includes/class-ves-review-state.php';
require_once $root.'/includes/class-ves-insight-brief-builder.php';
require_once $root.'/includes/class-ves-generation-prompt-package-builder.php';
require_once $root.'/includes/class-ves-brand-context-service.php';
require_once $root.'/includes/class-ves-source-intake.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};
$WS = 3;

// ── Step 1 — manual text source ───────────────────────────────────────────────
$r = VES_Source_Intake::process_source(['workspace_id'=>$WS,'intake_type'=>'manual','source_title'=>'Lagoon residency interview','notes'=>'Operator notes from the artist interview. Strong interest in slow-craft formats.']);
$ok(is_array($r) && $r['source_id'] > 0, 'step 1: manual source created');
$src_manual = (int) $r['source_id'];
$r2 = VES_Source_Intake::process_source(['workspace_id'=>$WS,'intake_type'=>'manual','source_title'=>'Lagoon residency interview','notes'=>'Operator notes from the artist interview. Strong interest in slow-craft formats.']);
$ok(is_array($r2) && (int)$r2['source_id'] === $src_manual, 'step 1: identical manual intake dedupes to the SAME source (idempotent)');
$e = VES_Source_Intake::process_source(['workspace_id'=>$WS,'intake_type'=>'manual','source_title'=>'']);
$ok(is_wp_error($e) && $e->get_error_code()==='ves_intake_title_required', 'step 1: missing title refused with WP_Error');
$e = VES_Source_Intake::process_source(['workspace_id'=>0,'intake_type'=>'manual','source_title'=>'x']);
$ok(is_wp_error($e) && $e->get_error_code()==='ves_intake_workspace', 'step 1: workspace 0 refused');

// ── Step 2 — URL-record source (never fetched) ───────────────────────────────
$r = VES_Source_Intake::process_source(['workspace_id'=>$WS,'intake_type'=>'url','source_url'=>'https://island-culture.example/essays/slow-craft?utm_source=feed','source_title'=>'Slow-craft essay']);
$ok(is_array($r) && $r['source_id'] > 0 && $r['source_id'] !== $src_manual, 'step 2: URL source recorded as a distinct source');
$src_url = (int) $r['source_id'];
$row = VES_Intelligence_Store::get_source($src_url);
$ok(is_array($row) && ($row['metadata']['fetch_status'] ?? '') === 'not_fetched', 'step 2: source carries fetch_status=not_fetched provenance');
$ok($GLOBALS['__http_calls'] === 0, 'step 2: ZERO HTTP calls — the URL was recorded, never fetched');
foreach (['ftp://files.example/x', 'https://user:secret@example.com/a', 'javascript:alert(1)', 'not a url'] as $bad) {
    $e = VES_Source_Intake::process_source(['workspace_id'=>$WS,'intake_type'=>'url','source_url'=>$bad]);
    $ok(is_wp_error($e) && $e->get_error_code()==='ves_intake_bad_url', "step 2: unsafe reference refused ({$bad})");
}

// ── Step 3 — signal from the source ──────────────────────────────────────────
$r = VES_Source_Intake::process_signal(['workspace_id'=>$WS,'source_id'=>$src_url,'signal_type'=>'content_pattern','title'=>'Slow-craft content outperforms on saves','summary'=>'Essay reports 3× save rate for slow-craft posts.','value_text'=>'3x saves','occurred_at'=>'2026-06-10T14:30']);
$ok(is_array($r) && $r['signal_id'] > 0, 'step 3: signal created from the source');
$sig = (int) $r['signal_id'];
$r2 = VES_Source_Intake::process_signal(['workspace_id'=>$WS,'source_id'=>$src_url,'signal_type'=>'content_pattern','title'=>'Slow-craft content outperforms on saves','summary'=>'Essay reports 3× save rate for slow-craft posts.','value_text'=>'3x saves','occurred_at'=>'2026-06-10T14:30']);
$ok(is_array($r2) && (int)$r2['signal_id'] === $sig, 'step 3: identical signal dedupes to the SAME id');
$sig_row = VES_Intelligence_Store::get_signal($sig);
$ok((int)($sig_row['recurrence_count'] ?? 0) === 2, 'step 3: dedupe bumped recurrence_count to 2');
$ok((float)($sig_row['confidence_score'] ?? 0) > 0, 'step 3: deterministic quality scores filled (no LLM)');
$e = VES_Source_Intake::process_signal(['workspace_id'=>$WS,'source_id'=>999,'title'=>'x']);
$ok(is_wp_error($e) && $e->get_error_code()==='ves_intake_source_missing', 'step 3: unknown source refused');
$other = VES_Source_Intake::process_source(['workspace_id'=>9,'intake_type'=>'manual','source_title'=>'Other-tenant note','notes'=>'n']);
$e = VES_Source_Intake::process_signal(['workspace_id'=>$WS,'source_id'=>(int)$other['source_id'],'title'=>'cross-tenant probe']);
$ok(is_wp_error($e) && strpos((string)$e->get_error_code(),'workspace')!==false, 'step 3: cross-workspace source refused by the guard');

// ── Step 4 — evidence + DRAFT insight (presentation states) ──────────────────
$r = VES_Source_Intake::process_signal_to_insight(['workspace_id'=>$WS,'signal_id'=>$sig,'insight_type'=>'opportunity','opportunity_score'=>250,'title'=>'Slow-craft series is an opportunity','summary'=>'Audience saves indicate appetite for a slow-craft series.','recommendation'=>'Stage a 3-part slow-craft series brief.']);
$ok(is_array($r) && $r['evidence_id'] > 0 && $r['insight_id'] > 0, 'step 4: evidence + draft insight created');
$evid = (int) $r['evidence_id']; $ins = (int) $r['insight_id'];
$ins_row = VES_Intelligence_Store::get_insight($ins);
$ok(($ins_row['status'] ?? '') === 'draft', 'step 4: promotion NEVER skips review — insight starts as draft');
$ok(($ins_row['insight_type'] ?? '') === 'opportunity', 'step 4: opportunity is an insight TYPE, not a separate entity');
$ok((int)($ins_row['metadata']['opportunity_score'] ?? -1) === 100, 'step 4: opportunity score bounded to 100');
$ok(in_array($evid, array_map('intval', (array)($ins_row['evidence_ids'] ?? [])), true), 'step 4: insight links the evidence id');
$ok(in_array($sig, array_map('intval', (array)($ins_row['signal_ids'] ?? [])), true), 'step 4: insight links the signal id');
$ev_row = VES_Intelligence_Store::get_evidence($evid);
$ok((int)($ev_row['signal_id'] ?? 0) === $sig && (int)($ev_row['source_id'] ?? 0) === $src_url, 'step 4: evidence carries signal + source ids (traceable)');
$ok(VES_Intelligence_Store::insight_presentation_state($ins_row) === 'ready_for_review', 'step 4: evidence-backed draft presents as ready_for_review');
$bare = VES_Intelligence_Store::create_insight(['workspace_id'=>$WS,'insight_type'=>'other','title'=>'Evidence-less note','status'=>'draft']);
$bare_row = VES_Intelligence_Store::get_insight((int)$bare);
$ok(VES_Intelligence_Store::insight_presentation_state($bare_row) === 'needs_evidence', 'step 4: evidence-less draft presents as needs_evidence');
$ok(VES_Intelligence_Store::insight_presentation_state(['status'=>'approved']) === 'approved' && VES_Intelligence_Store::insight_presentation_state(['status'=>'rejected']) === 'rejected', 'step 4: terminal/approved states pass through');
$ok(VES_Intelligence_Store::sanitize_insight_type('weird-stuff') === 'other', 'step 4: unknown insight type sanitized to other');

// ── Step 5 — human review through the REAL lifecycle ─────────────────────────
$e = VES_Source_Intake::process_insight_to_brief(['workspace_id'=>$WS,'insight_id'=>$ins]);
$ok(is_wp_error($e) && $e->get_error_code()==='ves_intake_insight_not_approved', 'step 5: a brief from an UNAPPROVED insight is refused');
$res = VES_Intelligence_Store::update_insight_status($ins, 'approved', ['reviewed_by'=>'operator-7']);
$ok(!is_wp_error($res), 'step 5: evidence-backed insight approves through the real matrix');
$ok((VES_Intelligence_Store::get_insight($ins)['status'] ?? '') === 'approved', 'step 5: insight persisted as approved');
$res = VES_Intelligence_Store::update_insight_status((int)$bare, 'approved');
$ok(is_wp_error($res), 'step 5: the evidence GATE still blocks approving the evidence-less insight');

// ── Step 6 — brief from the approved insight ─────────────────────────────────
$r = VES_Source_Intake::process_insight_to_brief(['workspace_id'=>$WS,'insight_id'=>$ins]);
$ok(is_array($r) && $r['brief_id'] > 0, 'step 6: brief created from the approved insight');
$brief = (int) $r['brief_id'];
$b_row = VES_Intelligence_Store::get_brief($brief);
$ok((int)($b_row['insight_id'] ?? 0) === $ins, 'step 6: brief.insight_id column carries the source insight (first-class traceability)');
$ok((int)(($b_row['metadata']['source_insight_id'] ?? 0)) === $ins, 'step 6: brief metadata keeps source_insight_id');
$ok(array_map('intval',(array)($b_row['evidence_ids'] ?? [])) === [$evid], 'step 6: evidence ids carried through to the brief');
$ok(($b_row['status'] ?? '') === 'draft', 'step 6: the brief itself starts in draft (its own review follows)');
$r2 = VES_Source_Intake::process_insight_to_brief(['workspace_id'=>$WS,'insight_id'=>$ins]);
$ok(is_array($r2) && (int)$r2['brief_id'] === $brief, 'step 6: repeating the action returns the SAME brief (idempotent)');

// ── Step 7 — draft preview = prompt package; nothing fabricated ──────────────
$res = VES_Intelligence_Store::update_brief_status($brief, 'approved');
if (is_wp_error($res)) { VES_Intelligence_Store::update_brief_status($brief, 'reviewed'); $res = VES_Intelligence_Store::update_brief_status($brief, 'approved'); }
$ok(!is_wp_error($res), 'step 7: brief approved for draft staging');
$pkg = VES_Generation_Prompt_Package_Builder::build(['workspace_id'=>$WS,'use_case'=>'draft_generation','target_type'=>'brief','target_id'=>$brief]);
$ok(is_array($pkg) && ($pkg['build_status'] ?? '') === 'ready', 'step 7: prompt package builds (status ready) — this IS the draft preview');
$ok(($pkg['safety']['provider_execution_allowed'] ?? null) === false, 'step 7: provider execution stays FORBIDDEN (AI disabled)');
$ok(count(VES_Intelligence_Store::list_drafts(['workspace_id'=>$WS])) === 0, 'step 7: NO draft entity was fabricated — no fake generated content anywhere');
$ok($GLOBALS['__http_calls'] === 0, 'step 7: still zero HTTP calls — no provider was contacted');

// ── Step 8 — memory candidate from the approved insight ──────────────────────
$mem_id = VES_Brand_Context_Service::create_candidate($WS, [
    'record_type' => 'review_learning',
    'summary' => 'Slow-craft series approved as an opportunity; audience saves are the leading indicator.',
    'source_target_type' => 'insight', 'source_target_id' => $ins, 'importance_score' => 60,
], 7);
$ok($mem_id > 0 && count(VES_Memory_Records::$saved) === 1, 'step 8: memory candidate proposed from the approved insight');
$saved = VES_Memory_Records::$saved[0];
$ok(in_array('candidate', (array)($saved['tags'] ?? []), true) && ($saved['content']['brand_context']['status'] ?? '') === 'candidate', 'step 8: candidate status is FORCED — nothing enters trusted context without approval');
$ok(!empty($saved['dedupe']) && (int)($saved['workspace_id'] ?? 0) === $WS && (string)($saved['source_id'] ?? '') === (string)$ins, 'step 8: candidate dedupes and stays traceable to the insight');

// ── Step 9 — usage event in the ledger ───────────────────────────────────────
$uid = VES_AI_Usage_Tracker::record(['provider'=>'none','model'=>'','status'=>'completed','module'=>'core_loop','operation_type'=>'prompt_preview','workspace_id'=>$WS,'input_tokens'=>0,'output_tokens'=>0]);
$ok($uid > 0, 'step 9: usage event recorded in the ledger');
$usage_row = null;
foreach ($GLOBALS['wpdb']->data as $table => $rows) {
    foreach ($rows as $row) { if (($row['operation_type'] ?? '') === 'prompt_preview') { $usage_row = $row; } }
}
$ok(is_array($usage_row) && (int)$usage_row['workspace_id'] === $WS, 'step 9: event row carries the workspace');
$ok((float)($usage_row['cost_usd'] ?? 0) == 0.0, 'step 9: zero-token preview fabricates NO cost');

// ── Phase 3 — workbench decision card reflects REAL presentation states ──────
require_once $root.'/includes/class-ves-workbench.php';
$wb = VES_Workbench::render_brief(['workspace_id'=>$WS,'insight_id'=>(int)$bare]);
$ok(strpos($wb,'No evidence is linked')!==false && strpos($wb,'Next:')!==false, 'decision card: evidence-less insight shows needs_evidence with a next action');
$wb2 = VES_Workbench::render_brief(['workspace_id'=>$WS,'insight_id'=>$ins]);
$ok(strpos($wb2,'Human-approved. A brief can be built')!==false, 'decision card: approved insight names the brief-building next step');
$wb3 = VES_Workbench::render_draft(['workspace_id'=>$WS,'brief_id'=>$brief]);
$ok(strpos($wb3,'prompt-package preview only')!==false, 'decision card: approved brief explains draft staging is preview-only (AI disabled)');

// ── Traceability walk: brief → insight → evidence → signal → source ──────────
$walk_ins = VES_Intelligence_Store::get_insight((int) $b_row['insight_id']);
$walk_ev  = VES_Intelligence_Store::get_evidence((int) ((array)$walk_ins['evidence_ids'])[0]);
$walk_sig = VES_Intelligence_Store::get_signal((int) $walk_ev['signal_id']);
$walk_src = VES_Intelligence_Store::get_source((int) $walk_sig['source_id']);
$ok((int)$walk_src['id'] === $src_url && strpos((string)$walk_src['source_url'], 'island-culture.example') !== false, 'traceability: brief walks back to the exact recorded source URL');
$ok($GLOBALS['__http_calls'] === 0, 'final: the ENTIRE loop ran with zero HTTP calls');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
