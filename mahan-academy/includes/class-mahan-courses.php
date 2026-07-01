<?php
/**
 * Course/lesson structure & query helpers. Canonical home for the meta keys.
 *
 * Structure: Course -> Units (a label on each lesson) -> Lessons (ordered).
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Courses {

	// Course meta.
	const M_SUBTITLE  = '_mahan_subtitle';
	const M_LEVEL     = '_mahan_level';
	const M_EST_HOURS = '_mahan_est_hours';
	const M_OUTCOMES  = '_mahan_outcomes';

	// Lesson meta.
	const M_COURSE_ID  = '_mahan_course_id';
	const M_UNIT       = '_mahan_unit';
	const M_UNIT_ORDER = '_mahan_unit_order';
	const M_ORDER      = '_mahan_order';
	const M_XP         = '_mahan_xp';
	const M_EST_MIN    = '_mahan_est_min';
	const M_TYPE       = '_mahan_type';
	const M_EXERCISES  = '_mahan_exercises';

	/* ------------------------------------------------------------------ */
	/* Course-level                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Get published courses for the catalog.
	 *
	 * @param array $args Optional WP_Query overrides.
	 * @return WP_Post[]
	 */
	public static function get_courses( $args = array() ) {
		$defaults = array(
			'post_type'      => Mahan_CPT::COURSE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order date',
			'order'          => 'ASC',
		);
		$query = new WP_Query( wp_parse_args( $args, $defaults ) );
		return $query->posts;
	}

	/**
	 * Compact, front-end-friendly representation of a course (no lessons).
	 *
	 * @param int|WP_Post $course Course.
	 * @return array|null
	 */
	public static function course_summary( $course ) {
		$course = get_post( $course );
		if ( ! $course || Mahan_CPT::COURSE !== $course->post_type ) {
			return null;
		}
		$id = (int) $course->ID;
		$terms = wp_get_post_terms( $id, Mahan_CPT::CAT, array( 'fields' => 'names' ) );
		return array(
			'id'           => $id,
			'title'        => get_the_title( $id ),
			'subtitle'     => Mahan_Utils::meta_str( $id, self::M_SUBTITLE ),
			'excerpt'      => wp_strip_all_tags( get_the_excerpt( $id ) ),
			'level'        => Mahan_Utils::meta_str( $id, self::M_LEVEL, 'beginner' ),
			'est_hours'    => Mahan_Utils::meta_int( $id, self::M_EST_HOURS, 0 ),
			'categories'   => is_wp_error( $terms ) ? array() : array_values( $terms ),
			'image'        => get_the_post_thumbnail_url( $id, 'large' ) ?: '',
			'lesson_count' => count( self::get_course_lessons( $id ) ),
			'permalink'    => get_permalink( $id ),
		);
	}

	/**
	 * "What you'll learn" outcomes (one per line).
	 *
	 * @param int $course_id Course id.
	 * @return string[]
	 */
	public static function course_outcomes( $course_id ) {
		$raw = Mahan_Utils::meta_str( $course_id, self::M_OUTCOMES, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = array_filter( array_map( 'trim', $lines ) );
		return array_values( $lines );
	}

	/* ------------------------------------------------------------------ */
	/* Lessons                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * All lessons of a course, ordered by (unit_order, order, menu_order, id).
	 *
	 * @param int $course_id Course id.
	 * @return WP_Post[]
	 */
	public static function get_course_lessons( $course_id ) {
		$course_id = (int) $course_id;
		if ( ! $course_id ) {
			return array();
		}
		$lessons = get_posts(
			array(
				'post_type'      => Mahan_CPT::LESSON,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => self::M_COURSE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $course_id,        // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		usort(
			$lessons,
			function ( $a, $b ) {
				$ua = Mahan_Utils::meta_int( $a->ID, self::M_UNIT_ORDER, 0 );
				$ub = Mahan_Utils::meta_int( $b->ID, self::M_UNIT_ORDER, 0 );
				if ( $ua !== $ub ) {
					return $ua <=> $ub;
				}
				$oa = Mahan_Utils::meta_int( $a->ID, self::M_ORDER, 0 );
				$ob = Mahan_Utils::meta_int( $b->ID, self::M_ORDER, 0 );
				if ( $oa !== $ob ) {
					return $oa <=> $ob;
				}
				if ( $a->menu_order !== $b->menu_order ) {
					return $a->menu_order <=> $b->menu_order;
				}
				return $a->ID <=> $b->ID;
			}
		);

		return $lessons;
	}

	/**
	 * Course lessons grouped into ordered units.
	 *
	 * @param int $course_id Course id.
	 * @return array list of [ 'title' => string, 'lessons' => WP_Post[] ]
	 */
	public static function get_course_units( $course_id ) {
		$lessons = self::get_course_lessons( $course_id );
		$units   = array();
		foreach ( $lessons as $lesson ) {
			$unit = Mahan_Utils::meta_str( $lesson->ID, self::M_UNIT, '' );
			if ( '' === $unit ) {
				$unit = __( 'Lessons', 'mahan-academy' );
			}
			if ( ! isset( $units[ $unit ] ) ) {
				$units[ $unit ] = array(
					'title'   => $unit,
					'lessons' => array(),
				);
			}
			$units[ $unit ]['lessons'][] = $lesson;
		}
		return array_values( $units );
	}

	public static function get_lesson_course_id( $lesson_id ) {
		return Mahan_Utils::meta_int( $lesson_id, self::M_COURSE_ID, 0 );
	}

	/**
	 * Decode the exercises stored on a lesson.
	 *
	 * @param int $lesson_id Lesson id.
	 * @return array[] list of exercise definitions.
	 */
	public static function get_exercises( $lesson_id ) {
		$raw = get_post_meta( (int) $lesson_id, self::M_EXERCISES, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Find a single exercise definition by its key.
	 *
	 * @param int    $lesson_id Lesson id.
	 * @param string $key       Exercise key.
	 * @return array|null
	 */
	public static function get_exercise( $lesson_id, $key ) {
		foreach ( self::get_exercises( $lesson_id ) as $ex ) {
			if ( isset( $ex['key'] ) && (string) $ex['key'] === (string) $key ) {
				return $ex;
			}
		}
		return null;
	}

	/**
	 * Lesson XP reward (falls back to the global default).
	 */
	public static function lesson_xp( $lesson_id ) {
		$xp = Mahan_Utils::meta_int( $lesson_id, self::M_XP, 0 );
		if ( $xp <= 0 ) {
			$xp = (int) Mahan_Settings::get( 'xp_per_lesson', 20 );
		}
		return $xp;
	}

	/**
	 * Previous / next lesson ids within the course.
	 *
	 * @param int $lesson_id Lesson id.
	 * @return array [ 'prev' => int, 'next' => int ]
	 */
	public static function lesson_siblings( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$course_id = self::get_lesson_course_id( $lesson_id );
		$lessons   = self::get_course_lessons( $course_id );

		$ids   = wp_list_pluck( $lessons, 'ID' );
		$index = array_search( $lesson_id, $ids, true );

		$prev = ( false !== $index && $index > 0 ) ? (int) $ids[ $index - 1 ] : 0;
		$next = ( false !== $index && $index < count( $ids ) - 1 ) ? (int) $ids[ $index + 1 ] : 0;

		return array(
			'prev' => $prev,
			'next' => $next,
		);
	}

	/**
	 * Strip answer/rubric data from an exercise before sending to the browser.
	 *
	 * @param array $ex Exercise definition.
	 * @return array
	 */
	public static function public_exercise( array $ex ) {
		$out = array(
			'key'      => isset( $ex['key'] ) ? (string) $ex['key'] : '',
			'type'     => isset( $ex['type'] ) ? (string) $ex['type'] : 'multiple_choice',
			'question' => isset( $ex['question'] ) ? (string) $ex['question'] : '',
			'hint'     => isset( $ex['hint'] ) ? (string) $ex['hint'] : '',
			'xp'       => isset( $ex['xp'] ) ? (int) $ex['xp'] : (int) Mahan_Settings::get( 'xp_per_exercise', 10 ),
		);
		if ( in_array( $out['type'], array( 'multiple_choice', 'true_false' ), true ) && ! empty( $ex['options'] ) && is_array( $ex['options'] ) ) {
			// Labels only — never the correct index.
			$out['options'] = array_values( array_map( 'strval', $ex['options'] ) );
		}
		if ( 'prompt_task' === $out['type'] && ! empty( $ex['task'] ) ) {
			$out['task'] = (string) $ex['task'];
		}
		if ( ! empty( $ex['placeholder'] ) ) {
			$out['placeholder'] = (string) $ex['placeholder'];
		}
		return $out;
	}
}
