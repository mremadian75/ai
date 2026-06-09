<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.49
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: version constant bumped to v0.3.49
ok(strpos($report_src, "'schema_version' => 'fidtf.report.v3.8'") !== false, 'report schema v3.4 marker');
ok(strpos($report_src, 'Provider query is provenance') !== false, 'provider query is not evidence language marker');
ok(strpos($report_src, 'demand_evidence_rows') !== false, 'demand evidence minimum helper exists');

$top_terms_method = new ReflectionMethod('FIDTF_Report_Service', 'top_terms');
$top_terms_method->setAccessible(true);
$terms = $top_terms_method->invoke(null, [
    [
        'source_key' => 'tiktok',
        'title' => 'Madrid terrace view',
        'caption_or_text' => 'Por el precio de una cerveza tienes estas vistas en Madrid',
        'provider_query' => 'mahou cerveza beer beverage',
        'evidence_tier' => 'support',
    ],
    [
        'source_key' => 'tiktok',
        'title' => 'Generic social clip',
        'caption_or_text' => 'Fyp viral trending views dance clip',
        'provider_query' => 'mahou cerveza beer beverage',
        'evidence_tier' => 'creative_only',
    ],
], 20);
$labels = array_map(static function($row) { return (string) ($row['label'] ?? ''); }, $terms);
ok(in_array('cerveza', $labels, true), 'actual evidence language remains in top terms');
ok(!in_array('mahou', $labels, true), 'provider-query-only brand term is not promoted to evidence language');
ok(!in_array('beer', $labels, true), 'provider-query-only English category term is not promoted');
ok(!in_array('beverage', $labels, true), 'provider-query-only category term is not promoted');
ok(!in_array('fyp', $labels, true) && !in_array('viral', $labels, true) && !in_array('trending', $labels, true), 'platform noise removed from strategic terms');

$top_hashtags_method = new ReflectionMethod('FIDTF_Report_Service', 'top_hashtags');
$top_hashtags_method->setAccessible(true);
$hashtags = $top_hashtags_method->invoke(null, [
    ['hashtags' => ['mahou', 'fyp', 'viral', 'trending', 'madrid'], 'caption_or_text' => '#fyp #viral #mahou #madrid'],
], 20);
$tag_labels = array_map(static function($row) { return (string) ($row['label'] ?? ''); }, $hashtags);
ok(in_array('#mahou', $tag_labels, true), 'brand hashtag remains');
ok(in_array('#madrid', $tag_labels, true), 'market hashtag remains');
ok(!in_array('#fyp', $tag_labels, true) && !in_array('#viral', $tag_labels, true) && !in_array('#trending', $tag_labels, true), 'platform hashtag noise removed');

$quality_method = new ReflectionMethod('FIDTF_Report_Service', 'evidence_quality_summary');
$quality_method->setAccessible(true);
$quality = $quality_method->invoke(null, [
    ['source_key' => 'tiktok', 'evidence_tier' => 'support', 'metrics' => ['views' => 1000], 'url' => 'https://example.test/a'],
    ['source_key' => 'instagram', 'evidence_tier' => 'creative_only', 'metrics' => ['likes' => 20], 'url' => 'https://example.test/b'],
    ['source_key' => 'google_trends', 'evidence_tier' => 'weak', 'metrics' => [], 'url' => 'https://example.test/c'],
], [
    'tiktok' => ['relevant_count' => 1],
    'instagram' => ['relevant_count' => 1],
    'google_trends' => ['relevant_count' => 1],
], ['engagement_ranges' => ['low' => 1, 'medium' => 1, 'high' => 0]]);
ok((int) ($quality['decision_ready_rows'] ?? 0) === 1, 'decision-ready rows exclude creative-only and weak rows');
ok((int) ($quality['decision_ready_source_families'] ?? 0) === 1, 'decision-ready source families counted separately from raw source coverage');

$confidence_method = new ReflectionMethod('FIDTF_Report_Service', 'confidence_breakdown');
$confidence_method->setAccessible(true);
$confidence = $confidence_method->invoke(null, [
    'tiktok' => ['relevant_count' => 10],
    'instagram' => ['relevant_count' => 10],
    'google_trends' => ['relevant_count' => 1],
], [
    'decision_ready_rows' => 20,
    'decision_ready_source_families' => 2,
], [
    'direct_count' => 20,
    'creative_only_count' => 5,
]);
ok(($confidence['market_demand_confidence'] ?? '') === 'weak_single_point_not_validated', 'single demand row is not treated as validated demand');
ok(($confidence['client_readiness'] ?? '') === 'internal_validation_only', 'single demand row blocks client-readiness');

fwrite(STDOUT, "FIDTF v0.3.49 live evidence readiness debug checks passed.\n");
