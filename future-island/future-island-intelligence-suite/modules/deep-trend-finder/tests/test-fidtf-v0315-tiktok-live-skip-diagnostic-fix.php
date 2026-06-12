<?php
require_once __DIR__ . '/test-fidtf-v0314-tiktok-retryable-dispatch-recovery.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$admin_code = file_get_contents($root . '/includes/class-fidtf-admin.php');
$job_service_code = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$run_service_code = file_get_contents($root . '/includes/class-fidtf-run-service.php');
$frontend_code = file_get_contents($root . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.15
// [v1.1.0 archived] removed obsolete standalone-addon admin-copy lineage assertion: 'admin copy describes live skipped diagnostic fix' (versioned release copy no longer present in unified admin UI)
ok(strpos($job_service_code, '$source_key === \'tiktok\' && $job_status === \'skipped\' && FIDTF_Settings::tiktok_dispatch_allowed()') !== false, 'live-intended TikTok skipped dispatch is remapped server-side');
ok(strpos($job_service_code, 'TikTok live dispatch was skipped before provider collection. Check admin diagnostics.') !== false, 'source job service has explicit TikTok skipped copy');
ok(strpos($run_service_code, 'TikTok live dispatch was skipped before provider collection. Check admin diagnostics. It is not planned-only.') !== false, 'run-level copy does not call live TikTok skipped planned-only');
ok(strpos($frontend_code, "source === 'tiktok' && status === 'skipped'") !== false, 'frontend handles skipped TikTok status explicitly');
ok(strpos($frontend_code, "return skippedMsg || 'TikTok live dispatch was skipped before provider collection. Check admin diagnostics.';") !== false, 'frontend skipped TikTok status does not fall through to planned-only copy');

$dispatch = [
    'status' => 'skipped',
    'error_code' => 'invalid_apidojo_tiktok_input',
    'message' => 'Selected TikTok actor is apidojo/tiktok-scraper, but generated input does not contain a recognized search keyword field.',
    'retryable' => false,
    'raw_count' => 0,
    'normalized_count' => 0,
    'relevant_count' => 0,
];
$msg = FIDTF_Source_Job_Service::message_for_dispatch_state('tiktok', 'skipped', $dispatch);
ok(strpos($msg, 'Selected TikTok actor is apidojo/tiktok-scraper') !== false, 'TikTok skipped message preserves provider diagnostic fallback');

$fallback_msg = FIDTF_Source_Job_Service::message_for_dispatch_state('tiktok', 'skipped', []);
ok($fallback_msg === 'TikTok live dispatch was skipped before provider collection. Check admin diagnostics.', 'TikTok skipped fallback is explicit, not planned-only');

fwrite(STDOUT, "FIDTF v0.3.15 TikTok live skipped diagnostic fix checks passed: {$checks} / {$checks}\n");
