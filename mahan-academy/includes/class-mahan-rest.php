<?php
/**
 * REST API for the front-end SPA. Namespace: mahan/v1.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_REST {

	const NS = 'mahan/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$logged_in = array( __CLASS__, 'perm_logged_in' );
		$public    = '__return_true';

		register_rest_route( self::NS, '/catalog', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'catalog' ),
			'permission_callback' => $public,
		) );

		register_rest_route( self::NS, '/course/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'course' ),
			'permission_callback' => $public,
		) );

		register_rest_route( self::NS, '/lesson/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'lesson' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/enroll', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'enroll' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/progress', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'complete_lesson' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/exercise', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'grade_exercise' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/tutor', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'tutor' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/chat', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'chat_history' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/me', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'me' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/profile', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_profile' ),
				'permission_callback' => $logged_in,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_profile' ),
				'permission_callback' => $logged_in,
			),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Permissions                                                         */
	/* ------------------------------------------------------------------ */

	public static function perm_logged_in( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'mahan-academy' ), array( 'status' => 401 ) );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'mahan-academy' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Catalog & courses                                                   */
	/* ------------------------------------------------------------------ */

	public static function catalog( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$courses = Mahan_Courses::get_courses();
		$items   = array();
		foreach ( $courses as $course ) {
			$summary = Mahan_Courses::course_summary( $course );
			if ( ! $summary ) {
				continue;
			}
			$summary['enrolled']     = false;
			$summary['progress_pct'] = 0;
			if ( $user_id ) {
				$enr = Mahan_Enrollment::get( $user_id, $summary['id'] );
				if ( $enr ) {
					$summary['enrolled']     = true;
					$summary['progress_pct'] = (int) $enr['progress_pct'];
				}
			}
			$items[] = $summary;
		}
		return rest_ensure_response( array(
			'ok'        => true,
			'courses'   => $items,
			'logged_in' => (bool) $user_id,
		) );
	}

	public static function course( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$course_id = (int) $request['id'];

		$summary = Mahan_Courses::course_summary( $course_id );
		if ( ! $summary ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}

		$enrolled     = $user_id ? Mahan_Enrollment::is_enrolled( $user_id, $course_id ) : false;
		$progress_pct = ( $user_id && $enrolled ) ? Mahan_Progress::course_progress_pct( $user_id, $course_id ) : 0;
		$status_map   = $user_id ? Mahan_Progress::course_lesson_status( $user_id, $course_id ) : array();

		$units     = array();
		$prev_done = true; // First lesson is always unlocked.
		foreach ( Mahan_Courses::get_course_units( $course_id ) as $unit ) {
			$lessons = array();
			foreach ( $unit['lessons'] as $lesson ) {
				$lid    = (int) $lesson->ID;
				$status = isset( $status_map[ $lid ] ) ? $status_map[ $lid ] : 'not_started';
				$locked = $enrolled ? ( ! $prev_done && 'completed' !== $status && 'in_progress' !== $status ) : false;
				$lessons[] = array(
					'id'      => $lid,
					'title'   => get_the_title( $lid ),
					'type'    => Mahan_Utils::meta_str( $lid, Mahan_Courses::M_TYPE, 'reading' ),
					'xp'      => Mahan_Courses::lesson_xp( $lid ),
					'est_min' => Mahan_Utils::meta_int( $lid, Mahan_Courses::M_EST_MIN, 0 ),
					'status'  => $status,
					'locked'  => $locked,
				);
				$prev_done = ( 'completed' === $status );
			}
			$units[] = array(
				'title'   => $unit['title'],
				'lessons' => $lessons,
			);
		}

		return rest_ensure_response( array(
			'ok'           => true,
			'course'       => $summary,
			'outcomes'     => Mahan_Courses::course_outcomes( $course_id ),
			'description'  => apply_filters( 'the_content', get_post_field( 'post_content', $course_id ) ),
			'enrolled'     => $enrolled,
			'progress_pct' => $progress_pct,
			'units'        => $units,
			'logged_in'    => (bool) $user_id,
		) );
	}

	public static function lesson( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$lesson_id = (int) $request['id'];

		$lesson = get_post( $lesson_id );
		if ( ! $lesson || Mahan_CPT::LESSON !== $lesson->post_type || 'publish' !== $lesson->post_status ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}

		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		$enrolled  = Mahan_Enrollment::is_enrolled( $user_id, $course_id );

		// Mark started (only counts once).
		if ( $enrolled ) {
			Mahan_Progress::start_lesson( $user_id, $lesson_id, $course_id );
		}

		$progress    = Mahan_Progress::get_lesson( $user_id, $lesson_id );
		$attempt_map = Mahan_Exercises::lesson_attempt_map( $user_id, $lesson_id );

		$exercises = array();
		foreach ( Mahan_Courses::get_exercises( $lesson_id ) as $ex ) {
			$pub = Mahan_Courses::public_exercise( $ex );
			$key = $pub['key'];
			if ( isset( $attempt_map[ $key ] ) ) {
				$pub['solved'] = $attempt_map[ $key ]['is_correct'];
				$pub['score']  = $attempt_map[ $key ]['score'];
			} else {
				$pub['solved'] = false;
			}
			$exercises[] = $pub;
		}

		$siblings = Mahan_Courses::lesson_siblings( $lesson_id );

		return rest_ensure_response( array(
			'ok'           => true,
			'id'           => $lesson_id,
			'title'        => get_the_title( $lesson_id ),
			'course_id'    => $course_id,
			'course_title' => $course_id ? get_the_title( $course_id ) : '',
			'content'      => apply_filters( 'the_content', $lesson->post_content ),
			'type'         => Mahan_Utils::meta_str( $lesson_id, Mahan_Courses::M_TYPE, 'reading' ),
			'xp'           => Mahan_Courses::lesson_xp( $lesson_id ),
			'exercises'    => $exercises,
			'status'       => $progress ? $progress['status'] : 'not_started',
			'enrolled'     => $enrolled,
			'siblings'     => array(
				'prev' => $siblings['prev'] ? array( 'id' => $siblings['prev'], 'title' => get_the_title( $siblings['prev'] ) ) : null,
				'next' => $siblings['next'] ? array( 'id' => $siblings['next'], 'title' => get_the_title( $siblings['next'] ) ) : null,
			),
			'stats'        => Mahan_Gamification::hud( $user_id ),
			'tutor_ready'  => Mahan_Settings::ai_ready(),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function enroll( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$course_id = isset( $body['course_id'] ) ? absint( $body['course_id'] ) : 0;

		$res = Mahan_Enrollment::enroll( $user_id, $course_id );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $res->get_error_message() ), 400 );
		}
		return rest_ensure_response( array(
			'ok'       => true,
			'enrolled' => true,
			'stats'    => Mahan_Gamification::hud( $user_id ),
		) );
	}

	public static function complete_lesson( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$lesson_id = isset( $body['lesson_id'] ) ? absint( $body['lesson_id'] ) : 0;
		if ( ! $lesson_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_lesson_id' ), 400 );
		}
		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		if ( ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_enrolled' ), 403 );
		}
		$res = Mahan_Progress::complete_lesson( $user_id, $lesson_id );
		return rest_ensure_response( $res );
	}

	public static function grade_exercise( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$lesson_id = isset( $body['lesson_id'] ) ? absint( $body['lesson_id'] ) : 0;
		$key       = isset( $body['key'] ) ? sanitize_text_field( (string) $body['key'] ) : '';
		$answer    = isset( $body['answer'] ) ? $body['answer'] : '';

		if ( ! $lesson_id || '' === $key ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_params' ), 400 );
		}
		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		if ( ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_enrolled' ), 403 );
		}

		// Normalize answer: int index for MC, string otherwise.
		if ( is_array( $answer ) ) {
			$answer = array_map( 'sanitize_text_field', $answer );
		} elseif ( is_numeric( $answer ) ) {
			$answer = (int) $answer;
		} else {
			$answer = sanitize_textarea_field( (string) $answer );
		}

		$res = Mahan_Exercises::grade( $user_id, $lesson_id, $key, $answer );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( $res, 400 );
		}
		return rest_ensure_response( $res );
	}

	public static function tutor( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$lesson_id = isset( $body['lesson_id'] ) ? absint( $body['lesson_id'] ) : 0;
		$message   = isset( $body['message'] ) ? (string) $body['message'] : '';

		$res = Mahan_AI_Stream::reply( $user_id, $lesson_id, $message );
		if ( ! $res['ok'] ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $res['error'] ), 400 );
		}
		return rest_ensure_response( array(
			'ok'    => true,
			'reply' => $res['text'],
		) );
	}

	public static function chat_history( WP_REST_Request $request ) {
		global $wpdb;
		$user_id   = get_current_user_id();
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );
		$table     = Mahan_DB::chat();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, created_at FROM {$table} WHERE user_id = %d AND lesson_id = %d ORDER BY id ASC LIMIT 50",
				$user_id,
				$lesson_id
			),
			ARRAY_A
		);
		$messages = array();
		foreach ( (array) $rows as $r ) {
			$messages[] = array(
				'role'    => $r['role'],
				'content' => $r['content'],
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'messages' => $messages ) );
	}

	public static function me( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		return rest_ensure_response( array(
			'ok'      => true,
			'user'    => array(
				'id'           => $user_id,
				'display_name' => $user ? $user->display_name : '',
				'avatar'       => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			),
			'stats'   => Mahan_Gamification::hud( $user_id ),
			'courses' => Mahan_Enrollment::get_user_courses( $user_id ),
		) );
	}

	public static function get_profile( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$profile = Mahan_Profile::get_profile( $user_id );
		return rest_ensure_response( array(
			'ok'       => true,
			'profile'  => $profile,
			'schema'   => Mahan_Profile::get_schema(),
			'complete' => Mahan_Profile::is_complete( $profile ),
		) );
	}

	public static function save_profile( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$body       = $request->get_json_params();
		$profile_in = isset( $body['profile'] ) && is_array( $body['profile'] ) ? $body['profile'] : array();

		$profile  = Mahan_Profile::save_profile( $user_id, $profile_in );
		$complete = Mahan_Profile::is_complete( $profile );

		return rest_ensure_response( array(
			'ok'       => true,
			'profile'  => $profile,
			'complete' => $complete,
		) );
	}
}
