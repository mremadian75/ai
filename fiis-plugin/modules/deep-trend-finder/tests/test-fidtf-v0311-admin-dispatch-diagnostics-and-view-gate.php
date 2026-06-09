<?php
require_once __DIR__ . '/test-fidtf-v0310-core-social-apify-execution-parity.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$rest_code = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$admin_code = file_get_contents($root . '/includes/class-fidtf-admin.php');
$filter_code = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');
$job_code = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$js = file_get_contents($root . '/assets/js/fidtf-frontend.js');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.12
// [v1.1.0 archived] removed obsolete standalone-addon version/lineage assertion: admin info copy describes v0.3.12 goal

// 1. Relevance filter records view_count_missing without hard-failing.
$missing = FIDTF_Normalizer::normalize('tiktok', [
    'aweme_id' => 'missing-1',
    'desc' => 'mahou beer format',
    'share_url' => 'https://www.tiktok.com/@x/video/1',
    'author' => ['unique_id' => 'creator'],
    // No 'statistics' or 'metrics' -> view count is missing.
]);
$missing_score = FIDTF_Relevance_Filter::score($missing, ['keywords' => ['mahou'], 'market' => 'Spain'], ['queries' => ['mahou']]);
ok(!empty($missing_score['view_count_missing']), 'relevance score flags view_count_missing when metrics absent');
ok(empty($missing_score['below_min_views']), 'view_count_missing path does not mis-flag below_min_views');
ok(strpos((string) $missing_score['reason'], 'minimum view threshold') === false, 'missing view item is NOT hard-rejected by view gate (existing relevance logic decides)');

// 2. summarize_view_diagnostics aggregates flags across scored items.
ok(method_exists('FIDTF_Relevance_Filter', 'summarize_view_diagnostics'), 'FIDTF_Relevance_Filter::summarize_view_diagnostics exists');
$sample_items = [
    ['view_count_missing' => true,  'below_min_views' => false],
    ['view_count_missing' => false, 'below_min_views' => true],
    ['view_count_missing' => false, 'below_min_views' => false],
    ['view_count_missing' => false, 'below_min_views' => false],
];
$diag = FIDTF_Relevance_Filter::summarize_view_diagnostics($sample_items);
ok(($diag['view_count_missing'] ?? null) === 1, 'view diagnostics count missing-views items');
ok(($diag['below_min_views'] ?? null) === 1, 'view diagnostics count below-threshold items');
ok(($diag['view_gate_passed'] ?? null) === 2, 'view diagnostics count items that passed the view gate');

// 3. source-job-service ingest_items now records view diagnostics in return shape.
ok(strpos($job_code, 'summarize_view_diagnostics') !== false, 'source-job-service calls summarize_view_diagnostics during ingest');
ok(strpos($job_code, "'view_count_missing' =>") !== false, 'source-job-service returns view_count_missing from ingest');
ok(strpos($job_code, "'view_gate_passed' =>") !== false, 'source-job-service returns view_gate_passed from ingest');
ok(strpos($job_code, "'fidtf_view_diag_'") !== false, 'source-job-service persists view diagnostics in a transient');
ok(strpos($job_code, 'view_diagnostics_for_job') !== false, 'source-job-service exposes view_diagnostics_for_job() accessor');

// 4. REST controller exposes view diagnostics on the per-job dispatch_diagnostic.
ok(strpos($rest_code, "'view_diagnostics'") !== false, 'REST controller exposes view_diagnostics on per-job dispatch_diagnostic');
ok(strpos($rest_code, 'view_diagnostics_for_job') !== false, 'REST controller reads view diagnostics through the job-service accessor');
ok(strpos($rest_code, 'configured_min_views') !== false, 'REST controller surfaces the configured min_views threshold');

// 5. Admin settings UI exposes the minimum-views input.
ok(strpos($admin_code, '[tiktok_min_views]') !== false, 'Admin settings UI exposes tiktok_min_views input');
ok(strpos($admin_code, 'Discovery actors may not enforce minimum views at source') !== false, 'Admin UI explains why the threshold is post-ingestion');

// 6. Frontend has an admin-only dispatch diagnostic panel.
ok(strpos($js, 'dispatchDiagnosticPanelHtml') !== false, 'frontend builds dispatch diagnostic panel HTML');
ok(strpos($js, 'isAdminContext') !== false, 'frontend gates dispatch diagnostic panel to admin context');
ok(strpos($js, "data-fidtf-dispatch-diagnostic") !== false, 'frontend marks the admin diagnostic block with a data attribute');
ok(strpos($js, 'View gate') !== false, 'frontend renders the view-gate diagnostic line');
ok(strpos($js, 'Output counts') !== false, 'frontend renders the output-counts diagnostic line');
ok(strpos($js, 'Provider mode used') !== false, 'frontend renders the provider-mode-used diagnostic line');
ok(strpos($js, 'Outcome reason') !== false, 'frontend renders the outcome reason diagnostic line');
ok(strpos($css, '.fidtf-dispatch-diagnostic') !== false, 'CSS styles the admin diagnostic panel');

fwrite(STDOUT, "FIDTF v0.3.11 admin dispatch diagnostics and view gate checks passed: {$checks} / {$checks}\n");
