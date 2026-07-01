<?php
/**
 * Uninstall handler. Runs only when the user explicitly deletes the plugin
 * from the WP plugins screen.
 *
 * We only drop tables / options if the site admin opted in by defining
 * MAHAN_REMOVE_ALL_DATA = true (or by setting the mahan_remove_all_data
 * option to 1 from a one-off script). Otherwise we leave the data alone so
 * a re-install is non-destructive.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$remove = ( defined( 'MAHAN_REMOVE_ALL_DATA' ) && MAHAN_REMOVE_ALL_DATA )
	|| (int) get_option( 'mahan_remove_all_data', 0 ) === 1;

if ( ! $remove ) {
	return;
}

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'mahan_enrollments',
	$wpdb->prefix . 'mahan_progress',
	$wpdb->prefix . 'mahan_attempts',
	$wpdb->prefix . 'mahan_stats',
	$wpdb->prefix . 'mahan_chat',
	$wpdb->prefix . 'mahan_ai_cache',
);
foreach ( $tables as $t ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}

// Delete options.
$options = array(
	'mahan_ai_provider', 'mahan_anthropic_key', 'mahan_anthropic_model',
	'mahan_openai_key', 'mahan_openai_model', 'mahan_google_key', 'mahan_google_model',
	'mahan_max_tokens', 'mahan_temperature', 'mahan_tutor_system_prompt',
	'mahan_gate_enabled', 'mahan_xp_per_lesson', 'mahan_xp_per_exercise',
	'mahan_streak_enabled', 'mahan_hearts_enabled', 'mahan_hearts_max',
	'mahan_level_curve', 'mahan_primary_color', 'mahan_accent_color',
	'mahan_theme', 'mahan_custom_css', 'mahan_app_page_id',
	'mahan_ai_cache_ttl', 'mahan_debug', 'mahan_profile_schema',
	'mahan_db_version', 'mahan_remove_all_data',
	// Gamification & courses (1.1.0).
	'mahan_badges_enabled', 'mahan_leaderboard_enabled', 'mahan_level_titles', 'mahan_certificate_enabled',
	// Emails (1.2.0).
	'mahan_emails_enabled', 'mahan_email_from_name', 'mahan_email_from_email',
	'mahan_email_welcome', 'mahan_email_complete', 'mahan_email_badge', 'mahan_email_streak',
	'mahan_email_welcome_subject', 'mahan_email_welcome_body',
	'mahan_email_complete_subject', 'mahan_email_complete_body',
	'mahan_email_badge_subject', 'mahan_email_badge_body',
	'mahan_email_streak_subject', 'mahan_email_streak_body',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Delete user meta.
foreach ( array( 'mahan_profile', 'mahan_badges' ) as $umeta ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", $umeta ) );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'mahan_daily_cron' );

// Delete CPTs.
foreach ( array( 'mahan_course', 'mahan_lesson', 'mahan_path' ) as $cpt ) {
	$posts = get_posts(
		array(
			'post_type'      => $cpt,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}
