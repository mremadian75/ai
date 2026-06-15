<?php
if (!defined('ABSPATH')) { exit; }

/** Lightweight private-beta readiness snapshot/admin page. */
final class VES_Private_Beta_Readiness_Service {
    public static function register(): void {
        if (function_exists('add_action')) { add_action('admin_menu', [__CLASS__, 'admin_menu'], 60); }
    }

    public static function admin_menu(): void {
        if (!function_exists('add_submenu_page')) { return; }
        add_submenu_page('fi-command-room', 'Private Beta Readiness', 'Beta Readiness', 'manage_options', 'fi-beta-readiness', [__CLASS__, 'render_page']);
    }

    public static function snapshot(): array {
        return [
            'provider_contracts' => class_exists('VES_Provider_Contract_Service') ? count(VES_Provider_Contract_Service::public_contracts()) : 0,
            'provider_settings' => class_exists('VES_Provider_Settings_Service') ? ['environment' => VES_Provider_Settings_Service::environment(), 'providers' => count(VES_Provider_Settings_Service::public_statuses())] : false,
            'callback_auth' => class_exists('VES_Provider_Callback_Auth_Service'),
            'replay_guard' => class_exists('VES_Provider_Replay_Guard'),
            'rate_limits' => class_exists('VES_Provider_Rate_Limit_Service'),
            'run_log_timeline' => class_exists('VES_Run_Log_Service'),
            'secret_redaction' => class_exists('VES_Secret_Redactor'),
            'provider_ingestion_store' => class_exists('VES_Provider_Ingestion_Store'),
            'provider_admin_ui' => class_exists('VES_Provider_Admin_Page'),
            'workspace_onboarding' => class_exists('VES_Workspace_Onboarding_Service'),
            'creative_taxonomy' => class_exists('VES_Creative_Taxonomy_Service') ? VES_Creative_Taxonomy_Service::dimensions() : [],
            'browser_validation_needed' => true,
            'security_notes' => [
                'Providers return rows through authenticated WordPress endpoints.',
                'External workers, including optional n8n examples, must not write directly to canonical tables.',
                'HMAC mode signs timestamp + raw body and uses replay-protected idempotency keys.',
                'Raw provider payloads and tokens are redacted before logs/reports/UI.',
            ],
        ];
    }

    public static function render_page(): void {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { if (function_exists('wp_die')) { wp_die('Insufficient capability'); } return; }
        $s = self::snapshot(); $e = static function($v){ return function_exists('esc_html') ? esc_html((string)$v) : htmlspecialchars((string)$v, ENT_QUOTES); };
        echo '<div class="wrap"><h1>' . $e('Future Island Private Beta Readiness') . '</h1><p>' . $e('Provider-connected beta checks for contract, callback auth, logging, redaction, onboarding and controlled ingestion.') . '</p><table class="widefat striped"><tbody>';
        foreach ($s as $k => $v) { echo '<tr><th>' . $e(str_replace('_',' ', $k)) . '</th><td>' . $e(is_array($v) ? self::stringify($v) : (string) $v) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private static function stringify(array $value): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($json) ? $json : '';
    }
}
