<?php
require_once __DIR__ . '/test-fidtf-v038-core-apify-dispatch-parity.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version is v0.3.9 or newer

$settings = FIDTF_Settings::get();
$settings['tiktok_default_limit'] = 25;
$settings['tiktok_max_limit'] = 50;
$settings['tiktok_min_views'] = 20000;
$settings['tiktok_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$settings['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
$GLOBALS['fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;

$actor_input = FIDTF_Provider_TikTok::build_actor_input_from_payload([
    'source_plan' => [
        'queries' => ['beverage', 'mahou', 'cerveza'],
        'hashtags' => ['#beer'],
        'max_items' => 300,
        'sort_strategy' => 'relevance_then_engagement',
    ],
    'request_context' => [
        'market' => 'Spain',
        'language' => 'es',
        'brand' => 'mahou',
        'keywords' => ['beverage', 'mahou'],
        'date_range' => 'last_30_days',
    ],
    'limits' => ['max_items' => 300],
]);

ok(!empty($actor_input['searchQueries']) && in_array('mahou', (array) $actor_input['searchQueries'], true), 'v0.3.12 discovery input prefers brand inside searchQueries');
ok(($actor_input['resultsPerPage'] ?? 0) === 50, 'v0.3.12 discovery input clamps requested count to admin max');
ok(($actor_input['proxyCountryCode'] ?? '') === 'ES', 'v0.3.12 discovery input sends ES proxy country');
ok(($actor_input['videoSearchSorting'] ?? '') === 'MOST_RELEVANT', 'TikTok discovery input sends live videoSearchSorting enum');
ok(array_key_exists('searchSection', $actor_input), 'v0.3.12 discovery input includes searchSection');
ok(!array_key_exists('searchDatePosted', $actor_input), 'TikTok discovery input omits legacy searchDatePosted');
foreach (['post_awemeId','listComments_awemeId','searchPosts_keyword','searchPosts_count','searchPosts_region'] as $forbidden) {
    ok(!array_key_exists($forbidden, $actor_input), 'v0.3.12 discovery input omits enrichment/Scraptik field ' . $forbidden);
}

ok(FIDTF_Settings::tiktok_min_views() === 20000, 'TikTok minimum view threshold defaults to 20000 when configured');

$low = FIDTF_Normalizer::normalize('tiktok', [
    'aweme_id' => 'low-1',
    'desc' => 'mahou beer format',
    'share_url' => 'https://www.tiktok.com/@x/video/1',
    'author' => ['unique_id' => 'creator'],
    'statistics' => ['play_count' => 19999, 'digg_count' => 1000],
]);
$low_score = FIDTF_Relevance_Filter::score($low, ['keywords' => ['mahou'], 'market' => 'Spain'], ['queries' => ['mahou']]);
ok(($low_score['recommended_use'] ?? '') === 'hide', 'TikTok item below 20000 views is hidden');
ok(strpos((string) ($low_score['reason'] ?? ''), 'minimum view threshold') !== false, 'low-view TikTok item explains threshold reason');

$high = FIDTF_Normalizer::normalize('tiktok', [
    'aweme_id' => 'high-1',
    'desc' => 'mahou beer format',
    'share_url' => 'https://www.tiktok.com/@x/video/2',
    'author' => ['unique_id' => 'creator'],
    'statistics' => ['play_count' => 20000, 'digg_count' => 1000, 'comment_count' => 100, 'share_count' => 50],
]);
$high_score = FIDTF_Relevance_Filter::score($high, ['keywords' => ['mahou'], 'market' => 'Spain'], ['queries' => ['mahou']]);
ok(strpos((string) ($high_score['reason'] ?? ''), 'minimum view threshold') === false, 'TikTok item at 20000 views is not rejected by threshold gate');

if (!class_exists('VES_Apify_Client_v039')) {
    // The VES_Apify_Client class is already defined by v0.3.8 test. Reuse it.
}
VES_Apify_Client::$calls = [];
$adapter = new FIDTF_Core_Apify_Client_Adapter();
$start = $adapter->start(['actor_id' => 'clockworks/tiktok-scraper', 'payload' => [], 'discovery_actor_id' => 'clockworks/tiktok-scraper'], $actor_input);
ok(!is_wp_error($start), 'Core adapter can start with v0.3.9 actor input');
$post_calls = array_values(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'POST'; }));
ok(count($post_calls) === 1, 'Core adapter made one POST call');
ok(is_string($post_calls[0]['body'] ?? null), 'Core adapter passes JSON string body like mother plugin');
$decoded = json_decode($post_calls[0]['body'], true);
ok(is_array($decoded) && !empty($decoded['searchQueries']), 'Core adapter JSON body contains discovery searchQueries');
ok(strpos((string) ($post_calls[0]['url'] ?? ''), 'waitForFinish=20') !== false || strpos((string) ($post_calls[0]['url'] ?? ''), 'waitForFinish=') !== false, 'Core adapter uses mother plugin run options with waitForFinish');

fwrite(STDOUT, "FIDTF v0.3.9 Scraptik social request contract checks passed: {$checks} / {$checks}\n");

$settings = FIDTF_Settings::get();
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$GLOBALS['fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$clockworks_input = FIDTF_Provider_TikTok::build_actor_input_from_payload([
    'source_plan' => ['queries' => ['mahou'], 'hashtags' => ['beer'], 'max_items' => 25, 'sort_strategy' => 'relevance_then_engagement'],
    'request_context' => ['market' => 'Spain', 'language' => 'es', 'brand' => 'mahou', 'date_range' => 'last_30_days'],
]);
ok(!array_key_exists('shouldDownloadVideos', $clockworks_input), 'Clockworks-compatible TikTok actor input omits explicit video download flag in discovery mode');
ok(($clockworks_input['downloadSubtitlesOptions'] ?? '') === 'NEVER_DOWNLOAD_SUBTITLES', 'Clockworks-compatible TikTok actor input keeps subtitles/transcription off in discovery mode');
ok(($clockworks_input['searchSection'] ?? '') === '/video', 'Clockworks-compatible TikTok actor input searches videos');
ok(($clockworks_input['proxyCountryCode'] ?? '') === 'ES', 'Clockworks-compatible TikTok actor input carries Spain proxy country');
ok(!array_key_exists('searchPosts_keyword', $clockworks_input), 'Clockworks-compatible TikTok actor input does not send Scraptik-only endpoint fields');
