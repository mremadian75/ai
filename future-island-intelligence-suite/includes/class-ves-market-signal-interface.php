<?php
if (!defined('ABSPATH')) { exit; }

/**
 * MarketSignal React workspace interface.
 *
 * Production frontend surface for the canonical SaaS loop:
 * Workspace -> Source -> Run -> Signal -> Insight -> Brief -> Draft -> Usage.
 * Server state is stored in WordPress and generation is handled by the backend
 * provider chain: ChatGPT/OpenAI and, for research runs, optional Apify intake.
 */
final class VES_Market_Signal_Interface {
    const SHORTCODE = 'ves_market_signal';
    const SCRIPT_HANDLE = 'ves-market-signal-app';
    const OPTION_PAGE_ID = 'ves_market_signal_frontend_page_id';

    public static function register() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render_shortcode']);
        add_shortcode('ves_market_signal_debug', [__CLASS__, 'render_debug_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'register_admin_page']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'register_assets']);
            add_action('admin_init', [__CLASS__, 'maybe_repair_frontend_page']);
            add_action('admin_post_ves_market_signal_repair_page', [__CLASS__, 'handle_repair_page']);
        }
    }

    public static function register_assets() {
        wp_register_script(
            self::SCRIPT_HANDLE,
            VES_PLUGIN_URL . 'assets/js/ves-market-signal.js',
            ['wp-element'],
            VES_PLUGIN_VERSION,
            true
        );
    }

