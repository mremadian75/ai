<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Module_Registry — single source of truth for the internal SaaS modules
 * and the unified "Future Island" admin navigation.
 *
 * v0.4.0: the major features become semi-independent modules inside this one
 * plugin (Trend Finder pattern, standardized). The registry:
 *   - registers modules and rejects duplicate ids,
 *   - owns ONE top-level Future Island menu (no admin-menu dump),
 *   - registers real pages for module-owned surfaces and link entries for
 *     existing surfaces (never a duplicate page for the same feature),
 *   - hides the now-redundant Tools menu items late (the pages themselves stay
 *     registered and reachable, so old URLs and tests keep working),
 *   - exposes nav()/modules() so tests can assert "exactly one Trend Finder".
 */
final class FI_Module_Registry {

    const MENU_SLUG = 'future-island';

    /** @var array<string,FI_Abstract_Module> */
    private static $modules = [];
    private static $booted = false;

    /** Register a module instance. Duplicate ids are rejected (WP_Error). */
    public static function register(FI_Abstract_Module $module) {
        $id = sanitize_key($module->id());
        if ($id === '') {
            return new WP_Error('fi_module_invalid_id', 'Module id cannot be empty.');
        }
        if (isset(self::$modules[$id])) {
            return new WP_Error('fi_module_duplicate', "Module id '{$id}' is already registered.");
        }
        self::$modules[$id] = $module;
        return true;
    }

    /** @return array<string,FI_Abstract_Module> in registration (nav) order. */
    public static function modules(): array { return self::$modules; }

    public static function get(string $id): ?FI_Abstract_Module {
        return self::$modules[sanitize_key($id)] ?? null;
    }

    /** Reset (tests only). */
    public static function reset(): void { self::$modules = []; self::$booted = false; }

    /** Navigation rows in order: [id, label, type, target, status_state]. */
    public static function nav(): array {
        $out = [];
        foreach (self::$modules as $module) {
            $nav = $module->nav();
            $status = $module->status();
            $out[] = [
                'id'     => $module->id(),
                'label'  => $module->label(),
                'type'   => (string) ($nav['type'] ?? 'page'),
                'target' => (string) ($nav['type'] ?? 'page') === 'link' ? (string) ($nav['url'] ?? '') : (string) ($nav['slug'] ?? ''),
                'state'  => (string) ($status['state'] ?? 'available'),
            ];
        }
        return $out;
    }

