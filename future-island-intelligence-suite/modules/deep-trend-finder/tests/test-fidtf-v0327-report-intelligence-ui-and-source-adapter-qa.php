<?php
require_once __DIR__ . '/test-fidtf-v0326-professional-intelligence-and-quality-gates.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$report = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$template = file_get_contents($root . '/templates/report-deep-trend-finder.php');
$frontend = file_get_contents($root . '/assets/js/fidtf-frontend.js');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$rest = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.27
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.27
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.27

ok(strpos($report, "'schema_version' => 'fidtf.report.v3.2'") !== false, 'report schema upgraded to v3.2');
ok(strpos($report, 'request_focus') !== false, 'report extracts request focus from run context');
ok(strpos($report, 'evidence_fit_diagnosis') !== false, 'report outputs evidence fit diagnosis');
ok(strpos($report, 'signal_clusters') !== false, 'report outputs signal clusters');
ok(strpos($report, 'briefing_recommendations') !== false, 'report outputs briefing recommendations');
ok(strpos($report, "['per_page' => 200, 'internal' => true]") !== false, 'report can analyze up to 200 stored evidence rows internally');
ok(strpos($report, 'Do not describe this as brand demand') !== false, 'report warns when brand-direct evidence is weak');
ok(strpos($report, 'category/context-led') !== false, 'report identifies category/context-led runs');

ok(strpos($template, 'Evidence fit diagnosis') !== false, 'template renders evidence fit diagnosis');
ok(strpos($template, 'Signal clusters') !== false, 'template renders signal clusters');
ok(strpos($template, 'Briefing recommendations') !== false, 'template renders briefing recommendations');
ok(strpos($frontend, 'evidenceGroupLabel') !== false, 'frontend groups evidence by direct/adjacent/weak');
ok(strpos($frontend, 'Weak / background signals') !== false, 'frontend collapses weak evidence group');
ok(strpos($frontend, 'Why this evidence is shown') !== false, 'frontend hides long rationale behind details');
ok(strpos($css, '.fidtf-fit-card') !== false, 'CSS styles evidence fit cards');
ok(strpos($css, '.fidtf-evidence-group') !== false, 'CSS styles grouped evidence');
ok(strpos($rest, 'matched_core_keywords') !== false, 'REST response exposes matched core keywords for audit/debug');

fwrite(STDOUT, "FIDTF v0.3.27 report intelligence, UI grouping and adapter QA checks passed: {$checks} / {$checks}\n");
