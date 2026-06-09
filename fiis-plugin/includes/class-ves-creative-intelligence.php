<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Creative_Intelligence {
    private static $booted = false;
    private static $plugin = null;

    public static function module_dir() { return trailingslashit(VES_PLUGIN_DIR . 'modules/creative-intelligence'); }
    public static function module_url() { return trailingslashit(VES_PLUGIN_URL . 'modules/creative-intelligence'); }
    public static function available() { return version_compare(PHP_VERSION, '8.1', '>='); }

    public static function register() {
        if (self::$booted) { return; }
        self::$booted = true;
        if (!self::available()) { return; }
        self::bootstrap_fici();
        self::register_core_bridge_hooks();
    }

    public static function activate() {
        if (!self::available()) { return; }
        self::bootstrap_fici();
        if (class_exists('FICI_Activator')) { FICI_Activator::activate(); }
    }

    public static function ensure_setup() {
        if (!self::available()) { return; }
        self::bootstrap_fici();
        if (class_exists('FICI_Activator')) { FICI_Activator::maybe_upgrade(); }
    }

    private static function bootstrap_fici() {
        if (!defined('FICI_VERSION')) { define('FICI_VERSION', '0.1.3-ves.1'); }
        if (!defined('FICI_PLUGIN_FILE')) { define('FICI_PLUGIN_FILE', VES_PLUGIN_FILE); }
        if (!defined('FICI_PLUGIN_DIR')) { define('FICI_PLUGIN_DIR', self::module_dir()); }
        if (!defined('FICI_PLUGIN_URL')) { define('FICI_PLUGIN_URL', self::module_url()); }
        if (!defined('FICI_REST_NAMESPACE')) { define('FICI_REST_NAMESPACE', 'fici/v1'); }
        if (!defined('FICI_TEXT_DOMAIN')) { define('FICI_TEXT_DOMAIN', 'future-island-creative-intelligence'); }

        if (!class_exists('FICI_Plugin')) {
            $files = [
                'includes/class-fici-activator.php',
                'includes/class-fici-deactivator.php',
                'includes/admin/class-fici-settings.php',
                'includes/security/class-fici-permissions.php',
                'includes/security/class-fici-rate-limiter.php',
                'includes/integrations/class-fici-core-bridge.php',
                'includes/services/class-fici-error-mapper.php',
                'includes/services/class-fici-file-service.php',
                'includes/services/class-fici-asset-service.php',
                'includes/services/class-fici-result-normalizer.php',
                'includes/ai/interface-fici-ai-provider.php',
                'includes/ai/class-fici-ai-schema-builder.php',
                'includes/ai/class-fici-openai-provider.php',
                'includes/ai/class-fici-ai-provider-registry.php',
                'includes/services/class-fici-analysis-service.php',
                'includes/rest/class-fici-rest-analyze-controller.php',
                'includes/class-fici-plugin.php',
            ];
            foreach ($files as $file) {
                $path = FICI_PLUGIN_DIR . $file;
                if (file_exists($path)) { require_once $path; }
            }
        }

        if (class_exists('FICI_Plugin') && null === self::$plugin) {
            self::$plugin = new FICI_Plugin();
            self::$plugin->init();
        }
    }

    private static function register_core_bridge_hooks() {
        add_filter('fici_core_workspace_id', [__CLASS__, 'workspace_id'], 10, 2);
        add_filter('fici_core_workspace_context', [__CLASS__, 'workspace_context'], 10, 2);
        add_filter('fici_core_user_can_use_addon', [__CLASS__, 'user_can_use_addon'], 10, 3);
        add_filter('fici_core_reserve_credits_payload', [__CLASS__, 'reserve_payload'], 10, 1);
        add_action('fici_core_reserve_credits', [__CLASS__, 'reserve_credits'], 10, 1);
        add_action('fici_core_finalize_usage', [__CLASS__, 'finalize_usage'], 10, 2);
        add_action('fici_core_refund_usage', [__CLASS__, 'refund_usage'], 10, 2);
        add_filter('fici_core_create_memory_record', [__CLASS__, 'create_memory_record'], 10, 3);
        add_filter('fici_estimated_credit_cost', [__CLASS__, 'estimated_credit_cost'], 10, 3);
    }

    public static function workspace_id($workspace_id, $user_id) { return max(0, (int) $user_id); }

    public static function estimated_credit_cost($estimated_cost, $operation, $context) {
        if (class_exists('VES_Config') && method_exists('VES_Config', 'cost_creative_asset_analysis')) {
            return (int) max(1, round((float) VES_Config::cost_creative_asset_analysis()));
        }
        return max(1, (int) $estimated_cost);
    }

    public static function workspace_context($context, $user_id) {
        $user = get_userdata((int) $user_id);
        $balance = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_balance((int) $user_id) : 0;
        return [
            'workspace_id' => max(0, (int) $user_id),
            'workspace_name' => $user instanceof WP_User ? ($user->display_name ?: $user->user_login) : __('SaaS workspace', FICI_TEXT_DOMAIN),
            'mode' => 'ves_core_connected',
            'core_available' => true,
            'credits_label' => class_exists('VES_Usage_Billing') ? sprintf('%.0f credits available', (float) $balance) : __('Core connected', FICI_TEXT_DOMAIN),
        ];
    }

    public static function user_can_use_addon($allowed, $user_id, $workspace_id) {
        if (current_user_can('manage_options')) { return true; }
        if (class_exists('VES_Usage_Billing')) { return VES_Usage_Billing::can_access_panel((int) $user_id); }
        return is_user_logged_in();
    }

    public static function reserve_payload($reservation) {
        if (!is_array($reservation)) { return $reservation; }
        if (empty($reservation['id'])) { $reservation['id'] = 'fici_' . wp_generate_uuid4(); }
        return $reservation;
    }

    public static function reserve_credits($reservation) {
        if (!is_array($reservation) || !class_exists('VES_Usage_Billing')) { return; }
        $user_id = (int) ($reservation['user_id'] ?? 0);
        $usage_key = sanitize_text_field((string) ($reservation['id'] ?? ''));
        if ($user_id <= 0 || $usage_key === '') { return; }
        VES_Usage_Billing::reserve_usage($user_id, 'creative_asset_analysis', $usage_key, 'Reserva Creative Intelligence', [
            'usage_key' => $usage_key,
            'target_type' => 'creative_asset_analysis',
            'target_id' => $usage_key,
            'estimated_cost' => (float) ($reservation['estimated_cost'] ?? 0),
            'context' => is_array($reservation['context'] ?? null) ? $reservation['context'] : [],
        ]);
    }

    public static function finalize_usage($reservation_id, $actual_usage) {
        if (class_exists('VES_Usage_Billing')) { VES_Usage_Billing::post_reserved_usage((string) $reservation_id, 'Creative Intelligence confirmado'); }
    }

    public static function refund_usage($reservation_id, $reason) {
        if (class_exists('VES_Usage_Billing')) { VES_Usage_Billing::void_reserved_usage((string) $reservation_id, (string) $reason); }
    }

    public static function create_memory_record($record_id, $workspace_id, $summary) {
        if (class_exists('VES_Temp_Memory') && method_exists('VES_Temp_Memory', 'save_creative_intelligence_report')) {
            return (int) VES_Temp_Memory::save_creative_intelligence_report(is_array($summary) ? $summary : []);
        }
        return 0;
    }

    public static function render_panel() {
        if (!self::available()) {
            return '<div class="ves-item-panel"><div class="ves-item-panel-title">Creative Intelligence unavailable</div><p class="ves-hint">This module requires PHP 8.1 or newer.</p></div>';
        }
        self::bootstrap_fici();
        if (shortcode_exists('fici_creative_analyzer')) { return do_shortcode('[fici_creative_analyzer]'); }
        return '<div class="ves-item-panel"><div class="ves-item-panel-title">Creative Intelligence unavailable</div><p class="ves-hint">The embedded Creative Intelligence module could not be initialized.</p></div>';
    }
}
