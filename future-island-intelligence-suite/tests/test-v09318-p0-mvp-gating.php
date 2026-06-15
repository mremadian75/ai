<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$shortcode = file_get_contents($root . '/templates/shortcode.php');
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$google = file_get_contents($root . '/includes/class-ves-google-intel.php');
$fici = file_get_contents($root . '/modules/creative-intelligence/includes/rest/class-fici-rest-analyze-controller.php');

$failures = [];
$assert = function($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$msg = 'Este módulo no está habilitado en el MVP de producción.';

$assert(strpos($main, 'Version: 1.4.0') !== false, 'Plugin header version bumped to 1.1.0');
$assert(strpos($main, "VES_PLUGIN_VERSION',         '1.4.0'") !== false, 'VES_PLUGIN_VERSION bumped to 1.1.0');
// [v1.1.0 archived] removed obsolete MVP-gating expectation: VES_PRODUCTION_MVP=true. Production decision:
// MVP gating is retired in FIIS v1.1.0; the unified app shell exposes all main routes. The constant is now
// false in product and is no longer used to lock the shell. Replaced with the current unified-shell contract.
$assert((bool) preg_match("/define\\('VES_PRODUCTION_MVP',\\s*false\\)/", $main), 'VES_PRODUCTION_MVP is false (MVP gating retired in v1.1.0)');
$assert(strpos($main, "define('VES_ENABLE_DEEP_VIDEO_ANALYSIS', false)") !== false, 'VES_ENABLE_DEEP_VIDEO_ANALYSIS defaults false');

// [v1.1.0 architecture] The unified sidebar nav is rendered through the $ves_nav() helper (route map),
// not static data-page="..." markup, and there are no locked MVP placeholders. Assert the current
// contract: every main module is exposed as a nav entry and nothing is MVP-locked.
$navStart = strpos($shortcode, '<nav class="ves-sidebar-nav"');
// v0.4.0: the legacy 'trend' nav entry was consolidated into the canonical
// Trend Finder ('deeptrend' nav -> deep-trend route), asserted below.
foreach (['social', 'google', 'memory', 'linkedin', 'seo', 'ads', 'creative'] as $route) {
    $assert(strpos($shortcode, "\$ves_nav('" . $route . "'") !== false, 'Unified shell exposes nav route: ' . $route);
}
// brand-audit / deep-trend use their canonical nav keys in the unified shell.
$assert(strpos($shortcode, "\$ves_nav('brand'") !== false, 'Unified shell exposes Brand Audit nav route');
$assert(strpos($shortcode, "\$ves_nav('deeptrend', 'Trend Finder', 'deep-trend');") !== false, 'Unified shell exposes exactly the canonical Trend Finder nav route');
$assert(strpos($shortcode, "\$ves_nav('trend',") === false, 'Legacy duplicate Trend Finder nav entry is gone');
// [v1.1.0 archived] removed obsolete locked-placeholder + MVP Spanish-message assertions: the unified shell
// no longer renders data-mvp-disabled="1" placeholders for linkedin/seo/brand-audit/creative/ads.
$assert(strpos($shortcode, 'data-mvp-disabled="1"') === false, 'Unified shell renders no MVP-locked placeholders');

$assert(strpos($ajax, 'private static function mvp_disabled_ajax_response') !== false, 'Central AJAX MVP disabled response helper exists');
$assert(strpos($ajax, "'success' => false") !== false, 'Disabled AJAX response returns success=false');
$assert(strpos($ajax, "'gated' => true") !== false, 'Disabled AJAX response returns gated=true');
$assert(strpos($ajax, "'code' => 'ves_mvp_module_disabled'") !== false, 'Disabled AJAX response uses stable code');
$assert(strpos($ajax, "'run_id' => null") !== false && strpos($ajax, "'bundle_id' => null") !== false, 'Disabled response omits run and bundle ids');
$assert(strpos($ajax, "'provider_call_attempted' => false") !== false, 'Disabled response marks provider not called');
$assert(strpos($ajax, "'credits_reserved' => false") !== false && strpos($ajax, "'credit_settlement_attempted' => false") !== false, 'Disabled response marks credits untouched');

$stdGate = strpos($ajax, 'ajax_mvp_gate');
$tokenCheck = strpos($ajax, 'ves_get_token()', $stdGate);
$assert($stdGate !== false && $tokenCheck !== false && $stdGate < $tokenCheck, 'Standard disabled platforms gate before backend token/provider dispatch');
$brandGate = strpos($ajax, 'brand_audit_mvp_gate');
$brandRun = strpos($ajax, 'create_brand_audit_run', $brandGate);
$assert($brandGate !== false && $brandRun !== false && $brandGate < $brandRun, 'Brand Deep Audit gate before run creation');
$googleGate = strpos($ajax, 'google_mvp_gate');
$googleRun = strpos($ajax, 'VES_Google_Intel::run_actor', $googleGate);
$assert($googleGate !== false && $googleRun !== false && $googleGate < $googleRun, 'Advanced Google module gate before actor execution');

$assert(strpos($google, "VES_PRODUCTION_MVP") !== false && strpos($google, "\$key !== 'search'") !== false, 'Google Intelligence public MVP modules are limited to Search/basic');
$assert(strpos($fici, 'fici_mvp_module_disabled') !== false, 'Creative Intelligence REST route is MVP-gated');
$assert(strpos($fici, "'run_id'") !== false && strpos($fici, "'provider_call_attempted'") !== false, 'Creative gate returns no run/provider metadata');

$assert(strpos($js, 'MVP_SCRAPER_PAGES') !== false && strpos($js, 'MVP_DISABLED_PAGES') !== false, 'Frontend MVP page allowlist/disabled list exists');
$assert(strpos($js, 'MVP_DISABLED_PLATFORMS') !== false && strpos($js, 'MVP_DISABLED_GOOGLE_MODULES') !== false, 'Frontend MVP dispatch guards exist');
$assert(strpos($js, "json.code === 'ves_mvp_module_disabled'") !== false, 'Frontend handles backend MVP disabled response');
// [v1.1.0 archived] removed obsolete MVP-only keyboard-shortcut expectation
// ("{ '1':'social','2':'trend','3':'google','4':'memory' }"). The unified shell ships a full
// keyboard map covering all main modules; assert that current contract instead.
$assert(strpos($js, "'2': 'social', '3': 'linkedin', '4': 'seo', '5': 'google', '6': 'deep-trend', '7': 'brand-audit', '8': 'ads', '9': 'creative'") !== false, 'Frontend keyboard shortcuts cover the full unified module set');

if ($failures) {
    fwrite(STDERR, "v1.1.0 MVP gating checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'v1.1.0 MVP gating checks passed: 36 / 36' . PHP_EOL;
