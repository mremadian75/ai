<?php
/**
 * Focused static/functional check — v0.9.30.21
 *
 * Covers the live YouTube staging blocker:
 * - Spain/Any-country must not map to NO
 * - Spanish runs must not force subtitlesLanguage=en
 * - YouTube search expansion is limited and language-aware
 * - Relevant language-mismatch rows are preserved as adjacent, not discarded, unless strict filtering is active
 * - normal frontend strings avoid provider mechanics in result/empty-state wording
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$plugin_root = dirname(__DIR__);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
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
if (!function_exists('remove_accents')) {
    function remove_accents($v) {
        return strtr((string) $v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query((array) $args); }
}
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['ves_t'][$k] = $v; return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['ves_t'][$k] ?? false; } }
if (!function_exists('ves_max_items')) { function ves_max_items() { return 100; } }
if (!function_exists('ves_hard_max_charge_usd')) { function ves_hard_max_charge_usd() { return 1.0; } }

require_once $plugin_root . '/includes/helpers.php';
require_once $plugin_root . '/includes/class-ves-query-planner.php';
require_once $plugin_root . '/includes/platform-input.php';
require_once $plugin_root . '/includes/analysis.php';
require_once $plugin_root . '/includes/class-ves-ajax-controller.php';

$failures = [];
$passes = [];
$assert = function($condition, $message) use (&$failures, &$passes) {
    if ($condition) { $passes[] = $message; } else { $failures[] = $message; }
};

$n = VES_Query_Planner::normalize_request_fields('social', 'youtube', [
    'targets' => 'marketing',
    'country' => 'España',
    'language' => 'Español',
    'limit' => 50,
]);
$assert(($n['country'] ?? '') === 'ES', 'Spain/España maps to ES');
$assert(($n['country'] ?? '') !== 'NO', 'Spain/España never maps to NO');

$n_any = VES_Query_Planner::normalize_request_fields('social', 'youtube', [
    'targets' => 'marketing',
    'country' => 'Cualquier país',
]);
$assert(($n_any['country'] ?? '') === '', 'Any-country omits country instead of mapping to NO');

$n_unknown = VES_Query_Planner::normalize_request_fields('social', 'youtube', [
    'targets' => 'marketing',
    'country' => 'Atlantis',
]);
$assert(($n_unknown['country'] ?? '') === '', 'Unknown country omits country instead of mapping to NO');

$input = ves_build_platform_input('youtube', [
    'targets' => 'marketing',
    'country' => 'España',
    'language' => 'Español',
    'limit' => 50,
    'datePreset' => 'last_30_days',
    'includeTranscript' => 1,
]);
$queries = $input['searchQueries'] ?? [];
$assert(($input['country'] ?? '') === 'ES', 'YouTube actor input sends ES for Spain');
$assert(!isset($input['subtitlesLanguage']), 'YouTube Spanish discovery does not force subtitlesLanguage');
$assert(!in_array('en', [(string)($input['subtitlesLanguage'] ?? '')], true), 'YouTube Spanish discovery never defaults subtitlesLanguage to en');
$assert(count($queries) <= 5, 'YouTube query expansion is capped to 5 terms');
$assert(array_slice($queries, 0, 4) === ['marketing', 'marketing digital', 'marketing en español', 'estrategia de marketing'], 'YouTube query expansion is Spanish-aware and close to the primary query');

$strict_items = [[
    'title' => 'The marketing strategy that works with your business',
    'text' => 'The strategy that works with your business and your customers',
    'url' => 'https://www.youtube.com/watch?v=abc123',
]];
list($strict_filtered, $strict_dropped) = ves_filter_items_by_language_strict($strict_items, 'es', 'ES');
$assert(count($strict_filtered) === 0 && $strict_dropped === 1, 'Strict Spanish language filtering still drops clear English rows');

$ref = new ReflectionMethod('VES_Ajax_Controller', 'annotate_locale_adjacent_items');
$ref->setAccessible(true);
$lang_mismatch = 0;
$country_mismatch = 0;
$adjacent = $ref->invokeArgs(null, [$strict_items, 'es', 'ES', &$lang_mismatch, &$country_mismatch]);
$assert(count($adjacent) === 1, 'Non-strict YouTube locale mismatch keeps row as usable adjacent evidence');
$assert($lang_mismatch === 1, 'Non-strict YouTube locale pass counts language mismatch');
$assert(($adjacent[0]['locale_match_type'] ?? '') === 'adjacent_match', 'Non-strict YouTube locale mismatch marks row as adjacent_match');
$assert(($adjacent[0]['evidence_quality'] ?? '') === 'limited', 'Adjacent-only evidence is labelled limited');

$frontend = file_get_contents($plugin_root . '/assets/js/ves-frontend.js');
foreach (['El proveedor devolvió', 'HTTP logico', 'HTTP logical'] as $bad) {
    $assert(strpos($frontend, $bad) === false, "Normal frontend bundle avoids user-facing technical string: {$bad}");
}
$assert(strpos($frontend, 'Reintentar con consulta en español') !== false, 'Language mismatch suggestions are contextual');

if ($failures) {
    echo "FAILURES:\n" . implode("\n", array_map(fn($f) => ' - ' . $f, $failures)) . "\n";
    exit(1);
}

echo "v0.9.30.21 YouTube Phase 0 tests passed (" . count($passes) . " checks).\n";
foreach ($passes as $p) { echo " - {$p}\n"; }
