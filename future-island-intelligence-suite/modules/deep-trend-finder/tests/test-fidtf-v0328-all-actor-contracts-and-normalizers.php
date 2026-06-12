<?php
require_once __DIR__ . '/test-fidtf-v0327-report-intelligence-ui-and-source-adapter-qa.php';
require_once dirname(__DIR__) . '/includes/providers/class-fidtf-generic-apify-live-adapter.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$generic_adapter = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$normalizer = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.28
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.28
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.28

$payload = [
    'source_key' => 'instagram',
    'source_plan' => [
        'queries' => ['mahou'],
        'hashtags' => ['mahou'],
        'max_items' => 7,
    ],
    'request_context' => [
        'keywords' => ['mahou'],
        'market' => 'Spain',
        'language' => 'es',
        'date_range' => 'last_7_days',
    ],
    'limits' => ['max_items' => 7],
];

$rc = new ReflectionClass('FIDTF_Generic_Apify_Live_Adapter');
$build = $rc->getMethod('build_actor_input');
$build->setAccessible(true);

$instagram = new FIDTF_Generic_Apify_Live_Adapter('instagram');
$ig_input = $build->invoke($instagram, 'instagram', $payload);
ok(isset($ig_input['directUrls'][0]) && strpos($ig_input['directUrls'][0], 'instagram.com/explore/tags/') !== false, 'Instagram actor input uses direct hashtag URL for post evidence');
ok(isset($ig_input['resultsType']) && $ig_input['resultsType'] === 'posts', 'Instagram actor input uses resultsType=posts');
ok(isset($ig_input['resultsLimit']) && $ig_input['resultsLimit'] === 7, 'Instagram actor input respects result limit');
ok(!isset($ig_input['queries']) && !isset($ig_input['keywords']) && !isset($ig_input['search']), 'Instagram actor input avoids undocumented query arrays and search directory mode');

$reddit_payload = $payload;
$reddit_payload['source_key'] = 'reddit';
$reddit = new FIDTF_Generic_Apify_Live_Adapter('reddit');
$reddit_input = $build->invoke($reddit, 'reddit', $reddit_payload);
ok(isset($reddit_input['searches']) && $reddit_input['searches'][0] === 'mahou', 'Reddit actor input uses searches');
ok(!isset($reddit_input['queries']) && !isset($reddit_input['searchTerms']), 'Reddit actor input avoids legacy queries/searchTerms');
ok(!empty($reddit_input['searchPosts']) && !empty($reddit_input['skipComments']), 'Reddit actor input targets posts and skips comment extraction by default');
ok(isset($reddit_input['maxPostCount']) && $reddit_input['maxPostCount'] === 7, 'Reddit actor input respects maxPostCount');

$trends_payload = $payload;
$trends_payload['source_key'] = 'google_trends';
$trends = new FIDTF_Generic_Apify_Live_Adapter('google_trends');
$trends_input = $build->invoke($trends, 'google_trends', $trends_payload);
ok(isset($trends_input['keyword']) && $trends_input['keyword'] === 'mahou', 'Google Trends actor input uses singular keyword');
ok(isset($trends_input['predefinedTimeframe']), 'Google Trends actor input uses predefinedTimeframe');
ok(!isset($trends_input['queries']) && !isset($trends_input['maxItems']), 'Google Trends keyword actor input avoids generic queries/maxItems');

$news_payload = $payload;
$news_payload['source_key'] = 'google_news';
$news = new FIDTF_Generic_Apify_Live_Adapter('google_news');
$news_input = $build->invoke($news, 'google_news', $news_payload);
ok(isset($news_input['keywords']) && $news_input['keywords'][0] === 'mahou', 'Google News actor input uses keywords array');
ok(isset($news_input['maxArticles']) && $news_input['maxArticles'] === 7, 'Google News actor input uses maxArticles');
ok(isset($news_input['region_language']) && $news_input['region_language'] === 'ES:es', 'Google News actor input uses region_language');
ok(!isset($news_input['query']) && !isset($news_input['queries']) && !isset($news_input['timeRange']), 'Google News actor input avoids old generic query/timeRange keys');

$ig_item = FIDTF_Normalizer::normalize('instagram', [
    'shortCode' => 'ABC123',
    'url' => 'https://www.instagram.com/p/ABC123/',
    'caption' => 'Mahou terrace ritual in Madrid #mahou',
    'likesCount' => 1200,
    'commentsCount' => 44,
    'videoViewCount' => 22000,
    'timestamp' => '2026-05-24T12:00:00.000Z',
    'displayUrl' => 'https://cdn.example/ig.jpg',
    'ownerUsername' => 'creator_es',
]);
ok($ig_item['external_id'] === 'ABC123', 'Instagram normalizer keeps shortCode as external id');
ok($ig_item['author'] === 'creator_es', 'Instagram normalizer extracts ownerUsername');
ok((int) $ig_item['metrics']['views'] === 22000, 'Instagram normalizer extracts videoViewCount');
ok((int) $ig_item['metrics']['likes'] === 1200, 'Instagram normalizer extracts likesCount');
ok($ig_item['media']['thumbnail_url'] === 'https://cdn.example/ig.jpg', 'Instagram normalizer extracts displayUrl thumbnail');

