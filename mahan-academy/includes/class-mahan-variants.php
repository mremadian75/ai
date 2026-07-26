<?php
/**
 * Level ladders and department ("field") variants.
 *
 * Two orthogonal axes make one course library serve very different learners:
 *
 *   LEVEL  — a real ladder of separate courses on the same subject
 *            (beginner → intermediate → advanced → expert). The curriculum
 *            genuinely differs per level, so each rung is its own course,
 *            linked by a shared `track` slug and ordered by `level_rank`.
 *
 *   FIELD  — the learner's department (marketing, finance, HR, management,
 *            sales, …). Duplicating every course per department would explode
 *            combinatorially, so a field is applied as a small OVERLAY on top of
 *            a lesson: a field-specific scenario, example, and practice angle
 *            authored once per lesson and swapped in at render time.
 *
 * Anything without an authored overlay still gets field specialisation from the
 * AI layer ({@see Mahan_Personalization::for_you()}), so every lesson adapts.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Variants {

	/** Course meta: the subject ladder this course belongs to. */
	const M_TRACK = '_mahan_track';

	/** Course meta: position on that ladder (1 = entry level). */
	const M_LEVEL_RANK = '_mahan_level_rank';

	/** Ordered proficiency tiers. Index + 1 is the level rank. */
	const LEVELS = array( 'beginner', 'intermediate', 'advanced', 'expert' );

	/**
	 * Department field keys we author variants for. `general` is the fallback
	 * used when the learner's role doesn't map to a specialised track.
	 */
	const FIELDS = array( 'marketing', 'sales', 'finance', 'hr', 'management', 'operations', 'engineering', 'product', 'general' );

	/**
	 * Profile `role` value → variant field. Roles that have no dedicated
	 * department angle fall through to `general`.
	 */
	const ROLE_FIELD = array(
		'marketing'   => 'marketing',
		'sales'       => 'sales',
		'finance'     => 'finance',
		'hr'          => 'hr',
		'management'  => 'management',
		'founder'     => 'management',
		'operations'  => 'operations',
		'engineering' => 'engineering',
		'product'     => 'product',
	);

	/* ------------------------------------------------------------------ */
	/* Levels                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalise a level string to one of LEVELS.
	 *
	 * @param string $level Raw level.
	 * @return string
	 */
	public static function normalize_level( $level ) {
		$level = strtolower( trim( (string) $level ) );
		return in_array( $level, self::LEVELS, true ) ? $level : 'beginner';
	}

	/**
	 * 1-based rank of a level on the ladder.
	 *
	 * @param string $level Level.
	 * @return int 1..4
	 */
	public static function level_rank( $level ) {
		$i = array_search( self::normalize_level( $level ), self::LEVELS, true );
		return ( false === $i ) ? 1 : ( (int) $i + 1 );
	}

	/**
	 * Human label for a level.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function level_label( $level ) {
		switch ( self::normalize_level( $level ) ) {
			case 'intermediate':
				return __( 'Intermediate', 'mahan-academy' );
			case 'advanced':
				return __( 'Advanced', 'mahan-academy' );
			case 'expert':
				return __( 'Expert', 'mahan-academy' );
			case 'beginner':
			default:
				return __( 'Beginner', 'mahan-academy' );
		}
	}

	/**
	 * All published courses on the same track, ordered by level rank — the
	 * "ladder" shown on a course page so a learner can pick their level.
	 *
	 * @param string $track Track slug.
	 * @return array[] list of { id, title, level, level_rank, subtitle }
	 */
	public static function track_ladder( $track ) {
		$track = sanitize_title( (string) $track );
		if ( '' === $track ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => Mahan_CPT::COURSE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'       => self::M_TRACK,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $track,
			)
		);
		$rungs = array();
		foreach ( (array) $ids as $id ) {
			$level   = Mahan_Utils::meta_str( $id, Mahan_Courses::M_LEVEL, 'beginner' );
			$rungs[] = array(
				'id'         => (int) $id,
				'title'      => get_the_title( $id ),
				'subtitle'   => Mahan_Utils::meta_str( $id, Mahan_Courses::M_SUBTITLE ),
				'level'      => self::normalize_level( $level ),
				'level_rank' => (int) Mahan_Utils::meta_int( $id, self::M_LEVEL_RANK, self::level_rank( $level ) ),
			);
		}
		usort(
			$rungs,
			function ( $a, $b ) {
				if ( $a['level_rank'] !== $b['level_rank'] ) {
					return $a['level_rank'] <=> $b['level_rank'];
				}
				return $a['id'] <=> $b['id'];
			}
		);
		return $rungs;
	}

	/* ------------------------------------------------------------------ */
	/* Fields (departments)                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * The variant field for a learner, from their profile role.
	 *
	 * @param int $user_id User id.
	 * @return string One of FIELDS.
	 */
	public static function field_for_user( $user_id ) {
		if ( ! class_exists( 'Mahan_Profile' ) ) {
			return 'general';
		}
		$profile = Mahan_Profile::get_profile( (int) $user_id );
		$role    = isset( $profile['role'] ) ? (string) $profile['role'] : '';
		return isset( self::ROLE_FIELD[ $role ] ) ? self::ROLE_FIELD[ $role ] : 'general';
	}

	/**
	 * Human label for a field.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	public static function field_label( $field ) {
		$labels = array(
			'marketing'   => __( 'Marketing', 'mahan-academy' ),
			'sales'       => __( 'Sales', 'mahan-academy' ),
			'finance'     => __( 'Finance', 'mahan-academy' ),
			'hr'          => __( 'HR & People', 'mahan-academy' ),
			'management'  => __( 'Management', 'mahan-academy' ),
			'operations'  => __( 'Operations', 'mahan-academy' ),
			'engineering' => __( 'Engineering', 'mahan-academy' ),
			'product'     => __( 'Product', 'mahan-academy' ),
			'general'     => __( 'General', 'mahan-academy' ),
		);
		$field = (string) $field;
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $labels['general'];
	}

	/* ------------------------------------------------------------------ */
	/* Applying a field variant to lesson content                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Pick the best variant block for a field, falling back to `general`.
	 *
	 * @param array  $variants field => block map.
	 * @param string $field    Desired field.
	 * @return array|null The chosen block, or null when there's nothing to apply.
	 */
	public static function pick( $variants, $field ) {
		if ( ! is_array( $variants ) || empty( $variants ) ) {
			return null;
		}
		$field = (string) $field;
		if ( isset( $variants[ $field ] ) && is_array( $variants[ $field ] ) ) {
			return $variants[ $field ];
		}
		if ( isset( $variants['general'] ) && is_array( $variants['general'] ) ) {
			return $variants['general'];
		}
		return null;
	}

	/**
	 * Render a variant block as an HTML aside appended to the lesson body.
	 *
	 * The block is authored data (never user input) but is escaped defensively
	 * anyway; only a short paragraph and an optional example line are emitted.
	 *
	 * @param array  $block { heading?, body, example? }.
	 * @param string $field Field key (for the label).
	 * @return string HTML, or '' when the block has no body.
	 */
	public static function render_block( $block, $field ) {
		if ( ! is_array( $block ) ) {
			return '';
		}
		$body = isset( $block['body'] ) ? trim( (string) $block['body'] ) : '';
		if ( '' === $body ) {
			return '';
		}
		$heading = isset( $block['heading'] ) && '' !== trim( (string) $block['heading'] )
			? (string) $block['heading']
			/* translators: %s: department name, e.g. Marketing. */
			: sprintf( __( 'In %s', 'mahan-academy' ), self::field_label( $field ) );

		$html  = '<aside class="mahan-field-variant" data-field="' . esc_attr( $field ) . '">';
		$html .= '<h3 class="mahan-field-variant-title">' . esc_html( $heading ) . '</h3>';
		$html .= '<p>' . wp_kses_post( $body ) . '</p>';
		if ( ! empty( $block['example'] ) ) {
			$html .= '<p class="mahan-field-variant-example"><strong>' . esc_html__( 'Try this:', 'mahan-academy' ) . '</strong> '
				. wp_kses_post( (string) $block['example'] ) . '</p>';
		}
		$html .= '</aside>';
		return $html;
	}

	/**
	 * Apply a lesson's field variants to its content for a given learner.
	 *
	 * @param string $content  Lesson HTML.
	 * @param array  $variants field => block map (from lesson meta).
	 * @param string $field    Learner's field.
	 * @return array { content:string, applied:string|'' } — applied is the field
	 *               whose block was used ('' when none).
	 */
	public static function apply( $content, $variants, $field ) {
		$block = self::pick( $variants, $field );
		if ( ! $block ) {
			return array( 'content' => (string) $content, 'applied' => '' );
		}
		// A `general` fallback is not a *tailored* result — report the field that
		// actually matched so the UI only claims tailoring when it's true.
		$applied = ( isset( $variants[ $field ] ) && is_array( $variants[ $field ] ) ) ? (string) $field : 'general';
		$html    = self::render_block( $block, $applied );
		if ( '' === $html ) {
			return array( 'content' => (string) $content, 'applied' => '' );
		}
		return array( 'content' => (string) $content . $html, 'applied' => $applied );
	}
}
