<?php
/**
 * v1.1.0 — Unified app architecture verification.
 *
 * Run: php tests/test-v110-unified-app.php
 *
 * Covers the full testing checklist for the unified-shell correction:
 * one canonical shell, legacy shortcodes as aliases, one route map, one CSS
 * design system (no competing layers), JS-hook preservation, scoping, mobile
 * + responsive + dense-result safety.
 */
$root = dirname(__DIR__);
$tpl    = (string) file_get_contents($root . '/templates/shortcode.php');
$js     = (string) file_get_contents($root . '/assets/js/ves-frontend.js');
$sc     = (string) file_get_contents($root . '/includes/class-ves-shortcode.php');
$router = (string) file_get_contents($root . '/includes/class-ves-app-router.php');
$assets = (string) file_get_contents($root . '/includes/class-ves-assets.php');
$css    = (string) file_get_contents($root . '/assets/css/fiis-app.css');

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) { if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; } };

// ── 1. ONE canonical shell + single-shell guard ─────────────────────────
$ok(strpos($sc, "add_shortcode('ves_intelligence_suite'") !== false, 'canonical shortcode [ves_intelligence_suite] registered');
$ok(strpos($sc, 'private static $rendered_full') !== false, 'single-shell guard flag present');
$ok(strpos($sc, 'self::$rendered_full = true') !== false, 'guard is set after render');
$ok(substr_count($tpl, "class=\"ves-wrap fiis-app") >= 1, 'shell root carries unified .fiis-app class');

// ── 2. Legacy shortcodes are ALIASES into render_app (not separate shells) ─
foreach (['ves_dashboard'=>'dashboard','ves_social'=>'social','ves_seo'=>'seo',
          'ves_linkedin'=>'linkedin','ves_brand_audit'=>'brand-audit','ves_ads'=>'ads',
          'ves_creative'=>'creative','ves_google_intel'=>'google','ves_trend'=>'trend',
          'ves_memory'=>'memory','vietnam_social_scraper'=>'social'] as $tag=>$route) {
    $ok(strpos($sc, "'$tag'") !== false && strpos($sc, "'$route'") !== false, "alias present: $tag → $route");
}
$ok(strpos($sc, 'render_app(') !== false, 'aliases route through render_app()');
$ok(strpos($sc, "add_shortcode('fiis_section'") !== false, 'section-mode shortcode [fiis_section] registered');

// ── 3. ONE unified route map (no panel/scrapers split) ──────────────────
$ok(strpos($js, 'const APP_PAGES = new Set(') !== false, 'JS has unified APP_PAGES set');
$ok(strpos($js, "widget.dataset.shellMode === 'panel'") === false, 'JS no longer branches on shellMode');
$ok(preg_match('/widgetPagesFor\s*=\s*\(widget\)\s*=>\s*isProductionMvpWidget/', $js) === 1, 'widgetPagesFor returns one unified set');
$ok(strpos($router, 'const ROUTES') !== false || strpos($router, 'ROUTES = [') !== false, 'PHP route map (VES_App_Router::ROUTES) exists');
$ok(strpos($assets, 'window.FIIS_ROUTES') !== false, 'route map localized to JS (FIIS_ROUTES)');
foreach (['dashboard','social','linkedin','seo','google','deep-trend','brand-audit',
          'ads','creative','memory','knowledge','usage','reports','brief-library','evidence','projects'] as $r) {
    $ok(strpos($router, "'$r'") !== false, "route in map: $r");
}
// v0.4.0 — ONE canonical Trend Finder: the legacy 'trend' route is an alias
// to 'deep-trend', never a standalone route, and the canonical label is
// 'Trend Finder' (no 'Deep Trend Finder' duplicate entry).
$ok(strpos($router, "'trend'        => 'deep-trend'") !== false, "legacy 'trend' route aliases to the canonical Trend Finder");
$ok(preg_match("/'trend'\s*=>\s*\['group'/", $router) !== 1, "'trend' is not a standalone route anymore");
$ok(substr_count($router, "'label' => 'Trend Finder'") === 1, 'router has exactly one Trend Finder label');
$ok(strpos($router, "'label' => 'Deep Trend Finder'") === false, "no separate 'Deep Trend Finder' nav label remains");

// ── 4. No duplicate app shells: each route section appears exactly once ──
foreach (['dashboard','social','linkedin','seo','google','deep-trend','brand-audit',
          'ads','creative','memory','knowledge','usage','brief-library','reports','evidence','projects'] as $r) {
    $ok(substr_count($tpl, 'data-page="' . $r . '"') === 1, "single section for route: $r");
}
// v0.4.0 — the legacy 'trend' page section is gone (one Trend Finder page).
$ok(substr_count($tpl, 'data-page="trend"') === 0, "legacy 'trend' page section removed from the shell");
$ok(substr_count($tpl, "\$ves_nav('deeptrend', 'Trend Finder', 'deep-trend');") === 1, 'exactly one Trend Finder nav entry');
$ok(substr_count($tpl, 'ves-page is-active') === 1, 'exactly one initially-active page (dashboard)');

