<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$filter_src = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');
$job_src = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.49
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: version constant bumped to v0.3.49
ok(strpos($report_src, "'schema_version' => 'fidtf.report.v3.8'") !== false, 'report schema v3.6 marker');
ok(strpos($filter_src, 'author handles can be false positives') !== false, 'social author handles excluded from semantic evidence');
ok(strpos($job_src, 'row_is_report_relevant') !== false, 'REST evidence-card hidden/noise gate exists');

$contains = new ReflectionMethod('FIDTF_Relevance_Filter', 'contains');
$contains->setAccessible(true);
ok($contains->invoke(null, 'tanzaniatiktok viral dance', 'san') === false, 'short token does not match inside unrelated words');
ok($contains->invoke(null, 'Mahou celebra San Isidro en Madrid', 'San Isidro') === true, 'multi-word phrase still matches real phrase');
ok($contains->invoke(null, 'ice beer capcut clip', 'beer') === true, 'standalone beer token still matches when separated');
ok($contains->invoke(null, 'ice.beer50 capcut clip', 'beer') === false, 'punctuated handle fragment does not match');
ok($contains->invoke(null, 'icebeer50 capcut clip', 'beer') === false, 'embedded handle fragment does not match');

$request = [
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => 'Mahou San Isidro cerveza',
    'brand' => 'Mahou',
];

$author_false_positive = FIDTF_Normalizer::normalize('tiktok', [
    'id' => 'live-author-handle-noise',
    'text' => '#CapCut',
    'textLanguage' => 'es',
    'locationCreated' => 'ES',
    'webVideoUrl' => 'https://www.tiktok.com/@ice.beer50/video/7637685813299514644',
    'authorMeta' => ['name' => 'ice.beer50'],
    'playCount' => 90000,
    'diggCount' => 500,
    'commentCount' => 12,
    'shareCount' => 8,
    'searchQuery' => 'Mahou San Isidro cerveza',
]);
$score_author = FIDTF_Relevance_Filter::score($author_false_positive, $request, []);
ok(empty($score_author['matched_core_keywords'] ?? []), 'author handle did not create core keyword matches');
ok(($score_author['recommended_use'] ?? '') === 'hide', 'provider-query-only social row hidden from evidence cards');
ok(($score_author['evidence_tier'] ?? '') === 'noise', 'provider-query-only social row is noise');

$row_gate = new ReflectionMethod('FIDTF_Source_Job_Service', 'row_is_report_relevant');
$row_gate->setAccessible(true);
ok($row_gate->invoke(null, [
    'relevance_score' => 34,
    'normalized_json' => json_encode(['evidence_tier' => 'noise', 'recommended_use' => 'hide']),
]) === false, 'hidden/noise row does not load as evidence card');
ok($row_gate->invoke(null, [
    'relevance_score' => 62,
    'normalized_json' => json_encode(['evidence_tier' => 'support', 'recommended_use' => 'show']),
]) === true, 'support row still loads as evidence card');

$summary = new ReflectionMethod('FIDTF_Report_Service', 'source_summary');
$summary->setAccessible(true);
$source_summary = $summary->invoke(null, [], [
    ['source_key' => 'tiktok', 'evidence_tier' => 'noise', 'recommended_use' => 'hide'],
    ['source_key' => 'tiktok', 'evidence_tier' => 'support', 'recommended_use' => 'show'],
    ['source_key' => 'instagram', 'evidence_tier' => 'creative_only', 'recommended_use' => 'show'],
]);
ok((int)($source_summary['tiktok']['relevant_count'] ?? 0) === 1, 'source relevant_count excludes hidden/noise rows');
ok((int)($source_summary['tiktok']['weak_or_noise_count'] ?? 0) === 1, 'source weak/noise diagnostic preserved');
ok((int)($source_summary['instagram']['creative_only_count'] ?? 0) === 1, 'creative-only diagnostic preserved');

fwrite(STDOUT, "FIDTF v0.3.49 live noise and evidence-card gate checks passed.\n");
