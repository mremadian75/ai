<?php
require_once __DIR__ . '/test-fidtf-v0334-live-gpt-response-and-trends-timeseries-hardening.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.35
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.35
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.35

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Plan sources for marketing trends in Spain.',
    'objective' => 'source plan only',
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => ['marketing'],
    'channels' => ['google_trends', 'google_news'],
]);
$fallback = [];

$valid_plan = [
    'schema_version' => 'fidtf.plan.v1',
    'brief_summary' => 'Array content block plan.',
    'sources' => [
        'google_trends' => [
            'source_key' => 'google_trends',
            'seed_keywords' => ['marketing'],
            'geo' => 'ES',
            'time_range' => 'last_30_days',
            'max_items' => 3,
            'sort_strategy' => 'keyword_interest',
        ],
        'google_news' => [
            'source_key' => 'google_news',
            'queries' => ['marketing Spain'],
            'geo' => 'ES',
            'language' => 'es',
            'time_range' => 'last_7_days',
            'max_items' => 3,
            'sort_strategy' => 'freshness',
        ],
    ],
    'limitations' => ['Provider validation required.'],
    'request_context' => ['market' => 'Spain'],
];

VES_AI_Planner_Bridge::$result = [
    'model' => 'gpt-array-content-shape',
    'choices' => [[
        'message' => [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($valid_plan),
            ]],
        ],
    ]],
];
$array_content = FIDTF_AI_Bridge::plan($request, $fallback);
ok(is_array($array_content) && ($array_content['model_label'] ?? '') === 'gpt-array-content-shape', 'AI bridge parses ChatGPT array content blocks');
ok(!is_wp_error(FIDTF_AI_Planner::validate_plan($array_content, $request)), 'array content block plan validates');

VES_AI_Planner_Bridge::$result = [
    'status' => 'incomplete',
    'incomplete_details' => ['reason' => 'max_output_tokens'],
    'output' => [],
    'model' => 'gpt-incomplete-shape',
];
$incomplete = FIDTF_AI_Bridge::plan($request, $fallback);
$incomplete_validated = FIDTF_AI_Planner::validate_plan($incomplete, $request);
ok(is_wp_error($incomplete_validated), 'incomplete OpenAI response cannot pass planner validation');
ok(strpos(implode(' ', (array) ($incomplete['validation_notes'] ?? [])), 'non-complete response status') !== false, 'incomplete OpenAI response produces explicit diagnostic');

VES_AI_Planner_Bridge::$result = [
    'error' => [
        'type' => 'invalid_request_error',
        'code' => 'bad_request',
        'message' => 'Authorization failed for Bearer sk-proj-EXAMPLESECRET1234567890',
    ],
];
$error_shape = FIDTF_AI_Bridge::plan($request, $fallback);
ok(is_array($error_shape) && ($error_shape['provider_error_code'] ?? '') === 'bad_request', 'OpenAI error object is converted to safe provider diagnostic');
ok(strpos((string) ($error_shape['raw_text'] ?? ''), 'sk-proj-EXAMPLESECRET') === false, 'OpenAI provider error redacts API keys from diagnostics');

VES_AI_Planner_Bridge::$result = [
    'output' => [[
        'type' => 'message',
        'content' => [[
            'type' => 'refusal',
            'refusal' => 'I cannot comply with that request.',
        ]],
    ]],
];
$refusal = FIDTF_AI_Bridge::plan($request, $fallback);
ok(strpos(implode(' ', (array) ($refusal['validation_notes'] ?? [])), 'model refusal') !== false, 'OpenAI refusal content is diagnosed instead of treated as a plan');

$sanitize = new ReflectionMethod('FIDTF_Report_Service', 'sanitize_external_report');
$sanitize->setAccessible(true);
$external_array_content = $sanitize->invoke(null, [
    'model' => 'gpt-report-array-content',
    'choices' => [[
        'message' => [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'executive_summary' => 'External evidence synthesis parsed from array content blocks.',
                    'recommended_validation_plan' => [['step' => 'Run a second source', 'reason' => 'Avoid single-source certainty']],
                    'unsafe_field' => 'must drop',
                ]),
            ]],
        ],
    ]],
]);
ok(($external_array_content['executive_summary'] ?? '') === 'External evidence synthesis parsed from array content blocks.', 'final synthesis bridge parses array content blocks');
ok(!isset($external_array_content['unsafe_field']), 'final synthesis bridge still allowlists external report fields');

$external_error = $sanitize->invoke(null, [
    'status' => 'incomplete',
    'incomplete_details' => ['reason' => 'max_output_tokens'],
    'output' => [],
]);
ok(empty($external_error), 'incomplete external synthesis is rejected and local synthesis remains source of truth');

ok(strpos($bridge_src, 'provider_issue_from_response') !== false, 'AI bridge contains provider issue diagnostics helper');
ok(strpos($bridge_src, 'model_refusal_from_response') !== false, 'AI bridge contains refusal diagnostics helper');
ok(strpos($report_src, 'external_text_from_blocks') !== false, 'report service parses external content blocks safely');

fwrite(STDOUT, "FIDTF v0.3.35 live GPT error and analysis hardening checks passed: {$checks} / {$checks}\n");