// ── 5. JS functional hooks preserved ────────────────────────────────────
foreach (['ves-form','ves-start','ves-abort','ves-status','ves-results','ves-report-container',
          'ves-tab','data-platform','ves-nav-item','ves-sidebar-collapse','ves-mobile-menu-toggle',
          'ves-user-avatar','ves-ui-language','data-page-label','data-credit-pill','data-run-state',
          'data-sidebar-memory'] as $hook) {
    $ok(strpos($tpl, $hook) !== false, "hook preserved: $hook");
}
foreach (['data-sidebar-memory','data-credit-pill','data-run-state','data-page-label'] as $u) {
    $ok(substr_count($tpl, $u) === 1, "querySelector hook unique: $u");
}

// ── 6. ONE CSS design system — competing layers removed ─────────────────
$ok(file_exists($root . '/assets/css/fiis-app.css'), 'fiis-app.css exists');
$ok(!file_exists($root . '/assets/css/ves-ux-enhancements.css'), 'ux-enhancements layer removed');
$ok(!file_exists($root . '/assets/css/ves-saas-shell.css'), 'saas-shell layer removed');
$ok(strpos($assets, "wp_enqueue_style('fiis-app')") !== false, 'fiis-app enqueued');
$ok(strpos($assets, "ves-ux-enhancements") === false && strpos($assets, "ves-saas-shell") === false, 'old layers de-registered');
// No second token palette: fiis-app must not redefine the core accent token.
$ok(preg_match('/--em\s*:\s*#/', $css) !== 1, 'fiis-app does not redefine the palette (single token system)');

// ── 7. CSS scoping — no unscoped global selectors ───────────────────────
$stripped = preg_replace('!/\*.*?\*/!s', '', $css);
$stripped = preg_replace('/@keyframes\s+[A-Za-z0-9_-]+\s*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $stripped);
$bad = []; $buf = '';
for ($i = 0, $n = strlen($stripped); $i < $n; $i++) {
    $ch = $stripped[$i];
    if ($ch === '{') {
        $sel = trim($buf); $buf = '';
        if ($sel === '' || $sel[0] === '@') continue;
        foreach (explode(',', $sel) as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/^\d+%$/', $part) || $part === 'from' || $part === 'to') continue;
            if (strpos($part, '.ves-wrap') === false && strpos($part, '.fidtf-shell') === false
                && strpos($part, '.fiis-app') === false && strpos($part, '.fiis-app-alias-note') === false) $bad[] = $part;
        }
    } elseif ($ch === '}') { $buf = ''; } else { $buf .= $ch; }
}
$ok(empty($bad), 'every CSS rule scoped' . (empty($bad) ? '' : ' — leaks: ' . implode(' | ', array_slice($bad,0,6))));

// ── 8. Mobile sidebar + responsive + dense-result safety ────────────────
$ok(strpos($css, 'is-sidebar-open') !== false && strpos($css, 'translateX(-100%)') !== false, 'mobile drawer present');
$ok(strpos($css, 'is-sidebar-collapsed') !== false, 'collapsed sidebar present');
$ok(preg_match('/@media\s*\(max-width:\s*1024px\)/', $css) === 1, 'tablet breakpoint present');
$ok(strpos($css, 'overflow-wrap: anywhere') !== false, 'overflow-wrap safety present');
$ok(strpos($css, 'minmax(0, 1fr)') !== false || strpos($css, 'min-width: 0') !== false, 'min-width:0 grid safety present');
$ok(strpos($css, 'ves-seo-table-wrap') !== false && strpos($css, 'overflow-x: auto') !== false, 'scrollable table wrapper present');
$ok(strpos($css, 'max-height: 420px') !== false, 'bounded raw-output container present');

// ── 9. v1.1.0 product-page refinement + hygiene ─────────────────────────
$ok(substr_count($tpl, 'fiis-results-region') >= 6, 'results/output region applied to tool pages');
$ok(strpos($tpl, 'fiis-region-empty') !== false, 'results region has empty state');
$ok(strpos($tpl, 'fiis-seo-scopes') !== false && strpos($tpl, 'fiis-seo-kpis') !== false, 'SEO page has scope chips + KPI strip');
$ok(strpos($tpl, 'fiis-stages') !== false && strpos($tpl, 'fiis-readiness') !== false, 'Deep Trend bridge has workflow stages + source readiness');
$ok(strpos($tpl, 'fiis-next-actions') !== false, 'Dashboard has recommended next actions');
$ok(strpos($tpl, 'ves-sidebar-credits') !== false, 'sidebar usage/credits mini block present');
// Hygiene: no stale layer/version language in the single CSS file.
$ok(strpos($css, 'UX Enhancement Layer') === false, 'CSS has no stale "UX Enhancement Layer" banner');
$ok(strpos($css, 'SaaS App Shell') === false, 'CSS has no stale "SaaS App Shell" banner');
$ok(strpos($css, 'v1.0.8') === false, 'CSS has no stale v1.0.8 reference');
// Preview hygiene (if the generated preview is shipped).
$prev = $root . '/preview/shell-preview.html';
if (file_exists($prev)) {
    $ph = (string) file_get_contents($prev);
    $ok(strpos($ph, 'v1.1.0') !== false, 'preview references v1.1.0');
    $ok(strpos($ph, 'v1.0.8') === false, 'preview has no stale v1.0.8 reference');
}

echo "\nPASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
