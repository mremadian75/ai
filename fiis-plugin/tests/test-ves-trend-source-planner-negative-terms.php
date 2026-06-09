<?php
/**
 * Sprint 1 (Part A, correction) — VES_Trend_Source_Planner negative-term wiring.
 *
 * Executes the REAL VES_Trend_Source_Planner::build() (the actual trend path that
 * consumes credit) together with the real VES_Trend_Query_Expander and
 * VES_Query_Planner, and verifies that Google Search/News source inputs carry the
 * Google exclusion syntax while NO other source (Trends, Semrush, social) does,
 * and that no negative-only query or new payload field is produced.
 *
 * Adds tests only. No production behavior is asserted that was not captured by
 * executing the planner. The planner resolves actor slugs from each source's
 * fallback_slug, so no VES_Config stub is required.
 *
 * Run: php tests/test-ves-trend-source-planner-negative-terms.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return strip_tags((string) $v); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($v) { return (string) $v; } }
if (!function_exists('remove_accents')) { function remove_accents($v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0) { return json_encode($v, $f); } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }

require_once dirname(__DIR__) . '/includes/class-ves-query-planner.php';
require_once dirname(__DIR__) . '/includes/class-ves-trend-query-expander.php';
require_once dirname(__DIR__) . '/includes/class-ves-trend-source-planner.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$flatten = function ($payload) {
    $arr = (array) $payload;
    $out = '';
    array_walk_recursive($arr, function ($v) use (&$out) { $out .= ' ' . (is_scalar($v) ? (string) $v : ''); });
    return strtolower($out);
};

// 'beer' triggers a domain pack whose negative terms include single words
// (casino/crypto) and multi-word phrases (homebrew equipment / alcohol abuse).
$request = [
    'topic'      => 'beer',
    'market'     => 'ES',
    'country'    => 'ES',
    'language'   => 'es',
    'duration'   => '7d',
    'budget_level' => 'balanced',     // enables semrush_keyword_context
    'source_mode'  => 'news_search_social', // enables search + news + social
    'businessObjective' => 'understand the competitive positioning to inform launch',
];

$plan = VES_Trend_Source_Planner::build($request);
$payloads = $plan['payloads'] ?? [];

// Confirm the expander actually produced negatives (test is not vacuous).
$negatives = (array) ($plan['negative_terms'] ?? []);
$ok(!empty($negatives), 'pre: expander produced negative_terms for this request');

$gs = $payloads['google_search_freshness']['input'] ?? null;
$gn = $payloads['google_news_freshness']['input'] ?? null;
$gt = $payloads['google_trends_interest']['input'] ?? null;
$sem = $payloads['semrush_keyword_context']['input'] ?? null;

// ── 1. Google Search input carries Google exclusion syntax ───────────────────
$ok(is_array($gs), '1: google_search_freshness source is present in the plan');
if (is_array($gs)) {
    $ok(isset($gs['query']) && strpos($gs['query'], ' -') !== false, '1: google_search query string includes exclusion syntax');
    $ok(isset($gs['queries']) && is_array($gs['queries']) && strpos($flatten($gs['queries']), ' -') !== false, '1: google_search queries[] include exclusion syntax');
    $ok(strpos($flatten($gs), '-"') !== false, '1: multi-word negative phrase is quoted in google_search input');
}

// ── 2. Google News input carries exclusions when news source is included ─────
$ok(is_array($gn), '2: google_news_freshness source is present in the plan');
if (is_array($gn)) {
    $ok(isset($gn['query']) && strpos($gn['query'], ' -') !== false, '2: google_news query string includes exclusion syntax');
    $ok(isset($gn['queries']) && strpos($flatten($gn['queries']), ' -') !== false, '2: google_news queries[] include exclusion syntax');
}

// ── 3. Google Trends does NOT receive negative exclusions ────────────────────
$ok(is_array($gt), '3: google_trends_interest source is present in the plan');
if (is_array($gt)) {
    $ok(strpos($flatten($gt), ' -') === false, '3: google_trends input contains no exclusion syntax');
    $ok(strpos($flatten($gt), 'casino') === false, '3: google_trends input is free of negative terms');
}

// ── 4. Semrush + social sources do NOT receive negative exclusions ───────────
$ok(is_array($sem), '4: semrush_keyword_context source is present (budget=balanced)');
if (is_array($sem)) {
    $ok(strpos($flatten($sem), ' -') === false && strpos($flatten($sem), 'casino') === false, '4: semrush input carries no exclusions/negatives');
}
$social_keys = ['tiktok_topic_videos', 'youtube_topic_videos', 'reddit_topic_posts', 'instagram_topic_posts'];
$social_clean = true; $social_seen = 0;
foreach ($social_keys as $sk) {
    if (!isset($payloads[$sk]['input'])) { continue; }
    $social_seen++;
    if (strpos($flatten($payloads[$sk]['input']), ' -') !== false || strpos($flatten($payloads[$sk]['input']), 'casino') !== false) { $social_clean = false; }
}
$ok($social_clean, '4: no social source input carries exclusions/negatives (' . $social_seen . ' social sources checked)');

// ── 5. No source input adds a payload field named negative_terms ─────────────
$no_neg_field = true;
foreach ($payloads as $key => $p) {
    if (is_array($p['input'] ?? null) && array_key_exists('negative_terms', $p['input'])) { $no_neg_field = false; }
}
$ok($no_neg_field, '5: no source input adds a negative_terms payload field');

// ── 6. No Google query is a negative-only query (must start with a real term) ─
$no_negative_only = true;
foreach (['google_search_freshness', 'google_news_freshness'] as $key) {
    $input = $payloads[$key]['input'] ?? null;
    if (!is_array($input)) { continue; }
    foreach ([$input['query'] ?? ''] as $q) {
        if ($q !== '' && strpos(ltrim($q), '-') === 0) { $no_negative_only = false; }
    }
    foreach ((array) ($input['queries'] ?? []) as $q) {
        if ($q !== '' && strpos(ltrim($q), '-') === 0) { $no_negative_only = false; }
    }
}
$ok($no_negative_only, '6: no Google query is a negative-only (exclusion-only) string');

echo "\nVES_Trend_Source_Planner negative-term wiring (Part A): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
