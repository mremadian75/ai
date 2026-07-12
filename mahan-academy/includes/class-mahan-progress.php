<?php
/**
 * Lesson & course progress tracking, wired to gamification and enrollment.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Progress {

	/**
	 * Get a user's progress row for a lesson (or null).
	 */
	public static function get_lesson( $user_id, $lesson_id ) {
		global $wpdb;
		$table = Mahan_DB::progress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d", (int) $user_id, (int) $lesson_id ),
			ARRAY_A
		);
	}

	/**
	 * Map of lesson_id => status for a course (for rendering the outline).
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course id.
	 * @return array
	 */
	public static function course_lesson_status( $user_id, $course_id ) {
		global $wpdb;
		$table = Mahan_DB::progress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lesson_id, status FROM {$table} WHERE user_id = %d AND course_id = %d",
				(int) $user_id,
				(int) $course_id
			),
			ARRAY_A
		);
		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r['lesson_id'] ] = $r['status'];
		}
		return $map;
	}

	/**
	 * Mark a lesson started (in_progress) if not already tracked.
	 */
	public static function start_lesson( $user_id, $lesson_id, $course_id = 0 ) {
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;
		if ( ! $course_id ) {
			$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		}
		$existing = self::get_lesson( $user_id, $lesson_id );
		if ( $existing ) {
			return;
		}
		global $wpdb;
		$now = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Mahan_DB::progress(),
			array(
				'user_id'    => $user_id,
				'lesson_id'  => $lesson_id,
				'course_id'  => (int) $course_id,
				'status'     => 'in_progress',
				'started_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Complete a lesson: award XP once, bump the streak, recompute course %.
	 *
	 * @param int $user_id   User id.
	 * @param int $lesson_id Lesson id.
	 * @return array Result payload for the UI.
	 */
	public static function complete_lesson( $user_id, $lesson_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;
		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		$now       = Mahan_Utils::now_mysql();

		$existing      = self::get_lesson( $user_id, $lesson_id );
		$already_done  = $existing && 'completed' === $existing['status'];
		$lesson_xp     = $already_done ? 0 : Mahan_Courses::lesson_xp( $lesson_id );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				Mahan_DB::progress(),
				array(
					'status'       => 'completed',
					'xp_awarded'   => (int) $existing['xp_awarded'] + $lesson_xp,
					'completed_at' => $already_done ? $existing['completed_at'] : $now,
					'updated_at'   => $now,
				),
				array(
					'user_id'   => $user_id,
					'lesson_id' => $lesson_id,
				),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				Mahan_DB::progress(),
				array(
					'user_id'      => $user_id,
					'lesson_id'    => $lesson_id,
					'course_id'    => (int) $course_id,
					'status'       => 'completed',
					'xp_awarded'   => $lesson_xp,
					'started_at'   => $now,
					'completed_at' => $now,
					'updated_at'   => $now,
				),
				array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		// record_activity() first so the streak reflects today before add_xp()
		// computes its streak bonus (and a lapsed streak is reset first).
		Mahan_Gamification::record_activity( $user_id );
		$award = Mahan_Gamification::add_xp( $user_id, $lesson_xp, 'lesson', $lesson_id );

		// Was the course already finished before this call? (so we don't
		// re-fire completion — e.g. duplicate completion emails.)
		$enr           = Mahan_Enrollment::get( $user_id, $course_id );
		$was_completed = $enr && ( 'completed' === $enr['status'] || 100 === (int) $enr['progress_pct'] );

		// Recompute course completion.
		$course_pct       = self::course_progress_pct( $user_id, $course_id );
		$course_completed = ( 100 === $course_pct );
		if ( Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			Mahan_Enrollment::update_progress( $user_id, $course_id, $course_pct );
		}

		do_action( 'mahan_lesson_completed', $user_id, $lesson_id, $course_id );
		if ( $course_completed && ! $was_completed ) {
			do_action( 'mahan_course_completed', $user_id, $course_id );
		}

		return array(
			'ok'               => true,
			'xp_awarded'       => (int) $award['awarded'],
			'leveled_up'       => ! empty( $award['leveled_up'] ),
			'new_badges'       => Mahan_Badges::take_new( $user_id ),
			'already_done'     => $already_done,
			'course_progress'  => $course_pct,
			'course_completed' => $course_completed,
			'stats'            => Mahan_Gamification::hud( $user_id ),
		);
	}

	/**
	 * Completion percentage for a course (based on completed lessons).
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course id.
	 * @return int 0-100
	 */
	public static function course_progress_pct( $user_id, $course_id ) {
		$lessons = Mahan_Courses::get_course_lessons( $course_id );
		$total   = count( $lessons );
		if ( 0 === $total ) {
			return 0;
		}
		$status = self::course_lesson_status( $user_id, $course_id );
		$done   = 0;
		foreach ( $lessons as $lesson ) {
			if ( isset( $status[ (int) $lesson->ID ] ) && 'completed' === $status[ (int) $lesson->ID ] ) {
				$done++;
			}
		}
		// 100% only when every lesson is done — otherwise floor so a large
		// course never rounds up to a false "complete" (and certificate).
		if ( $done >= $total ) {
			return 100;
		}
		return (int) min( 99, floor( ( $done / $total ) * 100 ) );
	}
}
