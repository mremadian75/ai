<?php
/**
 * Admin analytics — read-only aggregates over the plugin's tables for the
 * Reports page and CSV export.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Reports {

	/* ------------------------------------------------------------------ */
	/* Overview KPIs                                                       */
	/* ------------------------------------------------------------------ */

	public static function overview() {
		global $wpdb;
		$stats    = Mahan_DB::stats();
		$enroll   = Mahan_DB::enrollments();
		$progress = Mahan_DB::progress();
		$attempts = Mahan_DB::attempts();
		$today    = Mahan_Utils::today();
		$week     = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$attempt_totals = $wpdb->get_row(
			"SELECT
				SUM( CASE WHEN type <> 'quiz' THEN 1 ELSE 0 END ) AS ex_total,
				SUM( CASE WHEN type <> 'quiz' AND is_correct = 1 THEN 1 ELSE 0 END ) AS ex_correct,
				SUM( CASE WHEN type = 'quiz' THEN 1 ELSE 0 END ) AS quiz_total,
				SUM( CASE WHEN type = 'quiz' AND is_correct = 1 THEN 1 ELSE 0 END ) AS quiz_pass
			 FROM {$attempts}",
			ARRAY_A
		);
		$out = array(
			'learners'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$stats}" ),
			'enrollments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$enroll}" ),
			'completions'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$enroll} WHERE status = 'completed'" ),
			'active_today'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$stats} WHERE last_active_date = %s", $today ) ),
			'active_week'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$stats} WHERE last_active_date >= %s", $week ) ),
			'total_xp'      => (int) $wpdb->get_var( "SELECT COALESCE(SUM(xp),0) FROM {$stats}" ),
			'lessons_done'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$progress} WHERE status = 'completed'" ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$ex_total   = (int) ( $attempt_totals['ex_total'] ?? 0 );
		$ex_correct = (int) ( $attempt_totals['ex_correct'] ?? 0 );
		$quiz_total = (int) ( $attempt_totals['quiz_total'] ?? 0 );
		$quiz_pass  = (int) ( $attempt_totals['quiz_pass'] ?? 0 );

		$out['exercise_attempts'] = $ex_total;
		$out['exercise_accuracy'] = $ex_total > 0 ? (int) round( ( $ex_correct / $ex_total ) * 100 ) : 0;
		$out['quiz_attempts']     = $quiz_total;
		$out['quiz_pass_rate']    = $quiz_total > 0 ? (int) round( ( $quiz_pass / $quiz_total ) * 100 ) : 0;

		// XP awarded in the last 7 days (exact, from the XP log).
		$xp_log = Mahan_DB::xp_log();
		$since  = gmdate( 'Y-m-d 00:00:00', strtotime( $today . ' -6 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out['xp_week'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$xp_log} WHERE created_at >= %s", $since ) );

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Per-course table                                                    */
	/* ------------------------------------------------------------------ */

	public static function per_course() {
		global $wpdb;
		$enroll = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT course_id,
				COUNT(*) AS enrolled,
				SUM( CASE WHEN status = 'completed' THEN 1 ELSE 0 END ) AS completed,
				ROUND(AVG(progress_pct)) AS avg_progress
			 FROM {$enroll} GROUP BY course_id",
			ARRAY_A
		);
		$by_course = array();
		foreach ( (array) $rows as $r ) {
			$by_course[ (int) $r['course_id'] ] = $r;
		}

		$out = array();
		foreach ( Mahan_Courses::get_courses() as $course ) {
			$cid = (int) $course->ID;
			$r   = isset( $by_course[ $cid ] ) ? $by_course[ $cid ] : array( 'enrolled' => 0, 'completed' => 0, 'avg_progress' => 0 );
			$enrolled  = (int) $r['enrolled'];
			$completed = (int) $r['completed'];
			$out[] = array(
				'id'           => $cid,
				'title'        => get_the_title( $cid ),
				'enrolled'     => $enrolled,
				'completed'    => $completed,
				'completion'   => $enrolled > 0 ? (int) round( ( $completed / $enrolled ) * 100 ) : 0,
				'avg_progress' => (int) $r['avg_progress'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Recent activity + top learners                                      */
	/* ------------------------------------------------------------------ */

	public static function recent( $limit = 20 ) {
		global $wpdb;
		$progress = Mahan_DB::progress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, lesson_id, course_id, completed_at
				 FROM {$progress} WHERE status = 'completed' AND completed_at IS NOT NULL
				 ORDER BY completed_at DESC LIMIT %d",
				(int) $limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$user = get_userdata( (int) $r['user_id'] );
			$out[] = array(
				'user'    => $user ? $user->display_name : __( '(deleted user)', 'mahan-academy' ),
				'lesson'  => get_the_title( (int) $r['lesson_id'] ),
				'course'  => get_the_title( (int) $r['course_id'] ),
				'when'    => $r['completed_at'],
			);
		}
		return $out;
	}

	public static function top_learners( $limit = 10 ) {
		global $wpdb;
		$stats = Mahan_DB::stats();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, xp, level, streak FROM {$stats} WHERE xp > 0 ORDER BY xp DESC LIMIT %d", (int) $limit ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$user = get_userdata( (int) $r['user_id'] );
			if ( ! $user ) {
				continue;
			}
			$out[] = array(
				'name'   => $user->display_name,
				'xp'     => (int) $r['xp'],
				'level'  => (int) $r['level'],
				'streak' => (int) $r['streak'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* CSV export (per-course)                                             */
	/* ------------------------------------------------------------------ */

	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'mahan-academy' ) );
		}
		check_admin_referer( 'mahan_export' );

		$rows = self::per_course();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mahan-course-report-' . gmdate( 'Ymd' ) . '.csv' );

		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, array( 'Course ID', 'Course', 'Enrolled', 'Completed', 'Completion %', 'Avg progress %' ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array( $r['id'], $r['title'], $r['enrolled'], $r['completed'], $r['completion'], $r['avg_progress'] ) );
		}
		fclose( $fh );
		exit;
	}
}
