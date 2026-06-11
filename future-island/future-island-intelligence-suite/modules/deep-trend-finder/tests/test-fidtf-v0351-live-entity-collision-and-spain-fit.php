<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$filter_src = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.53
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: version constant bumped to v0.3.53
ok(strpos($filter_src, 'entity_context_gate') !== false, 'entity collision gate exists');
ok(strpos($filter_src, 'text_market_drift_gate') !== false, 'text market drift gate exists');
ok(strpos($report_src, "'schema_version' => 'fidtf.report.v3.8'") !== false, 'report schema v3.8 marker');

$request = [
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => 'Mahou San Isidro cerveza',
    'brand' => 'Mahou',
];

$anime = FIDTF_Normalizer::normalize('tiktok', [
    'text' => '"Roshutsu-kei Mahou" Song • Montagem Boyfriend #anime #wofsq #dcxnime #animescene #fyp',
    'textLanguage' => 'en',
    'locationCreated' => 'US',
    'webVideoUrl' => 'https://www.tiktok.com/@anime/video/1',
    'playCount' => 310770,
    'diggCount' => 19177,
    'commentCount' => 86,
    'shareCount' => 1319,
    'hashtags' => [['name' => 'anime'], ['name' => 'fyp']],
]);
$anime_score = FIDTF_Relevance_Filter::score($anime, $request, []);
ok(($anime_score['recommended_use'] ?? '') === 'hide', 'anime Mahou collision hidden from evidence');
ok(($anime_score['evidence_tier'] ?? '') === 'noise', 'anime Mahou collision is noise');
ok((float) ($anime_score['score'] ?? 99) <= 30.0, 'anime Mahou collision capped below evidence threshold');
ok(stripos((string) ($anime_score['reason'] ?? ''), 'anime') !== false || stripos((string) ($anime_score['reason'] ?? ''), 'non-beer') !== false, 'anime collision reason is explicit');

$foreign = FIDTF_Normalizer::normalize('tiktok', [
    'text' => 'Este 30 de mayo estaremos en feria en el Sport Granden en el Mahou Shoujo Fest, visítanos #ecuadorec #Guayaquil #mahoushoujo #mahou',
    'textLanguage' => 'es',
    'locationCreated' => 'EC',
    'webVideoUrl' => 'https://www.tiktok.com/@festival/video/2',
    'playCount' => 39461,
    'diggCount' => 3039,
    'commentCount' => 102,
    'shareCount' => 833,
    'hashtags' => [['name' => 'ecuadorec'], ['name' => 'Guayaquil'], ['name' => 'mahoushoujo'], ['name' => 'mahou']],
]);
$foreign_score = FIDTF_Relevance_Filter::score($foreign, $request, []);
ok(($foreign_score['recommended_use'] ?? '') === 'hide', 'foreign Mahou Shoujo festival row hidden');
ok(($foreign_score['marketing_allowed_use'] ?? '') === 'excluded', 'foreign Mahou Shoujo festival row excluded from marketing use');
ok((float) ($foreign_score['score'] ?? 99) <= 30.0, 'foreign Mahou Shoujo festival row capped below evidence threshold');

$brand = FIDTF_Normalizer::normalize('instagram', [
    'caption' => 'Special label from Mahou for the San Isidro festival in Madrid 2026. Mahou Cinco Estrellas edición especial.',
    'textLanguage' => 'en',
    'locationName' => 'Madrid, Spain',
    'url' => 'https://www.instagram.com/p/example/',
    'likesCount' => 30,
    'commentsCount' => 1,
    'hashtags' => ['mahou', 'sanisidro', 'madrid'],
]);
$brand_score = FIDTF_Relevance_Filter::score($brand, $request, []);
ok(($brand_score['recommended_use'] ?? '') !== 'hide', 'Mahou San Isidro Madrid beer-context row remains usable');
ok(in_array(($brand_score['evidence_tier'] ?? ''), ['support','strong','weak'], true), 'valid Mahou Madrid row is not forced to noise');

fwrite(STDOUT, "FIDTF v0.3.53 live entity collision and Spain-fit checks passed.\n");
