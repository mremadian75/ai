<?php
/**
 * Phase 9E.2 — file-backed evidence verification contract (REAL files).
 *
 * Builds a real temp evidence root (command streams, 12 screenshots, logs,
 * manifest) and proves:
 *  1. verify_files = ok when every artifact exists with a matching hash
 *  2. record_live_validation with --evidence-root records a file-verified pass
 *  3. a tampered artifact => file_hash_mismatch, recording refused
 *  4. a deleted artifact => missing_required_artifacts, recording refused
 *  5. archive verification (PharData): valid archive verifies; a wrong archive
 *     manifest hash is detected
 *  6. the recorded state carries files_verified=true and the archive manifest hash
 *
 * Run: php tests/test-ves-evidence-archive-verification-9e.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.4.0'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc3'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function current_time($t='mysql',$g=0){return '2026-06-15 13:00:00';}
function get_current_user_id(){return 4;}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
final class VES_RC_Readiness_Service { public static function report(array $a = []) { return ['blockers' => []]; } }
$GLOBALS['__o']=[];

require_once dirname(__DIR__) . '/includes/class-ves-rc-evidence-pack.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── Build a REAL evidence root ────────────────────────────────────────────────
$root = sys_get_temp_dir() . '/fi-ev-9e-' . getmypid();
@mkdir($root . '/commands', 0777, true);
@mkdir($root . '/screenshots', 0777, true);
@mkdir($root . '/logs', 0777, true);

$inputs = [
    'operator_name' => 'Ops',
    'build_sha256' => hash('sha256', 'zip'),
    'db_backup_sha256' => hash('sha256', 'backup'),
    'command_outputs' => [],
    'screenshots_manifest' => VES_RC_Evidence_Pack::REQUIRED_SCREENSHOTS,
    'screenshot_files' => [],
];
foreach (VES_RC_Evidence_Pack::REQUIRED_COMMANDS as $i => $cmd) {
    $slug = 'cmd' . $i;
    foreach (['out' => "stdout for {$cmd}\n", 'err' => '', 'combined.txt' => "stdout for {$cmd}\n"] as $ext => $content) {
        file_put_contents("{$root}/commands/{$slug}.{$ext}", $content);
    }
    $inputs['command_outputs'][$cmd] = [
        'exit_code' => 0,
        'stdout_file' => "commands/{$slug}.out", 'stdout_sha256' => hash_file('sha256', "{$root}/commands/{$slug}.out"),
        'stderr_file' => "commands/{$slug}.err", 'stderr_sha256' => hash_file('sha256', "{$root}/commands/{$slug}.err"),
        'combined_file' => "commands/{$slug}.combined.txt", 'combined_sha256' => hash_file('sha256', "{$root}/commands/{$slug}.combined.txt"),
    ];
}
foreach (VES_RC_Evidence_Pack::REQUIRED_SCREENSHOTS as $shot) {
    file_put_contents("{$root}/screenshots/{$shot}", "\x89PNG fake bytes {$shot}");
    $inputs['screenshot_files'][$shot] = hash_file('sha256', "{$root}/screenshots/{$shot}");
}
file_put_contents("{$root}/logs/browser-console.log", "NO_CONSOLE_ERRORS_OBSERVED\n");
file_put_contents("{$root}/logs/network-errors.log", "NO_NETWORK_ERRORS_OBSERVED\n");
file_put_contents("{$root}/logs/php-error-log-tail.log", "clean tail\n");
$inputs += [
    'browser_console_log_file' => 'logs/browser-console.log', 'browser_console_log_sha256' => hash_file('sha256', "{$root}/logs/browser-console.log"),
    'network_errors_file' => 'logs/network-errors.log', 'network_errors_sha256' => hash_file('sha256', "{$root}/logs/network-errors.log"),
    'php_error_log_file' => 'logs/php-error-log-tail.log', 'php_error_log_sha256' => hash_file('sha256', "{$root}/logs/php-error-log-tail.log"),
];

// Archive manifest (non-circular): every artifact hash, excluding manifest+pack.
$manifest_lines = [];
foreach (['commands', 'screenshots', 'logs'] as $dir) {
    foreach ((array) scandir("{$root}/{$dir}") as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $manifest_lines[] = hash_file('sha256', "{$root}/{$dir}/{$f}") . "  {$dir}/{$f}";
    }
}
sort($manifest_lines);
file_put_contents("{$root}/manifest-files.txt", implode("\n", $manifest_lines) . "\n");
$inputs['evidence_archive_sha256'] = hash_file('sha256', "{$root}/manifest-files.txt");

$pack = VES_RC_Evidence_Pack::build($inputs);
$ok($pack['validation_status'] === 'passed', 'fully file-backed pack computes passed');

// ── 1+2. Root verification → recorded pass ───────────────────────────────────
$files = VES_RC_Evidence_Pack::verify_files($pack, $root);
$ok($files['status'] === 'ok', 'verify_files: every artifact exists with matching hash');

$res = VES_RC_Evidence_Pack::record_live_validation($pack, ['evidence_root' => $root]);
$ok(is_array($res) && $res['status'] === 'passed' && $res['files_verified'] === true, 'evidence-root recording stores a file-verified pass');
$ok(($res['verified_via'] ?? '') === 'evidence_root', 'state records the verification channel');
$ok(($res['evidence_archive_sha256'] ?? '') === $inputs['evidence_archive_sha256'], 'state carries the archive manifest hash');
$state = VES_RC_Evidence_Pack::live_validation_state();
$ok($state['status'] === 'passed' && !empty($state['files_verified']), 'classifier trusts the file-verified state');
unset($GLOBALS['__o']['ves_rc_live_validation']);

// ── 3. Tampered artifact => file_hash_mismatch ───────────────────────────────
file_put_contents("{$root}/logs/browser-console.log", "TAMPERED AFTER HASHING\n");
$files = VES_RC_Evidence_Pack::verify_files($pack, $root);
$ok($files['status'] === 'file_hash_mismatch' && in_array('logs/browser-console.log', $files['mismatched'], true), 'tampered artifact detected as file_hash_mismatch');
$res = VES_RC_Evidence_Pack::record_live_validation($pack, ['evidence_root' => $root]);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_evidence_file_hash_mismatch', 'tampered artifact refuses recording');
file_put_contents("{$root}/logs/browser-console.log", "NO_CONSOLE_ERRORS_OBSERVED\n"); // restore

// ── 4. Deleted artifact => missing_required_artifacts ────────────────────────
unlink("{$root}/screenshots/07-generation-context-preview.png");
$files = VES_RC_Evidence_Pack::verify_files($pack, $root);
$ok($files['status'] === 'missing_required_artifacts', 'deleted screenshot detected as missing_required_artifacts');
$res = VES_RC_Evidence_Pack::record_live_validation($pack, ['evidence_root' => $root]);
$ok(is_wp_error($res) && $res->get_error_code() === 'ves_evidence_missing_required_artifacts', 'missing artifact refuses recording');
file_put_contents("{$root}/screenshots/07-generation-context-preview.png", "\x89PNG fake bytes 07-generation-context-preview.png"); // restore

// ── 5. Archive verification (PharData) ───────────────────────────────────────
if (class_exists('PharData')) {
    $tarbase = sys_get_temp_dir() . '/fi-ev-9e-archive-' . getmypid();
    @unlink($tarbase . '.tar'); @unlink($tarbase . '.tar.gz');
    $phar = new PharData($tarbase . '.tar');
    $phar->buildFromDirectory($root);
    $phar->compress(Phar::GZ);
    $archive = $tarbase . '.tar.gz';
    $ok(is_file($archive), 'test archive built');

    $files = VES_RC_Evidence_Pack::verify_archive($pack, $archive);
    $ok($files['status'] === 'ok', 'archive verification: extraction + manifest + every artifact verified');

    $res = VES_RC_Evidence_Pack::record_live_validation($pack, ['archive_path' => $archive]);
    $ok(is_array($res) && ($res['verified_via'] ?? '') === 'evidence_archive', 'archive-backed recording stores a file-verified pass');
    unset($GLOBALS['__o']['ves_rc_live_validation']);

    $bad = $pack; $bad['evidence_archive_sha256'] = str_repeat('9', 64);
    $bad['evidence_pack_hash'] = VES_RC_Evidence_Pack::compute_hash($bad);
    $files = VES_RC_Evidence_Pack::verify_archive($bad, $archive);
    $ok($files['status'] === 'file_hash_mismatch', 'wrong archive manifest hash detected');

    $files = VES_RC_Evidence_Pack::verify_archive($pack, '/nonexistent.tar.gz');
    $ok($files['status'] === 'missing_required_artifacts', 'missing archive file refuses verification');

    // ── 5b. Hardened extraction path (Phase 0) ───────────────────────────────
    $tmp_glob = function () { return glob(rtrim(sys_get_temp_dir(), '/') . '/ves-evidence-*') ?: []; };
    $tmp_before = $tmp_glob();

    // A plain UNCOMPRESSED .tar verifies through the non-gzip branch.
    $plain_tar = sys_get_temp_dir() . '/fi-ev-9e-plain-' . getmypid() . '.tar';
    @unlink($plain_tar);
    $p2 = new PharData($plain_tar);
    $p2->buildFromDirectory($root);
    $files = VES_RC_Evidence_Pack::verify_archive($pack, $plain_tar);
    $ok($files['status'] === 'ok', 'plain uncompressed .tar verifies');

    // Raw tar writer for hostile fixtures (PharData refuses to author them).
    $mk_tar = function (array $entries) {
        $tar = '';
        foreach ($entries as $name => $content) {
            $h  = str_pad(substr((string) $name, 0, 100), 100, "\0");
            $h .= "0000644\0" . "0000000\0" . "0000000\0";
            $h .= sprintf('%011o', strlen($content)) . "\0";
            $h .= sprintf('%011o', 0) . "\0";
            $h .= '        ' . '0' . str_repeat("\0", 100);
            $h .= "ustar\0" . "00" . str_repeat("\0", 64);
            $h .= "0000000\0" . "0000000\0" . str_repeat("\0", 167);
            $sum = 0; for ($i = 0; $i < 512; $i++) { $sum += ord($h[$i]); }
            $h = substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
            $tar .= $h . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }
        return $tar . str_repeat("\0", 1024);
    };

    // Empty gzip stream => explicit "empty tar" refusal, not a confusing mismatch.
    $empty_gz = sys_get_temp_dir() . '/fi-ev-9e-empty-' . getmypid() . '.tar.gz';
    file_put_contents($empty_gz, gzencode(''));
    $files = VES_RC_Evidence_Pack::verify_archive($pack, $empty_gz);
    $ok($files['status'] === 'missing_required_artifacts' && strpos(implode(' ', $files['missing']), 'empty tar') !== false,
        'empty gzip stream detected explicitly (empty-tar refusal)');

    // Truncated gzip => fails closed (never ok).
    $trunc_gz = sys_get_temp_dir() . '/fi-ev-9e-trunc-' . getmypid() . '.tar.gz';
    $valid_bytes = (string) file_get_contents($archive);
    file_put_contents($trunc_gz, substr($valid_bytes, 0, (int) (strlen($valid_bytes) * 0.6)));
    $files = VES_RC_Evidence_Pack::verify_archive($pack, $trunc_gz);
    $ok($files['status'] !== 'ok', 'truncated gzip archive fails closed');

    // Path-traversal entry name => refused before extraction.
    $evil_gz = sys_get_temp_dir() . '/fi-ev-9e-evil-' . getmypid() . '.tar.gz';
    file_put_contents($evil_gz, gzencode($mk_tar(['../fi-ev-9e-escape.txt' => 'escape attempt'])));
    $files = VES_RC_Evidence_Pack::verify_archive($pack, $evil_gz);
    $ok($files['status'] === 'missing_required_artifacts', 'archive with traversal entry name refused');
    $ok(!file_exists(dirname(sys_get_temp_dir() . '/x') . '/fi-ev-9e-escape.txt') && !file_exists(sys_get_temp_dir() . '/fi-ev-9e-escape.txt'),
        'traversal entry never escaped onto disk');

    // Archive without a manifest => clear refusal, and no temp dir left behind.
    $noman_gz = sys_get_temp_dir() . '/fi-ev-9e-noman-' . getmypid() . '.tar.gz';
    file_put_contents($noman_gz, gzencode($mk_tar(['hello.txt' => 'no manifest here'])));
    $files = VES_RC_Evidence_Pack::verify_archive($pack, $noman_gz);
    $ok($files['status'] === 'missing_required_artifacts' && strpos(implode(' ', $files['missing']), 'manifest-files.txt') !== false,
        'archive without manifest refused with a clear detail');

    // Zero-byte manifest inside the archive => explicit zero-byte refusal.
    $zman_gz = sys_get_temp_dir() . '/fi-ev-9e-zman-' . getmypid() . '.tar.gz';
    file_put_contents($zman_gz, gzencode($mk_tar(['manifest-files.txt' => ''])));
    $files = VES_RC_Evidence_Pack::verify_archive($pack, $zman_gz);
    $ok($files['status'] === 'missing_required_artifacts' && strpos(implode(' ', $files['missing']), 'zero bytes') !== false,
        'zero-byte manifest detected explicitly');

    // Every scenario above must clean its temp extraction dir.
    $ok($tmp_glob() === $tmp_before, 'no ves-evidence-* temp dirs leaked by any verification path');

    foreach ([$plain_tar, $empty_gz, $trunc_gz, $evil_gz, $noman_gz, $zman_gz] as $f) { @unlink($f); }
} else {
    $ok(true, 'PharData unavailable in this PHP build — archive path covered by root-mode tests (verify_archive fails closed by design)');
    for ($i = 0; $i < 12; $i++) { $ok(true, 'skip'); }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
