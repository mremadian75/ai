<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$is_logged_in   = is_user_logged_in();
$knowledge_exts = class_exists('VES_Workspace_Knowledge') ? VES_Workspace_Knowledge::accepted_extensions_hint() : '.txt,.md,.csv,.json,.html,.xml';
$current_user   = $is_logged_in ? wp_get_current_user() : null;
$user_initials  = '';
if ($current_user instanceof WP_User) {
    $name  = trim((string) ($current_user->display_name ?: $current_user->user_login));
    $parts = preg_split('/\s+/', $name);
    $user_initials = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1));
    if ($user_initials === '') {
        $user_initials = strtoupper(substr($name, 0, 2));
    }
}
$shell_mode    = isset($shell_mode) ? $shell_mode : 'scrapers';
$dashboard_url = isset($dashboard_url) ? $dashboard_url : '';
$scrapers_url  = isset($scrapers_url) ? $scrapers_url : '';
$is_panel_mode = false; // unified app — no separate panel/scrapers worlds
$default_page  = isset($ves_force_initial_page) && $ves_force_initial_page ? (string) $ves_force_initial_page : 'dashboard';
$ves_production_mvp = defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP;
$ves_mvp_disabled_message = 'Este módulo no está habilitado en el MVP de producción.';
$ves_deep_trend_addon_active = class_exists('VES_Deep_Trend_Addon_Bridge') && VES_Deep_Trend_Addon_Bridge::addon_active();
$ves_deep_trend_addon_url = $ves_deep_trend_addon_active ? VES_Deep_Trend_Addon_Bridge::addon_page_url() : '';

