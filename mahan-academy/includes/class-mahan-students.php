<?php
/**
 * Students — the admin's view of the people actually learning.
 *
 * One screen answers the operator questions the Reports page can't: who is
 * enrolled, who is stuck, who went quiet, and what do I do about THIS person.
 * A searchable, sortable, filterable roster opens into a per-student file:
 * profile, stats, every enrollment with its progress, certificates, and the
 * three actions an operator actually needs — enroll, unenroll, reset progress
 * — plus certificate revoke/restore.
 *
 * Boundaries, stated once:
 * - **Unenroll keeps progress.** Re-enrolling resumes where they left off.
 *   Destroying work should never be a side effect of a membership change.
 * - **Reset progress is the destructive one** (progress + attempts + review
 *   queue for that course), and it says so before it does it.
 * - **Certificates survive both.** They are issued credentials, not cache;
 *   the only way to withdraw one is an explicit revoke, which is reversible
 *   and leaves the row in place.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Students {

	const PAGE     = 'mahan-students';
	const PER_PAGE = 20;

	/** Columns the list may be ordered by → the SQL that orders them. */
	const ORDERBY = array(
		'name'        => 'u.display_name ASC',
		'xp'          => 'xp DESC',
		'last_active' => 'last_active DESC',
		'enrolled'    => 'enrolled DESC',
		'completed'   => 'completed DESC',
	);

	public static function init() {
		add_action( 'admin_post_mahan_student_enroll', array( __CLASS__, 'handle_enroll' ) );
		add_action( 'admin_post_mahan_student_unenroll', array( __CLASS__, 'handle_unenroll' ) );
		add_action( 'admin_post_mahan_student_reset', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_post_mahan_student_cert', array( __CLASS__, 'handle_cert' ) );
		add_action( 'admin_post_mahan_export_students', array( __CLASS__, 'export_csv' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Query                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Whitelist + clamp the raw request into safe query arguments.
	 *
	 * Everything here reaches SQL, so nothing here may pass through raw:
	 * orderby resolves against {@see ORDERBY} or falls back, activity against
	 * its three known values, paging is clamped to sane bounds.
	 *
	 * @param array $raw Raw request args.
	 * @return array { search, course, activity, orderby, paged, per_page }
	 */
	public static function normalize_args( $raw ) {
		$orderby  = isset( $raw['orderby'] ) ? sanitize_key( (string) $raw['orderby'] ) : '';
		$activity = isset( $raw['activity'] ) ? sanitize_key( (string) $raw['activity'] ) : '';
		return array(
			'search'   => isset( $raw['s'] ) ? sanitize_text_field( (string) $raw['s'] ) : '',
			'course'   => isset( $raw['course'] ) ? absint( $raw['course'] ) : 0,
			'activity' => in_array( $activity, array( 'active7', 'inactive30' ), true ) ? $activity : '',
			'orderby'  => isset( self::ORDERBY[ $orderby ] ) ? $orderby : 'last_active',
			// (int), not absint(): "?paged=-5" must clamp to page 1, not
			// silently become page 5.
			'paged'    => isset( $raw['paged'] ) ? max( 1, min( 10000, (int) $raw['paged'] ) ) : 1,
			'per_page' => isset( $raw['per_page'] ) ? max( 1, min( 100, (int) $raw['per_page'] ) ) : self::PER_PAGE,
		);
	}

	/**
	 * The roster: one row per learner, with the aggregates the list shows.
	 *
	 * A "student" is anyone with an enrollment or a stats row — a bare
	 * subscriber who never touched the academy doesn't belong on this screen.
	 *
	 * @param array $args From {@see normalize_args()}.
	 * @return array { rows: array[], total: int, pages: int }
	 */
	public static function query( $args ) {
		global $wpdb;
		$args    = self::normalize_args( $args );
		$enroll  = Mahan_DB::enrollments();
		$stats   = Mahan_DB::stats();
		$certs   = Mahan_DB::certificates();
		$users   = $wpdb->users;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( $args['course'] ) {
			$where[]  = "u.ID IN ( SELECT user_id FROM {$enroll} WHERE course_id = %d )";
			$params[] = $args['course'];
		}
		if ( 'active7' === $args['activity'] ) {
			$where[] = 's.last_active_date >= ' . "DATE_SUB( CURDATE(), INTERVAL 7 DAY )";
		} elseif ( 'inactive30' === $args['activity'] ) {
			$where[] = "( s.last_active_date IS NULL OR s.last_active_date < DATE_SUB( CURDATE(), INTERVAL 30 DAY ) )";
		}

		$where_sql = implode( ' AND ', $where );
		$order_sql = self::ORDERBY[ $args['orderby'] ];
		$offset    = ( $args['paged'] - 1 ) * $args['per_page'];

		$base = "FROM {$users} u
			LEFT JOIN {$stats} s ON s.user_id = u.ID
			LEFT JOIN ( SELECT user_id,
					COUNT(*) AS enrolled,
					SUM( CASE WHEN status = 'completed' THEN 1 ELSE 0 END ) AS completed
				FROM {$enroll} GROUP BY user_id ) e ON e.user_id = u.ID
			LEFT JOIN ( SELECT user_id, COUNT(*) AS certs
				FROM {$certs} WHERE revoked = 0 GROUP BY user_id ) c ON c.user_id = u.ID
			WHERE ( e.user_id IS NOT NULL OR s.user_id IS NOT NULL ) AND {$where_sql}";

		$count_sql = "SELECT COUNT(*) {$base}";
		$rows_sql  = "SELECT u.ID, u.display_name, u.user_email,
				COALESCE( s.xp, 0 ) AS xp,
				COALESCE( s.level, 1 ) AS level,
				COALESCE( s.streak, 0 ) AS streak,
				s.last_active_date AS last_active,
				COALESCE( e.enrolled, 0 ) AS enrolled,
				COALESCE( e.completed, 0 ) AS completed,
				COALESCE( c.certs, 0 ) AS certs
			{$base} ORDER BY {$order_sql}, u.ID ASC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$row_params   = $params;
		$row_params[] = $args['per_page'];
		$row_params[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $row_params ), ARRAY_A );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $args['per_page'] ),
			'args'  => $args,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Mutations                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Remove the enrollment row — and nothing else. Progress, attempts, and
	 * certificates all stay, so re-enrolling resumes rather than restarts.
	 */
	public static function unenroll( $user_id, $course_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete(
			Mahan_DB::enrollments(),
			array(
				'user_id'   => (int) $user_id,
				'course_id' => (int) $course_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Wipe one learner's work in one course: lesson progress, exercise/quiz
	 * attempts, and their review queue for it. The enrollment (if any) is put
	 * back to a fresh start instead of deleted; certificates and the XP audit
	 * log are deliberately untouched.
	 */
	public static function reset_progress( $user_id, $course_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		$pair      = array(
			'user_id'   => $user_id,
			'course_id' => $course_id,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Mahan_DB::progress(), $pair, array( '%d', '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Mahan_DB::attempts(), $pair, array( '%d', '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Mahan_DB::reviews(), $pair, array( '%d', '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Mahan_DB::enrollments(),
			array(
				'status'       => 'active',
				'progress_pct' => 0,
				'completed_at' => null,
			),
			$pair,
			array( '%s', '%d', null ),
			array( '%d', '%d' )
		);
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Student file (detail data)                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Everything the per-student page shows, in one bundle.
	 *
	 * @param int $user_id User id.
	 * @return array|null Null when the user doesn't exist.
	 */
	public static function file_for( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$stats   = Mahan_Gamification::get_stats( $user_id );
		$totals  = Mahan_Learner::totals( $user_id );
		$profile = class_exists( 'Mahan_Profile' ) ? Mahan_Profile::get_profile( $user_id ) : array();

		// Enrollments with course titles, newest first.
		global $wpdb;
		$enroll = Mahan_DB::enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$enrollments = $wpdb->get_results(
			$wpdb->prepare( "SELECT course_id, status, progress_pct, enrolled_at, completed_at FROM {$enroll} WHERE user_id = %d ORDER BY enrolled_at DESC", $user_id ),
			ARRAY_A
		);

		// Recent XP activity — what have they actually been doing?
		$log = Mahan_DB::xp_log();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$activity = $wpdb->get_results(
			$wpdb->prepare( "SELECT amount, reason, ref_id, created_at FROM {$log} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT 12", $user_id ),
			ARRAY_A
		);

		// Certificates including revoked ones — the admin needs to see (and
		// undo) a revocation, which the learner-facing list hides.
		$certs = Mahan_DB::certificates();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$certificates = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, course_id, serial, issued_at, revoked FROM {$certs} WHERE user_id = %d ORDER BY issued_at DESC", $user_id ),
			ARRAY_A
		);

		return array(
			'user'         => $user,
			'stats'        => $stats,
			'totals'       => $totals,
			'profile'      => is_array( $profile ) ? $profile : array(),
			'placement'    => class_exists( 'Mahan_Placement' ) ? Mahan_Placement::get( $user_id ) : null,
			'enrollments'  => is_array( $enrollments ) ? $enrollments : array(),
			'activity'     => is_array( $activity ) ? $activity : array(),
			'certificates' => is_array( $certificates ) ? $certificates : array(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Action handlers (admin_post)                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Shared guard: capability + nonce + the two ids every action needs.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return array { user_id, course_id }
	 */
	private static function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'mahan-academy' ) );
		}
		check_admin_referer( $nonce_action );
		return array(
			'user_id'   => isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0,
			'course_id' => isset( $_REQUEST['course_id'] ) ? absint( $_REQUEST['course_id'] ) : 0,
		);
	}

	private static function back_to_student( $user_id, $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE,
					'student' => (int) $user_id,
					'notice'  => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_enroll() {
		$ids = self::guard( 'mahan_student_enroll' );
		if ( $ids['user_id'] && $ids['course_id'] && Mahan_CPT::COURSE === get_post_type( $ids['course_id'] ) ) {
			Mahan_Enrollment::enroll( $ids['user_id'], $ids['course_id'] );
		}
		self::back_to_student( $ids['user_id'], 'enrolled' );
	}

	public static function handle_unenroll() {
		$ids = self::guard( 'mahan_student_unenroll_' . ( isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0 ) );
		if ( $ids['user_id'] && $ids['course_id'] ) {
			self::unenroll( $ids['user_id'], $ids['course_id'] );
		}
		self::back_to_student( $ids['user_id'], 'unenrolled' );
	}

	public static function handle_reset() {
		$ids = self::guard( 'mahan_student_reset_' . ( isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0 ) );
		if ( $ids['user_id'] && $ids['course_id'] ) {
			self::reset_progress( $ids['user_id'], $ids['course_id'] );
		}
		self::back_to_student( $ids['user_id'], 'reset' );
	}

	/**
	 * Revoke or restore one certificate. The row never leaves the table —
	 * a withdrawn credential is a fact worth keeping, not deleting.
	 */
	public static function handle_cert() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'mahan-academy' ) );
		}
		$cert_id = isset( $_GET['cert_id'] ) ? absint( $_GET['cert_id'] ) : 0;
		check_admin_referer( 'mahan_student_cert_' . $cert_id );
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$revoke  = isset( $_GET['revoke'] ) && '1' === (string) $_GET['revoke'];

		global $wpdb;
		// The user_id in the WHERE pins the certificate to the student whose
		// file the admin is looking at — a stale or forged cert_id can't flip
		// someone else's credential.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Mahan_DB::certificates(),
			array( 'revoked' => $revoke ? 1 : 0 ),
			array(
				'id'      => $cert_id,
				'user_id' => $user_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
		self::back_to_student( $user_id, $revoke ? 'cert_revoked' : 'cert_restored' );
	}

	/**
	 * CSV of the roster under the CURRENT filters — what you see is what you
	 * export, minus pagination.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'mahan-academy' ) );
		}
		check_admin_referer( 'mahan_export_students' );

		$args             = self::normalize_args( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args['paged']    = 1;
		$args['per_page'] = 10000;
		$result           = self::query( $args );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mahan-students-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Name', 'Email', 'XP', 'Level', 'Streak', 'Enrolled', 'Completed', 'Certificates', 'Last active' ) );
		foreach ( $result['rows'] as $r ) {
			fputcsv(
				$out,
				array(
					self::csv_safe( $r['display_name'] ),
					self::csv_safe( $r['user_email'] ),
					(int) $r['xp'],
					(int) $r['level'],
					(int) $r['streak'],
					(int) $r['enrolled'],
					(int) $r['completed'],
					(int) $r['certs'],
					(string) $r['last_active'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Neutralise spreadsheet formula injection: a display name of "=CMD(...)"
	 * must open as text, not execute.
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$student = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap mahan-admin-wrap mahan-students-wrap">';
		if ( $student ) {
			self::render_detail( $student );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function notice() {
		$notice = isset( $_GET['notice'] ) ? sanitize_key( (string) $_GET['notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'enrolled'      => __( 'Student enrolled.', 'mahan-academy' ),
			'unenrolled'    => __( 'Student unenrolled. Their progress is kept.', 'mahan-academy' ),
			'reset'         => __( 'Course progress reset.', 'mahan-academy' ),
			'cert_revoked'  => __( 'Certificate revoked. It no longer verifies.', 'mahan-academy' ),
			'cert_restored' => __( 'Certificate restored.', 'mahan-academy' ),
		);
		if ( isset( $map[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
		}
	}

	/* ---------------------------- List --------------------------------- */

	private static function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result  = self::query( wp_unslash( $_GET ) );
		$args    = $result['args'];
		$courses = Mahan_Courses::get_courses();

		$export = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'   => 'mahan_export_students',
						's'        => $args['search'],
						'course'   => $args['course'],
						'activity' => $args['activity'],
					)
				),
				admin_url( 'admin-post.php' )
			),
			'mahan_export_students'
		);
		?>
		<h1>
			<?php esc_html_e( 'Students', 'mahan-academy' ); ?>
			<span class="mahan-ver"><?php echo esc_html( number_format_i18n( $result['total'] ) ); ?></span>
			<a class="page-title-action" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'mahan-academy' ); ?></a>
		</h1>
		<?php self::notice(); ?>

		<form method="get" class="mahan-students-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search name or email…', 'mahan-academy' ); ?>" />
			<select name="course">
				<option value="0"><?php esc_html_e( 'All courses', 'mahan-academy' ); ?></option>
				<?php foreach ( $courses as $c ) : ?>
					<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $args['course'], $c->ID ); ?>><?php echo esc_html( get_the_title( $c->ID ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="activity">
				<option value=""><?php esc_html_e( 'Any activity', 'mahan-academy' ); ?></option>
				<option value="active7" <?php selected( $args['activity'], 'active7' ); ?>><?php esc_html_e( 'Active in the last 7 days', 'mahan-academy' ); ?></option>
				<option value="inactive30" <?php selected( $args['activity'], 'inactive30' ); ?>><?php esc_html_e( 'Inactive for 30+ days', 'mahan-academy' ); ?></option>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'mahan-academy' ); ?></button>
		</form>

		<table class="widefat striped mahan-students-table">
			<thead>
				<tr>
					<th><?php self::sort_link( 'name', __( 'Student', 'mahan-academy' ), $args ); ?></th>
					<th><?php self::sort_link( 'xp', __( 'XP / Level', 'mahan-academy' ), $args ); ?></th>
					<th><?php esc_html_e( 'Streak', 'mahan-academy' ); ?></th>
					<th><?php self::sort_link( 'enrolled', __( 'Enrolled', 'mahan-academy' ), $args ); ?></th>
					<th><?php self::sort_link( 'completed', __( 'Completed', 'mahan-academy' ), $args ); ?></th>
					<th><?php esc_html_e( 'Certificates', 'mahan-academy' ); ?></th>
					<th><?php self::sort_link( 'last_active', __( 'Last active', 'mahan-academy' ), $args ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $result['rows'] ) ) : ?>
				<tr><td colspan="7" class="mahan-students-none"><?php esc_html_e( 'No students match these filters.', 'mahan-academy' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $r ) : ?>
				<?php $url = add_query_arg( array( 'page' => self::PAGE, 'student' => (int) $r['ID'] ), admin_url( 'admin.php' ) ); ?>
				<tr>
					<td class="mahan-students-who">
						<?php echo get_avatar( (int) $r['ID'], 32 ); ?>
						<span>
							<a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $r['display_name'] ); ?></strong></a><br />
							<span class="mahan-students-mail"><?php echo esc_html( $r['user_email'] ); ?></span>
						</span>
					</td>
					<td><strong><?php echo esc_html( number_format_i18n( (int) $r['xp'] ) ); ?></strong> · L<?php echo esc_html( (int) $r['level'] ); ?></td>
					<td><?php echo (int) $r['streak'] > 0 ? '🔥 ' . esc_html( (int) $r['streak'] ) : '—'; ?></td>
					<td><?php echo esc_html( (int) $r['enrolled'] ); ?></td>
					<td><?php echo esc_html( (int) $r['completed'] ); ?></td>
					<td><?php echo esc_html( (int) $r['certs'] ); ?></td>
					<td><?php echo $r['last_active'] ? esc_html( $r['last_active'] ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pagination( $result );
	}

	private static function sort_link( $key, $label, $args ) {
		$url = add_query_arg(
			array_filter(
				array(
					'page'     => self::PAGE,
					's'        => $args['search'],
					'course'   => $args['course'],
					'activity' => $args['activity'],
					'orderby'  => $key,
				)
			),
			admin_url( 'admin.php' )
		);
		$is = $args['orderby'] === $key;
		echo '<a class="mahan-students-sort' . ( $is ? ' is-on' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . ( $is ? ' ▾' : '' ) . '</a>';
	}

	private static function pagination( $result ) {
		if ( $result['pages'] < 2 ) {
			return;
		}
		$args = $result['args'];
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
		for ( $p = 1; $p <= $result['pages']; $p++ ) {
			$url = add_query_arg(
				array_filter(
					array(
						'page'     => self::PAGE,
						's'        => $args['search'],
						'course'   => $args['course'],
						'activity' => $args['activity'],
						'orderby'  => $args['orderby'],
						'paged'    => $p,
					)
				),
				admin_url( 'admin.php' )
			);
			if ( $p === $args['paged'] ) {
				echo '<span class="tablenav-pages-navspan button disabled">' . esc_html( $p ) . '</span> ';
			} else {
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $p ) . '</a> ';
			}
		}
		echo '</span></div></div>';
	}

	/* ---------------------------- Detail -------------------------------- */

	private static function render_detail( $user_id ) {
		$file = self::file_for( $user_id );
		$back = add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
		if ( ! $file ) {
			echo '<h1>' . esc_html__( 'Students', 'mahan-academy' ) . '</h1>';
			echo '<p>' . esc_html__( 'That user no longer exists.', 'mahan-academy' ) . ' <a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'mahan-academy' ) . '</a></p>';
			return;
		}
		$user    = $file['user'];
		$stats   = $file['stats'];
		$totals  = $file['totals'];
		$profile = $file['profile'];
		?>
		<h1>
			<a href="<?php echo esc_url( $back ); ?>" class="mahan-students-back">← <?php esc_html_e( 'Students', 'mahan-academy' ); ?></a>
		</h1>
		<?php self::notice(); ?>

		<div class="mahan-student-head">
			<?php echo get_avatar( $user->ID, 64 ); ?>
			<div class="mahan-student-id">
				<h2><?php echo esc_html( $user->display_name ); ?></h2>
				<p>
					<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
					· <a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php esc_html_e( 'WordPress profile', 'mahan-academy' ); ?></a>
					· <?php
					/* translators: %s: registration date */
					printf( esc_html__( 'Member since %s', 'mahan-academy' ), esc_html( mysql2date( get_option( 'date_format' ), $user->user_registered ) ) );
					?>
				</p>
				<p class="mahan-student-tags">
					<?php if ( ! empty( $profile['role'] ) ) : ?>
						<span class="mahan-student-tag"><?php echo esc_html( $profile['role'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $profile['ai_level'] ) ) : ?>
						<span class="mahan-student-tag"><?php echo esc_html( $profile['ai_level'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $file['placement']['level'] ) ) : ?>
						<span class="mahan-student-tag is-placement"><?php
						/* translators: %s: placement level */
						printf( esc_html__( 'Placed: %s', 'mahan-academy' ), esc_html( Mahan_Variants::level_label( $file['placement']['level'] ) ) );
						?></span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<div class="mahan-cards mahan-report-cards mahan-student-cards">
			<?php
			$tiles = array(
				array( __( 'XP', 'mahan-academy' ), number_format_i18n( (int) $stats['xp'] ) ),
				array( __( 'Level', 'mahan-academy' ), (int) $stats['level'] ),
				array( __( 'Streak', 'mahan-academy' ), (int) $stats['streak'] ),
				array( __( 'Lessons completed', 'mahan-academy' ), (int) $totals['lessons_completed'] ),
				array( __( 'Exercises correct', 'mahan-academy' ), (int) $totals['exercises_correct'] ),
				array( __( 'Active days', 'mahan-academy' ), (int) $totals['active_days'] ),
			);
			foreach ( $tiles as $tile ) {
				echo '<div class="mahan-card"><span class="mahan-card-num">' . esc_html( $tile[1] ) . '</span><span class="mahan-card-label">' . esc_html( $tile[0] ) . '</span></div>';
			}
			?>
		</div>

		<h2><?php esc_html_e( 'Enrollments', 'mahan-academy' ); ?></h2>
		<table class="widefat striped mahan-student-enrollments">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Course', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Enrolled', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Completed', 'mahan-academy' ); ?></th>
					<th class="mahan-students-actions-col"><?php esc_html_e( 'Actions', 'mahan-academy' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $file['enrollments'] ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Not enrolled in any course yet.', 'mahan-academy' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $file['enrollments'] as $e ) : ?>
				<?php
				$cid      = (int) $e['course_id'];
				$unenroll = wp_nonce_url(
					admin_url( 'admin-post.php?action=mahan_student_unenroll&user_id=' . $user->ID . '&course_id=' . $cid ),
					'mahan_student_unenroll_' . $cid
				);
				$reset    = wp_nonce_url(
					admin_url( 'admin-post.php?action=mahan_student_reset&user_id=' . $user->ID . '&course_id=' . $cid ),
					'mahan_student_reset_' . $cid
				);
				?>
				<tr>
					<td><strong><?php echo esc_html( get_the_title( $cid ) ?: __( '(deleted course)', 'mahan-academy' ) ); ?></strong></td>
					<td>
						<span class="mahan-student-meter" title="<?php echo esc_attr( (int) $e['progress_pct'] ); ?>%">
							<span style="width:<?php echo esc_attr( max( 0, min( 100, (int) $e['progress_pct'] ) ) ); ?>%"></span>
						</span>
						<?php echo esc_html( (int) $e['progress_pct'] ); ?>%
					</td>
					<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $e['enrolled_at'] ) ); ?></td>
					<td><?php echo $e['completed_at'] ? esc_html( mysql2date( get_option( 'date_format' ), $e['completed_at'] ) ) : '—'; ?></td>
					<td>
						<a href="<?php echo esc_url( $unenroll ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Unenroll from this course? Their progress is kept and comes back if they re-enroll.', 'mahan-academy' ) ); ?>');"><?php esc_html_e( 'Unenroll', 'mahan-academy' ); ?></a>
						·
						<a href="<?php echo esc_url( $reset ); ?>" class="mahan-students-danger" onclick="return confirm('<?php echo esc_js( __( 'Reset ALL progress in this course? Lessons, exercise attempts and their review queue for it are permanently deleted. Certificates are kept.', 'mahan-academy' ) ); ?>');"><?php esc_html_e( 'Reset progress', 'mahan-academy' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mahan-student-enroll">
			<?php wp_nonce_field( 'mahan_student_enroll' ); ?>
			<input type="hidden" name="action" value="mahan_student_enroll" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
			<select name="course_id">
				<?php foreach ( Mahan_Courses::get_courses() as $c ) : ?>
					<option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( get_the_title( $c->ID ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button button-primary"><?php esc_html_e( 'Enroll in course', 'mahan-academy' ); ?></button>
		</form>

		<div class="mahan-report-cols">
			<div>
				<h2><?php esc_html_e( 'Certificates', 'mahan-academy' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Serial', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Course', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Issued', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mahan-academy' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $file['certificates'] ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No certificates yet.', 'mahan-academy' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $file['certificates'] as $cert ) : ?>
						<?php
						$revoked = ! empty( $cert['revoked'] );
						$toggle  = wp_nonce_url(
							admin_url( 'admin-post.php?action=mahan_student_cert&cert_id=' . (int) $cert['id'] . '&user_id=' . $user->ID . '&revoke=' . ( $revoked ? '0' : '1' ) ),
							'mahan_student_cert_' . (int) $cert['id']
						);
						?>
						<tr class="<?php echo $revoked ? 'mahan-cert-revoked' : ''; ?>">
							<td><code><?php echo esc_html( $cert['serial'] ); ?></code></td>
							<td><?php echo esc_html( get_the_title( (int) $cert['course_id'] ) ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $cert['issued_at'] ) ); ?></td>
							<td>
								<?php if ( $revoked ) : ?>
									<span class="mahan-students-danger"><?php esc_html_e( 'Revoked', 'mahan-academy' ); ?></span>
									· <a href="<?php echo esc_url( $toggle ); ?>"><?php esc_html_e( 'Restore', 'mahan-academy' ); ?></a>
								<?php else : ?>
									<span><?php esc_html_e( 'Valid', 'mahan-academy' ); ?></span>
									· <a class="mahan-students-danger" href="<?php echo esc_url( $toggle ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Revoke this certificate? It will stop verifying until restored.', 'mahan-academy' ) ); ?>');"><?php esc_html_e( 'Revoke', 'mahan-academy' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div>
				<h2><?php esc_html_e( 'Recent activity', 'mahan-academy' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'What', 'mahan-academy' ); ?></th>
						<th>XP</th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $file['activity'] ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No activity yet.', 'mahan-academy' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $file['activity'] as $a ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $a['created_at'] ) ); ?></td>
							<td><?php echo esc_html( self::reason_label( $a['reason'] ) ); ?></td>
							<td>+<?php echo esc_html( (int) $a['amount'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Human label for an xp_log reason code.
	 */
	public static function reason_label( $reason ) {
		$map = array(
			'lesson'   => __( 'Completed a lesson', 'mahan-academy' ),
			'exercise' => __( 'Solved an exercise', 'mahan-academy' ),
			'quiz'     => __( 'Passed a unit quiz', 'mahan-academy' ),
			'review'   => __( 'Cleared a review', 'mahan-academy' ),
			'practice' => __( 'Smart practice', 'mahan-academy' ),
			'viva'     => __( 'Passed a live assessment', 'mahan-academy' ),
			'course'   => __( 'Completed a course', 'mahan-academy' ),
		);
		$reason = (string) $reason;
		return isset( $map[ $reason ] ) ? $map[ $reason ] : $reason;
	}
}
