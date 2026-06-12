<?php
require_once __DIR__ . '/test-fidtf-v0329-all-actor-live-qa-hardening.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$generic_adapter = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.30
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.30
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.30

$rc = new ReflectionClass('FIDTF_Generic_Apify_Live_Adapter');
$build = $rc->getMethod('build_actor_input');
$build->setAccessible(true);
$validate = $rc->getMethod('validate_actor_input');
$validate->setAccessible(true);
$payload = [
    'source_plan' => [
        'queries' => ['mahou'],
        'hashtags' => ['mahou'],
        'max_items' => 5,
    ],
    'request_context' => [
        'keywords' => ['mahou'],
        'market' => 'Spain',
        'language' => 'es_ES',
        'date_range' => 'last_7_days',
    ],
    'limits' => ['max_items' => 5],
];

$reddit = new FIDTF_Generic_Apify_Live_Adapter('reddit');
$reddit_input = $build->invoke($reddit, 'reddit', $payload);
ok(!array_key_exists('type', $reddit_input), 'Reddit post search input avoids ambiguous type field');
ok(($reddit_input['searchPosts'] ?? false) === true, 'Reddit post search uses documented searchPosts flag');
ok(isset($reddit_input['maxCommunitiesCount'], $reddit_input['maxUserCount'], $reddit_input['maxLeaderBoardItems']), 'Reddit input includes documented count caps for non-post entities');
$reddit_validation = $validate->invoke($reddit, 'reddit', 'reddit_trudax_lite', $reddit_input);
ok(!empty($reddit_validation['ok']), 'Reddit hardened input still validates');

$trends = new FIDTF_Generic_Apify_Live_Adapter('google_trends');
$trends_input = $build->invoke($trends, 'google_trends', $payload);
ok(($trends_input['proxyConfiguration']['useApifyProxy'] ?? false) === true, 'Google Trends input uses Apify proxy');
ok(empty($trends_input['proxyConfiguration']['apifyProxyGroups'] ?? []), 'Google Trends input uses standard Apify proxy by default');
$trends_validation = $validate->invoke($trends, 'google_trends', 'google_trends_data_xplorer', $trends_input);
ok(!empty($trends_validation['ok']), 'Google Trends hardened input still validates');

$news = new FIDTF_Generic_Apify_Live_Adapter('google_news');
$news_input = $build->invoke($news, 'google_news', $payload);
ok(($news_input['region_language'] ?? '') === 'ES:es', 'Google News normalizes region_language from market + locale');
ok(empty($news_input['proxyConfiguration']['apifyProxyGroups'] ?? []), 'Google News input uses standard Apify proxy by default');
$news_validation = $validate->invoke($news, 'google_news', 'google_news_data_xplorer', $news_input);
ok(!empty($news_validation['ok']), 'Google News hardened input still validates');

$news_item = FIDTF_Normalizer::normalize('google_news', [
    'title' => 'Mahou activation',
    'url' => 'https://example.com/news',
    'description' => 'A local beer activation.',
    'image' => ['src' => 'https://example.com/news.jpg'],
    'metadata' => ['keyword' => 'mahou'],
]);
ok($news_item['media']['thumbnail_url'] === 'https://example.com/news.jpg', 'Google News normalizer extracts nested image.src after hardening');

$ig_item = FIDTF_Normalizer::normalize('instagram', [
    'shortCode' => 'ABC123',
    'caption' => 'Mahou terrace ritual',
    'url' => 'https://www.instagram.com/p/ABC123/',
    'images' => ['https://example.com/ig.jpg'],
]);
ok($ig_item['media']['thumbnail_url'] === 'https://example.com/ig.jpg', 'Instagram normalizer extracts scalar images.0');

ok(strpos($generic_adapter, 'fetch_dataset_total_count($dataset_id)') !== false, 'generic adapter can persist provider total count before dataset item fetch');
ok(strpos($generic_adapter, 'fidtf_google_actor_proxy_configuration') !== false, 'generic adapter allows Google actor proxy override');
ok(strpos($normalizer_src, 'images.0') !== false, 'normalizer supports scalar image arrays');

fwrite(STDOUT, "FIDTF v0.3.30 live contract diagnostics and provider total counts checks passed: {$checks} / {$checks}\n");
