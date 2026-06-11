<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');
$filter_src = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');
$adapter_src = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.53
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: version constant bumped to v0.3.53
ok(strpos($report_src, "'schema_version' => 'fidtf.report.v3.8'") !== false, 'report schema v3.7 marker');
ok(strpos($normalizer_src, 'provider_malformed_empty_item') !== false, 'normalizer marks malformed empty provider rows');
ok(strpos($filter_src, 'Provider returned a malformed/empty social item') !== false, 'relevance filter excludes malformed provider rows');
ok(strpos($adapter_src, 'instagram_post_scraper') !== false, 'instagram post scraper has a dedicated actor profile');

$request = [
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => 'Mahou San Isidro cerveza',
    'brand' => 'Mahou',
];

// Live Instagram QA pattern observed: provider returned only the profile URL,
// without caption, metrics, date, author, hashtags or post content. This must
// never become evidence merely because the profile URL exists.
$malformed = FIDTF_Normalizer::normalize('instagram', [
    'url' => 'https://www.instagram.com/mahou_es',
]);
$quality = FIDTF_Normalizer::evidence_quality($malformed);
ok(!empty($quality['provider_malformed_empty_item']), 'profile-url-only Instagram row is marked malformed/empty');

$score = FIDTF_Relevance_Filter::score($malformed, $request, []);
ok(($score['recommended_use'] ?? '') === 'hide', 'malformed Instagram row hidden from report evidence');
ok(($score['evidence_tier'] ?? '') === 'noise', 'malformed Instagram row is noise');
ok(($score['marketing_allowed_use'] ?? '') === 'excluded', 'malformed Instagram row excluded from marketing use');
ok(stripos((string) ($score['reason'] ?? ''), 'malformed/empty') !== false, 'malformed reason is explicit');

$quality_method = new ReflectionMethod('FIDTF_Report_Service', 'evidence_quality_summary');
$quality_method->setAccessible(true);
$summary = $quality_method->invoke(null, [
    array_merge($malformed, [
        'source_key' => 'instagram',
        'evidence_tier' => 'noise',
        'recommended_use' => 'hide',
        'provider_malformed_empty_item' => true,
    ]),
], ['instagram' => ['relevant_count' => 0]], []);
ok((int) ($summary['provider_malformed_empty_rows'] ?? 0) === 1, 'report diagnostics count malformed provider rows');

fwrite(STDOUT, "FIDTF v0.3.53 live Instagram malformed provider row gate checks passed.\n");
