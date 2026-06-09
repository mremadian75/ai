<?php
require_once __DIR__ . '/test-fidtf-v014-bridge-safety.php';

$plugin_file = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version includes v0.2.0 AI planner bridge or newer planner contract build

// AI planner bridge setting and hard gate.
global $fidtf_options, $fidtf_filter_handlers, $fidtf_filter_calls, $fidtf_is_admin;
$settings = FIDTF_Settings::get();
$settings['enable_ai_planner_bridge'] = false;
$settings['enable_live_dispatch'] = false;
$settings['planner_model_alias'] = 'primary_reasoning_model';
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_handlers = [];
$fidtf_filter_calls = [];
$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Find TikTok and search trend angles for quiet summer travel in Spain.',
    'objective' => 'Build source plan only',
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => ['verano silencioso', 'turismo cultural'],
    'channels' => ['tiktok', 'google_news', 'ai_research'],
]);
$fidtf_filter_handlers['fidtf_ai_planner_result'] = function($tag, $value, $request_arg, $fallback_arg) {
    return ['sources' => ['tiktok' => ['queries' => ['should not fire'], 'max_items' => 1]]];
};
$disabled_plan = FIDTF_AI_Planner::build_plan($request);
ok(($disabled_plan['planner_mode'] ?? '') === 'local_fallback', 'disabled AI planner bridge returns local_fallback');
ok(($disabled_plan['planner_notice'] ?? '') === 'AI planner bridge is disabled. Local fallback planner was used.', 'disabled planner notice is explicit');
ok(empty($fidtf_filter_calls['fidtf_ai_planner_result']), 'AI planner filter is not called when planner bridge disabled');
ok(FIDTF_Settings::ai_planner_bridge_enabled() === false, 'AI planner bridge disabled by default/setting');

