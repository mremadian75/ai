<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Trend_Finder_Module — the ONE canonical Trend Finder entry.
 *
 * Canonical engine: Deep Trend Finder (FIDTF_*). The legacy core trend page
 * ('trend' route in the SaaS app) is deprecated: its route now aliases to the
 * canonical page and its nav entry is gone (see FUTUREISLAND_MIGRATION_NOTES.md).
 */
final class FI_Trend_Finder_Module extends FI_Abstract_Module {

    const PAGE_SLUG = 'fi-trend-finder';

    public function id(): string { return 'trend_finder'; }
    public function label(): string { return 'Trend Finder'; }
    public function description(): string {
        return 'Multi-source trend engine: live collection, evidence quality, claim-readiness and a report artifact.';
    }
    public function nav(): array { return ['type' => 'page', 'slug' => self::PAGE_SLUG]; }
    public function status(): array { return FI_Trend_Finder_Service::status(); }
    public function service_class(): string { return 'FI_Trend_Finder_Service'; }
    public function renderer_class(): string { return 'FI_Trend_Finder_Renderer'; }

    public function render(): void {
        if (function_exists('current_user_can') && !current_user_can($this->capability())) { return; }
        echo FI_Trend_Finder_Renderer::render_html();
    }
}
