<?php
require_once __DIR__ . '/test-fidtf-v013-async-readiness.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-rest-controller.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-admin.php';

$plugin_file = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version includes v0.1.4 bridge-safety or newer AI planner bridge build

// Provider hook gating.
global $fidtf_options, $fidtf_filter_handlers, $fidtf_filter_calls, $fidtf_is_admin;
$fidtf_filter_handlers = [];
$fidtf_filter_calls = [];
$settings = FIDTF_Settings::get();
$settings['enable_live_dispatch'] = false;
$settings['enable_ai_research_bridge'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;

$fidtf_filter_handlers['fidtf_dispatch_source_job'] = function($tag, $value, $context) {
    return ['ok' => true, 'status' => 'queued', 'provider_run_id' => 'should-not-fire'];
};
$tiktok_provider = new FIDTF_Provider_TikTok();
$disabled_dispatch = $tiktok_provider->dispatch(['queries' => ['verano']], ['market' => 'Spain']);
ok(is_wp_error($disabled_dispatch), 'disabled live dispatch returns WP_Error');
ok($disabled_dispatch->get_error_code() === 'live_dispatch_disabled', 'disabled live dispatch returns live_dispatch_disabled');
ok(empty($fidtf_filter_calls['fidtf_dispatch_source_job']), 'live_dispatch_disabled prevents fidtf_dispatch_source_job filter from firing');

$settings['enable_live_dispatch'] = true;
$settings['enable_tiktok_live_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_calls = [];
$enabled_dispatch = $tiktok_provider->dispatch(['queries' => ['verano']], ['market' => 'Spain']);
ok(is_array($enabled_dispatch) && ($enabled_dispatch['status'] ?? '') === 'queued', 'live dispatch enabled allows source filter result');
ok(($fidtf_filter_calls['fidtf_dispatch_source_job'] ?? 0) === 1, 'live dispatch enabled calls fidtf_dispatch_source_job filter once');

$fidtf_filter_handlers = [];
$fidtf_filter_calls = [];
$missing_bridge = $tiktok_provider->dispatch(['queries' => ['verano']], ['market' => 'Spain']);
ok(is_wp_error($missing_bridge) && $missing_bridge->get_error_code() === 'tiktok_provider_unavailable', 'TikTok live bridge enabled without provider returns tiktok_provider_unavailable');

$ai_provider = new FIDTF_Provider_AI();
$settings['enable_live_dispatch'] = true;
$settings['enable_ai_research_bridge'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_handlers['fidtf_ai_research_items'] = function($tag, $value) {
    return [['title' => 'should not fire']];
};
$fidtf_filter_calls = [];
$ai_disabled = $ai_provider->dispatch(['research_questions' => ['What is trending?']], ['market' => 'Spain']);
ok(is_wp_error($ai_disabled) && $ai_disabled->get_error_code() === 'ai_research_bridge_missing', 'AI bridge disabled returns ai_research_bridge_missing');
ok(empty($fidtf_filter_calls['fidtf_ai_research_items']), 'AI bridge disabled prevents fidtf_ai_research_items filter from firing');

$settings['enable_ai_research_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_calls = [];
$ai_enabled = $ai_provider->dispatch(['research_questions' => ['What is trending?']], ['market' => 'Spain']);
ok(is_array($ai_enabled) && count($ai_enabled) === 1, 'AI bridge enabled allows fidtf_ai_research_items filter result');
ok(($fidtf_filter_calls['fidtf_ai_research_items'] ?? 0) === 1, 'AI bridge enabled calls fidtf_ai_research_items filter once');

// Status model.
ok(in_array('planned_waiting_for_sources', FIDTF_Run_Service::allowed_statuses(), true), 'run allowed_statuses exists and includes planned_waiting_for_sources');
ok(in_array('waiting_for_provider', FIDTF_Source_Job_Service::allowed_statuses(), true), 'source allowed_statuses exists and includes waiting_for_provider');
ok(FIDTF_Run_Service::sanitize_status('not_real', 'failed') === 'failed', 'unknown run status sanitizes to fallback');
ok(FIDTF_Source_Job_Service::sanitize_status('not_real', 'skipped') === 'skipped', 'unknown source status sanitizes to fallback');

$settings['enable_live_dispatch'] = false;
$settings['enable_ai_research_bridge'] = false;
$settings['enable_credit_reservation'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$run_for_status = FIDTF_Run_Service::create_run([
    'user_brief' => 'Bridge safety trend check',
    'keywords' => ['bridge safety'],
    'channels' => ['tiktok'],
], 88);
ok(!is_wp_error($run_for_status), 'v0.1.4 run created for status checks');
$run_id = (int) $run_for_status['run_id'];
$jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
$job_id = (int) ($jobs[0]['id'] ?? 0);
FIDTF_Run_Service::update_run($run_id, ['status' => 'invalid_run_status']);
FIDTF_Source_Job_Service::update_job($job_id, ['status' => 'invalid_source_status']);
$clamped_run = FIDTF_Run_Service::get_run($run_id);
$clamped_job = FIDTF_Source_Job_Service::get_job($job_id);
ok(($clamped_run['status'] ?? '') === 'created', 'update_run clamps invalid status');
ok(($clamped_job['status'] ?? '') === 'planned', 'update_job clamps invalid status');
$unknown_dispatch = FIDTF_Source_Job_Service::normalize_dispatch_result(['ok' => true, 'status' => 'alien_provider_status']);
ok($unknown_dispatch['status'] === 'queued', 'provider result with unknown status does not leak unknown status');

// Credit mode.
ok(FIDTF_Credit_Service::credit_mode(['status' => 'planned_waiting_for_sources']) === 'planning_only', 'credit_mode planning_only for planned run');
ok(FIDTF_Credit_Service::credit_mode(['credits_reserved' => 3.5, 'final_credits_settled' => 0]) === 'reserved', 'credit_mode reserved when credits_reserved > 0');
ok(FIDTF_Credit_Service::credit_mode(['credits_reserved' => 3.5, 'final_credits_settled' => 1.25]) === 'settled', 'credit_mode settled when final_credits_settled > 0');

$run_response_ref = new ReflectionMethod('FIDTF_REST_Controller', 'prepare_run_for_response');
$run_response_ref->setAccessible(true);
$prepared_run = $run_response_ref->invoke(null, ['id' => 1, 'status' => 'alien_status', 'credits_reserved' => 0, 'final_credits_settled' => 0]);
ok(($prepared_run['status'] ?? '') === 'created', 'REST run response never returns unknown status');
ok(array_key_exists('credit_mode', $prepared_run), 'REST run response includes credit_mode');
ok(array_key_exists('credits_reserved', $prepared_run) && array_key_exists('final_credits_settled', $prepared_run), 'REST run response includes credit fields');

$job_response_ref = new ReflectionMethod('FIDTF_REST_Controller', 'prepare_jobs_for_response');
$job_response_ref->setAccessible(true);
$job_row = [
    'id' => 1,
    'run_id' => 1,
    'source_key' => 'tiktok',
    'status' => 'alien_status',
    'provider' => 'apify_bridge',
    'actor_id' => 'private/actor',
    'provider_run_id' => 'run-private',
    'query_payload_json' => wp_json_encode(['queries' => ['q'], 'max_items' => 10]),
];
$fidtf_is_admin = false;
$job_public = $job_response_ref->invoke(null, [$job_row]);
ok(($job_public[0]['status'] ?? '') === 'planned', 'REST source job response never returns unknown status');
ok(!array_key_exists('actor_id', $job_public[0]), 'non-admin response hides actor_id');
$fidtf_is_admin = true;
$job_admin = $job_response_ref->invoke(null, [$job_row]);
ok(($job_admin[0]['actor_id'] ?? '') === 'private/actor', 'admin response can show actor_id');
$fidtf_is_admin = false;

$report = FIDTF_Report_Service::build_or_get_report($run_id, FIDTF_Run_Service::get_run($run_id), true);
ok(array_key_exists('credit_mode', $report), 'report includes credit_mode');
ok(in_array('No final credits were settled for this planned/no-evidence run.', (array) ($report['can_say'] ?? []), true), 'report explains no final credit settlement for planned/no-evidence run');

// Admin/docs/source checks.
$admin_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-admin.php');
ok(strpos($admin_code, 'v0.1.1 hardens') === false, 'admin copy no longer says v0.1.1 hardening copy');
ok(strpos($admin_code, 'Live dispatch is disabled. Runs are planned only and no provider calls will be made.') !== false, 'live dispatch disabled warning exists');
ok(strpos($admin_code, 'Live dispatch is enabled. Provider bridge hooks may start external jobs and consume provider/API costs.') !== false, 'live dispatch enabled cost warning exists');
ok(strpos($admin_code, 'Deep video is hard-disabled in this build. The setting alone will not download or analyze video/audio without the external worker.') !== false, 'deep video hard-disabled warning exists');
ok(strpos($admin_code, 'Credit reservation is disabled. Runs use planning estimates only.') !== false, 'credit reservation disabled warning exists');
$settings_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-settings.php');
ok(strpos($settings_code, 'enable_ai_research_bridge') !== false, 'AI research bridge setting exists');
ok(FIDTF_Settings::live_dispatch_enabled() === false, 'live dispatch disabled by default after reset');
ok(FIDTF_Settings::deep_video_enabled() === false, 'deep video disabled by default');

$apify_code = file_get_contents(dirname(__DIR__) . '/includes/providers/class-fidtf-provider-apify.php');
ok(strpos($apify_code, 'source_live_bridge_disabled') !== false, 'Generic Apify provider no longer starts non-TikTok live source filters');
$ai_code = file_get_contents(dirname(__DIR__) . '/includes/providers/class-fidtf-provider-ai.php');
ok(strpos($ai_code, 'ai_research_bridge_enabled') < strpos($ai_code, "apply_filters('fidtf_ai_research_items'"), 'AI provider checks bridge setting before AI filter');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');
ok(strpos($js, 'Planning estimate only. No final credits were settled.') !== false, 'frontend includes planning-only credit message');

fwrite(STDOUT, "FIDTF v0.1.4 bridge-safety checks passed: {$checks} / {$checks}\n");
