<?php
/**
 * v0.9.31.6 — BUG-06: report renderer emits diagnostic before silent fallback.
 * Run: php tests/test-v09315-renderer-diagnostic.php
 */
$root = dirname(__DIR__);
$ren  = file_get_contents($root . '/includes/reports/class-ves-report-renderer.php');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.8') !== false, 'Version is 1.1.0');

$assert(strpos($ren, 'report_template_unreadable') !== false, 'Renderer raises report_template_unreadable diagnostic');
$assert(strpos($ren, 'VES_Admin::record_diagnostic') !== false, 'Renderer calls VES_Admin::record_diagnostic');
$assert(strpos($ren, "VES_Report_Template_Registry::get('empty-report')") !== false, 'Renderer still falls back to empty-report after logging');

// Ensure the diagnostic is emitted BEFORE the fallback file assignment.
$diag_pos = strpos($ren, 'report_template_unreadable');
$fallback_pos = strpos($ren, "\$file = VES_Report_Template_Registry::get('empty-report')");
$assert($diag_pos !== false && $fallback_pos !== false && $diag_pos < $fallback_pos, 'diagnostic is emitted before the silent fallback assignment');

if ($fail === 0) {
    echo "OK v0.9.31.6 renderer diagnostic — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 renderer diagnostic — $fail failures, $pass passed\n";
    exit(1);
}
