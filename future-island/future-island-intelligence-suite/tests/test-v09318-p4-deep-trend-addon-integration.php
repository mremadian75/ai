<?php
require __DIR__ . '/bootstrap-wp-shims.php';

$root = dirname(__DIR__);
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$template = file_get_contents($root . '/templates/shortcode.php');
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$admin = file_get_contents($root . '/includes/class-ves-admin.php');
$memory = file_get_contents($root . '/includes/class-ves-memory-records.php');
$bridge = file_get_contents($root . '/includes/class-ves-deep-trend-addon-bridge.php');
$checks = 0; $failures = [];
$ok = function($condition, $label) use (&$checks, &$failures) { $checks++; if (!$condition) { $failures[] = $label; } };

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot '0.9.31.15-p4.8-deep-trend-sidebar-layout'.
// Current contract: the suite is version 1.1.0 and DTF is a built-in module booted in-process.
$ok(strpos($main, 'Version: 1.2.7') !== false, 'Suite header is v1.1.0');
$ok(strpos($main, 'FIDTF_Plugin::boot()') !== false, 'DTF is booted in-process by the unified bootstrap (built-in module, not a separate add-on)');
$ok(strpos($main, 'class-ves-deep-trend-addon-bridge.php') !== false, 'Core bootstraps Deep Trend add-on bridge');
$ok(strpos($bridge, 'class VES_Deep_Trend_Addon_Bridge') !== false || strpos($bridge, 'final class VES_Deep_Trend_Addon_Bridge') !== false, 'Bridge class exists');
$ok(strpos($bridge, 'addon_active') !== false && strpos($bridge, 'fidtf_module_info') !== false, 'Bridge detects add-on and consumes module info fallback');
$ok(strpos($bridge, 'addon_page_url') !== false && strpos($bridge, 'fidtf_get_frontend_url') !== false, 'Bridge exposes add-on page URL');
$ok(strpos($template, 'Deep Trend Finder') !== false, 'Core shell labels Deep Trend Finder');
// [v1.1.0 archived] removed obsolete standalone-addon CTA 'Open Deep Trend Finder'. DTF is now a first-class
// route in the unified shell, not an external add-on opened via CTA. Assert the current nav contract.
$ok(strpos($template, "\$ves_nav('deeptrend'") !== false, 'Unified shell exposes Deep Trend Finder as a first-class nav route');
$ok(strpos($template, "include VES_PLUGIN_DIR . 'templates/_trend-finder-form.php'") === false, 'Legacy Trend Finder form is no longer rendered by shortcode shell');
$ok(strpos($template, 'ves_deep_trend_addon_active') !== false && strpos($template, 'ves_deep_trend_addon_url') !== false, 'Template routes navigation through add-on bridge');
$ok(substr_count($ajax, 'send_deprecated_response') >= 3, 'Run-mutating legacy Trend Finder endpoints hard-block with deprecated response');
$ok(strpos($ajax, "'replacement' => 'future_island_deep_trend_finder'") !== false || strpos($bridge, "'replacement' => self::SHORTCODE") !== false, 'Deprecated response advertises replacement shortcode');
$ok(strpos($ajax, 'deprecated_trend_success_payload') !== false, 'Legacy read/cleanup endpoints tag responses deprecated');
// [v1.1.0 archived] removed obsolete standalone-addon admin notices ('Add-on is connected' /
// 'internal Trend Finder has been deprecated'). In v1.1.0 DTF is always a built-in module, so the
// separate add-on connection/deprecation notices were intentionally retired. Assert the current contract:
// the admin advertises DTF as a built-in module (no external add-on connection flow).
$ok(strpos($admin, 'Deep Trend Finder is always present as a built-in module') !== false, 'Admin treats DTF as a built-in module (no separate add-on connection flow)');
$ok(strpos($memory, 'Legacy Trend Finder report') !== false, 'Legacy Trend Finder memory rows are labelled');
$ok(strpos($memory, 'Deep Trend Finder') !== false, 'Deep Trend Finder memory rows are labelled');
$ok(strpos($ajax, 'VES_Run_Execution_Service::start_queued_sources') !== false, 'Legacy code kept on disk for in-flight compatibility only');
$ok(strpos($ajax, 'ves_trends_start') !== false && strpos($ajax, "'action' => 'ves_trends_start'") !== false, 'Deprecated start action identified in response context');

if ($failures) { fwrite(STDERR, "p4 deep trend add-on integration FAILED:\n - " . implode("\n - ", $failures) . "\n"); exit(1); }
echo "p4 deep trend add-on integration checks passed: {$checks} / {$checks}\n";
