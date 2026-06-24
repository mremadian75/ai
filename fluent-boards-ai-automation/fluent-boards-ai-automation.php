<?php
/**
 * Plugin Name: Fluent Boards AI Automation Add-on
 * Plugin URI: https://example.com/fluent-boards-ai-automation
 * Description: Internal WordPress AI automation and intelligence engine for the official Fluent Boards / Fluent Boards Pro plugin. Adds company memory, team context, smart AI recommendations, AI comments, subtasks, reminders, and digest emails without external automation tools.
 * Version: 0.9.4
 * Author: Mahan Emadian + ChatGPT
 * Text Domain: fluent-boards-ai-automation
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FBAIA_VERSION', '0.9.4');
define('FBAIA_FILE', __FILE__);
define('FBAIA_DIR', plugin_dir_path(__FILE__));
define('FBAIA_URL', plugin_dir_url(__FILE__));
define('FBAIA_OPTION', 'fbaia_settings');
define('FBAIA_LOG_OPTION', 'fbaia_logs');

require_once FBAIA_DIR . 'includes/class-fbaia-helpers.php';
require_once FBAIA_DIR . 'includes/class-fbaia-logger.php';
require_once FBAIA_DIR . 'includes/class-fbaia-knowledge-library.php';
require_once FBAIA_DIR . 'includes/class-fbaia-suggestion-store.php';
require_once FBAIA_DIR . 'includes/class-fbaia-audit-trail.php';
require_once FBAIA_DIR . 'includes/class-fbaia-usage-tracker.php';
require_once FBAIA_DIR . 'includes/class-fbaia-automation-playbooks.php';
require_once FBAIA_DIR . 'includes/class-fbaia-health-check.php';
require_once FBAIA_DIR . 'includes/class-fbaia-insights.php';
require_once FBAIA_DIR . 'includes/class-fbaia-exporter.php';
require_once FBAIA_DIR . 'includes/class-fbaia-context-builder.php';
require_once FBAIA_DIR . 'includes/class-fbaia-openai-client.php';
require_once FBAIA_DIR . 'includes/class-fbaia-fluentboards-adapter.php';
require_once FBAIA_DIR . 'includes/class-fbaia-internal-actions.php';
require_once FBAIA_DIR . 'includes/class-fbaia-runner.php';
require_once FBAIA_DIR . 'includes/class-fbaia-rest-controller.php';
require_once FBAIA_DIR . 'includes/class-fbaia-admin.php';
require_once FBAIA_DIR . 'includes/class-fbaia-plugin.php';

add_filter('cron_schedules', ['FBAIA_Plugin', 'cron_schedules']);

register_activation_hook(__FILE__, ['FBAIA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['FBAIA_Plugin', 'deactivate']);

add_action('plugins_loaded', ['FBAIA_Plugin', 'init'], 20);
