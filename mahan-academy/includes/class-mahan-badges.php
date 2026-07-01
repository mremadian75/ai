<?php
/**
 * Achievements / badges. Learners earn badges for milestones (first lesson,
 * course completion, streaks, levels, XP). Badges are stored in user meta and
 * surfaced on the dashboard.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Badges {

	const META_KEY = 'mahan_badges';

	public static function init() {
		add_action( 'mahan_lesson_completed', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_course_completed', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_level_up', array( __CLASS__, 'evaluate' ), 20 );
		add_action( 'mahan_streak_updated', array( __CLASS__, 'evaluate' ), 20 );
	}

	private static function enabled() {
		return (bool) Mahan_Settings::get( 'badges_enabled', 1 );
	}

	/**
	 * All badge definitions. Each: key, icon, title, desc, and a metric+threshold.
	 *
	 * @return array
	 */
	public static function defs() {
		$defs = array(
			array( 'key' => 'first_lesson', 'icon' => '📘', 'metric' => 'lessons', 'need' => 1, 'title' => __( 'First Steps', 'mahan-academy' ), 'desc' => __( 'Complete your first lesson', 'mahan-academy' ) ),
			array( 'key' => 'ten_lessons', 'icon' => '📚', 'metric' => 'lessons', 'need' => 10, 'title' => __( 'Bookworm', 'mahan-academy' ), 'desc' => __( 'Complete 10 lessons', 'mahan-academy' ) ),
			array( 'key' => 'first_course', 'icon' => '🎓', 'metric' => 'courses', 'need' => 1, 'title' => __( 'Graduate', 'mahan-academy' ), 'desc' => __( 'Complete your first course', 'mahan-academy' ) ),
			array( 'key' => 'three_courses', 'icon' => '🏅', 'metric' => 'courses', 'need' => 3, 'title' => __( 'Scholar', 'mahan-academy' ), 'desc' => __( 'Complete 3 courses', 'mahan-academy' ) ),
			array( 'key' => 'streak_7', 'icon' => '🔥', 'metric' => 'streak', 'need' => 7, 'title' => __( 'On Fire', 'mahan-academy' ), 'desc' => __( 'Keep a 7-day streak', 'mahan-academy' ) ),
			array( 'key' => 'streak_30', 'icon' => '🏆', 'metric' => 'streak', 'need' => 30, 'title' => __( 'Unstoppable', 'mahan-academy' ), 'desc' => __( 'Keep a 30-day streak', 'mahan-academy' ) ),
			array( 'key' => 'level_5', 'icon' => '⭐', 'metric' => 'level', 'need' => 5, 'title' => __( 'Rising Star', 'mahan-academy' ), 'desc' => __( 'Reach level 5', 'mahan-academy' ) ),
			array( 'key' => 'level_10', 'icon' => '🌟', 'metric' => 'level', 'need' => 10, 'title' => __( 'AI Pro', 'mahan-academy' ), 'desc' => __( 'Reach level 10', 'mahan-academy' ) ),
			array( 'key' => 'xp_1000', 'icon' => '⚡', 'metric' => 'xp', 'need' => 1000, 'title' => __( 'Power Learner', 'mahan-academy' ), 'desc' => __( 'Earn 1,000 XP', 'mahan-academy' ) ),
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
				do_action( 'mahan_badge_awarded', $user_id, $key );
			}
		}

		if ( ! empty( $new ) ) {
			update_user_meta( $user_id, self::META_KEY, $earned );
		}
		return $new;
	}

	private static function metrics( $user_id ) {
		$stats = Mahan_Gamification::get_stats( $user_id );
		return array(
			'lessons' => self::lessons_completed( $user_id ),
			'courses' => self::courses_completed( $user_id ),
			'streak'  => max( (int) $stats['streak'], (int) $stats['longest_streak'] ),
			'level'   => (int) $stats['level'],
			'xp'      => (int) $stats['xp'],
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
		$earned = self::earned_map( $user_id );
		$out    = array();
		foreach ( self::defs() as $def ) {
			$out[] = array(
				'key'       => $def['key'],
				'icon'      => $def['icon'],
				'title'     => $def['title'],
				'desc'      => $def['desc'],
				'earned'    => isset( $earned[ $def['key'] ] ),
				'earned_at' => isset( $earned[ $def['key'] ] ) ? $earned[ $def['key'] ] : null,
			);
		}
		return $out;
	}
}
