<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Settings_Module — navigation shell for the existing suite settings page
 * (options-general.php?page=ves-social-scraper). Link entry; the settings
 * page itself is not duplicated or moved.
 */
final class FI_Settings_Module extends FI_Abstract_Module {

    public function id(): string { return 'settings'; }
    public function label(): string { return 'Settings'; }
    public function description(): string {
        return 'Suite configuration: providers, credentials, limits and module settings.';
    }
    public function nav(): array { return ['type' => 'link', 'url' => 'options-general.php?page=ves-social-scraper']; }
    public function status(): array {
        if (!class_exists('VES_Admin')) {
            return ['state' => 'unavailable', 'detail' => 'Settings surface is not loaded.'];
        }
        return ['state' => 'available', 'detail' => ''];
    }
    public function renderer_class(): string { return 'VES_Admin'; }
}