$reddit_item = FIDTF_Normalizer::normalize('reddit', [
    'id' => 't3_x1',
    'url' => 'https://www.reddit.com/r/spain/comments/x1/beer_question/',
    'username' => 'user1',
    'title' => 'Mahou vs Estrella Galicia?',
    'body' => 'Which beer do people actually prefer in Madrid?',
    'upVotes' => 91,
    'numberOfComments' => 32,
    'createdAt' => '2026-05-21T10:00:00.000Z',
]);
ok($reddit_item['author'] === 'user1', 'Reddit normalizer extracts username');
ok((int) $reddit_item['metrics']['score'] === 91, 'Reddit normalizer extracts upVotes');
ok((int) $reddit_item['metrics']['comments'] === 32, 'Reddit normalizer extracts numberOfComments');
ok(strpos($reddit_item['title'], 'Mahou') !== false, 'Reddit normalizer keeps post title');

$trend_item = FIDTF_Normalizer::normalize('google_trends', [
    'keyword' => 'mahou',
    'timeframe' => 'today 1-m',
    'geo' => 'ES',
    'trends_url' => 'https://trends.google.com/trends/explore?q=mahou&geo=ES',
    'timeline_data' => ['2026-W20' => 51, '2026-W21' => 77],
]);
ok($trend_item['title'] === 'mahou', 'Google Trends normalizer uses keyword as title');
ok($trend_item['url'] === 'https://trends.google.com/trends/explore?q=mahou&geo=ES', 'Google Trends normalizer keeps trends_url');
ok((int) $trend_item['metrics']['trend_interest'] === 77, 'Google Trends normalizer derives max trend interest from timeline_data');
ok(!empty($trend_item['trend_timeseries']), 'Google Trends normalizer keeps timeline_data as trend_timeseries');

$sanitize = $rc->getMethod('sanitize_items');
$sanitize->setAccessible(true);
$trend_container = new FIDTF_Generic_Apify_Live_Adapter('google_trends');
$flattened = $sanitize->invoke($trend_container, [[
    'geo' => 'ES',
    'language' => 'es-ES',
    'timeframe_hours' => '24',
    'trending_searches' => [
        ['rank' => 1, 'term' => 'Mahou Cinco Estrellas', 'trend_volume' => '50K+', 'related_terms' => ['cerveza']],
        ['rank' => 2, 'term' => 'Madrid terraza', 'trend_volume' => '20K+'],
    ],
]]);
ok(count($flattened) === 2, 'Google Trends adapter expands trending_searches into separate evidence rows');
ok(($flattened[0]['provider_container_type'] ?? '') === 'google_trends_trending_search', 'Google Trends expanded rows keep provenance type');

$news_item = FIDTF_Normalizer::normalize('google_news', [
    'title' => 'Mahou launches summer activation in Madrid',
    'source' => 'Marketing News ES',
    'url' => 'https://news.example/mahou-summer',
    'description' => 'The beer brand is testing a terrace-led cultural campaign.',
    'publishedAt' => '2026-05-22T08:00:00.000Z',
    'image' => 'https://news.example/image.jpg',
    'metadata' => ['keyword' => 'mahou', 'timeframe' => '1d'],
]);
ok($news_item['author'] === 'Marketing News ES', 'Google News normalizer extracts source as author');
ok(strpos($news_item['caption_or_text'], 'beer brand') !== false, 'Google News normalizer extracts description');
ok($news_item['provider_query'] === 'mahou', 'Google News normalizer extracts metadata keyword');
ok($news_item['media']['thumbnail_url'] === 'https://news.example/image.jpg', 'Google News normalizer extracts image thumbnail');

ok(strpos($generic_adapter, 'searchType') !== false, 'adapter source includes Instagram searchType contract');
ok(strpos($generic_adapter, 'searches') !== false, 'adapter source includes Reddit searches contract');
ok(strpos($generic_adapter, 'predefinedTimeframe') !== false, 'adapter source includes Google Trends keyword contract');
ok(strpos($generic_adapter, 'maxArticles') !== false, 'adapter source includes Google News maxArticles contract');
ok(strpos($normalizer, 'timeline_data') !== false, 'normalizer source supports Google Trends timeline_data');
ok(strpos($normalizer, 'publishedAt') !== false, 'normalizer source supports Google News publishedAt');

fwrite(STDOUT, "FIDTF v0.3.28 all actor contract and normalizer checks passed: {$checks} / {$checks}\n");
