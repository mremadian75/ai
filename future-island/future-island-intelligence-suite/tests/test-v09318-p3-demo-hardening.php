<?php
require __DIR__ . '/bootstrap-wp-shims.php';

if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('VES_PRODUCTION_MVP')) { define('VES_PRODUCTION_MVP', true); }
if (!defined('VES_ENABLE_DEEP_VIDEO_ANALYSIS')) { define('VES_ENABLE_DEEP_VIDEO_ANALYSIS', false); }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = false) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null, $enc = null) { return $length === null ? substr((string) $s, (int) $start) : substr((string) $s, (int) $start, (int) $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $enc = null) { return strtolower((string) $s); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return trim((string) $s); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('ves_sanitize_country_code')) { function ves_sanitize_country_code($v) { return strtoupper(trim((string) $v)) ?: 'None'; } }
if (!function_exists('ves_sanitize_language_code')) { function ves_sanitize_language_code($v) { return strtolower(preg_replace('/[^a-z\-]/i', '', (string) $v)); } }
if (!function_exists('ves_clean_iso_date')) { function ves_clean_iso_date($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : ''; } }

$root = dirname(__DIR__);
require_once $root . '/includes/trend-finder.php';
require_once $root . '/includes/class-ves-temp-memory.php';
require_once $root . '/includes/class-ves-ajax-controller.php';

$checks = 0;
$failures = [];
$assert = function($condition, $label) use (&$checks, &$failures) {
    $checks++;
    if (!$condition) { $failures[] = $label; }
};

$main = file_get_contents($root . '/future-island-intelligence-suite.php');
// [v1.1.0 archived] the unified FIIS v1.1.0 package ships no root README.md; the version contract lives
// in the plugin header / VES_PLUGIN_VERSION (asserted above), so the stale README-version read is removed.
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$assert(strpos($main, 'Version: 1.2.7') !== false, 'plugin header bumped to p3 demo hardening');
$assert(strpos($main, "VES_PLUGIN_VERSION',         '1.2.7'") !== false, 'VES_PLUGIN_VERSION bumped to p3 demo hardening');
// [v1.1.0 archived] removed obsolete 'README version bumped' assertion: no root README.md exists in the
// unified package; the version contract is the plugin header / VES_PLUGIN_VERSION, already asserted above.

$alias = ves_trend_sanitize_request(['trendFuentes' => 'search_only', 'trendCategory' => 'cerveza']);
$assert(($alias['source_mode'] ?? '') === 'search_only', 'trendFuentes alias is honored by backend');
$assert(($alias['trendSources'] ?? '') === 'search_only', 'trendSources mirrors trendFuentes alias');
$assert(($alias['sourceMode'] ?? '') === 'search_only', 'sourceMode mirrors backend source_mode');

$reduced = ves_trend_sanitize_request([
    'trendFuentes' => 'balanced',
    'reducedScope' => '1',
    'maxSources' => 9,
    'candidatePoolSize' => 80,
    'desiredFinalTrends' => 20,
]);
$assert(($reduced['source_mode'] ?? '') === 'search_only', 'reducedScope forces source_mode search_only');
$assert((int) ($reduced['max_sources'] ?? 99) <= 2, 'reducedScope clamps max_sources <= 2');
$assert((int) ($reduced['maxSources'] ?? 99) <= 2, 'reducedScope mirrors maxSources <= 2');
$assert((int) ($reduced['candidatePoolSize'] ?? 99) <= 15, 'reducedScope clamps candidatePoolSize <= 15');
$assert((int) ($reduced['desiredFinalTrends'] ?? 99) <= 3, 'reducedScope clamps desiredFinalTrends <= 3');
$assert(($reduced['trendFuentes'] ?? '') === 'search_only', 'reducedScope mirrors trendFuentes search_only');

$assert(strpos($js, 'next.trendFuentes = mode') !== false, 'frontend reduced payload sets trendFuentes');
$assert(strpos($js, 'next.trendSources = mode') !== false, 'frontend reduced payload sets trendSources');
$assert(strpos($js, 'next.sourceMode = mode') !== false, 'frontend reduced payload sets sourceMode');
$assert(strpos($js, 'next.source_mode = mode') !== false, 'frontend reduced payload sets source_mode');
$assert(strpos($js, 'next.max_sources = next.maxSources') !== false, 'frontend reduced payload sets max_sources');
$assert(strpos($js, 'next.reduced_scope') !== false, 'frontend reduced payload sets reduced_scope');

$assert(strpos($ajax, "return ['news', 'trends', 'images', 'maps', 'crawler', 'advanced'];") !== false, 'Google advanced modules remain gated including advanced');
$assert(strpos($ajax, 'google_basic_diagnostic_payload') !== false, 'Google Basic diagnostic helper exists');
$assert(strpos($ajax, 'Google Intelligence Basic no está disponible con la configuración actual') !== false, 'missing Google Basic config gives honest diagnostic copy');
$assert(strpos($ajax, "'final_credit_settled' => false") !== false, 'Google Basic config diagnostic does not settle final credit');
$assert(strpos($ajax, 'self::success(self::google_basic_diagnostic_payload') !== false, 'Google Basic config diagnostic returns safe payload before provider dispatch');
$assert(strpos($js, "vesErrorStatusHtml('Google Intelligence Basic', err, widget)") !== false, 'ajaxGoogleStart catch uses vesErrorStatusHtml');
$assert(strpos($js, "['CONFIG_REQUIRED','NO_EVIDENCE','HONEST_DIAGNOSTIC'].includes(data.status)") !== false, 'Google diagnostic status is handled without fake report');

$mem = new ReflectionClass('VES_Temp_Memory');
$prepTrend = $mem->getMethod('prepare_trend_payload');
$prepTrend->setAccessible(true);
$partialJson = $prepTrend->invoke(null, [
    'report_mode' => 'source_failure_partial_report',
    'report_status' => 'validation_report',
    'run_status' => 'partial',
    'memory_type' => 'validated_insight',
    'analysis' => 'Partial analysis',
    'analysis_structured' => ['executive_summary' => 'Partial'],
]);
$partial = json_decode($partialJson, true);
$assert(($partial['memory_type'] ?? '') !== 'validated_insight', 'Trend partial is not saved as validated_insight');
$assert(($partial['memory_type'] ?? '') === 'source_failure_history', 'Trend partial becomes source_failure_history');

$diagJson = $prepTrend->invoke(null, [
    'report_mode' => 'retryable_diagnostic',
    'report_status' => 'retryable_diagnostic',
    'run_status' => 'retryable_diagnostic',
    'memory_type' => 'validated_insight',
    'fallback_used' => true,
    'analysis' => 'No evidence',
]);
$diag = json_decode($diagJson, true);
$assert(($diag['memory_type'] ?? '') !== 'validated_insight', 'Retryable diagnostic is not saved as validated insight');
$assert(($diag['memory_type'] ?? '') === 'source_failure_history', 'Retryable diagnostic becomes source_failure_history');

$prepSearch = $mem->getMethod('prepare_search_payload');
$prepSearch->setAccessible(true);
$socialJson = $prepSearch->invoke(null, [
    'platform' => 'tiktok',
    'items' => [],
    'metrics' => ['views' => 80700, 'likes' => 2786, 'comments' => 97, 'shares' => 721, 'saves' => null, 'engagement_visible' => true],
    'evidence_quality' => ['caption' => true, 'metrics' => true, 'video_frames' => false, 'audio' => false, 'transcript' => false],
    'model_label' => 'Análisis local de respaldo',
    'analysis_source' => 'local_fallback',
    'limitations' => ['No se realizó análisis visual frame-by-frame ni análisis de audio.'],
]);
$social = json_decode($socialJson, true);
$assert(($social['metrics']['views'] ?? null) === 80700, 'Social memory keeps normalized metrics');
$assert(($social['evidence_quality']['metrics'] ?? false) === true, 'Social memory keeps evidence_quality');
$assert(($social['model_label'] ?? '') === 'Análisis local de respaldo', 'Local fallback model_label survives memory payload');
$assert(($social['analysis_source'] ?? '') === 'local_fallback', 'analysis_source survives memory payload');
$assert(!empty($social['limitations']), 'limitations survive memory payload');

$assert(strpos(file_get_contents($root . '/includes/class-ves-temp-memory.php'), 'report_template_unreadable') !== false, 'unreadable memory entries get safe status');
$assert(strpos($js, 'El registro puede estar incompleto o ser ilegible') !== false, 'frontend memory unreadable item does not break recent reports');

if ($failures) {
    fwrite(STDERR, "v1.1.0 checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'v1.1.0 checks passed: ' . $checks . ' / ' . $checks . PHP_EOL;
