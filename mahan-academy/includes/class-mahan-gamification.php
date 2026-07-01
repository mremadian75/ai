<?php
/**
 * XP, level, and daily-streak engine. All numbers come from settings so admins
 * can tune the curve without touching code.
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
				'updated_at'        => Mahan_Utils::now_mysql(),
			);
		}
		// Normalize types.
		$row['xp']             = (int) $row['xp'];
		$row['level']          = (int) $row['level'];
		$row['streak']         = (int) $row['streak'];
		$row['longest_streak'] = (int) $row['longest_streak'];
		$row['hearts']         = (int) $row['hearts'];
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
		);
	}

	private static function ensure_row( $user_id ) {
		global $wpdb;
		$now = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Mahan_DB::stats() .
				' (user_id, xp, level, streak, longest_streak, hearts, hearts_updated_at, last_active_date, updated_at)' .
				' VALUES (%d, 0, 1, 0, 0, %d, %s, NULL, %s)',
				(int) $user_id,
				(int) Mahan_Settings::get( 'hearts_max', 5 ),
				$now,
				$now
			)
		);
	}

	/**
	 * Award XP and recompute level. Returns the post-update stats.
	 *
	 * @param int $user_id User id.
	 * @param int $amount  XP amount.
	 * @return array
	 */
	public static function add_xp( $user_id, $amount ) {
		$user_id = (int) $user_id;
		$amount  = (int) $amount;
		if ( ! $user_id || $amount <= 0 ) {
			return self::get_stats( $user_id );
		}
		self::ensure_row( $user_id );

		global $wpdb;
		$table = Mahan_DB::stats();
		$now   = Mahan_Utils::now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET xp = xp + %d, updated_at = %s WHERE user_id = %d",
				$amount,
				$now,
				$user_id
			)
		);

		// Recompute level from XP.
		$row = self::get_stats( $user_id );
		$per = max( 10, (int) Mahan_Settings::get( 'level_curve', 100 ) );
		$new_level = max( 1, (int) floor( $row['xp'] / $per ) + 1 );
		if ( $new_level !== $row['level'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET level = %d, updated_at = %s WHERE user_id = %d",
					$new_level,
					$now,
					$user_id
				)
			);
			$row['level'] = $new_level;
			do_action( 'mahan_level_up', $user_id, $new_level );
		}
		return $row;
	}

	/**
	 * Record activity for today; bump streak if it's a new day.
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

		$streak = 1;
		if ( $last ) {
			$diff = Mahan_Utils::date_diff_days( $last, $today );
			if ( 1 === $diff ) {
				$streak = $row['streak'] + 1;
			} else {
				$streak = 1;
			}
		}
		$longest = max( (int) $row['longest_streak'], $streak );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET streak = %d, longest_streak = %d, last_active_date = %s, updated_at = %s WHERE user_id = %d",
				$streak,
				$longest,
				$today,
				$now,
				$user_id
			)
		);
		$row['streak']           = $streak;
		$row['longest_streak']   = $longest;
		$row['last_active_date'] = $today;
		do_action( 'mahan_streak_updated', $user_id, $streak );
		return $row;
	}

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
	 * Compact HUD payload for the front-end (includes progress to next level).
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function hud( $user_id ) {
		$row = self::get_stats( $user_id );
		$per = max( 10, (int) Mahan_Settings::get( 'level_curve', 100 ) );
		$into = $row['xp'] % $per;
		return array(
			'xp'             => (int) $row['xp'],
			'level'          => (int) $row['level'],
			'streak'         => (int) $row['streak'],
			'longest_streak' => (int) $row['longest_streak'],
			'hearts'         => (int) $row['hearts'],
			'xp_per_level'   => $per,
			'xp_into_level'  => $into,
			'level_title'    => self::level_title( (int) $row['level'] ),
		);
	}
}
