<?php
require_once __DIR__ . '/test-fidtf-v012-foundation-corrections.php';

// v0.1.3 async-readiness checks.
$plugin_file = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version includes async-readiness baseline or newer build

$schema = implode("\n", FIDTF_DB::schema_sql());
ok(strpos($schema, 'request_meta_json LONGTEXT NULL') !== false, 'runs schema includes request_meta_json');

$terms = [];
for ($i = 1; $i <= 40; $i++) { $terms[] = 'term' . $i; }
$sanitized_v013 = FIDTF_Run_Service::sanitize_request([
    'user_brief' => '<b>brief</b>',
    'objective' => '<script>bad()</script>Find angles',
    'market' => '<em>Spain</em>',
    'language' => 'ES_es',
    'date_range' => 'custom',
    'keywords' => implode(',', $terms),
    'brand' => '<strong>Brand</strong>',
    'company' => '<i>Company</i>',
    'competitors' => implode(',', $terms),
    'audience' => '<u>Spanish marketers</u>',
    'exclude_terms' => implode(',', $terms),
    'channels' => ['tiktok', 'reddit', 'unknown_source'],
]);
ok($sanitized_v013['date_range'] === 'custom', 'custom date_range accepted for future UI support');
ok($sanitized_v013['brand'] === 'Brand', 'brand sanitized separately');
ok($sanitized_v013['company'] === 'Company', 'company sanitized separately');
ok(count($sanitized_v013['competitors']) === 20, 'competitors list max is 20');
ok(count($sanitized_v013['exclude_terms']) === 30, 'exclude_terms list max is 30');
ok(strpos($sanitized_v013['objective'], '<') === false, 'objective context strips HTML');

$v013_plan = FIDTF_AI_Planner::build_plan($sanitized_v013);
$context = $v013_plan['request_context'] ?? [];
foreach (['user_brief', 'objective', 'market', 'language', 'date_range', 'keywords', 'brand', 'company', 'competitors', 'audience', 'exclude_terms', 'channels'] as $context_key) {
    ok(array_key_exists($context_key, $context), 'request_context includes ' . $context_key);
}
ok($context['date_range'] === 'custom', 'request_context includes date_range');
ok($context['market'] === 'Spain', 'request_context includes sanitized market');
ok($context['language'] === 'es_es', 'request_context includes sanitized language');
ok(count($context['keywords']) === 30, 'planner context keyword list is capped and structured');
ok(count($context['exclude_terms']) === 30, 'planner context exclude_terms preserves 30-item limit');
ok(in_array('tiktok', $context['channels'], true) && in_array('reddit', $context['channels'], true), 'planner context includes selected channels');

$external_plan = [
    'sources' => [
        'tiktok' => ['queries' => ['external query'], 'max_items' => 5],
    ],
    'request_context' => ['brand' => '<b>External Brand</b>'],
];
$validated = FIDTF_AI_Planner::validate_plan($external_plan, $sanitized_v013);
ok(!is_wp_error($validated), 'external planner validates');
ok(($validated['request_context']['brand'] ?? '') === 'External Brand', 'external planner context is sanitized');
ok(($validated['request_context']['market'] ?? '') === 'Spain', 'missing external planner context merges from request');
ok(($validated['request_context']['date_range'] ?? '') === 'custom', 'missing external date_range merges from request');
ok(strpos(json_encode($validated['request_context']), '<') === false, 'merged planner context contains no HTML');

$dispatch_error = FIDTF_Source_Job_Service::normalize_dispatch_result(new WP_Error('live_dispatch_disabled', 'disabled', ['retryable' => false]));
ok($dispatch_error['ok'] === false, 'canonical provider result exposes ok=false');
ok($dispatch_error['status'] === 'skipped', 'non-retryable WP_Error maps to skipped');
ok($dispatch_error['error_code'] === 'live_dispatch_disabled', 'provider error_code sanitized');
ok($dispatch_error['retryable'] === false, 'provider retryable false preserved');
$dispatch_retry = FIDTF_Source_Job_Service::normalize_dispatch_result(new WP_Error('temporary_provider_timeout', 'timeout', ['retryable' => true]));
ok($dispatch_retry['status'] === 'retryable_failed' && $dispatch_retry['retryable'] === true, 'retryable WP_Error maps to retryable_failed');
$dispatch_queue = FIDTF_Source_Job_Service::normalize_dispatch_result(['ok' => true, 'status' => 'queued', 'provider_run_id' => ' run-123 ', 'raw_count' => 9]);
ok($dispatch_queue['status'] === 'queued', 'queued provider result accepted');
ok($dispatch_queue['provider_run_id'] === 'run-123', 'provider_run_id sanitized');
ok($dispatch_queue['raw_count'] === 9, 'provider raw_count preserved');
$dispatch_items = FIDTF_Source_Job_Service::normalize_dispatch_result([['id' => 'x', 'text' => 'Verano silencioso']]);
ok($dispatch_items['ok'] === true && $dispatch_items['status'] === 'completed' && count($dispatch_items['items']) === 1, 'list provider result normalizes as completed items');
foreach (['ok', 'status', 'provider_run_id', 'raw_count', 'normalized_count', 'relevant_count', 'error_code', 'retryable', 'message', 'items'] as $key) {
    ok(array_key_exists($key, $dispatch_items), 'canonical provider result key exists: ' . $key);
}

