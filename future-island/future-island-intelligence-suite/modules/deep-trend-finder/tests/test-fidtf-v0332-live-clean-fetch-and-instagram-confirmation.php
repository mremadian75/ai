<?php
require_once __DIR__ . '/test-fidtf-v0331-live-actor-contract-fixes.php';
$plugin = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.32
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.32
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.32

$generic = file_get_contents(dirname(__DIR__) . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$tiktok = file_get_contents(dirname(__DIR__) . '/includes/providers/class-fidtf-tiktok-live-adapter.php');
ok(strpos($generic, "'clean' => 'false'") !== false, 'generic Apify dataset fallback fetches raw rows with clean=false');
ok(strpos($generic, 'clean=false') !== false, 'generic direct Apify dataset URL uses clean=false');
ok(strpos($tiktok, "'clean' => 'false'") !== false, 'TikTok direct dataset fetches raw rows with clean=false');
ok(strpos($generic, 'cleanItemCount=0') !== false, 'generic adapter documents cleanItemCount false-zero risk');
ok(strpos($tiktok, 'cleanItemCount=0') !== false, 'TikTok adapter documents cleanItemCount false-zero risk');

$adapter = new FIDTF_Generic_Apify_Live_Adapter('instagram');
$ref = new ReflectionClass($adapter);
$build = $ref->getMethod('build_actor_input'); $build->setAccessible(true);
$payload = [
  'source_key' => 'instagram',
  'request_context' => ['keywords' => ['coffee'], 'market' => 'Spain', 'language' => 'es'],
  'source_plan' => ['queries' => ['coffee']],
  'limits' => ['max_items' => 3],
];
$input = $build->invoke($adapter, 'instagram', $payload);
ok(isset($input['directUrls'][0]) && $input['directUrls'][0] === 'https://www.instagram.com/explore/tags/coffee/', 'Instagram hashtag mode uses direct hashtag URL');
ok(($input['resultsType'] ?? '') === 'posts' && ($input['resultsLimit'] ?? 0) === 3, 'Instagram direct hashtag mode requests post rows');

$ig = FIDTF_Normalizer::normalize('instagram', [
  'caption' => 'Fresh coffee ritual',
  'shortCode' => 'ABC123',
  'url' => 'https://www.instagram.com/p/ABC123/',
  'ownerUsername' => 'barista',
  'likesCount' => 10,
  'commentsCount' => 2,
  'displayUrl' => 'https://example.com/image.jpg',
  'images' => ['https://example.com/slide.jpg'],
  'hashtags' => ['coffee'],
]);
ok(($ig['source_key'] ?? '') === 'instagram', 'Instagram live post row normalizes');
ok(($ig['media']['thumbnail_url'] ?? '') !== '', 'Instagram live post row keeps media thumbnail');
ok(($ig['metrics']['likes'] ?? null) == 10, 'Instagram live post row keeps likes metric');

fwrite(STDOUT, "FIDTF v0.3.32 live clean fetch and Instagram confirmation checks passed: {$checks} / {$checks}\n");
