<?php
require_once __DIR__ . '/test-fidtf-v0330-live-contract-diagnostics-and-provider-total-counts.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$tiktok_src = file_get_contents($root . '/includes/providers/class-fidtf-provider-tiktok.php');
$generic_src = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.31
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.31
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.31

$payload = [
    'source_plan' => [
        'queries' => ['coffee'],
        'hashtags' => ['coffee'],
        'max_items' => 3,
        'sort_strategy' => 'relevance_then_engagement',
    ],
    'request_context' => [
        'keywords' => ['coffee'],
        'market' => 'Spain',
        'language' => 'es_ES',
        'date_range' => 'last_7_days',
    ],
    'limits' => ['max_items' => 3],
];

$tiktok_input = FIDTF_Provider_TikTok::build_clockworks_input('coffee', ['coffee'], [], [], 3, 'relevance_then_engagement', 'ES', 'last_7_days');
ok(($tiktok_input['videoSearchSorting'] ?? '') === 'MOST_RELEVANT', 'Clockworks TikTok uses live enum videoSearchSorting');
ok(!array_key_exists('searchSorting', $tiktok_input), 'Clockworks TikTok no longer sends removed searchSorting field');
ok(!array_key_exists('searchDatePosted', $tiktok_input), 'Clockworks TikTok no longer sends legacy numeric searchDatePosted field');
ok(($tiktok_input['videoSearchDateFilter'] ?? '') === 'PAST_WEEK', 'Clockworks TikTok maps date filter to live enum PAST_WEEK');
ok(strpos($tiktok_src, 'MOST_RELEVANT') !== false && strpos($tiktok_src, 'PAST_WEEK') !== false, 'TikTok provider source contains live enum contract');

$rc = new ReflectionClass('FIDTF_Generic_Apify_Live_Adapter');
$build = $rc->getMethod('build_actor_input');
$build->setAccessible(true);
$validate = $rc->getMethod('validate_actor_input');
$validate->setAccessible(true);

$ig = new FIDTF_Generic_Apify_Live_Adapter('instagram');
$ig_input = $build->invoke($ig, 'instagram', $payload);
ok(isset($ig_input['directUrls'][0]) && $ig_input['directUrls'][0] === 'https://www.instagram.com/explore/tags/coffee/', 'Instagram hashtag input uses direct hashtag URL for post evidence');
ok(!isset($ig_input['search']), 'Instagram hashtag post evidence path no longer uses search directory mode');
$ig_validation = $validate->invoke($ig, 'instagram', 'instagram_apify_official', $ig_input);
ok(!empty($ig_validation['ok']), 'Instagram direct hashtag URL input validates');

$trends = new FIDTF_Generic_Apify_Live_Adapter('google_trends');
$trends_input = $build->invoke($trends, 'google_trends', $payload);
ok(array_key_exists('enableTrendingSearches', $trends_input) && $trends_input['enableTrendingSearches'] === false, 'Google Trends keyword mode explicitly disables trending search default');
ok(($trends_input['predefinedTimeframe'] ?? '') === 'now 7-d', 'Google Trends last_7_days maps to live timeframe enum');
ok(($trends_input['geo'] ?? '') === 'ES', 'Google Trends keeps requested Spain geo in keyword mode');
ok(empty($trends_input['proxyConfiguration']['apifyProxyGroups'] ?? []), 'Google Trends default proxy avoids forced residential group');
$trends_validation = $validate->invoke($trends, 'google_trends', 'google_trends_data_xplorer', $trends_input);
ok(!empty($trends_validation['ok']), 'Google Trends keyword input validates with explicit enableTrendingSearches=false');

$trends_bad = $trends_input;
unset($trends_bad['enableTrendingSearches']);
$bad_validation = $validate->invoke($trends, 'google_trends', 'google_trends_data_xplorer', $trends_bad);
ok(empty($bad_validation['ok']) && in_array('google_trends_keyword_mode_must_disable_trending_searches', $bad_validation['errors'], true), 'Google Trends validation catches silent trending-default bug');

$news = new FIDTF_Generic_Apify_Live_Adapter('google_news');
$news_input = $build->invoke($news, 'google_news', $payload);
ok(empty($news_input['proxyConfiguration']['apifyProxyGroups'] ?? []), 'Google News default proxy avoids forced residential group');

ok(strpos($generic_src, 'enableTrendingSearches') !== false, 'generic adapter source documents Google Trends mode switch');
ok(strpos($generic_src, 'instagram_hashtag_slug') !== false, 'generic adapter source includes Instagram hashtag URL builder');

fwrite(STDOUT, "FIDTF v0.3.31 live actor contract fixes checks passed: {$checks} / {$checks}\n");