    /** Boot: register module hooks + the unified menu. Idempotent. */
    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        foreach (self::$modules as $module) {
            $module->register();
        }
        if (function_exists('add_action')) {
            add_action('admin_menu', [__CLASS__, 'register_menu'], 9);
            add_action('admin_menu', [__CLASS__, 'dedupe_legacy_menu_items'], 999);
            add_action('admin_init', [__CLASS__, 'legacy_tools_redirects']);
        }
    }

    public static function register_menu(): void {
        if (!function_exists('add_menu_page')) { return; }
        add_menu_page(
            'Future Island',
            'Future Island',
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_index'],
            'dashicons-visibility',
            3
        );
        // First submenu mirrors the parent as "Overview" (module index).
        add_submenu_page(self::MENU_SLUG, 'Future Island — Overview', 'Overview', 'manage_options', self::MENU_SLUG, [__CLASS__, 'render_index']);
        foreach (self::$modules as $module) {
            $nav = $module->nav();
            $type = (string) ($nav['type'] ?? 'page');
            if ($type === 'link') {
                $url = (string) ($nav['url'] ?? '');
                if ($url === '') { continue; }
                // WP renders a submenu whose slug is a URL as a plain link —
                // the canonical page stays where it is (no duplicate page).
                add_submenu_page(self::MENU_SLUG, $module->label(), $module->label(), $module->capability(), $url);
                continue;
            }
            $slug = sanitize_key((string) ($nav['slug'] ?? ''));
            if ($slug === '') { continue; }
            add_submenu_page(self::MENU_SLUG, 'Future Island — ' . $module->label(), $module->label(), $module->capability(), $slug, [$module, 'render']);
        }
    }

    /**
     * The FI menu is the product navigation. The legacy Tools entries for the
     * same surfaces are hidden (NOT unregistered: the pages stay reachable so
     * bookmarks/tests keep working) to avoid duplicate navigation.
     */
    public static function dedupe_legacy_menu_items(): void {
        if (!function_exists('remove_submenu_page')) { return; }
        foreach (['fi-intake', 'fi-signal-room', 'fi-brief-workbench', 'fi-draft-workbench'] as $slug) {
            remove_submenu_page('tools.php', $slug);
        }
    }

    /**
     * Old deep links may use tools.php?page=… for surfaces that now live in
     * the Future Island menu. The page registrations still exist under Tools,
     * so those URLs render fine; this hook only catches the inverse case —
     * admin.php?page=… for a Tools-registered slug — and sends it home.
     */
    public static function legacy_tools_redirects(): void {
        if (!function_exists('wp_safe_redirect') || !function_exists('admin_url')) { return; }
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === '') { return; }
        $legacy = ['fi-intake', 'fi-signal-room', 'fi-brief-workbench', 'fi-draft-workbench'];
        $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';
        if ($pagenow === 'admin.php' && in_array($page, $legacy, true)) {
            $args = $_GET;
            unset($args['page']);
            $url = add_query_arg(array_map('sanitize_text_field', wp_unslash($args)), admin_url('tools.php?page=' . $page));
            wp_safe_redirect($url);
            exit;
        }
    }

    /** Module index — an honest registry view, not a metric dashboard. */
    public static function render_index(): void {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return; }
        $e = static function ($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); };
        $u = static function ($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); };
        echo '<div class="wrap ves-wrap fi-signal-room fi-module-index">';
        echo '<header class="fi-room-context">';
        echo '<p class="fi-breadcrumb fiis-sr-eyebrow">' . $e('Future Island · Module registry') . '</p>';
        echo '<h1>' . $e('Future Island workspace') . '</h1>';
        echo '<p class="fi-intake-sub">' . $e('Source → Signal → Insight → Brief → Asset → Memory → Usage. Each module below is a dedicated working surface; unavailable modules say so instead of pretending to run.') . '</p>';
        echo '</header>';
        echo '<div class="fi-module-list">';
        foreach (self::$modules as $module) {
            $meta = $module->describe();
            $nav = $meta['nav'];
            $state = (string) ($meta['status']['state'] ?? 'available');
            $detail = (string) ($meta['status']['detail'] ?? '');
            $target = ($nav['type'] ?? 'page') === 'link'
                ? (string) ($nav['url'] ?? '')
                : 'admin.php?page=' . (string) ($nav['slug'] ?? '');
            $state_label = [
                'available' => 'Available',
                'configuration_needed' => 'Configuration needed',
                'read_only' => 'Read-only',
                'unavailable' => 'Unavailable',
            ][$state] ?? ucfirst($state);
            echo '<section class="fi-module-row is-' . $e($state) . '">';
            echo '<div class="fi-module-row-main">';
            echo '<h2>' . $e($meta['label']) . '</h2>';
            echo '<p>' . $e($meta['description']) . '</p>';
            if ($detail !== '') { echo '<p class="fi-module-detail">' . $e($detail) . '</p>'; }
            echo '</div>';
            echo '<div class="fi-module-row-side">';
            echo '<span class="fi-status-badge fiis-badge-' . $e($state === 'available' ? 'ready' : ($state === 'configuration_needed' ? 'needs_review' : 'candidate')) . '">' . $e($state_label) . '</span>';
            if ($state !== 'unavailable' && $target !== '') {
                echo '<a class="button button-primary" href="' . $u($target) . '">' . $e('Open') . '</a>';
            } else {
                echo '<span class="fi-intake-disabled">' . $e('Not runnable') . '</span>';
            }
            echo '</div>';
            echo '</section>';
        }
        echo '</div></div>';
    }
}
