<?php
require __DIR__ . '/bootstrap-wp-shims.php';

if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('VES_PRODUCTION_MVP')) { define('VES_PRODUCTION_MVP', true); }
if (!defined('VES_ENABLE_DEEP_VIDEO_ANALYSIS')) { define('VES_ENABLE_DEEP_VIDEO_ANALYSIS', false); }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql') { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('wp_generate_uuid4')) { function wp_generate_uuid4() { return '11111111-2222-4333-8444-555555555555'; } }
if (!function_exists('wp_generate_password')) { function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return str_repeat('a', (int) $length); } }
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $t = 0) { return true; } }
if (!function_exists('ves_sanitize_country_code')) { function ves_sanitize_country_code($v) { return strtoupper(trim((string) $v)) ?: 'None'; } }
if (!function_exists('ves_sanitize_language_code')) { function ves_sanitize_language_code($v) { return strtolower(preg_replace('/[^a-z\-]/i', '', (string) $v)); } }
if (!function_exists('ves_clean_iso_date')) { function ves_clean_iso_date($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : ''; } }

if (!class_exists('VES_Config')) {
    class VES_Config {
        public static function get_settings() { return []; }
        public static function get_actor_slug($slot) { return 'apify/' . sanitize_key((string) $slot); }
        public static function estimate_trend_provider_cost($payloads) { return ['source_count' => count((array) $payloads)]; }
    }
}

$root = dirname(__DIR__);
require_once $root . '/includes/trend-finder.php';
require_once $root . '/includes/class-ves-trend-source-planner.php';
require_once $root . '/includes/class-ves-trend-query-planner.php';
require_once $root . '/includes/class-ves-trend-source-evaluator.php';
require_once $root . '/includes/class-ves-run-execution-service.php';

$checks = 0;
$failures = [];
$assert = function($condition, $label) use (&$checks, &$failures) {
    $checks++;
    if (!$condition) { $failures[] = $label; }
};

$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$form = file_get_contents($root . '/templates/_trend-finder-form.php');
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$service = file_get_contents($root . '/includes/class-ves-run-execution-service.php');

$assert(strpos($main, 'Version: 1.2.7') !== false, 'plugin version bumped to p2 trend lite');
$assert(strpos($main, "VES_PLUGIN_VERSION',         '1.2.7'") !== false, 'VES_PLUGIN_VERSION bumped to p2 trend lite');
// [v1.1.0 archived] removed obsolete 'P0 MVP flag remains true' expectation: MVP gating is retired in
// FIIS v1.1.0 (VES_PRODUCTION_MVP is false in product). The Trend Lite budget-clamp checks below remain
// active and assert the still-current sanitizer behavior independently of the MVP flag.
$assert((bool) preg_match("/define\\('VES_PRODUCTION_MVP',\\s*false\\)/", $main), 'VES_PRODUCTION_MVP is false (MVP gating retired in v1.1.0)');
$assert(strpos($main, "define('VES_ENABLE_DEEP_VIDEO_ANALYSIS', false)") !== false, 'Deep video flag remains false');

$sanitized = ves_trend_sanitize_request([
    'trendCategory' => 'cerveza',
    'trendCountry' => 'ES',
    'sourceBudgetLevel' => 'balanced',
    'trendSources' => 'balanced',
    'candidatePoolSize' => 80,
    'desiredFinalTrends' => 20,
]);
$assert($sanitized['sourceBudgetLevel'] === 'cheap_probe', 'MVP backend clamps balanced to cheap_probe');
$assert($sanitized['budget_level'] === 'cheap_probe', 'MVP backend budget_level cheap_probe');
$assert($sanitized['candidatePoolSize'] <= 20, 'MVP backend clamps candidatePoolSize to <= 20');
$assert($sanitized['desired_final_trends'] <= 5, 'MVP backend clamps desired final trends to <= 5');
$assert((int) ($sanitized['max_sources'] ?? 99) <= 3, 'MVP backend max_sources <= 3');
$assert($sanitized['trendSources'] === 'lite', 'MVP backend downgrades balanced source mode to lite');

$planned = VES_Trend_Source_Planner::build($sanitized);
$assert(($planned['mode'] ?? '') === 'cheap_probe', 'source planner mode cheap_probe');
$assert(($planned['source_mode'] ?? '') === 'lite', 'source planner source_mode lite');
$assert((int) ($planned['desired_final_trends'] ?? 99) <= 5, 'source planner desired_final <= 5');
$assert((int) ($planned['max_sources'] ?? 99) <= 3, 'source planner max_sources <= 3');
$assert(count((array) ($planned['payloads'] ?? [])) <= 3, 'source planner payloads capped to <= 3 in MVP');

$built = VES_Trend_Query_Planner::build(['topic' => 'cerveza', 'sourceBudgetLevel' => 'deep_scan', 'candidatePoolSize' => 200]);
$assert(($built['request']['sourceBudgetLevel'] ?? '') === 'cheap_probe', 'query planner receives sanitized cheap_probe request');
$assert((int) ($built['request']['candidatePoolSize'] ?? 99) <= 20, 'query planner candidate pool <= 20');
$assert(count((array) ($built['payloads'] ?? [])) <= 3, 'query planner payloads <= 3');

