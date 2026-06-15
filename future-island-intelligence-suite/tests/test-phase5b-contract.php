<?php
/**
 * Phase 5B source contract — staging validation + batch insight reassessment +
 * threshold audit, plus CLI/admin wiring and a leakage scan. No execution;
 * inspects file contents only.
 *
 * Run: php tests/test-phase5b-contract.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };
$src = function ($rel) use ($root) { $p = $root . '/' . $rel; return is_file($p) ? (string) file_get_contents($p) : ''; };

// ── Classes exist + bootstrapped ─────────────────────────────────────────────
$map = [
    'VES_Threshold_Audit_Service'       => 'includes/class-ves-threshold-audit-service.php',
    'VES_Insight_Reassessment_Service'  => 'includes/class-ves-insight-reassessment-service.php',
    'VES_Staging_Validation_Service'    => 'includes/class-ves-staging-validation-service.php',
];
$boot = $src('future-island-intelligence-suite.php');
foreach ($map as $cls => $file) {
    $ok(strpos($src($file), 'class ' . $cls) !== false, "{$cls} present");
    $ok(strpos($boot, basename($file)) !== false, "{$cls} bootstrapped");
}

// ── Part B — staging validation service ──────────────────────────────────────
$sv = $src('includes/class-ves-staging-validation-service.php');
$ok(strpos($sv, 'function run_validation(') !== false, 'B: run_validation present');
$ok(strpos($sv, 'function schema_health(') !== false && strpos($sv, 'function trend_health(') !== false
    && strpos($sv, 'function insight_health(') !== false && strpos($sv, 'function telemetry_health(') !== false,
    'B: schema/trend/insight/telemetry health methods present');
$ok(strpos($sv, 'function recommendation_summary(') !== false && strpos($sv, 'production_readiness_status') !== false,
    'B: recommendation_summary derives production_readiness_status');
$ok(strpos($sv, "READY = 'ready'") !== false && strpos($sv, "READY_WARN = 'ready_with_warnings'") !== false
    && strpos($sv, "NOT_READY = 'not_ready'") !== false, 'B: readiness status vocabulary defined');

// ── Part F — JSON-safe export summary ────────────────────────────────────────
$ok(strpos($sv, 'function export_summary(') !== false && strpos($sv, 'FORBIDDEN_EXPORT_KEYS') !== false
    && strpos($sv, 'function scrub_export(') !== false, 'F: export_summary scrubs forbidden keys');
$ok(strpos($sv, "'generated_at'") !== false && strpos($sv, "'plugin_version'") !== false
    && strpos($sv, "'blocking_issues'") !== false && strpos($sv, "'next_commands'") !== false,
    'F: export carries generated_at/plugin_version/blocking_issues/next_commands');

// ── Part D — reassessment service ────────────────────────────────────────────
$rs = $src('includes/class-ves-insight-reassessment-service.php');
$ok(strpos($rs, 'function find_candidates(') !== false && strpos($rs, 'function reassess_batch(') !== false
    && strpos($rs, 'function reassess_one(') !== false && strpos($rs, 'function build_status_recommendation(') !== false,
    'D: find_candidates/reassess_batch/reassess_one/build_status_recommendation present');
foreach (['keep_draft', 'eligible_for_review', 'eligible_for_approval_manual_only', 'needs_evidence', 'reject_unsupported', 'archive_stale', 'reassess_later'] as $r) {
    $ok(strpos($rs, "'" . $r . "'") !== false, "D: recommendation type {$r} defined");
}
$ok(strpos($rs, 'merge_insight_metadata') !== false, 'D: apply path writes metadata only (merge_insight_metadata)');
$ok(strpos($rs, 'promote_to_reviewed') !== false && strpos($rs, 'reject_insight') !== false, 'D: safe promotion/rejection gated through lifecycle');
// SAFETY: no auto-approve, no delete, never promote unsupported beyond draft.
$ok(strpos($rs, 'approve_insight') === false, 'D-SAFETY: reassessment NEVER calls approve_insight');
$ok(stripos($rs, 'delete_insight') === false && stripos($rs, '::delete(') === false, 'D-SAFETY: reassessment NEVER deletes');
$ok(preg_match('/promote_reviewed_safe\][^\n]*eligible_for_review[^\n]*draft/', $rs) === 1
    || (strpos($rs, "=== 'eligible_for_review'") !== false && strpos($rs, "=== 'draft'") !== false),
    'D-SAFETY: promotion requires review-eligible draft (never unsupported)');
$ok(strpos($rs, 'merge_insight_metadata') !== false, 'D-SAFETY: store metadata-only helper used');

// store metadata-only helper exists.
$store = $src('includes/class-ves-intelligence-store.php');
$ok(strpos($store, 'function merge_insight_metadata(') !== false, 'D: store exposes merge_insight_metadata (metadata-only, no status)');

// ── Part E — threshold audit ─────────────────────────────────────────────────
$ta = $src('includes/class-ves-threshold-audit-service.php');
$ok(strpos($ta, 'function audit_insight_thresholds(') !== false && strpos($ta, 'function audit_trend_thresholds(') !== false
    && strpos($ta, 'function suggest_threshold_warnings(') !== false, 'E: threshold audit methods present');

// ── Part C/H — CLI ───────────────────────────────────────────────────────────
$cli = $src('includes/class-ves-cli-trends.php');
$ok(strpos($cli, "add_command('ves validate-staging'") !== false, 'C: validate-staging CLI registered');
$ok(strpos($cli, "add_command('ves readiness-check'") !== false, 'C: readiness-check alias registered');
$ok(strpos($cli, "add_command('ves insight-reassess'") !== false, 'H: insight-reassess CLI registered');
$ok(strpos($cli, "'fail-on'") !== false && strpos($cli, "'format'") !== false && strpos($cli, 'workspace_id') !== false,
    'C: validate-staging supports --workspace_id/--format/--fail-on');
$ok(strpos($cli, "apply-metadata") !== false && strpos($cli, "promote-reviewed-safe") !== false
    && strpos($cli, "reject-unsupported") !== false, 'H: insight-reassess exposes apply-metadata/promote-reviewed-safe/reject-unsupported');
$ok(strpos($cli, 'DRY-RUN') !== false, 'H: insight-reassess is dry-run by default');
$ok(stripos($cli, "'approve'") === false && stripos($cli, '--approve') === false, 'H-SAFETY: NO approve flag in CLI');
$ok(strpos($cli, 'require_cap') !== false, 'C/H: new CLI commands are capability-gated');

// ── Part G — admin readiness panel ───────────────────────────────────────────
$ac = $src('includes/class-ves-admin-console.php');
$ok(strpos($ac, 'function render_readiness(') !== false && strpos($ac, 'render_readiness()') !== false, 'G: admin renders readiness panel');
$ok(strpos($ac, 'Production Readiness') !== false, 'G: panel titled Production Readiness');
$ok(strpos($ac, 'VES_Staging_Validation_Service::run_validation') !== false, 'G: panel uses run_validation (read-only)');
// Part I — no NEW admin write action introduced by the readiness panel.
$ok(strpos($ac, 'reassess_batch') === false && strpos($ac, 'promote_to_reviewed') === false
    && strpos($ac, 'approve_insight') === false, 'I: readiness panel adds NO admin write/approve action');

// ── Leakage scan across the three new services + admin panel ─────────────────
$leak_targets = [
    'includes/class-ves-staging-validation-service.php',
    'includes/class-ves-insight-reassessment-service.php',
    'includes/class-ves-threshold-audit-service.php',
];
foreach ($leak_targets as $f) {
    $c = $src($f);
    // No LLM/network calls.
    $ok(stripos($c, 'api.openai.com') === false && stripos($c, 'wp_remote') === false
        && stripos($c, 'chat/completions') === false && stripos($c, '/v1/responses') === false,
        "no LLM/network calls in {$f}");
    // No raw secret/payload field emission (allow FORBIDDEN_EXPORT_KEYS list which DEFENDS against them).
    $emits_raw = (bool) preg_match('/[\'"]raw_response[\'"]\s*=>/', $c)
        || (bool) preg_match('/[\'"]bearer[\'"]\s*=>/', $c)
        || (bool) preg_match('/[\'"]authorization[\'"]\s*=>/', $c)
        || (bool) preg_match('/[\'"]messages[\'"]\s*=>/', $c);
    $ok(!$emits_raw, "no raw_response/bearer/authorization/messages emitted in {$f}");
}

echo "\nPhase 5B contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
