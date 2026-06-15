<?php
require_once __DIR__ . '/test-fidtf-v020-ai-planner-bridge.php';

global $fidtf_options, $fidtf_filter_handlers, $fidtf_filter_calls, $fidtf_is_admin;
$plugin_file = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.2.1 planner contract hardening

$settings = FIDTF_Settings::get();
$settings['enable_ai_planner_bridge'] = true;
$settings['enable_live_dispatch'] = false;
$settings['enable_ai_research_bridge'] = false;
$settings['enable_credit_reservation'] = false;
$settings['enable_deep_video'] = false;
$settings['source_limits']['tiktok'] = 17;
$settings['source_limits']['instagram'] = 19;
$settings['source_limits']['reddit'] = 23;
$settings['source_limits']['google_trends'] = 29;
$settings['source_limits']['google_news'] = 31;
$settings['source_limits']['ai_research'] = 7;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Find source signals for quiet summer travel in Spain.',
    'objective' => 'Build TikTok bridge-ready source payloads',
    'market' => 'Spain',
    'language' => 'es',
    'date_range' => 'last_30_days',
    'brand' => 'Future Island',
    'company' => 'Future Island',
    'competitors' => ['Competitor One', 'Competitor Two'],
    'audience' => 'Cultural travelers',
    'exclude_terms' => ['cheap flights'],
    'keywords' => ['verano silencioso', 'turismo cultural'],
    'channels' => ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news', 'ai_research'],
]);

$source_heavy_plan = [
    'schema_version' => 'fidtf.plan.v1',
    'brief_summary' => 'Source-specific plan.',
    'model_label' => 'configured_planner_model',
    'sources' => [
        'tiktok' => [
            'source_key' => 'tiktok',
            'queries' => array_merge(['<b>verano silencioso</b>'], range(1, 25)),
            'hashtags' => ['veranosilencioso', 'turismocultural'],
            'creator_or_format_hints' => ['POV travel diary', '<script>bad</script>street interview'],
            'language_hints' => ['es'],
            'market_hints' => ['Spain'],
            'max_items' => 999,
            'sort_strategy' => 'relevance_then_engagement',
            'notes' => '<b>Plan only</b>',
        ],
        'instagram' => [
            'source_key' => 'instagram',
            'queries' => ['slow summer Spain'],
            'hashtags' => ['slowsummer'],
            'profile_or_topic_hints' => ['travel creators', 'culture magazines'],
            'language_hints' => ['es'],
            'market_hints' => ['Spain'],
            'max_items' => 200,
            'sort_strategy' => 'hashtag_relevance_then_recency',
            'notes' => '',
        ],
        'reddit' => [
            'source_key' => 'reddit',
            'queries' => ['quiet travel Spain'],
            'subreddits' => ['travel', 'solotravel'],
            'time_range' => 'last_30_days',
            'language_hints' => ['en'],
            'market_hints' => ['Spain'],
            'max_items' => 200,
            'sort_strategy' => 'relevance_week',
        ],
        'google_trends' => [
            'source_key' => 'google_trends',
            'seed_keywords' => ['verano silencioso', 'turismo cultural'],
            'related_query_hints' => ['slow travel', 'quiet tourism'],
            'geo' => 'ES',
            'time_range' => 'last_30_days',
            'language_hints' => ['es'],
            'market_hints' => ['Spain'],
            'max_items' => 200,
            'sort_strategy' => 'interest_and_related_queries',
        ],
        'google_news' => [
            'source_key' => 'google_news',
            'queries' => ['quiet summer travel Spain'],
            'publisher_or_topic_hints' => ['travel media', 'culture'],
            'geo' => 'ES',
            'language' => 'es',
            'time_range' => 'last_30_days',
            'max_items' => 200,
            'sort_strategy' => 'freshness_and_publisher_diversity',
        ],
        'ai_research' => [
            'source_key' => 'ai_research',
            'research_questions' => ['What assumptions should be tested?'],
            'assumptions_to_test' => ['Quiet travel is increasing'],
            'market_questions' => ['Which public market signals matter?'],
            'competitor_questions' => ['How do competitors frame quiet travel?'],
            'max_items' => 20,
            'sort_strategy' => 'evidence_framing',
        ],
        'unselected_source' => [
            'source_key' => 'unselected_source',
            'queries' => ['must be removed'],
            'max_items' => 10,
            'sort_strategy' => 'ignore',
        ],
    ],
    'limitations' => ['Provider execution still required.'],
    'request_context' => ['market' => 'Spain'],
];

