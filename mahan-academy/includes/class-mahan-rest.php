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

		// On-demand AI practice generator (fresh questions for a lesson).
		register_rest_route( self::NS, '/practice', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'practice' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/practice/grade', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'practice_grade' ),
			'permission_callback' => $logged_in,
		) );

		// Live AI oral exam (viva): open a sitting, answer a question, walk away.
		register_rest_route( self::NS, '/viva/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'viva_start' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/viva/answer', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'viva_answer' ),
			'permission_callback' => $logged_in,
		) );

		register_rest_route( self::NS, '/viva/abandon', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'viva_abandon' ),
			'permission_callback' => $logged_in,
		) );

		// Per-lesson AI "For you" personalization note.
		register_rest_route( self::NS, '/lesson/personalize', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'lesson_personalize' ),
			'permission_callback' => $logged_in,
		) );

		// Personalized "Recommended for you" courses/bundle.
		register_rest_route( self::NS, '/recommendations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'recommendations' ),
			'permission_callback' => $logged_in,
		) );

		// Placement test: fetch a sitting, submit it for a level.
		register_rest_route( self::NS, '/placement', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'placement_get' ),
				'permission_callback' => $logged_in,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'placement_submit' ),
				'permission_callback' => $logged_in,
			),
		) );

		// The learner's own certificates.
		register_rest_route( self::NS, '/certificates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'certificates_mine' ),
			'permission_callback' => $logged_in,
		) );

		// Verification is deliberately public and unauthenticated — a
		// credential only a logged-in holder can check verifies nothing.
		register_rest_route( self::NS, '/certificate/(?P<serial>[A-Za-z0-9\-]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'certificate_verify' ),
			'permission_callback' => $public,
		) );

		// The learner's profile page.
		register_rest_route( self::NS, '/profile/summary', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'profile_summary' ),
			'permission_callback' => $logged_in,
		) );

		// Save a course for later (Udemy/Coursera-style wishlist).
		register_rest_route( self::NS, '/save', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'toggle_saved' ),
			'permission_callback' => $logged_in,
		) );

		// Language. Public on purpose: a visitor browsing the catalog should be
		// able to read it in their own language before deciding to sign up.
		register_rest_route( self::NS, '/language', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_language' ),
			'permission_callback' => $public,
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Permissions                                                         */
	/* ------------------------------------------------------------------ */

	public static function perm_logged_in( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'mahan-academy' ), array( 'status' => 401 ) );
		}
		// Require a valid REST nonce on every logged-in (state-changing or
		// personal) route — don't rely on core's cookie check as the only
		// CSRF guard.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Invalid or missing nonce.', 'mahan-academy' ), array( 'status' => 403 ) );
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

		// Category facets (Coursera-style domains) with course counts, so the
		// catalog can render a filter row and section headers.
		$cat_counts = array();
		foreach ( $items as $it ) {
			foreach ( (array) $it['categories'] as $cat ) {
				$cat = (string) $cat;
				if ( '' === $cat ) {
					continue;
				}
				$cat_counts[ $cat ] = isset( $cat_counts[ $cat ] ) ? $cat_counts[ $cat ] + 1 : 1;
			}
		}
		ksort( $cat_counts );
		$categories = array();
		foreach ( $cat_counts as $name => $count ) {
			$categories[] = array( 'name' => $name, 'count' => (int) $count );
		}

		// Bundles (specializations) — the learning paths, surfaced right in the
		// catalog so the Coursera-style programs are discoverable up front.
		$bundles = array();
		foreach ( Mahan_Paths::get_paths() as $path ) {
			$summary = Mahan_Paths::summary( $path, $user_id );
			if ( $summary && $summary['course_count'] > 0 ) {
				$bundles[] = $summary;
			}
		}

		return rest_ensure_response( array(
			'ok'         => true,
			'courses'    => $items,
			'categories' => $categories,
			'bundles'    => $bundles,
			'logged_in'  => (bool) $user_id,
		) );
	}

	public static function course( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$course_id = (int) $request['id'];

		// Never expose unpublished (draft/pending/private) course content on a
		// public endpoint.
		if ( 'publish' !== get_post_status( $course_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}

		$summary = Mahan_Courses::course_summary( $course_id );
		if ( ! $summary ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}

		$enrolled     = $user_id ? Mahan_Enrollment::is_enrolled( $user_id, $course_id ) : false;
		$progress_pct = ( $user_id && $enrolled ) ? Mahan_Progress::course_progress_pct( $user_id, $course_id ) : 0;
		$status_map   = $user_id ? Mahan_Progress::course_lesson_status( $user_id, $course_id ) : array();

		// Compute lock state in the flat, canonical lesson order so the
		// outline agrees exactly with the server-side gate (lesson_locked),
		// even when unit grouping metadata is inconsistent.
		$locked_map = array();
		if ( $enrolled ) {
			$flat_prev_done = true;
			foreach ( Mahan_Courses::get_course_lessons( $course_id ) as $flat_lesson ) {
				$flid    = (int) $flat_lesson->ID;
				$fstatus = isset( $status_map[ $flid ] ) ? $status_map[ $flid ] : 'not_started';
				$locked_map[ $flid ] = ( ! $flat_prev_done && 'completed' !== $fstatus && 'in_progress' !== $fstatus );
				$flat_prev_done = ( 'completed' === $fstatus );
			}
		}

		// Every unit's viva state in two queries, rather than four per unit —
		// the lesson list and the status map are already in hand here.
		$viva_states = ( $user_id && $enrolled )
			? Mahan_Viva::course_states( $user_id, $course_id, $status_map )
			: array();

		$units = array();
		foreach ( Mahan_Courses::get_course_units( $course_id ) as $unit ) {
			$lessons = array();
			foreach ( $unit['lessons'] as $lesson ) {
				$lid    = (int) $lesson->ID;
				$status = isset( $status_map[ $lid ] ) ? $status_map[ $lid ] : 'not_started';
				$locked = $enrolled && ! empty( $locked_map[ $lid ] );
				$lessons[] = array(
					'id'      => $lid,
					'title'   => get_the_title( $lid ),
					'type'    => Mahan_Utils::meta_str( $lid, Mahan_Courses::M_TYPE, 'reading' ),
					'xp'      => Mahan_Courses::lesson_xp( $lid ),
					'est_min' => Mahan_Utils::meta_int( $lid, Mahan_Courses::M_EST_MIN, 0 ),
					'status'  => $status,
					'locked'  => $locked,
				);
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
				// Live oral exam for this unit. Only meaningful for an enrolled
				// learner with a provider configured; otherwise the row simply
				// doesn't appear.
				'viva'    => isset( $viva_states[ $unit['title'] ] ) ? $viva_states[ $unit['title'] ] : null,
			);
		}

		return rest_ensure_response( array(
			'ok'           => true,
			'course'       => $summary,
			'outcomes'     => Mahan_Courses::course_outcomes( $course_id ),
			'references'   => Mahan_Courses::course_references( $course_id ),
			// Level ladder: the other rungs of this subject (beginner → expert),
			// so a learner can jump to the level that fits them.
			'ladder'       => Mahan_Variants::track_ladder( Mahan_Utils::meta_str( $course_id, Mahan_Variants::M_TRACK, '' ) ),
			'description'  => apply_filters( 'the_content', get_post_field( 'post_content', $course_id ) ),
			'promo_video'  => Mahan_Courses::promo_video( $course_id ),
			'prerequisite' => Mahan_Courses::prerequisite( $course_id ),
			'certificate'  => Mahan_Certificates::offered( $course_id ),
			// The issued credential, if this learner holds one — so the course
			// page shows the real serial and issue date instead of drawing a
			// card stamped with today.
			'my_certificate' => $user_id ? Mahan_Certificates::for_course( $user_id, $course_id ) : null,
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

		// Enforce sequential gating server-side: don't serve (or start) a
		// lesson whose predecessor isn't complete.
		if ( $enrolled && Mahan_Courses::lesson_locked( $user_id, $lesson_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'locked' ), 403 );
		}

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

		// Personalize (profile placeholders) then apply the learner's department
		// variant, so one lesson serves every field without duplicating it.
		$lesson_body = Mahan_Variants::apply(
			Mahan_Profile::personalize_content( $lesson->post_content, $user_id ),
			Mahan_Courses::lesson_variants( $lesson_id ),
			Mahan_Variants::field_for_user( $user_id )
		);

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
			// The lesson carries its course's categories so the front-end can
			// paint it in that course's colour without a second request.
			'course_categories' => $course_id ? Mahan_Courses::course_categories( $course_id ) : array(),
			'position'     => $position,
			'course_pct'   => $enrolled ? Mahan_Progress::course_progress_pct( $user_id, $course_id ) : 0,
			'unit_quiz'    => $unit_quiz,
			// Personalize the lesson body: {{profile placeholders}} resolve to the
			// reader (with natural fallbacks) so every lesson adapts, not just the
			// tutor — then overlay the department ("field") variant when the
			// lesson has one for this learner.
			'content'      => apply_filters( 'the_content', $lesson_body['content'] ),
			// Which department variant was applied ('' when none) so the UI can
			// only claim tailoring when it's actually true.
			'field'        => $lesson_body['applied'],
			'field_label'  => '' !== $lesson_body['applied'] ? Mahan_Variants::field_label( $lesson_body['applied'] ) : '',
			'topics'       => Mahan_Courses::lesson_topics( $lesson_id ),
			// Whether a per-lesson AI "For you" note can be generated for this
			// learner (AI configured + a profile to personalize from).
			'personalize'  => ( Mahan_Settings::ai_ready() && '' !== Mahan_Profile::signature( $user_id ) ),
			'type'         => Mahan_Utils::meta_str( $lesson_id, Mahan_Courses::M_TYPE, 'reading' ),
			// Normalized to { type, src } server-side — the app never embeds an
			// arbitrary URL, only what the whitelist let through.
			'video'        => Mahan_Courses::lesson_video( $lesson_id ),
			'est_min'      => Mahan_Utils::meta_int( $lesson_id, Mahan_Courses::M_EST_MIN, 0 ),
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
		if ( Mahan_Courses::lesson_locked( $user_id, $lesson_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'locked' ), 403 );
		}
		$res = Mahan_Progress::complete_lesson( $user_id, $lesson_id );
		// On course completion, hand back the certificate that was just
		// issued (the mahan_course_completed action mints it), so the
		// celebration can show the real credential rather than a placeholder.
		if ( ! empty( $res['course_completed'] ) ) {
			$issued             = Mahan_Certificates::for_course( $user_id, $course_id );
			$res['certificate'] = (bool) $issued;
			$res['my_certificate'] = $issued;
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
		if ( Mahan_Courses::lesson_locked( $user_id, $lesson_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'locked' ), 403 );
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
		// A fresh permutation per sitting: retaking a failed quiz must not be
		// answerable from memory of where the right option sat last time.
		$seed = wp_rand( 1, 2000000 );
		return rest_ensure_response( array(
			'ok'       => true,
			'seed'     => $seed,
			'quiz'     => Mahan_Quizzes::public_quiz( $def, $seed ),
			'enrolled' => Mahan_Enrollment::is_enrolled( $user_id, $course_id ),
			'best'     => $best ? array(
				'score'    => (int) $best['score'],
				'passed'   => (bool) $best['is_correct'],
				'attempts' => (int) $best['attempts'],
			) : null,
		) );
	}

	public static function submit_quiz( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$course_id = isset( $body['course_id'] ) ? absint( $body['course_id'] ) : 0;
		$unit      = isset( $body['unit'] ) ? (string) $body['unit'] : '';
		$answers   = isset( $body['answers'] ) && is_array( $body['answers'] ) ? $body['answers'] : array();
		// Travels with the sitting so grading reconstructs the option order the
		// learner actually saw, without the server storing anything in between.
		$seed      = isset( $body['seed'] ) ? absint( $body['seed'] ) : 0;

		if ( ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_enrolled' ), 403 );
		}

		// Normalize each answer: int index for choice types, string otherwise.
		$clean = array();
		foreach ( $answers as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( is_array( $v ) ) {
				// "Select all that apply" sends a list of chosen positions.
				// Casting it to a string here would have flattened every
				// multi-select answer to the literal "Array".
				$set = array();
				foreach ( $v as $one ) {
					if ( is_numeric( $one ) ) {
						$set[ (int) $one ] = true;
					}
				}
				$clean[ $key ] = array_keys( $set );
			} elseif ( is_numeric( $v ) ) {
				$clean[ $key ] = (int) $v;
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $v );
			}
		}

		$res = Mahan_Quizzes::grade( $user_id, $course_id, $unit, $clean, $seed );
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
		// hud() already carries 'reviews' (Mahan_Reviews::counts) — no refetch.

		// "Jump back in": give each in-progress course its next lesson so the
		// dashboard can deep-link straight into the lesson player.
		$courses = Mahan_Enrollment::get_user_courses( $user_id );
		$saved   = Mahan_Learner::saved( $user_id );
		$touched = Mahan_Learner::last_activity_map( $user_id );
		foreach ( $courses as &$c ) {
			$cid        = (int) $c['id'];
			$c['saved'] = in_array( $cid, $saved, true );
			// When the learner last worked on this course, so the dashboard can
			// resume the course they were actually studying rather than the one
			// they most recently enrolled in.
			$c['last_activity'] = isset( $touched[ $cid ] ) ? $touched[ $cid ] : '';
			if ( (int) $c['progress_pct'] < 100 ) {
				$next = Mahan_Courses::next_lesson( $user_id, $c['id'] );
				if ( $next ) {
					$c['next_lesson_id']    = $next;
					$c['next_lesson_title'] = get_the_title( $next );
				}
				// What is actually left, measured from the unfinished lessons —
				// "40% to go" says nothing about whether that is ten minutes or
				// two hours, and the difference decides whether someone starts.
				$c['minutes_left'] = Mahan_Learner::minutes_remaining( $user_id, (int) $c['id'] );
			}
		}
		unset( $c );

		return rest_ensure_response( array(
			'ok'      => true,
			'user'    => array(
				'id'           => $user_id,
				// Both keys so the HUD (reads `name`) and certificate (reads
				// `display_name`) work regardless of which shape they got.
				'name'         => $user ? $user->display_name : '',
				'display_name' => $user ? $user->display_name : '',
				'avatar'       => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			),
			'stats'       => $stats,
			'courses'     => $courses,
			'saved'       => Mahan_Learner::saved_courses( $user_id ),
			'badges'      => Mahan_Badges::for_user( $user_id ),
			'certificates' => Mahan_Certificates::for_user( $user_id ),
			'placement'   => Mahan_Placement::get( $user_id ),
			'leaderboard' => (bool) Mahan_Settings::get( 'leaderboard_enabled', 0 ),
			// Pace, so the dashboard can say whether this week beats last week
			// instead of only ever counting upwards.
			'week_summary' => Mahan_Learner::week_summary( $user_id ),
			'next_freeze'  => Mahan_Learner::next_freeze( $stats ),
			// The one unit that is finished and waiting to be examined. Computed
			// only for the course the learner is actually mid-way through, so a
			// dashboard load costs one course's worth of viva queries, not one
			// per enrollment.
			'viva_ready'   => self::viva_ready( $user_id, $courses ),
		) );
	}

	/**
	 * The first unit of the learner's current course whose live assessment is
	 * unlocked and not yet passed.
	 *
	 * The viva is the highest-value action in the product and the dashboard
	 * never mentioned it — you had to walk into the course to discover a unit
	 * was waiting.
	 *
	 * @param int     $user_id Learner.
	 * @param array[] $courses /me course list (already ordered).
	 * @return array|null { course_id, course_title, unit, resume }
	 */
	private static function viva_ready( $user_id, $courses ) {
		if ( ! class_exists( 'Mahan_Viva' ) || ! Mahan_Viva::available() ) {
			return null;
		}
		// The course they were last working on — the same one the "continue"
		// card points at, so the dashboard never offers two different courses.
		$focus = null;
		foreach ( (array) $courses as $c ) {
			if ( (int) $c['progress_pct'] >= 100 ) {
				continue;
			}
			if ( null === $focus || (string) $c['last_activity'] > (string) $focus['last_activity'] ) {
				$focus = $c;
			}
		}
		if ( ! $focus ) {
			return null;
		}

		$course_id = (int) $focus['id'];
		$status    = Mahan_Progress::course_lesson_status( $user_id, $course_id );
		$states    = Mahan_Viva::course_states( $user_id, $course_id, $status );
		foreach ( $states as $unit => $state ) {
			if ( empty( $state['unlocked'] ) || 'passed' === $state['status'] ) {
				continue;
			}
			return array(
				'course_id'    => $course_id,
				'course_title' => (string) $focus['title'],
				'unit'         => (string) $unit,
				'resume'       => ! empty( $state['resume'] ),
			);
		}
		return null;
	}

	/**
	 * The learner's profile: who they are and what they have become.
	 *
	 * Separate from /me because it is heavier (skill and total queries scan
	 * history) and the dashboard reloads far more often than the profile does.
	 */
	public static function profile_summary( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		$profile = class_exists( 'Mahan_Profile' ) ? Mahan_Profile::get_profile( $user_id ) : array();

		$totals = Mahan_Learner::totals( $user_id );
		$stats  = Mahan_Gamification::hud( $user_id );

		return rest_ensure_response(
			array(
				'ok'           => true,
				'user'         => array(
					'name'   => $user ? $user->display_name : '',
					'avatar' => get_avatar_url( $user_id, array( 'size' => 160 ) ),
					// The learner's own words about their work, when they gave
					// them — a profile headed only by a display name says nothing.
					'role'    => isset( $profile['role'] ) ? (string) $profile['role'] : '',
					'goal'    => isset( $profile['primary_goal'] ) ? (string) $profile['primary_goal'] : '',
				),
				'stats'        => $stats,
				'totals'       => $totals,
				'skills'       => Mahan_Learner::skills( $user_id ),
				'activity'     => Mahan_Gamification::activity_map( $user_id, 91 ),
				'certificates' => Mahan_Certificates::for_user( $user_id ),
				'placement'    => Mahan_Placement::get( $user_id ),
				'badges'       => Mahan_Badges::for_user( $user_id ),
			)
		);
	}

	/**
	 * Save / unsave a course for later.
	 */
	public static function toggle_saved( WP_REST_Request $request ) {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = get_post( $course_id );
		if ( ! $course || Mahan_CPT::COURSE !== $course->post_type || 'publish' !== $course->post_status ) {
			return new WP_Error( 'mahan_not_found', __( 'Course not found.', 'mahan-academy' ), array( 'status' => 404 ) );
		}
		$state = Mahan_Learner::toggle_saved( get_current_user_id(), $course_id );
		return rest_ensure_response( array_merge( array( 'ok' => true ), $state ) );
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
		// Don't expose unpublished learning paths on a public endpoint.
		if ( 'publish' !== get_post_status( $path_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}
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
		$seed   = isset( $body['seed'] ) ? absint( $body['seed'] ) : 0;
		$res    = Mahan_Reviews::grade( $user_id, $rid, $answer, $token, $seed );
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

	/* ------------------------------------------------------------------ */
	/* On-demand AI practice                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Generate a fresh practice set for a lesson.
	 */
	public static function practice( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$lesson_id = isset( $body['lesson_id'] ) ? absint( $body['lesson_id'] ) : 0;
		$count     = isset( $body['count'] ) ? absint( $body['count'] ) : 0;
		if ( ! $lesson_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_lesson_id' ), 400 );
		}
		$res = Mahan_Practice::generate( $user_id, $lesson_id, $count );
		if ( empty( $res['ok'] ) ) {
			$err    = isset( $res['error'] ) ? $res['error'] : '';
			$status = 422;
			if ( 'not_found' === $err ) {
				$status = 404;
			} elseif ( 'not_enrolled' === $err || 'locked' === $err ) {
				$status = 403;
			}
			return new WP_REST_Response( $res, $status );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * Grade one answer within a generated practice set.
	 */
	public static function practice_grade( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$token   = isset( $body['token'] ) ? (string) $body['token'] : '';
		$index   = isset( $body['index'] ) ? absint( $body['index'] ) : 0;
		$answer  = isset( $body['answer'] ) ? $body['answer'] : '';
		if ( '' === $token ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_token' ), 400 );
		}
		$res = Mahan_Practice::grade( $user_id, $token, $index, $answer );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( $res, 404 );
		}
		return rest_ensure_response( $res );
	}

	/* ------------------------------------------------------------------ */
	/* Live AI oral exam (viva)                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * HTTP status for a viva error code. A locked unit is a 403, a missing one
	 * a 404, and "the provider didn't answer" a 503 — the browser retries the
	 * last one and only the last one.
	 */
	private static function viva_status( $error ) {
		switch ( (string) $error ) {
			case 'not_found':
				return 404;
			case 'not_enrolled':
			case 'locked':
				return 403;
			case 'generation_failed':
			case 'grading_failed':
			case 'ai_unavailable':
				return 503;
			case 'empty_answer':
				return 400;
			case 'daily_limit':
				return 429;
			default:
				return 422;
		}
	}

	/**
	 * Open (or resume) a viva sitting for a unit.
	 */
	public static function viva_start( WP_REST_Request $request ) {
		$body      = $request->get_json_params();
		$course_id = isset( $body['course_id'] ) ? absint( $body['course_id'] ) : 0;
		$unit      = isset( $body['unit'] ) ? (string) $body['unit'] : '';
		if ( ! $course_id || '' === trim( $unit ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_params' ), 400 );
		}
		$res = Mahan_Viva::start( get_current_user_id(), $course_id, $unit );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( $res, self::viva_status( isset( $res['error'] ) ? $res['error'] : '' ) );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * Submit one answer in a live sitting.
	 */
	public static function viva_answer( WP_REST_Request $request ) {
		$body       = $request->get_json_params();
		$session_id = isset( $body['session_id'] ) ? absint( $body['session_id'] ) : 0;
		$answer     = isset( $body['answer'] ) ? (string) $body['answer'] : '';
		if ( ! $session_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_params' ), 400 );
		}
		$res = Mahan_Viva::answer( get_current_user_id(), $session_id, $answer );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( $res, self::viva_status( isset( $res['error'] ) ? $res['error'] : '' ) );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * Retire a sitting the learner walked out of.
	 */
	public static function viva_abandon( WP_REST_Request $request ) {
		$body       = $request->get_json_params();
		$session_id = isset( $body['session_id'] ) ? absint( $body['session_id'] ) : 0;
		if ( ! $session_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_params' ), 400 );
		}
		$res = Mahan_Viva::abandon( get_current_user_id(), $session_id );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( $res, 404 );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * Generate (or return cached) the per-lesson "how this applies to your work"
	 * personalization note.
	 */
	public static function lesson_personalize( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$body      = $request->get_json_params();
		$lesson_id = isset( $body['lesson_id'] ) ? absint( $body['lesson_id'] ) : 0;
		if ( ! $lesson_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_lesson_id' ), 400 );
		}
		// Only for enrolled, unlocked lessons — mirrors the tutor/practice gate.
		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		if ( ! $course_id || ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'not_enrolled' ), 403 );
		}
		if ( Mahan_Courses::lesson_locked( $user_id, $lesson_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'locked' ), 403 );
		}
		$res = Mahan_Personalization::for_you( $user_id, $lesson_id );
		if ( empty( $res['ok'] ) ) {
			// A missing profile or unconfigured AI isn't an error — the SPA just
			// hides the card. Use 200 so it isn't logged as a failure.
			return rest_ensure_response( $res );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * Personalized recommendations for the current learner.
	 */
	public static function recommendations( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$limit   = absint( $request->get_param( 'limit' ) );
		$res     = Mahan_Recommend::for_user( $user_id, $limit > 0 ? $limit : 6 );

		// Attach enrollment/progress so cards render consistently with the catalog.
		foreach ( $res['recommended'] as &$course ) {
			$course['enrolled']     = false;
			$course['progress_pct'] = 0;
			$enr = Mahan_Enrollment::get( $user_id, $course['id'] );
			if ( $enr ) {
				$course['enrolled']     = true;
				$course['progress_pct'] = (int) $enr['progress_pct'];
			}
		}
		unset( $course );

		return rest_ensure_response( array_merge( array( 'ok' => true ), $res ) );
	}

	/* ------------------------------------------------------------------ */
	/* Placement                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * A sitting, plus whatever the learner scored last time.
	 */
	public static function placement_get( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		// The seed travels with the sitting and comes back on submit: it is
		// what lets grading reconstruct the option order without the server
		// keeping any state between the two calls.
		$seed      = wp_rand( 1, 2000000 );
		$questions = Mahan_Placement::sitting( Mahan_Placement::LENGTH, $seed );

		return rest_ensure_response( array(
			'ok'        => true,
			'seed'      => $seed,
			'questions' => $questions,
			'previous'  => Mahan_Placement::get( $user_id ),
			'levels'    => array_values( Mahan_Placement::TIERS ),
		) );
	}

	/**
	 * Grade a sitting, store the level, and say where to start.
	 */
	public static function placement_submit( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$answers = isset( $body['answers'] ) && is_array( $body['answers'] ) ? $body['answers'] : array();
		$seed    = isset( $body['seed'] ) ? absint( $body['seed'] ) : 0;

		if ( empty( $answers ) ) {
			return new WP_Error( 'mahan_no_answers', __( 'No answers submitted.', 'mahan-academy' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $answers as $key => $choice ) {
			$clean[ sanitize_text_field( (string) $key ) ] = (int) $choice;
		}

		$result = Mahan_Placement::grade( $clean, $seed );
		$stored = Mahan_Placement::save( $user_id, $result );

		// Where to actually go next: the rung of each ladder that matches the
		// level we just measured.
		$starts = array();
		foreach ( Mahan_Courses::get_courses() as $course ) {
			$track = Mahan_Utils::meta_str( $course->ID, Mahan_Variants::M_TRACK, '' );
			if ( '' === $track || isset( $starts[ $track ] ) ) {
				continue;
			}
			$rung = Mahan_Placement::start_rung( $track, $stored['level_rank'] );
			if ( $rung ) {
				$starts[ $track ] = $rung;
			}
		}

		return rest_ensure_response( array_merge(
			array( 'ok' => true, 'start' => array_values( $starts ) ),
			$result,
			array( 'taken_at' => $stored['taken_at'] )
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Certificates                                                        */
	/* ------------------------------------------------------------------ */

	public static function certificates_mine( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'ok'           => true,
			'certificates' => Mahan_Certificates::for_user( get_current_user_id() ),
			'issuer'       => get_bloginfo( 'name' ),
		) );
	}

	/**
	 * Public verification by serial.
	 *
	 * Always 200: whether a serial is valid is the answer, not an error, and
	 * a 404 would let a scraper distinguish "no such certificate" from other
	 * failures slightly more cheaply.
	 */
	public static function certificate_verify( WP_REST_Request $request ) {
		$cert = Mahan_Certificates::verify( (string) $request->get_param( 'serial' ) );
		if ( ! $cert ) {
			return rest_ensure_response( array( 'ok' => true, 'valid' => false ) );
		}
		return rest_ensure_response( array_merge( array( 'ok' => true, 'valid' => true ), $cert ) );
	}

	/* ------------------------------------------------------------------ */
	/* Language                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Record the reader's language choice.
	 *
	 * The route is public so guests can switch too, but the write still needs
	 * a valid REST nonce — otherwise any page on the internet could quietly
	 * flip a signed-in learner's academy into a language they can't read.
	 */
	public static function set_language( WP_REST_Request $request ) {
		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Invalid or missing nonce.', 'mahan-academy' ), array( 'status' => 403 ) );
		}
		if ( ! (int) Mahan_Settings::get( 'learner_language', 1 ) ) {
			return new WP_Error( 'mahan_language_locked', __( 'Language switching is turned off on this site.', 'mahan-academy' ), array( 'status' => 403 ) );
		}

		$requested = (string) $request->get_param( 'locale' );
		if ( '' !== $requested && ! Mahan_I18n::is_supported( $requested ) ) {
			return new WP_Error( 'mahan_language_unsupported', __( 'That language is not available.', 'mahan-academy' ), array( 'status' => 400 ) );
		}

		$saved = Mahan_I18n::set_preference( get_current_user_id(), $requested );

		return rest_ensure_response(
			array(
				'ok'     => true,
				'locale' => '' !== $saved ? $saved : Mahan_I18n::current(),
				'saved'  => $saved,
			)
		);
	}
}
