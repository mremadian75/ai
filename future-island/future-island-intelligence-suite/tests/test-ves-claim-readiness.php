<?php
/**
 * Sprint 2 (Part A + Part D) — VES_Claim_Readiness deterministic classifier.
 *
 * Pure mapping tests (no WordPress runtime, no AI). Verifies the canonical
 * claim_readiness / claim_strength_score / claim_limitations / recommended_language
 * / minimum_required_action contract, plus the recommendation-support vocabulary.
 *
 * Run: php tests/test-ves-claim-readiness.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/includes/class-ves-evidence-quality.php';
require_once dirname(__DIR__) . '/includes/class-ves-claim-readiness.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$has = function (array $arr, $v) { return in_array($v, $arr, true); };

// A reusable, fully-attributed strong record factory.
$strong_record = function ($source, $i = 1) {
    return [
        'source' => $source,
        'url' => "https://{$source}.test/a{$i}",
        'published_at' => '2026-05-0' . $i,
        'author' => 'reporter' . $i,
        'text' => 'Quarterly beverage category sales increased across major retailers nationwide in spring',
        'metrics' => ['views' => 9000, 'likes' => 120],
        'evidence_refs' => ["ev_{$i}{$i}_a1b2c3"],
    ];
};

// ── 1. Strong multi-source evidence => ready / assertive / can_state ──────────
$multi = [$strong_record('news', 1), $strong_record('blog', 2), $strong_record('forum', 3)];
$r1 = VES_Claim_Readiness::assess($multi);
$ok($r1['claim_readiness'] === 'ready', '1: strong multi-source => ready');
$ok($r1['recommended_language'] === 'assertive', '1: ready => assertive language');
$ok($r1['minimum_required_action'] === 'can_state', '1: ready => can_state');
$ok($r1['claim_strength_score'] >= 70, '1: ready => high strength score');

// ── 2. One good source only => cautious / state_with_limitations ─────────────
$one = [$strong_record('news', 1)];
$r2 = VES_Claim_Readiness::assess($one);
$ok($r2['claim_readiness'] === 'cautious', '2: single good source => cautious');
$ok($r2['minimum_required_action'] === 'state_with_limitations', '2: cautious => state_with_limitations');
$ok($has($r2['claim_limitations'], 'single_source_only'), '2: single_source_only limitation present');
$ok($has($r2['claim_limitations'], 'low_source_diversity'), '2: low_source_diversity limitation present');

// ── 3. Weak / no-url / no-date evidence => hypothesis_only ───────────────────
$weak = [
    ['source' => 'x', 'text' => 'some short note'],
    ['source' => 'y', 'author' => 'anon'],
];
$r3 = VES_Claim_Readiness::assess($weak);
$ok($r3['claim_readiness'] === 'hypothesis_only', '3: weak/no-url/no-date => hypothesis_only');
$ok($r3['recommended_language'] === 'exploratory', '3: hypothesis_only => exploratory');
$ok($r3['minimum_required_action'] === 'frame_as_hypothesis', '3: hypothesis_only => frame_as_hypothesis');
$ok($has($r3['claim_limitations'], 'weak_evidence_quality'), '3: weak_evidence_quality limitation present');

// ── 4. Metric-only bundle => not ready for a broad claim ─────────────────────
$metric_bundle = [
    ['source' => 'gtrends', 'url' => 'https://g.test/m1', 'metrics' => ['search_interest' => 80]],
    ['source' => 'gads', 'url' => 'https://g.test/m2', 'metrics' => ['search_volume' => 5000]],
];
$r4 = VES_Claim_Readiness::assess($metric_bundle); // default broad/general claim
$ok(in_array($r4['claim_readiness'], ['cautious', 'hypothesis_only'], true), '4: metric-only broad claim => cautious or hypothesis_only (not ready)');
$ok($r4['claim_readiness'] !== 'ready', '4: metric-only is never ready for a broad claim');
$ok($has($r4['claim_limitations'], 'metric_only_support'), '4: metric_only_support limitation present');
// Same bundle DOES support an explicit metric/signal claim more readily.
$r4b = VES_Claim_Readiness::assess($metric_bundle, ['claim_type' => 'metric']);
$ok(!$has($r4b['claim_limitations'], 'metric_only_support'), '4: metric claim is not penalised for metric_only_support');

// ── 5. Opinion-only bundle supports perception, not factual ──────────────────
$opinion_bundle = [
    ['source' => 'reddit', 'url' => 'https://r.test/o1', 'published_at' => '2026-05-01', 'author' => 'u1', 'text' => 'Honest review: en mi opinión this product is overrated and disappointing'],
    ['source' => 'tiktok', 'url' => 'https://t.test/o2', 'published_at' => '2026-05-02', 'author' => 'u2', 'text' => 'I think the brand is overrated, recommend skipping it honestly'],
];
$r5_fact = VES_Claim_Readiness::assess($opinion_bundle); // broad/factual default
$r5_perc = VES_Claim_Readiness::assess($opinion_bundle, ['claim_type' => 'perception']);
$rank = ['unsupported' => 0, 'hypothesis_only' => 1, 'cautious' => 2, 'ready' => 3];
$ok($r5_fact['claim_readiness'] === 'hypothesis_only', '5: opinion-only factual claim => hypothesis_only');
$ok($has($r5_fact['claim_limitations'], 'opinion_only_support'), '5: opinion_only_support limitation present for factual claim');
$ok(in_array($r5_perc['claim_readiness'], ['cautious', 'ready'], true), '5: opinion-only perception claim is allowed (cautious/ready)');
$ok($rank[$r5_perc['claim_readiness']] > $rank[$r5_fact['claim_readiness']], '5: perception claim is stronger than factual claim for the same opinion bundle');
$ok(!$has($r5_perc['claim_limitations'], 'opinion_only_support'), '5: perception claim is not penalised for opinion_only_support');

// ── 6. No evidence => unsupported / do_not_state ─────────────────────────────
$r6 = VES_Claim_Readiness::assess([]);
$ok($r6['claim_readiness'] === 'unsupported', '6: no evidence => unsupported');
$ok($r6['recommended_language'] === 'avoid', '6: unsupported => avoid language');
$ok($r6['minimum_required_action'] === 'do_not_state', '6: unsupported => do_not_state');
$ok($has($r6['claim_limitations'], 'insufficient_evidence'), '6: insufficient_evidence limitation present');

// ── 7. Noisy evidence caps readiness ─────────────────────────────────────────
$noisy = [
    $strong_record('news', 1),
    $strong_record('blog', 2),
    ['source' => 'spam', 'url' => 'https://s.test/n', 'text' => 'viral clip', 'metrics' => ['views' => 100], 'discard_reason' => 'negative_term:casino'],
];
$r7 = VES_Claim_Readiness::assess($noisy);
$ok($r7['claim_readiness'] !== 'ready', '7: noisy evidence caps readiness below ready');
$ok($has($r7['claim_limitations'], 'noisy_evidence_present'), '7: noisy_evidence_present limitation present');

// ── 8. Input is not mutated ──────────────────────────────────────────────────
$before = $multi;
VES_Claim_Readiness::assess($multi);
$ok($multi === $before, '8: assess does not mutate the input bundle');

// ── 9. Malformed input handled safely ────────────────────────────────────────
$ok(VES_Claim_Readiness::assess('not-an-array')['claim_readiness'] === 'unsupported', '9: non-array input => unsupported safely');
$ok(VES_Claim_Readiness::assess([null, 'x', 42])['claim_readiness'] === 'unsupported', '9: list of non-arrays => unsupported safely');
$ok(is_array(VES_Claim_Readiness::assess(['text' => null, 'metrics' => 'oops'])['claim_limitations']), '9: malformed single record handled without error');

// ── 10. Output contract: exact canonical keys / vocabularies ─────────────────
$keys = array_keys($r1);
$ok($keys === ['claim_readiness', 'claim_strength_score', 'claim_limitations', 'recommended_language', 'minimum_required_action'], '10: assess returns exactly the five contract keys');
$ok(in_array($r1['claim_readiness'], VES_Claim_Readiness::tiers(), true), '10: readiness uses canonical tier vocabulary');
$ok(in_array($r1['recommended_language'], VES_Claim_Readiness::languages(), true), '10: language uses canonical vocabulary');
$ok(in_array($r1['minimum_required_action'], VES_Claim_Readiness::actions(), true), '10: action uses canonical vocabulary');
$ok(is_int($r1['claim_strength_score']) && $r1['claim_strength_score'] >= 0 && $r1['claim_strength_score'] <= 100, '10: strength score is an int 0..100');

// ── 11. Part D — recommendation safety vocabulary ────────────────────────────
$rec_strong = VES_Claim_Readiness::classify_recommendation($multi);
$rec_weak   = VES_Claim_Readiness::classify_recommendation($weak);
$rec_none   = VES_Claim_Readiness::classify_recommendation([]);
$ok($rec_strong['recommendation_support'] === 'evidence_backed', '11: strong evidence recommendation => evidence_backed');
$ok(in_array($rec_weak['recommendation_support'], ['partially_supported', 'speculative'], true), '11: weak evidence recommendation => partially_supported or speculative');
$ok($rec_none['recommendation_support'] === 'unsupported', '11: no evidence recommendation => unsupported');
$ok($has($rec_none['recommendation_limitations'], 'unsupported_recommendation'), '11: unsupported recommendation is flagged');
$ok(in_array($rec_strong['recommendation_support'], VES_Claim_Readiness::recommendation_states(), true), '11: recommendation vocabulary is canonical');
$single_rec = VES_Claim_Readiness::classify_recommendation($one);
$ok($single_rec['recommendation_support'] === 'partially_supported', '11: single-source recommendation => partially_supported');

echo "\nVES_Claim_Readiness classifier (Part A + Part D): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
