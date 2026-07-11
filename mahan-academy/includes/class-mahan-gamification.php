<?php
/**
 * XP, level, and daily-streak engine.
 *
 * Design follows the patterns proven by mature open-source gamification
 * systems (GamiPress / myCred / Duolingo-style learning apps):
 *
 *  - Every XP award is written to an append-only log with a reason, so
 *    weekly leaderboards, daily goals, and reports are exact — not estimates.
 *  - Levels support a linear curve or a progressive (RPG-style) curve where
 *    each level costs more than the last.
 *  - Streaks can earn "freezes" that are consumed automatically to cover
 *    missed days (positive reinforcement instead of punishing a slip).
 *  - A configurable daily XP goal gives learners a small, reachable target.
 *
 * All numbers come from settings so admins can tune without touching code.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Gamification {

	/**
	 * Read (or lazily create) the stats row for a user.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function get_stats( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return self::empty_stats();
		}
		$table = Mahan_DB::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			self::ensure_row( $user_id );
			$row = array(
				'user_id'           => $user_id,
				'xp'                => 0,
				'level'             => 1,
				'streak'            => 0,
				'longest_streak'    => 0,
				'hearts'            => (int) Mahan_Settings::get( 'hearts_max', 5 ),
				'hearts_updated_at' => null,
				'last_active_date'  => null,
				'freezes'           => 0,
				'daily_goal'        => 0,
				'updated_at'        => Mahan_Utils::now_mysql(),
			);
		}
		// Normalize types (also fills defaults for rows created before v1.3.0).
		$row['xp']             = (int) $row['xp'];
		$row['level']          = (int) $row['level'];
		$row['streak']         = (int) $row['streak'];
		$row['longest_streak'] = (int) $row['longest_streak'];
		$row['hearts']         = (int) $row['hearts'];
		$row['freezes']        = isset( $row['freezes'] ) ? (int) $row['freezes'] : 0;
		$row['daily_goal']     = isset( $row['daily_goal'] ) ? (int) $row['daily_goal'] : 0;
		return $row;
	}

	private static function empty_stats() {
		return array(
			'user_id'        => 0,
			'xp'             => 0,
			'level'          => 1,
			'streak'         => 0,
			'longest_streak' => 0,
			'hearts'         => (int) Mahan_Settings::get( 'hearts_max', 5 ),
			'freezes'        => 0,
			'daily_goal'     => 0,
		);
	}

	private static function ensure_row( $user_id ) {
		global $wpdb;
		$now = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Mahan_DB::stats() .
				' (user_id, xp, level, streak, longest_streak, hearts, hearts_updated_at, last_active_date, freezes, daily_goal, updated_at)' .
				' VALUES (%d, 0, 1, 0, 0, %d, %s, NULL, 0, 0, %s)',
				(int) $user_id,
				(int) Mahan_Settings::get( 'hearts_max', 5 ),
				$now,
				$now
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Level curve                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Resolve level + progress for a total-XP value under the active curve.
	 *
	 * linear:      every level costs `level_curve` XP.
	 * progressive: level N costs `level_curve * N` XP (RPG-style), so the
	 *              threshold to *reach* level L is curve * L*(L-1)/2.
	 *
	 * @param int $xp Total XP.
	 * @return array { level, xp_into_level, xp_per_level }
	 */
	public static function level_for_xp( $xp ) {
		$xp   = max( 0, (int) $xp );
		$per  = max( 10, (int) Mahan_Settings::get( 'level_curve', 100 ) );
		$mode = Mahan_Settings::get( 'level_mode', 'linear' );

		if ( 'progressive' === $mode ) {
			// Largest L with per * L*(L-1)/2 <= xp.
			$level     = (int) floor( ( 1 + sqrt( 1 + 8 * $xp / $per ) ) / 2 );
			$level     = max( 1, $level );
			$threshold = (int) ( $per * $level * ( $level - 1 ) / 2 );
			return array(
				'level'         => $level,
				'xp_into_level' => $xp - $threshold,
				'xp_per_level'  => $per * $level, // cost of the next level
			);
		}

		return array(
			'level'         => (int) floor( $xp / $per ) + 1,
			'xp_into_level' => $xp % $per,
			'xp_per_level'  => $per,
		);
	}

	/* ------------------------------------------------------------------ */
	/* XP                                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Streak XP multiplier: `xp_streak_bonus` percent per full week of streak,
	 * capped at 50%. Returns the bonus XP (not the total).
	 */
	private static function streak_bonus( $user_id, $amount ) {
		$per_tier = (int) Mahan_Settings::get( 'xp_streak_bonus', 10 );
		if ( $per_tier <= 0 ) {
			return 0;
		}
		$stats = self::get_stats( $user_id );
		$tiers = (int) floor( $stats['streak'] / 7 );
		if ( $tiers <= 0 ) {
			return 0;
		}
		$pct = min( 50, $per_tier * $tiers );
		return (int) round( $amount * $pct / 100 );
	}

	/**
	 * Award XP: applies the streak bonus, logs the award, recomputes the level.
	 *
	 * @param int    $user_id User id.
	 * @param int    $amount  Base XP amount.
	 * @param string $reason  Short slug for the log (lesson|exercise|quiz|…).
	 * @param int    $ref_id  Related object id (lesson/course id).
	 * @return array Stats row plus 'awarded' (incl. bonus) and 'leveled_up'.
	 */
	public static function add_xp( $user_id, $amount, $reason = '', $ref_id = 0 ) {
		$user_id = (int) $user_id;
		$amount  = (int) $amount;
		if ( ! $user_id || $amount <= 0 ) {
			$row               = self::get_stats( $user_id );
			$row['awarded']    = 0;
			$row['leveled_up'] = false;
			return $row;
		}
		self::ensure_row( $user_id );

		$bonus = self::streak_bonus( $user_id, $amount );
		$total = $amount + $bonus;

		global $wpdb;
		$table = Mahan_DB::stats();
		$now   = Mahan_Utils::now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET xp = xp + %d, updated_at = %s WHERE user_id = %d",
				$total,
				$now,
				$user_id
			)
		);

		// Append to the XP log (audit trail; powers weekly boards + daily goal).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Mahan_DB::xp_log(),
			array(
				'user_id'    => $user_id,
				'amount'     => $total,
				'reason'     => sanitize_key( (string) $reason ),
				'ref_id'     => (int) $ref_id,
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		// Recompute level from XP under the active curve.
		$row        = self::get_stats( $user_id );
		$resolved   = self::level_for_xp( $row['xp'] );
		$leveled_up = false;
		if ( $resolved['level'] !== $row['level'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET level = %d, updated_at = %s WHERE user_id = %d",
					$resolved['level'],
					$now,
					$user_id
				)
			);
			$leveled_up   = ( $resolved['level'] > $row['level'] );
			$row['level'] = $resolved['level'];
			if ( $leveled_up ) {
				do_action( 'mahan_level_up', $user_id, $resolved['level'] );
			}
		}
		$row['awarded']    = $total;
		$row['leveled_up'] = $leveled_up;
		return $row;
	}

	/**
	 * XP earned since a given MySQL datetime (from the log).
	 */
	public static function xp_since( $user_id, $since ) {
		global $wpdb;
		$table = Mahan_DB::xp_log();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE user_id = %d AND created_at >= %s",
				(int) $user_id,
				$since
			)
		);
	}

	/**
	 * XP earned today (site timezone).
	 */
	public static function xp_today( $user_id ) {
		return self::xp_since( $user_id, Mahan_Utils::today() . ' 00:00:00' );
	}

	/**
	 * Duolingo-style activity for the last 7 days (oldest first, site tz).
	 *
	 * Each day: [ 'label' => localized weekday initial, 'active' => earned any
	 * XP, 'goal_met' => reached the daily goal, 'today' => bool ].
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function week_activity( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		global $wpdb;
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today = new DateTimeImmutable( 'now', $tz );
		$table = Mahan_DB::xp_log();
		$since = $today->modify( '-6 days' )->format( 'Y-m-d' ) . ' 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, SUM(amount) AS s FROM {$table} WHERE user_id = %d AND created_at >= %s GROUP BY DATE(created_at)",
				$user_id,
				$since
			),
			ARRAY_A
		);
		$per_day = array();
		foreach ( (array) $rows as $r ) {
			$per_day[ $r['d'] ] = (int) $r['s'];
		}

		$goal = self::daily_goal( $user_id );
		$out  = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$day = $today->modify( '-' . $i . ' days' );
			$key = $day->format( 'Y-m-d' );
			$xp  = isset( $per_day[ $key ] ) ? $per_day[ $key ] : 0;
			$out[] = array(
				'label'    => wp_date( 'D', $day->getTimestamp(), $tz ),
				'active'   => $xp > 0,
				'goal_met' => $goal > 0 ? ( $xp >= $goal ) : ( $xp > 0 ),
				'today'    => 0 === $i,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Streaks + freezes                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Record activity for today; bump streak if it's a new day. Consumes
	 * streak freezes to cover missed days and earns a freeze at each interval.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function record_activity( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return self::get_stats( $user_id );
		}
		if ( ! (int) Mahan_Settings::get( 'streak_enabled', 1 ) ) {
			return self::get_stats( $user_id );
		}
		self::ensure_row( $user_id );

		global $wpdb;
		$table  = Mahan_DB::stats();
		$today  = Mahan_Utils::today();
		$now    = Mahan_Utils::now_mysql();
		$row    = self::get_stats( $user_id );
		$last   = $row['last_active_date'];

		if ( $last === $today ) {
			return $row; // Already counted today.
		}

		$freezes        = (int) $row['freezes'];
		$freezes_used   = 0;
		$freeze_enabled = (int) Mahan_Settings::get( 'streak_freeze_enabled', 1 );

		$streak = 1;
		if ( $last ) {
			$diff = Mahan_Utils::date_diff_days( $last, $today );
			if ( 1 === $diff ) {
				$streak = $row['streak'] + 1;
			} elseif ( $diff > 1 && $freeze_enabled && $freezes >= ( $diff - 1 ) ) {
				// Freezes cover every missed day → the streak survives.
				$freezes_used = $diff - 1;
				$freezes     -= $freezes_used;
				$streak       = $row['streak'] + 1;
				do_action( 'mahan_streak_frozen', $user_id, $freezes_used );
			} else {
				$streak = 1;
			}
		}

		// Earn a freeze at each interval of consecutive days (default: weekly).
		$earn_every = max( 0, (int) Mahan_Settings::get( 'freeze_earn_days', 7 ) );
		$freeze_max = max( 0, (int) Mahan_Settings::get( 'freeze_max', 2 ) );
		if ( $freeze_enabled && $earn_every > 0 && $streak > 0 && 0 === $streak % $earn_every && $freezes < $freeze_max ) {
			$freezes++;
		}

		$longest = max( (int) $row['longest_streak'], $streak );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET streak = %d, longest_streak = %d, last_active_date = %s, freezes = %d, updated_at = %s WHERE user_id = %d",
				$streak,
				$longest,
				$today,
				$freezes,
				$now,
				$user_id
			)
		);
		$row['streak']           = $streak;
		$row['longest_streak']   = $longest;
		$row['last_active_date'] = $today;
		$row['freezes']          = $freezes;
		do_action( 'mahan_streak_updated', $user_id, $streak );
		return $row;
	}

	/* ------------------------------------------------------------------ */
	/* Daily goal                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * The user's daily XP goal (their choice, else the site default).
	 */
	public static function daily_goal( $user_id ) {
		$stats = self::get_stats( $user_id );
		if ( $stats['daily_goal'] > 0 ) {
			return $stats['daily_goal'];
		}
		return max( 0, (int) Mahan_Settings::get( 'daily_goal_default', 30 ) );
	}

	/**
	 * Persist a learner-chosen daily goal (0 = use the site default).
	 */
	public static function set_daily_goal( $user_id, $goal ) {
		$user_id = (int) $user_id;
		$goal    = max( 0, min( 1000, (int) $goal ) );
		if ( ! $user_id ) {
			return false;
		}
		self::ensure_row( $user_id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Mahan_DB::stats(),
			array( 'daily_goal' => $goal, 'updated_at' => Mahan_Utils::now_mysql() ),
			array( 'user_id' => $user_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Level titles + HUD                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Configurable title for a level (e.g. "Explorer"). Falls back to "Level N".
	 *
	 * @param int $level Level number.
	 * @return string
	 */
	public static function level_title( $level ) {
		$level = max( 1, (int) $level );
		$raw   = (string) Mahan_Settings::get( 'level_titles', '' );
		$names = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) );
		if ( empty( $names ) ) {
			/* translators: %d: level number */
			return sprintf( __( 'Level %d', 'mahan-academy' ), $level );
		}
		// Map levels onto the provided names, holding the last for higher levels.
		$idx = min( count( $names ) - 1, $level - 1 );
		return $names[ $idx ];
	}

	/**
	 * Compact HUD payload for the front-end (includes progress to next level,
	 * today's XP vs. the daily goal, and available streak freezes).
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function hud( $user_id ) {
		$row      = self::get_stats( $user_id );
		$resolved = self::level_for_xp( $row['xp'] );
		return array(
			'xp'             => (int) $row['xp'],
			'level'          => $resolved['level'],
			'streak'         => (int) $row['streak'],
			'longest_streak' => (int) $row['longest_streak'],
			'hearts'         => (int) $row['hearts'],
			'freezes'        => (int) $row['freezes'],
			'xp_per_level'   => $resolved['xp_per_level'],
			'xp_into_level'  => $resolved['xp_into_level'],
			'level_title'    => self::level_title( $resolved['level'] ),
			'daily_xp'       => $user_id ? self::xp_today( $user_id ) : 0,
			'daily_goal'     => $user_id ? self::daily_goal( $user_id ) : 0,
		);
	}
}
