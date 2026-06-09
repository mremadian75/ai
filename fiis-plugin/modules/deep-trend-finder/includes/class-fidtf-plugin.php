<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Plugin {
    private static $booted = false;

    public static function boot(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [__CLASS__, 'register_shortcode']);
        add_action('init', [__CLASS__, 'maybe_create_frontend_page'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('rest_api_init', ['FIDTF_REST_Controller', 'register_routes']);
        FIDTF_AI_Bridge::register();
        FIDTF_DB::maybe_upgrade();
        FIDTF_Settings::maybe_migrate();

        if (is_admin()) {
            FIDTF_Admin::register();
        }
    }

    public static function activate(): void {
        FIDTF_DB::create_tables();
        FIDTF_Settings::ensure_defaults();
        FIDTF_Settings::maybe_migrate();
        self::maybe_create_frontend_page();
    }

    public static function maybe_create_frontend_page(): int {
        return self::ensure_frontend_page(false);
    }

    public static function recreate_frontend_page(bool $force_update = false): int {
        return self::ensure_frontend_page($force_update);
    }

    private static function ensure_frontend_page(bool $force_update = false): int {
        if (!function_exists('get_option') || !function_exists('update_option') || !function_exists('wp_insert_post')) { return 0; }
        $slug = 'deep-trend-finder';
        update_option('fidtf_page_slug', $slug, false);
        $shortcode = '[future_island_deep_trend_finder]';

        $stored = (int) get_option('fidtf_page_id', 0);
        if ($stored > 0 && function_exists('get_post')) {
            $existing = get_post($stored);
            if ($existing && !empty($existing->ID)) {
                self::flag_missing_shortcode_if_needed($existing);
                if ($force_update && strpos((string) ($existing->post_content ?? ''), $shortcode) === false && function_exists('wp_update_post')) {
                    wp_update_post(['ID' => (int) $existing->ID, 'post_content' => $shortcode]);
                    delete_option('fidtf_page_shortcode_missing');
                }
                return (int) $existing->ID;
            }
        }

        if (function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug);
            if ($page && !empty($page->ID)) {
                update_option('fidtf_page_id', (int) $page->ID, false);
                self::flag_missing_shortcode_if_needed($page);
                if ($force_update && strpos((string) ($page->post_content ?? ''), $shortcode) === false && function_exists('wp_update_post')) {
                    wp_update_post(['ID' => (int) $page->ID, 'post_content' => $shortcode]);
                    delete_option('fidtf_page_shortcode_missing');
                }
                return (int) $page->ID;
            }
        }

        $inserted = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Deep Trend Finder',
            'post_name' => $slug,
            'post_content' => $shortcode,
        ], true);
        if (is_wp_error($inserted)) {
            return 0;
        }
        $page_id = (int) $inserted;
        if ($page_id > 0) {
            update_option('fidtf_page_id', $page_id, false);
            delete_option('fidtf_page_shortcode_missing');
        }
        return $page_id > 0 ? $page_id : 0;
    }

    private static function flag_missing_shortcode_if_needed($page): void {
        $content = (string) ($page->post_content ?? '');
        if (strpos($content, '[future_island_deep_trend_finder]') === false) {
            update_option('fidtf_page_shortcode_missing', 1, false);
        } else {
            delete_option('fidtf_page_shortcode_missing');
        }
    }

    public static function deactivate(): void {
        // No destructive cleanup. Runs/reports are user data and remain available.
    }

    public static function register_shortcode(): void {
        add_shortcode('future_island_deep_trend_finder', [__CLASS__, 'render_shortcode']);
    }

    public static function register_assets(): void {
        wp_register_style('fidtf-frontend', FIDTF_PLUGIN_URL . 'assets/css/fidtf-frontend.css', [], FIDTF_VERSION);
        wp_register_script('fidtf-frontend', FIDTF_PLUGIN_URL . 'assets/js/fidtf-frontend.js', [], FIDTF_VERSION, true);
    }

    public static function enqueue_assets(): void {
        self::register_assets();
        wp_enqueue_style('fidtf-frontend');
        wp_enqueue_script('fidtf-frontend');
        wp_localize_script('fidtf-frontend', 'FIDTF', [
            'restUrl' => esc_url_raw(rest_url('future-island-dtf/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'isAdmin' => current_user_can('manage_options'),
            'deepVideoEnabled' => FIDTF_Settings::deep_video_enabled(),
            'liveDispatchEnabled' => FIDTF_Settings::live_dispatch_enabled(),
            'tiktokLiveEnabled' => FIDTF_Settings::tiktok_live_ready(['tiktok']),
            'preflight' => FIDTF_Settings::live_preflight_status(),
            'moduleInfo' => class_exists('FIDTF_Module_Info') ? FIDTF_Module_Info::get() : [],
            'strings' => [
                'creating' => __('Creating deep trend run...', 'future-island-deep-trend-finder-addon'),
                'dependencyMissing' => FIDTF_Dependency::missing_message(),
            ],
        ]);
    }

    public static function render_shortcode($atts = []): string {
        $atts = shortcode_atts(['debug' => '0'], $atts, 'future_island_deep_trend_finder');
        if (!FIDTF_Dependency::mother_active()) {
            return '<div class="fidtf-notice fidtf-warning">' . esc_html(FIDTF_Dependency::missing_message()) . '</div>';
        }
        if (!FIDTF_Dependency::can_use_frontend()) {
            return '<div class="fidtf-notice fidtf-warning">' . esc_html__('You do not have access to Deep Trend Finder.', 'future-island-deep-trend-finder-addon') . '</div>';
        }
        self::enqueue_assets();
        $settings = FIDTF_Settings::get();
        $debug = !empty($atts['debug']) && $atts['debug'] !== '0';
        ob_start();
        include FIDTF_PLUGIN_DIR . 'templates/shortcode-deep-trend-finder.php';
        return (string) ob_get_clean();
    }
}
