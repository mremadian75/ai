<?php
/**
 * Source contract — the legacy admin approve action must call the evidence gate
 * before update_status_by_id('approved'), keep its nonce + capability checks, and
 * leave reject/archive/supersede unchanged. No execution; inspects file contents.
 *
 * Run: php tests/test-legacy-approval-gate-contract.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };
$src = function ($rel) use ($root) { $p = $root . '/' . $rel; return is_file($p) ? (string) file_get_contents($p) : ''; };

$admin = $src('includes/class-ves-admin.php');
$rec   = $src('includes/class-ves-insight-records.php');

// ── Gate helpers exist on the legacy class ───────────────────────────────────
$ok(strpos($rec, 'function has_supporting_evidence(') !== false, 'gate: has_supporting_evidence() present');
$ok(strpos($rec, 'function can_approve_legacy_insight(') !== false, 'gate: can_approve_legacy_insight() present');
$ok(strpos($rec, 'function legacy_approval_health(') !== false, 'gate: legacy_approval_health() present');
// Deterministic, no LLM / no network in the legacy class additions.
$ok(stripos($rec, 'api.openai.com') === false && stripos($rec, 'wp_remote') === false && stripos($rec, 'chat/completions') === false, 'gate: no LLM/network calls in legacy class');

// ── Admin handler gates approve BEFORE the status update ─────────────────────
$ok(strpos($admin, 'can_approve_legacy_insight') !== false, 'handler: calls the evidence gate');
$gatePos = strpos($admin, 'can_approve_legacy_insight');
$updPos  = strpos($admin, 'update_status_by_id');
$ok($gatePos !== false && $updPos !== false && $gatePos < $updPos, 'handler: gate is evaluated BEFORE update_status_by_id');
$ok(strpos($admin, '$approval_blocked') !== false && strpos($admin, '!$approval_blocked') !== false, 'handler: status update is skipped when approval is blocked');
$ok(strpos($admin, 'No supporting evidence') !== false || strpos($admin, 'no supporting evidence was found') !== false, 'handler: shows a clear failure message (not silent)');

// Gate must apply ONLY to approve_insight (reject/archive/supersede untouched).
$ok(strpos($admin, "\$action_type === 'approve_insight'") !== false, 'handler: gate scoped to approve_insight only');
$ok(strpos($admin, "'reject_insight' => 'rejected'") !== false
    && strpos($admin, "'archive_insight' => 'archived'") !== false
    && strpos($admin, "'supersede_insight' => 'superseded'") !== false, 'handler: reject/archive/supersede status map unchanged');

// ── Security: nonce + capability preserved on the handler ────────────────────
$ok(strpos($admin, "check_admin_referer('ves_memory_admin_action')") !== false, 'security: nonce check preserved');
$ok(strpos($admin, "current_user_can('manage_options')") !== false, 'security: capability check preserved');

// ── Safety: no auto-approval introduced; canonical lifecycle untouched here ──
// Strip // line comments first so the safety comment ("never auto-approves") does
// not itself trip the scan — we only care about actual auto-approval CODE.
$admin_code = preg_replace('!//.*$!m', '', $admin);
$ok(stripos((string) $admin_code, 'auto_approve') === false && stripos((string) $admin_code, 'auto-approve') === false, 'safety: no auto-approval code path');
$ok(strpos($admin, 'update_insight_status') === false, 'safety: legacy handler does not touch the canonical store status mutator');

// ── Leakage: no raw evidence text / secrets surfaced in the new code path ────
$ok(strpos($admin, 'evidence_count') !== false, 'diag: block logs evidence_count (a number), not raw text');
foreach (['raw_response', 'api_key', 'bearer', 'authorization'] as $needle) {
    $ok(stripos($rec, $needle) === false, "leakage: '{$needle}' absent from legacy class additions");
}

echo "\nLegacy approval gate contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