// Locale-aware output language and Semrush market defaults
$output_language  = 'es';
$semrush_country  = 'es';
$semrush_language = 'es';
$_ves_locale = $is_logged_in ? get_user_locale() : get_locale();
if (strpos($_ves_locale, 'fa') !== false) {
    $output_language = 'fa'; $semrush_country = 'ir'; $semrush_language = 'fa';
} elseif (strpos($_ves_locale, 'en_US') !== false) {
    $output_language = 'en'; $semrush_country = 'us'; $semrush_language = 'en';
} elseif (strpos($_ves_locale, 'en_GB') !== false) {
    $output_language = 'en'; $semrush_country = 'gb'; $semrush_language = 'en';
} elseif (strpos($_ves_locale, 'en') !== false) {
    $output_language = 'en'; $semrush_country = 'us'; $semrush_language = 'en';
} elseif (strpos($_ves_locale, 'fr') !== false) {
    $output_language = 'fr'; $semrush_country = 'fr'; $semrush_language = 'fr';
} elseif (strpos($_ves_locale, 'de') !== false) {
    $output_language = 'de'; $semrush_country = 'de'; $semrush_language = 'de';
} elseif (strpos($_ves_locale, 'it') !== false) {
    $output_language = 'it'; $semrush_country = 'it'; $semrush_language = 'it';
} elseif (strpos($_ves_locale, 'pt_BR') !== false) {
    $output_language = 'pt'; $semrush_country = 'br'; $semrush_language = 'pt';
} elseif (strpos($_ves_locale, 'pt') !== false) {
    $output_language = 'pt'; $semrush_country = 'pt'; $semrush_language = 'pt';
}
unset($_ves_locale);
$output_language  = apply_filters('ves_default_output_language',  $output_language);
$semrush_country  = apply_filters('ves_default_semrush_country',  $semrush_country);
$semrush_language = apply_filters('ves_default_semrush_language', $semrush_language);
?>
<a class="ves-skip-link" href="#<?php echo esc_attr($widget_id); ?>-main">Saltar al contenido</a>
<?php
$ves_debug_mode = (current_user_can('manage_options') && isset($_GET['ves_debug']) && sanitize_text_field(wp_unslash($_GET['ves_debug'])) === '1') ? '1' : '0';
?>
<?php if (isset($ves_force_initial_page) && $ves_force_initial_page) : ?>
<script>try{localStorage.removeItem('ves_active_page_scrapers_v1');}catch(e){}</script>
<?php endif; ?>
<div class="ves-wrap fiis-app ves-dashboard-shell ves-shell-v3 ves-light-suite" id="<?php echo esc_attr($widget_id); ?>" data-default-page="<?php echo esc_attr($default_page); ?>"<?php echo (isset($ves_force_initial_page) && $ves_force_initial_page) ? ' data-force-route="' . esc_attr($ves_force_initial_page) . '"' : ''; ?> data-shell-mode="app" data-admin-ui="<?php echo current_user_can('manage_options') ? '1' : '0'; ?>" data-debug-mode="<?php echo esc_attr($ves_debug_mode); ?>" data-production-mvp="<?php echo $ves_production_mvp ? '1' : '0'; ?>" style="--ves-suite-height: <?php echo esc_attr(isset($atts['height']) && $atts['height'] !== '' ? $atts['height'] : '820px'); ?>;">

    <?php /* v0.9.28.0 — Phase 8: mobile drawer infrastructure rendered in PHP
        so it doesn't depend on the JS injecting it at runtime. The hamburger
        button is hidden by CSS on ≥1280px. The backdrop is purely cosmetic
        and click-through to close the drawer (handled by the existing JS
        outside-click handler from v0.9.26.0). */ ?>
    <button type="button" class="ves-mobile-menu-toggle" aria-label="Abrir menú" aria-controls="<?php echo esc_attr($widget_id); ?>-sidebar" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <div class="ves-mobile-drawer-backdrop" aria-hidden="true"></div>

    <?php
    /* ── v1.0.8 Shell IA helpers ──────────────────────────────────────────
       One grouped, Semrush-style navigation rendered for BOTH shell modes.
       Items whose page lives in the CURRENT mode render as real `data-page`
       tabs (preserving every JS hook + the allowed-page sets). Items that
       belong to the OTHER mode render as cross-links to the correct WP page,
       so the sidebar always presents the whole product IA without ever
       mis-switching a page that the JS would reject. */
    $ves_icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'projects'  => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'memory'    => '<path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/>',
        'knowledge' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'usage'     => '<path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-5"/>',
        'brief'     => '<path d="M4 19V5a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M14 3v5h5"/><path d="M8 13h8M8 17h5"/>',
        'social'    => '<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'linkedin'  => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
        'seo'       => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'google'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/>',
        'trend'     => '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="14 7 21 7 21 14"/>',
        'deeptrend' => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
        'brand'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'ads'       => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'creative'  => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'reports'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/>',
        'evidence'  => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
    ];
    $ves_ico = function ($key) use ($ves_icons) {
        $p = $ves_icons[$key] ?? '';
        return '<svg class="ves-nav-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    };
    // Render a nav entry. If $page is allowed in the current mode → tab; else → cross-link ($url) or omitted.
    // Unified app: every route is a sibling page inside ONE shell, so every
    // sidebar entry is an internal route tab — no cross-links, no shell modes.
    // v0.4.0: legacy 'trend' page removed — VES_App_Router aliases it to the
    // canonical 'deep-trend' Trend Finder, so old links still land correctly.
    $ves_mode_pages    = ['dashboard','projects','memory','knowledge','usage','social','linkedin','seo','google','deep-trend','brand-audit','ads','creative','reports','brief-library','evidence'];
    $ves_other_url     = '';
    $ves_nav = function ($icoKey, $label, $page = '', $linkUrl = '', $soon = false) use ($ves_ico, $ves_mode_pages, $default_page, $ves_other_url) {
        $is_tab = $page !== '' && in_array($page, $ves_mode_pages, true);
        $soon_badge = $soon ? '<span class="ves-nav-soon" aria-label="Próximamente">Soon</span>' : '';
        if ($is_tab) {
            $active = $default_page === $page;
            printf(
                '<button type="button" class="ves-nav-item%s%s" data-page="%s" role="tab" aria-selected="%s"><span class="ves-nav-ico-wrap">%s</span><span class="ves-nav-label">%s</span>%s</button>',
                $active ? ' is-active' : '', $soon ? ' is-soon' : '', esc_attr($page), $active ? 'true' : 'false', $ves_ico($icoKey), esc_html($label), $soon_badge
            );
            return;
        }
        $href = $linkUrl !== '' ? $linkUrl : $ves_other_url;
        if ($href === '') { return; }
        printf(
            '<a class="ves-nav-item ves-nav-link" href="%s"><span class="ves-nav-ico-wrap">%s</span><span class="ves-nav-label">%s</span><span class="ves-nav-ext" aria-hidden="true">&#8599;</span></a>',
            esc_url($href), $ves_ico($icoKey), esc_html($label)
        );
    };
    ?>
    <!-- ============ SIDEBAR ============ -->
    <aside class="ves-sidebar" id="<?php echo esc_attr($widget_id); ?>-sidebar" role="navigation" aria-label="Workspace navigation">
        <div class="ves-sidebar-head">
            <button type="button" class="ves-workspace-switcher" aria-label="Workspace activo">
                <div class="ves-logo" aria-hidden="true">FI</div>
                <div class="ves-workspace-text">
                    <strong><?php echo esc_html($atts['title']); ?></strong>
                    <span class="ves-workspace-meta">
                        <span class="ves-workspace-status-dot" aria-hidden="true"></span>
                        <span class="ves-current-project-pill" data-current-project-label>Default Project</span>
                    </span>
                </div>
                <svg class="ves-workspace-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <button type="button" class="ves-sidebar-collapse" aria-label="Plegar barra lateral">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </button>
        </div>

        <nav class="ves-sidebar-nav" role="tablist" aria-label="Secciones">
            <div class="ves-nav-group">
                <div class="ves-sidebar-section-label">Workspace</div>
                <?php
                    $ves_nav('dashboard', 'Dashboard', 'dashboard');
                    $ves_nav('projects',  'Projects', 'projects', '', true);
                    $ves_nav('memory',    'Memory',    'memory');
                    $ves_nav('knowledge', 'Knowledge Base', 'knowledge');
                    $ves_nav('usage',     'Usage & Credits', 'usage');
                ?>
            </div>

            <div class="ves-nav-group">
                <div class="ves-sidebar-section-label">Social Intelligence</div>
                <?php
                    $ves_nav('social',   'Social Media', 'social');
                    $ves_nav('linkedin', 'LinkedIn', 'linkedin');
                ?>
            </div>

            <div class="ves-nav-group">
                <div class="ves-sidebar-section-label">Search &amp; Discovery</div>
                <?php
                    $ves_nav('seo',       'SEO / SEM / AEO', 'seo');
                    $ves_nav('google',    'Google Intelligence', 'google');
                    // v0.4.0: ONE Trend Finder entry — the canonical deep-trend
                    // workspace. The legacy 'trend' route aliases to it.
                    $ves_nav('deeptrend', 'Trend Finder', 'deep-trend');
                ?>
            </div>

            <div class="ves-nav-group">
                <div class="ves-sidebar-section-label">Brand &amp; Creative</div>
                <?php
                    $ves_nav('brand',    'Brand Audit', 'brand-audit');
                    $ves_nav('ads',      'Ads Intelligence', 'ads');
                    $ves_nav('creative', 'Creative Intelligence', 'creative');
                ?>
            </div>

            <div class="ves-nav-group">
                <div class="ves-sidebar-section-label">Outputs</div>
                <?php
                    $ves_nav('reports',  'Reports', 'reports', '', true);
                    $ves_nav('brief',    'Brief Library', 'brief-library');
                    $ves_nav('evidence', 'Evidence Library', 'evidence', '', true);
                ?>
            </div>
        </nav>

        <?php if ($is_logged_in) : ?>
            <section class="ves-sidebar-memory" data-sidebar-memory aria-label="Project memory">
                <div class="ves-sidebar-memory-separator" aria-hidden="true"></div>
                <div class="ves-sidebar-memory-loading">Cargando memoria...</div>
            </section>
        <?php endif; ?>

        <?php if ($is_logged_in) :
            $ves_side_credits = '—'; $ves_side_unlimited = false; $ves_side_pct = 60;
            if (class_exists('VES_Usage_Billing')) {
                try {
                    if (method_exists('VES_Usage_Billing','is_unlimited_user') && VES_Usage_Billing::is_unlimited_user(get_current_user_id())) {
                        $ves_side_unlimited = true; $ves_side_credits = '∞'; $ves_side_pct = 100;
                    } else {
                        $ves_side_sum = VES_Usage_Billing::get_summary(get_current_user_id());
                        if (is_array($ves_side_sum) && isset($ves_side_sum['balance'])) { $ves_side_credits = number_format((float) $ves_side_sum['balance']); }
                    }
                } catch (Throwable $e) {}
            }
        ?>
        <a class="ves-sidebar-credits" href="?fiis_page=usage" aria-label="Uso y créditos">
            <div class="ves-sidebar-credits-row">
                <span class="ves-sidebar-credits-label">Créditos</span>
                <span class="ves-sidebar-credits-value" data-sidebar-credits><?php echo esc_html($ves_side_credits); ?></span>
            </div>
            <div class="ves-sidebar-credits-bar" aria-hidden="true"><span style="width:<?php echo (int) $ves_side_pct; ?>%"></span></div>
        </a>
        <?php endif; ?>

        <div class="ves-sidebar-foot">
            <button type="button" class="ves-cmdk-btn" aria-label="Abrir command palette">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <span class="ves-nav-label">Buscar / Acciones</span>
                <kbd>⌘K</kbd>
            </button>
        </div>
    </aside>

    <!-- ============ MAIN COLUMN ============ -->
    <div class="ves-main-col">

        <!-- ============ TOP BAR ============ -->
        <header class="ves-topbar" role="banner">
            <div class="ves-topbar-left">
                <button type="button" class="ves-sidebar-toggle" aria-label="Mostrar barra lateral">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>
                <nav class="ves-breadcrumbs fi-breadcrumb" aria-label="Ruta">
                    <?php
                    // Phase 5C-UX (B1): breadcrumb ROOT is the product identity (Future
                    // Island), not the tenant page title. Module group + page follow.
                    $ves_product_root = (class_exists('VES_Branding') && (string) VES_Branding::product_name() !== '' && VES_Branding::product_name() !== 'Intelligence Suite')
                        ? VES_Branding::product_name() : 'Future Island';
                    ?>
                    <span class="ves-breadcrumb-root"><?php echo esc_html($ves_product_root); ?></span>
                    <span class="ves-breadcrumb-sep" data-page-group-sep aria-hidden="true">/</span>
                    <span class="ves-breadcrumb-group" data-page-group></span>
                    <span class="ves-breadcrumb-sep" aria-hidden="true">/</span>
                    <span class="ves-breadcrumb-current" data-page-label></span>
                </nav>
                <button type="button" class="ves-topbar-search ves-cmdk-btn" aria-label="Buscar acciones y páginas">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <span class="ves-topbar-search-label">Buscar herramientas, páginas, acciones…</span>
                    <kbd>⌘K</kbd>
                </button>
            </div>
            <div class="ves-topbar-right">
                <button type="button" class="ves-topbar-icon-btn ves-notif-btn" aria-label="Notificaciones y estado">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                </button>
                <div class="ves-ui-language-switcher" aria-label="Interface language">
                    <span class="ves-ui-language-icon" aria-hidden="true">文</span>
                    <select class="ves-ui-language" name="vesUiLanguage" aria-label="Interface language">
                        <option value="es">ES</option>
                        <option value="en">EN</option>
                        <option value="fa">FA</option>
                    </select>
                </div>
                <div class="ves-run-state" data-run-state hidden>
                    <span class="ves-run-state-dot" aria-hidden="true"></span>
                    <span class="ves-run-state-label">Idle</span>
                </div>
                <?php if ($is_logged_in) : ?>
                <div class="ves-credit-pill" data-credit-pill aria-label="Créditos disponibles">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 10h6M9 14h6"/></svg>
                    <span class="ves-credit-pill-value">—</span>
                    <span class="ves-credit-pill-label">créditos</span>
                </div>
                <?php endif; ?>
                <?php if ($is_logged_in && class_exists('VES_Auth')) : ?>
                    <div class="ves-user-menu">
                        <button type="button" class="ves-user-avatar" aria-label="Menú de usuario" aria-haspopup="true" aria-expanded="false">
                            <span class="ves-user-avatar-initials"><?php echo esc_html($user_initials ?: 'U'); ?></span>
                        </button>
                        <div class="ves-user-dropdown" role="menu" hidden>
                            <div class="ves-user-dropdown-head">
                                <div class="ves-user-dropdown-name"><?php echo esc_html($current_user instanceof WP_User ? $current_user->display_name : ''); ?></div>
                                <div class="ves-user-dropdown-email"><?php echo esc_html($current_user instanceof WP_User ? $current_user->user_email : ''); ?></div>
                            </div>
                            <a class="ves-user-dropdown-item" role="menuitem" href="<?php echo esc_url(VES_Auth::get_dashboard_url()); ?>">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                                Mi Panel SaaS
                            </a>
                            <a class="ves-user-dropdown-item" role="menuitem" href="<?php echo esc_url(wp_logout_url(VES_Auth::get_page_url('login'))); ?>">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                                Cerrar sesión
                            </a>
                        </div>
                    </div>
                <?php elseif (class_exists('VES_Auth')) : ?>
                    <a class="ves-btn ves-btn-secondary" href="<?php echo esc_url(VES_Auth::get_page_url('login')); ?>">Login</a>
                    <a class="ves-btn ves-btn-primary" href="<?php echo esc_url(VES_Auth::get_page_url('register')); ?>">Registro</a>
                <?php endif; ?>
            </div>
        </header>

        <!-- ============ PAGE BODY ============ -->
        <main class="ves-page-body" role="main" id="<?php echo esc_attr($widget_id); ?>-main" tabindex="-1">

            <?php /* Unified app: ALL routes render once inside this single shell. */ ?>
                <!-- ============ SOCIAL INTELLIGENCE ============ -->
                <section class="ves-page" data-page="social" data-default-platform="tiktok" role="tabpanel" aria-label="Social Intelligence" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Social Intelligence">Social Media Intelligence</h2>
                                <p class="ves-page-sub">Busca posts públicos con pocos controles y salida enfocada en relevancia.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-danger ves-abort" hidden>Cancelar</button>
                                <button type="button" class="ves-btn ves-btn-primary ves-start">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Ejecutar
                                    <kbd>⌘↵</kbd>
                                </button>
                            </div>
                        </div>

                        <div class="ves-card ves-command-center-card">
                            <div class="ves-tabs ves-platform-tabs" role="tablist" aria-label="Plataforma social">
                                <button type="button" class="ves-tab active" data-platform="tiktok" role="tab" aria-label="TikTok" title="TikTok">
                                    <span class="ves-platform-logo ves-logo-tiktok" aria-hidden="true"><svg viewBox="0 0 48 48" focusable="false"><path class="tt-cyan" d="M21 7h8c.4 4.6 3 7.7 7.8 8.4v7.6c-3-.1-5.8-1-7.8-2.7v12.1c0 7.2-5.1 12-12.2 12-6.7 0-11.6-4.5-11.6-10.8 0-6.5 5.2-11 12.3-11 .9 0 1.7.1 2.5.3v8.1a6.1 6.1 0 0 0-2.1-.4c-2.5 0-4.2 1.4-4.2 3.5s1.7 3.5 4.1 3.5c2.6 0 4.2-1.6 4.2-4.5V7Z"/><path class="tt-red" d="M24 7h8c.4 4.6 3 7.7 7.8 8.4v7.6c-3-.1-5.8-1-7.8-2.7v12.1c0 7.2-5.1 12-12.2 12-6.7 0-11.6-4.5-11.6-10.8 0-6.5 5.2-11 12.3-11 .9 0 1.7.1 2.5.3v8.1a6.1 6.1 0 0 0-2.1-.4c-2.5 0-4.2 1.4-4.2 3.5s1.7 3.5 4.1 3.5c2.6 0 4.2-1.6 4.2-4.5V7Z"/><path class="tt-main" d="M22.5 7h8c.4 4.6 3 7.7 7.8 8.4v7.6c-3-.1-5.8-1-7.8-2.7v12.1c0 7.2-5.1 12-12.2 12-6.7 0-11.6-4.5-11.6-10.8 0-6.5 5.2-11 12.3-11 .9 0 1.7.1 2.5.3v8.1a6.1 6.1 0 0 0-2.1-.4c-2.5 0-4.2 1.4-4.2 3.5s1.7 3.5 4.1 3.5c2.6 0 4.2-1.6 4.2-4.5V7Z"/></svg></span>
                                    <span class="ves-platform-name">TikTok</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="youtube" role="tab" aria-label="YouTube" title="YouTube">
                                    <span class="ves-platform-logo ves-logo-youtube" aria-hidden="true"><svg viewBox="0 0 28 20" focusable="false"><path d="M27.4 3.1A3.5 3.5 0 0 0 25 .6C22.8 0 14 0 14 0S5.2 0 3 .6A3.5 3.5 0 0 0 .6 3.1 36.4 36.4 0 0 0 0 10a36.4 36.4 0 0 0 .6 6.9A3.5 3.5 0 0 0 3 19.4c2.2.6 11 .6 11 .6s8.8 0 11-.6a3.5 3.5 0 0 0 2.4-2.5A36.4 36.4 0 0 0 28 10a36.4 36.4 0 0 0-.6-6.9Z"/><path class="ves-yt-play" d="M11.2 14.2V5.8L18.5 10l-7.3 4.2Z"/></svg></span>
                                    <span class="ves-platform-name">YouTube</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="facebook" role="tab" aria-label="Facebook" title="Facebook">
                                    <span class="ves-platform-logo ves-logo-facebook" aria-hidden="true"><svg viewBox="0 0 48 48" focusable="false"><circle cx="24" cy="24" r="22"/><path d="M29.8 25.4h-4.1v14.2h-6V25.4h-3v-5.1h3v-3.1c0-2.4 1.2-6.2 6.2-6.2h4.6v5.1h-3.3c-.5 0-1.4.3-1.4 1.5v2.7h4.9l-.9 5.1Z"/></svg></span>
                                    <span class="ves-platform-name">Facebook</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="instagram" role="tab" aria-label="Instagram" title="Instagram">
                                    <span class="ves-platform-logo ves-logo-instagram" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none" focusable="false" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="ig-g" x1="6" y1="42" x2="42" y2="6" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#FEDA75"/><stop offset="18%" stop-color="#FA7E1E"/><stop offset="42%" stop-color="#D62976"/><stop offset="68%" stop-color="#962FBF"/><stop offset="100%" stop-color="#4F5BD5"/></linearGradient></defs><rect width="48" height="48" rx="13" fill="url(#ig-g)"/><rect x="13" y="13" width="22" height="22" rx="6.5" stroke="#fff" stroke-width="3"/><circle cx="24" cy="24" r="6" stroke="#fff" stroke-width="3"/><circle cx="34.5" cy="13.5" r="2.5" fill="#fff"/></svg></span>
                                    <span class="ves-platform-name">Instagram</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="twitter" role="tab" aria-label="X / Twitter" title="X / Twitter">
                                    <span class="ves-platform-logo ves-logo-x" aria-hidden="true"><svg viewBox="0 0 48 48" focusable="false"><path d="M28.6 21.1 42.2 5h-7.1L25.3 16.7 17.5 5H5.8l14.3 21.2L5 43h7.1l11.3-13.3L32.5 43h11.7L28.6 21.1Zm-4 4.7-1.8-2.6L14.1 10.6h3.9l7 10.1 1.8 2.6 9.1 13.1H32l-7.4-10.6Z"/></svg></span>
                                    <span class="ves-platform-name">X</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="reddit" role="tab" aria-label="Reddit" title="Reddit">
                                    <span class="ves-platform-logo ves-logo-reddit" aria-hidden="true"><svg viewBox="0 0 48 48" focusable="false"><circle cx="24" cy="24" r="22"/><path d="M34.8 22.3c-.9 0-1.7.4-2.2 1a13.8 13.8 0 0 0-7.4-2.3l1.4-6.5 4.5 1a2.6 2.6 0 1 0 .4-1.7l-5.3-1.1a.9.9 0 0 0-1 .7L23.6 21a14 14 0 0 0-8.2 2.3 3 3 0 1 0-3.3 4.9 5.2 5.2 0 0 0-.1 1.1c0 4.6 5.4 8.3 12 8.3s12-3.7 12-8.3c0-.4 0-.8-.1-1.1a3 3 0 0 0-1.1-5.9ZM18.6 28a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm10.7 5.8c-1.5 1-3.2 1.4-5.3 1.4s-3.8-.5-5.3-1.4a.8.8 0 1 1 .9-1.4c1.2.8 2.6 1.1 4.4 1.1s3.2-.4 4.4-1.1a.8.8 0 1 1 .9 1.4ZM29.4 32a2 2 0 1 1 0-4 2 2 0 0 1 0 4Z"/></svg></span>
                                    <span class="ves-platform-name">Reddit</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="pinterest" role="tab" aria-label="Pinterest" title="Pinterest">
                                    <span class="ves-platform-logo ves-logo-pinterest" aria-hidden="true"><svg viewBox="0 0 48 48" focusable="false"><circle cx="24" cy="24" r="22"/><path d="M24.2 8.7c-8.4 0-14.4 5.7-14.4 13.4 0 5.8 3.3 9.2 5.3 9.2 0.9 0 1.4-2.4 1.4-3.1 0-0.8-2-2.4-2-5.6 0-5.5 4.1-9.4 9.6-9.4 4.7 0 8.2 2.7 8.2 7.7 0 3.8-1.5 10.9-6.4 10.9-1.8 0-3.3-1.3-3.3-3.1 0-2.8 2-5.5 2-8.4 0-4.9-7-4-7 1.9 0 1.2 0.1 2.5 0.7 3.6-1 4.1-3 10.3-3 14.5 0 1.3 0.2 2.6 0.3 3.9 0.2 0.2 0.1 0.2 0.4 0.1 3.5-4.8 3.4-5.7 4.9-11.9 0.8 1.5 2.9 2.3 4.5 2.3 6.9 0 10-6.7 10-12.8 0-6.5-5.6-11.2-12.2-11.2Z"/></svg></span>
                                    <span class="ves-platform-name">Pinterest</span>
                                </button>
                            </div>
                            <?php $form_default_platform = 'tiktok'; include VES_PLUGIN_DIR . 'templates/_scraper-form.php'; ?>
                        </div>

                        <div class="fiis-results-region" aria-label="Resultados">
                            <div class="fiis-region-head"><h3 class="fiis-region-title">Resultados</h3><span class="fiis-region-hint">Evidencia, métricas y reporte de la última ejecución</span></div>
                            <div class="ves-status" role="status" aria-live="polite"></div>
                            <div class="ves-results" aria-live="polite"></div>
                            <div class="ves-report-container" aria-live="polite"></div>
                            <div class="fiis-region-empty">Ejecuta una búsqueda para ver resultados, evidencia y reporte aquí.</div>
                        </div>
                    </div>
                </section>

                <!-- ============ LINKEDIN ============ -->
                <section class="ves-page" data-page="linkedin" data-default-platform="linkedin" role="tabpanel" aria-label="LinkedIn Intelligence" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Social Intelligence">LinkedIn Intelligence</h2>
                                <p class="ves-page-sub">Busca perfiles profesionales públicos y convierte señales de personas, roles y compañías en inteligencia accionable.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-danger ves-abort" hidden>Cancelar</button>
                                <button type="button" class="ves-btn ves-btn-primary ves-start">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Ejecutar LinkedIn
                                </button>
                            </div>
                        </div>
                        <div class="ves-card ves-command-center-card ves-linkedin-card">
                            <?php include VES_PLUGIN_DIR . 'templates/_linkedin-form.php'; ?>
                        </div>
                        <div class="fiis-results-region" aria-label="Resultados">
                            <div class="fiis-region-head"><h3 class="fiis-region-title">Resultados</h3><span class="fiis-region-hint">Evidencia, métricas y reporte de la última ejecución</span></div>
                            <div class="ves-status" role="status" aria-live="polite"></div>
                            <div class="ves-results" aria-live="polite"></div>
                            <div class="ves-report-container" aria-live="polite"></div>
                            <div class="fiis-region-empty">Ejecuta una búsqueda para ver resultados, evidencia y reporte aquí.</div>
                        </div>
                    </div>
                </section>

                <!-- ============ SEO/SEM/AEO ============ -->
                <section class="ves-page" data-page="seo" data-default-platform="semrush" role="tabpanel" aria-label="SEO SEM AEO Intelligence" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Search &amp; Discovery">SEO / SEM / AEO Intelligence</h2>
                                <p class="ves-page-sub">Recolecta datos SEO, agencias Semrush, autoridad MOZ y señales Ahrefs para investigación, prospección y benchmarking.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-danger ves-abort" hidden>Cancelar</button>
                                <button type="button" class="ves-btn ves-btn-primary ves-start">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Ejecutar SEO
                                </button>
                            </div>
                        </div>

                        <div class="fiis-seo-scopes" role="list" aria-label="Cobertura SEO/SEM/AEO">
                            <span class="fiis-scope-chip is-active" role="listitem">Keyword research</span>
                            <span class="fiis-scope-chip" role="listitem">Domain overview</span>
                            <span class="fiis-scope-chip" role="listitem">Competidores</span>
                            <span class="fiis-scope-chip" role="listitem">AEO / Answer engines</span>
                        </div>

                        <div class="ves-dash-stats fiis-seo-kpis" aria-label="Resumen">
                            <div class="ves-stat-card"><div class="ves-stat-label">Keywords</div><div class="ves-stat-value">—</div><div class="ves-stat-trend">Tras ejecutar</div></div>
                            <div class="ves-stat-card"><div class="ves-stat-label">Tráfico est.</div><div class="ves-stat-value">—</div><div class="ves-stat-trend">Tras ejecutar</div></div>
                            <div class="ves-stat-card"><div class="ves-stat-label">Autoridad</div><div class="ves-stat-value">—</div><div class="ves-stat-trend">Tras ejecutar</div></div>
                            <div class="ves-stat-card"><div class="ves-stat-label">Competidores</div><div class="ves-stat-value">—</div><div class="ves-stat-trend">Tras ejecutar</div></div>
                        </div>

                        <div class="ves-card ves-command-center-card ves-seo-card fiis-control-panel">
                            <div class="fiis-panel-label">Consulta</div>
                            <?php include VES_PLUGIN_DIR . 'templates/_seo-form.php'; ?>
                        </div>
                        <div class="fiis-results-region" aria-label="Resultados">
                            <div class="fiis-region-head"><h3 class="fiis-region-title">Resultados</h3><span class="fiis-region-hint">Evidencia, métricas y reporte de la última ejecución</span></div>
                            <div class="ves-status" role="status" aria-live="polite"></div>
                            <div class="ves-results" aria-live="polite"></div>
                            <div class="ves-report-container" aria-live="polite"></div>
                            <div class="fiis-region-empty">Ejecuta una búsqueda para ver resultados, evidencia y reporte aquí.</div>
                        </div>
                    </div>
                </section>

                <!-- v0.4.0: the legacy 'trend' page section was removed. ONE canonical
                     Trend Finder remains (the deep-trend section below); the router
                     aliases the old 'trend' route to it so bookmarks still work. -->
                <!-- ============ BRAND DEEP AUDIT ============ -->
                <section class="ves-page" data-page="brand-audit" data-default-platform="brand_audit" role="tabpanel" aria-label="Brand Deep Audit" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Brand &amp; Creative">Brand Deep Audit</h2>
                                <p class="ves-page-sub">Audita una marca desde web, social, búsqueda, maps y research para preparar insights y una presentación.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-danger ves-abort" hidden>Cancelar</button>
                                <button type="button" class="ves-btn ves-btn-primary ves-start">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Ejecutar Brand Audit
                                </button>
                            </div>
                        </div>
                        <div class="ves-card ves-command-center-card ves-brand-audit-card">
                            <?php include VES_PLUGIN_DIR . 'templates/_brand-audit-form.php'; ?>
                        </div>
                        <div class="fiis-results-region" aria-label="Resultados">
                            <div class="fiis-region-head"><h3 class="fiis-region-title">Resultados</h3><span class="fiis-region-hint">Evidencia, métricas y reporte de la última ejecución</span></div>
                            <div class="ves-status" role="status" aria-live="polite"></div>
                            <div class="ves-results" aria-live="polite"></div>
                            <div class="ves-report-container" aria-live="polite"></div>
                            <div class="fiis-region-empty">Ejecuta una búsqueda para ver resultados, evidencia y reporte aquí.</div>
                        </div>
                    </div>
                </section>

                <!-- ============ CREATIVE INTELLIGENCE ============ -->
                <section class="ves-page" data-page="creative" role="tabpanel" aria-label="Creative Intelligence" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Brand &amp; Creative">Creative Intelligence</h2>
                                <p class="ves-page-sub">Analiza imágenes, screenshots, creatividades y vídeos cortos para detectar hook, claridad, fit de plataforma, riesgos y próximos pasos.</p>
                            </div>
                        </div>
                        <?php
                        if (!$is_logged_in) {
                            include VES_PLUGIN_DIR . 'templates/_login-empty-state.php';
                        } elseif (class_exists('VES_Creative_Intelligence')) {
                            echo VES_Creative_Intelligence::render_panel();
                        } else {
                            echo '<div class="ves-item-panel"><div class="ves-item-panel-title">Creative Intelligence</div><p class="ves-hint">El módulo creativo no está disponible. Asegúrate de que el archivo de clase esté cargado.</p></div>';
                        }
                        ?>
                    </div>
                </section>

                <!-- ============ GOOGLE INTELLIGENCE ============ -->
                <section class="ves-page" data-page="google" data-default-platform="google_intel" role="tabpanel" aria-label="Google Intelligence" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Search &amp; Discovery">Google Intelligence</h2>
                                <p class="ves-page-sub">Búsqueda, Noticias, Trends e Imágenes con parámetros seguros por defecto.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-danger ves-abort" hidden>Cancelar</button>
                                <button type="button" class="ves-btn ves-btn-primary ves-start">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Ejecutar Google Intelligence
                                </button>
                            </div>
                        </div>

                        <div class="ves-card ves-command-center-card ves-google-card">
                            <?php include VES_PLUGIN_DIR . 'templates/_google-intel-form.php'; ?>
                        </div>

                        <div class="fiis-results-region" aria-label="Resultados">
                            <div class="fiis-region-head"><h3 class="fiis-region-title">Resultados</h3><span class="fiis-region-hint">Evidencia, métricas y reporte de la última ejecución</span></div>
                            <div class="ves-status" role="status" aria-live="polite"></div>
                            <div class="ves-results" aria-live="polite"></div>
                            <div class="ves-report-container" aria-live="polite"></div>
                            <div class="fiis-region-empty">Ejecuta una búsqueda para ver resultados, evidencia y reporte aquí.</div>
                        </div>
                    </div>
                </section>



                <!-- ============ ADS INTELLIGENCE ============ -->
                <section class="ves-page" data-page="ads" data-default-platform="facebook_ads" role="tabpanel" aria-label="Ads Intelligence" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Brand &amp; Creative">Ads Intelligence</h2>
                                <p class="ves-page-sub">Analiza señales publicitarias públicas de las principales plataformas y extrae inteligencia competitiva.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-danger ves-abort" hidden>Cancelar</button>
                                <button type="button" class="ves-btn ves-btn-primary ves-start">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Ejecutar
                                </button>
                            </div>
                        </div>
                        <div class="ves-card ves-command-center-card">
                            <div class="ves-tabs ves-platform-tabs ves-ads-platform-tabs" role="tablist" aria-label="Ads platform">
                                <button type="button" class="ves-tab active" data-platform="facebook_ads" role="tab" aria-label="Meta Ads" title="Meta Ads">
                                    <span class="ves-platform-logo ves-logo-meta" aria-hidden="true"><svg viewBox="0 0 64 40" focusable="false"><path d="M13.6 31.1C7.2 31.1 3 26 3 19.9 3 13.6 7.3 8.9 13 8.9c4.3 0 7.5 2.5 10.7 7.1l2.3 3.4 2.2-3.4c3.4-5 6.7-7.1 10.9-7.1 5.8 0 10.9 4.7 10.9 11.2 0 6.6-4.8 11-10.9 11-4.4 0-7.5-2.1-11.2-7.4l-2-2.8-2.1 3.1c-3.6 5.1-6.4 7.1-10.2 7.1Zm.1-6.1c2.1 0 3.6-1.1 6.2-4.8l1.9-2.7-1.5-2.2c-2.6-3.8-4.5-4.9-7-4.9-3.2 0-5.7 2.7-5.7 6.4 0 3.7 2.5 6.2 6.1 6.2Zm25.8 0c3.5 0 6-2.5 6-6.1 0-3.8-2.7-6.5-6.2-6.5-2.4 0-4.2 1.1-6.9 5l-1.6 2.3 1.9 2.7c2.8 3.8 4.4 4.6 6.8 4.6Z"/></svg></span>
                                    <span class="ves-platform-name">Meta Ads</span>
                                </button>
                                <button type="button" class="ves-tab" data-platform="google_ads" role="tab" aria-label="Google Ads" title="Google Ads">
                                    <span class="ves-platform-logo ves-logo-google-ads" aria-hidden="true"><span class="ga-blue"></span><span class="ga-green"></span><span class="ga-yellow"></span></span>
                                    <span class="ves-platform-name">Google Ads</span>
                                </button>
                            </div>
                            <?php $form_default_platform = 'facebook_ads'; include VES_PLUGIN_DIR . 'templates/_ads-form.php'; ?>
                        </div>
                        <div class="fiis-results-region" aria-label="Resultados">
                            <div class="fiis-region-head"><h3 class="fiis-region-title">Resultados</h3><span class="fiis-region-hint">Evidencia, métricas y reporte de la última ejecución</span></div>
                            <div class="ves-status" role="status" aria-live="polite"></div>
                            <div class="ves-results" aria-live="polite"></div>
                            <div class="ves-report-container" aria-live="polite"></div>
                            <div class="fiis-region-empty">Ejecuta una búsqueda para ver resultados, evidencia y reporte aquí.</div>
                        </div>
                    </div>
                </section>

                <!-- ============ MEMORY PAGE ============ -->
                <section class="ves-page" data-page="memory" role="tabpanel" aria-label="Memory" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title">Memory</h2>
                                <p class="ves-page-sub">Tus búsquedas guardadas, resultados anteriores y análisis AI. Accede a cualquier resultado sin repetir la recolección.</p>
                            </div>
                        </div>
                        <?php if (!$is_logged_in) : ?>
                            <?php include VES_PLUGIN_DIR . 'templates/_login-empty-state.php'; ?>
                        <?php else : ?>
                            <div class="ves-memory-skeleton" data-memory-skeleton>
                                <div class="ves-skeleton-card">
                                    <div class="ves-skel ves-skel-line" style="width:30%"></div>
                                    <div class="ves-skel ves-skel-line" style="width:55%;margin-top:10px"></div>
                                    <div class="ves-skeleton-grid">
                                        <div class="ves-skeleton-tile"></div>
                                        <div class="ves-skeleton-tile"></div>
                                        <div class="ves-skeleton-tile"></div>
                                        <div class="ves-skeleton-tile"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="ves-memory-host" aria-live="polite"></div>
                            <form class="ves-form" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;" hidden>
                                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                                <input type="hidden" name="platform" value="memory">
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ============ DASHBOARD / OVERVIEW (composed) ============ -->
                <?php
                    // Server-rendered credit value for the KPI strip (degrades safely).
                    $ves_dash_credits = '—';
                    if ($is_logged_in && class_exists('VES_Usage_Billing')) {
                        try {
                            if (method_exists('VES_Usage_Billing', 'is_unlimited_user') && VES_Usage_Billing::is_unlimited_user(get_current_user_id())) {
                                $ves_dash_credits = '∞';
                            } else {
                                $ves_dash_sum = VES_Usage_Billing::get_summary(get_current_user_id());
                                if (is_array($ves_dash_sum) && isset($ves_dash_sum['balance'])) {
                                    $ves_dash_credits = number_format((float) $ves_dash_sum['balance']);
                                }
                            }
                        } catch (Throwable $e) { $ves_dash_credits = '—'; }
                    }
                    $ves_dash_tools = [
                        ['social',      'social',      'Social Media',  'Señales sociales y posts públicos'],
                        ['seo',         'seo',         'SEO / SEM / AEO','Keywords, dominios y autoridad'],
                        ['google',      'google',      'Google Intelligence', 'Search, news y trends'],
                        ['trend',       'deep-trend',  'Trend Finder',  'Tendencias con evidencia real'],
                        ['brand',       'brand-audit', 'Brand Audit',   'Auditoría estratégica de marca'],
                        ['ads',         'ads',         'Ads Intelligence', 'Inteligencia competitiva de ads'],
                    ];
                ?>
                <section class="ves-page is-active" data-page="dashboard" role="tabpanel" aria-label="Dashboard">
                    <div class="ves-page-inner">
                        <div class="ves-dash">
                            <!-- Section 1: welcome hero -->
                            <div class="ves-dash-hero">
                                <div>
                                    <h2><?php echo esc_html($is_logged_in && $current_user instanceof WP_User ? ('Hola, ' . $current_user->display_name) : 'Bienvenido a Future Island'); ?></h2>
                                    <p>Tu sala de señales: evidencia primero, insight después, brief y output al final. Elige una herramienta o retoma un run reciente.</p>
                                </div>
                                <div class="ves-page-head-actions">
                                    <a class="ves-btn ves-btn-primary" href="?fiis_page=social">Abrir herramientas</a>
                                </div>
                            </div>

                            <!-- Section 2: KPI / usage strip -->
                            <div class="ves-dash-stats">
                                <div class="ves-stat-card"><div class="ves-stat-label">Créditos</div><div class="ves-stat-value"><?php echo esc_html($ves_dash_credits); ?></div><div class="ves-stat-trend">Disponibles</div></div>
                                <div class="ves-stat-card"><div class="ves-stat-label">Herramientas</div><div class="ves-stat-value">11</div><div class="ves-stat-trend">Módulos activos</div></div>
                                <div class="ves-stat-card"><div class="ves-stat-label">Workspace</div><div class="ves-stat-value" style="font-size:18px">Default</div><div class="ves-stat-trend">Proyecto actual</div></div>
                                <div class="ves-stat-card"><div class="ves-stat-label">Estado</div><div class="ves-stat-value" style="font-size:18px;color:var(--ok,#16a34a)">● Activo</div><div class="ves-stat-trend">Señal en vivo</div></div>
                            </div>

                            <!-- Section 3+4: tool shortcuts + recent activity -->
                            <div class="ves-dash-grid">
                                <div class="ves-dash-section">
                                    <div class="ves-dash-section-head"><h3>Herramientas de inteligencia</h3></div>
                                    <div class="ves-dash-tools">
                                        <?php foreach ($ves_dash_tools as $t) : ?>
                                            <a class="ves-tool-card" href="?fiis_page=<?php echo esc_attr($t[1]); ?>">
                                                <span class="ves-tool-ico"><?php echo $ves_ico($t[0]); ?></span>
                                                <span><span class="ves-tool-name"><?php echo esc_html($t[2]); ?></span><span class="ves-tool-desc"><?php echo esc_html($t[3]); ?></span></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="ves-dash-section">
                                    <div class="ves-dash-section-head"><h3>Actividad reciente</h3></div>
                                    <?php if ($is_logged_in) : ?>
                                        <div class="ves-dash-recent">
                                            <div class="ves-empty-state" style="padding:22px">Tus runs y memoria reciente aparecerán aquí. Abre <strong>Memory</strong> para ver el historial completo.</div>
                                        </div>
                                    <?php else : ?>
                                        <?php include VES_PLUGIN_DIR . 'templates/_login-empty-state.php'; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Section 5: reports/briefs + recommended next actions -->
                            <div class="ves-dash-grid">
                                <div class="ves-dash-section">
                                    <div class="ves-dash-section-head"><h3>Reportes y briefs</h3><a class="fiis-section-link" href="?fiis_page=reports">Ver todos</a></div>
                                    <div class="ves-empty-state" style="padding:22px">Aún no hay reportes guardados. Ejecuta una herramienta para generar el primero.</div>
                                </div>
                                <div class="ves-dash-section">
                                    <div class="ves-dash-section-head"><h3>Próximas acciones recomendadas</h3></div>
                                    <ul class="fiis-next-actions">
                                        <li><a href="?fiis_page=knowledge"><span class="fiis-na-ico">＋</span><span><strong>Completa el contexto de marca</strong><span>Mejora la calidad del análisis AI en cada herramienta.</span></span></a></li>
                                        <li><a href="?fiis_page=seo"><span class="fiis-na-ico">⌕</span><span><strong>Lanza un análisis SEO</strong><span>Investiga keywords, dominios y competidores.</span></span></a></li>
                                        <li><a href="?fiis_page=deep-trend"><span class="fiis-na-ico">↗</span><span><strong>Explora tendencias</strong><span>Cruza fuentes en vivo con Deep Trend Finder.</span></span></a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============ KNOWLEDGE PAGE ============ -->
                <section class="ves-page" data-page="knowledge" role="tabpanel" aria-label="Knowledge base" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title">Knowledge base</h2>
                                <p class="ves-page-sub">Contexto, documentos y referencias que alimentan los análisis AI del workspace.</p>
                            </div>
                        </div>

                        <?php if (!$is_logged_in) : ?>
                            <?php include VES_PLUGIN_DIR . 'templates/_login-empty-state.php'; ?>
                        <?php else : ?>
                            <div class="ves-knowledge-grid-2col">
                                <div class="ves-card ves-project-context-card">
                                    <div class="ves-item-panel-head">
                                        <div>
                                            <div class="ves-item-panel-title">AI Project Context</div>
                                            <div class="ves-meta">Marca, mercado, tono y objetivo. Personaliza cada análisis cuando aporta señal real.</div>
                                        </div>
                                        <span class="ves-context-status" data-context-status hidden>
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span>Guardado</span>
                                        </span>
                                    </div>
                                    <div class="ves-project-context-form">
                                        <div class="ves-row">
                                            <div class="ves-field"><label class="ves-label">Marca</label><input class="ves-input" type="text" name="brand_name" placeholder="Your brand"></div>
                                            <div class="ves-field"><label class="ves-label">Web</label><input class="ves-input" type="url" name="website_url" placeholder="https://..."></div>
                                        </div>
                                        <div class="ves-row">
                                            <div class="ves-field"><label class="ves-label">Resumen de marca / negocio</label><textarea class="ves-textarea" name="brand_summary"></textarea></div>
                                            <div class="ves-field"><label class="ves-label">Productos / servicios</label><textarea class="ves-textarea" name="products_services"></textarea></div>
                                        </div>
                                        <div class="ves-row">
                                            <div class="ves-field"><label class="ves-label">Audiencia objetivo</label><textarea class="ves-textarea" name="target_audience"></textarea></div>
                                            <div class="ves-field"><label class="ves-label">Objetivo principal</label><textarea class="ves-textarea" name="primary_goal"></textarea></div>
                                        </div>
                                        <div class="ves-row">
                                            <div class="ves-field"><label class="ves-label">Tono / voz</label><input class="ves-input" type="text" name="tone_of_voice"></div>
                                            <div class="ves-field"><label class="ves-label">Mercados / países clave</label><input class="ves-input" type="text" name="markets"></div>
                                        </div>
                                        <div class="ves-row">
                                            <div class="ves-field"><label class="ves-label">Competidores / referencias</label><textarea class="ves-textarea" name="competitors"></textarea></div>
                                            <div class="ves-field"><label class="ves-label">Notas estratégicas</label><textarea class="ves-textarea" name="notes"></textarea></div>
                                        </div>
                                        <div class="ves-toolbar" style="margin-top:14px">
                                            <button type="button" class="ves-btn ves-btn-primary ves-project-context-save">Guardar contexto</button>
                                            <button type="button" class="ves-btn ves-btn-secondary ves-project-context-refresh">Recargar</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="ves-card ves-knowledge-card">
                                    <div class="ves-item-panel-head">
                                        <div>
                                            <div class="ves-item-panel-title">Workspace Knowledge</div>
                                            <div class="ves-meta">Documentos, URLs y conocimiento estructurado.</div>
                                        </div>
                                        <div class="ves-meta">Soporta <?php echo esc_html($knowledge_exts); ?></div>
                                    </div>
                                    <div class="ves-knowledge-tools">
                                        <div class="ves-knowledge-block">
                                            <div class="ves-mini-title">Subir archivo</div>
                                            <div class="ves-row">
                                                <div class="ves-field"><label class="ves-label">Título opcional</label><input class="ves-input" type="text" name="knowledge_title"></div>
                                                <div class="ves-field"><label class="ves-label">Archivo</label><input class="ves-input" type="file" name="knowledge_file" accept="<?php echo esc_attr($knowledge_exts); ?>"></div>
                                            </div>
                                            <div class="ves-toolbar"><button type="button" class="ves-btn ves-btn-primary ves-knowledge-upload">Subir archivo</button></div>
                                        </div>
                                        <div class="ves-knowledge-block">
                                            <div class="ves-mini-title">Ingerir URL / perfil</div>
                                            <div class="ves-row">
                                                <div class="ves-field"><label class="ves-label">URL</label><input class="ves-input" type="url" name="knowledge_url"></div>
                                                <div class="ves-field"><label class="ves-label">Título opcional</label><input class="ves-input" type="text" name="knowledge_url_title"></div>
                                            </div>
                                            <div class="ves-toolbar"><button type="button" class="ves-btn ves-btn-primary ves-knowledge-ingest">Ingerir URL</button></div>
                                        </div>
                                    </div>
                                    <div class="ves-knowledge-search-bar">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                                        <input class="ves-knowledge-search-input" type="search" placeholder="Buscar en assets…" aria-label="Buscar en knowledge assets">
                                    </div>
                                    <div class="ves-knowledge-list"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ============ USAGE PAGE ============ -->
                <section class="ves-page" data-page="usage" role="tabpanel" aria-label="Usage" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title">Usage</h2>
                                <p class="ves-page-sub">Créditos, plan actual y consumo por período. Monitores activos y eventos recientes.</p>
                            </div>
                        </div>
                        <?php if ($is_logged_in && class_exists('VES_Usage_Billing')) : ?>
                            <?php echo VES_Usage_Billing::render_account_summary(get_current_user_id()); ?>
                        <?php else : ?>
                            <?php include VES_PLUGIN_DIR . 'templates/_login-empty-state.php'; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ============ BRIEF LIBRARY PAGE ============ -->
                <section class="ves-page" data-page="brief-library" role="tabpanel" aria-label="Brief Library" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title">Brief Library</h2>
                                <p class="ves-page-sub">Briefs generados desde oportunidades aprobadas o shortlisted. Este es el seed del futuro Brief Board.</p>
                            </div>
                            <div class="ves-page-head-actions">
                                <button type="button" class="ves-btn ves-btn-secondary ves-brief-library-refresh">Recargar</button>
                            </div>
                        </div>
                        <?php if (!$is_logged_in) : ?>
                            <?php include VES_PLUGIN_DIR . 'templates/_login-empty-state.php'; ?>
                        <?php else : ?>
                            <form class="ves-form ves-brief-library-form" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;" hidden>
                                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                            </form>
                            <div class="ves-card ves-brief-library-controls">
                                <div class="ves-row">
                                    <div class="ves-field"><label class="ves-label">Estado</label><select class="ves-select" name="briefLibraryStatus"><option value="">Todos</option><option value="draft">Draft</option><option value="reviewed">Reviewed</option><option value="approved">Approved</option><option value="archived">Archived</option></select></div>
                                    <div class="ves-field"><label class="ves-label">Tipo</label><select class="ves-select" name="briefLibraryType"><option value="">Todos</option><option value="campaign">Campaign</option><option value="content">Content</option><option value="social">Social</option><option value="search">Search</option><option value="creative">Creative</option></select></div>
                                </div>
                            </div>
                            <div class="ves-brief-library-host" data-brief-library-host aria-live="polite"><div class="ves-card"><div class="ves-hint">Abre la biblioteca para cargar briefs recientes.</div></div></div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ============ TREND FINDER (canonical route, deep-trend engine) ============ -->
                <section class="ves-page" data-page="deep-trend" role="tabpanel" aria-label="Trend Finder" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div>
                                <h2 class="ves-page-title" data-eyebrow="Search &amp; Discovery">Trend Finder</h2>
                                <p class="ves-page-sub">Investigación de tendencias con evidencia real cruzando múltiples fuentes en un solo workspace.</p>
                            </div>
                            <?php if ($ves_deep_trend_addon_active && $ves_deep_trend_addon_url !== '') : ?>
                            <div class="ves-page-head-actions">
                                <a class="ves-btn ves-btn-primary" href="<?php echo esc_url($ves_deep_trend_addon_url); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    <?php esc_html_e('Abrir workspace', 'ves'); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="ves-dash-stats">
                            <div class="ves-stat-card"><div class="ves-stat-label">Fuentes</div><div class="ves-stat-value" style="font-size:15px">TikTok · Reddit · YouTube · Trends</div></div>
                            <div class="ves-stat-card"><div class="ves-stat-label">Análisis</div><div class="ves-stat-value" style="font-size:15px">Síntesis AI + brief accionable</div></div>
                            <div class="ves-stat-card"><div class="ves-stat-label">Workspace</div><div class="ves-stat-value" style="font-size:15px">Runs, evidencia y reportes</div></div>
                        </div>

                        <div class="ves-dash-grid">
                            <div class="ves-dash-section">
                                <div class="ves-dash-section-head"><h3>Cómo funciona el workflow</h3></div>
                                <ol class="fiis-stages">
                                    <li class="fiis-stage"><span class="fiis-stage-num">1</span><div><strong>Recolección</strong><span>Cruza fuentes en vivo: TikTok, Reddit, YouTube, Google Trends y News.</span></div></li>
                                    <li class="fiis-stage"><span class="fiis-stage-num">2</span><div><strong>Evidencia</strong><span>Normaliza y puntúa señales, guardando cada fuente como evidencia citable.</span></div></li>
                                    <li class="fiis-stage"><span class="fiis-stage-num">3</span><div><strong>Síntesis AI</strong><span>Genera relevancia, calidad y un brief estratégico accionable.</span></div></li>
                                    <li class="fiis-stage"><span class="fiis-stage-num">4</span><div><strong>Reporte</strong><span>Entrega un informe navegable con oportunidades y limitaciones.</span></div></li>
                                </ol>
                            </div>
                            <div class="ves-dash-section">
                                <div class="ves-dash-section-head"><h3>Estado de fuentes</h3></div>
                                <ul class="fiis-readiness">
                                    <li><span class="fiis-dot is-ok"></span>TikTok <span class="fiis-ready-tag">Listo</span></li>
                                    <li><span class="fiis-dot is-ok"></span>Reddit <span class="fiis-ready-tag">Listo</span></li>
                                    <li><span class="fiis-dot is-ok"></span>YouTube <span class="fiis-ready-tag">Listo</span></li>
                                    <li><span class="fiis-dot is-ok"></span>Google Trends <span class="fiis-ready-tag">Listo</span></li>
                                    <li><span class="fiis-dot is-warn"></span>Instagram <span class="fiis-ready-tag is-warn">Parcial</span></li>
                                </ul>
                                <?php if ($ves_deep_trend_addon_active && $ves_deep_trend_addon_url !== '') : ?>
                                <a class="ves-btn ves-btn-primary" style="margin-top:14px;width:100%;justify-content:center" href="<?php echo esc_url($ves_deep_trend_addon_url); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    <?php esc_html_e('Iniciar Deep Trend Finder', 'ves'); ?>
                                </a>
                                <p class="fiis-next-note">Se abrirá el workspace dedicado con tu historial de runs y reportes.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ves-dash-section">
                            <div class="ves-dash-section-head"><h3>Runs recientes</h3><?php if ($ves_deep_trend_addon_active && $ves_deep_trend_addon_url !== '') : ?><a class="fiis-section-link" href="<?php echo esc_url($ves_deep_trend_addon_url); ?>">Abrir workspace</a><?php endif; ?></div>
                            <div class="ves-empty-state" style="padding:22px">Aún no has ejecutado un Deep Trend run. Tus runs, su evidencia y sus reportes aparecerán aquí una vez ejecutes el primero desde el workspace.</div>
                        </div>

                        <?php if (!($ves_deep_trend_addon_active && $ves_deep_trend_addon_url !== '')) : ?>
                            <div class="ves-empty-state">El módulo Deep Trend Finder no está activo. Actívalo en Ajustes para usar esta ruta como primera clase dentro de la app.</div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ============ PROJECTS (workspace) ============ -->
                <section class="ves-page" data-page="projects" role="tabpanel" aria-label="Projects" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div><h2 class="ves-page-title" data-eyebrow="Workspace">Projects</h2><p class="ves-page-sub">Organiza marcas, mercados y contextos. Cada proyecto agrupa runs, memoria y reportes.</p></div>
                            <div class="ves-page-head-actions"><span class="fiis-soon-pill">Próximamente</span></div>
                        </div>
                        <div class="ves-dash-section">
                            <div class="ves-dash-section-head"><h3>Proyecto activo</h3></div>
                            <div class="fiis-project-card">
                                <span class="fiis-project-mark">DP</span>
                                <div><strong>Default Project</strong><span>Todos tus runs, memoria y reportes se guardan aquí.</span></div>
                                <span class="ves-badge is-ok">Activo</span>
                            </div>
                        </div>
                        <div class="fiis-coming-soon">
                            <h3>Multi-proyecto en camino</h3>
                            <p>Pronto podrás crear varios proyectos para separar marcas, clientes o mercados, cada uno con su propio contexto y librería de evidencia.</p>
                            <a class="ves-btn ves-btn-secondary" href="?fiis_page=knowledge">Configurar contexto del proyecto</a>
                        </div>
                    </div>
                </section>

                <!-- ============ REPORTS (outputs) ============ -->
                <section class="ves-page" data-page="reports" role="tabpanel" aria-label="Reports" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div><h2 class="ves-page-title" data-eyebrow="Outputs">Reports</h2><p class="ves-page-sub">Biblioteca de reportes generados a partir de tus runs de inteligencia.</p></div>
                            <div class="ves-page-head-actions"><span class="fiis-soon-pill">Próximamente</span></div>
                        </div>
                        <div class="ves-empty-state fiis-library-empty">
                            <div class="fiis-empty-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/></svg></div>
                            <h3>Tu biblioteca de reportes está vacía</h3>
                            <p>Cada ejecución de SEO, Trend Finder, Brand Audit o Deep Trend genera un reporte navegable que se archivará aquí para reabrir, exportar o compartir.</p>
                            <div class="ves-empty-actions">
                                <a class="ves-btn ves-btn-primary" href="?fiis_page=seo">Generar un reporte</a>
                                <a class="ves-btn ves-btn-secondary" href="?fiis_page=brief-library">Ver Brief Library</a>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============ EVIDENCE LIBRARY (outputs) ============ -->
                <section class="ves-page" data-page="evidence" role="tabpanel" aria-label="Evidence Library" hidden>
                    <div class="ves-page-inner">
                        <div class="ves-page-head">
                            <div><h2 class="ves-page-title" data-eyebrow="Outputs">Evidence Library</h2><p class="ves-page-sub">Fuentes y evidencia recolectadas en tus runs, listas para auditar o citar en briefs.</p></div>
                            <div class="ves-page-head-actions"><span class="fiis-soon-pill">Próximamente</span></div>
                        </div>
                        <div class="ves-empty-state fiis-library-empty">
                            <div class="fiis-empty-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg></div>
                            <h3>La evidencia se consolidará aquí</h3>
                            <p>Cada fuente capturada en un run —posts, métricas, URLs y snippets— se guarda como evidencia citable. Esta librería las reunirá de forma transversal a tus proyectos.</p>
                            <div class="ves-empty-actions">
                                <a class="ves-btn ves-btn-primary" href="?fiis_page=memory">Ver evidencia por run en Memory</a>
                            </div>
                        </div>
                    </div>
                </section>
        </main>

        <!-- ============ IN-APP FOOTER / UTILITY STRIP ============ -->
        <footer class="ves-app-footer" role="contentinfo">
            <span class="ves-app-footer-build">Future Island Intelligence Suite · v<?php echo esc_html(defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : ''); ?></span>
            <nav class="ves-app-footer-links" aria-label="Utilidades">
                <span class="ves-footer-status"><span class="ves-workspace-status-dot" aria-hidden="true" style="display:inline-block;margin-right:5px"></span>Operativo</span>
                <a href="#" data-ves-help>Ayuda</a>
                <a href="#" data-ves-help>Docs</a>
            </nav>
        </footer>
    </div>

    <!-- ============ COMMAND PALETTE ============ -->
    <div class="ves-cmdk" role="dialog" aria-modal="true" aria-label="Command palette" hidden>
        <div class="ves-cmdk-backdrop" data-cmdk-close></div>
        <div class="ves-cmdk-panel">
            <div class="ves-cmdk-input-wrap">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <input type="search" class="ves-cmdk-input" placeholder="Buscar acciones, plataformas, páginas…" aria-label="Buscar">
                <kbd class="ves-cmdk-esc">Esc</kbd>
            </div>
            <div class="ves-cmdk-results" role="listbox"></div>
            <div class="ves-cmdk-foot">
                <span><kbd>↑</kbd><kbd>↓</kbd> navegar</span>
                <span><kbd>↵</kbd> seleccionar</span>
                <span><kbd>Esc</kbd> cerrar</span>
            </div>
        </div>
    </div>

    <!-- ============ TOAST CONTAINER ============ -->
    <div class="ves-toast-host" aria-live="polite" aria-atomic="true"></div>
</div>
