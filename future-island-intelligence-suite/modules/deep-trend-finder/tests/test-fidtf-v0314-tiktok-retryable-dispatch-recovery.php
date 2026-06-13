<?php
require_once __DIR__ . '/test-fidtf-v0313-apidojo-tiktok-input-schema-hotfix.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$job_service_code = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$run_service_code = file_get_contents($root . '/includes/class-fidtf-run-service.php');
$rest_code = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$frontend_code = file_get_contents($root . '/assets/js/fidtf-frontend.js');
$admin_code = file_get_contents($root . '/includes/class-fidtf-admin.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.14
// [v1.1.0 archived] removed obsolete standalone-addon admin-copy lineage assertion: 'admin copy describes retryable dispatch recovery' (versioned release copy no longer present in unified admin UI)
ok(strpos($job_service_code, 'message_for_dispatch_state') !== false, 'source job service has dispatch-state message helper');
ok(strpos($job_service_code, "provider_run_id === ''\n                ? self::normalize_dispatch_result(\$adapter->start(\$payload))") !== false || strpos($job_service_code, "provider_run_id === ''\r\n                ? self::normalize_dispatch_result(\$adapter->start(\$payload))") !== false, 'retryable TikTok jobs without provider_run_id can restart discovery dispatch');
ok(strpos($job_service_code, "record_refresh_backoff_if_needed(\$job_id, \$dispatch)") !== false, 'initial retryable dispatch records refresh backoff');
ok(strpos($job_service_code, "['completed_no_relevant_evidence', 'failed', 'skipped']") !== false, 'retryable_failed is no longer marked completed in the initial dispatch branch');
ok(strpos($job_service_code, "'error_message' => sanitize_text_field((string) (\$dispatch['message'] ?? ''))") !== false, 'dispatch diagnostics persist provider error_message');
ok(strpos($job_service_code, "'retry_after' => max(0, (int) (\$dispatch['retry_after'] ?? 0))") !== false, 'dispatch diagnostics persist retry_after');
ok(strpos($rest_code, "'error_message' => sanitize_text_field((string) (\$stored_diag['error_message'] ?? ''))") !== false, 'REST job diagnostics expose stored error_message to admins');
ok(strpos($rest_code, "'retry_after' => max(0, (int) (\$stored_diag['retry_after'] ?? 0))") !== false, 'REST job diagnostics expose retry_after to admins');
ok(strpos($run_service_code, 'TikTok trend run is waiting for a retryable provider response. It is not planned-only.') !== false, 'run-level message does not call retryable TikTok failures planned-only');
ok(strpos($frontend_code, "status === 'retryable_failed'") !== false, 'frontend handles retryable_failed source status explicitly');
ok(strpos($frontend_code, 'TikTok provider is temporarily busy. The job will retry automatically.') !== false, 'frontend shows provider_busy retry copy');
ok(strpos($frontend_code, "var FINAL_STATUSES = ['completed', 'completed_no_relevant_evidence', 'evidence_ingested', 'failed', 'skipped'];") !== false, 'frontend no longer treats retryable_failed as a final polling state');
ok(strpos($frontend_code, 'maxAttempts: isLiveDispatchEnabled() ? 30 : 2') !== false, 'frontend polling window is long enough for retryable provider backoff');
ok(strpos($frontend_code, 'retry the provider dispatch instead of treating the source as planned-only') !== false, 'frontend poll notice explains retryable dispatch truthfully');

$dispatch = [
    'status' => 'retryable_failed',
    'error_code' => 'provider_busy',
    'message' => 'Original provider busy message.',
    'retryable' => true,
    'raw_count' => 0,
    'normalized_count' => 0,
    'relevant_count' => 0,
];
$msg = FIDTF_Source_Job_Service::message_for_dispatch_state('tiktok', 'retryable_failed', $dispatch);
ok($msg === 'TikTok provider is temporarily busy. The job will retry automatically.', 'retryable provider_busy message is truthful');

$fallback = FIDTF_Source_Job_Service::message_for_ingest_counts('tiktok', 0, 0, 0, 'Provider diagnostic message.');
ok($fallback === 'Provider diagnostic message.', 'message_for_ingest_counts preserves fallback for zero-count provider failures');

fwrite(STDOUT, "FIDTF v0.3.14 TikTok retryable dispatch recovery checks passed: {$checks} / {$checks}\n");
