<?php
/**
 * The learner's own record of themselves: saved courses, skills, milestones.
 *
 * The dashboard could already answer "what should I do next". None of it could
 * answer "what have I actually become" — the question every learning platform
 * worth using puts on a profile page, because a streak counter measures
 * attendance and a skill list measures ability.
 *
 * Everything here is derived from data the plugin already stores. No new
 * tables: skills come out of the topic taxonomy crossed with completed
 * lessons, milestones out of the progress and enrollment tables, and the
 * activity map out of the XP log.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Learner {

	/** Saved-for-later course ids. */
	const META_SAVED = 'mahan_saved';

	/** A topic counts as a skill once this many of its lessons are done. */
	const SKILL_THRESHOLD = 2;

	/* ------------------------------------------------------------------ */
	/* Saved courses                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Course ids the learner has saved for later.
	 *
	 * Deliberately separate from enrollment: "I might do this" and "I am doing
	 * this" are different intentions, and collapsing them into one list is what
	 * turns a dashboard into a graveyard of things you never started.
	 *
	 * @return int[]
	 */
	public static function saved( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$raw = get_user_meta( $user_id, self::META_SAVED, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Save or unsave a course.
	 *
	 * @return array { saved:bool, count:int } state after the toggle.
	 */
	public static function toggle_saved( $user_id, $course_id ) {
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		$list      = self::saved( $user_id );

		if ( ! $user_id || $course_id < 1 ) {
			return array( 'saved' => false, 'count' => count( $list ) );
		}

		$at = array_search( $course_id, $list, true );
		if ( false !== $at ) {
			unset( $list[ $at ] );
			$list  = array_values( $list );
			$state = false;
		} else {
			// Newest first: the thing you just saved is the thing you are most
			// likely to come back for.
			array_unshift( $list, $course_id );
			$state = true;
		}

		update_user_meta( $user_id, self::META_SAVED, $list );
		return array( 'saved' => $state, 'count' => count( $list ) );
	}

	/**
	 * Saved courses as catalog summaries, skipping any that no longer exist.
	 */
	public static function saved_courses( $user_id ) {
		$out = array();
		foreach ( self::saved( $user_id ) as $course_id ) {
			$summary = Mahan_Courses::course_summary( $course_id );
			if ( ! $summary ) {
				continue;
			}
			$summary['saved'] = true;
			$out[]            = $summary;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Skills                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Concepts the learner has actually worked through, strongest first.
	 *
	 * A topic is credited once SKILL_THRESHOLD of its lessons are complete —
	 * one lesson is an encounter, not a skill, and a profile that lists every
	 * topic ever touched says nothing.
	 *
	 * @param int $user_id Learner.
	 * @param int $limit   Maximum skills to return.
	 * @return array[] { name, slug, lessons }
	 */
	public static function skills( $user_id, $limit = 12 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		$progress = Mahan_DB::progress();

		// One query: completed lessons joined to their topic terms. Doing this
		// per-lesson in PHP would be a query per lesson on a busy profile.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name AS name, t.slug AS slug, COUNT(DISTINCT p.lesson_id) AS n
				 FROM {$progress} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.lesson_id
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE p.user_id = %d AND p.status = 'completed' AND tt.taxonomy = %s
				 GROUP BY t.term_id, t.name, t.slug
				 HAVING n >= %d
				 ORDER BY n DESC, t.name ASC
				 LIMIT %d",
				$user_id,
				Mahan_CPT::TOPIC,
				self::SKILL_THRESHOLD,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'name'    => (string) $r['name'],
				'slug'    => (string) $r['slug'],
				'lessons' => (int) $r['n'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Milestones                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * The lifetime totals a profile is actually about.
	 *
	 * @return array
	 */
	public static function totals( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$empty   = array(
			'lessons_completed' => 0,
			'courses_completed' => 0,
			'courses_enrolled'  => 0,
			'active_days'       => 0,
			'exercises_correct' => 0,
			'member_since'      => '',
		);
		if ( ! $user_id ) {
			return $empty;
		}

		$progress    = Mahan_DB::progress();
		$enrollments = Mahan_DB::enrollments();
		$attempts    = Mahan_DB::attempts();
		$xp_log      = Mahan_DB::xp_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lessons = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$progress} WHERE user_id = %d AND status = 'completed'", $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$enrolled = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments} WHERE user_id = %d", $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments} WHERE user_id = %d AND progress_pct >= 100", $user_id ) );
		// Distinct calendar days with any XP — "days active" is a truer measure
		// of a habit than a streak, which one missed day resets to nothing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$days = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT DATE(created_at)) FROM {$xp_log} WHERE user_id = %d", $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$correct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE user_id = %d AND is_correct = 1", $user_id ) );

		$user = get_userdata( $user_id );

		return array(
			'lessons_completed' => $lessons,
			'courses_completed' => $done,
			'courses_enrolled'  => $enrolled,
			'active_days'       => $days,
			'exercises_correct' => $correct,
			'member_since'      => ( $user && ! empty( $user->user_registered ) ) ? (string) $user->user_registered : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Pace                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * How much work is left in a course, in minutes.
	 *
	 * Uses the lessons the learner has not finished rather than scaling the
	 * course total by percentage, because lessons are not the same length and
	 * "60% done" says nothing about whether the remaining 40% is ten minutes
	 * or two hours.
	 *
	 * @return int Minutes remaining (0 when nothing is left or nothing is known).
	 */
	public static function minutes_remaining( $user_id, $course_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		if ( ! $user_id || ! $course_id ) {
			return 0;
		}

		$lessons = Mahan_Courses::get_course_lessons( $course_id );
		if ( empty( $lessons ) ) {
			return 0;
		}

		$progress = Mahan_DB::progress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$done = $wpdb->get_col(
			$wpdb->prepare( "SELECT lesson_id FROM {$progress} WHERE user_id = %d AND course_id = %d AND status = 'completed'", $user_id, $course_id )
		);
		$done = array_map( 'intval', (array) $done );

		$minutes = 0;
		foreach ( $lessons as $lesson ) {
			$id = (int) $lesson->ID;
			if ( ! $id || in_array( $id, $done, true ) ) {
				continue;
			}
			$est = Mahan_Utils::meta_int( $id, Mahan_Courses::M_EST_MIN, 0 );
			// A lesson with no estimate still takes time; assume the modest end
			// rather than pretending it is free.
			$minutes += $est > 0 ? $est : 8;
		}
		return $minutes;
	}

	/**
	 * When the learner last finished a lesson in each course.
	 *
	 * The dashboard's "continue where you left off" has to point at the course
	 * you were last *working on*, which is not the course you most recently
	 * *enrolled in* — enrol in something on a whim and it would otherwise hide
	 * the course you have been grinding through all week.
	 *
	 * @param int $user_id Learner.
	 * @return array course_id => 'Y-m-d H:i:s' of the last completed lesson.
	 */
	public static function last_activity_map( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$progress = Mahan_DB::progress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT course_id, MAX(completed_at) AS last_at
				 FROM {$progress}
				 WHERE user_id = %d AND status = 'completed' AND completed_at IS NOT NULL
				 GROUP BY course_id",
				$user_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['course_id'] ] = (string) $r['last_at'];
		}
		return $out;
	}

	/**
	 * This week against last week — the only comparison that tells a learner
	 * whether they are speeding up or drifting.
	 *
	 * Both weeks come back in one sweep per metric; the split is done in PHP.
	 * "This week" is the last 7 days including today, not an ISO week, because
	 * a Monday-reset counter reads as a collapse every Monday morning.
	 *
	 * @param int $user_id Learner.
	 * @return array {lessons, xp, prev_lessons, prev_xp}
	 */
	public static function week_summary( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$out     = array( 'lessons' => 0, 'xp' => 0, 'prev_lessons' => 0, 'prev_xp' => 0 );
		if ( ! $user_id ) {
			return $out;
		}

		$today     = Mahan_Utils::today();
		$cur_from  = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
		$prev_from = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );

		$progress = Mahan_DB::progress();
		$xp_log   = Mahan_DB::xp_log();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$lesson_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(completed_at) AS d, COUNT(*) AS n
				 FROM {$progress}
				 WHERE user_id = %d AND status = 'completed' AND completed_at >= %s
				 GROUP BY DATE(completed_at)",
				$user_id,
				$prev_from . ' 00:00:00'
			),
			ARRAY_A
		);
		$xp_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS n
				 FROM {$xp_log}
				 WHERE user_id = %d AND created_at >= %s
				 GROUP BY DATE(created_at)",
				$user_id,
				$prev_from . ' 00:00:00'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$out['lessons']      = self::sum_between( $lesson_rows, $cur_from, $today );
		$out['prev_lessons'] = self::sum_between( $lesson_rows, $prev_from, $cur_from, true );
		$out['xp']           = self::sum_between( $xp_rows, $cur_from, $today );
		$out['prev_xp']      = self::sum_between( $xp_rows, $prev_from, $cur_from, true );
		return $out;
	}

	/**
	 * Sum grouped-by-day rows inside a date range.
	 *
	 * @param array[] $rows      {d, n} rows.
	 * @param string  $from      First day (inclusive).
	 * @param string  $to        Last day.
	 * @param bool    $exclusive Treat $to as exclusive — used for the previous
	 *                           week so the two windows cannot double-count the
	 *                           day they share.
	 * @return int
	 */
	public static function sum_between( $rows, $from, $to, $exclusive = false ) {
		$sum = 0;
		foreach ( (array) $rows as $r ) {
			$d = (string) $r['d'];
			if ( $d < $from ) {
				continue;
			}
			if ( $exclusive ? ( $d >= $to ) : ( $d > $to ) ) {
				continue;
			}
			$sum += (int) $r['n'];
		}
		return $sum;
	}

	/**
	 * How many more days of studying earn the next streak freeze.
	 *
	 * Mirrors the award rule in Mahan_Gamification::touch_streak() — a freeze
	 * lands when the streak hits a multiple of `freeze_earn_days`, and only
	 * while the learner is below `freeze_max`. Returns null when there is
	 * nothing to promise: the feature is off, the holder is full, or the
	 * streak has not started.
	 *
	 * @param array $stats Gamification hud() payload.
	 * @return int|null Days remaining, or null.
	 */
	public static function next_freeze( $stats ) {
		if ( ! Mahan_Settings::get( 'streak_freeze_enabled', 1 ) ) {
			return null;
		}
		$every = max( 0, (int) Mahan_Settings::get( 'freeze_earn_days', 7 ) );
		$max   = max( 0, (int) Mahan_Settings::get( 'freeze_max', 2 ) );
		if ( $every <= 0 || $max <= 0 ) {
			return null;
		}
		if ( (int) ( isset( $stats['freezes'] ) ? $stats['freezes'] : 0 ) >= $max ) {
			return null;
		}
		$streak = (int) ( isset( $stats['streak'] ) ? $stats['streak'] : 0 );
		if ( $streak <= 0 ) {
			return null;
		}
		$left = $every - ( $streak % $every );
		// A streak sitting exactly on the boundary has already been paid; the
		// next one is a whole cycle away, not zero days away.
		return $left === $every ? $every : $left;
	}
}
