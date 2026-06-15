<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Asset_Studio_Module — brief → platform-ready output fields. */
final class FI_Asset_Studio_Module extends FI_Abstract_Module {

    const PAGE_SLUG = 'fi-asset-studio';

    public function id(): string { return 'asset_studio'; }
    public function label(): string { return 'Asset Studio'; }
    public function description(): string {
        return 'Google Ads asset blocks from approved briefs: 5/5/5 fields with limits, caveat, proof-needed note and copyable text.';
    }
    public function nav(): array { return ['type' => 'page', 'slug' => self::PAGE_SLUG]; }
    public function status(): array { return FI_Asset_Studio_Service::status(); }
    public function service_class(): string { return 'FI_Asset_Studio_Service'; }
    public function renderer_class(): string { return 'FI_Asset_Studio_Renderer'; }
    public function actions(): array {
        // The studio reuses the audited intake actions (no parallel handlers).
        return class_exists('VES_Source_Intake')
            ? [VES_Source_Intake::ACTION_DRAFT, VES_Source_Intake::ACTION_APPROVE_DRAFT, VES_Source_Intake::ACTION_MEMORY, VES_Source_Intake::ACTION_USAGE]
            : [];
    }

    public function render(): void {
        if (function_exists('current_user_can') && !current_user_can($this->capability())) { return; }
        echo FI_Asset_Studio_Renderer::render_html();
    }
}
