<?php
require_once __DIR__ . '/test-fidtf-v0328-all-actor-contracts-and-normalizers.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$generic_adapter = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.29
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.29
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.29

$rc = new ReflectionClass('FIDTF_Generic_Apify_Live_Adapter');
$build = $rc->getMethod('build_actor_input');
$build->setAccessible(true);
$profile = $rc->getMethod('actor_profile');
$profile->setAccessible(true);
$validate = $rc->getMethod('validate_actor_input');
$validate->setAccessible(true);
$sanitize = $rc->getMethod('sanitize_items');
$sanitize->setAccessible(true);
$limit_from_actor_input = $rc->getMethod('limit_from_actor_input');
$limit_from_actor_input->setAccessible(true);

$payload = [
    'source_plan' => [
        'queries' => ['mahou'],
        'hashtags' => ['mahou'],
        'max_items' => 5,
    ],
    'request_context' => [
        'keywords' => ['mahou'],
        'market' => 'Spain',
        'language' => 'es',
        'date_range' => 'last_7_days',
    ],
    'limits' => ['max_items' => 5],
];

$adapter = new FIDTF_Generic_Apify_Live_Adapter('instagram');
ok($profile->invoke($adapter, 'instagram', 'apify/instagram-scraper') === 'instagram_apify_official', 'Instagram exact actor maps to official profile');
ok($profile->invoke($adapter, 'instagram', 'custom/instagram-post-scraper') === 'instagram_post_scraper', 'Instagram post scraper aliases map to dedicated post-scraper profile');
ok($profile->invoke($adapter, 'reddit', 'trudax/reddit-scraper-lite') === 'reddit_trudax_lite', 'Reddit exact actor maps to trudax lite profile');
ok($profile->invoke($adapter, 'google_trends', 'data_xplorer/google-trends-fast-scraper') === 'google_trends_data_xplorer', 'Google Trends exact actor maps to Data Xplorer profile');
ok($profile->invoke($adapter, 'google_news', 'data_xplorer/google-news-scraper-fast') === 'google_news_data_xplorer', 'Google News exact actor maps to Data Xplorer profile');

foreach (['instagram', 'reddit', 'google_trends', 'google_news'] as $source) {
    $source_payload = $payload;
    $source_payload['source_key'] = $source;
    $source_adapter = new FIDTF_Generic_Apify_Live_Adapter($source);
    $input = $build->invoke($source_adapter, $source, $source_payload);
    $input_profile = $profile->invoke($source_adapter, $source, FIDTF_Settings::actor_for($source));
    $validation = $validate->invoke($source_adapter, $source, $input_profile, $input);
    ok(!empty($validation['ok']), $source . ' actor input passes local contract validation');
    ok(!isset($input['_fidtf_contract_warnings']), $source . ' actor input does not leak internal warning fields to Apify');
}

$bad_ig = $validate->invoke($adapter, 'instagram', 'instagram_apify_official', ['resultsLimit' => 5]);
ok(empty($bad_ig['ok']) && in_array('instagram_missing_search_or_direct_url', $bad_ig['errors'], true), 'Instagram contract validator catches missing search/direct URL');
$bad_news = $validate->invoke($adapter, 'google_news', 'google_news_data_xplorer', ['maxArticles' => 5]);
ok(empty($bad_news['ok']) && in_array('google_news_missing_keywords', $bad_news['errors'], true), 'Google News contract validator catches missing keywords array');

$reddit_adapter = new FIDTF_Generic_Apify_Live_Adapter('reddit');
ok($limit_from_actor_input->invoke($reddit_adapter, ['maxPostCount' => 17]) === 17, 'limit_from_actor_input respects Reddit maxPostCount');
$news_adapter = new FIDTF_Generic_Apify_Live_Adapter('google_news');
ok($limit_from_actor_input->invoke($news_adapter, ['maxArticles' => 13]) === 13, 'limit_from_actor_input respects Google News maxArticles');
$trends_adapter = new FIDTF_Generic_Apify_Live_Adapter('google_trends');
ok($limit_from_actor_input->invoke($trends_adapter, ['trendingSearchesMaxItems' => 23]) === 23, 'limit_from_actor_input respects Google Trends trendingSearchesMaxItems');

