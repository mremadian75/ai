<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Shortcode — unified app entry points.
 *
 * v1.1.0: every shortcode renders the SAME canonical app shell, differing only
 * by initial route. Legacy shortcodes are aliases/deep-links, not separate
 * product surfaces. `[ves_intelligence_suite]` is canonical; it accepts
 * page="..." and section="...". A single-shell guard prevents two full shells
 * from rendering on one page; use `[fiis_section]` for composition instead.
 */
final class VES_Shortcode {

    /** Has a full app shell already rendered this request? */
    private static $rendered_full = false;

    /** Legacy shortcode => forced route into the unified app. */
    private static $aliases = [
        'ves_dashboard'          => 'dashboard',
        'ves_social'             => 'social',
        'ves_linkedin'           => 'linkedin',
        'ves_seo'                => 'seo',
        'ves_brand_audit'        => 'brand-audit',
        'ves_ads'                => 'ads',
        'ves_creative'           => 'creative',
        'ves_google_intel'       => 'google',
        'ves_trend'              => 'trend',
        'ves_memory'             => 'memory',
        'vietnam_social_scraper' => 'social',
    ];

    public static function register() {
        // Canonical app shortcode (route from `page` attr; default dashboard).
        add_shortcode('ves_intelligence_suite', [__CLASS__, 'render']);

        // Legacy aliases — same shell, forced initial route.
        foreach (self::$aliases as $tag => $route) {
            add_shortcode($tag, function ($atts) use ($route, $tag) {
                return self::render_app(is_array($atts) ? $atts : [], $route, $tag);
            });
        }

        // Section mode — render a single composable section, no shell.
        add_shortcode('fiis_section', [__CLASS__, 'render_section']);
    }

    /** Canonical renderer entry (also the back-compat `render()`). */
    public static function render($atts = []) {
        return self::render_app(is_array($atts) ? $atts : [], null, 'ves_intelligence_suite');
    }

    /**
     * Should an anonymous visitor be shown the gate (lead form / login) instead of
     * the app shell? Pure decision so it is unit-testable.
     *
     * Public lead-gen funnel: if the lead gate is active, anonymous users are ALWAYS
     * gated (so they complete the lead form first) regardless of the require_login
     * setting; otherwise we fall back to the legacy require_login behaviour.
     */
    public static function should_show_gate($logged_in, $lead_gate_active, $require_login): bool {
        if ($logged_in) { return false; }
        return (bool) $lead_gate_active || (bool) $require_login;
    }

    /**
     * Render the unified app shell with an initial route.
     *
     * @param array       $raw_atts shortcode attributes
     * @param string|null $forced   route forced by a legacy alias (authoritative)
     * @param string      $tag      originating shortcode tag
     */
    public static function render_app(array $raw_atts, ?string $forced = null, string $tag = 'ves_intelligence_suite'): string {
        $atts = shortcode_atts([
            'title'    => 'Future Island',
            'subtitle' => '',
            'debug'    => '0',
            'height'   => '820px',
            'page'     => '',
            'section'  => '',
        ], $raw_atts, $tag);

        // section="" on the canonical shortcode → section mode (no shell).
        if ($atts['section'] !== '') {
            return self::render_section(['type' => $atts['section']]);
        }

        // Resolve the initial route: alias force wins, else `page` attr.
        $route = '';
        if ($forced !== null && $forced !== '') {
            $route = $forced;
        } elseif ($atts['page'] !== '') {
            $route = (string) $atts['page'];
        }
        if ($route !== '' && class_exists('VES_App_Router')) {
            $route = VES_App_Router::normalize($route);
        }
        // Empty/invalid route → canonical default (template/JS choose dashboard).
        $ves_force_initial_page = $route !== '' ? $route : null;

        // Access gates. Public lead-gen: when the lead gate is active, anonymous
        // visitors must complete the lead form before the app shell loads —
        // independent of the require_login setting (which defaults to false and
        // would otherwise let anonymous users into the shell, where every run is
        // rejected for not being logged in).
        if (class_exists('VES_Auth')
            && self::should_show_gate(is_user_logged_in(), class_exists('VES_Lead_Gate'), VES_Config::require_login())) {
            return VES_Auth::render_gate();
        }
        if (is_user_logged_in() && class_exists('VES_Usage_Billing') && !VES_Usage_Billing::can_access_panel(get_current_user_id())) {
            return VES_Usage_Billing::render_access_denied();
        }

        // Single-shell guard — one app per page. Further full shells become a
        // lightweight deep-link instead of a second, conflicting shell.
        if (self::$rendered_full) {
            $target = $route !== '' ? $route : 'dashboard';
            return '<div class="fiis-app-alias-note"><a class="ves-btn ves-btn-secondary" href="?fiis_page='
                . esc_attr($target) . '">' . esc_html__('Open', 'ves') . ' '
                . esc_html(ucfirst(str_replace('-', ' ', $target))) . '</a></div>';
        }

        $debug     = !empty($atts['debug']) && $atts['debug'] !== '0';
        $widget_id = 'ves-' . wp_generate_password(8, false, false);
        $ajax_url  = admin_url('admin-ajax.php');
        $nonce     = wp_create_nonce('ves_nonce');

        VES_Assets::enqueue($debug);

        // Cross-link URLs no longer used by the unified shell; kept empty so any
        // residual template reference degrades safely.
        $shell_mode    = 'app';
        $dashboard_url = '';
        $scrapers_url  = '';

        self::$rendered_full = true;

        ob_start();
        include VES_PLUGIN_DIR . 'templates/shortcode.php';
        $output = (string) ob_get_clean();

        // Lead-gen demo CTA: when a free (non-admin) user has spent their 10 free
        // requests, surface a "book a demo" banner above the panel. Server-side,
        // additive and fully guarded — no effect for admins or users with credits.
        if (class_exists('VES_Lead_Gate') && is_user_logged_in()) {
            $uid = get_current_user_id();
            if (VES_Lead_Gate::should_show_demo($uid)) {
                // Out of credits → show the "book a demo" CTA.
                $output = VES_Lead_Gate::demo_cta_html($uid) . $output;
            } elseif (VES_Lead_Gate::should_show_requests_status($uid)) {
                // Still has free requests → show the "N of 10" counter.
                $output = VES_Lead_Gate::requests_status_html($uid) . $output;
            }
        }
        return $output;
    }

    /**
     * Section mode: render ONE composable section without the app shell.
     * Static sections only (no run forms) so multiple sections can coexist on a
     * composed page without duplicate shell chrome.
     */
    public static function render_section($atts = []): string {
        $atts = shortcode_atts(['type' => '', 'title' => ''], is_array($atts) ? $atts : [], 'fiis_section');
        $type = strtolower(str_replace('_', '-', trim((string) $atts['type'])));
        VES_Assets::enqueue(false);

        $safe    = preg_replace('/[^a-z0-9-]/', '', $type);
        $partial = VES_PLUGIN_DIR . 'templates/sections/' . $safe . '.php';

        ob_start();
        echo '<div class="ves-wrap fiis-app fiis-section ves-light-suite">';
        if ($safe !== '' && file_exists($partial)) {
            include $partial;
        } else {
            echo '<div class="ves-empty-state">'
                . esc_html__('Sección no reconocida. Tipos disponibles: tool-shortcuts, usage-summary, recent-activity.', 'ves')
                . '</div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    // ── Legacy URL resolvers (kept for back-compat; no longer used by shell) ──
    public static function resolve_dashboard_url() { return ''; }
    public static function resolve_scrapers_url() { return ''; }
}
