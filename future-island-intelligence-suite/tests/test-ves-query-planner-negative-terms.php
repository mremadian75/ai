<?php
/**
 * Sprint 1 (Part A) — Google-only negative-term query exclusion.
 *
 * Executes the REAL VES_Query_Planner to prove that negative/exclusion terms are
 * appended to the raw Google Search / Google News query strings ONLY, using safe
 * Google syntax (-term and -"multi word"), and that no other provider, payload
 * field, or objective text is affected.
 *
 * Adds tests only. No production behavior is asserted that was not captured by
 * executing the planner.
 *
 * Run: php tests/test-ves-query-planner-negative-terms.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

if (!function_exists('current_user_can')) { function current_user_can($c) { return false; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('remove_accents')) { function remove_accents($v) { return $v; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($v) { return (string) $v; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0, $depth = 512) { return json_encode($v, $flags); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return strip_tags((string) $v); } }

require_once dirname(__DIR__) . '/includes/class-ves-query-planner.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$flatten = function ($payload) {
    $out = '';
    array_walk_recursive($payload, function ($v) use (&$out) { $out .= ' ' . (is_scalar($v) ? (string) $v : ''); });
    return strtolower($out);
};
$build = function ($module, $platform, $actor, $request) {
    $n = VES_Query_Planner::normalize_request_fields($module, $platform, $request);
    return VES_Query_Planner::build_actor_payload($module, $platform, $actor, $n);
};

$negatives = ['casino', 'crypto', 'homebrew equipment'];

// ── 1. Google News appends exclusions to the raw query ───────────────────────
$gn = $build('google', 'google_news', 'easyapi/google-news-scraper', [
    'queryTerms' => 'vegan protein', 'language' => '', 'limit' => 20, 'negative_terms' => $negatives,
]);
$ok(strpos($gn['query'], '-casino') !== false, '1: google_news query includes -casino');
$ok(strpos($gn['query'], '-crypto') !== false, '1: google_news query includes -crypto');
$ok(strpos($gn['query'], 'vegan protein') === 0, '1: google_news query preserves the original terms first');
$ok(is_array($gn['queries']) && strpos($gn['queries'][0], '-casino') !== false, '1: google_news queries[] entries also carry exclusions');

// ── 2. Multi-word negative phrase is quoted correctly ────────────────────────
$ok(strpos($gn['query'], '-"homebrew equipment"') !== false, '2: multi-word negative phrase is quoted as -"homebrew equipment"');

// ── 3. Google Search path applies the same exclusions ────────────────────────
$gs = $build('google', 'google_search', 'apify/google-search-scraper', [
    'queryTerms' => 'vegan protein', 'language' => '', 'limit' => 20, 'negative_terms' => $negatives,
]);
$ok(strpos($gs['query'], '-casino') !== false && strpos($gs['query'], '-"homebrew equipment"') !== false, '3: google_search query includes single and quoted multi-word exclusions');

// ── 4. Empty negative_terms leaves the query unchanged ───────────────────────
$gn0 = $build('google', 'google_news', 'easyapi/google-news-scraper', [
    'queryTerms' => 'vegan protein', 'language' => '', 'limit' => 20,
]);
$ok($gn0['query'] === 'vegan protein', '4: empty negative_terms leaves the query unchanged');

// ── 5. Non-Google providers are unchanged (no exclusion injected) ────────────
$reddit = $build('social', 'reddit', 'trudax/reddit-scraper-lite', [
    'queryTerms' => "vegan protein\nmocktail", 'language' => '', 'limit' => 20, 'negative_terms' => $negatives,
]);
$ok(strpos($flatten($reddit), '-casino') === false && strpos($flatten($reddit), 'casino') === false, '5: reddit payload carries no exclusion/negative terms');
$ok(in_array('vegan protein', $reddit['searches'] ?? [], true), '5: reddit search terms remain the plain query terms');

$tiktok = $build('social', 'tiktok', 'clockworks/tiktok-scraper', [
    'hashtags' => '#veganprotein', 'language' => '', 'limit' => 20, 'negative_terms' => $negatives,
]);
$ok(strpos($flatten($tiktok), 'casino') === false, '5: tiktok payload carries no exclusion/negative terms');

// ── 6. No unsupported payload field is added ─────────────────────────────────
$gn_allowed = ['query', 'queries', 'gl', 'hl', 'lr', 'time_period', 'max_items'];
$ok(empty(array_diff(array_keys($gn), $gn_allowed)), '6: google_news payload adds no field outside its existing contract');
$gs_allowed = ['queries', 'query', 'countryCode', 'languageCode', 'maxResults'];
$ok(empty(array_diff(array_keys($gs), $gs_allowed)), '6: google_search payload adds no field outside its existing contract');

// ── 7. business_objective / searchContext never leak into the query ──────────
$gn_leak = $build('google', 'google_news', 'easyapi/google-news-scraper', [
    'queryTerms' => 'vegan protein', 'language' => '', 'limit' => 20, 'negative_terms' => $negatives,
    'businessObjective' => 'understand the competitive positioning to inform launch',
    'searchContext' => 'spanish market 2026',
]);
$ok(strpos(strtolower($gn_leak['query']), 'competitive positioning') === false, '7: business_objective does not leak into the google query');
$ok(strpos(strtolower($gn_leak['query']), 'spanish market 2026') === false, '7: searchContext does not leak into the google query');

// ── 8. Injected exclusions are capped to a small safe number (<=6) ───────────
$many = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9'];
$gn_cap = $build('google', 'google_news', 'easyapi/google-news-scraper', [
    'queryTerms' => 'vegan protein', 'language' => '', 'limit' => 20, 'negative_terms' => $many,
]);
$exclusion_count = substr_count($gn_cap['query'], ' -');
$ok($exclusion_count === 6, '8: at most 6 exclusions are injected (got ' . $exclusion_count . ')');

// ── 9. Guard: empty/blank base query never becomes a negative-only query ─────
$ok(VES_Query_Planner::apply_google_query_exclusions('', ['casino']) === '', '9: empty base query returns empty (no negative-only query)');
$ok(VES_Query_Planner::apply_google_query_exclusions('   ', ['casino']) === '   ', '9: whitespace-only base query is returned unchanged');
$ok(strpos(VES_Query_Planner::apply_google_query_exclusions('', ['casino', 'crypto']), '-casino') === false, '9: empty base query never yields an exclusion token');
$ok(VES_Query_Planner::apply_google_query_exclusions('vegan protein', []) === 'vegan protein', '9: no negative terms leaves a real query unchanged');

// ── 9a. Google payload builders never emit a negative-only query ─────────────
// When there are no usable query terms, the query field must be absent (dropped
// by non_empty), not an exclusion-only string.
$gn_empty = $build('google', 'google_news', 'easyapi/google-news-scraper', [
    'queryTerms' => '', 'language' => '', 'limit' => 20, 'negative_terms' => $negatives,
]);
$ok(!isset($gn_empty['query']) || strpos($gn_empty['query'], '-casino') !== 0, '9a: google_news never dispatches a negative-only query');
$ok(empty($gn_empty['queries']), '9a: google_news has no negative-only queries[] when there are no base terms');

echo "\nGoogle negative-term exclusion (Part A): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
