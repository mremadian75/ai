<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.49
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: version constant bumped to v0.3.49
ok(strpos($report_src, "'schema_version' => 'fidtf.report.v3.8'") !== false, 'report schema v3.5 marker');
ok(strpos($normalizer_src, "'source_country'") !== false, 'source country normalization exists');
ok(strpos($report_src, 'demand_decision_ready_rows') !== false, 'demand decision-ready gate exists');

$request = [
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => 'mahou, cerveza, beer, bebida sin alcohol, terraceo, San Isidro',
    'brand' => 'Mahou',
];

// Live QA pattern observed on Apify: outside-market rows matched the provider query
// but did not provide Spain/Mahou evidence. They must not become strategy rows.
$mx = FIDTF_Normalizer::normalize('tiktok', [
    'id' => 'live-mx-zero-alcohol',
    'text' => '¡Reto Mayahuasca: cerveza cero alcohol! 🍻 #Reto #Cerveza #Alcohol',
    'textLanguage' => 'es',
    'locationCreated' => 'MX',
    'webVideoUrl' => 'https://www.tiktok.com/@mayahuasca_mexico/video/7624653065664105748',
    'playCount' => 61570,
    'diggCount' => 203,
    'commentCount' => 4,
    'shareCount' => 7,
    'searchQuery' => 'mahou cerveza 0,0',
]);
$score_mx = FIDTF_Relevance_Filter::score($mx, $request, []);
ok(($score_mx['recommended_use'] ?? '') === 'hide', 'outside-market TikTok row hidden from strategy');
ok(($score_mx['marketing_allowed_use'] ?? '') === 'excluded', 'outside-market TikTok row excluded from marketing use');
ok(stripos((string) ($score_mx['reason'] ?? ''), 'does not match requested market') !== false, 'outside-market reason is explicit');

$es_low = FIDTF_Normalizer::normalize('tiktok', [
    'id' => 'live-es-low-view-mahou',
    'text' => '¿Te acuerdas de cuando la cerveza sabía a esto? #Bebicer #CervezasMahou #saborquemuevemadrid',
    'textLanguage' => 'es',
    'locationCreated' => 'ES',
    'webVideoUrl' => 'https://www.tiktok.com/@inspectorbebicer1/video/7632635543964388610',
    'playCount' => 49,
    'diggCount' => 1,
    'commentCount' => 0,
    'shareCount' => 0,
    'searchQuery' => 'mahou cerveza 0,0',
]);
$score_es_low = FIDTF_Relevance_Filter::score($es_low, $request, []);
ok(($score_es_low['recommended_use'] ?? '') === 'hide', 'very-low-view TikTok row hidden even when market and brand fit exist');
ok(!empty($score_es_low['below_min_views']), 'low-view TikTok row clearly marked below threshold');

$decision_method = new ReflectionMethod('FIDTF_Report_Service', 'decision_ready_items');
$decision_method->setAccessible(true);
$decision_items = $decision_method->invoke(null, [
    ['source_key' => 'tiktok', 'evidence_tier' => 'creative_only', 'caption_or_text' => 'meme hook'],
    ['source_key' => 'instagram', 'evidence_tier' => 'weak', 'caption_or_text' => 'weak caption'],
    ['source_key' => 'google_news', 'evidence_tier' => 'support', 'caption_or_text' => 'Mahou 0,0 news'],
]);
ok(count($decision_items) === 1, 'creative-only rows are not decision-ready rows');
ok(($decision_items[0]['source_key'] ?? '') === 'google_news', 'support row remains decision-ready');

$quality_method = new ReflectionMethod('FIDTF_Report_Service', 'evidence_quality_summary');
$quality_method->setAccessible(true);
$quality = $quality_method->invoke(null, [
    ['source_key' => 'google_news', 'evidence_tier' => 'weak', 'metrics' => [], 'url' => 'https://example.test/weak'],
    ['source_key' => 'google_trends', 'evidence_tier' => 'support', 'metrics' => ['trend_interest' => 45], 'url' => 'https://example.test/trend'],
    ['source_key' => 'tiktok', 'evidence_tier' => 'creative_only', 'metrics' => ['views' => 100000], 'url' => 'https://example.test/tiktok'],
], [
    'google_news' => ['relevant_count' => 1],
    'google_trends' => ['relevant_count' => 1],
    'tiktok' => ['relevant_count' => 1],
], ['engagement_ranges' => ['low' => 1, 'medium' => 1, 'high' => 1]]);
ok((int) ($quality['demand_rows'] ?? 0) === 2, 'all demand-source rows are counted for diagnostics');
ok((int) ($quality['demand_decision_ready_rows'] ?? 0) === 1, 'only strong/support demand rows count toward demand readiness');

$confidence_method = new ReflectionMethod('FIDTF_Report_Service', 'confidence_breakdown');
$confidence_method->setAccessible(true);
$confidence = $confidence_method->invoke(null, [
    'google_news' => ['relevant_count' => 1, 'decision_ready_count' => 0],
    'google_trends' => ['relevant_count' => 1, 'decision_ready_count' => 1],
    'tiktok' => ['relevant_count' => 1, 'decision_ready_count' => 0, 'creative_only_count' => 1],
], $quality, [
    'direct_count' => 1,
    'creative_only_count' => 1,
]);
ok(($confidence['market_demand_confidence'] ?? '') === 'weak_single_point_not_validated', 'weak demand rows do not inflate market-demand confidence');
ok(($confidence['client_readiness'] ?? '') === 'internal_validation_only', 'client readiness remains blocked without enough demand decision rows');

fwrite(STDOUT, "FIDTF v0.3.49 live market-fit and decision gate checks passed.\n");