$ig_flattened = $sanitize->invoke(new FIDTF_Generic_Apify_Live_Adapter('instagram'), [[
    'items' => [
        ['shortCode' => 'IG1', 'caption' => 'Mahou terrace #mahou'],
        ['shortCode' => 'IG2', 'caption' => 'Beer ritual #mahou'],
    ],
]]);
ok(count($ig_flattened) === 2 && ($ig_flattened[0]['provider_container_type'] ?? '') === 'instagram_items', 'Instagram wrapper dataset items are expanded safely');

$news_flattened = $sanitize->invoke(new FIDTF_Generic_Apify_Live_Adapter('google_news'), [[
    'articles' => [
        ['title' => 'Mahou summer activation', 'url' => 'https://news.example/1'],
        ['title' => 'Beer category trend', 'url' => 'https://news.example/2'],
    ],
]]);
ok(count($news_flattened) === 2 && ($news_flattened[0]['provider_container_type'] ?? '') === 'google_news_articles', 'Google News article container is expanded into evidence rows');

$trends_flattened = $sanitize->invoke(new FIDTF_Generic_Apify_Live_Adapter('google_trends'), [[
    'geo' => 'ES',
    'trendingSearches' => [
        ['query' => 'Mahou terrazas', 'formattedTraffic' => '10K+'],
        ['query' => 'cerveza Madrid', 'formattedTraffic' => '20K+'],
    ],
]]);
ok(count($trends_flattened) === 2 && ($trends_flattened[1]['provider_container_type'] ?? '') === 'google_trends_trending_search', 'Google Trends camelCase trendingSearches are expanded into evidence rows');

$trend_item = FIDTF_Normalizer::normalize('google_trends', [
    'query' => 'Mahou terrazas',
    'formattedTraffic' => '10K+',
    'trendsUrl' => 'https://trends.google.com/trends/explore?q=Mahou',
    'timelineData' => [
        ['date' => '2026-05-01', 'value' => 35],
        ['date' => '2026-05-02', 'value' => 92],
    ],
]);
ok($trend_item['title'] === 'Mahou terrazas', 'Google Trends normalizer accepts query as title');
ok((int) $trend_item['metrics']['trend_interest'] === 92, 'Google Trends normalizer derives interest from timelineData rows');
ok(!empty($trend_item['trend_timeseries']), 'Google Trends normalizer preserves timelineData');

$news_item = FIDTF_Normalizer::normalize('google_news', [
    'title' => 'Mahou summer activation',
    'sourceName' => 'Campaign ES',
    'articleUrl' => 'https://news.example/mahou',
    'content' => 'A new terrace-led beer activation is gaining cultural attention.',
    'image' => ['src' => 'https://news.example/image.jpg'],
    'metadata' => ['query' => 'mahou'],
]);
ok($news_item['author'] === 'Campaign ES', 'Google News normalizer accepts sourceName');
ok($news_item['url'] === 'https://news.example/mahou', 'Google News normalizer accepts articleUrl');
ok($news_item['provider_query'] === 'mahou', 'Google News normalizer accepts metadata.query');
ok($news_item['media']['thumbnail_url'] === 'https://news.example/image.jpg', 'Google News normalizer extracts nested image src');

ok(strpos($generic_adapter, 'validate_actor_input') !== false, 'generic adapter includes local actor contract validation');
ok(strpos($generic_adapter, 'source_actor_contract_invalid') !== false, 'generic adapter fails locally before provider dispatch on invalid actor contracts');
ok(strpos($generic_adapter, 'trendingSearches') !== false, 'generic adapter supports camelCase Google Trends containers');
ok(strpos($normalizer_src, 'timelineData') !== false, 'normalizer supports timelineData');
ok(strpos($normalizer_src, 'sourceName') !== false, 'normalizer supports Google News sourceName');

fwrite(STDOUT, "FIDTF v0.3.29 all actor live QA hardening checks passed: {$checks} / {$checks}\n");