    public static function enqueue_assets() {
        self::register_assets();
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.VES_MARKET_SIGNAL = window.VES_MARKET_SIGNAL || ' . wp_json_encode([
                'version' => VES_PLUGIN_VERSION,
                'mode'    => 'wordpress-chatgpt-apify-production',
                'browserFallback' => false,
                'isLoggedIn' => is_user_logged_in(),
                'loginUrl' => wp_login_url(self::get_frontend_page_url() ?: home_url('/')),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'restUrl' => esc_url_raw(rest_url('ves/v1/market-signal')),
                'stateUrl' => esc_url_raw(rest_url('ves/v1/market-signal/state')),
                'generateUrl' => esc_url_raw(rest_url('ves/v1/market-signal/generate')),
                'healthUrl' => esc_url_raw(rest_url('ves/v1/market-signal/health')),
                'exportUrl' => esc_url_raw(rest_url('ves/v1/market-signal/export')),
                'eventsUrl' => esc_url_raw(rest_url('ves/v1/market-signal/events')),
                'nonce' => wp_create_nonce('wp_rest'),
                'userId' => get_current_user_id(),
                'pluginUrl' => VES_PLUGIN_URL,
            ]) . ';',
            'before'
        );
    }

    public static function render_shortcode($atts = []) {
        $atts = shortcode_atts([
            'shadow' => '1',
            'height' => '760px',
            'require_auth' => '1',
            'title' => 'Social Scraper',
            'subtitle' => 'Intelligence Suite',
        ], $atts, self::SHORTCODE);

        // v0.9.24.73: [ves_market_signal] now renders the full original
        // Intelligence Suite surface instead of the reduced React dashboard.
        // This preserves Social Media, LinkedIn, SEO/SEM/AEO, Trend Finder,
        // Brand Deep Audit, Creative Intelligence, Google Intelligence,
        // Ads Intelligence and Memory pages, while keeping the newer light,
        // embed-safe styling.
        if (class_exists('VES_Shortcode')) {
            return VES_Shortcode::render([
                'title' => sanitize_text_field((string) $atts['title']),
                'subtitle' => sanitize_text_field((string) $atts['subtitle']),
                'height' => sanitize_text_field((string) $atts['height']),
            ]);
        }

        $requires_auth = $atts['require_auth'] !== '0';
        if ($requires_auth && !is_user_logged_in() && class_exists('VES_Auth')) {
            return VES_Auth::render_gate();
        }
        if (class_exists('VES_Config') && VES_Config::require_login() && !is_user_logged_in() && class_exists('VES_Auth')) {
            return VES_Auth::render_gate();
        }
        if (is_user_logged_in() && class_exists('VES_Usage_Billing') && !VES_Usage_Billing::can_access_panel(get_current_user_id())) {
            return VES_Usage_Billing::render_access_denied();
        }

        self::enqueue_assets();

        $height = sanitize_text_field((string) $atts['height']);
        if ($height === '') {
            $height = '760px';
        }

        return sprintf(
            '<div class="ves-market-signal-root" data-ves-market-signal-root data-ves-version="%s" data-shadow="%s" style="height:%s;min-height:560px;width:100%%;max-width:1180px;margin:0 auto;display:block;background:transparent;overflow:visible;"><div class="ves-market-signal-loading" style="padding:22px;color:#475569;background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;font-family:Inter,system-ui,sans-serif">Loading MarketSignal workspace...</div><noscript><div style="padding:22px;color:#475569;background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;font-family:Inter,system-ui,sans-serif">MarketSignal needs JavaScript enabled.</div></noscript></div>',
            esc_attr(VES_PLUGIN_VERSION),
            esc_attr($atts['shadow'] === '1' ? '1' : '0'),
            esc_attr($height)
        );
    }


    public static function maybe_repair_frontend_page() {
        if (!current_user_can('manage_options')) { return; }
        self::ensure_frontend_page();
    }

    public static function handle_repair_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Permission denied.', 'ves')); }
        check_admin_referer('ves_market_signal_repair_page');
        delete_option(self::OPTION_PAGE_ID);
        $page_id = self::ensure_frontend_page();
        $redirect = add_query_arg([
            'page' => 'ves-market-signal-interface',
            'repaired' => $page_id ? '1' : '0',
        ], admin_url('options-general.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function render_debug_shortcode() {
        if (!current_user_can('manage_options')) {
            return '';
        }
        $health = class_exists('VES_Market_Signal_Commercial') ? VES_Market_Signal_Commercial::health_report() : [];
        $suite_pages = [
            'social' => 'Social Media',
            'linkedin' => 'LinkedIn',
            'seo' => 'SEO/SEM/AEO',
            'trend' => 'Trend Finder',
            'brand-audit' => 'Brand Deep Audit',
            'creative' => 'Creative Intelligence',
            'google' => 'Google Intelligence',
            'ads' => 'Ads Intelligence',
            'memory' => 'Memory',
            'knowledge' => 'Knowledge base',
            'usage' => 'Usage',
            'brief-library' => 'Brief Library',
        ];
        $template_checks = [];
        foreach (['_scraper-form.php','_linkedin-form.php','_trend-finder-form.php','_brand-audit-form.php','_google-intel-form.php','shortcode.php'] as $template) {
            $template_checks[$template] = file_exists(VES_PLUGIN_DIR . 'templates/' . $template) ? 'ok' : 'missing';
        }
        $checks = [
            'plugin_version' => defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '',
            'shortcode_registered' => shortcode_exists(self::SHORTCODE) ? 'yes' : 'no',
            'frontend_page_url' => self::get_frontend_page_url(),
            'suite_pages_expected' => $suite_pages,
            'templates' => $template_checks,
            'admin_settings_locations' => [
                'primary_api_and_actor_settings' => admin_url('options-general.php?page=ves-social-scraper'),
                'commercial_provider_settings' => admin_url('options-general.php?page=ves-market-signal-commercial'),
                'interface_page_repair' => admin_url('options-general.php?page=ves-market-signal-interface'),
                'memory_knowledge_admin' => admin_url('tools.php?page=ves-memory-knowledge'),
                'ops_diagnostics' => admin_url('tools.php?page=ves-operations'),
            ],
            'rest_state' => rest_url('ves/v1/market-signal/state'),
            'rest_generate' => rest_url('ves/v1/market-signal/generate'),
            'is_logged_in' => is_user_logged_in() ? 'yes' : 'no',
            'user_id' => get_current_user_id(),
            'commercial_health' => $health,
        ];
        return '<pre style="white-space:pre-wrap;background:#111;color:#eee;padding:16px;border-radius:8px;overflow:auto">' . esc_html(wp_json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }

    public static function ensure_frontend_page() {
        if (!function_exists('wp_insert_post')) { return 0; }
        $existing = (int) get_option(self::OPTION_PAGE_ID, 0);
        $default_content = '[ves_market_signal height="760px"]';
        if ($existing > 0) {
            $post = get_post($existing);
            if ($post && $post->post_status !== 'trash') {
                if (strpos((string) $post->post_content, 'ves_market_signal') !== false && $post->post_content !== $default_content) {
                    wp_update_post(['ID' => $existing, 'post_content' => $default_content]);
                }
                return $existing;
            }
        }
        $page = get_page_by_path('market-signal-workspace');
        if ($page && $page->post_status !== 'trash') {
            if (strpos((string) $page->post_content, 'ves_market_signal') !== false && $page->post_content !== $default_content) {
                wp_update_post(['ID' => (int) $page->ID, 'post_content' => $default_content]);
            }
            update_option(self::OPTION_PAGE_ID, (int) $page->ID, false);
            return (int) $page->ID;
        }
        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'MarketSignal Workspace',
            'post_name' => 'market-signal-workspace',
            'post_content' => $default_content,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);
        if (is_wp_error($page_id)) { return 0; }
        update_option(self::OPTION_PAGE_ID, (int) $page_id, false);
        return (int) $page_id;
    }

    public static function get_frontend_page_url() {
        $page_id = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($page_id <= 0) { return ''; }
        $url = get_permalink($page_id);
        return $url ? $url : '';
    }

    public static function register_admin_page() {
        add_submenu_page(
            'options-general.php',
            __('MarketSignal Interface', 'ves'),
            __('MarketSignal Interface', 'ves'),
            'manage_options',
            'ves-market-signal-interface',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ves'));
        }
        self::enqueue_assets();
        $page_url = self::get_frontend_page_url();
        echo '<div class="wrap" style="max-width:none;">';
        echo '<h1>MarketSignal Interface</h1>';
        echo '<p>Frontend shortcode: <code>[ves_market_signal height="760px"]</code></p>';
        echo '<p>Debug shortcode for admins: <code>[ves_market_signal_debug]</code></p>';
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ves_market_signal_repair_page'), 'ves_market_signal_repair_page')) . '">Repair / recreate frontend page</a></p>';
        if ($page_url) { echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url($page_url) . '">Open frontend workspace page</a></p>'; }
        echo '</div>';
        echo '<div class="wrap" style="margin:0;padding:0;max-width:none;">';
        echo do_shortcode('[ves_market_signal height="760px"]');
        echo '</div>';
    }
}
