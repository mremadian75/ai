<?php
require_once __DIR__ . '/test-fidtf-v0341-cross-platform-data-scientist-and-live-gpt-safety.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$template_src = file_get_contents($root . '/templates/report-deep-trend-finder.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$readme = file_get_contents($root . '/README.md');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.42
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.42
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.42
ok(strpos($report_src, 'cross_platform_statistical_scorecard') !== false, 'report computes cross-platform statistical scorecard');
ok(strpos($report_src, 'coverage_by_evidence_family') !== false, 'report separates social, discourse, demand and news evidence coverage');
ok(strpos($report_src, 'dominant_source_share') !== false, 'report detects dominant-source concentration risk');
ok(strpos($report_src, 'direct_evidence_share') !== false, 'report calculates direct evidence share');
ok(strpos($template_src, 'Data scientist scorecard') !== false, 'frontend renders data scientist scorecard');
ok(strpos($bridge_src, 'platform_weighting') !== false, 'OpenAI schema requires platform weighting');
ok(strpos($bridge_src, 'cross_source_consistency') !== false, 'OpenAI schema requires cross-source consistency');
ok(strpos($bridge_src, 'evidence_gaps') !== false, 'OpenAI schema requires evidence gaps');
ok(strpos($bridge_src, 'causal_limits') !== false, 'OpenAI schema requires causal limits');
ok(strpos($bridge_src, 'Correlation across platforms is not proof') !== false || strpos($bridge_src, 'correlation across platforms is not proof') !== false, 'OpenAI prompt forces causal caution');
// [v1.1.0 archived] removed obsolete standalone-addon README version-snapshot assertion: 'README documents v0.3.42'.
// No per-module deep-trend-finder/README.md exists in unified FIIS v1.1.0; version contract is FIDTF_VERSION.

$schema_method = new ReflectionMethod('FIDTF_AI_Bridge', 'openai_smoke_response_schema');
$schema_method->setAccessible(true);
$schema = $schema_method->invoke(null);
foreach (['platform_weighting', 'cross_source_consistency', 'evidence_gaps', 'causal_limits'] as $required) {
    ok(in_array($required, (array) ($schema['required'] ?? []), true), 'runtime OpenAI schema requires ' . $required);
}

fwrite(STDOUT, "FIDTF v0.3.42 cross-platform statistical scoring and GPT analysis contract checks passed: {$checks} / {$checks}\n");
