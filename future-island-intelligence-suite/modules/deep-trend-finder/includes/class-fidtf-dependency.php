<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Dependency {
    /**
     * In the unified Intelligence Suite the Core is always co-loaded.
     */
    public static function mother_active(): bool {
        return true;
    }

    public static function missing_message(): string {
        return __('Deep Trend Finder requires the Intelligence Suite core to be active.', 'future-island-intelligence-suite');
    }

    /** Never shown — Core is always present in the unified suite. */
    public static function admin_notice(): void {}

    public static function can_use_frontend(): bool {
        if (class_exists('VES_Config') && method_exists('VES_Config', 'require_login') && VES_Config::require_login() && !is_user_logged_in()) {
            return false;
        }
        if (is_user_logged_in() && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'can_access_panel')) {
            return (bool) VES_Usage_Billing::can_access_panel(get_current_user_id());
        }
        return true;
    }
}
