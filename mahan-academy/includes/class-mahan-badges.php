<?php
/**
 * Achievements / badges.
 *
 * BadgeOS/GamiPress-style tiered milestones: learners earn badges for lessons,
 * courses, streaks, levels, XP, quizzes, exercises, and learning paths. Newly
 * earned badges are accumulated per request so REST responses can carry them
 * and the front-end can celebrate immediately (not just on the dashboard).
 *
 * Badges are stored in user meta; definitions are filterable via
 * `mahan_badge_defs` so sites can add their own.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Badges {

	const META_KEY = 'mahan_badges';

	/**
	 * Badges awarded during this request, keyed by user id.
	 *
	 * @var array
	 */
	private static $just_awarded = array();

	public static function init() {
		add_action( 'mahan_lesson_completed', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_course_completed', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_level_up', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_streak_updated', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_quiz_passed', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_exercise_correct', array( __CLASS__, 'evaluate' ), 20 );
	}

	private static function enabled() {
		return (bool) Mahan_Settings::get( 'badges_enabled', 1 );
	}

	/**
	 * All badge definitions. Each: key, icon, title, desc, metric + threshold.
	 *
	 * Keys from 1.1.0 are preserved so already-earned badges stay earned.
	 *
	 * @return array
	 */
	public static function defs() {
		$defs = array(
			// Lessons.
			array( 'key' => 'first_lesson', 'icon' => '📘', 'metric' => 'lessons', 'need' => 1, 'title' => __( 'First Steps', 'mahan-academy' ), 'desc' => __( 'Complete your first lesson', 'mahan-academy' ) ),
			array( 'key' => 'ten_lessons', 'icon' => '📚', 'metric' => 'lessons', 'need' => 10, 'title' => __( 'Bookworm', 'mahan-academy' ), 'desc' => __( 'Complete 10 lessons', 'mahan-academy' ) ),
			array( 'key' => 'lessons_25', 'icon' => '🎯', 'metric' => 'lessons', 'need' => 25, 'title' => __( 'Dedicated', 'mahan-academy' ), 'desc' => __( 'Complete 25 lessons', 'mahan-academy' ) ),
			array( 'key' => 'lessons_50', 'icon' => '👑', 'metric' => 'lessons', 'need' => 50, 'title' => __( 'Lesson Legend', 'mahan-academy' ), 'desc' => __( 'Complete 50 lessons', 'mahan-academy' ) ),

			// Courses.
			array( 'key' => 'first_course', 'icon' => '🎓', 'metric' => 'courses', 'need' => 1, 'title' => __( 'Graduate', 'mahan-academy' ), 'desc' => __( 'Complete your first course', 'mahan-academy' ) ),
			array( 'key' => 'three_courses', 'icon' => '🏅', 'metric' => 'courses', 'need' => 3, 'title' => __( 'Scholar', 'mahan-academy' ), 'desc' => __( 'Complete 3 courses', 'mahan-academy' ) ),
			array( 'key' => 'five_courses', 'icon' => '🎖️', 'metric' => 'courses', 'need' => 5, 'title' => __( 'Course Master', 'mahan-academy' ), 'desc' => __( 'Complete 5 courses', 'mahan-academy' ) ),

			// Streaks.
			array( 'key' => 'streak_3', 'icon' => '✨', 'metric' => 'streak', 'need' => 3, 'title' => __( 'Warming Up', 'mahan-academy' ), 'desc' => __( 'Keep a 3-day streak', 'mahan-academy' ) ),
			array( 'key' => 'streak_7', 'icon' => '🔥', 'metric' => 'streak', 'need' => 7, 'title' => __( 'On Fire', 'mahan-academy' ), 'desc' => __( 'Keep a 7-day streak', 'mahan-academy' ) ),
			array( 'key' => 'streak_30', 'icon' => '🏆', 'metric' => 'streak', 'need' => 30, 'title' => __( 'Unstoppable', 'mahan-academy' ), 'desc' => __( 'Keep a 30-day streak', 'mahan-academy' ) ),
			array( 'key' => 'streak_100', 'icon' => '☄️', 'metric' => 'streak', 'need' => 100, 'title' => __( 'Eternal Flame', 'mahan-academy' ), 'desc' => __( 'Keep a 100-day streak', 'mahan-academy' ) ),

			// Levels.
			array( 'key' => 'level_5', 'icon' => '⭐', 'metric' => 'level', 'need' => 5, 'title' => __( 'Rising Star', 'mahan-academy' ), 'desc' => __( 'Reach level 5', 'mahan-academy' ) ),
			array( 'key' => 'level_10', 'icon' => '🌟', 'metric' => 'level', 'need' => 10, 'title' => __( 'AI Pro', 'mahan-academy' ), 'desc' => __( 'Reach level 10', 'mahan-academy' ) ),
			array( 'key' => 'level_20', 'icon' => '💫', 'metric' => 'level', 'need' => 20, 'title' => __( 'Luminary', 'mahan-academy' ), 'desc' => __( 'Reach level 20', 'mahan-academy' ) ),

			// XP.
			array( 'key' => 'xp_1000', 'icon' => '⚡', 'metric' => 'xp', 'need' => 1000, 'title' => __( 'Power Learner', 'mahan-academy' ), 'desc' => __( 'Earn 1,000 XP', 'mahan-academy' ) ),
			array( 'key' => 'xp_5000', 'icon' => '💎', 'metric' => 'xp', 'need' => 5000, 'title' => __( 'Dynamo', 'mahan-academy' ), 'desc' => __( 'Earn 5,000 XP', 'mahan-academy' ) ),

			// Quizzes.
			array( 'key' => 'quiz_1', 'icon' => '🧩', 'metric' => 'quizzes_passed', 'need' => 1, 'title' => __( 'Brain Teaser', 'mahan-academy' ), 'desc' => __( 'Pass your first unit quiz', 'mahan-academy' ) ),
			array( 'key' => 'quiz_10', 'icon' => '🧠', 'metric' => 'quizzes_passed', 'need' => 10, 'title' => __( 'Quiz Whiz', 'mahan-academy' ), 'desc' => __( 'Pass 10 unit quizzes', 'mahan-academy' ) ),
			array( 'key' => 'perfect_quiz', 'icon' => '💯', 'metric' => 'perfect_quizzes', 'need' => 1, 'title' => __( 'Perfectionist', 'mahan-academy' ), 'desc' => __( 'Score 100% on a unit quiz', 'mahan-academy' ) ),

			// Exercises.
			array( 'key' => 'exercises_50', 'icon' => '🥋', 'metric' => 'exercises_correct', 'need' => 50, 'title' => __( 'Practice Black Belt', 'mahan-academy' ), 'desc' => __( 'Solve 50 exercises correctly', 'mahan-academy' ) ),

			// Learning paths.
			array( 'key' => 'path_1', 'icon' => '🧭', 'metric' => 'paths_completed', 'need' => 1, 'title' => __( 'Trailblazer', 'mahan-academy' ), 'desc' => __( 'Complete a learning path', 'mahan-academy' ) ),
		);
		return apply_filters( 'mahan_badge_defs', $defs );
	}

	/* ------------------------------------------------------------------ */
	/* Evaluation                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Evaluate and award any newly earned badges for a user.
	 *
	 * @param int $user_id User id.
	 * @return array Newly awarded badge keys.
	 */
	public static function evaluate( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! self::enabled() ) {
			return array();
		}
		$metrics = self::metrics( $user_id );
		$earned  = self::earned_map( $user_id );
		$new     = array();

		foreach ( self::defs() as $def ) {
			$key = $def['key'];
			if ( isset( $earned[ $key ] ) ) {
				continue;
			}
			$have = isset( $metrics[ $def['metric'] ] ) ? (int) $metrics[ $def['metric'] ] : 0;
			if ( $have >= (int) $def['need'] ) {
				$earned[ $key ] = Mahan_Utils::now_mysql();
				$new[]          = $key;
				self::$just_awarded[ $user_id ][] = array(
					'key'   => $key,
					'icon'  => $def['icon'],
					'title' => $def['title'],
				);
				do_action( 'mahan_badge_awarded', $user_id, $key );
			}
		}

		if ( ! empty( $new ) ) {
			update_user_meta( $user_id, self::META_KEY, $earned );
		}
		return $new;
	}

	/**
	 * Badges awarded to this user during the current request — for REST
	 * responses so the front-end can toast them. Clears the buffer.
	 *
	 * @param int $user_id User id.
	 * @return array list of { key, icon, title }
	 */
	public static function take_new( $user_id ) {
		$user_id = (int) $user_id;
		if ( empty( self::$just_awarded[ $user_id ] ) ) {
			return array();
		}
		$out = self::$just_awarded[ $user_id ];
		unset( self::$just_awarded[ $user_id ] );
		return $out;
	}

	private static function metrics( $user_id ) {
		$stats = Mahan_Gamification::get_stats( $user_id );
		return array(
			'lessons'           => self::lessons_completed( $user_id ),
			'courses'           => self::courses_completed( $user_id ),
			'streak'            => max( (int) $stats['streak'], (int) $stats['longest_streak'] ),
			'level'             => (int) $stats['level'],
			'xp'                => (int) $stats['xp'],
			'quizzes_passed'    => self::quizzes_passed( $user_id ),
			'perfect_quizzes'   => self::perfect_quizzes( $user_id ),
			'exercises_correct' => self::exercises_correct( $user_id ),
			'paths_completed'   => self::paths_completed( $user_id ),
		);
	}

	private static function lessons_completed( $user_id ) {
		global $wpdb;
		$table = Mahan_DB::progress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'completed'", (int) $user_id ) );
	}

	private static function courses_completed( $user_id ) {
		global $wpdb;
		$table = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'completed'", (int) $user_id ) );
	}

	private static function quizzes_passed( $user_id ) {
		global $wpdb;
		$table = Mahan_DB::attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT exercise_key) FROM {$table} WHERE user_id = %d AND type = 'quiz' AND is_correct = 1", (int) $user_id )
		);
	}

	private static function perfect_quizzes( $user_id ) {
		global $wpdb;
		$table = Mahan_DB::attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT exercise_key) FROM {$table} WHERE user_id = %d AND type = 'quiz' AND score = 100", (int) $user_id )
		);
	}

	private static function exercises_correct( $user_id ) {
		global $wpdb;
		$table = Mahan_DB::attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT CONCAT(lesson_id, ':', exercise_key)) FROM {$table} WHERE user_id = %d AND type <> 'quiz' AND is_correct = 1",
				(int) $user_id
			)
		);
	}

	private static function paths_completed( $user_id ) {
		if ( ! class_exists( 'Mahan_Paths' ) ) {
			return 0;
		}
		$done = 0;
		foreach ( Mahan_Paths::get_paths() as $path ) {
			$summary = Mahan_Paths::summary( $path, $user_id );
			if ( $summary && $summary['course_count'] > 0 && $summary['completed'] >= $summary['course_count'] ) {
				$done++;
			}
		}
		return $done;
	}

	/* ------------------------------------------------------------------ */
	/* Read                                                                */
	/* ------------------------------------------------------------------ */

	private static function earned_map( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Full badge list for a user with earned/locked state (for the dashboard).
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		if ( ! self::enabled() ) {
			return array();
		}
		$earned  = self::earned_map( $user_id );
		$metrics = self::metrics( $user_id );
		$out     = array();
		foreach ( self::defs() as $def ) {
			$have  = isset( $metrics[ $def['metric'] ] ) ? (int) $metrics[ $def['metric'] ] : 0;
			$out[] = array(
				'key'       => $def['key'],
				'icon'      => $def['icon'],
				'title'     => $def['title'],
				'desc'      => $def['desc'],
				'earned'    => isset( $earned[ $def['key'] ] ),
				'earned_at' => isset( $earned[ $def['key'] ] ) ? $earned[ $def['key'] ] : null,
				// Progress toward locked badges ("3/10") keeps them
				// motivating instead of opaque.
				'need'      => (int) $def['need'],
				'progress'  => min( (int) $def['need'], $have ),
			);
		}
		return $out;
	}
}
