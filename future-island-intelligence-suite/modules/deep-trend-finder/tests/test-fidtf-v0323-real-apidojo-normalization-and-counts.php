<?php
require_once __DIR__ . '/test-fidtf-v0322-repair-force-and-public-counts.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$normalizer_code = file_get_contents($root . '/includes/class-fidtf-normalizer.php');
$relevance_code = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');
$core_code = file_get_contents($root . '/includes/providers/class-fidtf-core-apify-client-adapter.php');
$direct_code = file_get_contents($root . '/includes/providers/class-fidtf-tiktok-live-adapter.php');
$source_job_code = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$rest_code = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$frontend_code = file_get_contents($root . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header is v0.3.23 or newer patch
// [v1.1.0 archived] removed obsolete standalone-addon version/lineage assertion: FIDTF_VERSION is v0.3.23 or newer patch

$real_apidojo_row = [
    'inputSource' => 'beverage',
    'id' => '7639618414637485326',
    'title' => 'The weather forecast apparently means I will be making this strawberry lime refresher every single day #mocktail',
    'views' => 22260,
    'likes' => 529,
    'comments' => 4,
    'shares' => 50,
    'bookmarks' => 438,
    'hashtags' => ['refresher', 'healthydrinks', 'mocktail', 'wellnessdrink', 'summerrecipes'],
    'channel' => [
        'id' => '7086858629790270506',
        'name' => 'isabellajoliefit',
        'username' => 'isabellajoliefit',
        'avatar' => 'https://example.test/avatar.heic',
        'verified' => true,
        'url' => 'https://www.tiktok.com/@isabellajoliefit',
        'followers' => 33129,
    ],
    'uploadedAt' => 1778737323,
    'uploadedAtFormatted' => '2026-05-14T05:42:03.000Z',
    'video' => [
        'width' => 720,
        'height' => 1280,
        'ratio' => '720p',
        'duration' => 26.516,
        'url' => 'https://v45.tiktokcdn-eu.com/video.mp4',
        'cover' => 'https://p16-common-sign.tiktokcdn-eu.com/cover.jpeg',
        'thumbnail' => 'https://p16-common-sign.tiktokcdn-eu.com/thumb.jpeg',
    ],
    'song' => [
        'id' => '7629046710379760000',
        'title' => 'Seaside Cafe',
        'artist' => 'Daiki Goto',
        'duration' => 173,
    ],
    'postPage' => 'https://www.tiktok.com/@isabellajoliefit/video/7639618414637485326',
];

$flattened = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([$real_apidojo_row]);
ok(count($flattened) === 1, 'real nested Apidojo row flattens into one post');
$item = FIDTF_Normalizer::normalize('tiktok', $flattened[0]);
ok(($item['external_id'] ?? '') === '7639618414637485326', 'real Apidojo row keeps id');
ok(($item['url'] ?? '') === $real_apidojo_row['postPage'], 'real Apidojo row keeps post URL');
ok(($item['author'] ?? '') === 'isabellajoliefit', 'real Apidojo row keeps channel username as author');
ok(($item['media']['media_url'] ?? '') === 'https://v45.tiktokcdn-eu.com/video.mp4', 'normalizer extracts Apidojo video.url as media_url');
ok(($item['media']['thumbnail_url'] ?? '') === 'https://p16-common-sign.tiktokcdn-eu.com/thumb.jpeg', 'normalizer prefers Apidojo video.thumbnail as thumbnail');
ok(in_array('mocktail', (array) ($item['hashtags'] ?? []), true), 'normalizer preserves hashtags');
ok(($item['provider_query'] ?? '') === 'beverage', 'normalizer preserves provider discovery query');
ok((int) ($item['metrics']['views'] ?? 0) === 22260, 'real Apidojo views normalize');
ok((int) ($item['metrics']['saves'] ?? 0) === 438, 'real Apidojo bookmarks normalize as saves');

$request = ['keywords' => ['beverage'], 'market' => ''];
$source_plan = ['queries' => ['beverage'], 'max_items' => 50];
$score = FIDTF_Relevance_Filter::score($item, $request, $source_plan);
// [v1.1.0 policy alignment] This fixture has provider_query='beverage' but no LITERAL "beverage"
// in its text/hashtags (refresher/mocktail). Under the current anti-noise policy (v0.3.26/v0.3.37),
// provider-query provenance adds a small additive boost but does NOT constitute a core match, so a
// semantic-only provider result with score<50 is intentionally hidden. The earlier expectation
// (is_relevant===true purely from provider-query provenance) predates that hardening and is retired.
// The current intended behavior is pinned positively in test-fidtf-relevance-golden-set.php (case B).
ok($score['score'] >= 45, 'provider-query boost still contributes (score stays in the 45+ band)');
ok($score['is_relevant'] === false, 'semantic-only provider result is NOT relevant under current anti-noise policy (no literal core match, score<50)');
ok($score['recommended_use'] === 'hide', 'no literal core match + score<50 => recommended_use=hide');
ok(strpos($score['reason'], 'provider discovery query') !== false, 'relevance reason still explains provider-query contribution');

ok(strpos($normalizer_code, 'video.url') !== false && strpos($normalizer_code, 'video.thumbnail') !== false, 'normalizer contains real Apidojo media aliases');
ok(strpos($relevance_code, 'provider_query_score') !== false, 'relevance filter contains provider query score helper');
ok(strpos($core_code, 'fetch_dataset_total_count') !== false, 'core adapter fetches dataset metadata count');
ok(strpos($direct_code, 'fetch_dataset_total_count') !== false, 'direct adapter fetches dataset metadata count');
ok(strpos($source_job_code, 'provider_dataset_total_rows') !== false, 'source job diagnostics preserve dataset total rows');
ok(strpos($rest_code, 'provider_dataset_total_rows') !== false, 'REST response exposes safe total dataset rows');
ok(strpos($frontend_code, 'provider_dataset_total_rows') !== false, 'frontend displays total dataset rows when available');

fwrite(STDOUT, "FIDTF v0.3.23 real Apidojo normalization and count checks passed: {$checks} / {$checks}\n");
