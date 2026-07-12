<?php
/**
 * Course enrollments: who's signed up for which course, and how far through.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Enrollment {

	/**
	 * Fetch the enrollment row for a (user, course), or null.
	 */
	public static function get( $user_id, $course_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		if ( ! $user_id || ! $course_id ) {
			return null;
		}
		$table = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d",
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function is_enrolled( $user_id, $course_id ) {
		return null !== self::get( $user_id, $course_id );
	}

	/**
	 * Create the enrollment (idempotent).
	 *
	 * @return array|WP_Error
	 */
	public static function enroll( $user_id, $course_id ) {
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		if ( ! $user_id ) {
			return new WP_Error( 'auth', __( 'You must be logged in.', 'mahan-academy' ) );
		}
		if ( ! $course_id || Mahan_CPT::COURSE !== get_post_type( $course_id ) ) {
			return new WP_Error( 'course', __( 'Course not found.', 'mahan-academy' ) );
		}
		$existing = self::get( $user_id, $course_id );
		if ( $existing ) {
			return $existing;
		}
		global $wpdb;
		$now = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			Mahan_DB::enrollments(),
			array(
				'user_id'      => $user_id,
				'course_id'    => $course_id,
				'status'       => 'active',
				'progress_pct' => 0,
				'enrolled_at'  => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		// Only fire the enrolled hook (welcome email, etc.) when THIS call
		// actually created the row — a concurrent double-enroll loses the
		// UNIQUE (user_id, course_id) race and must not re-notify.
		if ( $inserted ) {
			do_action( 'mahan_enrolled', $user_id, $course_id );
		}
		return self::get( $user_id, $course_id );
	}

	/**
	 * Update course completion percentage; mark complete when it hits 100.
	 */
	public static function update_progress( $user_id, $course_id, $pct ) {
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		$pct       = max( 0, min( 100, (int) $pct ) );
		if ( ! $user_id || ! $course_id ) {
			return;
		}
		// Preserve a course's completion: once finished it stays 'completed'
		// with its original timestamp, even if progress is later recomputed
		// below 100 (e.g. a lesson is added). Never wipe completed_at.
		$existing     = self::get( $user_id, $course_id );
		$prior_at     = ( $existing && ! empty( $existing['completed_at'] ) ) ? $existing['completed_at'] : '';
		if ( 100 === $pct ) {
			$status       = 'completed';
			$completed_at = '' !== $prior_at ? $prior_at : Mahan_Utils::now_mysql();
		} elseif ( '' !== $prior_at ) {
			$status       = 'completed';
			$completed_at = $prior_at;
		} else {
			$status       = 'active';
			$completed_at = null;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Mahan_DB::enrollments(),
			array(
				'progress_pct' => $pct,
				'status'       => $status,
				'completed_at' => $completed_at,
			),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			),
			array( '%d', '%s', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * All courses a user is enrolled in, as catalog summaries.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function get_user_courses( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$table = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT course_id, progress_pct, status FROM {$table} WHERE user_id = %d ORDER BY enrolled_at DESC",
				$user_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$summary = Mahan_Courses::course_summary( (int) $r['course_id'] );
			if ( ! $summary ) {
				continue;
			}
			$summary['enrolled']     = true;
			$summary['progress_pct'] = (int) $r['progress_pct'];
			$summary['status']       = $r['status'];
			$out[] = $summary;
		}
		return $out;
	}
}
