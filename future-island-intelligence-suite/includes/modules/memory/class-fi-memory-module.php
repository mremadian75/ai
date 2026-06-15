<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Memory_Module — memory candidates + human review decisions. */
final class FI_Memory_Module extends FI_Abstract_Module {

    const PAGE_SLUG = 'fi-memory';

    public function id(): string { return 'memory'; }
    public function label(): string { return 'Memory'; }
    public function description(): string {
        return 'Reviewable continuity candidates with approve/reject decisions, source references and workspace scope.';
    }
    public function nav(): array { return ['type' => 'page', 'slug' => self::PAGE_SLUG]; }
    public function status(): array { return FI_Memory_Service::status(); }
    public function service_class(): string { return 'FI_Memory_Service'; }
    public function renderer_class(): string { return 'FI_Memory_Renderer'; }
    public function actions(): array { return [FI_Memory_Service::ACTION_APPROVE, FI_Memory_Service::ACTION_REJECT]; }

    public function register(): void {
        FI_Memory_Service::register_actions();
    }

    public function render(): void {
        if (function_exists('current_user_can') && !current_user_can($this->capability())) { return; }
        echo FI_Memory_Renderer::render_html();
    }
}
