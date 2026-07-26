<?php
/**
 * Learning paths (tracks) — an ordered program of courses.
 *
 * A path is a `mahan_path` CPT whose meta stores an ordered list of course IDs.
 * Paths are a discovery/organization layer: they show the recommended order and
 * aggregate a learner's progress across the member courses (non-gating).
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Paths {

	const M_COURSES  = '_mahan_path_courses';
	const M_SUBTITLE = '_mahan_path_subtitle';

	/**
	 * Published paths.
	 *
	 * @return WP_Post[]
	 */
	public static function get_paths() {
		return get_posts(
			array(
				'post_type'      => Mahan_CPT::PATH,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Ordered course IDs that belong to a path (published courses only).
	 *
	 * @param int $path_id Path id.
	 * @return int[]
	 */
	public static function course_ids( $path_id ) {
		$raw = get_post_meta( (int) $path_id, self::M_COURSES, true );
		$ids = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id && Mahan_CPT::COURSE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Compact path representation for the catalog.
	 *
	 * @param int|WP_Post $path Path.
	 * @param int         $user_id Optional user for progress.
	 * @return array|null
	 */
	public static function summary( $path, $user_id = 0 ) {
		$path = get_post( $path );
		if ( ! $path || Mahan_CPT::PATH !== $path->post_type ) {
			return null;
		}
		$id  = (int) $path->ID;
		$ids = self::course_ids( $id );

		$done = 0;
		if ( $user_id ) {
			foreach ( $ids as $cid ) {
				$enr = Mahan_Enrollment::get( $user_id, $cid );
				if ( $enr && 'completed' === $enr['status'] ) {
					$done++;
				}
			}
		}
		$total = count( $ids );

		return array(
			'id'           => $id,
			'title'        => get_the_title( $id ),
			'subtitle'     => Mahan_Utils::meta_str( $id, self::M_SUBTITLE ),
			'excerpt'      => wp_strip_all_tags( get_the_excerpt( $id ) ),
			'image'        => get_the_post_thumbnail_url( $id, 'large' ) ?: '',
			'course_count' => $total,
			'completed'    => $done,
			'progress_pct' => ( $total > 0 && $done >= $total ) ? 100 : ( $total > 0 ? (int) min( 99, floor( ( $done / $total ) * 100 ) ) : 0 ),
		);
	}

	/**
	 * Full path detail with per-course progress for a user.
	 *
	 * @param int $path_id Path id.
	 * @param int $user_id User id.
	 * @return array|null
	 */
	public static function detail( $path_id, $user_id = 0 ) {
		$summary = self::summary( $path_id, $user_id );
		if ( ! $summary ) {
			return null;
		}
		$courses = array();
		foreach ( self::course_ids( $path_id ) as $cid ) {
			$c = Mahan_Courses::course_summary( $cid );
			if ( ! $c ) {
				continue;
			}
			$c['enrolled']     = false;
			$c['progress_pct'] = 0;
			$c['completed']    = false;
			if ( $user_id ) {
				$enr = Mahan_Enrollment::get( $user_id, $cid );
				if ( $enr ) {
					$c['enrolled']     = true;
					$c['progress_pct'] = (int) $enr['progress_pct'];
					$c['completed']    = ( 'completed' === $enr['status'] );
				}
			}
			$courses[] = $c;
		}

		$summary['description'] = apply_filters( 'the_content', get_post_field( 'post_content', $path_id ) );
		$summary['courses']     = $courses;
		return $summary;
	}
}