$assert(strpos($form, 'value="low_cost" selected') !== false || strpos($form, 'value="cheap_probe" selected') !== false, 'Trend Finder form defaults to low cost/lite');
$assert(strpos($form, 'name="candidatePoolSize" value="18"') !== false || strpos($form, 'name="candidatePoolSize" value="15"') !== false, 'Trend Finder form candidate pool default <=20');
$assert(strpos($form, 'name="desiredFinalTrends" value="4"') !== false || strpos($form, 'name="desiredFinalTrends" value="5"') !== false, 'Trend Finder form desired final default <=5');
$assert(strpos($form, 'value="deep_scan" disabled') !== false, 'deep_scan is not selectable by default');

$ref = new ReflectionClass('VES_Run_Execution_Service');
$classify = $ref->getMethod('classify_provider_error');
$classify->setAccessible(true);
foreach ([
    'This source is temporarily busy. Try again in a few minutes.',
    'too many concurrent runs',
    'provider busy',
    'rate limited by provider',
] as $msg) {
    $status = $classify->invoke(null, new WP_Error('provider_error', $msg, ['provider_state' => '']));
    $assert($status === 'provider_busy', 'provider_busy classification works for: ' . $msg);
}

$summary = VES_Trend_Source_Evaluator::summarize_run_sources([
    ['source_key' => 'google_search_freshness', 'source_status' => 'usable', 'analytical_usability_status' => 'usable', 'usable_records_count' => 4, 'direct_match_count' => 2, 'metric_completeness_score' => 0.8],
    ['source_key' => 'reddit_topic_posts', 'source_status' => 'provider_busy', 'dispatch_status' => 'provider_busy', 'analytical_usability_status' => 'failed', 'usable_records_count' => 0, 'metric_completeness_score' => 0],
]);
$assert((int) $summary['usable_sources'] === 1, 'one success + one busy keeps usable source count');
$assert((int) $summary['failed_sources'] === 1, 'one success + one busy counts failed source');
$assert((int) ($summary['provider_busy_sources'] ?? 0) === 1, 'provider_busy source counted');

$zeroSummary = VES_Trend_Source_Evaluator::summarize_run_sources([
    ['source_key' => 'google_trends_interest', 'source_status' => 'provider_busy', 'dispatch_status' => 'provider_busy', 'analytical_usability_status' => 'failed', 'usable_records_count' => 0],
]);
$diag = $ref->getMethod('build_retryable_trend_diagnostic');
$diag->setAccessible(true);
$payload = $diag->invoke(null, ['bundle_id' => 'B1', 'provider_cost' => ['source_count' => 1], 'query_plan' => []], ['topic' => 'cerveza', 'country' => 'ES', 'duration' => '30d'], [
    ['source_key' => 'google_trends_interest', 'source_status' => 'provider_busy', 'dispatch_status' => 'provider_busy', 'support_code' => 'FI-TEST123', 'notes' => 'busy'],
], $zeroSummary, 'REQ-P2');
$assert(($payload['status'] ?? '') === 'RETRYABLE_DIAGNOSTIC', 'all busy builds retryable diagnostic status');
$assert(($payload['error_code'] ?? '') === 'provider_busy', 'diagnostic error_code provider_busy');
$assert(($payload['safeDiagnostic']['retryable'] ?? false) === true, 'diagnostic safeDiagnostic.retryable true');
$assert(($payload['final_credit_settled'] ?? true) === false, 'zero evidence does not settle final credit');
$assert(($payload['credit_voided'] ?? false) === true, 'zero evidence marks reserved usage voided');
$assert(in_array('retry', (array) ($payload['actions'] ?? []), true), 'diagnostic contains retry action');
$assert(in_array('reduced_scope', (array) ($payload['actions'] ?? []), true), 'diagnostic contains reduced scope action');
$assert(in_array('diagnostic', (array) ($payload['actions'] ?? []), true), 'diagnostic contains diagnostic action');
$assert(strpos((string) ($payload['user_message'] ?? ''), 'No se consumieron créditos finales') !== false, 'diagnostic message says no final credits consumed');

$assert(strpos($js, 'const vesNullableMetric') !== false, 'frontend nullable metric helper exists');
$assert(strpos($js, 'saves: vesFirstMetric(card.saves') !== false, 'frontend saves uses nullable first metric helper');
$assert(strpos($js, 'saves:card.saves || card.raw?.collectCount || card.raw?.saveCount || 0') === false, 'frontend no longer invents missing saves as zero');
$assert(strpos($js, 'data-ves-retry="trend_reduced_scope"') !== false, 'frontend renders reduced scope action');
$assert(strpos($js, 'Ejecutar búsqueda reducida') !== false, 'frontend Spanish reduced scope copy exists');
$assert(strpos($js, 'Ver diagnóstico') !== false, 'frontend diagnostic action exists');
$assert(strpos($js, "vesErrorStatusHtml('Ejecución no iniciada', err, widget)") !== false, 'Trend Finder start catch uses vesErrorStatusHtml');
$assert(strpos($js, "vesErrorStatusHtml('Trend Finder Lite', data, widget)") !== false, 'Trend Finder polling diagnostic uses vesErrorStatusHtml');
$assert(strpos($js, 'normalizeTrendLitePayload') !== false && strpos($js, 'candidatePoolSize = Math.max(5, Math.min(maxPool') !== false, 'frontend reduced scope clamps candidate pool');
$assert(strpos($service, 'Las fuentes están temporalmente ocupadas. No se consumieron créditos finales') !== false, 'backend all-busy Spanish message exists');
$assert(strpos($service, 'Ejecución completada con evidencia parcial. Algunas fuentes no estuvieron disponibles.') !== false, 'backend partial Spanish message exists');

if ($failures) {
    fwrite(STDERR, "v1.1.0 checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'v1.1.0 checks passed: ' . $checks . ' / ' . $checks . PHP_EOL;
