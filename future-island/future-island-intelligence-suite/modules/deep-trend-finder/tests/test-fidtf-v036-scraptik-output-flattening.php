<?php
require_once __DIR__ . '/test-fidtf-v034-live-activation-ui-polish.php';
require_once dirname(__DIR__) . '/includes/providers/class-fidtf-provider-apify.php';
require_once dirname(__DIR__) . '/includes/providers/class-fidtf-provider-tiktok.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-source-dispatcher.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-relevance-filter.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-source-job-service.php';

if (!function_exists('esc_url_raw')) { function esc_url_raw($str) { return trim((string) $str); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($str) { return strip_tags((string) $str); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($value) { return json_encode($value); } }

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$provider_code = file_get_contents($root . '/includes/providers/class-fidtf-provider-tiktok.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.6
ok(strpos($provider_code, 'searchPosts_keyword') !== false && strpos($provider_code, 'searchPosts_count') !== false && strpos($provider_code, 'searchPosts_region') !== false && strpos($provider_code, 'searchPosts_sortType') !== false, 'v0.3.12 keeps Scraptik helpers but default actor input is discovery');

$search_items = [];
for ($i = 1; $i <= 25; $i++) {
    $search_items[] = [
        'aweme_info' => [
            'aweme_id' => 'aweme-' . $i,
            'desc' => 'Mahou cerveza trend clip ' . $i,
            'share_url' => 'https://www.tiktok.com/@creator' . $i . '/video/' . $i,
            'create_time' => 1770000000 + $i,
            'author' => [
                'unique_id' => 'creator' . $i,
                'nickname' => 'Creator ' . $i,
                'sec_uid' => 'SEC' . $i,
            ],
            'statistics' => [
                'play_count' => 1000 + $i,
                'digg_count' => 200 + $i,
                'comment_count' => 30 + $i,
                'share_count' => 10 + $i,
                'collect_count' => 5 + $i,
            ],
            'video' => [
                'play_addr' => ['url_list' => ['https://cdn.example/video' . $i . '.mp4']],
                'cover' => ['url_list' => ['https://cdn.example/cover' . $i . '.jpg']],
            ],
            'music' => ['title' => 'Song ' . $i, 'author' => 'Artist ' . $i],
        ],
    ];
}
$dataset_rows = [[
    'aweme_list' => [],
    'search_item_list' => $search_items,
]];

$flattened = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items($dataset_rows);
ok(count($flattened) === 25, 'dataset row with search_item_list containing 25 items returns 25 flattened items');
ok(($flattened[0]['aweme_id'] ?? '') === 'aweme-1', 'search_item_list item with aweme_info extracts aweme_info');
ok(FIDTF_Provider_TikTok::provider_dataset_row_count($dataset_rows) === 1, 'provider dataset row count tracks top-level Apify rows');

$wrapped_aweme = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([['search_item_list' => [['aweme' => ['aweme_id' => 'aweme-wrapper', 'desc' => 'wrapped aweme']]]]]);
ok(($wrapped_aweme[0]['aweme_id'] ?? '') === 'aweme-wrapper', 'search_item_list item with aweme extracts aweme');
$wrapped_item = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([['search_item_list' => [['item' => ['id' => 'item-wrapper', 'text' => 'wrapped item']]]]]);
ok(($wrapped_item[0]['id'] ?? '') === 'item-wrapper', 'search_item_list item with item extracts item');

$dispatch = FIDTF_Source_Job_Service::normalize_dispatch_result([
    'ok' => true,
    'status' => 'completed',
    'items' => $dataset_rows,
    'provider_dataset_rows' => FIDTF_Provider_TikTok::provider_dataset_row_count($dataset_rows),
    'flattened_raw_items' => count($flattened),
]);
ok(($dispatch['raw_count'] ?? 0) === 25 && ($dispatch['flattened_raw_items'] ?? 0) === 25, 'raw_count uses flattened count');

$normalized = FIDTF_Normalizer::normalize('tiktok', $flattened[0]);
ok(($normalized['external_id'] ?? '') === 'aweme-1', 'normalized TikTok item extracts aweme_id');
ok(strpos((string) ($normalized['caption_or_text'] ?? ''), 'Mahou cerveza') !== false, 'normalized TikTok item extracts text');
ok(($normalized['author'] ?? '') === 'creator1', 'normalized TikTok item extracts author unique_id');
ok(($normalized['url'] ?? '') === 'https://www.tiktok.com/@creator1/video/1', 'normalized TikTok item extracts share_url');
ok(($normalized['metrics']['views'] ?? 0) === 1001.0, 'normalized TikTok item extracts play_count metric');
ok(($normalized['metrics']['likes'] ?? 0) === 201.0, 'normalized TikTok item extracts digg_count metric');
ok(($normalized['metrics']['comments'] ?? 0) === 31.0, 'normalized TikTok item extracts comment_count metric');
ok(($normalized['metrics']['shares'] ?? 0) === 11.0, 'normalized TikTok item extracts share_count metric');
ok(($normalized['metrics']['saves'] ?? 0) === 6.0, 'normalized TikTok item extracts collect_count metric');
ok(($normalized['media']['media_url'] ?? '') === 'https://cdn.example/video1.mp4', 'normalized TikTok item extracts video.play_addr.url_list[0]');
ok(($normalized['media']['thumbnail_url'] ?? '') === 'https://cdn.example/cover1.jpg', 'normalized TikTok item extracts video.cover.url_list[0]');
ok(($normalized['media']['music']['title'] ?? '') === 'Song 1' && ($normalized['media']['music']['author'] ?? '') === 'Artist 1', 'normalized TikTok item retains music title and author');

$zero_msg = FIDTF_Source_Job_Service::message_for_ingest_counts('tiktok', 0, 0, 0);
$normalizer_msg = FIDTF_Source_Job_Service::message_for_ingest_counts('tiktok', 25, 0, 0);
$relevance_msg = FIDTF_Source_Job_Service::message_for_ingest_counts('tiktok', 25, 25, 0);
ok($zero_msg === 'Discovery returned zero posts', 'zero flattened items message is precise');
ok($normalizer_msg === 'TikTok data was returned, but the normalizer could not extract usable post fields.', 'normalizer failure message is precise');
ok($relevance_msg === 'Discovery returned posts but relevance filter rejected them', 'relevance failure message is precise');
ok($zero_msg !== $normalizer_msg && $normalizer_msg !== $relevance_msg, 'zero, normalizer, and relevance messages are distinct');

$source_plan = ['queries' => ['mahou cerveza'], 'max_items' => 25, 'sort_strategy' => 'relevance_then_engagement'];
$request = ['market' => 'ES', 'language' => 'es', 'keywords' => ['beverage']];
$actor_input = FIDTF_Provider_TikTok::build_actor_input_from_payload([
    'source_key' => 'tiktok',
    'source_plan' => $source_plan,
    'request_context' => $request,
    'limits' => ['max_items' => 25],
]);
ok(!empty($actor_input['searchQueries']) && in_array('mahou cerveza', (array) $actor_input['searchQueries'], true), 'TikTok discovery input uses searchQueries');
ok(($actor_input['resultsPerPage'] ?? 0) === 25, 'TikTok discovery input uses resultsPerPage');
ok(($actor_input['proxyCountryCode'] ?? '') === 'ES', 'TikTok discovery input uses proxyCountryCode');
ok(($actor_input['videoSearchSorting'] ?? '') === 'MOST_RELEVANT', 'TikTok discovery input uses live videoSearchSorting enum');

fwrite(STDOUT, "FIDTF v0.3.6 Scraptik output flattening checks passed: {$checks} / {$checks}\n");
