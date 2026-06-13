<?php
/**
 * Phase 9C.1 — live validation script v2 + browser smoke harness contract.
 *
 * Proves (statically — the script itself only runs on real staging):
 *  1. both scripts parse (bash -n)
 *  2. no executable --apply anywhere (comments only)
 *  3. staging confirmation + DB backup are hard requirements
 *  4. the secret-redaction function exists and covers token shapes
 *  5. every required validation command is present
 *  6. evidence folder, manifest, archive and next-steps are produced
 *  7. the script never runs destructive/AI/publish commands
 *
 * Run: php tests/test-ves-live-validation-script-9c.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

$script = dirname(__DIR__) . '/scripts/future-island-live-validation-v2.sh';
$smoke  = dirname(__DIR__) . '/scripts/future-island-browser-smoke.sh';
$ok(file_exists($script), 'validation script exists');
$ok(file_exists($smoke), 'browser smoke script exists');
$src = (string) file_get_contents($script);
$smoke_src = (string) file_get_contents($smoke);

// ── 1. bash -n ────────────────────────────────────────────────────────────────
exec('bash -n ' . escapeshellarg($script) . ' 2>&1', $o1, $rc1);
$ok($rc1 === 0, 'validation script passes bash -n');
exec('bash -n ' . escapeshellarg($smoke) . ' 2>&1', $o2, $rc2);
$ok($rc2 === 0, 'smoke script passes bash -n');

// ── 2. No executable --apply ──────────────────────────────────────────────────
$apply_exec = 0;
foreach (explode("\n", $src) as $line) {
    $trim = ltrim($line);
    if (strpos($trim, '#') === 0) { continue; }
    if (strpos($line, '--apply') !== false) { $apply_exec++; }
}
$ok($apply_exec === 0, 'no executable --apply in the validation script');
$ok(strpos($src, '--dry-run') !== false, 'trend backfill runs dry-run only');

// ── 3. Hard requirements ──────────────────────────────────────────────────────
$ok(strpos($src, '--i-confirm-this-is-staging') !== false && strpos($src, 'CONFIRM_STAGING') !== false, 'staging confirmation flag required');
$ok(preg_match('/REFUSED.*staging/i', $src) === 1, 'refusal message for missing staging confirmation');
$ok(strpos($src, '--db-backup') !== false && preg_match('/REFUSED.*db.?backup/is', $src) === 1, 'DB backup is a hard requirement');
$ok(strpos($src, "looks like production") !== false, 'production-looking siteurl is refused');

// ── 4. Redaction ──────────────────────────────────────────────────────────────
$ok(strpos($src, 'redact()') !== false, 'redact function defined');
foreach (['apify_api_', 'sk-', 'sk_live_', 'whsec_', 'AIza', 'earer'] as $shape) {
    $ok(strpos($src, $shape) !== false, "redaction covers {$shape} tokens");
}

// ── 5. Required commands present ──────────────────────────────────────────────
foreach ([
    'php -v', 'wp --info', 'core version', 'SELECT VERSION();', 'option get siteurl', 'option get home',
    'plugin list', 'theme list', 'ves verify-schema', 'ves validate-staging --format=json',
    'ves readiness-check --format=json', 'ves rc-readiness-check --format=json',
    'ves memory-summary --format=json', 'ves memory-context-preview', 'ves generation-context-preview',
    'ves generation-prompt-preview', 'ves operator-queue', 'ves memory-expire --dry-run',
    'ves rc-evidence-pack',
] as $cmd) {
    $ok(strpos($src, $cmd) !== false, "script runs: {$cmd}");
}

// ── 6. Evidence artifacts ─────────────────────────────────────────────────────
$ok(strpos($src, 'fi-evidence-') !== false && strpos($src, 'mkdir -p') !== false, 'timestamped evidence folder created');
$ok(strpos($src, 'manifest-commands.txt') !== false, 'command manifest produced');
$ok(strpos($src, 'tar -czf') !== false, 'evidence tar.gz archive produced');
$ok(strpos($src, 'sha256sum') !== false, 'SHA-256 computed');
$ok(strpos($src, 'NEXT STEPS') !== false && strpos($src, 'rc-record-live-validation') !== false, 'next steps point to the evidence-backed record command');
$ok(strpos($src, 'does NOT make the build production-ready') !== false, 'script states the pass is not production-readiness');

// ── 7. Forbidden operations absent ───────────────────────────────────────────
foreach (['db drop', 'db reset', 'plugin delete', 'generate with ai', 'publish', 'wp post create'] as $forbidden) {
    $ok(stripos($src, $forbidden) === false, "script never runs: {$forbidden}");
}

// ── 8. Smoke harness honesty ──────────────────────────────────────────────────
$ok(strpos($smoke_src, 'NOT a visual validation') !== false, 'smoke harness states it is not a visual validation');
$ok(strpos($smoke_src, 'never fakes screenshots') !== false, 'smoke harness never fakes screenshots');
$ok(strpos($smoke_src, 'ves-release-candidate') !== false, 'smoke harness checks the RC page URL');
$ok(strpos($smoke_src, 'REQUIRED SCREENSHOTS') !== false, 'smoke harness prints the screenshot list');
$ok(stripos($smoke_src, 'Auto-approve') !== false && stripos($smoke_src, 'must not') !== false, 'smoke harness reminds about forbidden buttons');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
