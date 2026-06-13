<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Usage_Ledger_Module — explainable usage events. */
final class FI_Usage_Ledger_Module extends FI_Abstract_Module {

    const PAGE_SLUG = 'fi-usage-ledger';

    public function id(): string { return 'usage_ledger'; }
    public function label(): string { return 'Usage Ledger'; }
    public function description(): string {
        return 'Explainable usage events with action, object trace, user, workspace and credit status.';
    }
    public function nav(): array { return ['type' => 'page', 'slug' => self::PAGE_SLUG]; }
    public function status(): array { return FI_Usage_Ledger_Service::status(); }
    public function service_class(): string { return 'FI_Usage_Ledger_Service'; }
    public function renderer_class(): string { return 'FI_Usage_Ledger_Renderer'; }

    public function render(): void {
        if (function_exists('current_user_can') && !current_user_can($this->capability())) { return; }
        echo FI_Usage_Ledger_Renderer::render_html();
    }
}
