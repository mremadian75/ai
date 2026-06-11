<?php
/**
 * Phase 18 — VES_Confidence unified confidence mapper tests.
 *
 * Pure mapping/derivation tests. No product behavior is exercised or changed.
 * The mapper has no WordPress dependencies, so this test only defines ABSPATH
 * and loads the class directly (no bootstrap shim required).
 *
 * Run: php tests/test-ves-confidence-mapper.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/includes/class-ves-confidence.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$CANON = ['validated','supported','directional','exploratory','hypothesis','insufficient','blocked'];
$REQUIRED_KEYS = ['band','numeric','source_module','native','evidence_status','allowed_action','client_facing','reason','limitations'];

$shape_ok = function (array $out, array $input) use ($REQUIRED_KEYS, $CANON) {
    foreach ($REQUIRED_KEYS as $k) { if (!array_key_exists($k, $out)) { return false; } }
    if (!in_array($out['band'], $CANON, true)) { return false; }
    if ($out['native'] !== $input) { return false; }
    if (!is_bool($out['client_facing'])) { return false; }
    if (!is_array($out['limitations'])) { return false; }
    return true;
};

// ── A. Output shape ─────────────────────────────────────────────────────────
$a_in = ['level' => 'validated', 'confidence' => 0.9, 'limitations' => ['x']];
$a = VES_Confidence::from_brand_audit($a_in);
$ok($shape_ok($a, $a_in), 'A: output has all required keys, canonical band, native preserved');
$ok(VES_Confidence::bands() && count(VES_Confidence::bands()) === 7, 'A: bands() returns the 7 canonical bands');
$ok($a['native'] === $a_in, 'A: native equals input verbatim');
$ok($a['limitations'] === ['x'], 'A: limitations passed through');

// ── B. Brand Audit labels ─────────────────────────────────────────────────────
$b = [
    'validated'      => 'validated',
    'directional'    => 'directional',
    'exploratory'    => 'exploratory',
    'limited'        => 'insufficient',
    'technical_only' => 'blocked',
];
foreach ($b as $label => $expected) {
    $r = VES_Confidence::from_brand_audit(['level' => $label]);
    $ok($r['band'] === $expected, "B: brand-audit level '$label' => $expected (got {$r['band']})");
}

// ── C. Brand Audit numeric (no label) ──────────────────────────────────────────
$c = [
    '0.90' => ['confidence' => 0.90, 'exp' => 'validated'],
    '0.70' => ['confidence' => 0.70, 'exp' => 'supported'],
    '0.50' => ['confidence' => 0.50, 'exp' => 'directional'],
    '0.35' => ['confidence' => 0.35, 'exp' => 'exploratory'],
    '0.20' => ['confidence' => 0.20, 'exp' => 'hypothesis'],
];
foreach ($c as $name => $case) {
    $r = VES_Confidence::from_brand_audit(['confidence' => $case['confidence']]);
    $ok($r['band'] === $case['exp'], "C: brand-audit numeric $name => {$case['exp']} (got {$r['band']})");
}
$ok(VES_Confidence::from_brand_audit(['confidence' => 0.0])['band'] === 'insufficient', 'C: numeric 0 => insufficient');
$ok(VES_Confidence::from_brand_audit([])['band'] === 'insufficient', 'C: null/empty => insufficient');

// ── D. Trend mode + score ──────────────────────────────────────────────────────
$d_mode = [
    'validated'             => 'validated',
    'directional'           => 'directional',
    'exploratory'           => 'exploratory',
    'insufficient_evidence' => 'insufficient',
];
foreach ($d_mode as $mode => $expected) {
    $r = VES_Confidence::from_trend(['confidence_mode' => $mode]);
    $ok($r['band'] === $expected, "D: trend mode '$mode' => $expected (got {$r['band']})");
}
$d_score = ['9' => ['s'=>9,'exp'=>'supported'], '6' => ['s'=>6,'exp'=>'directional'], '4' => ['s'=>4,'exp'=>'exploratory'], '1' => ['s'=>1,'exp'=>'insufficient']];
foreach ($d_score as $name => $case) {
    $r = VES_Confidence::from_trend(['confidence_score' => $case['s']]);
    $ok($r['band'] === $case['exp'], "D: trend score $name => {$case['exp']} (got {$r['band']})");
}
$r = VES_Confidence::from_trend(['confidence_mode' => 'exploratory', 'confidence_score' => 9]);
$ok($r['band'] === 'exploratory', 'D: mode exploratory + score 9 stays exploratory (score cannot raise above weaker mode)');

// ── E. DTF mappings (representative Phase 14A golden-set outputs) ───────────────
$e_strong = VES_Confidence::from_dtf(['evidence_tier' => 'strong', 'client_safe' => true, 'recommended_use' => 'show', 'score' => 80, 'is_relevant' => true]);
$ok($e_strong['band'] === 'validated', "E: strong + client_safe => validated (got {$e_strong['band']})");
$e_support = VES_Confidence::from_dtf(['evidence_tier' => 'support', 'client_safe' => true, 'recommended_use' => 'show', 'score' => 67, 'is_relevant' => true]);
$ok($e_support['band'] === 'supported', "E: support + client_safe => supported (got {$e_support['band']})");
$e_hide = VES_Confidence::from_dtf(['evidence_tier' => 'noise', 'recommended_use' => 'hide', 'client_safe' => false, 'score' => 49.2, 'is_relevant' => false]);
$ok($e_hide['band'] === 'insufficient', "E: semantic-only hide/noise => insufficient (got {$e_hide['band']})");
$e_weak = VES_Confidence::from_dtf(['evidence_tier' => 'weak', 'client_safe' => false, 'recommended_use' => 'show', 'score' => 53.85, 'is_relevant' => true]);
$ok(in_array($e_weak['band'], ['exploratory','insufficient'], true), "E: high-engagement weak/!client_safe => exploratory|insufficient (got {$e_weak['band']})");
$ok(!in_array($e_weak['band'], ['supported','validated'], true), 'E: high-engagement weak is NEVER supported/validated');
$e_excl = VES_Confidence::from_dtf(['marketing_allowed_use' => 'excluded', 'evidence_tier' => 'noise', 'recommended_use' => 'hide']);
$ok($e_excl['band'] === 'blocked', "E: marketing_allowed_use=excluded => blocked (got {$e_excl['band']})");
// raw score alone must not grant supported/validated
$e_score_only = VES_Confidence::from_dtf(['score' => 99, 'evidence_tier' => 'weak', 'client_safe' => false, 'recommended_use' => 'show', 'is_relevant' => true]);
$ok(!in_array($e_score_only['band'], ['supported','validated'], true), 'E: raw score 99 alone does not grant supported/validated');

// ── F. Evidence overlay ─────────────────────────────────────────────────────────
$base_validated = VES_Confidence::from_brand_audit(['level' => 'validated']);
$f1 = VES_Confidence::from_evidence_validation(['evidence_validation_status' => 'unsupported', 'evidence_ref_count' => 0], $base_validated);
$ok($f1['band'] === 'hypothesis', "F: validated + unsupported evidence => hypothesis (got {$f1['band']})");
$ok($f1['evidence_status'] === 'unsupported', 'F: overlay sets evidence_status=unsupported');

$base_supported = VES_Confidence::from_brand_audit(['confidence' => 0.70]);
$f2 = VES_Confidence::from_evidence_validation(['evidence_validation_status' => 'weak', 'evidence_ref_count' => 1], $base_supported);
$ok($f2['band'] === 'directional', "F: supported + weak evidence => directional (got {$f2['band']})");

$base_directional = VES_Confidence::from_brand_audit(['level' => 'directional']);
$f3 = VES_Confidence::from_evidence_validation(['evidence_validation_status' => 'supported', 'evidence_ref_count' => 3], $base_directional);
$ok($f3['band'] === 'directional', "F: directional + supported evidence stays directional (got {$f3['band']})");

$f4 = VES_Confidence::from_evidence_validation(['unsupported_by_evidence' => true], $base_validated);
$ok($f4['band'] === 'hypothesis', "F: unsupported_by_evidence=true => hypothesis (got {$f4['band']})");

// overlay never upgrades: supported evidence on a weak base must NOT raise it
$base_explore = VES_Confidence::from_brand_audit(['level' => 'exploratory']);
$f5 = VES_Confidence::from_evidence_validation(['evidence_validation_status' => 'supported', 'evidence_ref_count' => 5], $base_explore);
$ok($f5['band'] === 'exploratory', "F: supported evidence does NOT upgrade an exploratory base (got {$f5['band']})");

// standalone overlay (no base)
$f6 = VES_Confidence::from_evidence_validation(['evidence_validation_status' => 'unsupported', 'evidence_ref_count' => 0]);
$ok($f6['band'] === 'hypothesis', "F: standalone unsupported => hypothesis (got {$f6['band']})");

// ── G. Client-facing / action rules ─────────────────────────────────────────────
$bands_meta = VES_Confidence::bands();
foreach (['validated','supported','directional'] as $cf) {
    $ok($bands_meta[$cf]['client_facing'] === true, "G: $cf is client-facing");
}
foreach (['exploratory','hypothesis','insufficient','blocked'] as $ncf) {
    $r = VES_Confidence::from_brand_audit(['confidence' => 0.0]); // insufficient sample for action checks below
    $ok($bands_meta[$ncf]['client_facing'] === false, "G: $ncf is NOT client-facing");
}
$ok($bands_meta['blocked']['allowed_action'] === 'none', 'G: blocked allowed_action = none');
$ok($bands_meta['insufficient']['allowed_action'] === 'collect_more_data', 'G: insufficient allowed_action = collect_more_data');
$ok($bands_meta['hypothesis']['allowed_action'] === 'hypothesis_only', 'G: hypothesis allowed_action = hypothesis_only');
// confirm the output wiring matches the band metadata
$blocked_out = VES_Confidence::from_brand_audit(['level' => 'technical_only']);
$ok($blocked_out['allowed_action'] === 'none' && $blocked_out['client_facing'] === false, 'G: blocked output wires action=none, client_facing=false');

// ── H. Monotonic downgrade invariant (matrix) ────────────────────────────────────
$rank = ['validated'=>7,'supported'=>6,'directional'=>5,'exploratory'=>4,'hypothesis'=>3,'insufficient'=>2,'blocked'=>1];
$base_cases = [
    VES_Confidence::from_brand_audit(['level' => 'validated']),
    VES_Confidence::from_brand_audit(['level' => 'directional']),
    VES_Confidence::from_brand_audit(['confidence' => 0.70]),
    VES_Confidence::from_trend(['confidence_mode' => 'validated']),
];
$overlays = [
    ['evidence_validation_status' => 'supported', 'evidence_ref_count' => 3],
    ['evidence_validation_status' => 'weak', 'evidence_ref_count' => 1],
    ['evidence_validation_status' => 'unsupported', 'evidence_ref_count' => 0],
    ['unsupported_by_evidence' => true],
    ['evidence_validation_status' => 'error'],
];
$invariant_holds = true;
foreach ($base_cases as $bc) {
    foreach ($overlays as $ov) {
        $res = VES_Confidence::from_evidence_validation($ov, $bc);
        if ($rank[$res['band']] > $rank[$bc['band']]) { $invariant_holds = false; }
    }
}
$ok($invariant_holds, 'H: evidence overlay NEVER returns a stronger band than the base (monotonic downgrade)');

echo "\nVES_Confidence mapper: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
