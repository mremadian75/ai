<?php
/**
 * Phase 9E.3 — live validation script v3 contract (static + behavioral refusals).
 *
 * Proves:
 *  1. bash -n passes; no executable --apply anywhere
 *  2. REFUSES without --expected-siteurl/--expected-host (weak staging detection rejected)
 *  3. REFUSES without staging confirmation and without a DB backup
 *  4. finalize mode REFUSES without screenshots / browser console / network errors / php error log
 *  5. redaction covers token shapes; stdout+stderr+combined captured per command
 *  6. the full 18-command battery + evidence pack v2 + archive manifest are present
 *  7. v2 script remains intact alongside v3
 *
 * Run: php tests/test-ves-validation-script-v3-9e.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$root = dirname(__DIR__);
$v3 = $root . '/scripts/future-island-live-validation-v3.sh';
$v2 = $root . '/scripts/future-island-live-validation-v2.sh';
$ok(file_exists($v3), 'v3 script exists');
$ok(file_exists($v2), 'v2 script kept alongside v3');
$src = (string) file_get_contents($v3);

// ── 1. Syntax + no executable --apply ────────────────────────────────────────
exec('bash -n ' . escapeshellarg($v3) . ' 2>&1', $o, $rc);
$ok($rc === 0, 'v3 passes bash -n');
$apply_exec = 0;
foreach (explode("\n", $src) as $line) {
    if (strpos(ltrim($line), '#') === 0) { continue; }
    if (strpos($line, '--apply') !== false) { $apply_exec++; }
}
$ok($apply_exec === 0, 'no executable --apply in v3');

// ── 2-4. Behavioral refusals (run the real script; wp is absent here, but the
//        refusal gates fire BEFORE any wp usage) ─────────────────────────────
$run = function ($args) use ($v3) {
    exec('bash ' . escapeshellarg($v3) . ' ' . $args . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
list($rc, $out) = $run('--mode=collect-cli --i-confirm-this-is-staging --db-backup=/etc/hostname');
$ok($rc === 3 && stripos($out, 'expected-siteurl') !== false, 'REFUSES without expected siteurl/host');
list($rc, $out) = $run('--mode=collect-cli --expected-siteurl=https://staging.example.com --db-backup=/etc/hostname');
$ok($rc === 3 && stripos($out, 'staging') !== false, 'REFUSES without --i-confirm-this-is-staging');
list($rc, $out) = $run('--mode=collect-cli --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging');
$ok($rc === 3 && stripos($out, 'db-backup') !== false, 'REFUSES without a DB backup');
list($rc, $out) = $run('--mode=finalize --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging');
$ok($rc === 3 && stripos($out, 'screenshots-dir') !== false, 'finalize REFUSES without screenshots');

// finalize with screenshots but no console/network/php logs
$tmp = sys_get_temp_dir() . '/fi-v3-' . getmypid();
@mkdir($tmp . '/shots', 0777, true);
foreach (['01-signal-room.png','02-social-media-signal-report.png','03-evidence-gate-blocked.png','04-evidence-gate-ready.png','05-operator-queue.png','06-memory-brand-context.png','07-generation-context-preview.png','08-prompt-package-preview.png','09-brief-workbench.png','10-draft-workbench.png','11-release-candidate-page.png','12-security-dead-letter-diagnostics.png'] as $f) {
    file_put_contents($tmp . '/shots/' . $f, 'png');
}
list($rc, $out) = $run('--mode=finalize --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging --screenshots-dir=' . escapeshellarg($tmp . '/shots'));
$ok($rc === 3 && stripos($out, 'browser-console-log') !== false, 'finalize REFUSES without browser console log');
file_put_contents($tmp . '/console.log', 'NO_CONSOLE_ERRORS_OBSERVED');
list($rc, $out) = $run('--mode=finalize --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging --screenshots-dir=' . escapeshellarg($tmp . '/shots') . ' --browser-console-log=' . escapeshellarg($tmp . '/console.log'));
$ok($rc === 3 && stripos($out, 'network-errors-log') !== false, 'finalize REFUSES without network errors log');
file_put_contents($tmp . '/net.log', 'NO_NETWORK_ERRORS_OBSERVED');
list($rc, $out) = $run('--mode=finalize --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging --screenshots-dir=' . escapeshellarg($tmp . '/shots') . ' --browser-console-log=' . escapeshellarg($tmp . '/console.log') . ' --network-errors-log=' . escapeshellarg($tmp . '/net.log'));
$ok($rc === 3 && stripos($out, 'php-error-log') !== false, 'finalize REFUSES without the PHP error log');

// a missing required screenshot is refused by name
unlink($tmp . '/shots/07-generation-context-preview.png');
file_put_contents($tmp . '/php.log', 'tail');
list($rc, $out) = $run('--mode=finalize --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging --screenshots-dir=' . escapeshellarg($tmp . '/shots') . ' --browser-console-log=' . escapeshellarg($tmp . '/console.log') . ' --network-errors-log=' . escapeshellarg($tmp . '/net.log') . ' --php-error-log=' . escapeshellarg($tmp . '/php.log'));
$ok($rc === 3 && strpos($out, '07-generation-context-preview.png') !== false, 'finalize names the missing required screenshot');

// ── 4b. Deep-review fix: finalize needs the DB-backup hash (stored or passed) ─
@mkdir($tmp . '/evdir/commands', 0777, true);
file_put_contents($tmp . '/shots/07-generation-context-preview.png', 'png'); // restore the set
list($rc, $out) = $run('--mode=finalize --expected-siteurl=https://staging.example.com --i-confirm-this-is-staging --evidence-dir=' . escapeshellarg($tmp . '/evdir') . ' --screenshots-dir=' . escapeshellarg($tmp . '/shots') . ' --browser-console-log=' . escapeshellarg($tmp . '/console.log') . ' --network-errors-log=' . escapeshellarg($tmp . '/net.log') . ' --php-error-log=' . escapeshellarg($tmp . '/php.log'));
$ok($rc === 3 && stripos($out, 'DB backup hash') !== false, 'finalize REFUSES without a stored or passed DB backup hash');
file_put_contents($tmp . '/evdir/db-backup-sha256.txt', str_repeat('a', 64) . "  staging-backup.sql\n");
$ok(strpos($src, 'db-backup-sha256.txt') !== false && strpos($src, 'finalize reuses the hash stored by collect-cli') !== false, 'finalize reuses the collect-cli stored DB-backup hash');

// ── 5. Redaction + siteurl mismatch logic present in source ─────────────────
foreach (['apify_api_', 'sk-', 'sk_live_', 'whsec_', 'AIza', 'earer'] as $shape) {
    $ok(strpos($src, $shape) !== false, "redaction covers {$shape} tokens");
}
$ok(strpos($src, 'does not match --expected-siteurl') !== false, 'siteurl mismatch refusal implemented');
$ok(strpos($src, '.out') !== false && strpos($src, '.err') !== false && strpos($src, '.combined.txt') !== false, 'stdout/stderr/combined captured per command');
$ok(strpos($src, 'stdout_sha') !== false && strpos($src, 'stderr_sha') !== false && strpos($src, 'combined_sha') !== false, 'per-stream hashes in the command manifest');

// ── 6. Battery + pack v2 + archive manifest ──────────────────────────────────
foreach ([
    'php -v', 'wp --info', 'core version', 'SELECT VERSION();', 'option get siteurl', 'option get home',
    'plugin list', 'theme list', 'ves verify-schema', 'ves validate-staging --format=json',
    'ves readiness-check --format=json', 'ves rc-readiness-check --format=json',
    'ves memory-summary --format=json', 'ves memory-context-preview', 'ves generation-context-preview',
    'ves generation-prompt-preview', 'ves operator-queue', 'ves memory-expire --dry-run',
] as $cmd) {
    $ok(strpos($src, $cmd) !== false, "v3 runs: {$cmd}");
}
$ok(strpos($src, 'evidence-pack-v2.json') !== false, 'v3 emits the evidence pack v2');
$ok(strpos($src, 'manifest-files.txt') !== false && strpos($src, 'evidence_archive_sha256') !== false, 'archive manifest hashing present (non-circular)');
$ok(strpos($src, 'rc-record-live-validation --evidence-pack=') !== false && strpos($src, '--evidence-root=') !== false, 'v3 prints the exact file-backed record command');
$ok(strpos($src, 'does NOT make the build production-ready') !== false, 'v3 never claims production-readiness');
foreach (['db drop', 'db reset', 'plugin delete', 'generate with ai', 'publish'] as $forbidden) {
    $ok(stripos($src, $forbidden) === false, "v3 never runs: {$forbidden}");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
