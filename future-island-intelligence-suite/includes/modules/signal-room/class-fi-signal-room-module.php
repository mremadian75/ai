<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Signal_Room_Module — navigation shell for the existing Signal Room
 * surface (tools.php?page=fi-signal-room, rendered by VES_Signal_Room).
 * Link entry: the canonical page stays where it is — no duplicate page.
 */
final class FI_Signal_Room_Module extends FI_Abstract_Module {

    public function id(): string { return 'signal_room'; }
    public function label(): string { return 'Signal Room'; }
    public function description(): string {
        return 'Live overview of the workspace loop: signals, insights, briefs and their review states.';
    }
    public function nav(): array { return ['type' => 'link', 'url' => 'tools.php?page=fi-signal-room']; }
    public function status(): array {
        if (!class_exists('VES_Signal_Room')) {
            return ['state' => 'unavailable', 'detail' => 'Signal Room renderer is not loaded.'];
        }
        return ['state' => 'available', 'detail' => ''];
    }
    public function renderer_class(): string { return 'VES_Signal_Room'; }
}
