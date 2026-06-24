<?php
/**
 * Plugin Name: Fluent Boards AI Automation Add-on
 * Plugin URI: https://example.com/fluent-boards-ai-automation
 * Description: Internal WordPress AI automation and intelligence engine for the official Fluent Boards / Fluent Boards Pro plugin. Adds company memory, team context, smart AI recommendations, AI comments, subtasks, reminders, and digest emails without external automation tools.
 * Version: 0.9.6
 * Author: Mahan Emadian + ChatGPT
 * Text Domain: fluent-boards-ai-automation
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FBAIA_VERSION', '0.9.6');
define('FBAIA_FILE', __FILE__);
define('FBAIA_DIR', plugin_dir_path(__FILE__));
define('FBAIA_URL', plugin_dir_url(__FILE__));
define('FBAIA_OPTION', 'fbaia_settings');
define('FBAIA_LOG_OPTION', 'fbaia_logs');

/**
 * PSR-style autoloader for the plugin's FBAIA_* classes.
 *
 * Replaces eager require_once of every class on every request. Each class file is loaded
 * only the first time the class is actually referenced, so a normal front-end page load
 * touches almost none of them — a real load-time win versus pulling in all ~18 files up front.
 * Mapping: FBAIA_OpenAI_Client -> includes/class-fbaia-openai-client.php
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'FBAIA_') !== 0) {
        return;
    }
    $slug = strtolower(str_replace('_', '-', substr($class, strlen('FBAIA_'))));
    $file = FBAIA_DIR . 'includes/class-fbaia-' . $slug . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

add_filter('cron_schedules', ['FBAIA_Plugin', 'cron_schedules']);

register_activation_hook(__FILE__, ['FBAIA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['FBAIA_Plugin', 'deactivate']);

add_action('plugins_loaded', ['FBAIA_Plugin', 'init'], 20);
