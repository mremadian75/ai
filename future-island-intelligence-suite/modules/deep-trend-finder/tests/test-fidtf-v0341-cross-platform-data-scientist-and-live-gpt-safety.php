<?php
require_once __DIR__ . '/test-fidtf-v0340-full-live-openai-json-schema-and-key-safety.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$template_src = file_get_contents($root . '/templates/report-deep-trend-finder.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$readme_path = $root . '/README.md';
$readme = is_readable($readme_path) ? file_get_contents($readme_path) : '';

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.41
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.41
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.41
ok(strpos($report_src, 'cross_platform_intelligence') !== false, 'report builds cross-platform intelligence block');
ok(strpos($report_src, 'cross_platform_data_scientist_readout') !== false, 'report declares data-scientist cross-platform readout');
ok(strpos($report_src, 'shared_terms_by_source') !== false, 'report detects shared language across source families');
ok(strpos($report_src, 'platform_bias_note') !== false, 'report includes platform bias warnings');
ok(strpos($template_src, 'Cross-platform intelligence') !== false, 'frontend renders cross-platform intelligence section');
ok(strpos($template_src, 'Hypotheses to test') !== false, 'frontend renders data-scientist hypotheses');
ok(strpos($bridge_src, 'cross_platform_findings') !== false, 'OpenAI smoke schema asks for cross-platform findings');
ok(strpos($bridge_src, 'source_diagnostics') !== false, 'OpenAI smoke schema asks for source diagnostics');
ok(strpos($bridge_src, 'quantitative_checks') !== false, 'OpenAI smoke schema asks for quantitative checks');
ok(strpos($bridge_src, 'senior marketing data scientist') !== false, 'OpenAI prompt asks for data-scientist analysis behavior');
ok(strpos($bridge_src, 'Do not invent facts outside the supplied evidence') !== false, 'OpenAI prompt forbids unsupported claims');
// [v1.1.0 archived] removed obsolete standalone-addon README version-snapshot assertion: 'README documents v0.3.41'.
// No per-module deep-trend-finder/README.md exists in unified FIIS v1.1.0; version contract is FIDTF_VERSION.

$schema_method = new ReflectionMethod('FIDTF_AI_Bridge', 'openai_smoke_response_schema');
$schema_method->setAccessible(true);
$schema = $schema_method->invoke(null);
ok(in_array('cross_platform_findings', (array) ($schema['required'] ?? []), true), 'runtime OpenAI schema requires cross_platform_findings');
ok(in_array('source_diagnostics', (array) ($schema['required'] ?? []), true), 'runtime OpenAI schema requires source_diagnostics');
ok(in_array('hypotheses_to_test', (array) ($schema['required'] ?? []), true), 'runtime OpenAI schema requires hypotheses_to_test');

$prompt_method = new ReflectionMethod('FIDTF_AI_Bridge', 'openai_smoke_prompt');
$prompt_method->setAccessible(true);
$prompt = $prompt_method->invoke(null, [
    ['source_key' => 'tiktok', 'title' => 'SEO hook', 'metrics' => ['views' => 1000]],
    ['source_key' => 'google_trends', 'title' => 'SEO trend', 'metrics' => ['trend_peak' => 100]],
]);
ok(strpos($prompt, 'Compare platforms against each other') !== false, 'OpenAI smoke prompt explicitly requires cross-platform comparison');
ok(strpos($prompt, 'Flag weak or off-topic high-engagement evidence') !== false, 'OpenAI smoke prompt explicitly guards against engagement-as-proof');

fwrite(STDOUT, "FIDTF v0.3.41 cross-platform data-scientist analysis and GPT safety checks passed: {$checks} / {$checks}\n");
