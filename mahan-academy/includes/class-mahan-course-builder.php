<?php
/**
 * Visual Course Builder — a drag-and-drop curriculum editor shown as a
 * full-width meta box on the Course edit screen.
 *
 * Instead of creating each lesson separately and hand-numbering its unit/order
 * meta, admins build the whole curriculum in one place: add units, add lessons
 * inline, reorder by dragging, and set per-lesson type/XP/duration without
 * leaving the course. Lessons are still ordinary `mahan_lesson` posts, so the
 * full block editor stays available via "Edit content".
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Course_Builder {

	const NONCE = 'mahan_cb';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_mahan_cb_add_lesson', array( __CLASS__, 'ajax_add_lesson' ) );
		add_action( 'wp_ajax_mahan_cb_get_lesson', array( __CLASS__, 'ajax_get_lesson' ) );
		add_action( 'wp_ajax_mahan_cb_update_lesson', array( __CLASS__, 'ajax_update_lesson' ) );
		add_action( 'wp_ajax_mahan_cb_delete_lesson', array( __CLASS__, 'ajax_delete_lesson' ) );
		add_action( 'wp_ajax_mahan_cb_duplicate_lesson', array( __CLASS__, 'ajax_duplicate_lesson' ) );
		add_action( 'wp_ajax_mahan_cb_save_structure', array( __CLASS__, 'ajax_save_structure' ) );
	}

	public static function register() {
		add_meta_box(
			'mahan_course_builder',
			__( 'Curriculum builder', 'mahan-academy' ),
			array( __CLASS__, 'render' ),
			Mahan_CPT::COURSE,
			'normal',
			'high'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Meta box                                                            */
	/* ------------------------------------------------------------------ */

	public static function render( $post ) {
		$course_id = (int) $post->ID;
		$tree      = self::tree( $course_id );
		?>
		<div
			id="mahan-cb"
			class="mahan-cb"
			data-course="<?php echo esc_attr( $course_id ); ?>"
			data-tree="<?php echo esc_attr( wp_json_encode( $tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">
			<div class="mahan-cb-toolbar">
				<div class="mahan-cb-stats">
					<span class="mahan-cb-pill"><span class="mahan-cb-stat" data-cb-count="units">0</span> <?php esc_html_e( 'units', 'mahan-academy' ); ?></span>
					<span class="mahan-cb-pill"><span class="mahan-cb-stat" data-cb-count="lessons">0</span> <?php esc_html_e( 'lessons', 'mahan-academy' ); ?></span>
					<span class="mahan-cb-pill"><span class="mahan-cb-stat" data-cb-count="min">0</span> <?php esc_html_e( 'min', 'mahan-academy' ); ?></span>
					<span class="mahan-cb-pill"><span class="mahan-cb-stat" data-cb-count="xp">0</span> XP</span>
				</div>
				<div class="mahan-cb-toolbar-actions">
					<button type="button" class="button" id="mahan-cb-toggle-all">
						<?php esc_html_e( 'Expand / collapse all', 'mahan-academy' ); ?>
					</button>
					<button type="button" class="button button-primary" id="mahan-cb-add-unit">
						+ <?php esc_html_e( 'Add unit', 'mahan-academy' ); ?>
					</button>
				</div>
			</div>
			<div class="mahan-cb-units" id="mahan-cb-units"></div>
			<div class="mahan-cb-empty" id="mahan-cb-empty" style="display:none">
				<span class="dashicons dashicons-welcome-add-page"></span>
				<p><?php esc_html_e( 'Build your course right here: add a unit, then add lessons inside it. Everything is drag-and-drop, and every lesson opens a full editor — text, video, minutes, XP — without leaving this page.', 'mahan-academy' ); ?></p>
			</div>
			<div class="mahan-cb-saving" id="mahan-cb-saving" aria-live="polite"></div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Tree building                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the curriculum tree consumed by the builder JS.
	 *
	 * @param int $course_id Course id.
	 * @return array list of [ 'title' => string, 'lessons' => node[] ]
	 */
	public static function tree( $course_id ) {
		$units   = Mahan_Courses::get_course_units( $course_id );
		$quizzes = Mahan_Quizzes::all( $course_id );
		$out     = array();
		foreach ( $units as $unit ) {
			$lessons = array();
			foreach ( $unit['lessons'] as $lesson ) {
				$lessons[] = self::node( $lesson->ID );
			}
			$quiz = isset( $quizzes[ $unit['title'] ] ) ? Mahan_Quizzes::sanitize( $quizzes[ $unit['title'] ] ) : null;
			$out[] = array(
				'title'   => $unit['title'],
				'quiz'    => $quiz,
				'lessons' => $lessons,
			);
		}
		return $out;
	}

	private static function node( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$video     = Mahan_Utils::meta_str( $lesson_id, Mahan_Courses::M_VIDEO, '' );
		return array(
			'id'          => $lesson_id,
			'title'       => get_the_title( $lesson_id ),
			'type'        => Mahan_Utils::meta_str( $lesson_id, Mahan_Courses::M_TYPE, 'reading' ),
			'xp'          => Mahan_Utils::meta_int( $lesson_id, Mahan_Courses::M_XP, 0 ),
			'est_min'     => Mahan_Utils::meta_int( $lesson_id, Mahan_Courses::M_EST_MIN, 0 ),
			'exercises'   => count( Mahan_Courses::get_exercises( $lesson_id ) ),
			'edit_url'    => get_edit_post_link( $lesson_id, 'raw' ),
			'status'      => get_post_status( $lesson_id ),
			// The studio flags at a glance what still needs work: a lesson with
			// no body, or a "video" lesson with no video behind it.
			'has_content' => '' !== trim( (string) get_post_field( 'post_content', $lesson_id ) ),
			'video'       => $video,
			'video_ok'    => '' !== Mahan_Courses::video_embed( $video )['type'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX helpers                                                        */
	/* ------------------------------------------------------------------ */

	private static function guard( $course_id ) {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
		}
		if ( $course_id ) {
			if ( Mahan_CPT::COURSE !== get_post_type( $course_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Course not found.', 'mahan-academy' ) ), 404 );
			}
			// Must be allowed to edit THIS specific course — not just "a"
			// course — so one author can't restructure another's curriculum.
			if ( ! current_user_can( 'edit_post', $course_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
			}
		}
	}

	/**
	 * New lessons are published only when the user can actually publish;
	 * lower-capability roles (e.g. Contributor) create drafts instead.
	 */
	private static function new_lesson_status() {
		return current_user_can( 'publish_posts' ) ? 'publish' : 'draft';
	}

	private static function sanitize_type( $type ) {
		$type = sanitize_key( (string) $type );
		return in_array( $type, array( 'reading', 'practice', 'video' ), true ) ? $type : 'reading';
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: add lesson                                                    */
	/* ------------------------------------------------------------------ */

	public static function ajax_add_lesson() {
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		self::guard( $course_id );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$unit  = isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : '';
		$type  = isset( $_POST['type'] ) ? self::sanitize_type( wp_unslash( $_POST['type'] ) ) : 'reading';
		if ( '' === trim( $title ) ) {
			$title = __( 'Untitled lesson', 'mahan-academy' );
		}

		$lesson_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Mahan_CPT::LESSON,
				'post_status' => self::new_lesson_status(),
			),
			true
		);
		if ( is_wp_error( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => $lesson_id->get_error_message() ), 500 );
		}

		update_post_meta( $lesson_id, Mahan_Courses::M_COURSE_ID, $course_id );
		update_post_meta( $lesson_id, Mahan_Courses::M_UNIT, $unit );
		update_post_meta( $lesson_id, Mahan_Courses::M_TYPE, $type );
		update_post_meta( $lesson_id, Mahan_Courses::M_XP, 0 );
		update_post_meta( $lesson_id, Mahan_Courses::M_EST_MIN, 0 );

		wp_send_json_success( array( 'lesson' => self::node( $lesson_id ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: read one lesson for the studio editor                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The tree stays light (titles + flags); the full body is fetched only
	 * when a lesson's editor actually opens.
	 */
	public static function ajax_get_lesson() {
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;
		self::guard( 0 );

		if ( ! $lesson_id || Mahan_CPT::LESSON !== get_post_type( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Lesson not found.', 'mahan-academy' ) ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
		}

		wp_send_json_success(
			array(
				'lesson'  => self::node( $lesson_id ),
				'content' => (string) get_post_field( 'post_content', $lesson_id ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: update lesson (title / type / xp / minutes / video / content) */
	/* ------------------------------------------------------------------ */

	public static function ajax_update_lesson() {
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;
		self::guard( 0 );

		if ( ! $lesson_id || Mahan_CPT::LESSON !== get_post_type( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Lesson not found.', 'mahan-academy' ) ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
		}

		$post_fields = array();
		if ( isset( $_POST['title'] ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST['title'] ) );
			if ( '' !== trim( $title ) ) {
				$post_fields['post_title'] = $title;
			}
		}
		if ( isset( $_POST['content'] ) ) {
			// Same filter the classic editor applies: rich HTML stays, scripts
			// and event handlers do not — regardless of the sender's browser.
			$post_fields['post_content'] = wp_kses_post( wp_unslash( (string) $_POST['content'] ) );
		}
		if ( ! empty( $post_fields ) ) {
			$post_fields['ID'] = $lesson_id;
			wp_update_post( $post_fields );
		}

		if ( isset( $_POST['type'] ) ) {
			update_post_meta( $lesson_id, Mahan_Courses::M_TYPE, self::sanitize_type( wp_unslash( $_POST['type'] ) ) );
		}
		if ( isset( $_POST['xp'] ) ) {
			update_post_meta( $lesson_id, Mahan_Courses::M_XP, absint( $_POST['xp'] ) );
		}
		if ( isset( $_POST['est_min'] ) ) {
			update_post_meta( $lesson_id, Mahan_Courses::M_EST_MIN, absint( $_POST['est_min'] ) );
		}
		if ( isset( $_POST['video'] ) ) {
			$url = esc_url_raw( trim( wp_unslash( (string) $_POST['video'] ) ) );
			if ( '' === $url ) {
				delete_post_meta( $lesson_id, Mahan_Courses::M_VIDEO );
			} else {
				update_post_meta( $lesson_id, Mahan_Courses::M_VIDEO, $url );
			}
		}

		wp_send_json_success( array( 'lesson' => self::node( $lesson_id ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: delete lesson                                                 */
	/* ------------------------------------------------------------------ */

	public static function ajax_delete_lesson() {
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;
		self::guard( 0 );

		if ( ! $lesson_id || Mahan_CPT::LESSON !== get_post_type( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Lesson not found.', 'mahan-academy' ) ), 404 );
		}
		if ( ! current_user_can( 'delete_post', $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
		}
		wp_trash_post( $lesson_id );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: duplicate lesson                                              */
	/* ------------------------------------------------------------------ */

	public static function ajax_duplicate_lesson() {
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;
		self::guard( 0 );

		$src = $lesson_id ? get_post( $lesson_id ) : null;
		if ( ! $src || Mahan_CPT::LESSON !== $src->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Lesson not found.', 'mahan-academy' ) ), 404 );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
		}

		$new_id = wp_insert_post(
			array(
				/* translators: %s: original lesson title */
				'post_title'   => sprintf( __( '%s (copy)', 'mahan-academy' ), $src->post_title ),
				'post_content' => $src->post_content,
				'post_type'    => Mahan_CPT::LESSON,
				'post_status'  => self::new_lesson_status(),
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( array( 'message' => $new_id->get_error_message() ), 500 );
		}

		$copy_keys = array(
			Mahan_Courses::M_COURSE_ID,
			Mahan_Courses::M_UNIT,
			Mahan_Courses::M_TYPE,
			Mahan_Courses::M_VIDEO,
			Mahan_Courses::M_XP,
			Mahan_Courses::M_EST_MIN,
			Mahan_Courses::M_EXERCISES,
		);
		foreach ( $copy_keys as $key ) {
			$val = get_post_meta( $lesson_id, $key, true );
			if ( '' !== $val && null !== $val ) {
				update_post_meta( $new_id, $key, $val );
			}
		}

		wp_send_json_success( array( 'lesson' => self::node( $new_id ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: persist the whole structure (units + names + order)           */
	/* ------------------------------------------------------------------ */

	public static function ajax_save_structure() {
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		self::guard( $course_id );

		$raw       = isset( $_POST['structure'] ) ? wp_unslash( (string) $_POST['structure'] ) : '[]';
		$structure = json_decode( $raw, true );
		if ( ! is_array( $structure ) ) {
			wp_send_json_error( array( 'message' => __( 'Bad structure.', 'mahan-academy' ) ), 400 );
		}

		$quiz_map = array();
		foreach ( $structure as $unit_index => $unit ) {
			$unit_title = isset( $unit['title'] ) ? sanitize_text_field( (string) $unit['title'] ) : '';
			$lessons    = isset( $unit['lessons'] ) && is_array( $unit['lessons'] ) ? $unit['lessons'] : array();
			foreach ( $lessons as $order => $lesson_id ) {
				$lesson_id = absint( $lesson_id );
				if ( ! $lesson_id || Mahan_CPT::LESSON !== get_post_type( $lesson_id ) ) {
					continue;
				}
				// Only restructure lessons this user may actually edit.
				if ( ! current_user_can( 'edit_post', $lesson_id ) ) {
					continue;
				}
				update_post_meta( $lesson_id, Mahan_Courses::M_COURSE_ID, $course_id );
				update_post_meta( $lesson_id, Mahan_Courses::M_UNIT, $unit_title );
				update_post_meta( $lesson_id, Mahan_Courses::M_UNIT_ORDER, (int) $unit_index );
				update_post_meta( $lesson_id, Mahan_Courses::M_ORDER, (int) $order );
				wp_update_post( array( 'ID' => $lesson_id, 'menu_order' => (int) $order ) );
			}
			// Quiz travels with the unit object, so renames stay attached.
			if ( '' !== $unit_title && isset( $unit['quiz'] ) && is_array( $unit['quiz'] ) ) {
				$quiz_map[ $unit_title ] = $unit['quiz'];
			}
		}
		Mahan_Quizzes::save_all( $course_id, $quiz_map );

		wp_send_json_success();
	}
}
