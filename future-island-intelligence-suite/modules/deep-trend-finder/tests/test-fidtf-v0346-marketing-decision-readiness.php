<?php
$root = dirname(__DIR__);
$files = [
    'main' => $root . '/../../future-island-intelligence-suite.php',
    'report_service' => $root . '/includes/class-fidtf-report-service.php',
    'relevance_filter' => $root . '/includes/class-fidtf-relevance-filter.php',
    'run_service' => $root . '/includes/class-fidtf-run-service.php',
    'rest_controller' => $root . '/includes/class-fidtf-rest-controller.php',
    'frontend_js' => $root . '/assets/js/fidtf-frontend.js',
    'form_template' => $root . '/templates/shortcode-deep-trend-finder.php',
    'report_template' => $root . '/templates/report-deep-trend-finder.php',
];
foreach ($files as $label => $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Missing file: {$label}\n");
        exit(1);
    }
}
function assert_contains_string($haystack, $needle, $label) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing {$label}: {$needle}\n");
        exit(1);
    }
}
$main = file_get_contents($files['main']);
$report_service = file_get_contents($files['report_service']);
$relevance_filter = file_get_contents($files['relevance_filter']);
$run_service = file_get_contents($files['run_service']);
$rest = file_get_contents($files['rest_controller']);
$js = file_get_contents($files['frontend_js']);
$form = file_get_contents($files['form_template']);
$template = file_get_contents($files['report_template']);
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: addon version header
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: addon version constant
assert_contains_string($report_service, "'schema_version' => 'fidtf.report.v3.8'", 'report schema v3.3');
assert_contains_string($report_service, 'marketing_decision_summary', 'marketing decision summary');
assert_contains_string($report_service, 'confidence_breakdown', 'confidence breakdown');
assert_contains_string($report_service, 'source_role_matrix', 'source role matrix');
assert_contains_string($report_service, 'is_report_noise_term', 'report noise filter');
assert_contains_string($relevance_filter, 'evidence_tier', 'evidence tier scoring');
assert_contains_string($relevance_filter, 'marketing_allowed_use', 'marketing allowed use');
assert_contains_string($run_service, 'sanitize_research_mode', 'research mode sanitizer');
assert_contains_string($rest, 'risk_note', 'REST risk note propagation');
assert_contains_string($js, 'Strong evidence', 'frontend evidence tiers');
assert_contains_string($js, 'Risk note:', 'frontend risk notes');
assert_contains_string($form, 'name="research_mode"', 'research mode form field');
assert_contains_string($template, 'Marketing decision summary', 'report decision UI');
assert_contains_string($template, 'Source role matrix', 'report source role UI');
echo "v0.3.49 marketing decision readiness regression checks passed.\n";
