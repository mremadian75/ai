<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_App_Router — single source of truth for the unified app route map.
 *
 * v1.1.0: replaces the old "panel vs scrapers" split. One app, one route map.
 * Used to validate/normalise an initial route and to localise the route map to
 * the frontend JS (window.FIIS_ROUTES).
 */
final class VES_App_Router {

    /** route => [group, label]. Order defines sidebar order within a group. */
    const ROUTES = [
        'dashboard'     => ['group' => 'workspace', 'label' => 'Dashboard'],
        'projects'      => ['group' => 'workspace', 'label' => 'Projects'],
        'memory'        => ['group' => 'workspace', 'label' => 'Memory'],
        'knowledge'     => ['group' => 'workspace', 'label' => 'Knowledge Base'],
        'usage'         => ['group' => 'workspace', 'label' => 'Usage & Credits'],
        'social'        => ['group' => 'social',    'label' => 'Social Media'],
        'linkedin'      => ['group' => 'social',    'label' => 'LinkedIn'],
        'seo'           => ['group' => 'search',    'label' => 'SEO / SEM / AEO'],
        'google'        => ['group' => 'search',    'label' => 'Google Intelligence'],
        // v0.4.0: ONE canonical Trend Finder. The legacy 'trend' route (the old
        // core implementation, later a duplicate launcher) is an alias below —
        // old links land here, no duplicate page or nav entry remains.
        'deep-trend'    => ['group' => 'search',    'label' => 'Trend Finder'],
        'brand-audit'   => ['group' => 'brand',     'label' => 'Brand Audit'],
        'ads'           => ['group' => 'brand',     'label' => 'Ads Intelligence'],
        'creative'      => ['group' => 'brand',     'label' => 'Creative Intelligence'],
        'reports'       => ['group' => 'outputs',   'label' => 'Reports'],
        'brief-library' => ['group' => 'outputs',   'label' => 'Brief Library'],
        'evidence'      => ['group' => 'outputs',   'label' => 'Evidence Library'],
    ];

    /** Legacy route synonyms accepted from shortcode `page` attributes. */
    const ALIASES = [
        'brand'        => 'brand-audit',
        'brand_audit'  => 'brand-audit',
        'deeptrend'    => 'deep-trend',
        'deep_trend'   => 'deep-trend',
        'trend'        => 'deep-trend',
        'trend_finder' => 'deep-trend',
        'trend-finder' => 'deep-trend',
        'google_intel' => 'google',
        'briefs'       => 'brief-library',
        'brief'        => 'brief-library',
        'scrapers'     => 'dashboard',
        'panel'        => 'dashboard',
    ];

    public static function is_route(string $route): bool {
        return isset(self::ROUTES[$route]);
    }

    /** Normalise any incoming route/page string to a valid route (or ''). */
    public static function normalize(string $route): string {
        $route = strtolower(trim($route));
        $route = str_replace('_', '-', $route);
        if (isset(self::ALIASES[$route])) { $route = self::ALIASES[$route]; }
        return self::is_route($route) ? $route : '';
    }

    /** Map consumed by the JS as window.FIIS_ROUTES. */
    public static function js_map(): array {
        $out = [];
        foreach (self::ROUTES as $route => $cfg) {
            $out[$route] = ['group' => $cfg['group'], 'label' => $cfg['label']];
        }
        return $out;
    }
}
