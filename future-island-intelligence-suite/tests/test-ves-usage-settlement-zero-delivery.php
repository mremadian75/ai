<?php
/**
 * v0.1 RC — charge-on-failure settlement contract.
 *
 * Proves by EXECUTION that a provider run which SUCCEEDS upstream but delivers
 * nothing usable is never final-charged as a successful delivery:
 *  - zero raw rows + zero delivered  -> settlement_classification
 *    'not_chargeable_zero_delivery', final cost 0.0
 *  - raw rows but zero delivered     -> 'failed_delivery_base_fee_only',
 *    final cost capped at the base scan fee
 *  - normal delivery                 -> 'delivered_charged'
 *  - the honest classification + zero-delivery diagnostic reach the ledger
 *  - no fabricated cost: final charge never exceeds the reservation
 *
 * Run: php tests/test-ves-usage-settlement-zero-delivery.php
 */

require __DIR__ . '/bootstrap-wp-shims.php';

if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return trim((string) $s); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($n, $decimals = 0) { return number_format((float) $n, (int) $decimals); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 3; } }

// Ledger stub: records every settle call; reserve cost is 1.0 credit.
final class VES_Usage_Billing {
    public static $settled = [];
    public static function get_cost($type) { return 1.0; }
    public static function settle_reserved_usage($usage_key, $final_cost, $message = '', $context = []) {
        self::$settled[] = ['usage_key' => $usage_key, 'final_cost' => (float) $final_cost, 'message' => (string) $message, 'context' => $context];
        return ['settled' => true, 'final_cost' => (float) $final_cost];
    }
    public static function void_reserved_usage($usage_key, $reason = '') { return true; }
}

$root = dirname(__DIR__);
require_once $root . '/includes/analysis.php';
require_once $root . '/includes/class-ves-ajax-controller.php';

$state = ['total' => 0, 'pass' => 0, 'fail' => []];

$ctx_method = new ReflectionMethod('VES_Ajax_Controller', 'delivery_usage_context');
$ctx_method->setAccessible(true);
$settle_method = new ReflectionMethod('VES_Ajax_Controller', 'settle_standard_usage_by_delivery');
$settle_method->setAccessible(true);

$request = ['limit' => 20];

// ── 1. Provider succeeded, dataset empty (zero raw, zero delivered) ─────────
$formatted = ['items' => [], 'all_items_count' => 0, 'raw_provider_returned_count' => 0, 'run_id' => 'RUN-EMPTY', 'status' => 'succeeded', 'provider_cost_usd' => 0.0];
$ctx = $ctx_method->invoke(null, $formatted, 'tiktok', $request, 'apidojo/tiktok-scraper');
ves_test_ok('zero-delivery classified not_chargeable_zero_delivery', ($ctx['settlement_classification'] ?? '') === 'not_chargeable_zero_delivery', $state);
ves_test_ok('zero-delivery final cost is 0.0', (float) $ctx['credits_charged_final'] === 0.0, $state);
ves_test_ok('zero-delivery refunds the full reservation', (float) $ctx['credits_refunded_or_voided'] === (float) $ctx['credits_reserved'], $state);

// ── 2. Provider returned rows but nothing usable was delivered ──────────────
$formatted = ['items' => [], 'all_items_count' => 0, 'raw_provider_returned_count' => 14, 'run_id' => 'RUN-FILTERED', 'status' => 'succeeded', 'provider_cost_usd' => 0.05];
$ctx = $ctx_method->invoke(null, $formatted, 'tiktok', $request, 'apidojo/tiktok-scraper');
ves_test_ok('filtered-to-zero classified failed_delivery_base_fee_only', ($ctx['settlement_classification'] ?? '') === 'failed_delivery_base_fee_only', $state);
ves_test_ok('filtered-to-zero charges at most the base scan fee', (float) $ctx['credits_charged_final'] <= 0.25 + 0.0001, $state);
ves_test_ok('filtered-to-zero never charges the full reservation', (float) $ctx['credits_charged_final'] < (float) $ctx['credits_reserved'], $state);

// ── 3. Normal delivery stays delivered_charged ──────────────────────────────
$items = []; for ($i = 0; $i < 20; $i++) { $items[] = ['id' => $i]; }
$formatted = ['items' => $items, 'all_items_count' => 20, 'raw_provider_returned_count' => 20, 'run_id' => 'RUN-OK', 'status' => 'succeeded', 'provider_cost_usd' => 0.05];
$ctx = $ctx_method->invoke(null, $formatted, 'tiktok', $request, 'apidojo/tiktok-scraper');
ves_test_ok('full delivery classified delivered_charged', ($ctx['settlement_classification'] ?? '') === 'delivered_charged', $state);
ves_test_ok('final charge never exceeds the reservation', (float) $ctx['credits_charged_final'] <= (float) $ctx['credits_reserved'], $state);

// ── 4. End-to-end settle: classification + honest message reach the ledger ──
VES_Usage_Billing::$settled = [];
VES_Admin::$diagnostics = [];
$formatted = ['items' => [], 'all_items_count' => 0, 'raw_provider_returned_count' => 0, 'run_id' => 'RUN-EMPTY2', 'status' => 'succeeded'];
$settle_method->invoke(null, 'usage-key-1', 'req-1', 'usage_billing', $formatted, 'tiktok', $request, 'apidojo/tiktok-scraper');
$entry = VES_Usage_Billing::$settled[0] ?? [];
ves_test_ok('settle was invoked once', count(VES_Usage_Billing::$settled) === 1, $state);
ves_test_ok('ledger receives final cost 0.0 for zero delivery', (float) ($entry['final_cost'] ?? -1) === 0.0, $state);
ves_test_ok('ledger context carries the settlement classification', (($entry['context']['settlement_classification'] ?? '') === 'not_chargeable_zero_delivery'), $state);
ves_test_ok('ledger message is honest about zero delivery (not "confirmado")', strpos((string) ($entry['message'] ?? ''), 'sin resultados entregables') !== false, $state);
$diag = json_encode(VES_Admin::$diagnostics);
ves_test_ok('zero-delivery settlement recorded as diagnostic', strpos($diag, 'usage_settlement_zero_delivery') !== false, $state);

// ── 5. Normal delivery keeps the original confirmation message ──────────────
VES_Usage_Billing::$settled = [];
$formatted = ['items' => $items, 'all_items_count' => 20, 'raw_provider_returned_count' => 20, 'run_id' => 'RUN-OK2', 'status' => 'succeeded'];
$settle_method->invoke(null, 'usage-key-2', 'req-2', 'usage_billing', $formatted, 'tiktok', $request, 'apidojo/tiktok-scraper');
$entry = VES_Usage_Billing::$settled[0] ?? [];
ves_test_ok('delivered runs keep the confirmation message', strpos((string) ($entry['message'] ?? ''), 'confirmado por resultados entregados') !== false, $state);

ves_test_finish('v0.1 RC zero-delivery settlement', $state);
