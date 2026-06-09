<?php
require_once __DIR__ . '/test-fidtf-v0344-full-live-cross-platform-gpt-contract-hardening.php';
$plugin = file_get_contents(__DIR__ . '/../../../future-island-intelligence-suite.php');
$report_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-report-service.php');
$bridge_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-ai-bridge.php');
$template_src = file_get_contents(__DIR__ . '/../templates/report-deep-trend-finder.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.45
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.45
ok(strpos($report_src, 'cross_platform_claim_readiness') !== false, 'deterministic claim-readiness gate exists');
ok(strpos($report_src, 'claims_blocked') !== false, 'claim-readiness blocks unsafe claims');
ok(strpos($report_src, 'minimum_next_tests') !== false, 'claim-readiness defines minimum next tests');
ok(strpos($template_src, 'Claim-readiness gate') !== false, 'claim-readiness scorecard is rendered');
ok(strpos($bridge_src, 'claim_readiness_expectation') !== false, 'OpenAI sample profile includes claim-readiness expectation');
ok(strpos($bridge_src, 'cross_platform_analysis_contract') !== false, 'OpenAI sample profile includes source-family roles');
ok(strpos($bridge_src, 'claim_readiness_judgement') !== false, 'OpenAI schema requires claim-readiness judgement');
ok(strpos($bridge_src, 'cross_platform_synthesis_depth') !== false, 'OpenAI schema requires synthesis-depth marker');

$schema_method = new ReflectionMethod('FIDTF_AI_Bridge', 'openai_smoke_response_schema');
$schema_method->setAccessible(true);
$schema = $schema_method->invoke(null);
ok(in_array('claim_readiness_judgement', (array) ($schema['required'] ?? []), true), 'schema requires claim_readiness_judgement');
ok(in_array('cross_platform_synthesis_depth', (array) ($schema['required'] ?? []), true), 'schema requires cross_platform_synthesis_depth');

$sample_method = new ReflectionMethod('FIDTF_AI_Bridge', 'prepare_openai_smoke_sample');
$sample_method->setAccessible(true);
$prepared = $sample_method->invoke(null, [
    'request_context' => ['keywords' => ['seo'], 'market' => 'ES'],
    'evidence' => [
        ['source_key' => 'tiktok', 'caption_or_text' => 'SEO tips', 'metrics' => ['views' => 779], 'relevance_tier' => 'direct'],
        ['source_key' => 'instagram', 'caption_or_text' => 'SEO agency post', 'metrics' => ['likes' => 0], 'relevance_tier' => 'direct'],
        ['source_key' => 'google_trends', 'title' => 'seo timeline', 'metrics' => ['trend_interest' => 100], 'relevance_tier' => 'direct'],
    ],
]);
ok(isset($prepared['sample_profile']['cross_platform_analysis_contract']['demand_movement']), 'prepared sample includes demand movement role');
ok(($prepared['sample_profile']['claim_readiness_expectation'] ?? '') !== '', 'prepared sample includes claim-readiness expectation');

fwrite(STDOUT, "FIDTF v0.3.45 full live GPT analysis depth checks passed: {$checks} / {$checks}\n");
