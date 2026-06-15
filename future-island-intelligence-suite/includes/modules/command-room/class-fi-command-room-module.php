<?php
if (!defined('ABSPATH')) { exit; }

final class FI_Command_Room_Module extends FI_Abstract_Module {
    public function id(): string { return 'command_room'; }
    public function label(): string { return 'Command Room'; }
    public function description(): string { return 'Guided self-serve surface: playbooks, evidence drawer, run timeline, memory and usage context.'; }
    public function nav(): array { return ['type' => 'page', 'slug' => 'fi-command-room']; }
    public function service_class(): string { return 'FI_Command_Room_Service'; }
    public function renderer_class(): string { return 'FI_Command_Room_Renderer'; }
    public function actions(): array { return class_exists('FI_Command_Room_Service') ? [FI_Command_Room_Service::ACTION_BRAND_XRAY, (class_exists('VES_Command_Room_Action_Service') ? VES_Command_Room_Action_Service::ACTION : 'fi_command_room_action')] : []; }
    public function register(): void {
        if (function_exists('add_action') && class_exists('FI_Command_Room_Service')) {
            add_action('admin_post_' . FI_Command_Room_Service::ACTION_BRAND_XRAY, ['FI_Command_Room_Service', 'handle_brand_xray']);
            if (class_exists('VES_Command_Room_Action_Service')) { add_action('admin_post_' . VES_Command_Room_Action_Service::ACTION, ['VES_Command_Room_Action_Service', 'handle_admin_post']); }
        }
    }
    public function status(): array {
        $missing = [];
        foreach (['VES_Workspace_Service','VES_Brand_Market_Xray_Playbook','VES_Run_Service','VES_Intelligence_Store'] as $class) { if (!class_exists($class)) { $missing[] = $class; } }
        if (!empty($missing)) { return ['state' => 'configuration_needed', 'detail' => 'Missing: ' . implode(', ', $missing)]; }
        return ['state' => 'available', 'detail' => 'Brand & Market X-Ray is runnable without external providers.'];
    }
    public function render(): void {
        if (function_exists('current_user_can') && !current_user_can($this->capability())) { return; }
        if (class_exists('VES_Assets')) { VES_Assets::enqueue(); }
        echo FI_Command_Room_Renderer::render_html();
    }
}
