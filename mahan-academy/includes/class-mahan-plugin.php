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

		// Ship the starter catalog by default, so the academy is never empty on
		// first visit (idempotent + one-time; never re-imposes if later removed).
		if ( class_exists( 'Mahan_Seed' ) ) {
			Mahan_Seed::maybe_autoseed();
		}

		flush_rewrite_rules();
	}

	public static function deactivate() {
		Mahan_Emails::clear_cron();
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

	/**
	 * Issue certificates for already-completed courses, once.
	 *
	 * Gated with an atomic add_option() rather than a get/set pair, so two
	 * concurrent requests on a busy site can't both run the sweep.
	 */
	public static function maybe_backfill_certificates() {
		if ( ! add_option( 'mahan_cert_backfill_done', '1', '', 'no' ) ) {
			return;
		}
		Mahan_Certificates::backfill();
	}

	/* ------------------------------------------------------------------ */
	/* Runtime                                                             */
	/* ------------------------------------------------------------------ */

	public static function init() {
		load_plugin_textdomain( 'mahan-academy', false, dirname( MAHAN_BASENAME ) . '/languages' );

		Mahan_DB::maybe_upgrade();
		Mahan_Settings::install_defaults();

		Mahan_CPT::init();
		Mahan_Badges::init();
		Mahan_Emails::init();
		Mahan_Certificates::init();
		Mahan_REST::init();
		Mahan_AI_Stream::init();
		Mahan_Front::init();

		// One-time back-fill for learners who completed courses before
		// certificates were recorded. Without it their only route to a
		// credential would be re-taking a course they've already finished.
		// Runs after the CPTs register so course titles resolve.
		add_action( 'init', array( __CLASS__, 'maybe_backfill_certificates' ), 20 );

		// Catch-up for sites that were already active before this version shipped
		// the starter catalog. Runs after the CPTs/taxonomies register (priority
		// 20 > CPT's 5) and self-guards to run its work at most once.
		add_action( 'init', array( 'Mahan_Seed', 'maybe_autoseed' ), 20 );

		// Sites that seeded before a later version added ladder wiring keep
		// their content but pick up the structural metadata. Runs after the
		// CPTs/taxonomies register, and self-guards to run once per version.
		add_action( 'init', array( 'Mahan_Seed', 'maybe_refresh_structure' ), 21 );

		if ( is_admin() ) {
			Mahan_Admin::init();
		}
	}
}
