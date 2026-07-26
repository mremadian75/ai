<?php
/**
 * Custom tables for the dynamic LMS data: enrollments, lesson progress,
 * exercise attempts, gamification stats, AI chat history, an AI cache, the
 * spaced-repetition review queue, issued certificates, and live AI oral-exam
 * (viva) sessions.
 *
 * CPTs hold the *content* (courses & lessons); these tables hold the
 * relational, per-user, fast-changing data.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_DB {

	const OPT_DB_VERSION = 'mahan_db_version';

	/**
	 * Run on plugins_loaded — upgrade schema if needed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::OPT_DB_VERSION, '' );
		if ( MAHAN_DB_VERSION !== $installed ) {
			self::create_tables();
			update_option( self::OPT_DB_VERSION, MAHAN_DB_VERSION );
		}
	}

	/**
	 * Run on activation.
	 */
	public static function install() {
		self::create_tables();
		update_option( self::OPT_DB_VERSION, MAHAN_DB_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Table names                                                         */
	/* ------------------------------------------------------------------ */

	public static function enrollments() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_enrollments';
	}

	public static function progress() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_progress';
	}

	public static function attempts() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_attempts';
	}

	public static function stats() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_stats';
	}

	public static function chat() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_chat';
	}

	public static function ai_cache() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_ai_cache';
	}

	public static function xp_log() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_xp_log';
	}

	public static function reviews() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_reviews';
	}

	public static function certificates() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_certificates';
	}

	public static function viva() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_viva';
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                              */
	/* ------------------------------------------------------------------ */

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$enrollments = self::enrollments();
		$progress    = self::progress();
		$attempts    = self::attempts();
		$stats       = self::stats();
		$chat        = self::chat();
		$ai_cache    = self::ai_cache();
		$xp_log      = self::xp_log();
		$reviews     = self::reviews();
		$certs       = self::certificates();
		$viva        = self::viva();

		$sql = array();

		$sql[] = "CREATE TABLE {$enrollments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
			enrolled_at DATETIME NOT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_course (user_id, course_id),
			KEY course (course_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$progress} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
			score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			xp_awarded INT UNSIGNED NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_lesson (user_id, lesson_id),
			KEY user_course (user_id, course_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$attempts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			exercise_key VARCHAR(64) NOT NULL,
			type VARCHAR(32) NOT NULL DEFAULT '',
			user_answer LONGTEXT NULL,
			is_correct TINYINT(1) NOT NULL DEFAULT 0,
			score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			xp_awarded INT UNSIGNED NOT NULL DEFAULT 0,
			feedback LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_exercise (user_id, lesson_id, exercise_key),
			KEY user_lesson (user_id, lesson_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$stats} (
			user_id BIGINT UNSIGNED NOT NULL,
			xp BIGINT UNSIGNED NOT NULL DEFAULT 0,
			level INT UNSIGNED NOT NULL DEFAULT 1,
			streak INT UNSIGNED NOT NULL DEFAULT 0,
			longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
			hearts TINYINT NOT NULL DEFAULT 5,
			hearts_updated_at DATETIME NULL DEFAULT NULL,
			last_active_date DATE NULL DEFAULT NULL,
			freezes TINYINT UNSIGNED NOT NULL DEFAULT 0,
			daily_goal SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			goal_date DATE NULL DEFAULT NULL,
			goal_streak INT UNSIGNED NOT NULL DEFAULT 0,
			longest_goal_streak INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$xp_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			amount INT NOT NULL DEFAULT 0,
			reason VARCHAR(32) NOT NULL DEFAULT '',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id, created_at),
			KEY created (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$chat} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			role VARCHAR(16) NOT NULL DEFAULT 'user',
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_lesson (user_id, lesson_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$ai_cache} (
			cache_key CHAR(64) NOT NULL,
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (cache_key)
		) {$charset};";

		// Adaptive review queue (spaced repetition of wrong answers).
		$sql[] = "CREATE TABLE {$reviews} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(16) NOT NULL DEFAULT 'exercise',
			item_key VARCHAR(128) NOT NULL,
			qtype VARCHAR(32) NOT NULL DEFAULT '',
			concept VARCHAR(190) NOT NULL DEFAULT '',
			snapshot LONGTEXT NULL,
			box TINYINT UNSIGNED NOT NULL DEFAULT 0,
			reps INT UNSIGNED NOT NULL DEFAULT 0,
			lapses INT UNSIGNED NOT NULL DEFAULT 0,
			last_result TINYINT(1) NOT NULL DEFAULT 0,
			last_xp_date DATE NULL DEFAULT NULL,
			due_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_item (user_id, item_key),
			KEY user_due (user_id, due_at),
			KEY user_lesson (user_id, lesson_id)
		) {$charset};";

		// Issued completion certificates. The unique key on (user_id,
		// course_id) is what makes issuing idempotent under concurrency: a
		// second completion event loses the insert instead of minting a
		// duplicate credential. `serial` is unique because it is the public
		// lookup key.
		$sql[] = "CREATE TABLE {$certs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			serial VARCHAR(32) NOT NULL,
			issued_at DATETIME NOT NULL,
			revoked TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_course (user_id, course_id),
			UNIQUE KEY serial (serial),
			KEY course (course_id)
		) {$charset};";

		// Live AI oral-exam (viva) sessions. One row per sitting, carrying the
		// whole conversation, so a learner who closes the tab mid-exam comes
		// back to the same question rather than a fresh one. `pending` holds
		// the current question *and* the grader's rubric for it — that stays
		// server-side, which is what makes the score unforgeable.
		$sql[] = "CREATE TABLE {$viva} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			unit VARCHAR(190) NOT NULL DEFAULT '',
			stage TINYINT UNSIGNED NOT NULL DEFAULT 1,
			turn TINYINT UNSIGNED NOT NULL DEFAULT 1,
			attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
			score INT UNSIGNED NOT NULL DEFAULT 0,
			max_score INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			pending LONGTEXT NULL,
			transcript LONGTEXT NULL,
			xp_awarded INT UNSIGNED NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_course (user_id, course_id),
			KEY user_status (user_id, status)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Drop all custom tables (used by uninstall when "remove data" is on).
	 */
	public static function drop_tables() {
		global $wpdb;
		$tables = array(
			self::enrollments(),
			self::progress(),
			self::attempts(),
			self::stats(),
			self::chat(),
			self::ai_cache(),
			self::xp_log(),
			self::reviews(),
			// Certificates shipped in 1.22.0 and viva in 1.28.0; both were
			// created by create_tables() so both have to be dropped here, or
			// "remove all data" leaves rows behind.
			self::certificates(),
			self::viva(),
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/* ------------------------------------------------------------------ */
	/* AI cache helpers                                                    */
	/* ------------------------------------------------------------------ */

	public static function cache_get( $key, $ttl_seconds = 0 ) {
		global $wpdb;
		$table = self::ai_cache();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT content, created_at FROM {$table} WHERE cache_key = %s", $key ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		if ( $ttl_seconds > 0 ) {
			$created = strtotime( (string) $row['created_at'] );
			if ( ! $created || ( time() - $created ) > $ttl_seconds ) {
				return null;
			}
		}
		return (string) $row['content'];
	}

	public static function cache_set( $key, $content ) {
		global $wpdb;
		$table = self::ai_cache();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$table,
			array(
				'cache_key'  => $key,
				'content'    => (string) $content,
				'created_at' => Mahan_Utils::now_mysql(),
			),
			array( '%s', '%s', '%s' )
		);
	}
}
