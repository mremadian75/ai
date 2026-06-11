<?php
/**
 * Phase 9B.3 — release evidence pack integrity contract (real class).
 *
 * Proves:
 *  1. schema validation catches missing fields / missing command outputs /
 *     non-zero exit codes
 *  2. the evidence hash is deterministic (key order independent) and any
 *     tamper invalidates verification
 *  3. build() computes validation_status (passed only with all outputs + operator)
 *  4. record_live_validation refuses invalid/incomplete/blocked packs and
 *     stores the hash-backed state on success
 *  5. a manual option without a pack is classified unverified_manual
 *  6. recording NEVER yields a production-ready state
 *
 * Run: php tests/test-ves-evidence-pack-9b.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.2.6'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc2'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return '2026-06-14 12:00:00';}
function get_current_user_id(){return 4;}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
$GLOBALS['__o']=[];

require_once dirname(__DIR__) . '/includes/class-ves-rc-evidence-pack.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

/** A fully valid command_outputs map. */
$outputs = [];
foreach (VES_RC_Evidence_Pack::REQUIRED_COMMANDS as $cmd) {
    $outputs[$cmd] = ['exit_code' => 0, 'output_sha256' => hash('sha256', 'out:' . $cmd)];
}

// ── 1. build() computes status honestly ─────────────────────────────────────
$incomplete = VES_RC_Evidence_Pack::build(['operator_name' => 'Ops']);
$ok($incomplete['validation_status'] === 'incomplete', 'pack without command outputs is incomplete');
$full = VES_RC_Evidence_Pack::build(['operator_name' => 'Ops', 'command_outputs' => $outputs, 'build_sha256' => hash('sha256', 'zip')]);
$ok($full['validation_status'] === 'passed', 'pack with every required output + operator computes passed');
$anon = VES_RC_Evidence_Pack::build(['command_outputs' => $outputs, 'build_sha256' => hash('sha256', 'zip')]);
$ok($anon['validation_status'] === 'incomplete', 'pack without an operator cannot be passed');

// ── 2. Schema validation ─────────────────────────────────────────────────────
$v = VES_RC_Evidence_Pack::schema_validate($full);
$ok($v['valid'] === true, 'full pack passes schema validation');
$broken = $full; unset($broken['command_outputs']['wp ves verify-schema']);
$v = VES_RC_Evidence_Pack::schema_validate($broken);
$ok($v['valid'] === false && strpos(implode(' ', $v['errors']), 'verify-schema') !== false, 'missing required command output fails schema');
$nonzero = $full; $nonzero['command_outputs']['php -v']['exit_code'] = 1;
$v = VES_RC_Evidence_Pack::schema_validate($nonzero);
$ok($v['valid'] === false, 'non-zero exit code on a required command fails schema');
$nofield = $full; unset($nofield['operator']);
$v = VES_RC_Evidence_Pack::schema_validate($nofield);
$ok($v['valid'] === false, 'missing operator fails schema');

// ── 3. Deterministic hash + tamper detection ─────────────────────────────────
$h1 = VES_RC_Evidence_Pack::compute_hash($full);
$shuffled = array_reverse($full, true);
$h2 = VES_RC_Evidence_Pack::compute_hash($shuffled);
$ok($h1 === $h2 && preg_match('/^[a-f0-9]{64}$/', $h1), 'hash is deterministic regardless of key order');
$v = VES_RC_Evidence_Pack::verify($full);
$ok($v['valid'] === true, 'untampered pack verifies');
$tampered = $full; $tampered['siteurl'] = 'https://evil.example.com';
$v = VES_RC_Evidence_Pack::verify($tampered);
$ok($v['valid'] === false && strpos(implode(' ', $v['errors']), 'mismatch') !== false, 'tampered pack fails hash verification');

// ── 4. record_live_validation gates ──────────────────────────────────────────
$res = VES_RC_Evidence_Pack::record_live_validation($tampered);
$ok(is_wp_error($res), 'tampered pack cannot be recorded');
$res = VES_RC_Evidence_Pack::record_live_validation($incomplete);
$ok(is_wp_error($res), 'incomplete pack cannot be recorded');

// Readiness blockers refuse recording.
if (!class_exists('VES_RC_Readiness_Service')) {
    final class VES_RC_Readiness_Service {
        public static $blockers = ['simulated blocker'];
        public static function report(array $a = []) { return ['blockers' => self::$blockers]; }
    }
}
$res = VES_RC_Evidence_Pack::record_live_validation($full);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_evidence_blockers', 'current readiness blockers refuse the recording');

VES_RC_Readiness_Service::$blockers = [];
$res = VES_RC_Evidence_Pack::record_live_validation($full);
$ok(is_array($res) && $res['status'] === 'passed' && $res['source'] === 'evidence_pack', 'valid pack records an evidence-backed pass');
$ok(($res['evidence_pack_hash'] ?? '') === $full['evidence_pack_hash'], 'stored state carries the pack hash');
$ok(!isset($res['production_ready']) || $res['production_ready'] === false, 'recording never grants production_ready');

// ── 5. Classification ────────────────────────────────────────────────────────
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'passed' && preg_match('/^[a-f0-9]{64}$/', $state['evidence_pack_hash']), 'recorded state classifies as passed with hash');
$GLOBALS['__o']['ves_rc_live_validation'] = ['status' => 'passed', 'recorded_at' => '2026-06-14', 'note' => 'manual wp option update'];
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'unverified_manual', 'manual option without pack hash is unverified_manual');
$GLOBALS['__o']['ves_rc_live_validation'] = ['status' => 'passed', 'source' => 'evidence_pack', 'evidence_pack_hash' => 'nothex'];
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'unverified_manual', 'malformed hash is unverified_manual');
unset($GLOBALS['__o']['ves_rc_live_validation']);
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'unrun', 'no option means unrun');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