$settings['enable_ai_planner_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_calls = [];
$fidtf_filter_handlers['fidtf_ai_planner_result'] = function($tag, $value, $request_arg, $fallback_arg) {
    return [
        'schema_version' => 'fidtf.plan.v1',
        'model_label' => 'configured_planner_model',
        'brief_summary' => 'Structured source plan from configured bridge.',
        'sources' => [
            'tiktok' => [
                'source_key' => 'tiktok',
                'queries' => ['verano silencioso turismo cultural'],
                'hashtags' => ['veranosilencioso'],
                'language_hints' => ['es'],
                'market_hints' => ['Spain'],
                'max_items' => 25,
                'sort_strategy' => 'relevance_then_engagement',
                'notes' => 'Plan only; do not claim scraping.',
            ],
        ],
        'limitations' => ['Provider data still needs to be ingested.'],
        'validation_notes' => ['Valid test bridge result.'],
        'request_context' => ['market' => 'Spain'],
    ];
};
$external_plan = FIDTF_AI_Planner::build_plan($request);
ok(($fidtf_filter_calls['fidtf_ai_planner_result'] ?? 0) === 1, 'AI planner filter is called when planner bridge enabled');
ok(($external_plan['planner_mode'] ?? '') === 'external_model_bridge', 'valid AI planner result returns external_model_bridge');
ok(($external_plan['model_label'] ?? '') === 'configured_planner_model', 'model_label is preserved only when provided by bridge');
ok(isset($external_plan['sources']['tiktok']), 'external planner source plan is preserved after validation');
ok(($external_plan['request_context']['market'] ?? '') === 'Spain', 'external planner request context remains merged/sanitized');

$fidtf_filter_calls = [];
$fidtf_filter_handlers['fidtf_ai_planner_result'] = function($tag, $value, $request_arg, $fallback_arg) {
    return ['raw_text' => 'not valid plan', 'sources' => []];
};
$invalid_plan = FIDTF_AI_Planner::build_plan($request);
ok(($invalid_plan['planner_mode'] ?? '') === 'local_fallback_after_invalid_ai', 'invalid AI planner output falls back safely');
ok(($invalid_plan['validation_error'] ?? '') === 'invalid_planner_json', 'invalid planner validation error is stored');
ok(!empty($invalid_plan['sources']['tiktok']), 'invalid AI planner fallback still contains local source plan');

// AI bridge adapter behavior.
ok(class_exists('FIDTF_AI_Bridge'), 'AI bridge class exists');
$payload = FIDTF_AI_Bridge::planner_payload($request, $disabled_plan, FIDTF_Settings::planner_model_alias());
ok(($payload['task'] ?? '') === 'future_island_deep_trend_finder_source_plan', 'AI bridge payload declares planner task');
ok(strpos((string) ($payload['instructions'] ?? ''), 'Do not claim that scraping') !== false, 'planner prompt forbids fake scraping claims');
ok(isset($payload['schema']['properties']['sources']), 'planner payload includes JSON schema for sources');
$no_client = FIDTF_AI_Bridge::plan($request, $disabled_plan);
ok(is_wp_error($no_client) && $no_client->get_error_code() === 'ai_planner_unavailable', 'no compatible mother AI client returns ai_planner_unavailable');

if (!class_exists('VES_AI_Planner_Bridge')) {
    class VES_AI_Planner_Bridge {
        public static $result = null;
        public static function plan_deep_trend_finder($payload, $request, $fallback) { return self::$result; }
    }
}
VES_AI_Planner_Bridge::$result = 'plain text, not json';
$invalid_text = FIDTF_AI_Bridge::plan($request, $disabled_plan);
ok(is_wp_error($invalid_text) && $invalid_text->get_error_code() === 'ai_planner_invalid_json', 'invalid AI text falls back via WP_Error');

VES_AI_Planner_Bridge::$result = [
    'ok' => true,
    'model_label' => 'actual_configured_model',
    'plan' => [
        'schema_version' => 'fidtf.plan.v1',
        'brief_summary' => 'Adapter wrapped plan.',
        'sources' => [
            'google_news' => [
                'source_key' => 'google_news',
                'queries' => ['quiet summer travel Spain'],
                'max_items' => 20,
                'sort_strategy' => 'recency_then_relevance',
                'notes' => 'News query plan only.',
            ],
        ],
        'limitations' => ['Needs provider validation.'],
        'request_context' => [],
    ],
];
$bridge_array = FIDTF_AI_Bridge::plan($request, $disabled_plan);
ok(is_array($bridge_array), 'valid compatible mother bridge returns an array');
ok(($bridge_array['model_label'] ?? '') === 'actual_configured_model', 'adapter preserves actual/configured model label');
$validated_bridge = FIDTF_AI_Planner::validate_plan($bridge_array, $request);
ok(!is_wp_error($validated_bridge), 'adapter output validates as planner result');
ok(isset($validated_bridge['sources']['google_news']), 'validated bridge output contains google_news plan');

// REST plan response exposes safe planner diagnostics and hides raw_text from non-admin.
$plan_response_ref = new ReflectionMethod('FIDTF_REST_Controller', 'prepare_plan_for_response');
$plan_response_ref->setAccessible(true);
$sample_plan = $invalid_plan;
$sample_plan['raw_text'] = 'raw model text for admin only';
$sample_plan['bridge_diagnostic'] = 'No configured AI planner client was available.';
$fidtf_is_admin = false;
$public_plan = $plan_response_ref->invoke(null, $sample_plan);
ok(!array_key_exists('raw_text', $public_plan), 'public plan response hides raw_text');
$fidtf_is_admin = true;
$admin_plan = $plan_response_ref->invoke(null, $sample_plan);
ok(($admin_plan['raw_text'] ?? '') === 'raw model text for admin only', 'admin plan response can expose sanitized raw_text');
ok(($admin_plan['bridge_diagnostic'] ?? '') === 'No configured AI planner client was available.', 'admin plan response exposes bridge diagnostic');
$fidtf_is_admin = false;

// Admin/settings/source checks.
$settings_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-settings.php');
ok(strpos($settings_code, 'enable_ai_planner_bridge') !== false, 'AI planner bridge setting exists');
ok(strpos($settings_code, 'ai_planner_bridge_enabled') !== false, 'AI planner bridge helper exists');
$admin_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-admin.php');
ok(strpos($admin_code, 'AI planner bridge may call configured model APIs and consume AI/API costs.') !== false, 'admin warning contains AI planner bridge cost warning');
$planner_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-ai-planner.php');
ok(strpos($planner_code, 'ai_planner_bridge_enabled') < strpos($planner_code, "apply_filters('fidtf_ai_planner_result'"), 'AI planner checks bridge setting before planner filter');
$bridge_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-ai-bridge.php');
ok(strpos($bridge_code, 'plan_deep_trend_finder') !== false, 'AI bridge detects compatible mother planner method');
ok(strpos($bridge_code, 'GPT-5') === false, 'AI bridge has no hardcoded GPT-5 label');
ok(strpos($plugin_file, '0.3.14-tiktok-retryable-dispatch-recovery') !== false || strpos($plugin_file, '0.3.13-apidojo-tiktok-input-schema-hotfix') !== false || strpos($plugin_file, '0.3.12-tiktok-discovery-enrichment-split') !== false || strpos($plugin_file, '0.3.11-admin-dispatch-diagnostics-and-view-gate') !== false || strpos($plugin_file, '0.3.10-core-social-apify-execution-parity') !== false || strpos($plugin_file, '0.3.9-scraptik-social-request-contract') !== false || strpos($plugin_file, '0.3.7-scraptik-input-region-hotfix') !== false || strpos($plugin_file, '0.3.6-scraptik-output-flattening') !== false || strpos($plugin_file, '0.3.4-live-activation-ui-polish') !== false || strpos($plugin_file, '0.3.3-core-addon-live-qa-hotfix') !== false || strpos($plugin_file, '0.3.2-core-shell-integration-ui') !== false || strpos($plugin_file, 'class-fidtf-ai-bridge.php') !== false, 'AI bridge class is loaded by plugin bootstrap');

fwrite(STDOUT, "FIDTF v0.2.0 ai-planner-bridge checks passed: {$checks} / {$checks}\n");
