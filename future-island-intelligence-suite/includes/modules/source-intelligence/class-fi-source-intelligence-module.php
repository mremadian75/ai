<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Source_Intelligence_Module — provider preflight + source intake doorway. */
final class FI_Source_Intelligence_Module extends FI_Abstract_Module {

    const PAGE_SLUG = 'fi-source-intelligence';

    public function id(): string { return 'source_intelligence'; }
    public function label(): string { return 'Source Intelligence'; }
    public function description(): string {
        return 'Provider/actor preflight truth, allowlist status, and the intake doorway for manual and URL sources.';
    }
    public function nav(): array { return ['type' => 'page', 'slug' => self::PAGE_SLUG]; }
    public function status(): array { return FI_Source_Intelligence_Service::status(); }
    public function service_class(): string { return 'FI_Source_Intelligence_Service'; }
    public function renderer_class(): string { return 'FI_Source_Intelligence_Renderer'; }

    public function render(): void {
        if (function_exists('current_user_can') && !current_user_can($this->capability())) { return; }
        echo FI_Source_Intelligence_Renderer::render_html();
    }
}
