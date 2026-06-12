<?php
require_once __DIR__ . '/test-fidtf-v0333-live-qa-diagnostics-and-zero-row-reasoning.php';

$root = dirname(__DIR__);
require_once $root . '/includes/class-fidtf-ai-bridge.php';
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.34
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.34
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.34

$live_trends_item = [
    'keyword' => 'marketing',
    'timeframe' => 'today 1-m',
    'geo' => 'ES',
    'language' => 'es-ES',
    'trends_url' => 'https://trends.google.com/trends/explore?date=today+1-m&geo=ES&q=marketing&hl=es-ES',
    'timeline_data' => [
        'marketing' => [
            '2026-04-26' => 74,
            '2026-04-27' => 92,
            '2026-04-28' => 95,
            '2026-05-05' => 100,
            '2026-05-26' => 64,
        ],
        'isPartial' => [
            '2026-05-26' => true,
        ],
    ],
    'region_data' => [],
    'data_granularity' => 'day',
];
$norm = FIDTF_Normalizer::normalize('google_trends', $live_trends_item);
ok(($norm['metrics']['trend_interest'] ?? null) == 100, 'Google Trends live nested timeline_data derives max trend_interest');
ok(!empty($norm['trend_timeseries']) && is_array($norm['trend_timeseries'][0] ?? null), 'Google Trends live nested timeline_data is flattened to rows');
ok(($norm['trend_timeseries'][0]['series'] ?? '') === 'marketing', 'Google Trends flattened timeline keeps series label');
ok(in_array('Live Google Trends keyword-mode timeline_data shape', $norm['evidence_quality']['limitations'] ?? [], true) === false, 'normalizer does not add fake limitation for valid timeline');
ok(strpos($normalizer_src, 'flatten_google_trends_timeline_data') !== false, 'normalizer contains live Google Trends timeline flattening helper');

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Find Instagram, Google News, and Google Trends signals for premium coffee in Spain.',
    'objective' => 'Build source plan only',
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => ['coffee', 'specialty coffee'],
    'channels' => ['instagram', 'google_trends', 'google_news'],
]);
$fallback = [];
if (!class_exists('VES_AI_Planner_Bridge')) {
    class VES_AI_Planner_Bridge { public static $result = null; public static function plan_deep_trend_finder($payload, $request, $fallback) { return self::$result; } }
}

$openai_plan = [
    'schema_version' => 'fidtf.plan.v1',
    'brief_summary' => 'ChatGPT planned source queries only.',
    'sources' => [
        'instagram' => [
            'source_key' => 'instagram',
            'hashtags' => ['specialtycoffee'],
            'queries' => ['specialty coffee Spain'],
            'max_items' => 3,
            'sort_strategy' => 'hashtag_relevance_then_recency',
        ],
        'google_trends' => [
            'source_key' => 'google_trends',
            'seed_keywords' => ['specialty coffee'],
            'geo' => 'ES',
            'time_range' => 'last_30_days',
            'max_items' => 3,
            'sort_strategy' => 'interest_and_related_queries',
        ],
        'not_selected_source' => [
            'source_key' => 'not_selected_source',
            'queries' => ['must be dropped'],
            'max_items' => 99,
            'sort_strategy' => 'invalid',
        ],
    ],
    'limitations' => ['Provider validation still required.'],
    'request_context' => ['market' => 'Spain'],
];
VES_AI_Planner_Bridge::$result = [
    'model' => 'gpt-test-live-shape',
    'output' => [[
        'type' => 'message',
        'content' => [[
            'type' => 'output_text',
            'text' => "```json\n" . json_encode($openai_plan) . "\n```",
        ]],
    ]],
];
$bridge_response = FIDTF_AI_Bridge::plan($request, $fallback);
ok(is_array($bridge_response), 'AI bridge parses OpenAI Responses API output text');
ok(($bridge_response['model_label'] ?? '') === 'gpt-test-live-shape', 'AI bridge preserves OpenAI model label');
ok(!empty($bridge_response['raw_text']), 'AI bridge stores sanitized raw model text for admin diagnostics');
$validated = FIDTF_AI_Planner::validate_plan($bridge_response, $request);
ok(!is_wp_error($validated), 'OpenAI-style bridge response validates as source plan');
ok(isset($validated['sources']['instagram']) && isset($validated['sources']['google_trends']), 'validated OpenAI-style plan keeps selected sources');
ok(!isset($validated['sources']['not_selected_source']), 'validated OpenAI-style plan drops unselected/unknown sources');

VES_AI_Planner_Bridge::$result = [
    'choices' => [[
        'message' => [
            'content' => json_encode($openai_plan),
        ],
    ]],
    'model' => 'gpt-chat-completions-shape',
];
$chat_response = FIDTF_AI_Bridge::plan($request, $fallback);
ok(is_array($chat_response) && ($chat_response['model_label'] ?? '') === 'gpt-chat-completions-shape', 'AI bridge parses Chat Completions message content');
ok(strpos($bridge_src, 'model_text_from_response') !== false, 'AI bridge contains model text extraction helper');
ok(strpos($bridge_src, 'decode_model_json_text') !== false, 'AI bridge contains model JSON decoding helper');

VES_AI_Planner_Bridge::$result = ['output_text' => 'not-json answer'];
$bad_ai = FIDTF_AI_Bridge::plan($request, $fallback);
$bad_validated = FIDTF_AI_Planner::validate_plan($bad_ai, $request);
ok(is_wp_error($bad_validated), 'invalid ChatGPT text does not pass planner validation');
ok(!empty($bad_ai['raw_text']), 'invalid ChatGPT text is preserved only as sanitized raw diagnostic');

$sanitize = new ReflectionMethod('FIDTF_Report_Service', 'sanitize_external_report');
$sanitize->setAccessible(true);
$external = $sanitize->invoke(null, [
    'output_text' => json_encode([
        'executive_summary' => 'External model synthesis based only on provided evidence.',
        'insights' => [['title' => 'Evidence-backed pattern', 'evidence' => 'Three rows', 'implication' => 'Run validation', 'confidence' => 'directional']],
        'unknown_unsafe_field' => 'drop me',
    ]),
    'model' => 'gpt-report-shape',
]);
ok(($external['executive_summary'] ?? '') === 'External model synthesis based only on provided evidence.', 'report service parses OpenAI-style external synthesis JSON');
ok(isset($external['insights']) && !isset($external['unknown_unsafe_field']), 'report service allowlists external synthesis fields');
ok(strpos($report_src, 'external_model_text') !== false, 'report service contains external model text extraction helper');

fwrite(STDOUT, "FIDTF v0.3.34 live GPT response and trends timeseries hardening checks passed: {$checks} / {$checks}\n");
