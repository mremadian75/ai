<?php
/**
 * Runtime start/source-access hardening checks — v0.9.30.21
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$plugin_root = dirname(__DIR__);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim(strip_tags($v)) : $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return is_string($v) ? trim(strip_tags($v)) : $v; } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return strip_tags((string) $v); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($v) { return (string) $v; } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return false; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 123; } }
if (!function_exists('wp_rand')) { function wp_rand($min = 0, $max = 0) { return 424242; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0, $depth = 512) { return json_encode($v, $flags); } }
if (!function_exists('add_query_arg')) { function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query((array) $args); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['ves_t'][$k] = $v; return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['ves_t'][$k] ?? false; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { unset($GLOBALS['ves_t'][$k]); return true; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['ves_o'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $autoload = null) { $GLOBALS['ves_o'][$k] = $v; return true; } }
if (!function_exists('remove_accents')) { function remove_accents($v) { return strtr((string) $v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N']); } }

require_once $plugin_root . '/includes/helpers.php';
require_once $plugin_root . '/includes/class-ves-source-adapter-registry.php';
require_once $plugin_root . '/includes/platform-input.php';
require_once $plugin_root . '/includes/class-ves-query-planner.php';
require_once $plugin_root . '/includes/analysis.php';
require_once $plugin_root . '/includes/class-ves-ajax-controller.php';
require_once $plugin_root . '/includes/class-ves-apify-client.php';

$failures = [];
$passes = [];
$assert = function($condition, $message) use (&$failures, &$passes) {
    if ($condition) { $passes[] = $message; } else { $failures[] = $message; }
};
$reflect = function($method) {
    $m = new ReflectionMethod('VES_Ajax_Controller', $method);
    $m->setAccessible(true);
    return $m;
};

$ajax = file_get_contents($plugin_root . '/includes/class-ves-ajax-controller.php');
$apify = file_get_contents($plugin_root . '/includes/class-ves-apify-client.php');

$assert(strpos($ajax, 'private static $active_run_context') !== false, 'AJAX controller tracks active run context for fatal diagnostics');
$assert(strpos($ajax, 'last_successful_stage') !== false, 'Fatal diagnostics preserve last successful stage');
$assert(strpos($ajax, 'cleanup_active_run_resources_after_fatal') !== false, 'Fatal path cleans start gates / slots / unposted reservations');
$assert(strpos($ajax, "'start_gate_acquired'") !== false, 'Start gate acquisition is recorded as a stage');
$assert(strpos($apify, 'record_diagnostic_safe') !== false, 'Apify client diagnostics are guarded against diagnostic-write failures');
$assert(substr_count($apify, 'VES_Admin::record_diagnostic') <= 1, 'Apify client does not use unguarded diagnostic writes in request path');

$classify = $reflect('classify_error_category');
$publicMessage = $reflect('public_error_message');
$category = $classify->invoke(null, 'ajax_start_fatal', 'Call to undefined function during source run', 500, ['stage' => 'caught_throwable', 'last_successful_stage' => 'query_planner_applied']);
$assert($category === 'ajax_fatal', 'Caught throwable during start is categorized as ajax_fatal');
$msg = $publicMessage->invoke(null, 'Call to undefined function during source run', 500, ['source' => 'ajax_start_fatal', 'stage' => 'caught_throwable']);
$assert(stripos($msg, 'request stopped') !== false || stripos($msg, 'could not be completed') !== false, 'Ajax fatal public message is accurate and not a misleading source-access bucket');
foreach (['Provider','Apify','actor','dataset','HTTP','request_id','source_execution_key'] as $bad) {
    $assert(stripos($msg, $bad) === false, "Ajax fatal public message hides {$bad}");
}

$source_access = $classify->invoke(null, 'unknown', 'Provider access failed. HTTP logico 500 [Ref: 1407d916-edf2]', 500, []);
$assert($source_access === 'provider_access_denied', 'Legacy provider-access raw message maps to provider_access_denied category');

if ($failures) {
    echo "FAILURES:\n" . implode("\n", array_map(fn($f) => ' - ' . $f, $failures)) . "\n";
    exit(1);
}

echo "v0.9.30.21 runtime start/source-access hardening tests passed (" . count($passes) . " checks).\n";
foreach ($passes as $p) { echo " - {$p}\n"; }
