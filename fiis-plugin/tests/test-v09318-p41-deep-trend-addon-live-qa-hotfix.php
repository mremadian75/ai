<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('__')) { function __($s, $domain = null) { return $s; } }
if (!function_exists('home_url')) { function home_url($path = '') { return 'https://example.test' . $path; } }
if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['__ves_options'][$key] ?? $default; } }
if (!function_exists('get_permalink')) { function get_permalink($id) { return 'https://example.test/?page_id=' . (int) $id; } }
if (!function_exists('shortcode_exists')) { function shortcode_exists($tag) { return false; } }
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        if ($tag === 'future_island_register_external_modules') {
            $value['deep_trend_finder'] = [
                'key' => 'deep_trend_finder',
                'shortcode' => 'future_island_deep_trend_finder',
                'url' => 'https://example.test/deep-trend-finder/',
                'status' => 'active',
            ];
        }
        return $value;
    }
}
class FIDTF_Plugin {}
function fidtf_module_info() {
    return [
        'key' => 'deep_trend_finder',
        'shortcode' => 'future_island_deep_trend_finder',
        'url' => 'https://example.test/deep-trend-finder/',
        'status' => 'active',
    ];
}
function fidtf_get_frontend_url() { return 'https://example.test/deep-trend-finder/'; }
function fidtf_get_frontend_page_id() { return 77; }

require_once dirname(__DIR__) . '/includes/class-ves-deep-trend-addon-bridge.php';
$checks = 0;
$ok = function($condition, $label) use (&$checks) { $checks++; if (!$condition) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); } };

$ok(VES_Deep_Trend_Addon_Bridge::addon_active() === true, 'bridge detects active add-on before shortcode registration timing');
$ok(VES_Deep_Trend_Addon_Bridge::addon_page_url() === 'https://example.test/deep-trend-finder/', 'bridge returns add-on frontend URL');
$info = VES_Deep_Trend_Addon_Bridge::addon_module_info();
$ok(($info['key'] ?? '') === 'deep_trend_finder', 'bridge exposes module key');
$ok(($info['url'] ?? '') === 'https://example.test/deep-trend-finder/', 'bridge exposes module URL');

fwrite(STDOUT, "p4.1 deep trend add-on live QA hotfix checks passed: {$checks} / {$checks}\n");