$validated = FIDTF_AI_Planner::validate_plan($source_heavy_plan, $request);
ok(!is_wp_error($validated), 'source-specific planner output validates');
ok(isset($validated['sources']['tiktok']['creator_or_format_hints']), 'TikTok creator_or_format_hints preserved');
ok(isset($validated['sources']['instagram']['profile_or_topic_hints']), 'Instagram profile_or_topic_hints preserved');
ok(isset($validated['sources']['reddit']['subreddits']), 'Reddit subreddits preserved');
ok(isset($validated['sources']['google_trends']['seed_keywords']), 'Google Trends seed_keywords preserved');
ok(isset($validated['sources']['google_trends']['related_query_hints']), 'Google Trends related_query_hints preserved');
ok(isset($validated['sources']['google_news']['publisher_or_topic_hints']), 'Google News publisher_or_topic_hints preserved');
ok(isset($validated['sources']['ai_research']['assumptions_to_test']), 'AI research assumptions_to_test preserved');
ok(isset($validated['sources']['ai_research']['market_questions']), 'AI research market_questions preserved');
ok(isset($validated['sources']['ai_research']['competitor_questions']), 'AI research competitor_questions preserved');
ok(!isset($validated['sources']['unselected_source']), 'unselected source removed from validated plan');
ok(($validated['sources']['tiktok']['max_items'] ?? 0) === 17, 'TikTok max_items clamped to source limit');
ok(($validated['sources']['ai_research']['max_items'] ?? 0) === 7, 'AI research max_items clamped to source limit');
ok(count($validated['sources']['tiktok']['queries']) === 20, 'planner list fields capped at 20');
ok(strpos(implode(' ', $validated['sources']['tiktok']['queries']), '<') === false, 'HTML stripped from list fields');
ok(($validated['sources']['tiktok']['notes'] ?? '') === 'Plan only', 'HTML stripped from notes');

$limited_request = $request;
$limited_request['channels'] = ['tiktok'];
$limited_validated = FIDTF_AI_Planner::validate_plan($source_heavy_plan, $limited_request);
ok(!isset($limited_validated['sources']['instagram']) && isset($limited_validated['sources']['tiktok']), 'validate_plan keeps selected channels only');

$payload = FIDTF_AI_Bridge::planner_payload($request, $validated, FIDTF_Settings::planner_model_alias());
$instructions = (string) ($payload['instructions'] ?? '');
foreach (['user_brief', 'objective', 'market', 'language', 'date_range', 'keywords', 'brand', 'competitors', 'audience', 'exclude_terms'] as $needle) {
    ok(strpos($instructions, $needle) !== false, 'planner prompt contains ' . $needle);
}
ok(strpos($instructions, 'No fake browsing') !== false && strpos($instructions, 'no fake scraping') !== false, 'planner prompt forbids fake browsing/scraping');
$schema = $payload['schema'];
ok(isset($schema['properties']['sources']['properties']['tiktok']['properties']['creator_or_format_hints']), 'schema includes TikTok creator_or_format_hints');
ok(isset($schema['properties']['sources']['properties']['instagram']['properties']['profile_or_topic_hints']), 'schema includes Instagram profile_or_topic_hints');
ok(isset($schema['properties']['sources']['properties']['reddit']['properties']['subreddits']), 'schema includes Reddit subreddits');
ok(isset($schema['properties']['sources']['properties']['google_trends']['properties']['seed_keywords']), 'schema includes Google Trends seed_keywords');
ok(isset($schema['properties']['sources']['properties']['google_news']['properties']['publisher_or_topic_hints']), 'schema includes Google News publisher_or_topic_hints');
ok(isset($schema['properties']['sources']['properties']['ai_research']['properties']['assumptions_to_test']), 'schema includes AI research assumptions_to_test');

$jobs = FIDTF_Source_Job_Service::create_jobs(321, $validated, $request);
$tiktok_payload = [];
$trends_payload = [];
foreach ($jobs as $job) {
    if (($job['source_key'] ?? '') === 'tiktok') { $tiktok_payload = json_decode((string) $job['query_payload_json'], true); }
    if (($job['source_key'] ?? '') === 'google_trends') { $trends_payload = json_decode((string) $job['query_payload_json'], true); }
}
ok(($tiktok_payload['creator_or_format_hints'][0] ?? '') === 'POV travel diary', 'source job payload preserves TikTok creator_or_format_hints');
ok(($trends_payload['seed_keywords'][0] ?? '') === 'verano silencioso', 'source job payload preserves Google Trends seed_keywords');
ok(($trends_payload['geo'] ?? '') === 'ES', 'source job payload preserves Google Trends geo');

