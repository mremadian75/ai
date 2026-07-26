<?php
/**
 * Completion certificates — issued, recorded, and verifiable.
 *
 * Before this existed the "certificate" was a card the browser drew on demand,
 * stamped with *today's* date: reopen it next month and the date changed, and
 * there was nothing anyone could check. A credential nobody can verify isn't a
 * credential.
 *
 * Now finishing a course writes a row with a serial. The serial is what makes
 * it a credential: anyone can look it up without logging in, and the record
 * carries the date it was actually earned.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Certificates {

	/** Serial prefix. */
	const PREFIX = 'MA';

	/**
	 * Wire auto-issue. A certificate should never depend on the learner
	 * finding a button — completing the course is the trigger.
	 */
	public static function init() {
		add_action( 'mahan_course_completed', array( __CLASS__, 'on_course_completed' ), 10, 2 );
	}

	/**
	 * @param int $user_id   User id.
	 * @param int $course_id Course id.
	 */
	public static function on_course_completed( $user_id, $course_id ) {
		self::issue( $user_id, $course_id );
	}

	/**
	 * Whether a course offers a certificate at all (per-course toggle plus
	 * the site-wide switch).
	 *
	 * @param int $course_id Course id.
	 * @return bool
	 */
	public static function offered( $course_id ) {
		return (bool) Mahan_Utils::meta_int( $course_id, Mahan_Courses::M_CERTIFICATE, 0 )
			&& (bool) Mahan_Settings::get( 'certificate_enabled', 1 );
	}

	/**
	 * Issue a certificate, or return the existing one.
	 *
	 * Idempotent on purpose: a learner can complete the last lesson, have
	 * progress recomputed, or re-take the course — none of that should mint a
	 * second serial for the same achievement.
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course id.
	 * @return array|null The certificate row, or null if not offered.
	 */
	public static function issue( $user_id, $course_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		if ( $user_id < 1 || $course_id < 1 || ! self::offered( $course_id ) ) {
			return null;
		}

		$existing = self::for_course( $user_id, $course_id );
		if ( $existing ) {
			return $existing;
		}

		$table = Mahan_DB::certificates();
		$row   = array(
			'user_id'   => $user_id,
			'course_id' => $course_id,
			'serial'    => self::new_serial(),
			'issued_at' => Mahan_Utils::now_mysql(),
			'revoked'   => 0,
		);
		// The unique index on (user_id, course_id) is the real guard: two
		// concurrent completions both pass the check above, and the loser's
		// insert fails instead of creating a duplicate credential.
		$ok = $wpdb->insert( $table, $row, array( '%d', '%d', '%s', '%s', '%d' ) );
		if ( false === $ok ) {
			return self::for_course( $user_id, $course_id );
		}

		$issued = self::for_course( $user_id, $course_id );
		if ( $issued ) {
			do_action( 'mahan_certificate_issued', $user_id, $course_id, $issued['serial'] );
		}
		return $issued;
	}

	/**
	 * A serial that is readable aloud and not guessable by counting.
	 *
	 * Sequential ids would let anyone enumerate every credential the site has
	 * ever issued, so the random part carries the entropy. Ambiguous glyphs
	 * (0/O, 1/I) are left out because these get typed off a printout.
	 *
	 * @return string e.g. MA-2026-7F3KQX92
	 */
	public static function new_serial() {
		global $wpdb;
		$alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
		$table    = Mahan_DB::certificates();
		$year     = gmdate( 'Y' );

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$body = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$body .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
			}
			$serial = self::PREFIX . '-' . $year . '-' . $body;
			$hit    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE serial = %s", $serial ) ); // phpcs:ignore WordPress.DB
			if ( ! $hit ) {
				return $serial;
			}
		}
		// Astronomically unlikely; fall back to something guaranteed unique
		// rather than returning a colliding serial.
		return self::PREFIX . '-' . $year . '-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 10 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Reads                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int $user_id   User id.
	 * @param int $course_id Course id.
	 * @return array|null
	 */
	public static function for_course( $user_id, $course_id ) {
		global $wpdb;
		$table = Mahan_DB::certificates();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d", (int) $user_id, (int) $course_id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Every certificate a learner holds, newest first.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = Mahan_DB::certificates();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND revoked = 0 ORDER BY issued_at DESC, id DESC", (int) $user_id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::shape( $row );
		}
		return $out;
	}

	/**
	 * Look a certificate up by serial. Public — that's the point of a serial.
	 *
	 * Returns only what a verifier needs: who, what, when. No email, no user
	 * id, no progress data.
	 *
	 * @param string $serial Serial.
	 * @return array|null
	 */
	public static function verify( $serial ) {
		global $wpdb;
		$serial = self::normalize_serial( $serial );
		if ( '' === $serial ) {
			return null;
		}
		$table = Mahan_DB::certificates();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE serial = %s", $serial ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		if ( ! $row || (int) $row['revoked'] === 1 ) {
			return null;
		}
		$shaped = self::shape( $row );
		return array(
			'serial'       => $shaped['serial'],
			'recipient'    => $shaped['recipient'],
			'course_title' => $shaped['course_title'],
			'issued_at'    => $shaped['issued_at'],
			'issuer'       => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Serials get typed off paper: accept lower case, stray spaces, and
	 * missing dashes rather than telling a real holder their real serial is
	 * invalid.
	 *
	 * @param string $serial Raw input.
	 * @return string Normalized, or '' if it can't be one.
	 */
	public static function normalize_serial( $serial ) {
		$s = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $serial ) );
		// PREFIX + 4-digit year + 8-10 char body.
		if ( ! preg_match( '/^' . self::PREFIX . '(\d{4})([A-Z0-9]{8,10})$/', $s, $m ) ) {
			return '';
		}
		return self::PREFIX . '-' . $m[1] . '-' . $m[2];
	}

	/**
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function shape( array $row ) {
		$user   = get_userdata( (int) $row['user_id'] );
		$course = (int) $row['course_id'];
		return array(
			'id'           => (int) $row['id'],
			'serial'       => (string) $row['serial'],
			'course_id'    => $course,
			'course_title' => get_the_title( $course ),
			'recipient'    => $user ? $user->display_name : '',
			'issued_at'    => (string) $row['issued_at'],
			'revoked'      => (int) $row['revoked'] === 1,
		);
	}

	/**
	 * Back-fill for learners who finished a course before certificates were
	 * recorded. Without this, everyone who completed anything up to v1.21
	 * would hold nothing, and their only route to a certificate would be to
	 * re-take a course they've already done.
	 *
	 * @return int How many were issued.
	 */
	public static function backfill() {
		global $wpdb;
		$enrollments = Mahan_DB::enrollments();
		$rows        = $wpdb->get_results( "SELECT user_id, course_id FROM {$enrollments} WHERE progress_pct >= 100", ARRAY_A ); // phpcs:ignore WordPress.DB
		$n = 0;
		foreach ( (array) $rows as $row ) {
			$user_id   = (int) $row['user_id'];
			$course_id = (int) $row['course_id'];
			// Only count the ones actually minted — issue() returns the
			// existing row for anyone who already holds one.
			if ( self::for_course( $user_id, $course_id ) ) {
				continue;
			}
			if ( self::issue( $user_id, $course_id ) ) {
				$n++;
			}
		}
		return $n;
	}
}
