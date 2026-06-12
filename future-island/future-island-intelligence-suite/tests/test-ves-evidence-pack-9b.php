<?php
/**
 * Phase 9E.1 — evidence pack v2 schema/hash contract (real class).
 *
 * Proves:
 *  1. schema 2.0 requires the FULL command battery (18), all 12 screenshots,
 *     browser console / network errors / PHP error log hashes and the DB
 *     backup hash — a pack missing ANY of them cannot validate or compute
 *     'passed'
 *  2. the evidence hash stays deterministic and tamper-evident
 *  3. record_live_validation REFUSES a pack JSON alone (json_only_unverified)
 *     — file verification is mandatory
 *  4. a manual option remains unverified_manual; an evidence-pack-shaped state
 *     without files_verified classifies json_only_unverified, never passed
 *
 * Run: php tests/test-ves-evidence-pack-9b.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.2.9'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc3'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return '2026-06-15 12:00:00';}
function get_current_user_id(){return 4;}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
$GLOBALS['__o']=[];

require_once dirname(__DIR__) . '/includes/class-ves-rc-evidence-pack.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$fake_sha = function ($seed) { return hash('sha256', (string) $seed); };

/** Build the complete v2 input set (schema-valid shapes; files come in the 9E archive test). */
$inputs = [
    'operator_name' => 'Ops',
    'build_sha256' => $fake_sha('zip'),
    'db_backup_sha256' => $fake_sha('backup'),
    'command_outputs' => [],
    'screenshots_manifest' => VES_RC_Evidence_Pack::REQUIRED_SCREENSHOTS,
    'screenshot_files' => [],
    'php_error_log_file' => 'logs/php-error-log-tail.log', 'php_error_log_sha256' => $fake_sha('php'),
    'browser_console_log_file' => 'logs/browser-console.log', 'browser_console_log_sha256' => $fake_sha('console'),
    'network_errors_file' => 'logs/network-errors.log', 'network_errors_sha256' => $fake_sha('net'),
    'evidence_archive_sha256' => $fake_sha('manifest'),
];
foreach (VES_RC_Evidence_Pack::REQUIRED_COMMANDS as $i => $cmd) {
    $slug = 'cmd' . $i;
    $inputs['command_outputs'][$cmd] = [
        'exit_code' => 0,
        'stdout_file' => "commands/{$slug}.out", 'stdout_sha256' => $fake_sha($slug . 'out'),
        'stderr_file' => "commands/{$slug}.err", 'stderr_sha256' => $fake_sha($slug . 'err'),
        'combined_file' => "commands/{$slug}.combined.txt", 'combined_sha256' => $fake_sha($slug . 'comb'),
    ];
}
foreach (VES_RC_Evidence_Pack::REQUIRED_SCREENSHOTS as $shot) {
    $inputs['screenshot_files'][$shot] = $fake_sha($shot);
}

// ── 1. build() honesty ────────────────────────────────────────────────────────
$ok(count(VES_RC_Evidence_Pack::REQUIRED_COMMANDS) === 18, 'required command battery is the full 18-command list');
$full = VES_RC_Evidence_Pack::build($inputs);
$ok($full['schema_version'] === '2.0', 'pack is schema 2.0');
$ok($full['validation_status'] === 'passed', 'complete v2 inputs compute passed');

$no_shots = $inputs; $no_shots['screenshots_manifest'] = []; $no_shots['screenshot_files'] = [];
$p = VES_RC_Evidence_Pack::build($no_shots);
$ok($p['validation_status'] === 'incomplete', 'pack CANNOT pass with empty screenshots_manifest');

$no_console = $inputs; $no_console['browser_console_log_sha256'] = '';
$p = VES_RC_Evidence_Pack::build($no_console);
$ok($p['validation_status'] === 'incomplete', 'pack CANNOT pass with empty browser console hash');

$no_php = $inputs; $no_php['php_error_log_sha256'] = '';
$p = VES_RC_Evidence_Pack::build($no_php);
$ok($p['validation_status'] === 'incomplete', 'pack CANNOT pass with empty PHP error log hash');

$no_net = $inputs; $no_net['network_errors_file'] = ''; $no_net['network_errors_sha256'] = '';
$p = VES_RC_Evidence_Pack::build($no_net);
$ok($p['validation_status'] === 'incomplete', 'pack CANNOT pass without the network-errors file+hash');

$no_db = $inputs; $no_db['db_backup_sha256'] = '';
$p = VES_RC_Evidence_Pack::build($no_db);
$ok($p['validation_status'] === 'incomplete', 'pack CANNOT pass without the DB backup hash');

