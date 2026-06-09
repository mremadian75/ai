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

if (!class_exists('VES_Usage_Billing')) {
    final class VES_Usage_Billing {
        public static $unlimited = false;
        public static $reserve_calls = 0;
        public static function is_unlimited_user($user_id = 0) { return self::$unlimited; }
        public static function reserve_usage($user_id, $event_type, $usage_key, $message = '', $context = []) {
            self::$reserve_calls++;
            return new WP_Error('usage_event_failed', 'No se pudo registrar la reserva de créditos.', ['status' => 402]);
        }
        public static function void_reserved_usage($usage_key, $message = '') { return true; }
    }
}

$root = dirname(__DIR__);
require_once $root . '/includes/class-ves-usage-reconciliation-service.php';
require_once $root . '/includes/class-ves-ajax-controller.php';

$checks = 0;
$failures = [];
$assert = function($condition, $label) use (&$checks, &$failures) {
    $checks++;
    if (!$condition) { $failures[] = $label; }
};

$main = file_get_contents($root . '/future-island-intelligence-suite.php');
// [v1.1.0 archived] the unified FIIS v1.1.0 package ships no root README.md; the version contract lives
// in the plugin header / VES_PLUGIN_VERSION (asserted below), so the stale README-version read is removed.
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$service = file_get_contents($root . '/includes/class-ves-run-execution-service.php');
$recon = file_get_contents($root . '/includes/class-ves-usage-reconciliation-service.php');
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$openai = file_get_contents($root . '/includes/class-ves-openai-client.php');

$assert(strpos($main, 'Version: 1.2.4') !== false, 'plugin header bumped to p3.1 live QA hotfix');
$assert(strpos($main, "VES_PLUGIN_VERSION',         '1.2.4'") !== false, 'VES_PLUGIN_VERSION bumped to p3.1 live QA hotfix');
// [v1.1.0 archived] removed obsolete 'README version bumped' assertion: no root README.md exists in the
// unified package; the version contract is the plugin header / VES_PLUGIN_VERSION, already asserted above.

VES_Usage_Billing::$unlimited = true;
VES_Usage_Billing::$reserve_calls = 0;
$reserve = VES_Usage_Reconciliation_Service::reserve_for_bundle('B-ADMIN', 99, 'trend_run', ['topic' => 'cerveza']);
$assert(is_array($reserve) && !empty($reserve['virtual_reservation']), 'admin unlimited receives virtual reservation');
$assert(($reserve['credits_reserved'] ?? true) === false, 'admin unlimited virtual reservation has credits_reserved=false');
$assert(VES_Usage_Billing::$reserve_calls === 0, 'admin unlimited bypasses failing reserve_usage write');

VES_Usage_Billing::$unlimited = false;
VES_Usage_Billing::$reserve_calls = 0;
$reserveFail = VES_Usage_Reconciliation_Service::reserve_for_bundle('B-NORMAL', 23, 'trend_run', ['topic' => 'cerveza']);
$assert(is_wp_error($reserveFail), 'non-admin still receives billing WP_Error when reservation fails');
$assert(VES_Usage_Billing::$reserve_calls === 1, 'non-admin reservation path still calls reserve_usage');

$ref = new ReflectionClass('VES_Ajax_Controller');
$classify = $ref->getMethod('classify_error_category');
$classify->setAccessible(true);
$msgFor = $ref->getMethod('public_message_for_error_category');
$msgFor->setAccessible(true);
$diag = $ref->getMethod('build_safe_diagnostic');
$diag->setAccessible(true);

$usageCat = $classify->invoke(null, 'trend_dispatch', 'No se pudo registrar la reserva de créditos (admin ilimitado).', 500, ['stage' => 'create_run', 'error_category' => 'usage_reservation_failed']);
$assert($usageCat === 'usage_reservation_failed', 'credit reservation failure is not classified as provider_busy');
$usageMsg = $msgFor->invoke(null, 'usage_reservation_failed');
$assert(strpos($usageMsg, 'No se consumieron créditos finales') !== false, 'credit reservation message says no final credits consumed');
$assert(stripos($usageMsg, 'temporarily busy') === false && stripos($usageMsg, 'fuente') === false, 'credit reservation message does not blame source/provider');

