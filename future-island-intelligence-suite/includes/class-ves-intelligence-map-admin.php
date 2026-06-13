<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Intelligence_Map_Admin — optional operator screen + REST endpoint for the
 * read-only relationship graph built by VES_Intelligence_Map.
 *
 * Scope & safety:
 *   - Adds ONE admin page (capability: manage_options) under the existing
 *     "Intelligence Suite" menu; falls back to Tools if that menu is absent.
 *   - Registers a read-only REST route (GET ves/v1/intelligence-map) gated to
 *     manage_options. Viewing the graph is FREE (no credit/usage event).
 *   - Enqueues a small, scoped JS/CSS bundle ONLY on its own page (no global
 *     asset or CSS pollution, no React-bundle changes).
 *   - Does not register shortcodes, routes, CPTs, or tables.
 */
final class VES_Intelligence_Map_Admin {

    const REST_NS    = 'ves/v1';
    const REST_ROUTE = '/intelligence-map';
    const PARENT_SLUG = 'ves-intelligence-suite';
    const PAGE_SLUG   = 'ves-intelligence-map';

    /** @var string captured page hook suffix for scoped enqueue */
    private static $hook = '';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 11);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'maybe_enqueue']);
    }

    // ── Admin menu ────────────────────────────────────────────────────────────

    public static function register_menu() {
        $cb = [__CLASS__, 'render_page'];
        if (class_exists('VES_Access_Control_Admin') && menu_page_url(self::PARENT_SLUG, false) !== '') {
            self::$hook = (string) add_submenu_page(self::PARENT_SLUG, 'Intelligence Map', 'Intelligence Map', 'manage_options', self::PAGE_SLUG, $cb);
        }
        if (self::$hook === '') {
            self::$hook = (string) add_management_page('Intelligence Map', 'Intelligence Map', 'manage_options', self::PAGE_SLUG, $cb);
        }
    }

    public static function maybe_enqueue($hook) {
        if (self::$hook === '' || $hook !== self::$hook) { return; }
        $ver = defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '1.0.0';
        $url = defined('VES_PLUGIN_URL') ? VES_PLUGIN_URL : plugin_dir_url(dirname(__FILE__) . '/../');
        wp_enqueue_style('fiis-intelligence-map', $url . 'assets/css/fiis-intelligence-map.css', [], $ver);
        wp_enqueue_script('fiis-intelligence-map', $url . 'assets/js/fiis-intelligence-map.js', [], $ver, true);
        $default_ws = 0;
        if (class_exists('VES_Memory_Records')) {
            $default_ws = (int) VES_Memory_Records::infer_workspace_id([], get_current_user_id());
        }
        wp_localize_script('fiis-intelligence-map', 'FIIS_IMAP', [
            'rest_url'     => esc_url_raw(rest_url(self::REST_NS . self::REST_ROUTE)),
            'nonce'        => wp_create_nonce('wp_rest'),
            'default_workspace' => $default_ws,
            'node_types'   => VES_Intelligence_Map::NODE_TYPES,
            'edge_types'   => VES_Intelligence_Map::EDGE_TYPES,
        ]);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability', 'ves'));
        }
        echo '<div class="wrap fiis-imap-wrap">';
        echo '<h1>' . esc_html__('Intelligence Map', 'ves') . '</h1>';
        echo '<p class="fiis-imap-intro">' . esc_html__('A read-only relationship map of how runs, signals, insights, opportunities, briefs, and memory connect. Viewing this map is free and never consumes credits.', 'ves') . '</p>';
        echo '<div id="fiis-imap-root" class="fiis-imap" aria-live="polite"></div>';
        echo '</div>';
    }

    // ── REST ──────────────────────────────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::REST_NS, self::REST_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_graph'],
            'permission_callback' => [__CLASS__, 'can_view'],
            'args'                => [
                'workspace_id'   => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
                'run_id'         => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                'days'           => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
                'min_confidence' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
                'max_nodes'      => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
                'types'          => ['required' => false],
                'status'         => ['required' => false],
            ],
        ]);
    }

    /** Operator-only, read-only. */
    public static function can_view() {
        if (!is_user_logged_in()) {
            return new WP_Error('login_required', 'Login required.', ['status' => 401]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Admin access required.', ['status' => 403]);
        }
        return true;
    }

    public static function rest_graph(WP_REST_Request $request) {
        $args = [
            'workspace_id'   => (int) $request->get_param('workspace_id'),
            'user_id'        => get_current_user_id(),
            'run_id'         => (string) $request->get_param('run_id'),
            'days'           => (int) $request->get_param('days'),
            'min_confidence' => (int) $request->get_param('min_confidence'),
            'max_nodes'      => (int) $request->get_param('max_nodes'),
            'types'          => $request->get_param('types'),
            'status'         => $request->get_param('status'),
        ];
        if ($args['workspace_id'] <= 0 && class_exists('VES_Memory_Records')) {
            $args['workspace_id'] = (int) VES_Memory_Records::infer_workspace_id([], get_current_user_id());
        }
        return rest_ensure_response(VES_Intelligence_Map::build_graph($args));
    }
}
