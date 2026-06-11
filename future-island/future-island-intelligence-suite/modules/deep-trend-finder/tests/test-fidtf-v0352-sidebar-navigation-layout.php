<?php
$root = dirname(__DIR__);
function ok($cond, $message) {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$template = file_get_contents($root . '/templates/shortcode-deep-trend-finder.php');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$js = file_get_contents($root . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.53
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: version constant bumped to v0.3.53
ok(strpos($template, 'data-fidtf-sidebar') !== false, 'sidebar markup exists');
ok(strpos($template, 'data-fidtf-sidebar-summary') !== false, 'sidebar summary slot exists');
ok(strpos($template, 'data-fidtf-sidebar-actions') !== false, 'sidebar actions slot exists');
ok(strpos($template, 'data-fidtf-uid') !== false, 'root exposes unique UID for anchors');
ok(strpos($template, '-sources') !== false && strpos($template, '-evidence') !== false && strpos($template, '-report-shell') !== false && strpos($template, '-full-output') !== false, 'major section anchors exist');
ok(strpos($css, '.fidtf-control-sidebar') !== false, 'sidebar CSS exists');
ok(strpos($css, 'position: sticky') !== false, 'desktop sticky behavior exists');
ok(strpos($css, '.fidtf-sidebar-nav') !== false, 'sidebar nav CSS exists');
ok(strpos($js, 'updateSidebarPlanning') !== false, 'planning sidebar JS exists');
ok(strpos($js, 'updateSidebarRun') !== false, 'run sidebar JS exists');
ok(strpos($js, 'updateSidebarReportLoaded') !== false, 'report sidebar JS exists');
ok(strpos($js, 'data-fidtf-load-report') !== false && strpos($js, 'data-fidtf-load-items') !== false, 'sidebar exposes report and evidence actions');

fwrite(STDOUT, "FIDTF v0.3.53 sidebar navigation layout checks passed.\n");
