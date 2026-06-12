<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Assets {
    private static $registered = false;
    private static $debug = false;

    public static function register() {
        if (self::$registered) {
            return;
        }

        wp_register_style(
            'ves-frontend',
            VES_PLUGIN_URL . 'assets/css/ves-frontend.css',
            [],
            VES_PLUGIN_VERSION
        );

        // Phase 5C-UX — Signal Report productization (pure, UI-only render helpers
        // for the canonical Source→…→Usage workflow + evidence gate). Loaded before
        // ves-frontend so window.FIIS_Productization exists when the report renders;
        // ves-frontend degrades gracefully if it is ever absent.
        wp_register_script(
            'fiis-signal-productization',
            VES_PLUGIN_URL . 'assets/js/fiis-signal-productization.js',
            [],
            VES_PLUGIN_VERSION,
            true
        );

        wp_register_script(
            'ves-frontend',
            VES_PLUGIN_URL . 'assets/js/ves-frontend.js',
            ['fiis-signal-productization'],
            VES_PLUGIN_VERSION,
            true
        );

        wp_register_style(
            'ves-report-templates',
            VES_PLUGIN_URL . 'assets/css/ves-report-templates.css',
            ['ves-frontend'],
            VES_PLUGIN_VERSION
        );

        // v1.1.0 — single unified app design system. Replaces the previous
        // ux-enhancements + saas-shell layers (deleted). Loaded last; scoped
        // under .ves-wrap/.fiis-app; consumes existing tokens (no 2nd palette).
        // ves-frontend.css is retained only for legacy/module internals.
        wp_register_style(
            'fiis-app',
            VES_PLUGIN_URL . 'assets/css/fiis-app.css',
            ['ves-frontend'],
            VES_PLUGIN_VERSION
        );

        // Additive mobile/touch refinements. Loaded after fiis-app; every rule is
        // scoped under .ves-wrap and gated behind mobile media queries, so desktop
        // and the React markup are unaffected.
        wp_register_style(
            'fiis-mobile',
            VES_PLUGIN_URL . 'assets/css/fiis-mobile.css',
            ['fiis-app'],
            VES_PLUGIN_VERSION
        );

        // UI/UX upgrade — unified Future Island UI system: one token source,
        // editorial components, dark mode, print output and a11y affordances.
        // Loaded LAST so it refines fiis-app/fiis-mobile; strictly additive.
        wp_register_style(
            'fiis-ui-system',
            VES_PLUGIN_URL . 'assets/css/fiis-ui-system.css',
            ['fiis-mobile'],
            VES_PLUGIN_VERSION
        );

        wp_register_script(
            'ves-report-actions',
            VES_PLUGIN_URL . 'assets/js/ves-report-actions.js',
            ['ves-frontend'],
            VES_PLUGIN_VERSION,
            true
        );

        self::$registered = true;
    }

    public static function enqueue($debug = false) {
        self::register();
        self::$debug = self::$debug || (bool) $debug;

        wp_enqueue_style('ves-frontend');
        wp_enqueue_style('ves-report-templates');
        wp_enqueue_style('fiis-app');
        wp_enqueue_style('fiis-mobile');
        wp_enqueue_style('fiis-ui-system');
        wp_enqueue_script('ves-frontend');
        wp_enqueue_script('ves-report-actions');
        wp_add_inline_script('ves-frontend', 'window.VES_DEBUG = ' . (self::$debug ? 'true' : 'false') . '; window.VES_IS_ADMIN = ' . ((function_exists('current_user_can') && current_user_can('manage_options')) ? 'true' : 'false') . ';', 'before');

        // v1.1.0 — localize the unified route map for the single-router JS.
        if (class_exists('VES_App_Router')) {
            wp_add_inline_script('ves-frontend', 'window.FIIS_ROUTES = ' . wp_json_encode(VES_App_Router::js_map()) . ';', 'before');
        }

        // v0.9.24.75 — inject filterable brand strings so the JS UI no longer
        // hardcodes a single tenant's label. Falls back to neutral defaults if
        // the helper is unavailable.
        $brand_payload = [
            'productName'   => class_exists('VES_Branding') ? VES_Branding::product_name()   : 'Intelligence Suite',
            'workspaceName' => class_exists('VES_Branding') ? VES_Branding::workspace_name() : 'Intelligence Workspace',
            'assistantName' => class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant',
            'companyName'   => class_exists('VES_Branding') ? VES_Branding::company_name()   : '',
        ];
        wp_add_inline_script('ves-frontend', 'window.VES_BRAND = ' . wp_json_encode($brand_payload) . ';', 'before');

        // v0.9.26.0 — Phase 1: localize the access map so the JS can render
        // locked / hidden / enabled states for sidebar items, module pages,
        // feature buttons, and provider tabs without making an extra round-trip.
        if (class_exists('VES_Access_Control')) {
            $access_map = VES_Access_Control::build_frontend_access_map(get_current_user_id());
            wp_add_inline_script('ves-frontend', 'window.VES_ACCESS_MAP = ' . wp_json_encode($access_map) . ';', 'before');
        }

        // v0.9.31.1 — localize the credit balance so the top-bar credit pill can render
        // on EVERY module page, not only the "Mi Panel" / Usage page. Previously the pill's
        // value was scraped from the account-summary KPI in the DOM (`.ves-account-kpis`),
        // which is only present on the Usage page — so on Ads/SEO/Trend pages the pill was
        // stuck at its initial "—". Computed defensively: a billing failure degrades to a
        // null/"unavailable" state instead of blanking, and never blocks module results.
        $credit_payload = ['known' => false, 'unlimited' => false, 'balance' => null];
        if (is_user_logged_in() && class_exists('VES_Usage_Billing')) {
            $uid = get_current_user_id();
            try {
                if (method_exists('VES_Usage_Billing', 'is_unlimited_user') && VES_Usage_Billing::is_unlimited_user($uid)) {
                    $credit_payload = ['known' => true, 'unlimited' => true, 'balance' => null];
                } else {
                    $summary = VES_Usage_Billing::get_summary($uid);
                    if (is_array($summary) && isset($summary['balance'])) {
                        $credit_payload = ['known' => true, 'unlimited' => false, 'balance' => (float) $summary['balance']];
                    }
                }
            } catch (Throwable $credit_error) {
                $credit_payload = ['known' => false, 'unlimited' => false, 'balance' => null];
            }
        }
        wp_add_inline_script('ves-frontend', 'window.VES_CREDIT = ' . wp_json_encode($credit_payload) . ';', 'before');
    }
}
