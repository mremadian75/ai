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
				'goal_date'         => null,
				'goal_streak'       => 0,
				'longest_goal_streak' => 0,
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
		// Added in DB v7; rows written before it read as nulls/zeroes.
		$row['goal_date']           = isset( $row['goal_date'] ) ? $row['goal_date'] : null;
		$row['goal_streak']         = isset( $row['goal_streak'] ) ? (int) $row['goal_streak'] : 0;
		$row['longest_goal_streak'] = isset( $row['longest_goal_streak'] ) ? (int) $row['longest_goal_streak'] : 0;
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
			'goal_date'      => null,
			'goal_streak'    => 0,
			'longest_goal_streak' => 0,
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

		$leveled_up = self::sync_level( $user_id );

		// Checked after the award is written, so today's total includes it.
		// It can pay a bonus, which may itself cross a level threshold — hence
		// the second sync rather than trusting the one above.
		$goal = self::maybe_award_goal( $user_id );
		if ( $goal && $goal['bonus'] > 0 ) {
			$leveled_up = self::sync_level( $user_id ) || $leveled_up;
		}

		$row               = self::get_stats( $user_id );
		$row['awarded']    = $total;
		$row['leveled_up'] = $leveled_up;
		$row['goal_met']   = $goal;
		return $row;
	}

	/**
	 * Bring the stored level in line with total XP under the active curve.
	 *
	 * @param int $user_id Learner.
	 * @return bool Whether the learner moved up (never true on a drop).
	 */
	private static function sync_level( $user_id ) {
		global $wpdb;
		$row      = self::get_stats( $user_id );
		$resolved = self::level_for_xp( $row['xp'] );
		if ( $resolved['level'] === $row['level'] ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Mahan_DB::stats() . ' SET level = %d, updated_at = %s WHERE user_id = %d',
				$resolved['level'],
				Mahan_Utils::now_mysql(),
				(int) $user_id
			)
		);
		if ( $resolved['level'] > $row['level'] ) {
			do_action( 'mahan_level_up', $user_id, $resolved['level'] );
			return true;
		}
		return false;
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

		// Which days were banked, as recorded at the time — not recomputed
		// against today's goal, which would let a settings change rewrite the
		// past in both directions.
		$banked = self::goal_days( $user_id, $since );

		$out = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$day = $today->modify( '-' . $i . ' days' );
			$key = $day->format( 'Y-m-d' );
			$xp  = isset( $per_day[ $key ] ) ? $per_day[ $key ] : 0;
			$out[] = array(
				'label'    => wp_date( 'D', $day->getTimestamp(), $tz ),
				'active'   => $xp > 0,
				'goal_met' => isset( $banked[ $key ] ),
				'today'    => 0 === $i,
			);
		}
		return $out;
	}

	/**
	 * A longer activity map for the profile, one entry per day.
	 *
	 * The seven-day strip answers "am I on track this week". It cannot show a
	 * habit, and a habit is the thing a learner is actually trying to build —
	 * so the profile gets a run of weeks instead, with the XP kept per day so
	 * the UI can show intensity rather than a flat on/off.
	 *
	 * @param int $user_id Learner.
	 * @param int $days    How far back to go (clamped to a sane range).
	 * @return array[] { date, xp, level:0-4, today:bool }
	 */
	public static function activity_map( $user_id, $days = 91 ) {
		$user_id = (int) $user_id;
		$days    = max( 7, min( 371, (int) $days ) );
		if ( ! $user_id ) {
			return array();
		}

		global $wpdb;
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today = new DateTimeImmutable( 'now', $tz );
		$table = Mahan_DB::xp_log();
		$since = $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ) . ' 00:00:00';

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

		// Intensity is relative to the learner's own goal, not to their best
		// day ever — otherwise one enormous session makes every ordinary day
		// afterwards look like a failure.
		$goal = self::daily_goal( $user_id );
		$goal = $goal > 0 ? $goal : 50;

		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = $today->modify( '-' . $i . ' days' );
			$key = $day->format( 'Y-m-d' );
			$xp  = isset( $per_day[ $key ] ) ? $per_day[ $key ] : 0;

			if ( $xp <= 0 ) {
				$level = 0;
			} elseif ( $xp >= $goal * 2 ) {
				$level = 4;
			} elseif ( $xp >= $goal ) {
				$level = 3;
			} elseif ( $xp >= $goal / 2 ) {
				$level = 2;
			} else {
				$level = 1;
			}

			$out[] = array(
				'date'  => $key,
				'xp'    => $xp,
				'level' => $level,
				'today' => 0 === $i,
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
	 * Bank today's daily goal if it has just been reached.
	 *
	 * The goal used to be a progress bar with nothing behind it: you set a
	 * target, the ring filled, and nothing happened. Worse, whether a *past*
	 * day counted was recomputed against your *current* goal every time the
	 * week strip rendered — so lowering your goal retroactively ticked days you
	 * had missed, and raising it un-ticked days you had earned.
	 *
	 * Meeting the goal is now an event: it pays a bonus and writes a `goal` row
	 * into the XP log. That row is the permanent record, so history stops
	 * moving when the setting does.
	 *
	 * The claim is a guarded UPDATE — `WHERE goal_date <> today` — so two
	 * requests finishing at the same moment cannot both pay the bonus. MySQL
	 * picks one winner; the loser sees 0 affected rows and pays nothing.
	 *
	 * @param int $user_id Learner.
	 * @return array|null { bonus, goal, streak } when banked this call, else null.
	 */
	public static function maybe_award_goal( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return null;
		}
		$goal = self::daily_goal( $user_id );
		if ( $goal <= 0 ) {
			return null; // No goal set (and none by default) — nothing to meet.
		}
		$row = self::get_stats( $user_id );
		$today = Mahan_Utils::today();
		if ( (string) $row['goal_date'] === $today ) {
			return null; // Already banked today.
		}
		if ( self::xp_today( $user_id ) < $goal ) {
			return null; // Not there yet.
		}

		// Yesterday's claim makes this a run; anything older restarts it.
		$streak = 1;
		if ( $row['goal_date'] ) {
			$diff = Mahan_Utils::date_diff_days( (string) $row['goal_date'], $today );
			if ( 1 === $diff ) {
				$streak = (int) $row['goal_streak'] + 1;
			}
		}
		$longest = max( (int) $row['longest_goal_streak'], $streak );

		global $wpdb;
		$table = Mahan_DB::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET goal_date = %s, goal_streak = %d, longest_goal_streak = %d, updated_at = %s
				 WHERE user_id = %d AND ( goal_date IS NULL OR goal_date <> %s )",
				$today,
				$streak,
				$longest,
				Mahan_Utils::now_mysql(),
				$user_id,
				$today
			)
		);
		if ( ! $claimed ) {
			return null; // Another request banked it first.
		}

		$bonus = max( 0, (int) Mahan_Settings::get( 'daily_goal_bonus', 15 ) );
		if ( $bonus > 0 ) {
			// Logged with reason 'goal' — this row is both the reward and the
			// record that the day was met. add_xp() would re-enter this method,
			// so the XP is written directly.
			self::log_flat_xp( $user_id, $bonus, 'goal' );
		}
		do_action( 'mahan_daily_goal_met', $user_id, $streak );

		return array( 'bonus' => $bonus, 'goal' => $goal, 'streak' => $streak );
	}

	/**
	 * Add XP without the streak bonus and without re-checking the daily goal.
	 *
	 * Used for the goal bonus itself: paying it through add_xp() would recurse
	 * into maybe_award_goal(), and a bonus for hitting a target should not
	 * itself be multiplied by the streak.
	 *
	 * @param int    $user_id Learner.
	 * @param int    $amount  XP.
	 * @param string $reason  Log slug.
	 */
	private static function log_flat_xp( $user_id, $amount, $reason ) {
		global $wpdb;
		$now = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare( 'UPDATE ' . Mahan_DB::stats() . ' SET xp = xp + %d, updated_at = %s WHERE user_id = %d', (int) $amount, $now, (int) $user_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Mahan_DB::xp_log(),
			array(
				'user_id'    => (int) $user_id,
				'amount'     => (int) $amount,
				'reason'     => sanitize_key( $reason ),
				'ref_id'     => 0,
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * The days in a range whose daily goal was actually banked.
	 *
	 * Read from the `goal` rows in the XP log — facts recorded on the day —
	 * rather than recomputed against whatever the goal happens to be now.
	 *
	 * @param int    $user_id Learner.
	 * @param string $since   'Y-m-d H:i:s' lower bound.
	 * @return array Y-m-d => true
	 */
	public static function goal_days( $user_id, $since ) {
		global $wpdb;
		$table = Mahan_DB::xp_log();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT DATE(created_at) FROM {$table}
				 WHERE user_id = %d AND reason = 'goal' AND created_at >= %s",
				(int) $user_id,
				$since
			)
		);
		$out = array();
		foreach ( (array) $rows as $d ) {
			$out[ (string) $d ] = true;
		}
		return $out;
	}

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
			// Whether today's goal has actually been banked, and the run of
			// consecutive days it belongs to. A goal streak is a stronger
			// signal than the activity streak: it means the learner hit the
			// target they set, not that they opened one lesson.
			'goal_done'      => $user_id ? ( (string) $row['goal_date'] === Mahan_Utils::today() ) : false,
			'goal_streak'    => (int) $row['goal_streak'],
			'longest_goal_streak' => (int) $row['longest_goal_streak'],
			// Carried on every stats payload so the SPA's reviews-due badge
			// stays live after grading (a new miss must surface immediately,
			// not wait for a full /me refetch).
			'reviews'        => $user_id && class_exists( 'Mahan_Reviews' )
				? Mahan_Reviews::counts( $user_id )
				: array( 'due' => 0, 'learning' => 0, 'mastered' => 0 ),
		);
	}
}
