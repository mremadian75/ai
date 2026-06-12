<?php
/**
 * Phase 6A — Brand Context (Memory V2) trust-boundary tests.
 * Pure logic (no DB) for the trust/ranking/limits/candidate rules + a small
 * MockWpdb integration for create_candidate/set_status. No LLM, no network.
 *
 * Run: php tests/test-ves-brand-context-service.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/fiis_bc_' . getmypid() . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d,$o=0){ return json_encode($d,$o); } }
if (!function_exists('current_time')) { function current_time($t='mysql',$g=0){ return '2026-06-08 12:00:00'; } }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }

require_once dirname(__DIR__) . '/includes/class-ves-brand-context-service.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };
$GLOBALS['ves_brand_context_now'] = strtotime('2026-06-08 12:00:00');

function bc_row($id, $type, $status, $opts = []) {
    $content = ['brand_context' => ['status' => $status]];
    return array_merge([
        'id' => $id, 'workspace_id' => 5, 'memory_type' => $type, 'summary' => 'rule ' . $id,
        'importance_score' => 50, 'is_pinned' => 0, 'expires_at' => null,
        'content_json' => json_encode($content), 'source_type' => 'draft', 'source_id' => $id,
        'updated_at' => '2026-06-01 00:00:00', 'created_at' => '2026-06-01 00:00:00',
    ], $opts);
}

// ── J1: type + status validation ─────────────────────────────────────────────
$ok(VES_Brand_Context_Service::is_brand_context_type('voice_rule') && !VES_Brand_Context_Service::is_brand_context_type('nope'), 'J1: brand-context type validation');
$ok(VES_Brand_Context_Service::is_valid_status('candidate') && !VES_Brand_Context_Service::is_valid_status('trusted'), 'J1: status validation');

// ── J2/J3/J4: candidate not trusted; active/pinned trusted; rejected/expired/archived excluded ─
$rows = [
    bc_row(1, 'voice_rule', 'active'),
    bc_row(2, 'voice_rule', 'candidate'),
    bc_row(3, 'preferred_term', 'rejected'),
    bc_row(4, 'brand_fact', 'archived'),
    bc_row(5, 'voice_rule', 'active', ['is_pinned' => 1]),
    bc_row(6, 'brand_fact', 'active', ['expires_at' => '2026-01-01 00:00:00']), // expired
];
$pkg = VES_Brand_Context_Service::build_package_from_rows($rows, ['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ids = array_map(function ($i) { return $i['memory_id']; }, $pkg['items']);
$ok(in_array(1, $ids, true) && in_array(5, $ids, true), 'J3: active + pinned memory retrieved');
$ok(!in_array(2, $ids, true), 'J2: candidate memory NOT retrieved as trusted context');
$ok(!in_array(3, $ids, true) && !in_array(4, $ids, true) && !in_array(6, $ids, true), 'J4: rejected/archived/expired excluded');
$ok($pkg['excluded']['untrusted'] >= 1 && $pkg['excluded']['rejected'] === 1 && $pkg['excluded']['expired'] === 1, 'J4: exclusions counted honestly');

// ── J5: pinned sorts before non-pinned ───────────────────────────────────────
$ok($pkg['items'][0]['memory_id'] === 5, 'J5: pinned memory sorts first');

// ── J6: importance + recency sorting ─────────────────────────────────────────
$srt = [
    bc_row(10, 'brand_fact', 'active', ['importance_score' => 40, 'updated_at' => '2026-06-02 00:00:00']),
    bc_row(11, 'brand_fact', 'active', ['importance_score' => 90, 'updated_at' => '2026-06-01 00:00:00']),
    bc_row(12, 'brand_fact', 'active', ['importance_score' => 90, 'updated_at' => '2026-06-05 00:00:00']),
];
$p2 = VES_Brand_Context_Service::build_package_from_rows($srt, ['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ok($p2['items'][0]['memory_id'] === 12 && $p2['items'][1]['memory_id'] === 11 && $p2['items'][2]['memory_id'] === 10, 'J6: importance desc then recency desc');

// ── J7: max_items + max_chars limits ─────────────────────────────────────────
$many = []; for ($i = 1; $i <= 20; $i++) { $many[] = bc_row(100 + $i, 'brand_fact', 'active', ['summary' => str_repeat('x', 100)]); }
$pLim = VES_Brand_Context_Service::build_package_from_rows($many, ['workspace_id' => 5, 'use_case' => 'brief_generation', 'max_items' => 5, 'max_chars' => 1000]);
$ok($pLim['item_count'] <= 5, 'J7: max_items hard limit respected');
$ok($pLim['char_count'] <= 1000 && $pLim['excluded']['over_limit'] >= 1, 'J7: max_chars hard limit respected');

// ── J8: honest empty ─────────────────────────────────────────────────────────
$pEmpty = VES_Brand_Context_Service::build_package_from_rows([], ['workspace_id' => 5, 'use_case' => 'draft_generation']);
$ok($pEmpty['item_count'] === 0 && $pEmpty['items'] === [] && strpos($pEmpty['note'], 'No trusted brand context') !== false, 'J8: empty package is honest');

// ── use-case filtering: a tone rule is excluded from insight_generation ───────
$ucRows = [bc_row(30, 'voice_rule', 'active'), bc_row(31, 'brand_fact', 'active')];
$pIns = VES_Brand_Context_Service::build_package_from_rows($ucRows, ['workspace_id' => 5, 'use_case' => 'insight_generation']);
$insIds = array_map(function ($i) { return $i['memory_id']; }, $pIns['items']);
$ok(in_array(31, $insIds, true) && !in_array(30, $insIds, true), 'use-case: voice_rule excluded from insight_generation; brand_fact kept');

// ── J9/J10/J11/J12: candidate proposers ──────────────────────────────────────
$ok(VES_Brand_Context_Service::propose_from_insight(['status' => 'approved', 'id' => 7, 'summary' => 'Hook works']) !== null, 'J9: approved insight proposes candidate');
$ok(VES_Brand_Context_Service::propose_from_insight(['status' => 'proposed', 'id' => 8, 'summary' => 'x']) === null, 'J12: unapproved insight proposes NOTHING');
$ok(VES_Brand_Context_Service::propose_from_brief(['status' => 'approved', 'id' => 9, 'positioning' => 'Premium local']) !== null, 'J10: approved brief proposes candidate');
$rejDraft = VES_Brand_Context_Service::propose_from_draft(['status' => 'rejected', 'id' => 10, 'notes' => 'Avoid overclaiming results']);
$ok($rejDraft !== null && in_array($rejDraft['record_type'], ['review_learning', 'forbidden_claim'], true), 'J11: rejected draft w/ notes -> review_learning/forbidden_claim candidate');
$ok(VES_Brand_Context_Service::propose_from_draft(['status' => 'rejected', 'id' => 11]) === null, 'J11: rejected draft WITHOUT notes proposes nothing');
$allProposals = [VES_Brand_Context_Service::propose_from_insight(['status' => 'approved', 'id' => 7, 'summary' => 's']), $rejDraft];
$badStatus = true; foreach ($allProposals as $pr) { if ($pr && $pr['status'] !== 'candidate') { $badStatus = false; } }
$ok($badStatus, 'J9-J11: every proposal is status=candidate (AI never auto-trusts)');

// ── J16: no raw prompt/response stored ───────────────────────────────────────
$scrub = VES_Brand_Context_Service::scrub_payload(['prompt' => 'secret', 'raw_response' => 'blob', 'api_key' => 'sk-x', 'note' => 'safe', 'status' => 'candidate']);
$ok(!isset($scrub['prompt']) && !isset($scrub['raw_response']) && !isset($scrub['api_key']) && isset($scrub['note']), 'J16: scrub drops prompt/raw_response/api_key, keeps safe keys');

// ── J14: admin render is escaped + warns candidates are not used ──────────────
$html = VES_Brand_Context_Service::build_package_from_rows([bc_row(40, 'voice_rule', 'active', ['summary' => '<script>x</script>'])], ['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ok($html['items'][0]['summary'] === '<script>x</script>', 'data kept raw in model (escaping happens at render)');

// ── Integration: create_candidate + set_status via MockWpdb ──────────────────
class BC_MockWpdb {
    public $prefix='wp_'; public $insert_id=0; public $data=['wp_ves_memory_records'=>[]]; private $auto=0;
    public function get_charset_collate(){ return ''; }
    public function prepare($sql,$a=[]){ if(!is_array($a)){$a=array_slice(func_get_args(),1);} foreach($a as $x){$r=is_int($x)?(string)$x:"'".addslashes((string)$x)."'";$sql=preg_replace('/%d|%s|%f/',$r,$sql,1);} return $sql; }
    public function query($s){ return true; }
    public function get_results($sql,$o=null){ $rows=array_values($this->data['wp_ves_memory_records']); if(preg_match('/workspace_id = (\d+)/',$sql,$m)){$rows=array_values(array_filter($rows,function($r)use($m){return (int)$r['workspace_id']===(int)$m[1];}));} return $rows; }
    public function get_row($sql,$o=null){ if(preg_match('/id = (\d+)/',$sql,$m)){ return isset($this->data['wp_ves_memory_records'][(int)$m[1]])?$this->data['wp_ves_memory_records'][(int)$m[1]]:null;} return null; }
    public function get_var($s){ return null; }
    public function get_col($s,$i=0){ return ['id','workspace_id','user_id','project_id','brand_name','memory_type','title','summary','content_json','source_type','source_id','tags_json','importance_score','expires_at','is_pinned','created_at','updated_at']; }
    public function insert($t,$row,$f=null){ $this->auto++; $row['id']=$this->auto; $this->data[$t][$this->auto]=$row; $this->insert_id=$this->auto; return 1; }
    public function update($t,$row,$w,$f=null,$wf=null){ $id=(int)($w['id']??0); if(isset($this->data[$t][$id])){$this->data[$t][$id]=array_merge($this->data[$t][$id],$row);return 1;} return 0; }
}
$mockable = true;
if (!class_exists('VES_Memory_Records')) {
    // Provide a tiny stand-in so the service's create_candidate/set_status can run.
    eval('class VES_Memory_Records { public static function table_name(){return "wp_ves_memory_records";} public static function save_record($a){ global $wpdb; $row=["workspace_id"=>(int)($a["workspace_id"]??0),"user_id"=>(int)($a["user_id"]??0),"memory_type"=>(string)($a["memory_type"]??""),"title"=>(string)($a["title"]??""),"summary"=>(string)($a["summary"]??""),"content_json"=>wp_json_encode($a["content"]??[]),"source_type"=>(string)($a["source_type"]??""),"source_id"=>(string)($a["source_id"]??""),"importance_score"=>(int)($a["importance_score"]??50),"is_pinned"=>0,"expires_at"=>null,"updated_at"=>"2026-06-08 12:00:00","created_at"=>"2026-06-08 12:00:00"]; $wpdb->insert("wp_ves_memory_records",$row); return $wpdb->insert_id; } }');
}
$GLOBALS['wpdb'] = new BC_MockWpdb();
$cid = VES_Brand_Context_Service::create_candidate(5, ['record_type' => 'voice_rule', 'summary' => 'Speak plainly', 'source_target_type' => 'draft', 'source_target_id' => 3], 1);
$ok($cid > 0, 'integration: create_candidate persists a row');
$loaded = VES_Brand_Context_Service::load_rows(5);
$ok(count($loaded) === 1 && VES_Brand_Context_Service::status_of($loaded[0]) === 'candidate', 'integration: created row is a candidate (not trusted)');
$pkgC = VES_Brand_Context_Service::build_context_package(['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ok($pkgC['item_count'] === 0, 'J17: freshly created candidate is NOT used in brief/draft context');
VES_Brand_Context_Service::set_status($cid, 'active', 1);
$pkgA = VES_Brand_Context_Service::build_context_package(['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ok($pkgA['item_count'] === 1, 'integration: after operator approval -> appears in trusted context');
$ok(VES_Brand_Context_Service::create_candidate(5, ['record_type' => 'not_a_type', 'summary' => 'x']) === 0, 'guard: invalid record_type rejected');

// ── Source contracts: security, CLI dry-run, no secrets, 7.4 ─────────────────
$root = dirname(__DIR__);
$admin = (string) @file_get_contents($root . '/includes/class-ves-admin.php');
$cli   = (string) @file_get_contents($root . '/includes/class-ves-cli-brand-context.php');
$svc   = (string) @file_get_contents($root . '/includes/class-ves-brand-context-service.php');

// J13: admin actions require nonce + capability (handler-level), gated to brand_ctx_*.
$ok(strpos($admin, "check_admin_referer('ves_memory_admin_action')") !== false, 'J13: memory handler verifies nonce');
$ok(strpos($admin, "current_user_can('manage_options')") !== false, 'J13: memory handler checks capability');
$ok(strpos($admin, "'brand_ctx_approve'") !== false && strpos($admin, 'VES_Brand_Context_Service::set_status') !== false, 'J13: brand_ctx actions routed to set_status (human-gated)');
// candidate review form carries a nonce field
$ok(strpos($admin, "wp_nonce_field('ves_memory_admin_action')") !== false, 'J13: candidate review form emits a nonce field');

// CLI: dry-run by default; --apply required to write; read-only summary/preview.
$ok(strpos($cli, "\$apply = !empty(\$assoc['apply'])") !== false, 'I: memory-expire reads --apply (dry-run otherwise)');
$ok(strpos($cli, "Dry-run complete (no writes)") !== false, 'I: memory-expire is dry-run by default');
$ok(strpos($cli, 'expire_stale($ws, $apply)') !== false, 'I: expiry only writes when --apply is passed');

// No auto-approval anywhere in the brand context surface.
$ok(stripos($svc, 'auto_approve') === false && stripos($cli, 'auto_approve') === false, 'safety: no auto-approval path');

// J16/K: no hardcoded secret VALUE in the service (the scrubber denylist + the
// redaction patterns legitimately mention key NAMES — those are allowed config).
$ok(!preg_match('/sk-[A-Za-z0-9]{16,}|AIza[A-Za-z0-9]{16,}|Bearer\s+[A-Za-z0-9]{16,}/', $svc), 'K: no hardcoded secret value in service');
$ok(strpos($svc, 'OPENAI_API_KEY') === false, "K: no OPENAI_API_KEY reference in service");

// J15: PHP 7.4 — no 8-only syntax in the new files.
$ok(!preg_match('/\bmatch\s*\(|\bstr_contains\(|\bstr_starts_with\(|\bstr_ends_with\(|\?->/', $svc . $cli), 'J15: brand context files are PHP 7.4-clean');

// ════════════ Phase 6A CORRECTIVE — trust-boundary hardening ════════════════

// ── Issue 1: explicit non-trusted status overrides pin ───────────────────────
$pin = ['is_pinned' => 1];
$ok(VES_Brand_Context_Service::status_of(bc_row(200, 'voice_rule', 'rejected', $pin)) === 'rejected', 'C1: pinned + rejected => not trusted (rejected)');
$ok(VES_Brand_Context_Service::status_of(bc_row(201, 'voice_rule', 'archived', $pin)) === 'archived', 'C1: pinned + archived => not trusted (archived)');
$ok(VES_Brand_Context_Service::status_of(bc_row(202, 'voice_rule', 'candidate', $pin)) === 'candidate', 'C1: pinned + candidate => not trusted (candidate)');
$ok(VES_Brand_Context_Service::status_of(bc_row(203, 'voice_rule', 'active', array_merge($pin, ['expires_at' => '2026-01-01 00:00:00']))) === 'expired', 'C1: pinned + expired => not trusted (expired, conservative)');
$c1rows = [bc_row(204, 'voice_rule', 'rejected', $pin), bc_row(205, 'brand_fact', 'archived', $pin)];
$c1pkg = VES_Brand_Context_Service::build_package_from_rows($c1rows, ['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ok($c1pkg['item_count'] === 0, 'C1: pinned rejected/archived rows excluded from context package');
$ok(!VES_Brand_Context_Service::is_trusted(bc_row(206, 'voice_rule', 'rejected', $pin)), 'C1: is_trusted=false for pinned+rejected');

// ── Issue 2: missing/invalid status => untrusted (NOT active) ─────────────────
$noStatus = bc_row(210, 'voice_rule', 'active'); $noStatus['content_json'] = json_encode(['other' => 1]); // no brand_context.status
$ok(VES_Brand_Context_Service::status_of($noStatus) === 'candidate', 'C2: brand row with no brand_context.status => candidate (untrusted)');
$ok(VES_Brand_Context_Service::stored_status_of($noStatus) === '', 'C2: stored_status_of returns empty for missing status');
$badStatusRow = bc_row(211, 'brand_fact', 'active'); $badStatusRow['content_json'] = json_encode(['brand_context' => ['status' => 'trusted_lol']]);
$ok(VES_Brand_Context_Service::status_of($badStatusRow) === 'candidate', 'C2: invalid status => candidate (untrusted)');
$c2pkg = VES_Brand_Context_Service::build_package_from_rows([$noStatus, $badStatusRow], ['workspace_id' => 5, 'use_case' => 'brief_generation']);
$ok($c2pkg['item_count'] === 0 && $c2pkg['excluded']['untrusted'] === 2, 'C2: missing/invalid-status rows excluded from context package');

// ── Issue 3: expire_stale eligibility (needs DB rows) ────────────────────────
$GLOBALS['wpdb'] = new BC_MockWpdb();
$expActive = (int) VES_Memory_Records::save_record(['workspace_id' => 9, 'memory_type' => 'brand_fact', 'summary' => 'stale', 'content' => ['brand_context' => ['status' => 'active']]]);
$GLOBALS['wpdb']->data['wp_ves_memory_records'][$expActive]['expires_at'] = '2026-01-01 00:00:00'; // past
$dry = VES_Brand_Context_Service::expire_stale(9, false);
$ok($dry['eligible'] === 1 && $dry['dry_run'] === true && $dry['applied'] === 0, 'C3: expired active row => dry-run eligible=1, no writes');
$applyR = VES_Brand_Context_Service::expire_stale(9, true);
$ok($applyR['applied'] === 1, 'C3: apply marks the expired row');
$ok(VES_Brand_Context_Service::stored_status_of($GLOBALS['wpdb']->data['wp_ves_memory_records'][$expActive]) === 'expired', 'C3: stored status is now expired');
$ok(VES_Brand_Context_Service::expire_stale(9, false)['eligible'] === 0, 'C3: already-expired row => not eligible again');
// pinned expired + rejected expired are not eligible
$GLOBALS['wpdb'] = new BC_MockWpdb();
$pinnedExp = (int) VES_Memory_Records::save_record(['workspace_id' => 9, 'memory_type' => 'brand_fact', 'summary' => 'p', 'content' => ['brand_context' => ['status' => 'pinned']]]);
$GLOBALS['wpdb']->data['wp_ves_memory_records'][$pinnedExp]['is_pinned'] = 1;
$GLOBALS['wpdb']->data['wp_ves_memory_records'][$pinnedExp]['expires_at'] = '2026-01-01 00:00:00';
$rejExp = (int) VES_Memory_Records::save_record(['workspace_id' => 9, 'memory_type' => 'brand_fact', 'summary' => 'r', 'content' => ['brand_context' => ['status' => 'rejected']]]);
$GLOBALS['wpdb']->data['wp_ves_memory_records'][$rejExp]['expires_at'] = '2026-01-01 00:00:00';
$ok(VES_Brand_Context_Service::expire_stale(9, false)['eligible'] === 0, 'C3: pinned-expired and rejected-expired rows => not eligible');

// ── Issue 4: set_status cannot mutate non-brand-context memory ───────────────
$GLOBALS['wpdb'] = new BC_MockWpdb();
$nonBrand = (int) VES_Memory_Records::save_record(['workspace_id' => 9, 'memory_type' => 'research_summary', 'summary' => 'x', 'content' => []]);
$ok(VES_Brand_Context_Service::set_status($nonBrand, 'active', 1) === false, 'C4: set_status on non-brand memory returns false');
$ok((int) ($GLOBALS['wpdb']->data['wp_ves_memory_records'][$nonBrand]['is_pinned'] ?? 0) === 0, 'C4: non-brand memory not pinned by brand action');
$brandRow = (int) VES_Brand_Context_Service::create_candidate(9, ['record_type' => 'voice_rule', 'summary' => 'ok']);
$ok(VES_Brand_Context_Service::set_status($brandRow, 'active', 1, 999) === false, 'C4: wrong workspace_id rejected');
$ok(VES_Brand_Context_Service::set_status($brandRow, 'active', 1, 9) === true, 'C4: correct workspace_id accepted');
$ok((int) $GLOBALS['wpdb']->data['wp_ves_memory_records'][$brandRow]['is_pinned'] === 0, 'C4: active clears pin');
VES_Brand_Context_Service::set_status($brandRow, 'pinned', 1, 9);
$ok((int) $GLOBALS['wpdb']->data['wp_ves_memory_records'][$brandRow]['is_pinned'] === 1, 'C4: pinned sets is_pinned=1');
VES_Brand_Context_Service::set_status($brandRow, 'rejected', 1, 9);
$ok((int) $GLOBALS['wpdb']->data['wp_ves_memory_records'][$brandRow]['is_pinned'] === 0, 'C1/C4: rejecting a pinned row clears the pin');

// ── Issue 6: free-text secret redaction ──────────────────────────────────────
$ok(strpos(VES_Brand_Context_Service::redact_sensitive_text('use sk-ABCDEFGH12345678 now'), 'sk-ABCDEFGH') === false, 'C6: sk- token redacted');
$ok(strpos(VES_Brand_Context_Service::redact_sensitive_text('Bearer ABCDEFGH12345678xx'), 'ABCDEFGH12345678') === false, 'C6: Bearer token redacted');
$ok(strpos(VES_Brand_Context_Service::redact_sensitive_text('api_key=SECRETVALUE123'), 'SECRETVALUE123') === false, 'C6: api_key=value redacted');
$ok(VES_Brand_Context_Service::redact_sensitive_text('Speak in a warm, concise brand voice.') === 'Speak in a warm, concise brand voice.', 'C6: safe text unchanged');
// applied on the write path: a candidate summary with a secret is stored redacted
$GLOBALS['wpdb'] = new BC_MockWpdb();
$leakId = (int) VES_Brand_Context_Service::create_candidate(9, ['record_type' => 'voice_rule', 'summary' => 'token sk-LEAKEDSECRET1234 in voice']);
$stored = $GLOBALS['wpdb']->data['wp_ves_memory_records'][$leakId]['summary'];
$ok(strpos($stored, 'sk-LEAKEDSECRET1234') === false && strpos($stored, '[redacted]') !== false, 'C6: candidate summary stored with secret redacted');

echo "\nBrand Context service: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
