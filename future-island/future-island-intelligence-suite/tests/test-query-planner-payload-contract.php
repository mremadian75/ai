<?php
/**
 * Phase 26B — Query planner payload contract.
 *
 * Executes the REAL VES_Query_Planner (normalize_request_fields + build_actor_payload)
 * and VES_Source_Adapter_Registry / prune_payload_for_actor_contract across
 * several platforms, and pins the CURRENT payload-shaping behavior. This is an
 * executable replacement for the string-grep style of query-planner coverage.
 *
 * It proves the doctrine the audit relies on:
 *   - payloads stay source-specific (TikTok != Reddit != Google),
 *   - the business objective / analysis goal does NOT leak into provider search
 *     term fields,
 *   - language/market are normalized into the per-actor fields,
 *   - actor "forbidden" fields are stripped by the contract pruner.
 *
 * This file adds tests only. No query-planning behavior, scoring, or routing is
 * changed. Assertions were captured by executing the planner, not invented.
 *
 * Run: php tests/test-query-planner-payload-contract.php
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
require_once dirname(__DIR__) . '/includes/class-ves-source-adapter-registry.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$flatten = function ($payload) {
    // Flatten all scalar values (recursively) into a single lowercase blob so we
    // can assert a term is/ isn't present anywhere in the provider payload.
    $out = '';
    array_walk_recursive($payload, function ($v) use (&$out) { $out .= ' ' . (is_scalar($v) ? (string) $v : ''); });
    return strtolower($out);
};

$build = function ($module, $platform, $actor, $request) {
    $n = VES_Query_Planner::normalize_request_fields($module, $platform, $request);
    return [VES_Query_Planner::build_actor_payload($module, $platform, $actor, $n), $n];
};

$base_request = [
    'limit'    => 40,
    'language' => 'es',
    'country'  => 'ES',
];

// ───────────────────────────────────────────────────────────────────────────
// 1. Payloads are source-specific (each platform produces its own fields)
// ───────────────────────────────────────────────────────────────────────────
[$tiktok]  = $build('social', 'tiktok', 'clockworks/tiktok-scraper', $base_request + ['hashtags' => '#veganprotein']);
[$insta]   = $build('social', 'instagram', 'apify/instagram-scraper', $base_request + ['hashtags' => '#veganprotein']);
[$gnews]   = $build('google', 'google_news', 'easyapi/google-news-scraper', $base_request + ['queryTerms' => 'vegan protein']);
[$gtrends] = $build('google', 'google_trends', 'apify/google-trends-scraper', $base_request + ['queryTerms' => 'vegan protein']);
[$youtube] = $build('social', 'youtube', 'streamers/youtube-scraper', $base_request + ['queryTerms' => 'vegan protein']);

$ok(isset($tiktok['videoSearchSorting']) && isset($tiktok['resultsPerPage']), '1: tiktok payload carries tiktok-specific fields (videoSearchSorting/resultsPerPage)');
$ok(!isset($tiktok['gl']) && !isset($tiktok['query']) && !isset($tiktok['directUrls']), '1: tiktok payload does not carry google/instagram fields');
$ok(isset($insta['directUrls']) && isset($insta['resultsType']), '1: instagram payload carries instagram-specific fields (directUrls/resultsType)');
$ok(!isset($insta['gl']) && !isset($insta['videoSearchSorting']), '1: instagram payload does not carry google/tiktok fields');
$ok(isset($gnews['gl']) && isset($gnews['hl']), '1: google_news payload carries gl/hl');
$ok(!isset($gnews['directUrls']) && !isset($gnews['videoSearchSorting']), '1: google_news payload does not carry social fields');
$ok(isset($gtrends['geo']) && isset($gtrends['timeRange']), '1: google_trends payload carries geo/timeRange');
$ok(isset($youtube['maxResults']) && isset($youtube['sortBy']), '1: youtube payload carries youtube-specific fields (maxResults/sortBy)');

// ───────────────────────────────────────────────────────────────────────────
// 2. Reddit search fields are populated from query terms (and are reddit-shaped)
// ───────────────────────────────────────────────────────────────────────────
// Use language '' so English terms are not dropped by the conservative filter.
[$reddit, $reddit_norm] = $build('social', 'reddit', 'trudax/reddit-scraper-lite', [
    'queryTerms'        => "vegan protein\nmocktail",
    'businessObjective' => 'understand the competitive positioning to inform our launch strategy',
    'searchContext'     => 'spanish market 2026',
    'limit'             => 40,
    'language'          => '',
]);
$ok(isset($reddit['searches']) && isset($reddit['queries']), '2: reddit payload exposes searches/queries when terms are provided');
$ok(in_array('vegan protein', $reddit['searches'] ?? [], true) && in_array('mocktail', $reddit['searches'] ?? [], true), '2: reddit search terms come from the query terms');
$ok(isset($reddit['searchPosts']) && isset($reddit['sort']) && isset($reddit['time']), '2: reddit payload carries reddit-specific controls (searchPosts/sort/time)');

// ───────────────────────────────────────────────────────────────────────────
// 3. Business objective / analysis goal does NOT leak into provider terms
// ───────────────────────────────────────────────────────────────────────────
$reddit_blob = $flatten($reddit);
$ok(strpos($reddit_blob, 'competitive positioning') === false, '3: objective text does not leak into reddit provider payload');
$ok(strpos($reddit_blob, 'launch strategy') === false, '3: objective phrase does not leak into reddit provider payload');
$ok(strpos($reddit_blob, 'spanish market 2026') === false, '3: search context does not leak into reddit provider payload');
// The doctrine: objective is retained for AI context, separate from provider terms.
$ok(($reddit_norm['business_objective'] ?? '') !== '', '3: objective is retained in the normalized request (for AI context), not discarded');
$ok(!in_array('understand the competitive positioning to inform our launch strategy', $reddit_norm['scraper_query_terms'] ?? [], true), '3: objective is kept out of scraper_query_terms');

// ───────────────────────────────────────────────────────────────────────────
// 4. Language/market normalized into the per-actor fields (current behavior)
// ───────────────────────────────────────────────────────────────────────────
$ok(($gtrends['geo'] ?? '') === 'ES', '4: country ES normalized into google_trends geo=ES');
$ok(($gnews['gl'] ?? '') === 'es' && ($gnews['hl'] ?? '') === 'es', '4: country/language normalized into google_news gl/hl=es');
$ok(($youtube['country'] ?? '') === 'ES' && ($youtube['language'] ?? '') === 'es', '4: youtube carries normalized country=ES / language=es');
$ok(($tiktok['proxyCountryCode'] ?? '') === 'ES', '4: tiktok carries normalized proxyCountryCode=ES');

// ───────────────────────────────────────────────────────────────────────────
// 5. Adapter contract: forbidden fields are stripped by the pruner
// ───────────────────────────────────────────────────────────────────────────
$adapter = VES_Source_Adapter_Registry::adapter_for('google_trends', 'data_xplorer/google-trends-trending-now');
$ok(in_array('keyword', $adapter['forbidden_fields'] ?? [], true), '5: adapter registry marks keyword as forbidden for trending-now');
$dirty_payload = ['country' => 'ES', 'keyword' => 'leak', 'searchTerms' => ['leak'], 'geo' => 'ES', 'maxItems' => 40];
$pruned = VES_Query_Planner::prune_payload_for_actor_contract('google_trends', 'data_xplorer/google-trends-trending-now', $dirty_payload);
$ok(!isset($pruned['keyword']) && !isset($pruned['searchTerms']) && !isset($pruned['geo']), '5: pruner removes forbidden fields (keyword/searchTerms/geo)');
$ok(($pruned['country'] ?? '') === 'ES' && ($pruned['maxItems'] ?? null) === 40, '5: pruner keeps supported fields (country/maxItems)');

echo "\nQuery planner payload contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
