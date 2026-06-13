<?php
require_once __DIR__ . '/test-fidtf-v033-core-addon-live-qa-hotfix.php';
if (!function_exists('sanitize_key')) { function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($text) { return trim(strip_tags((string) $text)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($text) { return trim(strip_tags((string) $text)); } }
if (!function_exists('checked')) { function checked($checked, $current = true, $echo = true) { return $checked == $current ? 'checked="checked"' : ''; } }
if (!function_exists('selected')) { function selected($selected, $current = true, $echo = true) { return $selected == $current ? 'selected="selected"' : ''; } }
if (!function_exists('has_filter')) { function has_filter($hook = null) { return !empty($GLOBALS['__fidtf_filters'][$hook]); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql') { return '2026-05-25 09:00:00'; } }

require_once dirname(__DIR__) . '/includes/providers/class-fidtf-core-apify-client-adapter.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-settings.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$settings_code = file_get_contents($root . '/includes/class-fidtf-settings.php');
$admin = file_get_contents($root . '/includes/class-fidtf-admin.php');
$rest = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$run = file_get_contents($root . '/includes/class-fidtf-run-service.php');
$provider = file_get_contents($root . '/includes/providers/class-fidtf-provider-tiktok.php');
$template = file_get_contents($root . '/templates/shortcode-deep-trend-finder.php');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$js = file_get_contents($root . '/assets/js/fidtf-frontend.js');

ok(strpos($plugin, '0.3.') !== false, 'plugin version remains in the v0.3 line');
ok(strpos($settings_code, 'live_preflight_status') !== false && strpos($rest, "'/preflight'") !== false, 'preflight status contract and REST route exist');
ok(strpos($settings_code, 'tiktok_live_ready') !== false, 'TikTok live readiness helper exists');
ok(strpos($run, 'run_intent') !== false && strpos($run, 'planned_brief_only') !== false && strpos($run, 'live_tiktok_collection') !== false, 'run intent distinguishes planned and live TikTok modes');
ok(strpos($provider, 'message_for_blocking_reason') !== false && strpos($provider, 'direct_token_missing') !== false, 'TikTok provider returns precise blocking reasons');
ok(strpos($admin, 'TikTok Live Readiness') !== false && strpos($admin, 'Final readiness') !== false, 'admin readiness panel exists');
ok(strpos($admin, 'Live dispatch is enabled globally, but TikTok is still disabled') !== false, 'admin warns when global live is on but TikTok is off');
ok(strpos($admin, 'Core Apify client is available. Enable TikTok bridge') !== false, 'admin success instruction exists when Core is ready but bridge is off');
ok(strpos($template, 'Create planned trend brief') !== false && strpos($template, 'Start TikTok trend run') !== false, 'frontend CTA labels reflect planned vs live mode');
ok(strpos($template, 'No Apify request until TikTok bridge is ready') !== false, 'source selection explains disabled TikTok bridge');
ok(strpos($template, 'No provider call will be made in this release') !== false, 'planned-only source helper exists');
ok(strpos($js, 'preflightWarningHtml') !== false && strpos($js, 'updateLiveUx') !== false, 'frontend preflight UI updates before run creation');
ok(strpos($js, 'Planned-only sources') !== false && strpos($js, 'fidtf-source-details') !== false, 'source rows use collapsed planned-only/details UI');
ok(strpos($js, 'No evidence yet') !== false && strpos($js, 'data-fidtf-load-items') !== false, 'show evidence is disabled when evidence count is zero');
ok(strpos($css, 'width: min(100%, 1280px)') !== false && strpos($css, '.fidtf-progressive-rows { grid-column: 1 / -1; }') !== false, 'layout max width and full-width progressive rows exist');
ok(strpos($css, 'repeat(auto-fit, minmax(260px, 1fr))') !== false, 'responsive minmax grid retained and widened');
ok(strpos($js, 'Waiting for provider bridge') === false, 'repeated provider bridge waiting copy removed');
ok(strpos($template . $js, 'sendPrompt(') === false, 'no undefined sendPrompt remains');
ok(strpos($template . $js . $admin, 'onclick=') === false, 'no inline onclick remains');
ok(strpos($template . $js, 'Chart.js') === false && strpos($template . $js, 'cdnjs.cloudflare.com') === false, 'no Chart.js/CDN dependency');

$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = array_merge(FIDTF_Settings::defaults(), [
    'enable_live_dispatch' => true,
    'enable_tiktok_live_bridge' => false,
    'tiktok_provider_mode' => 'core_apify_client',
]);
$not_ready = FIDTF_Settings::live_preflight_status(['tiktok']);
ok(empty($not_ready['tiktok_live_ready']), 'preflight says TikTok is not ready when bridge disabled');
ok(in_array('tiktok_live_bridge_disabled', $not_ready['blocking_reasons'], true), 'bridge disabled blocking reason is visible');

$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = array_merge(FIDTF_Settings::defaults(), [
    'enable_live_dispatch' => false,
    'enable_tiktok_live_bridge' => true,
    'tiktok_provider_mode' => 'core_apify_client',
]);
$global_off = FIDTF_Settings::live_preflight_status(['tiktok']);
ok(empty($global_off['tiktok_live_ready']) && in_array('global_live_disabled', $global_off['blocking_reasons'], true), 'preflight says TikTok is not ready when global live is disabled');

$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = array_merge(FIDTF_Settings::defaults(), [
    'enable_live_dispatch' => true,
    'enable_tiktok_live_bridge' => true,
    'tiktok_provider_mode' => 'apify_bridge',
    'tiktok_apify_token' => '',
]);
$missing_token = FIDTF_Settings::live_preflight_status(['tiktok']);
ok(empty($missing_token['tiktok_live_ready']) && in_array('direct_token_missing', $missing_token['blocking_reasons'], true), 'preflight blocks direct mode without token');

$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = array_merge(FIDTF_Settings::defaults(), [
    'enable_live_dispatch' => true,
    'enable_tiktok_live_bridge' => true,
    'tiktok_provider_mode' => 'apify_bridge',
    'tiktok_apify_token' => 'redacted-token',
]);
$ready = FIDTF_Settings::live_preflight_status(['tiktok', 'instagram']);
ok(!empty($ready['tiktok_live_ready']) && in_array('tiktok', $ready['live_sources'], true), 'preflight says TikTok is ready when global bridge and provider are ready');
ok(in_array('instagram', $ready['live_sources'], true), 'non-TikTok source can run live when multi-source bridge and actor mapping are ready');

fwrite(STDOUT, "FIDTF v0.3.4 live activation UI polish checks passed: {$checks} / {$checks}\n");