$plan_response_ref = new ReflectionMethod('FIDTF_REST_Controller', 'prepare_plan_for_response');
$plan_response_ref->setAccessible(true);
$diagnostic_plan = $validated;
$diagnostic_plan['planner_mode'] = 'external_model_bridge';
$diagnostic_plan['planner_notice'] = 'AI planner bridge returned a validated source plan.';
$diagnostic_plan['raw_text'] = 'raw text only for admin';
$diagnostic_plan['bridge_diagnostic'] = 'diagnostic only for admin';
$fidtf_is_admin = false;
$public_plan = $plan_response_ref->invoke(null, $diagnostic_plan);
ok(($public_plan['planner_mode'] ?? '') === 'external_model_bridge', 'REST plan response includes planner_mode');
ok(($public_plan['planner_notice'] ?? '') === 'AI planner bridge returned a validated source plan.', 'REST plan response includes planner_notice');
ok(!isset($public_plan['raw_text']) && !isset($public_plan['bridge_diagnostic']), 'non-admin does not receive raw AI diagnostics');
ok(isset($public_plan['sources']['tiktok']['creator_or_format_hints']), 'REST plan response includes source-specific plan fields');
$fidtf_is_admin = true;
$admin_plan = $plan_response_ref->invoke(null, $diagnostic_plan);
ok(($admin_plan['raw_text'] ?? '') === 'raw text only for admin', 'admin can receive raw AI text');
ok(($admin_plan['bridge_diagnostic'] ?? '') === 'diagnostic only for admin', 'admin can receive bridge diagnostic');
$fidtf_is_admin = false;

$job_response_ref = new ReflectionMethod('FIDTF_REST_Controller', 'prepare_jobs_for_response');
$job_response_ref->setAccessible(true);
$job_response = $job_response_ref->invoke(null, $jobs);
$found_tiktok_summary = false;
foreach ($job_response as $job) {
    if (($job['source_key'] ?? '') === 'tiktok') {
        $found_tiktok_summary = in_array('POV travel diary', (array) ($job['planned_queries'] ?? []), true);
    }
}
ok($found_tiktok_summary, 'source plan summary includes source-specific hints');

$settings['enable_ai_planner_bridge'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$estimate_disabled = FIDTF_Credit_Service::estimate($request, ['tiktok']);
ok(($estimate_disabled['breakdown']['ai_planner'] ?? -1) == 0.0, 'AI planner disabled => no AI planner cost');
$settings['enable_ai_planner_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$estimate_enabled = FIDTF_Credit_Service::estimate($request, ['tiktok']);
ok(($estimate_enabled['breakdown']['ai_planner'] ?? 0) > 0, 'AI planner enabled => planner cost included');
ok(FIDTF_Credit_Service::credit_mode(['credits_reserved' => 0, 'final_credits_settled' => 0]) === 'planning_only', 'credit_mode remains planning_only when reservation disabled');
$report = FIDTF_Report_Service::build_report(999, [
    'status' => 'planned_waiting_for_sources',
    'channels_json' => wp_json_encode(['tiktok']),
    'user_brief' => $request['user_brief'],
    'market' => $request['market'],
    'language' => $request['language'],
    'date_range' => $request['date_range'],
    'credits_reserved' => 0,
    'final_credits_settled' => 0,
]);
ok(isset($report['credit_breakdown']['ai_planner']), 'report includes planner credit estimate breakdown');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');
ok(strpos($js, 'data-fidtf-planner-diagnostics') !== false, 'frontend renders planner diagnostics block');
ok(strpos($js, 'Planner mode:') !== false, 'frontend renders planner mode');
ok(strpos($js, 'Planned-only sources') !== false || strpos($js, 'sourceCardState') !== false, 'frontend renders source readiness/state');

$settings = FIDTF_Settings::get();
ok(FIDTF_Settings::live_dispatch_enabled() === false, 'live dispatch remains disabled by default/setting');
ok(FIDTF_Settings::ai_research_bridge_enabled() === false, 'AI research bridge remains disabled by default/setting');
ok(FIDTF_Settings::deep_video_enabled() === false, 'deep video remains disabled by hard gate');

$planner_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-ai-planner.php');
ok(strpos($planner_code, 'ai_planner_bridge_enabled') < strpos($planner_code, "apply_filters('fidtf_ai_planner_result'"), 'no unguarded fidtf_ai_planner_result call');

fwrite(STDOUT, "FIDTF v0.2.1 planner-contract-hardening checks passed: {$checks} / {$checks}\n");
