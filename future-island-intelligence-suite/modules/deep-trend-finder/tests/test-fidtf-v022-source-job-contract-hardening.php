<?php
require_once __DIR__ . '/test-fidtf-v021-planner-contract-hardening.php';

global $fidtf_options, $fidtf_filter_handlers, $fidtf_filter_calls, $fidtf_is_admin;
$plugin_file = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.2.2 source job contract hardening

$settings = FIDTF_Settings::get();
$settings['enable_live_dispatch'] = false;
$settings['enable_ai_planner_bridge'] = false;
$settings['enable_ai_research_bridge'] = false;
$settings['enable_deep_video'] = false;
$settings['enable_credit_reservation'] = false;
$settings['source_limits']['tiktok'] = 17;
$settings['source_limits']['reddit'] = 23;
$settings['source_limits']['google_trends'] = 29;
$settings['source_limits']['ai_research'] = 7;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Validate source job payload contract for TikTok bridge readiness.',
    'objective' => 'Prepare canonical source jobs without running providers',
    'market' => 'Spain',
    'language' => 'es',
    'date_range' => 'last_30_days',
    'brand' => 'Future Island',
    'company' => 'Future Island',
    'competitors' => ['Competitor One'],
    'audience' => 'Cultural travelers',
    'exclude_terms' => ['cheap flights'],
    'keywords' => ['verano silencioso', 'turismo cultural'],
    'channels' => ['tiktok', 'reddit', 'google_trends', 'ai_research'],
]);

$plan = [
    'schema_version' => 'fidtf.plan.v1',
    'planner_mode' => 'external_model_bridge',
    'brief_summary' => 'Contract hardening plan.',
    'request_context' => FIDTF_AI_Planner::request_context($request),
    'sources' => [
        'tiktok' => [
            'source_key' => 'tiktok',
            'queries' => ['verano silencioso'],
            'hashtags' => ['veranosilencioso'],
            'creator_or_format_hints' => ['POV travel diary'],
            'language_hints' => ['es'],
            'market_hints' => ['Spain'],
            'max_items' => 99,
            'sort_strategy' => 'relevance_then_engagement',
            'notes' => 'Plan only.',
        ],
        'reddit' => [
            'source_key' => 'reddit',
            'queries' => ['quiet travel Spain'],
            'subreddits' => ['travel', 'solotravel'],
            'time_range' => 'last_30_days',
            'max_items' => 99,
            'sort_strategy' => 'relevance_week',
        ],
        'google_trends' => [
            'source_key' => 'google_trends',
            'seed_keywords' => ['verano silencioso'],
            'related_query_hints' => ['slow travel'],
            'geo' => 'ES',
            'time_range' => 'last_30_days',
            'max_items' => 99,
            'sort_strategy' => 'interest_and_related_queries',
        ],
        'ai_research' => [
            'source_key' => 'ai_research',
            'research_questions' => ['What public signals should be checked?'],
            'assumptions_to_test' => ['Quiet travel is increasing'],
            'market_questions' => ['Which market signals matter?'],
            'competitor_questions' => ['How do competitors frame it?'],
            'max_items' => 99,
            'sort_strategy' => 'evidence_framing',
        ],
    ],
];

$jobs = FIDTF_Source_Job_Service::create_jobs(422, $plan, $request);
$payloads = [];
foreach ($jobs as $job) {
    $payloads[$job['source_key']] = json_decode((string) $job['query_payload_json'], true);
}
$tiktok = $payloads['tiktok'] ?? [];
$trends = $payloads['google_trends'] ?? [];
$reddit = $payloads['reddit'] ?? [];

ok(($tiktok['schema_version'] ?? '') === 'fidtf.source_payload.v1', 'create_jobs stores canonical query_payload_json schema version');
ok(($tiktok['source_key'] ?? '') === 'tiktok', 'canonical payload includes source_key');
ok(($tiktok['planner_mode'] ?? '') === 'external_model_bridge', 'canonical payload includes planner_mode');
ok(isset($tiktok['request_context']) && is_array($tiktok['request_context']), 'canonical payload includes request_context');
ok(isset($tiktok['source_plan']) && is_array($tiktok['source_plan']), 'canonical payload includes source_plan');
ok(isset($tiktok['limits']['max_items']) && isset($tiktok['limits']['admin_limit']), 'canonical payload includes limits');
ok(($tiktok['provider_intent'] ?? '') === 'planned_only', 'canonical payload provider_intent is planned_only');
ok(($tiktok['live_dispatch_enabled'] ?? true) === false, 'canonical payload live_dispatch_enabled is false by default');
ok(($tiktok['source_plan']['creator_or_format_hints'][0] ?? '') === 'POV travel diary', 'TikTok canonical source_plan preserves creator_or_format_hints');
ok(($tiktok['request_context']['exclude_terms'][0] ?? '') === 'cheap flights', 'TikTok payload request_context includes exclude_terms');
ok(($tiktok['request_context']['date_range'] ?? '') === 'last_30_days', 'TikTok payload request_context includes date_range');
ok(($tiktok['request_context']['market'] ?? '') === 'Spain', 'TikTok payload request_context includes market');
ok(($tiktok['request_context']['language'] ?? '') === 'es', 'TikTok payload request_context includes language');
ok(($trends['source_plan']['seed_keywords'][0] ?? '') === 'verano silencioso', 'Google Trends payload source_plan includes seed_keywords');
ok(($trends['source_plan']['time_range'] ?? '') === 'last_30_days', 'Google Trends payload source_plan includes time_range');
ok(($reddit['source_plan']['subreddits'][0] ?? '') === 'travel', 'Reddit payload source_plan includes subreddits');
ok(($reddit['source_plan']['time_range'] ?? '') === 'last_30_days', 'Reddit payload source_plan includes time_range');