$no_op = $inputs; unset($no_op['operator_name']);
$p = VES_RC_Evidence_Pack::build($no_op);
$ok($p['validation_status'] === 'incomplete', 'pack cannot pass without an operator');

// ── 2. Schema validation specifics ───────────────────────────────────────────
$v = VES_RC_Evidence_Pack::schema_validate($full);
$ok($v['valid'] === true, 'full v2 pack passes schema validation');
$broken = $full; unset($broken['command_outputs']['wp ves verify-schema']);
$v = VES_RC_Evidence_Pack::schema_validate($broken);
$ok($v['valid'] === false && strpos(implode(' ', $v['errors']), 'verify-schema') !== false, 'missing required command fails schema');
$nonzero = $full; $nonzero['command_outputs']['php -v']['exit_code'] = 1;
$ok(VES_RC_Evidence_Pack::schema_validate($nonzero)['valid'] === false, 'non-zero exit on a required command fails schema');
$nostream = $full; unset($nostream['command_outputs']['php -v']['stderr_sha256']);
$ok(VES_RC_Evidence_Pack::schema_validate($nostream)['valid'] === false, 'missing stderr hash fails schema (stdout/stderr/combined all required)');
$noshot = $full; $noshot['screenshots_manifest'] = array_slice($full['screenshots_manifest'], 0, 11);
$ok(VES_RC_Evidence_Pack::schema_validate($noshot)['valid'] === false, 'a missing required screenshot fails schema');
$traversal = $full; $traversal['php_error_log_file'] = '../../etc/passwd';
$ok(VES_RC_Evidence_Pack::schema_validate($traversal)['valid'] === false, 'path traversal in artifact paths fails schema');

// ── 3. Deterministic, tamper-evident hash ────────────────────────────────────
$h1 = VES_RC_Evidence_Pack::compute_hash($full);
$h2 = VES_RC_Evidence_Pack::compute_hash(array_reverse($full, true));
$ok($h1 === $h2 && preg_match('/^[a-f0-9]{64}$/', $h1), 'hash is deterministic regardless of key order');
$ok(VES_RC_Evidence_Pack::verify($full)['valid'] === true, 'untampered pack verifies');
$tampered = $full; $tampered['siteurl'] = 'https://evil.example.com';
$ok(VES_RC_Evidence_Pack::verify($tampered)['valid'] === false, 'tampered pack fails hash verification');

// ── 4. JSON alone can never be recorded ──────────────────────────────────────
if (!class_exists('VES_RC_Readiness_Service')) {
    final class VES_RC_Readiness_Service {
        public static $blockers = [];
        public static function report(array $a = []) { return ['blockers' => self::$blockers]; }
    }
}
$res = VES_RC_Evidence_Pack::record_live_validation($full); // no root, no archive
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_evidence_json_only', 'pack JSON alone is REFUSED as json_only_unverified');
$res = VES_RC_Evidence_Pack::record_live_validation($full, ['evidence_root' => '/nonexistent-root-xyz']);
$ok(is_wp_error($res), 'missing evidence root refuses recording');
$ok(get_option('ves_rc_live_validation', null) === null, 'nothing was recorded by the refused attempts');

// ── 5. Classification taxonomy ───────────────────────────────────────────────
$GLOBALS['__o']['ves_rc_live_validation'] = ['status' => 'passed', 'recorded_at' => '2026-06-15', 'note' => 'manual'];
$ok(VES_RC_Evidence_Pack::live_validation_state()['status'] === 'unverified_manual', 'manual option is unverified_manual');
$GLOBALS['__o']['ves_rc_live_validation'] = ['status' => 'passed', 'source' => 'evidence_pack', 'evidence_pack_hash' => str_repeat('ab', 32)];
$ok(VES_RC_Evidence_Pack::live_validation_state()['status'] === 'json_only_unverified', 'evidence-pack state WITHOUT files_verified is json_only_unverified, not passed');
$GLOBALS['__o']['ves_rc_live_validation'] = ['status' => 'passed', 'source' => 'evidence_pack', 'evidence_pack_hash' => str_repeat('ab', 32), 'files_verified' => true, 'schema_version' => '2.0', 'verified_via' => 'evidence_root'];
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'passed' && !empty($state['files_verified']), 'file-backed recorded state classifies as passed');
unset($GLOBALS['__o']['ves_rc_live_validation']);
$ok(VES_RC_Evidence_Pack::live_validation_state()['status'] === 'unrun', 'no option means unrun');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
