<?php
/**
 * Readiness output surfaces — proves the plugin RENDERS non-empty, escaped
 * readiness/validation output and represents a missing live environment as an
 * explicit `unavailable` / `not_run` state (never a silent blank, never a fake
 * pass). Deterministic; no DB, no WP, no LLM, no network.
 *
 *   VES_Staging_Validation_Service::environment_health()
 *   ::legacy_gate_health() / ::security_health()
 *   ::run_validation() / ::export_summary()
 *   ::render_admin_html() / ::unavailable_html()
 *
 * Run: php tests/test-ves-readiness-output-surfaces.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/fiis_readiness_' . getmypid() . '/'); }
// esc_html shim so the formatter's escaping path is exercised and assertable.
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }

require_once dirname(__DIR__) . '/includes/class-ves-staging-validation-service.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };

// ── 1. Missing DB ⇒ explicit unavailable/not_run (NOT a pass) ─────────────────
unset($GLOBALS['wpdb']);
$env = VES_Staging_Validation_Service::environment_health();
$ok($env['database'] === 'unavailable' && $env['live_validation'] === 'unavailable', '1: no DB ⇒ database/live_validation = unavailable (not pass)');
$ok($env['wp_cli'] === 'not_run', '1: no WP-CLI context ⇒ wp_cli = not_run');

// ── 2. Usable $wpdb ⇒ available ──────────────────────────────────────────────
$GLOBALS['wpdb'] = new class { public function get_var($q = '') { return '1'; } };
$env2 = VES_Staging_Validation_Service::environment_health();
$ok($env2['database'] === 'available' && $env2['live_validation'] === 'available', '2: usable $wpdb ⇒ database/live_validation = available');
unset($GLOBALS['wpdb']);

// ── 3. security_health / legacy_gate_health are safe + count-only ─────────────
$sec = VES_Staging_Validation_Service::security_health();
$ok($sec['scrubber'] === 'active' && $sec['auto_approval'] === 'disabled' && $sec['forbidden_keys'] > 0, '3: security_health: scrubber active, auto_approval disabled, forbidden_keys>0');
$ok($sec['secret_leak_scan'] === 'not_run' && $sec['secret_leak_scan'] !== 'clean', '3: security_health: secret_leak_scan is honestly not_run (no false "clean" claim)');
$ok(($sec['export_redaction'] ?? '') === 'active', '3: security_health: export_redaction reported as active (the thing actually proven)');
$lg = VES_Staging_Validation_Service::legacy_gate_health(['workspace_id' => 0]);
$ok($lg['available'] === false && $lg['legacy_total'] === 0 && !isset($lg['evidence_refs']), '3: legacy_gate_health degrades gracefully (available=false) with counts only, no raw evidence');

// ── 4. run_validation: non-empty, has the new blocks, never throws sans DB ────
$r = VES_Staging_Validation_Service::run_validation(['workspace_id' => 0]);
$ok(is_array($r) && !empty($r), '4: run_validation returns non-empty structured array without a DB');
foreach (['environment_health', 'schema_health', 'trend_health', 'insight_health', 'telemetry_health', 'legacy_gate', 'security_health', 'production_readiness_status'] as $k) {
    $ok(array_key_exists($k, $r), "4: run_validation includes '$k'");
}

// ── 5. export_summary (CLI JSON path): JSON-safe, scrubbed, carries new blocks ─
$exp = VES_Staging_Validation_Service::export_summary(['workspace_id' => 0]);
$json = json_encode($exp);
$ok(is_string($json) && strlen($json) > 2, '5: export_summary is JSON-encodable (feeds wp ves validate-staging --format=json)');
foreach (VES_Staging_Validation_Service::FORBIDDEN_EXPORT_KEYS as $bad) {
    if (array_key_exists($bad, $exp)) { $ok(false, "5: scrubber removed forbidden top-level key '$bad'"); }
}
$ok(isset($exp['environment_health']) && isset($exp['security_health']), '5: CLI JSON export carries environment_health + security_health');

// ── 6. render_admin_html: ALWAYS non-empty, escaped, explicit states ─────────
$html_empty = VES_Staging_Validation_Service::render_admin_html([]);
$ok(is_string($html_empty) && strlen($html_empty) > 200, '6: render_admin_html([]) returns non-empty HTML (no blank panel)');
$ok(strpos($html_empty, 'Production Readiness') !== false, '6: output contains the panel heading');
$ok(strpos($html_empty, 'UNAVAILABLE') !== false && strpos($html_empty, 'NOT_RUN') !== false, '6: missing env rendered as explicit UNAVAILABLE / NOT_RUN');
$ok(strpos($html_empty, 'Legacy gate') !== false && strpos($html_empty, 'Security') !== false, '6: panel surfaces Legacy gate + Security rows');
$ok(strpos($html_empty, 'Legend') !== false, '6: panel shows the passed/warning/failed/not_run/unavailable legend');
// Honesty: Security row must NOT claim a clean secret scan; must show secret-scan not_run + export redaction.
$ok(stripos($html_empty, 'secret-scan clean') === false, '6: Security row no longer overclaims "secret-scan clean"');
$ok(strpos($html_empty, 'export redaction active') !== false && strpos($html_empty, 'secret-scan') !== false, '6: Security row shows "export redaction active" + "secret-scan NOT_RUN" (honest)');

// ── 7. Escaping: dynamic values are escaped (no XSS passthrough) ──────────────
$html_xss = VES_Staging_Validation_Service::render_admin_html(['production_readiness_status' => '<script>alert(1)</script>']);
$ok(stripos($html_xss, '<script') === false && strpos($html_xss, '&lt;') !== false, '7: dynamic status is HTML-escaped (no raw <script> tag emitted)');

// ── 8. Healthy/available render path shows AVAILABLE + READY + legacy counts ──
$r_ok = [
    'production_readiness_status' => 'ready',
    'environment_health' => ['database' => 'available', 'live_validation' => 'available', 'wp_cli' => 'available'],
    'schema_health' => ['available' => true, 'status' => 'ok'],
    'legacy_gate' => ['available' => true, 'gate' => 'active', 'legacy_total' => 7, 'legacy_approved' => 3, 'legacy_approved_without_evidence' => 1],
];
$html_ok = VES_Staging_Validation_Service::render_admin_html($r_ok);
$ok(strpos($html_ok, 'AVAILABLE') !== false && strpos($html_ok, 'READY') !== false, '8: available env + ready status render explicitly');
$ok(strpos($html_ok, 'approved_without_evidence=1') !== false && strpos($html_ok, 'total=7') !== false, '8: legacy counts surfaced (no raw evidence text)');

// ── 9. unavailable_html fallback is explicit + escaped ───────────────────────
$u = VES_Staging_Validation_Service::unavailable_html('not_run', 'db down <x>');
$ok(strpos($u, 'NOT_RUN') !== false && strpos($u, '&lt;x&gt;') !== false && strpos($u, '<x>') === false, '9: unavailable_html shows explicit state and escapes the reason');

// ── 10. Contract: admin console delegates (no silent blank) + no auto-approval ─
$console = (string) @file_get_contents(dirname(__DIR__) . '/includes/class-ves-admin-console.php');
$svc_src = (string) @file_get_contents(dirname(__DIR__) . '/includes/class-ves-staging-validation-service.php');
$ok(strpos($console, 'VES_Staging_Validation_Service::render_admin_html') !== false, '10: admin console renders via render_admin_html');
$ok(strpos($console, 'unavailable_html') !== false, '10: admin console shows explicit unavailable/not_run card on failure');
$ok(stripos($svc_src, 'auto_approve') === false && strpos($svc_src, 'update_status') === false && strpos($svc_src, "'approved'") === false, '10: readiness service introduces no approval/auto-approval/status-mutation path');
// PHP 7.4 safety: no 8-only syntax in the changed service.
$ok(!preg_match('/\bmatch\s*\(|\bstr_contains\(|\bstr_starts_with\(|\bstr_ends_with\(|\?->/', $svc_src), '10: changed service is PHP 7.4-clean (no match()/str_*/nullsafe)');

echo "\nReadiness output surfaces: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
