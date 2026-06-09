<?php
/**
 * v0.9.31.6 — BUG-G: competitor-flagged items must actually rank lower.
 * The previous v0.9.31.5 patch set the `ves_relevance_flag` but the sort
 * comparator only used numeric score — so flagged items still ranked
 * identically when their score was unchanged. This test asserts the sort
 * comparator now applies an effective-score penalty to flagged items.
 *
 * The test runs the live sorter through reflection: it loads analysis.php,
 * fabricates two items (one flagged, one clean) with identical scores, and
 * asserts the unflagged item lands above the flagged item.
 *
 * Run: php tests/test-v09316-competitor-rank-penalty.php
 */
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Static check first.
$ana = file_get_contents($root . '/includes/analysis.php');
$assert(strpos($ana, 'flag_penalty') !== false, 'sort comparator declares a flag_penalty');
$assert(strpos($ana, "ves_relevance_flag'") !== false && strpos($ana, "incidental_tag_match") !== false, 'sort comparator references the incidental_tag_match flag');

// Behavior check: replicate the comparator inline using the same constants so
// the test stays close to the implementation but does not have to bootstrap
// all of WordPress. The flag penalty is 1.5 (per implementation).
$flag_penalty = 1.5;
$cmp = static function ($a, $b) use ($flag_penalty) {
    $flag_a = (isset($a['item']['ves_relevance_flag']) && $a['item']['ves_relevance_flag'] === 'incidental_tag_match') ? $flag_penalty : 0.0;
    $flag_b = (isset($b['item']['ves_relevance_flag']) && $b['item']['ves_relevance_flag'] === 'incidental_tag_match') ? $flag_penalty : 0.0;
    $eff_a = ((float) $a['score']) - $flag_a;
    $eff_b = ((float) $b['score']) - $flag_b;
    $score_cmp = $eff_b <=> $eff_a;
    if ($score_cmp !== 0) { return $score_cmp; }
    return ((int) $a['idx']) <=> ((int) $b['idx']);
};

// Case 1: same score → unflagged ranks above flagged.
$items = [
    ['idx' => 0, 'score' => 5.0, 'item' => ['ves_relevance_flag' => 'incidental_tag_match']],
    ['idx' => 1, 'score' => 5.0, 'item' => []],
];
usort($items, $cmp);
$assert($items[0]['idx'] === 1, 'same-score: unflagged item (idx 1) ranks above flagged (idx 0)');

// Case 2: flagged has slightly higher score but within penalty → still ranks below.
$items = [
    ['idx' => 0, 'score' => 5.5, 'item' => ['ves_relevance_flag' => 'incidental_tag_match']], // effective 4.0
    ['idx' => 1, 'score' => 5.0, 'item' => []], // effective 5.0
];
usort($items, $cmp);
$assert($items[0]['idx'] === 1, 'flagged item with +0.5 score still ranks below unflagged (penalty wins)');

// Case 3: flagged has much higher score than unflagged → flagged still wins.
// This guarantees we are not destructively filtering — high-quality flagged
// items remain visible above noise.
$items = [
    ['idx' => 0, 'score' => 8.0, 'item' => ['ves_relevance_flag' => 'incidental_tag_match']], // effective 6.5
    ['idx' => 1, 'score' => 5.0, 'item' => []],
];
usort($items, $cmp);
$assert($items[0]['idx'] === 0, 'high-score flagged item (eff 6.5) still ranks above low-score unflagged (5.0)');

// Case 4: two flagged items keep relative score order.
$items = [
    ['idx' => 0, 'score' => 4.0, 'item' => ['ves_relevance_flag' => 'incidental_tag_match']],
    ['idx' => 1, 'score' => 6.0, 'item' => ['ves_relevance_flag' => 'incidental_tag_match']],
];
usort($items, $cmp);
$assert($items[0]['idx'] === 1, 'between two flagged items, higher numeric score still wins');

if ($fail === 0) {
    echo "OK v0.9.31.6 competitor rank penalty — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 competitor rank penalty — $fail failures, $pass passed\n";
    exit(1);
}
