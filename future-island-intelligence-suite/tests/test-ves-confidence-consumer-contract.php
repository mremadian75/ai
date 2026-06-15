<?php
/**
 * Phase 20 — VES_Confidence consumer-contract test (test-only).
 *
 * Proves the Phase-18 mapper works against the REAL field shapes that current
 * producers emit, so future wiring cannot silently drift from actual data:
 *
 *   - VES_Insight_Records::prepare() rows (Brand-Audit-derived insights) carry
 *     `confidence`, `evidence_validation_status`, `unsupported_by_evidence`,
 *     `evidence_ref_count`, `evidence_refs`, `invalid_evidence_refs`, `meta`, …
 *   - FIDTF_Relevance_Filter::score() rows carry `score`, `matched_core_keywords`,
 *     `is_relevant`, `relevance_tier`, `recommended_use`, `evidence_tier`,
 *     `marketing_allowed_use`, `client_safe`, `reason`, …
 *
 * These shapes are reproduced here verbatim (field names match the producers).
 * The mapper is exercised as an EXTERNAL consumer would; no product code is
 * called and the mapper remains dormant in production.
 *
 * Run: php tests/test-ves-confidence-consumer-contract.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/includes/class-ves-confidence.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$REQUIRED_KEYS = ['band','numeric','source_module','native','evidence_status','allowed_action','client_facing','reason','limitations'];
$CANON = ['validated','supported','directional','exploratory','hypothesis','insufficient','blocked'];
$RANK = ['validated'=>7,'supported'=>6,'directional'=>5,'exploratory'=>4,'hypothesis'=>3,'insufficient'=>2,'blocked'=>1];

$shape_ok = function (array $out, array $input) use ($REQUIRED_KEYS, $CANON) {
    foreach ($REQUIRED_KEYS as $k) { if (!array_key_exists($k, $out)) { return false; } }
    if (!in_array($out['band'], $CANON, true)) { return false; }
    if ($out['native'] !== $input) { return false; }
    if (!is_bool($out['client_facing'])) { return false; }
    if (!is_array($out['limitations'])) { return false; }
    if ($out['numeric'] !== null && !is_numeric($out['numeric'])) { return false; }
    return true;
};

// numeric must never imply a band stronger than the final band.
$numeric_consistent = function (array $out) use ($RANK) {
    if ($out['numeric'] === null) { return true; }
    $n = (float) $out['numeric'];
    // Lower numeric bound implied by each band (mirrors VES_Confidence::bands()).
    $min = ['validated'=>0.82,'supported'=>0.62,'directional'=>0.45,'exploratory'=>0.30,'hypothesis'=>0.0,'insufficient'=>0.0,'blocked'=>0.0];
    // The numeric must not reach the floor of any band STRONGER than the final band.
    foreach (['validated','supported','directional','exploratory'] as $b) {
        if ($RANK[$b] > $RANK[$out['band']] && $n >= $min[$b]) { return false; }
    }
    return true;
};

// ─────────────────────────────────────────────────────────────────────────────
// 1–2. Real VES_Insight_Records::prepare() row shape (unsupported evidence)
//      Field names match the producer exactly.
// ─────────────────────────────────────────────────────────────────────────────
$insight_unsupported = [
    'id' => 101,
    'workspace_id' => 5,
    'user_id' => 9,
    'run_id' => 'ba_run_1',
    'insight_key' => 'k1',
    'title' => 'Brand owns the premium territory',
    'insight_type' => 'insight',
    'summary' => 'Claim with no surviving evidence refs.',
    'evidence_refs' => [],
    'pattern_ids' => [],
    'confidence' => 0.90,                 // AI-proposed high confidence
    'business_impact' => 'high',
    'urgency' => 'medium',
    'status' => 'proposed',
    'created_by' => 'ai_writeback',
    'created_at' => '2026-05-01 00:00:00',
    'updated_at' => '2026-05-01 00:00:00',
    'meta' => ['unsupported_by_evidence' => true, 'evidence_ref_count' => 0, 'invalid_evidence_refs' => ['ev_FAKE']],
    'unsupported_by_evidence' => true,
    'invalid_evidence_refs' => ['ev_FAKE'],
    'evidence_ref_count' => 0,
    'evidence_validation_status' => 'unsupported',
];
$base_u = VES_Confidence::from_brand_audit($insight_unsupported);
$ok($shape_ok($base_u, $insight_unsupported), '1: insight prepare() row → mapper output has full shape, native preserved');
$ok($base_u['band'] === 'validated' || $base_u['band'] === 'supported', '1: base (confidence 0.90) reads high before evidence overlay (band=' . $base_u['band'] . ')');
$final_u = VES_Confidence::from_evidence_validation($insight_unsupported, $base_u);
$ok($final_u['band'] === 'hypothesis', '2: unsupported evidence overlay downgrades to hypothesis (got ' . $final_u['band'] . ')');
$ok($final_u['evidence_status'] === 'unsupported', '2: evidence_status=unsupported');
$ok($final_u['native'] === $insight_unsupported, '2: overlay preserves native row');

// Weak-evidence insight (single valid ref) → cap directional.
$insight_weak = $insight_unsupported;
$insight_weak['evidence_refs'] = ['ev_1_aaaaaa'];
$insight_weak['evidence_ref_count'] = 1;
$insight_weak['unsupported_by_evidence'] = false;
$insight_weak['evidence_validation_status'] = 'weak';
$insight_weak['confidence'] = 0.90;
$base_w = VES_Confidence::from_brand_audit($insight_weak);
$final_w = VES_Confidence::from_evidence_validation($insight_weak, $base_w);
$ok($final_w['band'] === 'directional', '2: weak evidence (1 ref) overlay caps to directional (got ' . $final_w['band'] . ')');

// Supported-evidence insight (>=2 refs) → no downgrade of a supported base.
$insight_supported = $insight_unsupported;
$insight_supported['evidence_refs'] = ['ev_1_aaaaaa','ev_2_bbbbbb'];
$insight_supported['evidence_ref_count'] = 2;
$insight_supported['unsupported_by_evidence'] = false;
$insight_supported['evidence_validation_status'] = 'supported';
$insight_supported['confidence'] = 0.70;
$base_s = VES_Confidence::from_brand_audit($insight_supported);
$final_s = VES_Confidence::from_evidence_validation($insight_supported, $base_s);
$ok($RANK[$final_s['band']] <= $RANK[$base_s['band']], '2: supported evidence never upgrades the base band');
$ok($numeric_consistent($final_u) && $numeric_consistent($final_w) && $numeric_consistent($final_s), '2: numeric stays consistent with downgraded band across insight cases');

// ─────────────────────────────────────────────────────────────────────────────
// 3–5. Real FIDTF_Relevance_Filter::score() output shapes
// ─────────────────────────────────────────────────────────────────────────────
// Case D (Phase 14A): high-engagement generic, no core match, weak, not client_safe.
$dtf_caseD = [
    'score' => 53.85,
    'matched_keywords' => [],
    'matched_core_keywords' => [],
    'matched_expansion_keywords' => [],
    'reason' => 'Engagement signal present. Matches provider discovery query.',
    'evidence_quality' => ['score' => 40],
    'is_relevant' => true,
    'relevance_tier' => 'weak',
    'recommended_use' => 'show',
    'signal_type' => 'engagement signal',
    'evidence_tier' => 'weak',
    'marketing_allowed_use' => 'background_only',
    'client_safe' => false,
    'risk_note' => '',
    'view_count_missing' => false,
    'below_min_views' => false,
];
$d = VES_Confidence::from_dtf($dtf_caseD);
$ok($shape_ok($d, $dtf_caseD), '3: DTF score() row → mapper output has full shape, native preserved');
$ok(in_array($d['band'], ['exploratory','insufficient'], true), '4: Case-D weak high-engagement → exploratory or lower (got ' . $d['band'] . ')');
$ok(!in_array($d['band'], ['supported','validated'], true), '4: Case-D is NEVER supported/validated');

// Raw-score-only: very high score but weak tier / not client_safe must not inflate.
$dtf_score_only = [
    'score' => 99.0,
    'matched_core_keywords' => [],
    'is_relevant' => true,
    'relevance_tier' => 'weak',
    'recommended_use' => 'show',
    'evidence_tier' => 'weak',
    'marketing_allowed_use' => 'background_only',
    'client_safe' => false,
];
$so = VES_Confidence::from_dtf($dtf_score_only);
$ok(!in_array($so['band'], ['supported','validated'], true), '5: raw score 99 alone cannot produce supported/validated (got ' . $so['band'] . ')');

// Genuinely strong/supported DTF rows (for contrast — proves mapper is not just flooring everything).
$dtf_strong = [
    'score' => 80.0, 'matched_core_keywords' => ['beverage'], 'is_relevant' => true,
    'relevance_tier' => 'direct', 'recommended_use' => 'show', 'evidence_tier' => 'strong',
    'marketing_allowed_use' => 'claim_support_after_review', 'client_safe' => true,
];
$ds = VES_Confidence::from_dtf($dtf_strong);
$ok($ds['band'] === 'validated', '5: strong + client_safe → validated (proves mapper is not over-suppressing) (got ' . $ds['band'] . ')');

// ─────────────────────────────────────────────────────────────────────────────
// 6. numeric/band consistency + native availability
// ─────────────────────────────────────────────────────────────────────────────
foreach (['u'=>$final_u,'w'=>$final_w,'s'=>$final_s,'d'=>$d,'so'=>$so,'ds'=>$ds] as $tag => $out) {
    $ok($numeric_consistent($out), "6: numeric does not imply a stronger band than final band ($tag)");
}
$ok(($base_u['native']['confidence'] ?? null) === 0.90, '6: native raw confidence remains available under native (0.90)');
$ok(($d['native']['score'] ?? null) === 53.85, '6: native raw DTF score remains available under native (53.85)');

// ─────────────────────────────────────────────────────────────────────────────
// 7. Output-shape contract across every produced result
// ─────────────────────────────────────────────────────────────────────────────
$all = ['base_u'=>$base_u,'final_u'=>$final_u,'final_w'=>$final_w,'final_s'=>$final_s,'caseD'=>$d,'score_only'=>$so,'strong'=>$ds];
$shape_all = true;
foreach ($all as $tag => $out) {
    foreach ($REQUIRED_KEYS as $k) { if (!array_key_exists($k, $out)) { $shape_all = false; echo "    (missing key $k in $tag)\n"; } }
}
$ok($shape_all, '7: every mapper result exposes the full standard output shape');

// client-facing consistency: non-client bands must report client_facing=false.
$cf_ok = true;
foreach ($all as $out) {
    if (in_array($out['band'], ['exploratory','hypothesis','insufficient','blocked'], true) && $out['client_facing'] !== false) { $cf_ok = false; }
}
$ok($cf_ok, '7: exploratory/hypothesis/insufficient/blocked are never client_facing');

echo "\nVES_Confidence consumer-contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
