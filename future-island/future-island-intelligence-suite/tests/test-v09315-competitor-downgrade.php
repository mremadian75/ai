<?php
/**
 * v0.9.31.6 — BUG-02: competitor authorship downgrade + incidental tag badge.
 * Static checks on the helper, the downgrade integration, the relevance-flag
 * surface, and the JS badge render.
 * Run: php tests/test-v09315-competitor-downgrade.php
 */
$root = dirname(__DIR__);
$ana  = file_get_contents($root . '/includes/analysis.php');
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.6') !== false, 'Version is 1.1.0');

// Helper declared
$assert(strpos($ana, 'function ves_detect_competitor_authorship(') !== false, 'ves_detect_competitor_authorship helper declared');

// Downgrade is applied inside classifier
$class_pos = strpos($ana, 'function ves_classify_social_item_relevance(');
$class_body = $class_pos !== false ? substr($ana, $class_pos, 6000) : '';
$assert(strpos($class_body, 'ves_detect_competitor_authorship(') !== false, 'classifier calls competitor detector');
$assert(strpos($class_body, "'category_relevant'") !== false && strpos($class_body, "'weak_match'") !== false, 'classifier still references the downgrade target buckets');
$assert(strpos($class_body, 'base_class') !== false, 'classifier uses two-step base_class downgrade path');

// Ranker exposes the flag
$rank_pos = strpos($ana, 'function ves_filter_and_rank_items_by_search_relevance(');
$rank_body = $rank_pos !== false ? substr($ana, $rank_pos, 3000) : '';
$assert(strpos($rank_body, "'ves_relevance_flag'") !== false, 'ranker sets ves_relevance_flag on items');
$assert(strpos($rank_body, 'incidental_tag_match') !== false, 'ranker uses incidental_tag_match value');

// JS badge surface
$assert(strpos($js, 'relevanceFlag:') !== false, 'JS card object exposes relevanceFlag');
$assert(strpos($js, 'ves-relevance-flag-incidental-tag') !== false, 'JS renders the incidental-tag CSS class');
$assert(strpos($js, 'Menci') !== false && strpos($js, 'hashtag') !== false, 'JS renders the Spanish badge text');

if ($fail === 0) {
    echo "OK v0.9.31.6 competitor downgrade — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 competitor downgrade — $fail failures, $pass passed\n";
    exit(1);
}
