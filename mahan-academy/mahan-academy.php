<?php
/**
 * Plugin Name: Mahan Academy
 * Description: A standalone AI-learning platform for WordPress — Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor powered by Anthropic, OpenAI, or Google. Visual course builder, AI authoring, unit quizzes, learning paths, achievements, email notifications, and admin analytics. No LMS dependency, no external automation services.
 * Version: 1.20.0
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

define( 'MAHAN_VERSION', '1.20.0' );
define( 'MAHAN_DB_VERSION', '4' );
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
require_once MAHAN_DIR . 'includes/class-mahan-personalization.php';
require_once MAHAN_DIR . 'includes/class-mahan-courses.php';
require_once MAHAN_DIR . 'includes/class-mahan-paths.php';
require_once MAHAN_DIR . 'includes/class-mahan-variants.php';
require_once MAHAN_DIR . 'includes/class-mahan-recommend.php';
require_once MAHAN_DIR . 'includes/class-mahan-seed.php';
require_once MAHAN_DIR . 'includes/class-mahan-gamification.php';
require_once MAHAN_DIR . 'includes/class-mahan-badges.php';
require_once MAHAN_DIR . 'includes/class-mahan-emails.php';
require_once MAHAN_DIR . 'includes/class-mahan-enrollment.php';
require_once MAHAN_DIR . 'includes/class-mahan-progress.php';
require_once MAHAN_DIR . 'includes/class-mahan-ai.php';
require_once MAHAN_DIR . 'includes/class-mahan-exercises.php';
require_once MAHAN_DIR . 'includes/class-mahan-quizzes.php';
require_once MAHAN_DIR . 'includes/class-mahan-reviews.php';
require_once MAHAN_DIR . 'includes/class-mahan-practice.php';
require_once MAHAN_DIR . 'includes/class-mahan-ai-stream.php';
require_once MAHAN_DIR . 'includes/class-mahan-rest.php';
require_once MAHAN_DIR . 'includes/class-mahan-front.php';

if ( is_admin() ) {
	require_once MAHAN_DIR . 'includes/class-mahan-meta-boxes-course.php';
	require_once MAHAN_DIR . 'includes/class-mahan-meta-boxes-lesson.php';
	require_once MAHAN_DIR . 'includes/class-mahan-meta-boxes-path.php';
	require_once MAHAN_DIR . 'includes/class-mahan-course-builder.php';
	require_once MAHAN_DIR . 'includes/class-mahan-ai-author.php';
	require_once MAHAN_DIR . 'includes/class-mahan-reports.php';
	require_once MAHAN_DIR . 'includes/class-mahan-admin.php';
}

require_once MAHAN_DIR . 'includes/class-mahan-plugin.php';

register_activation_hook( __FILE__, array( 'Mahan_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mahan_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Mahan_Plugin', 'init' ) );
