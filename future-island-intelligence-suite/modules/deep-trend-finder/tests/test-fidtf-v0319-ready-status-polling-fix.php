<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$core_adapter_code = file_get_contents($root . '/includes/providers/class-fidtf-core-apify-client-adapter.php');
$direct_adapter_code = file_get_contents($root . '/includes/providers/class-fidtf-tiktok-live-adapter.php');
$job_code = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$normalizer_code = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

ok(strpos($core_adapter_code, "['READY', 'SUCCEEDED']") === false, 'core adapter no longer treats READY as terminal success');
ok(strpos($direct_adapter_code, "['READY', 'SUCCEEDED']") === false, 'direct adapter no longer treats READY as terminal success');
ok(strpos($direct_adapter_code, "\$status === 'READY' || \$status === 'SUCCEEDED'") === false, 'direct adapter has no READY-or-SUCCEEDED terminal shortcut');
ok(strpos($core_adapter_code, 'Apify READY is not a finished run') !== false, 'core adapter documents READY polling fix');
ok(strpos($job_code, 'is_tiktok_zero_count_repair_candidate') !== false, 'source-job service can repair prior premature zero-count TikTok jobs');

$adapter = new FIDTF_Core_Apify_Client_Adapter();
$method = new ReflectionMethod($adapter, 'normalize_start_response');
$method->setAccessible(true);
$ready = $method->invoke($adapter, [
    'body' => [
        'data' => [
            'id' => 'ready-run-1',
            'status' => 'READY',
            'defaultDatasetId' => 'dataset-with-results-later',
        ],
    ],
], ['actor_id' => 'apidojo/tiktok-scraper'], ['maxItems' => 25]);
ok(is_array($ready), 'READY run produces a normalized array response');
ok(($ready['status'] ?? '') === 'queued', 'READY run remains queued instead of completed_no_relevant_evidence');
ok(empty($ready['items']), 'READY run does not fetch or freeze an empty dataset');
ok(($ready['provider_run_id'] ?? '') === 'ready-run-1', 'READY run keeps provider run id for later polling');

$apidojo_row = [
    'id' => '7639618414637485326',
    'title' => '#beer #summerwins Spanish cerveza campaign angle',
    'views' => 22260,
    'likes' => 529,
    'comments' => 4,
    'shares' => 50,
    'bookmarks' => 438,
    'uploadedAtFormatted' => '2026-05-14T05:42:03.000Z',
    'postPage' => 'https://www.tiktok.com/@isabellajoliefit/video/7639618414637485326',
    'channel' => [
        'name' => 'isabellajoliefit',
        'username' => 'isabellajoliefit',
        'avatar' => 'https://example.test/avatar.jpg',
    ],
    'song' => ['title' => 'Seaside Cafe'],
];
$flattened = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([$apidojo_row]);
ok(count($flattened) === 1, 'Apidojo flat dataset rows are recognized as TikTok posts');
$item = FIDTF_Normalizer::normalize('tiktok', $flattened[0]);
ok(($item['external_id'] ?? '') === '7639618414637485326', 'Apidojo row keeps TikTok post id');
ok(($item['url'] ?? '') === $apidojo_row['postPage'], 'Apidojo postPage is normalized as URL');
ok(($item['author'] ?? '') === 'isabellajoliefit', 'Apidojo channel.username is normalized as author');
ok((int) ($item['metrics']['views'] ?? 0) === 22260, 'Apidojo views are normalized');
ok((int) ($item['metrics']['saves'] ?? 0) === 438, 'Apidojo bookmarks are normalized as saves');
ok(!empty($item['published_at']), 'Apidojo uploadedAtFormatted is normalized as published_at');
ok(strpos($normalizer_code, 'postPage') !== false, 'normalizer includes Apidojo postPage field');

fwrite(STDOUT, "FIDTF v0.3.19 READY polling fix checks passed: {$checks} / {$checks}\n");
