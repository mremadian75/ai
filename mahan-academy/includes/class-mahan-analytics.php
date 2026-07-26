<?php
/**
 * Admin analytics dashboard — windowed time series, funnel, drop-off points,
 * hardest exercises, live-assessment stats, and the SVG charts that show them.
 *
 * Everything here is read-only aggregation over the plugin's own tables.
 * Charts are generated server-side as inline SVG from internal numbers only —
 * no JS charting library, nothing user-controlled inside the markup. The one
 * request value that reaches SQL (the range) is whitelisted first, same as
 * every other admin screen.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Analytics {

	/** Selectable windows, in days. Anything else falls back to the default. */
	const RANGES        = array( 7, 30, 90, 365 );
	const DEFAULT_RANGE = 30;

	/** An exercise needs this many attempts before it can be called "hard". */
	const MIN_ATTEMPTS = 5;

	/* ------------------------------------------------------------------ */
	/* Input laundering                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The requested window, clamped to the whitelist.
	 *
	 * @param mixed $raw Raw request value.
	 * @return int Days.
	 */
	public static function range_days( $raw ) {
		$days = (int) $raw;
		return in_array( $days, self::RANGES, true ) ? $days : self::DEFAULT_RANGE;
	}

	/* ------------------------------------------------------------------ */
	/* Time series                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Daily activity for the window AND the window before it, in one sweep.
	 *
	 * Queries 2×$days of history once per metric, then splits in PHP — the
	 * previous window exists only to answer "up or down vs before?", so it
	 * needs sums, not its own charts.
	 *
	 * @param int $days Window length.
	 * @return array {days, enrollments, completions, lessons, xp, active,
	 *                totals, prev} — series are oldest-first, zero-filled.
	 */
	public static function series( $days ) {
		global $wpdb;
		$days     = max( 1, (int) $days );
		$enroll   = Mahan_DB::enrollments();
		$progress = Mahan_DB::progress();
		$xp_log   = Mahan_DB::xp_log();
		$today    = Mahan_Utils::today();

		$prev_from = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( 2 * $days - 1 ) . ' days' ) );
		$cur_from  = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		$since     = $prev_from . ' 00:00:00';
		$cur_since = $cur_from . ' 00:00:00';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$maps = array(
			'enrollments' => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(enrolled_at) AS d, COUNT(*) AS n FROM {$enroll} WHERE enrolled_at >= %s GROUP BY DATE(enrolled_at)", $since ), ARRAY_A ),
			'completions' => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(completed_at) AS d, COUNT(*) AS n FROM {$enroll} WHERE status = 'completed' AND completed_at IS NOT NULL AND completed_at >= %s GROUP BY DATE(completed_at)", $since ), ARRAY_A ),
			'lessons'     => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(completed_at) AS d, COUNT(*) AS n FROM {$progress} WHERE status = 'completed' AND completed_at IS NOT NULL AND completed_at >= %s GROUP BY DATE(completed_at)", $since ), ARRAY_A ),
			'xp'          => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS n FROM {$xp_log} WHERE created_at >= %s GROUP BY DATE(created_at)", $since ), ARRAY_A ),
			'active'      => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(DISTINCT user_id) AS n FROM {$xp_log} WHERE created_at >= %s GROUP BY DATE(created_at)", $since ), ARRAY_A ),
		);

		// "Active learners" as a window total must be distinct people, not a
		// sum of daily actives (one person on 30 days is one learner).
		$active_cur  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$xp_log} WHERE created_at >= %s", $cur_since ) );
		$active_prev = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$xp_log} WHERE created_at >= %s AND created_at < %s", $since, $cur_since ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$out = array(
			'days'   => self::day_keys( $cur_from, $days ),
			'totals' => array( 'active' => $active_cur ),
			'prev'   => array( 'active' => $active_prev ),
		);
		$prev_keys = self::day_keys( $prev_from, $days );

		foreach ( $maps as $key => $rows ) {
			$by_day = array();
			foreach ( (array) $rows as $r ) {
				$by_day[ (string) $r['d'] ] = (int) $r['n'];
			}
			$out[ $key ] = self::fill_days( $by_day, $out['days'] );
			if ( 'active' === $key ) {
				continue; // Window totals for actives were computed above.
			}
			$out['totals'][ $key ] = array_sum( $out[ $key ] );
			$out['prev'][ $key ]   = array_sum( self::fill_days( $by_day, $prev_keys ) );
		}
		return $out;
	}

	/**
	 * The consecutive day keys of a window, oldest first.
	 *
	 * @param string $from  First day (Y-m-d).
	 * @param int    $days  How many.
	 * @return string[]
	 */
	public static function day_keys( $from, $days ) {
		$out = array();
		$ts  = strtotime( $from . ' 00:00:00 UTC' );
		for ( $i = 0; $i < $days; $i++ ) {
			$out[] = gmdate( 'Y-m-d', $ts + $i * DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Zero-fill a sparse date=>value map onto a run of day keys.
	 *
	 * @param array    $by_day Sparse map.
	 * @param string[] $keys   Day keys, oldest first.
	 * @return int[] Dense series aligned with $keys.
	 */
	public static function fill_days( $by_day, $keys ) {
		$out = array();
		foreach ( $keys as $k ) {
			$out[] = isset( $by_day[ $k ] ) ? (int) $by_day[ $k ] : 0;
		}
		return $out;
	}

	/**
	 * Change vs the previous window, as something a human can read.
	 *
	 * @param int $cur  Current window total.
	 * @param int $prev Previous window total.
	 * @return array {dir: up|down|flat|new, pct: int|null}
	 */
	public static function delta( $cur, $prev ) {
		$cur  = (int) $cur;
		$prev = (int) $prev;
		if ( $prev <= 0 ) {
			return $cur > 0 ? array( 'dir' => 'new', 'pct' => null ) : array( 'dir' => 'flat', 'pct' => 0 );
		}
		$pct = (int) round( ( ( $cur - $prev ) / $prev ) * 100 );
		if ( 0 === $pct ) {
			return array( 'dir' => 'flat', 'pct' => 0 );
		}
		return array( 'dir' => $pct > 0 ? 'up' : 'down', 'pct' => $pct );
	}

	/* ------------------------------------------------------------------ */
	/* Funnel                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Enrolled → started → halfway → completed, over all enrollments.
	 *
	 * Each stage includes completed enrollments so the funnel can never go
	 * back up — a finished learner obviously started and passed halfway,
	 * whatever their progress_pct row happens to say.
	 *
	 * @return array {enrolled, started, halfway, completed}
	 */
	public static function funnel() {
		global $wpdb;
		$enroll = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS enrolled,
				SUM( CASE WHEN status = 'completed' OR progress_pct > 0 THEN 1 ELSE 0 END ) AS started,
				SUM( CASE WHEN status = 'completed' OR progress_pct >= 50 THEN 1 ELSE 0 END ) AS halfway,
				SUM( CASE WHEN status = 'completed' THEN 1 ELSE 0 END ) AS completed
			 FROM {$enroll}",
			ARRAY_A
		);
		return array(
			'enrolled'  => (int) ( $row['enrolled'] ?? 0 ),
			'started'   => (int) ( $row['started'] ?? 0 ),
			'halfway'   => (int) ( $row['halfway'] ?? 0 ),
			'completed' => (int) ( $row['completed'] ?? 0 ),
		);
	}

	/**
	 * A stage as % of enrolled, guarded against an empty academy.
	 *
	 * @param int $part  Stage count.
	 * @param int $whole Enrolled count.
	 * @return int 0–100.
	 */
	public static function pct( $part, $whole ) {
		return $whole > 0 ? (int) round( ( $part / $whole ) * 100 ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Course health                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The per-course table, extended with what per_course() can't see:
	 * quiz pass rate, exercise accuracy, live-assessment pass rate, and the
	 * lesson where stalled learners actually stopped.
	 *
	 * @return array[] per_course() rows + {quiz_pass, ex_accuracy, viva_pass,
	 *                 stall_lesson, stall_count} (rates are int|null — null
	 *                 renders as "—", meaning "no data", not "0%").
	 */
	public static function course_health() {
		global $wpdb;
		$attempts = Mahan_DB::attempts();
		$viva     = Mahan_DB::viva();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$attempt_rows = $wpdb->get_results(
			"SELECT course_id,
				SUM( CASE WHEN type <> 'quiz' THEN 1 ELSE 0 END ) AS ex_total,
				SUM( CASE WHEN type <> 'quiz' AND is_correct = 1 THEN 1 ELSE 0 END ) AS ex_correct,
				SUM( CASE WHEN type = 'quiz' THEN 1 ELSE 0 END ) AS quiz_total,
				SUM( CASE WHEN type = 'quiz' AND is_correct = 1 THEN 1 ELSE 0 END ) AS quiz_pass
			 FROM {$attempts} GROUP BY course_id",
			ARRAY_A
		);
		$viva_rows = $wpdb->get_results(
			"SELECT course_id,
				SUM( CASE WHEN status = 'passed' THEN 1 ELSE 0 END ) AS passed,
				SUM( CASE WHEN status IN ('passed','failed') THEN 1 ELSE 0 END ) AS decided
			 FROM {$viva} GROUP BY course_id",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$by_attempts = array();
		foreach ( (array) $attempt_rows as $r ) {
			$by_attempts[ (int) $r['course_id'] ] = $r;
		}
		$by_viva = array();
		foreach ( (array) $viva_rows as $r ) {
			$by_viva[ (int) $r['course_id'] ] = $r;
		}
		$stalls = self::stall_points();

		$out = array();
		foreach ( Mahan_Reports::per_course() as $row ) {
			$cid = (int) $row['id'];
			$a   = isset( $by_attempts[ $cid ] ) ? $by_attempts[ $cid ] : array();
			$v   = isset( $by_viva[ $cid ] ) ? $by_viva[ $cid ] : array();

			$ex_total   = (int) ( $a['ex_total'] ?? 0 );
			$quiz_total = (int) ( $a['quiz_total'] ?? 0 );
			$decided    = (int) ( $v['decided'] ?? 0 );

			$row['ex_accuracy'] = $ex_total > 0 ? (int) round( (int) $a['ex_correct'] / $ex_total * 100 ) : null;
			$row['quiz_pass']   = $quiz_total > 0 ? (int) round( (int) $a['quiz_pass'] / $quiz_total * 100 ) : null;
			$row['viva_pass']   = $decided > 0 ? (int) round( (int) $v['passed'] / $decided * 100 ) : null;

			$row['stall_lesson'] = 0;
			$row['stall_count']  = 0;
			if ( isset( $stalls[ $cid ] ) ) {
				$row['stall_lesson'] = (int) $stalls[ $cid ]['lesson_id'];
				$row['stall_count']  = (int) $stalls[ $cid ]['stalled'];
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Where non-completed learners stopped: for every unfinished enrollment,
	 * the learner's LAST completed lesson in that course — grouped, so each
	 * course can point at the lesson bleeding the most learners.
	 *
	 * One query for the whole catalog; the per-course maximum is picked in
	 * PHP by reduce_stalls().
	 *
	 * @return array course_id => {lesson_id, stalled}
	 */
	public static function stall_points() {
		global $wpdb;
		$progress = Mahan_DB::progress();
		$enroll   = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT p.course_id, p.lesson_id, COUNT(*) AS stalled
			 FROM {$progress} p
			 INNER JOIN (
				SELECT user_id, course_id, MAX(completed_at) AS mx
				FROM {$progress} WHERE status = 'completed' AND completed_at IS NOT NULL
				GROUP BY user_id, course_id
			 ) last ON last.user_id = p.user_id AND last.course_id = p.course_id AND last.mx = p.completed_at
			 INNER JOIN {$enroll} e ON e.user_id = p.user_id AND e.course_id = p.course_id AND e.status <> 'completed'
			 WHERE p.status = 'completed'
			 GROUP BY p.course_id, p.lesson_id",
			ARRAY_A
		);
		return self::reduce_stalls( (array) $rows );
	}

	/**
	 * Pick each course's worst stall from the grouped rows.
	 *
	 * Deterministic on ties: the higher count wins; equal counts keep the
	 * lower lesson_id, so reruns and tests never flap.
	 *
	 * @param array[] $rows {course_id, lesson_id, stalled} rows.
	 * @return array course_id => {lesson_id, stalled}
	 */
	public static function reduce_stalls( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$cid  = (int) $r['course_id'];
			$item = array( 'lesson_id' => (int) $r['lesson_id'], 'stalled' => (int) $r['stalled'] );
			if ( ! isset( $out[ $cid ] )
				|| $item['stalled'] > $out[ $cid ]['stalled']
				|| ( $item['stalled'] === $out[ $cid ]['stalled'] && $item['lesson_id'] < $out[ $cid ]['lesson_id'] ) ) {
				$out[ $cid ] = $item;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Hardest exercises                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The exercises learners get wrong the most — the course author's actual
	 * to-do list. Noise-gated: an exercise attempted fewer than MIN_ATTEMPTS
	 * times is not ranked at all.
	 *
	 * @param int $limit How many.
	 * @return array[] {course_id, lesson_id, exercise_key, type, attempts,
	 *                  correct, rate}
	 */
	public static function hardest_exercises( $limit = 10 ) {
		global $wpdb;
		$attempts = Mahan_DB::attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT course_id, lesson_id, exercise_key, type,
					COUNT(*) AS attempts, SUM(is_correct) AS correct
				 FROM {$attempts} WHERE type <> 'quiz'
				 GROUP BY course_id, lesson_id, exercise_key, type
				 HAVING COUNT(*) >= %d
				 ORDER BY ( SUM(is_correct) / COUNT(*) ) ASC, COUNT(*) DESC
				 LIMIT %d",
				self::MIN_ATTEMPTS,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$n     = (int) $r['attempts'];
			$out[] = array(
				'course_id'    => (int) $r['course_id'],
				'lesson_id'    => (int) $r['lesson_id'],
				'exercise_key' => (string) $r['exercise_key'],
				'type'         => (string) $r['type'],
				'attempts'     => $n,
				'correct'      => (int) $r['correct'],
				'rate'         => $n > 0 ? (int) round( (int) $r['correct'] / $n * 100 ) : 0,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Study pattern                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * XP events by weekday over the window — when learners actually study.
	 *
	 * @param int $days Window length.
	 * @return array {labels: string[7] Mon-first, values: int[7]}
	 */
	public static function weekday_activity( $days ) {
		global $wpdb;
		$xp_log = Mahan_DB::xp_log();
		$today  = Mahan_Utils::today();
		$since  = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( max( 1, (int) $days ) - 1 ) . ' days' ) ) . ' 00:00:00';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DAYOFWEEK(created_at) AS dw, COUNT(*) AS n FROM {$xp_log} WHERE created_at >= %s GROUP BY DAYOFWEEK(created_at)", $since ),
			ARRAY_A
		);
		return array(
			'labels' => array(
				__( 'Mon', 'mahan-academy' ),
				__( 'Tue', 'mahan-academy' ),
				__( 'Wed', 'mahan-academy' ),
				__( 'Thu', 'mahan-academy' ),
				__( 'Fri', 'mahan-academy' ),
				__( 'Sat', 'mahan-academy' ),
				__( 'Sun', 'mahan-academy' ),
			),
			'values' => self::weekday_counts( (array) $rows ),
		);
	}

	/**
	 * Map MySQL DAYOFWEEK rows (1 = Sunday … 7 = Saturday) onto a Monday-first
	 * week, because that is how humans read a study pattern.
	 *
	 * @param array[] $rows {dw, n} rows.
	 * @return int[7] Mon..Sun.
	 */
	public static function weekday_counts( $rows ) {
		$out = array_fill( 0, 7, 0 );
		foreach ( $rows as $r ) {
			$dw = (int) $r['dw'];
			if ( $dw < 1 || $dw > 7 ) {
				continue;
			}
			// DAYOFWEEK 2 (Monday) → slot 0 … 1 (Sunday) → slot 6.
			$out[ ( $dw + 5 ) % 7 ] = (int) $r['n'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Live assessments                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The viva at a glance: sittings by status, pass rate over decided
	 * sittings, and the average score of a pass.
	 *
	 * @return array {sittings, passed, failed, active, abandoned, pass_rate,
	 *                avg_pass_score} (rates int|null when nothing decided).
	 */
	public static function viva_stats() {
		global $wpdb;
		$viva = Mahan_DB::viva();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS n,
				AVG( CASE WHEN status = 'passed' AND max_score > 0 THEN score / max_score * 100 END ) AS avg_pct
			 FROM {$viva} GROUP BY status",
			ARRAY_A
		);
		$out = array(
			'sittings'       => 0,
			'passed'         => 0,
			'failed'         => 0,
			'active'         => 0,
			'abandoned'      => 0,
			'pass_rate'      => null,
			'avg_pass_score' => null,
		);
		foreach ( (array) $rows as $r ) {
			$status = (string) $r['status'];
			$n      = (int) $r['n'];
			$out['sittings'] += $n;
			if ( isset( $out[ $status ] ) && ! in_array( $status, array( 'sittings', 'pass_rate', 'avg_pass_score' ), true ) ) {
				$out[ $status ] = $n;
			}
			if ( 'passed' === $status && null !== $r['avg_pct'] ) {
				$out['avg_pass_score'] = (int) round( (float) $r['avg_pct'] );
			}
		}
		$decided = $out['passed'] + $out['failed'];
		if ( $decided > 0 ) {
			$out['pass_rate'] = (int) round( $out['passed'] / $decided * 100 );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* SVG charts (pure functions of internal numbers)                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Round a data maximum up to a 1/2/5×10ⁿ "nice" axis maximum.
	 *
	 * @param int|float $v Largest value in the series.
	 * @return int At least 1.
	 */
	public static function nice_max( $v ) {
		$v = (float) $v;
		if ( $v <= 1 ) {
			return 1;
		}
		$pow  = pow( 10, floor( log10( $v ) ) );
		$unit = $v / $pow;
		foreach ( array( 1, 2, 5, 10 ) as $step ) {
			if ( $unit <= $step ) {
				return (int) round( $step * $pow );
			}
		}
		return (int) round( 10 * $pow );
	}

	/**
	 * A tiny sparkline: area + line, colored by CSS via currentColor.
	 *
	 * @param int[] $values Series, oldest first.
	 * @param int   $w      Width.
	 * @param int   $h      Height.
	 * @return string SVG markup ('' when there is nothing to draw).
	 */
	public static function svg_spark( $values, $w = 140, $h = 38 ) {
		$values = array_values( array_map( 'intval', (array) $values ) );
		$n      = count( $values );
		if ( $n < 2 ) {
			return '';
		}
		$max = max( 1, max( $values ) );
		$pad = 2;
		$pts = array();
		foreach ( $values as $i => $v ) {
			$x     = $pad + ( $i / ( $n - 1 ) ) * ( $w - 2 * $pad );
			$y     = $h - $pad - ( $v / $max ) * ( $h - 2 * $pad );
			$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}
		$line = implode( ' ', $pts );
		$area = $pad . ',' . ( $h - $pad ) . ' ' . $line . ' ' . round( $w - $pad, 1 ) . ',' . ( $h - $pad );
		return '<svg class="mahan-an-spark" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="' . (int) $w . '" height="' . (int) $h . '" aria-hidden="true">'
			. '<polygon class="mahan-an-spark-area" points="' . $area . '"></polygon>'
			. '<polyline class="mahan-an-spark-line" points="' . $line . '" fill="none"></polyline>'
			. '</svg>';
	}

	/**
	 * The main multi-series line chart with a y axis and three date labels.
	 *
	 * @param array[]  $series Each {key, label, values}; values align with $days.
	 * @param string[] $days   Day keys (Y-m-d), oldest first.
	 * @param int      $w      Width.
	 * @param int      $h      Height.
	 * @return string SVG markup.
	 */
	public static function svg_chart( $series, $days, $w = 920, $h = 240 ) {
		$n = count( $days );
		if ( $n < 2 || empty( $series ) ) {
			return '';
		}
		$max = 1;
		foreach ( $series as $s ) {
			$vals = array_map( 'intval', (array) $s['values'] );
			if ( $vals ) {
				$max = max( $max, max( $vals ) );
			}
		}
		$max = self::nice_max( $max );

		$left   = 42;
		$bottom = 22;
		$top    = 8;
		$right  = 10;
		$iw     = $w - $left - $right;
		$ih     = $h - $top - $bottom;

		$svg = '<svg class="mahan-an-chart" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="100%" height="' . (int) $h . '" role="img">';

		// Grid + y labels at 0, ½ and max.
		foreach ( array( 0, 0.5, 1 ) as $f ) {
			$y    = round( $top + $ih - $f * $ih, 1 );
			$svg .= '<line class="mahan-an-grid" x1="' . $left . '" y1="' . $y . '" x2="' . ( $w - $right ) . '" y2="' . $y . '"></line>';
			$svg .= '<text class="mahan-an-tick" x="' . ( $left - 6 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end">' . esc_html( number_format_i18n( (int) round( $f * $max ) ) ) . '</text>';
		}

		// X labels: first, middle, last day.
		$marks = array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 );
		foreach ( array_unique( $marks ) as $i ) {
			$x      = round( $left + ( $i / ( $n - 1 ) ) * $iw, 1 );
			$anchor = 0 === $i ? 'start' : ( $i === $n - 1 ? 'end' : 'middle' );
			$svg   .= '<text class="mahan-an-tick" x="' . $x . '" y="' . ( $h - 6 ) . '" text-anchor="' . $anchor . '">' . esc_html( mysql2date( 'M j', $days[ $i ] . ' 00:00:00' ) ) . '</text>';
		}

		foreach ( $series as $s ) {
			$vals = array_values( array_map( 'intval', (array) $s['values'] ) );
			$pts  = array();
			foreach ( $vals as $i => $v ) {
				$x     = $left + ( $i / ( $n - 1 ) ) * $iw;
				$y     = $top + $ih - ( $v / $max ) * $ih;
				$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
			}
			$key  = sanitize_key( $s['key'] );
			$svg .= '<polyline class="mahan-an-line is-' . esc_attr( $key ) . '" points="' . implode( ' ', $pts ) . '" fill="none"></polyline>';
			// A dot on the latest point, so "today" reads at a glance.
			$last = explode( ',', end( $pts ) );
			$svg .= '<circle class="mahan-an-dot is-' . esc_attr( $key ) . '" cx="' . $last[0] . '" cy="' . $last[1] . '" r="3"></circle>';
		}
		return $svg . '</svg>';
	}

	/**
	 * Weekday bars.
	 *
	 * @param int[]    $values 7 values, Mon-first.
	 * @param string[] $labels 7 labels.
	 * @param int      $w      Width.
	 * @param int      $h      Height.
	 * @return string SVG markup.
	 */
	public static function svg_bars( $values, $labels, $w = 440, $h = 150 ) {
		$values = array_values( array_map( 'intval', (array) $values ) );
		$n      = count( $values );
		if ( 0 === $n ) {
			return '';
		}
		$max    = max( 1, max( $values ) );
		$bottom = 20;
		$top    = 14;
		$ih     = $h - $top - $bottom;
		$slot   = $w / $n;
		$bw     = round( $slot * 0.56, 1 );

		$svg = '<svg class="mahan-an-weekbars" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="100%" height="' . (int) $h . '" role="img">';
		foreach ( $values as $i => $v ) {
			$bh   = round( ( $v / $max ) * $ih, 1 );
			$x    = round( $i * $slot + ( $slot - $bw ) / 2, 1 );
			$y    = round( $top + $ih - $bh, 1 );
			$cx   = round( $i * $slot + $slot / 2, 1 );
			$svg .= '<rect class="mahan-an-bar" x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . max( $bh, 1 ) . '" rx="3"></rect>';
			if ( $v > 0 ) {
				$svg .= '<text class="mahan-an-tick" x="' . $cx . '" y="' . ( $y - 4 ) . '" text-anchor="middle">' . esc_html( number_format_i18n( $v ) ) . '</text>';
			}
			$svg .= '<text class="mahan-an-tick" x="' . $cx . '" y="' . ( $h - 5 ) . '" text-anchor="middle">' . esc_html( isset( $labels[ $i ] ) ? $labels[ $i ] : '' ) . '</text>';
		}
		return $svg . '</svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Activity CSV                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The day-by-day rows behind the charts, aligned by index.
	 *
	 * @param array $series series() output.
	 * @return array[] {date, enrollments, completions, lessons, xp, active}
	 */
	public static function activity_rows( $series ) {
		$out = array();
		foreach ( $series['days'] as $i => $day ) {
			$out[] = array(
				'date'        => $day,
				'enrollments' => (int) $series['enrollments'][ $i ],
				'completions' => (int) $series['completions'][ $i ],
				'lessons'     => (int) $series['lessons'][ $i ],
				'xp'          => (int) $series['xp'][ $i ],
				'active'      => (int) $series['active'][ $i ],
			);
		}
		return $out;
	}

	/**
	 * Export the selected window as CSV (admin-post handler).
	 */
	public static function export_activity_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'mahan-academy' ) );
		}
		check_admin_referer( 'mahan_export_activity' );

		$days = self::range_days( isset( $_GET['range'] ) ? wp_unslash( $_GET['range'] ) : 0 );
		$rows = self::activity_rows( self::series( $days ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mahan-activity-' . (int) $days . 'd-' . gmdate( 'Ymd' ) . '.csv' );

		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, array( 'Date', 'Enrollments', 'Completions', 'Lessons completed', 'XP awarded', 'Active learners' ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array( $r['date'], $r['enrollments'], $r['completions'], $r['lessons'], $r['xp'], $r['active'] ) );
		}
		fclose( $fh );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* The dashboard                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the whole Reports screen. Mahan_Admin::render_reports() delegates
	 * here so the page can be rendered (and tested) without the admin shell.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$days    = self::range_days( isset( $_GET['range'] ) ? wp_unslash( $_GET['range'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$series  = self::series( $days );
		$funnel  = self::funnel();
		$week    = self::weekday_activity( $days );
		$viva    = self::viva_stats();
		$health  = self::course_health();
		$hardest = self::hardest_exercises( 10 );

		$o      = Mahan_Reports::overview();
		$recent = Mahan_Reports::recent( 15 );
		$top    = Mahan_Reports::top_learners( 10 );

		$certs       = Mahan_Reports::certificates( 25 );
		$cert_total  = Mahan_Reports::certificate_count();
		$placement   = Mahan_Reports::placement_spread();
		$export      = wp_nonce_url( admin_url( 'admin-post.php?action=mahan_export_csv' ), 'mahan_export' );
		$cert_export = wp_nonce_url( admin_url( 'admin-post.php?action=mahan_export_certs' ), 'mahan_export_certs' );
		$act_export  = wp_nonce_url( admin_url( 'admin-post.php?action=mahan_export_activity&range=' . $days ), 'mahan_export_activity' );

		$base = admin_url( 'admin.php?page=mahan-reports' );

		$range_labels = array(
			7   => __( 'Last 7 days', 'mahan-academy' ),
			30  => __( 'Last 30 days', 'mahan-academy' ),
			90  => __( 'Last 90 days', 'mahan-academy' ),
			365 => __( 'Last year', 'mahan-academy' ),
		);

		$heroes = array(
			array( 'enrollments', __( 'Enrollments', 'mahan-academy' ) ),
			array( 'active', __( 'Active learners', 'mahan-academy' ) ),
			array( 'lessons', __( 'Lessons completed', 'mahan-academy' ) ),
			array( 'xp', __( 'XP awarded', 'mahan-academy' ) ),
		);

		$chart = self::svg_chart(
			array(
				array( 'key' => 'lessons', 'label' => __( 'Lessons completed', 'mahan-academy' ), 'values' => $series['lessons'] ),
				array( 'key' => 'enrollments', 'label' => __( 'Enrollments', 'mahan-academy' ), 'values' => $series['enrollments'] ),
				array( 'key' => 'completions', 'label' => __( 'Course completions', 'mahan-academy' ), 'values' => $series['completions'] ),
			),
			$series['days']
		);

		$funnel_rows = array(
			array( __( 'Enrolled', 'mahan-academy' ), $funnel['enrolled'] ),
			array( __( 'Started', 'mahan-academy' ), $funnel['started'] ),
			array( __( 'Halfway', 'mahan-academy' ), $funnel['halfway'] ),
			array( __( 'Completed', 'mahan-academy' ), $funnel['completed'] ),
		);

		$all_time = array(
			array( __( 'Learners', 'mahan-academy' ), number_format_i18n( $o['learners'] ) ),
			array( __( 'Enrollments', 'mahan-academy' ), number_format_i18n( $o['enrollments'] ) ),
			array( __( 'Course completions', 'mahan-academy' ), number_format_i18n( $o['completions'] ) ),
			array( __( 'Total XP', 'mahan-academy' ), number_format_i18n( $o['total_xp'] ) ),
			array( __( 'Lessons completed', 'mahan-academy' ), number_format_i18n( $o['lessons_done'] ) ),
			array( __( 'Exercise accuracy', 'mahan-academy' ), $o['exercise_accuracy'] . '%' ),
			array( __( 'Quiz pass rate', 'mahan-academy' ), $o['quiz_pass_rate'] . '%' ),
			array( __( 'Active today', 'mahan-academy' ), number_format_i18n( $o['active_today'] ) ),
		);
		?>
		<div class="wrap mahan-admin-wrap mahan-an">
			<h1><?php esc_html_e( 'Mahan Academy — Reports', 'mahan-academy' ); ?></h1>

			<div class="mahan-an-toolbar">
				<nav class="mahan-an-ranges" aria-label="<?php esc_attr_e( 'Date range', 'mahan-academy' ); ?>">
					<?php foreach ( $range_labels as $r => $label ) : ?>
						<a class="mahan-an-range<?php echo $r === $days ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $r, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<a class="button" href="<?php echo esc_url( $act_export ); ?>"><?php esc_html_e( 'Export activity (CSV)', 'mahan-academy' ); ?></a>
			</div>

			<div class="mahan-an-heroes">
				<?php foreach ( $heroes as $hero ) : list( $key, $label ) = $hero; ?>
					<?php $d = self::delta( $series['totals'][ $key ], $series['prev'][ $key ] ); ?>
					<div class="mahan-an-hero">
						<span class="mahan-an-hero-label"><?php echo esc_html( $label ); ?></span>
						<span class="mahan-an-hero-num"><?php echo esc_html( number_format_i18n( $series['totals'][ $key ] ) ); ?></span>
						<span class="mahan-an-delta is-<?php echo esc_attr( $d['dir'] ); ?>">
							<?php
							if ( 'new' === $d['dir'] ) {
								esc_html_e( 'New', 'mahan-academy' );
							} elseif ( 'flat' === $d['dir'] ) {
								esc_html_e( 'No change', 'mahan-academy' );
							} else {
								echo esc_html( ( $d['pct'] > 0 ? '+' : '' ) . $d['pct'] . '%' );
							}
							?>
							<span class="mahan-an-delta-vs"><?php esc_html_e( 'vs previous period', 'mahan-academy' ); ?></span>
						</span>
						<?php echo self::svg_spark( $series[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mahan-an-panel">
				<div class="mahan-an-panel-head">
					<h2><?php esc_html_e( 'Activity', 'mahan-academy' ); ?></h2>
					<span class="mahan-an-legend">
						<span class="mahan-an-key is-lessons"><?php esc_html_e( 'Lessons completed', 'mahan-academy' ); ?></span>
						<span class="mahan-an-key is-enrollments"><?php esc_html_e( 'Enrollments', 'mahan-academy' ); ?></span>
						<span class="mahan-an-key is-completions"><?php esc_html_e( 'Course completions', 'mahan-academy' ); ?></span>
					</span>
				</div>
				<?php if ( array_sum( $series['lessons'] ) + array_sum( $series['enrollments'] ) + array_sum( $series['completions'] ) > 0 ) : ?>
					<?php echo $chart; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php else : ?>
					<p class="mahan-muted mahan-an-empty"><?php esc_html_e( 'No activity in this period yet.', 'mahan-academy' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="mahan-an-cols">
				<div class="mahan-an-panel">
					<h2><?php esc_html_e( 'Learning funnel', 'mahan-academy' ); ?></h2>
					<p class="mahan-muted"><?php esc_html_e( 'Of everyone who ever enrolled, how far they get.', 'mahan-academy' ); ?></p>
					<div class="mahan-an-funnel">
						<?php foreach ( $funnel_rows as $row ) : list( $label, $count ) = $row; ?>
							<?php $p = self::pct( $count, $funnel['enrolled'] ); ?>
							<div class="mahan-an-stage">
								<span class="mahan-an-stage-label"><?php echo esc_html( $label ); ?></span>
								<span class="mahan-an-stage-bar"><span style="width:<?php echo (int) $p; ?>%"></span></span>
								<span class="mahan-an-stage-num"><?php echo esc_html( number_format_i18n( $count ) ); ?> <em><?php echo esc_html( $p ); ?>%</em></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="mahan-an-panel">
					<h2><?php esc_html_e( 'Study pattern', 'mahan-academy' ); ?></h2>
					<p class="mahan-muted"><?php esc_html_e( 'When your learners actually study, by weekday.', 'mahan-academy' ); ?></p>
					<?php if ( array_sum( $week['values'] ) > 0 ) : ?>
						<?php echo self::svg_bars( $week['values'], $week['labels'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php else : ?>
						<p class="mahan-muted mahan-an-empty"><?php esc_html_e( 'No activity in this period yet.', 'mahan-academy' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $viva['sittings'] > 0 ) : ?>
				<h2><?php esc_html_e( 'Live assessments', 'mahan-academy' ); ?></h2>
				<div class="mahan-cards mahan-report-cards mahan-an-viva">
					<div class="mahan-card"><span class="mahan-card-num"><?php echo esc_html( number_format_i18n( $viva['sittings'] ) ); ?></span><span class="mahan-card-label"><?php esc_html_e( 'Sittings', 'mahan-academy' ); ?></span></div>
					<div class="mahan-card"><span class="mahan-card-num"><?php echo esc_html( number_format_i18n( $viva['passed'] ) ); ?></span><span class="mahan-card-label"><?php esc_html_e( 'Passed', 'mahan-academy' ); ?></span></div>
					<div class="mahan-card"><span class="mahan-card-num"><?php echo esc_html( null === $viva['pass_rate'] ? '—' : $viva['pass_rate'] . '%' ); ?></span><span class="mahan-card-label"><?php esc_html_e( 'Pass rate', 'mahan-academy' ); ?></span></div>
					<div class="mahan-card"><span class="mahan-card-num"><?php echo esc_html( null === $viva['avg_pass_score'] ? '—' : $viva['avg_pass_score'] . '%' ); ?></span><span class="mahan-card-label"><?php esc_html_e( 'Average passing score', 'mahan-academy' ); ?></span></div>
					<div class="mahan-card"><span class="mahan-card-num"><?php echo esc_html( number_format_i18n( $viva['abandoned'] ) ); ?></span><span class="mahan-card-label"><?php esc_html_e( 'Abandoned', 'mahan-academy' ); ?></span></div>
				</div>
			<?php endif; ?>

			<h2>
				<?php esc_html_e( 'Course health', 'mahan-academy' ); ?>
				<a class="button button-secondary" style="margin-inline-start:8px" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'mahan-academy' ); ?></a>
			</h2>
			<table class="widefat striped mahan-an-health">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Course', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Enrolled', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Completed', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Completion', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Avg progress', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Exercise accuracy', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Quiz pass', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Live pass', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Drop-off point', 'mahan-academy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $health ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No courses yet.', 'mahan-academy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $health as $c ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $c['id'] ) ); ?>"><?php echo esc_html( $c['title'] ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( $c['enrolled'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $c['completed'] ) ); ?></td>
								<td><?php echo esc_html( $c['completion'] . '%' ); ?></td>
								<td><?php echo esc_html( $c['avg_progress'] . '%' ); ?></td>
								<td><?php echo esc_html( null === $c['ex_accuracy'] ? '—' : $c['ex_accuracy'] . '%' ); ?></td>
								<td><?php echo esc_html( null === $c['quiz_pass'] ? '—' : $c['quiz_pass'] . '%' ); ?></td>
								<td><?php echo esc_html( null === $c['viva_pass'] ? '—' : $c['viva_pass'] . '%' ); ?></td>
								<td>
									<?php if ( $c['stall_lesson'] > 0 ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $c['stall_lesson'] ) ); ?>"><?php echo esc_html( get_the_title( $c['stall_lesson'] ) ); ?></a>
										<span class="mahan-muted">
											<?php
											/* translators: %s: number of learners whose last completed lesson this is. */
											echo esc_html( sprintf( _n( '%s learner stopped here', '%s learners stopped here', $c['stall_count'], 'mahan-academy' ), number_format_i18n( $c['stall_count'] ) ) );
											?>
										</span>
									<?php else : ?>
										<span class="mahan-muted">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Hardest exercises', 'mahan-academy' ); ?></h2>
			<p class="mahan-muted">
				<?php
				/* translators: %d: minimum number of attempts before an exercise is ranked. */
				echo esc_html( sprintf( __( 'The exercises learners get wrong the most — your revision list. Only exercises with at least %d attempts are ranked.', 'mahan-academy' ), self::MIN_ATTEMPTS ) );
				?>
			</p>
			<table class="widefat striped mahan-an-hardest">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Lesson', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Exercise', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Correct rate', 'mahan-academy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $hardest ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Not enough attempts yet to rank exercises.', 'mahan-academy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $hardest as $x ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $x['lesson_id'] ) ); ?>"><?php echo esc_html( get_the_title( $x['lesson_id'] ) ); ?></a>
									<span class="mahan-muted">· <?php echo esc_html( get_the_title( $x['course_id'] ) ); ?></span>
								</td>
								<td><code><?php echo esc_html( $x['exercise_key'] ); ?></code></td>
								<td><?php echo esc_html( $x['type'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $x['attempts'] ) ); ?></td>
								<td>
									<span class="mahan-an-rate<?php echo $x['rate'] < 40 ? ' is-low' : ''; ?>"><?php echo esc_html( $x['rate'] ); ?>%</span>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'All time', 'mahan-academy' ); ?></h2>
			<div class="mahan-cards mahan-report-cards">
				<?php foreach ( $all_time as $c ) : ?>
					<div class="mahan-card">
						<span class="mahan-card-num"><?php echo esc_html( $c[1] ); ?></span>
						<span class="mahan-card-label"><?php echo esc_html( $c[0] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mahan-report-cols">
				<div>
					<h2><?php esc_html_e( 'Top learners', 'mahan-academy' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th>#</th><th><?php esc_html_e( 'Learner', 'mahan-academy' ); ?></th><th>XP</th><th><?php esc_html_e( 'Level', 'mahan-academy' ); ?></th><th>🔥</th></tr></thead>
						<tbody>
							<?php if ( empty( $top ) ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No learners yet.', 'mahan-academy' ); ?></td></tr>
							<?php else : foreach ( $top as $i => $l ) : ?>
								<tr>
									<td><?php echo esc_html( $i + 1 ); ?></td>
									<td><?php echo esc_html( $l['name'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $l['xp'] ) ); ?></td>
									<td><?php echo esc_html( $l['level'] ); ?></td>
									<td><?php echo esc_html( $l['streak'] ); ?></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
				<div>
					<h2><?php esc_html_e( 'Recent completions', 'mahan-academy' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Learner', 'mahan-academy' ); ?></th><th><?php esc_html_e( 'Lesson', 'mahan-academy' ); ?></th><th><?php esc_html_e( 'When', 'mahan-academy' ); ?></th></tr></thead>
						<tbody>
							<?php if ( empty( $recent ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No activity yet.', 'mahan-academy' ); ?></td></tr>
							<?php else : foreach ( $recent as $r ) : ?>
								<tr>
									<td><?php echo esc_html( $r['user'] ); ?></td>
									<td><?php echo esc_html( $r['lesson'] ); ?> <span class="mahan-muted">· <?php echo esc_html( $r['course'] ); ?></span></td>
									<td><?php echo esc_html( human_time_diff( strtotime( $r['when'] ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'mahan-academy' ); ?></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<h2><?php esc_html_e( 'Placement levels', 'mahan-academy' ); ?></h2>
			<p class="mahan-muted">
				<?php
				printf(
					/* translators: %s: number of learners who have taken the placement test. */
					esc_html__( '%s learners have taken the placement test. This is who your catalog is actually being read by.', 'mahan-academy' ),
					esc_html( number_format_i18n( (int) $placement['tested'] ) )
				);
				?>
			</p>
			<table class="widefat striped" style="max-width:520px">
				<thead><tr><th><?php esc_html_e( 'Level', 'mahan-academy' ); ?></th><th><?php esc_html_e( 'Learners', 'mahan-academy' ); ?></th></tr></thead>
				<tbody>
					<?php
					$level_labels = array(
						'beginner'     => __( 'Beginner', 'mahan-academy' ),
						'intermediate' => __( 'Intermediate', 'mahan-academy' ),
						'advanced'     => __( 'Advanced', 'mahan-academy' ),
						'expert'       => __( 'Expert', 'mahan-academy' ),
					);
					foreach ( $level_labels as $key => $label ) :
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $placement[ $key ] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:26px">
				<?php esc_html_e( 'Certificates issued', 'mahan-academy' ); ?>
				<span class="mahan-muted">(<?php echo esc_html( number_format_i18n( $cert_total ) ); ?>)</span>
			</h2>
			<p>
				<a class="button" href="<?php echo esc_url( $cert_export ); ?>"><?php esc_html_e( 'Export certificates (CSV)', 'mahan-academy' ); ?></a>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Serial', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Course', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Issued', 'mahan-academy' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $certs ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No certificates issued yet.', 'mahan-academy' ); ?></td></tr>
					<?php else : foreach ( $certs as $c ) : ?>
						<tr>
							<td><code><?php echo esc_html( $c['serial'] ); ?></code></td>
							<td><?php echo esc_html( $c['user'] ); ?></td>
							<td><?php echo esc_html( $c['course'] ); ?></td>
							<td><?php echo esc_html( $c['issued_at'] ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
