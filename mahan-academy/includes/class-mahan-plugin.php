<?php
/**
 * Plugin orchestrator: lifecycle hooks and runtime wiring.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Plugin {

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	public static function activate() {
		Mahan_DB::install();
		Mahan_Settings::install_defaults();
		self::ensure_app_page();

		// Make the CPT permalinks work right away.
		Mahan_CPT::register();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Create the academy page (with the shortcode) on first activation.
	 */
	private static function ensure_app_page() {
		$existing = (int) Mahan_Settings::get( 'app_page_id', 0 );
		if ( $existing && get_post( $existing ) ) {
			return;
		}

		// Avoid duplicates if a page already contains the shortcode.
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				's'              => '[' . Mahan_Front::SHORTCODE . ']',
			)
		);
		if ( ! empty( $found ) ) {
			Mahan_Settings::set( 'app_page_id', (int) $found[0] );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Academy', 'mahan-academy' ),
				'post_name'    => 'academy',
				'post_content' => '<!-- wp:shortcode -->[' . Mahan_Front::SHORTCODE . ']<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			Mahan_Settings::set( 'app_page_id', (int) $page_id );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Runtime                                                             */
	/* ------------------------------------------------------------------ */

	public static function init() {
		load_plugin_textdomain( 'mahan-academy', false, dirname( MAHAN_BASENAME ) . '/languages' );

		Mahan_DB::maybe_upgrade();
		Mahan_Settings::install_defaults();

		Mahan_CPT::init();
		Mahan_REST::init();
		Mahan_AI_Stream::init();
		Mahan_Front::init();

		if ( is_admin() ) {
			Mahan_Admin::init();
		}
	}
}
