<?php
/**
 * Plugin Name: Mahan Academy
 * Description: A standalone AI-learning platform for WordPress — Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor powered by Anthropic, OpenAI, or Google. No LMS dependency, no external automation services.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Mahan Academy
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mahan-academy
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAHAN_VERSION', '1.0.0' );
define( 'MAHAN_DB_VERSION', '1' );
define( 'MAHAN_FILE', __FILE__ );
define( 'MAHAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAHAN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAHAN_BASENAME', plugin_basename( __FILE__ ) );

require_once MAHAN_DIR . 'includes/class-mahan-logger.php';
require_once MAHAN_DIR . 'includes/class-mahan-utils.php';
require_once MAHAN_DIR . 'includes/class-mahan-db.php';
require_once MAHAN_DIR . 'includes/class-mahan-settings.php';
require_once MAHAN_DIR . 'includes/class-mahan-cpt.php';
require_once MAHAN_DIR . 'includes/class-mahan-profile.php';
require_once MAHAN_DIR . 'includes/class-mahan-courses.php';
require_once MAHAN_DIR . 'includes/class-mahan-gamification.php';
require_once MAHAN_DIR . 'includes/class-mahan-enrollment.php';
require_once MAHAN_DIR . 'includes/class-mahan-progress.php';
require_once MAHAN_DIR . 'includes/class-mahan-ai.php';
require_once MAHAN_DIR . 'includes/class-mahan-exercises.php';
require_once MAHAN_DIR . 'includes/class-mahan-ai-stream.php';
require_once MAHAN_DIR . 'includes/class-mahan-rest.php';
require_once MAHAN_DIR . 'includes/class-mahan-front.php';

if ( is_admin() ) {
	require_once MAHAN_DIR . 'includes/class-mahan-meta-boxes-course.php';
	require_once MAHAN_DIR . 'includes/class-mahan-meta-boxes-lesson.php';
	require_once MAHAN_DIR . 'includes/class-mahan-admin.php';
}

require_once MAHAN_DIR . 'includes/class-mahan-plugin.php';

register_activation_hook( __FILE__, array( 'Mahan_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mahan_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Mahan_Plugin', 'init' ) );
