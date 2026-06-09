<?php
/**
 * Focused Phase 0 diagnostics + YouTube runtime planner check — v0.9.30.21
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
if (!function_exists('add_query_arg')) { function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query((array) $args); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['ves_t'][$k] = $v; return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['ves_t'][$k] ?? false; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { unset($GLOBALS['ves_t'][$k]); return true; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['ves_o'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $autoload = null) { $GLOBALS['ves_o'][$k] = $v; return true; } }
if (!function_exists('ves_max_items')) { function ves_max_items() { return 100; } }
if (!function_exists('ves_hard_max_charge_usd')) { function ves_hard_max_charge_usd() { return 1.0; } }

require_once $plugin_root . '/includes/helpers.php';
require_once $plugin_root . '/includes/class-ves-source-adapter-registry.php';
require_once $plugin_root . '/includes/platform-input.php';
require_once $plugin_root . '/includes/class-ves-query-planner.php';
require_once $plugin_root . '/includes/analysis.php';
require_once $plugin_root . '/includes/class-ves-ajax-controller.php';

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

$support = $reflect('public_support_code')->invoke(null, '1407d916-edf2-47a0-9ec1-e8aa12dad7c1');
$assert((bool) preg_match('/^FI-[A-Z0-9]{6}$/', $support), 'Normal support code uses short FI-style format');
$assert(strpos($support, '1407') === false && strpos($support, 'dad7') === false, 'Support code does not expose raw request UUID fragments');

$classify = $reflect('classify_error_category');
$cases = [
    ['ajax_config', 'missing token', 500, ['stage' => 'missing_backend_token'], 'missing_backend_token'],
    ['ajax_validation', 'Payload contract failed', 400, ['stage' => 'actor_payload_contract'], 'actor_payload_contract_failed'],
    ['actor_dispatch_error', 'Unauthorized', 401, ['provider_state' => 'AUTH'], 'provider_auth_error'],
    ['actor_dispatch_error', 'Too many requests', 429, ['provider_state' => 'WAITING_RETRY'], 'provider_rate_limit'],
    ['actor_dispatch_error', 'Source run did not return a valid process identifier.', 500, ['stage' => 'provider_run_id_missing'], 'provider_run_id_missing'],
    ['dataset_fetch_failed', 'Results could not be retrieved.', 500, ['stage' => 'dataset_fetch_failed'], 'dataset_fetch_failed'],
];
foreach ($cases as [$source, $msg, $status, $ctx, $expected]) {
    $got = $classify->invoke(null, $source, $msg, $status, $ctx);
    $assert($got === $expected, "Error category {$expected} is classified correctly");
}

$publicMessage = $reflect('public_error_message');
$msg = $publicMessage->invoke(null, 'Provider access failed. HTTP logico 500 request_id: 1407d916-edf2-47a0-9ec1-e8aa12dad7c1 actor_slug apidojo/tiktok-scraper dataset_id DS', 500, [
    'source' => 'actor_dispatch_error',
    'request_id' => '1407d916-edf2-47a0-9ec1-e8aa12dad7c1',
    'actor_slug' => 'apidojo/tiktok-scraper',
    'dataset_id' => 'DS',
]);
$unsafe = ['Provider','Apify','actor','dataset','run ID','HTTP','request_id','1407d916','source_execution_key'];
foreach ($unsafe as $needle) {
    $assert(stripos($msg, $needle) === false, "Public error message hides {$needle}");
}
$assert(stripos($msg, 'source') !== false || stripos($msg, 'run') !== false, 'Public error message remains product-contextual');

$n = VES_Query_Planner::normalize_request_fields('standard', 'youtube', [
    'targets' => 'marketing',
    'country' => 'España',
    'language' => 'Español',
    'limit' => 50,
]);
$payload = VES_Query_Planner::build_actor_payload('standard', 'youtube', 'streamers/youtube-scraper', $n);
$pruned = VES_Query_Planner::prune_payload_for_actor_contract('youtube', 'streamers/youtube-scraper', $payload);
$assert(($payload['country'] ?? '') === 'ES', 'YouTube runtime planner maps Spain to ES');
$assert(($payload['country'] ?? '') !== 'NO', 'YouTube runtime planner never maps Spain to NO');
$assert(($payload['language'] ?? '') === 'es', 'YouTube runtime planner sends selected Spanish language');
$assert(!isset($payload['subtitlesLanguage']), 'YouTube runtime planner does not force subtitlesLanguage by default');
$assert(($payload['searchQueries'] ?? []) === ['marketing', 'marketing digital', 'marketing en español', 'estrategia de marketing'], 'YouTube runtime planner uses language-aware search terms');
$assert(count($payload['searchQueries'] ?? []) <= 5, 'YouTube runtime planner caps searchQueries to 3-5 terms');
$assert(($pruned['country'] ?? '') === 'ES' && ($pruned['language'] ?? '') === 'es', 'Adapter/contract pruning keeps YouTube country/language fields');
$assert(array_key_exists('maxResultsShorts', $pruned) && array_key_exists('maxResultStreams', $pruned), 'Adapter/contract pruning keeps YouTube shorts/streams fields');
$assert(!isset($pruned['subtitlesLanguage']) || $pruned['subtitlesLanguage'] !== 'en', 'Adapter/contract pruning does not inject English subtitles');

$n_any = VES_Query_Planner::normalize_request_fields('standard', 'youtube', ['targets'=>'marketing','country'=>'Cualquier país']);
$n_unknown = VES_Query_Planner::normalize_request_fields('standard', 'youtube', ['targets'=>'marketing','country'=>'Atlantis']);
$assert(($n_any['country'] ?? '') === '', 'Any-country omits country in runtime planner');
$assert(($n_unknown['country'] ?? '') === '', 'Unknown country omits country in runtime planner');

$qp = file_get_contents($plugin_root . '/includes/class-ves-query-planner.php');
$assert(strpos($qp, 'ves_youtube_search_terms') !== false, 'Canonical query planner calls ves_youtube_search_terms for YouTube');
$frontend = file_get_contents($plugin_root . '/assets/js/ves-frontend.js');
foreach (['El proveedor devolvió', 'HTTP logico', 'Provider access failed'] as $bad) {
    $assert(strpos($frontend, $bad) === false, "Normal frontend avoids {$bad}");
}
$assert(strpos($frontend, 'No usable social signals found') !== false || strpos($frontend, 'No usable signals matched your filters') !== false, 'Local-filter-empty state uses no-usable-signals wording');

if ($failures) {
    echo "FAILURES:\n" . implode("\n", array_map(fn($f) => ' - ' . $f, $failures)) . "\n";
    exit(1);
}

echo "v0.9.30.21 diagnostics + YouTube runtime tests passed (" . count($passes) . " checks).\n";
foreach ($passes as $p) { echo " - {$p}\n"; }
