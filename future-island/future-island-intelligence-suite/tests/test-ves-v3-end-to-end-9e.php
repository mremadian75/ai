<?php
/**
 * Deep review — Phase 9E END-TO-END pipeline integration (real script + real PHP).
 *
 * Simulates a staging run with a fake `wp` binary and drives the ACTUAL chain:
 *   v3 script (--mode=full) → command capture → browser artifacts → python pack
 *   assembly + hashing → tar.gz archive → PHP VES_RC_Evidence_Pack::verify()
 *   → record_live_validation() with --evidence-root AND --evidence-archive.
 *
 * This is the proof the deliverables have been missing:
 *  1. the bash/python-assembled pack VERIFIES under the PHP canonical hash
 *     (cross-language hash agreement — if the canonicalizations diverge, the
 *     entire staging flow dies at the record step)
 *  2. the script-produced pack computes validation_status=passed and the PHP
 *     side records a file-verified pass from the evidence ROOT
 *  3. the tar.gz ARCHIVE path also records (PharData extraction + manifest hash)
 *  4. tampering with one captured command output AFTER the run is caught
 *
 * No network, no real WP — the wp shim answers locally.
 * Run: php tests/test-ves-v3-end-to-end-9e.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.2.8'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc3'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return gmdate('Y-m-d H:i:s');}
function get_current_user_id(){return 4;}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
final class VES_RC_Readiness_Service { public static function report(array $a = []) { return ['blockers' => []]; } }
$GLOBALS['__o']=[];

require_once dirname(__DIR__) . '/includes/class-ves-rc-evidence-pack.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/fi-e2e-' . getmypid();
@mkdir($sandbox . '/bin', 0777, true);
@mkdir($sandbox . '/shots', 0777, true);
@mkdir($sandbox . '/out', 0777, true);

// ── Fake wp binary: answers every battery command locally, exit 0 ────────────
$wp_shim = <<<'SH'
#!/usr/bin/env bash
case "$*" in
    *"option get siteurl"*) echo "https://staging.example.test" ;;
    *"option get home"*)    echo "https://staging.example.test" ;;
    *"core version"*)       echo "6.5.2" ;;
    *"db query"*)           echo "VERSION()
8.0.36-staging" ;;
    *"--info"*)             echo "WP-CLI 2.10.0 (shim)" ;;
    *"plugin list"*)        echo "future-island-intelligence-suite active 1.2.8" ;;
    *"theme list"*)         echo "twentytwentyfour active" ;;
    *"ves "*)               echo '{"status":"ready_with_warnings","shim":true}' ;;
    *)                      echo "shim-ok" ;;
esac
exit 0
SH;
file_put_contents($sandbox . '/bin/wp', $wp_shim);
chmod($sandbox . '/bin/wp', 0755);

// ── Browser artifacts + DB backup ─────────────────────────────────────────────
foreach (VES_RC_Evidence_Pack::REQUIRED_SCREENSHOTS as $shot) {
    file_put_contents($sandbox . '/shots/' . $shot, "\x89PNG e2e {$shot}");
}
file_put_contents($sandbox . '/console.log', "NO_CONSOLE_ERRORS_OBSERVED\n");
file_put_contents($sandbox . '/net.log', "NO_NETWORK_ERRORS_OBSERVED\n");
file_put_contents($sandbox . '/php-errors.log', "[15-Jun-2026] nothing of note\n");
file_put_contents($sandbox . '/staging-backup.sql', "-- fake staging dump for e2e\n");

// ── Run the REAL v3 script in --mode=full ────────────────────────────────────
$cmd = 'PATH=' . escapeshellarg($sandbox . '/bin') . ':$PATH bash ' . escapeshellarg($root . '/scripts/future-island-live-validation-v3.sh')
    . ' --mode=full --expected-siteurl=https://staging.example.test --i-confirm-this-is-staging'
    . ' --db-backup=' . escapeshellarg($sandbox . '/staging-backup.sql')
    . ' --screenshots-dir=' . escapeshellarg($sandbox . '/shots')
    . ' --browser-console-log=' . escapeshellarg($sandbox . '/console.log')
    . ' --network-errors-log=' . escapeshellarg($sandbox . '/net.log')
    . ' --php-error-log=' . escapeshellarg($sandbox . '/php-errors.log')
    . ' --operator="E2E Ops" --output=' . escapeshellarg($sandbox . '/out') . ' 2>&1';
exec($cmd, $out_lines, $rc);
$out = implode("\n", $out_lines);
$ok($rc === 0, 'v3 full-mode run exits 0 against the wp shim' . ($rc !== 0 ? " (rc={$rc}) — " . substr($out, -400) : ''));
$ok(strpos($out, 'validation_status: passed') !== false, 'script-assembled pack computes validation_status=passed');

$evdirs = glob($sandbox . '/out/fi-evidence-*', GLOB_ONLYDIR);
$ok(count($evdirs) === 1, 'exactly one evidence folder produced');
$evdir = (string) ($evdirs[0] ?? '');
$pack_file = $evdir . '/evidence-pack-v2.json';
$archive = $evdir . '.tar.gz';
$ok(is_file($pack_file), 'evidence-pack-v2.json exists');
$ok(is_file($archive), 'evidence tar.gz archive exists');
$ok(is_file($evdir . '/manifest-files.txt') && is_file($evdir . '/manifest-commands.txt'), 'both manifests exist');
$ok(is_file($evdir . '/logs/browser-console.log') && is_file($evdir . '/screenshots/01-signal-room.png'), 'browser artifacts copied into the evidence folder');

$pack = json_decode((string) file_get_contents($pack_file), true);
$ok(is_array($pack) && ($pack['schema_version'] ?? '') === '2.0', 'pack parses as schema 2.0');
$ok(($pack['siteurl'] ?? '') === 'https://staging.example.test', 'pack captured the shim siteurl');
$ok(($pack['db_backup_sha256'] ?? '') === hash_file('sha256', $sandbox . '/staging-backup.sql'), 'pack carries the real DB backup hash');

// ── 1. CROSS-LANGUAGE HASH AGREEMENT ─────────────────────────────────────────
$verify = VES_RC_Evidence_Pack::verify($pack);
$ok($verify['valid'] === true, 'PHP verify() accepts the python-hashed pack (canonicalizations AGREE): ' . implode('; ', array_slice($verify['errors'], 0, 3)));
$ok(VES_RC_Evidence_Pack::compute_hash($pack) === strtolower((string) $pack['evidence_pack_hash']), 'PHP compute_hash equals the python-computed evidence_pack_hash');

// ── 2. File-backed recording from the evidence ROOT ──────────────────────────
$res = VES_RC_Evidence_Pack::record_live_validation($pack, ['evidence_root' => $evdir]);
$ok(is_array($res) && ($res['status'] ?? '') === 'passed' && !empty($res['files_verified']), 'ROOT recording: file-verified pass recorded');
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'passed' && ($state['schema_version'] ?? '') === '2.0', 'classifier reports a schema-2.0 file-backed pass');
unset($GLOBALS['__o']['ves_rc_live_validation']);

// ── 3. File-backed recording from the tar.gz ARCHIVE ─────────────────────────
if (class_exists('PharData')) {
    $res = VES_RC_Evidence_Pack::record_live_validation($pack, ['archive_path' => $archive]);
    $ok(is_array($res) && ($res['verified_via'] ?? '') === 'evidence_archive', 'ARCHIVE recording: extraction + manifest hash + artifact verification all pass');
    unset($GLOBALS['__o']['ves_rc_live_validation']);
} else {
    $ok(true, 'PharData unavailable — archive leg covered by the dedicated 9E test');
}

// ── 4. Post-run tamper is caught ─────────────────────────────────────────────
$victim = glob($evdir . '/commands/*.out');
file_put_contents($victim[0], "tampered after the fact\n");
$res = VES_RC_Evidence_Pack::record_live_validation($pack, ['evidence_root' => $evdir]);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_evidence_file_hash_mismatch', 'tampering a captured command output after the run refuses recording');
$ok(get_option('ves_rc_live_validation', null) === null, 'nothing was recorded after the tamper');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