$slotCat = $classify->invoke(null, 'trend_dispatch', 'This exact dispatch plan is already running.', 429, ['stage' => 'create_run', 'error_category' => 'dispatch_slot_limit']);
$assert($slotCat === 'dispatch_slot_limit', 'dispatch_slot_limit remains dispatch category, not provider_busy');
$slotMsg = $msgFor->invoke(null, 'dispatch_slot_limit');
$assert(strpos($slotMsg, 'El sistema no pudo iniciar') !== false, 'dispatch_slot_limit has system-busy copy');
$assert(stripos($slotMsg, 'source is temporarily busy') === false, 'dispatch_slot_limit copy does not say source is temporarily busy');

$dupMsg = $msgFor->invoke(null, 'duplicate_dispatch_blocked');
$assert(strpos($dupMsg, 'Ya hay una ejecución en curso') !== false, 'duplicate click gets active-run copy');

$safe = $diag->invoke(null, 'dispatch_slot_limit', ['stage' => 'create_run', 'credits_reserved' => false, 'final_credit_settled' => false, 'credit_voided' => true], 'FI-TEST');
$assert(($safe['retryable'] ?? null) === true, 'dispatch_slot_limit safeDiagnostic.retryable=true');
$assert(($safe['credits_reserved'] ?? true) === false, 'safeDiagnostic carries credits_reserved=false');
$assert(($safe['credit_voided'] ?? false) === true, 'safeDiagnostic carries credit_voided');

$assert(strpos($service, "new WP_Error('usage_reservation_failed'") !== false, 'Trend create_run wraps reserve failure as usage_reservation_failed');
$assert(strpos($service, "'provider_call_attempted' => false") !== false, 'reserve failure explicitly happens before provider call');
$assert(strpos($ajax, "'actions' => array_values(array_unique(") !== false, 'AJAX error payload carries retry actions');
$assert(strpos($ajax, "'reduced_scope'") !== false, 'AJAX only exposes reduced_scope for provider-side transient errors');
$assert(strpos($recon, 'virtual_unlimited') !== false, 'usage reconciliation has virtual_unlimited admin path');

$assert(strpos($js, "displayLabel = 'Sistema ocupado'") !== false, 'frontend known dispatch errors show Sistema ocupado title');
$assert(strpos($js, "displayLabel = 'Ejecución no iniciada'") !== false, 'frontend known reservation errors show Ejecución no iniciada title');
$assert(strpos($js, "const showReduced = actions.includes('reduced_scope')") !== false, 'frontend reduced-scope button is conditional');
$assert(strpos($js, "['provider_busy','provider_rate_limit','provider_timeout','provider_transport_error'].includes(code)") !== false, 'frontend reduced-scope is for provider errors, not dispatch_slot_limit');
$assert(strpos($js, "vesErrorStatusHtml('Ejecución no iniciada', err, widget)") !== false, 'Trend start catch no longer labels known errors Error inesperado');

$assert(strpos($js, 'No hay señales utilizables.') !== false, 'Spanish no-usable-signals copy exists');
$assert(strpos($js, 'La fuente devolvió resultados, pero ninguno pasó los filtros de relevancia, idioma, mercado o calidad.') !== false, 'raw>0 usable=0 uses filtered-results Spanish copy');
$assert(strpos($js, 'La fuente no devolvió resultados para esta consulta.') !== false, 'raw=0 uses no-results Spanish copy');
$assert(strpos($js, 'No usable signals found.') === false, 'English no-usable status copy removed from frontend');

$assert(strpos($openai, 'GPT-5.1') === false, 'fallback/source file does not hardcode fake GPT-5.1 label');
$assert(strpos($openai, "'model_label' => 'Análisis local de respaldo'") !== false, 'local_fallback label remains honest');
$assert(strpos($openai, "'model_label' => 'Texto AI + métricas locales'") !== false, 'invalid AI JSON/text label remains honest');

if ($failures) {
    fwrite(STDERR, "v1.1.0 checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'v1.1.0 checks passed: ' . $checks . ' / ' . $checks . PHP_EOL;