$apify_provider = new FIDTF_Provider_TikTok();
$apify_payload = $apify_provider->build_payload($plan['sources']['tiktok'], array_merge($request, ['planner_mode' => 'external_model_bridge']));
ok(($apify_payload['schema_version'] ?? '') === 'fidtf.source_payload.v1', 'Apify provider build_payload returns canonical wrapper');
ok(isset($apify_payload['source_plan']) && isset($apify_payload['request_context']) && isset($apify_payload['limits']), 'Apify canonical payload has source_plan/request_context/limits');
$ai_provider = new FIDTF_Provider_AI();
$ai_payload = $ai_provider->build_payload($plan['sources']['ai_research'], array_merge($request, ['planner_mode' => 'external_model_bridge']));
ok(($ai_payload['schema_version'] ?? '') === 'fidtf.source_payload.v1', 'AI provider build_payload returns canonical wrapper');
ok(isset($ai_payload['source_plan']['assumptions_to_test']), 'AI canonical payload preserves assumptions_to_test');

$fidtf_filter_calls = [];
$fidtf_filter_handlers['fidtf_dispatch_source_job'] = function($tag, $value, $context, $source_plan_arg, $request_arg) {
    return ['ok' => true, 'status' => 'queued', 'provider_run_id' => 'provider-test', 'items' => []];
};
$disabled = $apify_provider->dispatch($plan['sources']['tiktok'], $request);
ok(is_wp_error($disabled) && $disabled->get_error_code() === 'live_dispatch_disabled', 'disabled live dispatch prevents provider filter');
ok(empty($fidtf_filter_calls['fidtf_dispatch_source_job']), 'provider filter not called when live dispatch disabled');
$settings['enable_live_dispatch'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$captured_context = [];
$fidtf_filter_calls = [];
$fidtf_filter_handlers['fidtf_dispatch_source_job'] = function($tag, $value, $context, $source_plan_arg, $request_arg) use (&$captured_context) {
    $captured_context = $context;
    return ['ok' => true, 'status' => 'queued', 'provider_run_id' => 'provider-test', 'items' => []];
};
$queued = $apify_provider->dispatch($plan['sources']['tiktok'], array_merge($request, ['planner_mode' => 'external_model_bridge']));
ok(!is_wp_error($queued) && ($fidtf_filter_calls['fidtf_dispatch_source_job'] ?? 0) === 1, 'dispatch context filter can run only when live dispatch enabled');
ok(($captured_context['payload']['schema_version'] ?? '') === 'fidtf.source_payload.v1', 'dispatch context contains canonical payload when enabled');
$settings['enable_live_dispatch'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;

$job_response_ref = new ReflectionMethod('FIDTF_REST_Controller', 'prepare_jobs_for_response');
$job_response_ref->setAccessible(true);
$fidtf_is_admin = false;
$public_jobs = $job_response_ref->invoke(null, $jobs);
$tiktok_job = [];
$trends_job = [];
$reddit_job = [];
foreach ($public_jobs as $job) {
    if (($job['source_key'] ?? '') === 'tiktok') { $tiktok_job = $job; }
    if (($job['source_key'] ?? '') === 'google_trends') { $trends_job = $job; }
    if (($job['source_key'] ?? '') === 'reddit') { $reddit_job = $job; }
}
ok(isset($tiktok_job['plan_summary']) && is_array($tiktok_job['plan_summary']), 'REST job response includes plan_summary');
ok(($tiktok_job['plan_summary']['creator_or_format_hints'][0] ?? '') === 'POV travel diary', 'TikTok plan_summary includes creator_or_format_hints');
ok(($trends_job['plan_summary']['seed_keywords'][0] ?? '') === 'verano silencioso', 'Google Trends plan_summary includes seed_keywords');
ok(($reddit_job['plan_summary']['subreddits'][0] ?? '') === 'travel', 'Reddit plan_summary includes subreddits');
ok(!array_key_exists('actor_id', $tiktok_job) && !array_key_exists('provider', $tiktok_job), 'non-admin response hides actor_id/provider');
$fidtf_is_admin = true;
$admin_jobs = $job_response_ref->invoke(null, $jobs);
$admin_tiktok = [];
foreach ($admin_jobs as $job) { if (($job['source_key'] ?? '') === 'tiktok') { $admin_tiktok = $job; } }
ok(!empty($admin_tiktok['actor_id']) && !empty($admin_tiktok['provider']), 'admin response can include actor_id/provider');
$fidtf_is_admin = false;

$js = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');
ok(strpos($js, 'function sourcePlanSummaryHtml') !== false, 'frontend sourcePlanSummaryHtml exists');
ok(strpos($js, 'sourceRowsHtml') !== false && strpos($js, 'sourcePlanSummaryHtml(source, planRow, job)') !== false, 'sourceRowsHtml uses sourcePlanSummaryHtml');
ok(strpos($js, 'creator_or_format_hints') !== false, 'TikTok summary supports creator_or_format_hints');
ok(strpos($js, 'seed_keywords') !== false, 'Google Trends summary supports seed_keywords');
ok(strpos($js, 'assumptions_to_test') !== false, 'AI Research summary supports assumptions_to_test');

$settings['enable_ai_planner_bridge'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$estimate_disabled = FIDTF_Credit_Service::estimate($request, ['tiktok']);
ok(array_key_exists('local_planning', $estimate_disabled['breakdown']), 'credit breakdown includes local_planning');
ok(array_key_exists('planning', $estimate_disabled['breakdown']), 'credit breakdown keeps planning backward compatibility');
ok(array_key_exists('ai_planner', $estimate_disabled['breakdown']), 'credit breakdown includes ai_planner');
ok(($estimate_disabled['breakdown']['ai_planner'] ?? -1) == 0.0, 'ai_planner disabled => 0');
$settings['enable_ai_planner_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$estimate_enabled = FIDTF_Credit_Service::estimate($request, ['tiktok']);
ok(($estimate_enabled['breakdown']['ai_planner'] ?? 0) > 0, 'ai_planner enabled => >0');
$sum = ($estimate_enabled['breakdown']['local_planning'] ?? 0) + ($estimate_enabled['breakdown']['ai_planner'] ?? 0) + ($estimate_enabled['breakdown']['source_jobs'] ?? 0) + ($estimate_enabled['breakdown']['relevance_batches'] ?? 0) + ($estimate_enabled['breakdown']['final_synthesis'] ?? 0) + ($estimate_enabled['breakdown']['deep_video'] ?? 0);
ok(abs($sum - $estimate_enabled['estimated_credits']) < 0.01, 'total includes local_planning and ai_planner');
$report = FIDTF_Report_Service::build_report(422, [
    'status' => 'planned_waiting_for_sources',
    'channels_json' => wp_json_encode(['tiktok']),
    'user_brief' => $request['user_brief'],
    'market' => $request['market'],
    'language' => $request['language'],
    'date_range' => $request['date_range'],
    'credits_reserved' => 0,
    'final_credits_settled' => 0,
]);
$html = FIDTF_Report_Service::render_html($report);
ok(is_string($html) && $html !== '', 'planned report renders to HTML without error');
// [v1.1.0] The unified report no longer renders itemized "Local planning credit estimate" /
// "AI planner credit estimate" display labels in the report body. The credit model is surfaced
// via the report array (credit_breakdown) instead, so assert the model contract — not display copy.
ok(array_key_exists('credit_breakdown', $report), 'report exposes credit_breakdown model');
ok(array_key_exists('local_planning', (array) $report['credit_breakdown']), 'report credit_breakdown includes local_planning');
ok(array_key_exists('ai_planner', (array) $report['credit_breakdown']), 'report credit_breakdown includes ai_planner');

ok(FIDTF_Settings::live_dispatch_enabled() === false, 'live dispatch disabled by default/setting after test reset');
ok(FIDTF_Settings::ai_planner_bridge_enabled() === true, 'AI planner bridge can be enabled for estimate test');
$settings['enable_ai_planner_bridge'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
ok(FIDTF_Settings::ai_planner_bridge_enabled() === false, 'AI planner bridge disabled by default/reset');
ok(FIDTF_Settings::ai_research_bridge_enabled() === false, 'AI research bridge disabled by default/reset');
ok(FIDTF_Settings::deep_video_enabled() === false, 'deep video disabled by hard gate');

fwrite(STDOUT, "FIDTF v0.2.2 source-job-contract-hardening checks passed: {$checks} / {$checks}\n");
