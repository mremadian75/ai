<?php
require_once __DIR__ . '/test-fidtf-v0319-ready-status-polling-fix.php';

$root = dirname(__DIR__);
$rest_code = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$provider_code = file_get_contents($root . '/includes/providers/class-fidtf-provider-tiktok.php');
$normalizer_code = file_get_contents($root . '/includes/class-fidtf-normalizer.php');
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version is numeric v0.3.20 or newer patch
ok(strpos($rest_code, 'refresh_run_jobs_and_status') !== false, 'REST controller has centralized refresh/repair helper');
ok(strpos($rest_code, 'report loading is also a safe refresh point') !== false, 'report endpoint refreshes before building report');
ok(strpos($rest_code, 'evidence loading must also attempt provider refresh/repair') !== false, 'items endpoint refreshes before returning evidence');
ok(strpos($provider_code, 'canonicalize_tiktok_row') !== false, 'TikTok provider canonicalizes display-label rows');
ok(strpos($normalizer_code, 'Post URL') !== false && strpos($normalizer_code, 'Channel Username') !== false, 'normalizer supports Apidojo display-label aliases');

$apidojo_display_row = [
    'ID' => '7639618414637485326',
    'Title' => 'The weather forecast apparently means I will be making this strawberry lime refresher every single day',
    'Views' => 22260,
    'Likes' => 529,
    'Comments' => 4,
    'Shares' => 50,
    'Bookmarks' => 438,
    'Uploaded At Formatted' => '2026-05-14T05:42:03.000Z',
    'Post URL' => 'https://www.tiktok.com/@isabellajoliefit/video/7639618414637485326',
    'Channel Name' => 'isabellajoliefit',
    'Channel Username' => 'isabellajoliefit',
    'Channel Avatar' => 'https://example.test/avatar.jpg',
    'Channel Verified' => true,
    'Song Title' => 'Seaside Cafe',
];
$flattened = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([$apidojo_display_row]);
ok(count($flattened) === 1, 'Apidojo display-label dataset row is flattened into one post');
ok(($flattened[0]['id'] ?? '') === '7639618414637485326', 'display-label ID is canonicalized to id');
ok(($flattened[0]['postPage'] ?? '') === $apidojo_display_row['Post URL'], 'display-label Post URL is canonicalized to postPage');
ok(($flattened[0]['channel']['username'] ?? '') === 'isabellajoliefit', 'display-label Channel Username is canonicalized to channel.username');

$item = FIDTF_Normalizer::normalize('tiktok', $flattened[0]);
ok(($item['external_id'] ?? '') === '7639618414637485326', 'display-label output normalizes external id');
ok(($item['url'] ?? '') === $apidojo_display_row['Post URL'], 'display-label output normalizes source URL');
ok(($item['author'] ?? '') === 'isabellajoliefit', 'display-label output normalizes author');
ok((int) ($item['metrics']['views'] ?? 0) === 22260, 'display-label output normalizes views');
ok((int) ($item['metrics']['likes'] ?? 0) === 529, 'display-label output normalizes likes');
ok((int) ($item['metrics']['saves'] ?? 0) === 438, 'display-label output normalizes bookmarks as saves');
ok(!empty($item['published_at']), 'display-label output normalizes published date');

fwrite(STDOUT, "FIDTF v0.3.20 Apidojo flat output and repair refresh checks passed: {$checks} / {$checks}\n");
