<?php
/**
 * Sprint 1 (Part B) — VES_Evidence_Quality deterministic classifier.
 *
 * Pure mapping tests (no WordPress runtime, no AI). Verifies the canonical
 * evidence_quality_tier / evidence_kind / evidence_limitations contract against
 * representative items. Assertions were captured by executing the classifier.
 *
 * Run: php tests/test-ves-evidence-quality.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/includes/class-ves-evidence-quality.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$has = function (array $arr, $v) { return in_array($v, $arr, true); };

$TIERS = ['strong', 'usable', 'weak', 'noisy', 'insufficient'];
$KINDS = ['fact', 'signal', 'metric', 'opinion', 'claim', 'unknown'];

// ── 1. Fully-attributed factual item => strong / fact, no limitations ────────
$full = [
    'url' => 'https://x.test/a', 'published_at' => '2026-05-01', 'author' => 'bob',
    'text' => 'Quarterly beverage category sales increased across major retailers in spring',
    'metrics' => ['views' => 9000, 'likes' => 120],
];
$rf = VES_Evidence_Quality::classify($full);
$ok($rf['evidence_quality_tier'] === 'strong', '1: full url/date/author/text/metrics => strong tier');
$ok($rf['evidence_kind'] === 'fact', '1: fully attributed factual item => kind fact');
$ok($rf['evidence_limitations'] === [], '1: full item reports no limitations');

// ── 2. Missing url + date => weaker tier with explicit limitations ───────────
$miss = [
    'author' => 'bob',
    'text' => 'Quarterly beverage category sales increased across major retailers in spring',
    'metrics' => ['views' => 9000],
];
$rm = VES_Evidence_Quality::classify($miss);
$ok($rm['evidence_quality_tier'] === 'usable', '2: missing url+date downgrades strong->usable');
$ok($has($rm['evidence_limitations'], 'missing_url') && $has($rm['evidence_limitations'], 'missing_date'), '2: limitations include missing_url and missing_date');

// ── 3. Negative-term noise => noisy tier + negative_term_noise limitation ─────
$noise = ['url' => 'https://x.test/n', 'text' => 'random viral clip', 'metrics' => ['views' => 1000], 'discard_reason' => 'negative_term:casino'];
$rn = VES_Evidence_Quality::classify($noise);
$ok($rn['evidence_quality_tier'] === 'noisy', '3: negative_term discard => noisy tier');
$ok($has($rn['evidence_limitations'], 'negative_term_noise'), '3: negative_term_noise limitation is recorded');

// ── 4. Metric-only item => kind metric (NOT fact) ────────────────────────────
$metric = ['url' => 'https://x.test/m', 'metrics' => ['search_interest' => 80], 'text' => ''];
$rmet = VES_Evidence_Quality::classify($metric);
$ok($rmet['evidence_kind'] === 'metric', '4: metric-only item is classified as metric, not fact');
$ok($rmet['evidence_kind'] !== 'fact', '4: metric-only item is never fact');

// ── 5. Opinion text => kind opinion/signal ───────────────────────────────────
$op = ['url' => 'https://x.test/o', 'published_at' => '2026-05-01', 'author' => 'ann', 'text' => 'Honest review: en mi opinión this product is overrated'];
$rop = VES_Evidence_Quality::classify($op);
$ok(in_array($rop['evidence_kind'], ['opinion', 'signal'], true), '5: opinion text => kind opinion (or signal)');
$ok($rop['evidence_kind'] === 'opinion', '5: opinion lexicon is detected as opinion');

// ── 6. Classifier returns only the metadata keys and does not mutate input ───
$before = $full;
$result = VES_Evidence_Quality::classify($full);
$ok($full === $before, '6: classify does not mutate the input item');
$ok(array_keys($result) === ['evidence_quality_tier', 'evidence_kind', 'evidence_limitations'], '6: classify returns exactly the three metadata keys');
$ok(in_array($result['evidence_quality_tier'], $TIERS, true) && in_array($result['evidence_kind'], $KINDS, true), '6: output uses only canonical tier/kind vocabulary');

// ── 7. Malformed / empty items are handled safely ────────────────────────────
$bad1 = VES_Evidence_Quality::classify('not-an-array');
$bad2 = VES_Evidence_Quality::classify([]);
$bad3 = VES_Evidence_Quality::classify(['metrics' => 'oops', 'text' => null]);
$ok($bad1['evidence_quality_tier'] === 'insufficient' && $bad1['evidence_kind'] === 'unknown', '7: non-array input => insufficient/unknown safely');
$ok($bad2['evidence_quality_tier'] === 'insufficient', '7: empty array => insufficient');
$ok(is_array($bad3['evidence_limitations']), '7: malformed metrics/text handled without error');

// ── 8. engagement-without-topic caps tier and is flagged ─────────────────────
$eng = ['url' => 'https://x.test/e', 'text' => 'wait for it', 'metrics' => ['views' => 5000000], 'matched_core_keywords' => [], 'is_relevant' => false];
$re = VES_Evidence_Quality::classify($eng);
$ok($has($re['evidence_limitations'], 'engagement_without_topic'), '8: engagement_without_topic limitation is recorded');
$ok(!in_array($re['evidence_quality_tier'], ['strong'], true), '8: engagement-without-topic is never strong');

echo "\nVES_Evidence_Quality classifier (Part B): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
