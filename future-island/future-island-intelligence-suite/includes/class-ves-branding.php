<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Branding
 *
 * v0.9.24.75 — centralized helper for user-visible product/brand strings.
 *
 * The plugin historically hardcoded a previous-tenant product label across
 * templates, OpenAI assistant prompts, admin pages, and public-facing error
 * strings. For commercial SaaS use, any previous-tenant label must be removed
 * from the runtime surface.
 *
 * This helper provides filterable defaults so a host site can override the
 * surface labels without editing core files. All getters return strings that
 * are safe to use in user-visible UI (callers must still late-escape).
 *
 * Filters:
 *   ves_brand_product_name    — "Intelligence Suite" (default)
 *   ves_brand_workspace_name  — "Intelligence Workspace" (default)
 *   ves_brand_assistant_name  — "AI Assistant" (default)
 *   ves_brand_company_name    — "" (default, optional kicker)
 *
 * New installs and upgrades default to neutral SaaS labels.
 */
final class VES_Branding {

    /** Neutral SaaS-friendly product label. */
    public static function product_name() {
        $default = __('Intelligence Suite', 'vietnam-estudio-social-scraper');
        return (string) apply_filters('ves_brand_product_name', $default);
    }

    /** Workspace label used in headers, exports, and AI prompts. */
    public static function workspace_name() {
        $default = __('Intelligence Workspace', 'vietnam-estudio-social-scraper');
        return (string) apply_filters('ves_brand_workspace_name', $default);
    }

    /** Assistant label used in the side panel and AI system prompts. */
    public static function assistant_name() {
        $default = __('AI Assistant', 'vietnam-estudio-social-scraper');
        return (string) apply_filters('ves_brand_assistant_name', $default);
    }

    /** Optional company/parent label used in auth screens. */
    public static function company_name() {
        $default = '';
        return (string) apply_filters('ves_brand_company_name', $default);
    }

}