$fidtf_current_user_id = 42;
$fidtf_workspace_id = 420;
$created_v013 = FIDTF_Run_Service::create_run($sanitized_v013, 42);
ok(!is_wp_error($created_v013), 'v0.1.3 contextual run created');
$run_v013 = FIDTF_Run_Service::get_run((int) $created_v013['run_id']);
$meta = json_decode((string) ($run_v013['request_meta_json'] ?? ''), true);
ok(is_array($meta), 'request_meta_json stored as JSON');
ok(($meta['brand'] ?? '') === 'Brand' && ($meta['company'] ?? '') === 'Company', 'request_meta_json stores brand/company');
ok(count((array) ($meta['competitors'] ?? [])) === 20, 'request_meta_json stores limited competitors');
ok(count((array) ($meta['exclude_terms'] ?? [])) === 30, 'request_meta_json stores limited exclude_terms');
ok(($created_v013['dispatch_results'][0]['status'] ?? '') === 'skipped', 'disabled dispatch returns canonical skipped result');
ok(($created_v013['dispatch_results'][0]['job_status'] ?? '') === 'waiting_for_provider', 'disabled dispatch keeps UI job waiting_for_provider');

$empty_report = FIDTF_Report_Service::build_or_get_report((int) $created_v013['run_id'], $run_v013, true);
ok(($empty_report['report_kind'] ?? '') === 'planned_run_not_evidence_report', 'no-evidence report is planned-run type');
ok(in_array('This is a planned run, not an evidence report yet.', (array) ($empty_report['can_say'] ?? []), true), 'no-evidence report contains planned-run language');
ok(in_array('No live scraping has been executed.', (array) ($empty_report['can_say'] ?? []), true), 'no-evidence report says no live scraping');
ok(in_array('No AI web research has been executed.', (array) ($empty_report['can_say'] ?? []), true), 'no-evidence report says no AI web research');
ok(empty($empty_report['insights']), 'no-evidence report has no fake insight entries');

$source_job_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-source-job-service.php');
ok(strpos($source_job_code, 'normalize_dispatch_result') !== false, 'source job service has canonical provider result normalizer');
ok(strpos($source_job_code, "['queued', 'running', 'completed', 'failed', 'retryable_failed', 'skipped']") !== false, 'provider status contract is explicit');
ok(strpos($source_job_code, '$max_limit = !empty($args[\'internal\']) ? 200 : 50') !== false, 'public items endpoint remains clamped while internal reads are separate');
$rest_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-rest-controller.php');
ok(strpos($rest_code, 'min(50') !== false && strpos($rest_code, 'per_page') !== false, 'REST items per_page clamps to 50');
$report_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-report-service.php');
ok(strpos($report_code, "'internal' => true") !== false, 'report uses internal item read path');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');
ok(strpos($js, 'var pollStates = {}') !== false, 'frontend keeps poll state per run');
ok(strpos($js, 'attempts: 0') !== false && strpos($js, 'maxAttempts') !== false && strpos($js, 'intervalMs: 6000') !== false, 'frontend polling state has attempts maxAttempts intervalMs');
ok(strpos($js, 'window.setTimeout') !== false && strpos($js, 'stopPolling(runId)') !== false, 'frontend polling is controlled with stopPolling');
ok(strpos($js, "request('/runs/' + encodeURIComponent(runId)") !== false, 'polling calls GET /runs/{id}');
ok(strpos($js, 'function updateSourceRows') !== false, 'source rows update function exists');
ok(strpos($js, 'Planned brief only.') !== false && strpos($js, 'No live source will run with the current settings.') !== false, 'planning mode stable message exists');
ok(strpos($js, 'renderWarning') !== false, 'fetch warning path exists without destroying main UI');
ok(strpos($js, 'renderItems(itemContainer') !== false && strpos($js, 'per_page=20') !== false, 'Show More still uses 20-item pages');
ok(strpos($js, 'setInterval') === false, 'frontend no longer uses infinite setInterval polling');

$template = file_get_contents(dirname(__DIR__) . '/templates/shortcode-deep-trend-finder.php');
ok(strpos($template, 'name="company"') !== false, 'company field exists in form');
ok(strpos($template, 'value="custom"') !== false, 'custom date_range option exists');

$normalizer_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-normalizer.php');
ok(strpos($normalizer_code, 'No frame-by-frame visual analysis was performed.') !== false, 'evidence limitation mentions no frame analysis');
$video_check = FIDTF_Normalizer::normalize('tiktok', ['id' => 'vid-v013', 'desc' => 'verano silencioso', 'videoUrl' => 'https://cdn.test/vid.mp4']);
ok(($video_check['evidence_quality']['video_file'] ?? true) === false, 'video URL still does not imply video_file');
ok(in_array('No frame-by-frame visual analysis was performed.', $video_check['evidence_quality']['limitations'], true), 'frame limitation is present in evidence quality');

fwrite(STDOUT, "FIDTF v0.1.3 async-readiness checks passed: {$checks} / {$checks}\n");
