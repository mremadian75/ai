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

		register_rest_route( self::NS, '/quiz', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_quiz' ),
				'permission_callback' => $logged_in,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit_quiz' ),
				'permission_callback' => $logged_in,
			),
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

		register_rest_route( self::NS, '/leaderboard', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'leaderboard' ),
			'permission_callback' => $public,
		) );

		register_rest_route( self::NS, '/goal', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_goal' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/paths', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'paths' ),
			'permission_callback' => $public,
		) );

		register_rest_route( self::NS, '/path/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'path' ),
			'permission_callback' => $public,
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

		// Adaptive review (spaced repetition of wrong answers).
		register_rest_route( self::NS, '/reviews', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_reviews' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/review', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'submit_review' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/review/variant', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'review_variant' ),
			'permission_callback' => $logged_in,
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
		// Featured courses first (stable for the rest).
		usort(
			$items,
			function ( $a, $b ) {
				return ( ! empty( $b['featured'] ) ? 1 : 0 ) <=> ( ! empty( $a['featured'] ) ? 1 : 0 );
			}
		);
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

			// Unit quiz summary (learner-facing, no answers).
			$quiz_def = Mahan_Quizzes::get( $course_id, $unit['title'] );
			$quiz     = null;
			if ( $quiz_def ) {
				$best = $user_id ? Mahan_Quizzes::best( $user_id, $course_id, $unit['title'] ) : null;
				$quiz = array(
					'title'   => '' !== $quiz_def['title'] ? $quiz_def['title'] : __( 'Unit quiz', 'mahan-academy' ),
					'count'   => count( $quiz_def['questions'] ),
					'passing' => (int) $quiz_def['passing'],
					'passed'  => $best ? (bool) $best['is_correct'] : false,
					'score'   => $best ? (int) $best['score'] : null,
				);
			}

			$units[] = array(
				'title'   => $unit['title'],
				'lessons' => $lessons,
				'quiz'    => $quiz,
			);
		}

		return rest_ensure_response( array(
			'ok'           => true,
			'course'       => $summary,
			'outcomes'     => Mahan_Courses::course_outcomes( $course_id ),
			'description'  => apply_filters( 'the_content', get_post_field( 'post_content', $course_id ) ),
			'promo_video'  => Mahan_Courses::promo_video( $course_id ),
			'prerequisite' => Mahan_Courses::prerequisite( $course_id ),
			'certificate'  => (bool) $summary['certificate'] && (bool) Mahan_Settings::get( 'certificate_enabled', 1 ),
			'enrolled'     => $enrolled,
			'progress_pct' => $progress_pct,
			'completed'    => ( 100 === (int) $progress_pct ),
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
		$position = Mahan_Courses::lesson_position( $lesson_id );

		// When this is the last lesson of a unit that has a quiz, tell the app
		// so the complete-lesson flow can route the learner into the quiz.
		$unit_quiz = null;
		if ( $course_id && '' !== $position['unit'] ) {
			$units = Mahan_Courses::get_course_units( $course_id );
			foreach ( $units as $unit ) {
				if ( $unit['title'] !== $position['unit'] || empty( $unit['lessons'] ) ) {
					continue;
				}
				$last = end( $unit['lessons'] );
				if ( (int) $last->ID === $lesson_id && Mahan_Quizzes::get( $course_id, $unit['title'] ) ) {
					$best      = $user_id ? Mahan_Quizzes::best( $user_id, $course_id, $unit['title'] ) : null;
					$unit_quiz = array(
						'unit'   => $unit['title'],
						'passed' => $best ? (bool) $best['is_correct'] : false,
					);
				}
				break;
			}
		}

		return rest_ensure_response( array(
			'ok'           => true,
			'id'           => $lesson_id,
			'title'        => get_the_title( $lesson_id ),
			'course_id'    => $course_id,
			'course_title' => $course_id ? get_the_title( $course_id ) : '',
			'position'     => $position,
			'course_pct'   => $enrolled ? Mahan_Progress::course_progress_pct( $user_id, $course_id ) : 0,
			'unit_quiz'    => $unit_quiz,
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
		// On course completion, tell the app whether a certificate is
		// available so the celebration can offer it directly.
		if ( ! empty( $res['course_completed'] ) ) {
			$res['certificate'] = (bool) Mahan_Utils::meta_int( $course_id, Mahan_Courses::M_CERTIFICATE, 0 )
				&& (bool) Mahan_Settings::get( 'certificate_enabled', 1 );
		}
		// How many questions from this lesson are queued for review, so the
		// app can offer a "review your misses" step before moving on.
		$res['review_pending'] = Mahan_Reviews::for_lesson_count( $user_id, $lesson_id );
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

	/* ------------------------------------------------------------------ */
	/* Quizzes                                                             */
	/* ------------------------------------------------------------------ */

	public static function get_quiz( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$course_id = absint( $request->get_param( 'course_id' ) );
		$unit      = (string) $request->get_param( 'unit' );

		$def = Mahan_Quizzes::get( $course_id, $unit );
		if ( ! $def ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_quiz' ), 404 );
		}
		$best = Mahan_Quizzes::best( $user_id, $course_id, $unit );
		return rest_ensure_response( array(
			'ok'       => true,
			'quiz'     => Mahan_Quizzes::public_quiz( $def ),
			'enrolled' => Mahan_Enrollment::is_enrolled( $user_id, $course_id ),
			'best'     => $best ? array( 'score' => (int) $best['score'], 'passed' => (bool) $best['is_correct'] ) : null,
		) );
	}

	public static function submit_quiz( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$course_id = isset( $body['course_id'] ) ? absint( $body['course_id'] ) : 0;
		$unit      = isset( $body['unit'] ) ? (string) $body['unit'] : '';
		$answers   = isset( $body['answers'] ) && is_array( $body['answers'] ) ? $body['answers'] : array();

		if ( ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_enrolled' ), 403 );
		}

		// Normalize each answer: int index for choice types, string otherwise.
		$clean = array();
		foreach ( $answers as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( is_numeric( $v ) ) {
				$clean[ $key ] = (int) $v;
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $v );
			}
		}

		$res = Mahan_Quizzes::grade( $user_id, $course_id, $unit, $clean );
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

		$stats            = Mahan_Gamification::hud( $user_id );
		$stats['week']    = Mahan_Gamification::week_activity( $user_id );
		$stats['reviews'] = Mahan_Reviews::counts( $user_id );

		// "Jump back in": give each in-progress course its next lesson so the
		// dashboard can deep-link straight into the lesson player.
		$courses = Mahan_Enrollment::get_user_courses( $user_id );
		foreach ( $courses as &$c ) {
			if ( (int) $c['progress_pct'] < 100 ) {
				$next = Mahan_Courses::next_lesson( $user_id, $c['id'] );
				if ( $next ) {
					$c['next_lesson_id']    = $next;
					$c['next_lesson_title'] = get_the_title( $next );
				}
			}
		}
		unset( $c );

		return rest_ensure_response( array(
			'ok'      => true,
			'user'    => array(
				'id'           => $user_id,
				'display_name' => $user ? $user->display_name : '',
				'avatar'       => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			),
			'stats'       => $stats,
			'courses'     => $courses,
			'badges'      => Mahan_Badges::for_user( $user_id ),
			'leaderboard' => (bool) Mahan_Settings::get( 'leaderboard_enabled', 0 ),
		) );
	}

	/**
	 * Public (opt-in) XP leaderboard.
	 *
	 * ?period=all  — lifetime XP from the stats table (default).
	 * ?period=week — XP earned in the last 7 days, from the XP log.
	 *
	 * When the caller is logged in but outside the top 20, a "me" entry with
	 * their exact rank is included so everyone can see where they stand.
	 */
	public static function leaderboard( WP_REST_Request $request ) {
		if ( ! Mahan_Settings::get( 'leaderboard_enabled', 0 ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'disabled' ), 403 );
		}
		global $wpdb;
		$period = ( 'week' === $request->get_param( 'period' ) ) ? 'week' : 'all';
		$stats  = Mahan_DB::stats();
		$log    = Mahan_DB::xp_log();
		$me     = get_current_user_id();

		if ( 'week' === $period ) {
			$since = gmdate( 'Y-m-d 00:00:00', strtotime( Mahan_Utils::today() . ' -6 days' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.user_id, SUM(l.amount) AS xp, s.level, s.streak
					 FROM {$log} l LEFT JOIN {$stats} s ON s.user_id = l.user_id
					 WHERE l.created_at >= %s
					 GROUP BY l.user_id HAVING xp > 0 ORDER BY xp DESC LIMIT 20",
					$since
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT user_id, xp, level, streak FROM {$stats} WHERE xp > 0 ORDER BY xp DESC LIMIT 20", ARRAY_A );
		}

		$entries = array();
		$rank    = 0;
		$me_seen = false;
		foreach ( (array) $rows as $r ) {
			$rank++;
			$uid  = (int) $r['user_id'];
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}
			if ( $uid === $me ) {
				$me_seen = true;
			}
			$entries[] = array(
				'rank'   => $rank,
				'name'   => $user->display_name,
				'avatar' => get_avatar_url( $uid, array( 'size' => 48 ) ),
				'xp'     => (int) $r['xp'],
				'level'  => (int) $r['level'],
				'streak' => (int) $r['streak'],
				'is_me'  => ( $uid === $me ),
			);
		}

		// Caller's own rank when they're outside the top list.
		$me_entry = null;
		if ( $me && ! $me_seen ) {
			if ( 'week' === $period ) {
				$my_xp = Mahan_Gamification::xp_since( $me, $since );
				if ( $my_xp > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$higher = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM ( SELECT user_id FROM {$log} WHERE created_at >= %s GROUP BY user_id HAVING SUM(amount) > %d ) t",
							$since,
							$my_xp
						)
					);
				}
			} else {
				$my_stats = Mahan_Gamification::get_stats( $me );
				$my_xp    = (int) $my_stats['xp'];
				if ( $my_xp > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$higher = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$stats} WHERE xp > %d", $my_xp ) );
				}
			}
			if ( ! empty( $my_xp ) ) {
				$user     = get_userdata( $me );
				$my_row   = Mahan_Gamification::get_stats( $me );
				$me_entry = array(
					'rank'   => $higher + 1,
					'name'   => $user ? $user->display_name : '',
					'avatar' => get_avatar_url( $me, array( 'size' => 48 ) ),
					'xp'     => (int) $my_xp,
					'level'  => (int) $my_row['level'],
					'streak' => (int) $my_row['streak'],
					'is_me'  => true,
				);
			}
		}

		return rest_ensure_response( array( 'ok' => true, 'period' => $period, 'entries' => $entries, 'me' => $me_entry ) );
	}

	/**
	 * Save the learner's daily XP goal.
	 */
	public static function set_goal( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$goal    = isset( $body['daily_goal'] ) ? (int) $body['daily_goal'] : 0;
		Mahan_Gamification::set_daily_goal( $user_id, $goal );
		return rest_ensure_response( array(
			'ok'    => true,
			'stats' => Mahan_Gamification::hud( $user_id ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Learning paths                                                      */
	/* ------------------------------------------------------------------ */

	public static function paths( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( Mahan_Paths::get_paths() as $path ) {
			$summary = Mahan_Paths::summary( $path, $user_id );
			if ( $summary ) {
				$items[] = $summary;
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'paths' => $items, 'logged_in' => (bool) $user_id ) );
	}

	public static function path( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$path_id = (int) $request['id'];
		$detail  = Mahan_Paths::detail( $path_id, $user_id );
		if ( ! $detail ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}
		return rest_ensure_response( array( 'ok' => true, 'path' => $detail, 'logged_in' => (bool) $user_id ) );
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

	/* ------------------------------------------------------------------ */
	/* Adaptive review                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Due review items. `scope=lesson&lesson_id=N` returns that lesson's
	 * misses (the end-of-lesson loop); otherwise the cross-course due queue.
	 */
	public static function get_reviews( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! Mahan_Reviews::enabled() ) {
			return rest_ensure_response( array( 'ok' => true, 'items' => array(), 'counts' => array( 'due' => 0, 'learning' => 0, 'mastered' => 0 ) ) );
		}
		$scope = (string) $request->get_param( 'scope' );
		if ( 'lesson' === $scope ) {
			$lesson_id = absint( $request->get_param( 'lesson_id' ) );
			$rows      = Mahan_Reviews::for_lesson( $user_id, $lesson_id );
		} else {
			$limit = absint( $request->get_param( 'limit' ) );
			$rows  = Mahan_Reviews::due( $user_id, $limit > 0 ? $limit : 20 );
		}
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = Mahan_Reviews::public_item( $row );
		}
		return rest_ensure_response( array(
			'ok'       => true,
			'items'    => $items,
			'counts'   => Mahan_Reviews::counts( $user_id ),
			'ai_ready' => Mahan_Settings::ai_ready(),
		) );
	}

	/**
	 * Grade a review answer (against the original snapshot or an AI variant).
	 */
	public static function submit_review( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$rid     = isset( $body['review_id'] ) ? absint( $body['review_id'] ) : 0;
		if ( ! $rid ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_review_id' ), 400 );
		}
		$answer = isset( $body['answer'] ) ? $body['answer'] : '';
		$token  = isset( $body['variant_token'] ) ? (string) $body['variant_token'] : '';
		$res    = Mahan_Reviews::grade( $user_id, $rid, $answer, $token );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( $res, 404 );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * Ask the AI to re-pose a missed question a different way.
	 */
	public static function review_variant( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$rid     = isset( $body['review_id'] ) ? absint( $body['review_id'] ) : 0;
		if ( ! $rid ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_review_id' ), 400 );
		}
		$res = Mahan_Reviews::variant( $user_id, $rid );
		if ( empty( $res['ok'] ) ) {
			$status = ( 'not_found' === ( isset( $res['error'] ) ? $res['error'] : '' ) ) ? 404 : 422;
			return new WP_REST_Response( $res, $status );
		}
		return rest_ensure_response( $res );
	}
}
