<?php
/**
 * Adaptive review — a spaced-repetition engine for questions the learner got
 * wrong.
 *
 * Every time a deterministic exercise or quiz question is graded, the result is
 * fed to {@see Mahan_Reviews::record()}. Questions answered *incorrectly* enter
 * a review queue and are re-asked:
 *
 *   1. immediately, at the end of the lesson ("review your misses"), and
 *   2. on later days, at growing intervals (a Leitner box schedule), until the
 *      learner has answered them correctly enough times to "graduate" them.
 *
 * Each queued item stores a self-contained snapshot of the question (with its
 * answer) so it can be re-asked and graded independently of the lesson — and so
 * the same concept can be re-served later as an AI-generated variant
 * ("ask it a different way", optionally from a different provider/model).
 *
 * Deterministic types only (multiple choice, true/false, fill-in-the-blank);
 * AI-graded open answers are never auto-queued because they can't be re-graded
 * reliably without a human/AI in the loop each time.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Reviews {

	const TYPES        = array( 'multiple_choice', 'true_false', 'fill_blank' );
	const MAX_BOX      = 6;
	const MASTERED_BOX = 5;

	/**
	 * Leitner intervals per box. Box 0 is due almost immediately (same
	 * session); higher boxes push the item further into the future.
	 */
	const INTERVALS = array(
		0 => '+0 seconds', // due immediately (end-of-lesson re-drill + due queue)
		1 => '+1 day',
		2 => '+3 days',
		3 => '+7 days',
		4 => '+16 days',
		5 => '+35 days',
		6 => '+75 days',
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mahan_reviews';
	}

	public static function enabled() {
		return (bool) Mahan_Settings::get( 'review_enabled', 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Item keys                                                           */
	/* ------------------------------------------------------------------ */

	public static function key_exercise( $lesson_id, $ex_key ) {
		return 'ex:' . (int) $lesson_id . ':' . (string) $ex_key;
	}

	public static function key_quiz( $course_id, $unit, $q_key ) {
		return 'qz:' . (int) $course_id . ':' . md5( (string) $unit ) . ':' . (string) $q_key;
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * A future wall-clock datetime, computed as a naive offset from the
	 * site-local "now" (so it compares directly against now_mysql()).
	 *
	 * @param int $box Leitner box.
	 * @return string MySQL datetime.
	 */
	private static function due_for_box( $box ) {
		$box    = max( 0, min( self::MAX_BOX, (int) $box ) );
		$offset = isset( self::INTERVALS[ $box ] ) ? self::INTERVALS[ $box ] : '+1 day';
		try {
			$dt = new DateTime( Mahan_Utils::now_mysql() );
			$dt->modify( $offset );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return Mahan_Utils::now_mysql();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Recording results                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Feed a graded deterministic question into the review queue.
	 *
	 * A first-try-correct question is never queued (there's nothing to
	 * review). A wrong answer creates or resets the item to box 0 (due now).
	 * A correct answer on an already-queued item advances its box and pushes
	 * the next review out.
	 *
	 * @param int   $user_id User id.
	 * @param array $ctx     source, course_id, lesson_id, item_key, concept, snapshot (question array), type.
	 * @param bool  $correct Whether the learner answered correctly.
	 */
	public static function record( $user_id, array $ctx, $correct ) {
		if ( ! self::enabled() ) {
			return;
		}
		$type = isset( $ctx['type'] ) ? (string) $ctx['type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return; // Only deterministic questions can be re-graded automatically.
		}
		global $wpdb;
		$table   = self::table();
		$user_id = (int) $user_id;
		$key     = (string) $ctx['item_key'];
		$now     = Mahan_Utils::now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, box, reps, lapses FROM {$table} WHERE user_id = %d AND item_key = %s", $user_id, $key ),
			ARRAY_A
		);

		if ( ! $row ) {
			if ( $correct ) {
				return; // Nailed it first time — nothing to review.
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'user_id'     => $user_id,
					'course_id'   => (int) $ctx['course_id'],
					'lesson_id'   => isset( $ctx['lesson_id'] ) ? (int) $ctx['lesson_id'] : 0,
					'source'      => isset( $ctx['source'] ) ? (string) $ctx['source'] : 'exercise',
					'item_key'    => $key,
					'qtype'       => $type,
					'concept'     => mb_substr( (string) $ctx['concept'], 0, 180 ),
					'snapshot'    => wp_json_encode( $ctx['snapshot'] ),
					'box'         => 0,
					'reps'        => 1,
					'lapses'      => 1,
					'last_result' => 0,
					'due_at'      => self::due_for_box( 0 ),
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
			return;
		}

		// Existing item: advance or reset its box.
		$box = (int) $row['box'];
		if ( $correct ) {
			$box = min( self::MAX_BOX, $box + 1 );
		} else {
			$box = 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'box'         => $box,
				'reps'        => (int) $row['reps'] + 1,
				'lapses'      => (int) $row['lapses'] + ( $correct ? 0 : 1 ),
				'last_result' => $correct ? 1 : 0,
				'due_at'      => self::due_for_box( $box ),
				'updated_at'  => $now,
			),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%d', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Review items due now (across all courses), oldest-due first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max items.
	 * @return array[] raw rows.
	 */
	public static function due( $user_id, $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 50, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND due_at <= %s AND box < %d ORDER BY box ASC, due_at ASC LIMIT %d",
				(int) $user_id,
				Mahan_Utils::now_mysql(),
				self::MASTERED_BOX,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Not-yet-mastered items from one lesson — the end-of-lesson "review your
	 * misses" set (regardless of the exact due time).
	 *
	 * @param int $user_id   User id.
	 * @param int $lesson_id Lesson id.
	 * @return array[] raw rows.
	 */
	public static function for_lesson( $user_id, $lesson_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d AND box < %d ORDER BY box ASC, updated_at ASC LIMIT 20",
				(int) $user_id,
				(int) $lesson_id,
				self::MASTERED_BOX
			),
			ARRAY_A
		);
	}

	public static function for_lesson_count( $user_id, $lesson_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND lesson_id = %d AND box < %d",
				(int) $user_id,
				(int) $lesson_id,
				self::MASTERED_BOX
			)
		);
	}

	/**
	 * Queue summary: due now / still learning / mastered.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function counts( $user_id ) {
		global $wpdb;
		$table   = self::table();
		$user_id = (int) $user_id;
		if ( ! $user_id || ! self::enabled() ) {
			return array( 'due' => 0, 'learning' => 0, 'mastered' => 0 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$due = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND due_at <= %s AND box < %d", $user_id, Mahan_Utils::now_mysql(), self::MASTERED_BOX )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$learning = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND box < %d", $user_id, self::MASTERED_BOX ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mastered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND box >= %d", $user_id, self::MASTERED_BOX ) );
		return array( 'due' => $due, 'learning' => $learning, 'mastered' => $mastered );
	}

	/**
	 * Browser-safe shape of a queued item (no correct answers).
	 *
	 * @param array $row     Raw DB row.
	 * @param array $variant Optional AI variant question (already answer-stripped) to serve instead.
	 * @return array
	 */
	public static function public_item( $row, $variant = null ) {
		$snap = json_decode( (string) $row['snapshot'], true );
		$snap = is_array( $snap ) ? $snap : array();
		$q    = ( is_array( $variant ) && ! empty( $variant['question'] ) ) ? $variant : $snap;
		$type = (string) $row['qtype'];

		// Where the question came from — lets the session card show context
		// ("Prompt Basics · Lesson 3") so a mixed cross-course queue isn't
		// disorienting.
		$course_title = (int) $row['course_id'] ? get_the_title( (int) $row['course_id'] ) : '';
		$lesson_title = (int) $row['lesson_id'] ? get_the_title( (int) $row['lesson_id'] ) : '';

		$out = array(
			'review_id' => (int) $row['id'],
			'source'    => (string) $row['source'],
			'type'      => $type,
			'question'  => isset( $q['question'] ) ? (string) $q['question'] : '',
			'lesson_id' => (int) $row['lesson_id'],
			'course_id' => (int) $row['course_id'],
			'context'   => trim( implode( ' · ', array_filter( array( $course_title, $lesson_title ) ) ) ),
			'box'       => (int) $row['box'],
			'is_variant' => ! empty( $variant ),
		);
		if ( ( 'multiple_choice' === $type || 'true_false' === $type ) && ! empty( $q['options'] ) ) {
			$out['options'] = array_values( array_map( 'strval', $q['options'] ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Grading a review answer                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Grade one deterministic question definition against a submitted answer.
	 *
	 * @param array $q      Question def (with answer).
	 * @param mixed $answer Submitted answer.
	 * @return array { correct, correct_index, correct_text }
	 */
	public static function grade_question( $q, $answer ) {
		$type = isset( $q['type'] ) ? (string) $q['type'] : 'multiple_choice';
		if ( 'multiple_choice' === $type || 'true_false' === $type ) {
			$correct = isset( $q['answer'] ) ? (int) $q['answer'] : -1;
			$chosen  = is_numeric( $answer ) ? (int) $answer : -1;
			$ctext   = ( isset( $q['options'] ) && isset( $q['options'][ $correct ] ) ) ? (string) $q['options'][ $correct ] : '';
			return array(
				'correct'       => ( $chosen >= 0 && $chosen === $correct ),
				'correct_index' => $correct,
				'correct_text'  => $ctext,
			);
		}
		// fill_blank
		$given    = trim( is_array( $answer ) ? implode( ' ', $answer ) : (string) $answer );
		$case     = ! empty( $q['case_sensitive'] );
		$accepted = array();
		if ( isset( $q['answer_text'] ) && '' !== trim( (string) $q['answer_text'] ) ) {
			$accepted[] = (string) $q['answer_text'];
		}
		if ( ! empty( $q['accept'] ) && is_array( $q['accept'] ) ) {
			foreach ( $q['accept'] as $a ) {
				$accepted[] = (string) $a;
			}
		}
		$norm = function ( $s ) use ( $case ) {
			$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
			return $case ? $s : mb_strtolower( $s );
		};
		$gn = $norm( $given );
		$ok = false;
		foreach ( $accepted as $a ) {
			if ( '' !== $norm( $a ) && $norm( $a ) === $gn ) {
				$ok = true;
				break;
			}
		}
		return array(
			'correct'       => $ok,
			'correct_index' => null,
			'correct_text'  => isset( $accepted[0] ) ? $accepted[0] : '',
		);
	}

	/**
	 * Grade a review answer, reschedule the item, and award review XP.
	 *
	 * @param int    $user_id       User id.
	 * @param int    $review_id     Review row id (must belong to the user).
	 * @param mixed  $answer        Submitted answer.
	 * @param string $variant_token Optional token identifying an AI variant to grade against.
	 * @return array
	 */
	public static function grade( $user_id, $review_id, $answer, $variant_token = '' ) {
		global $wpdb;
		$table   = self::table();
		$user_id = (int) $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", (int) $review_id, $user_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}

		// Grade against the AI variant (if a valid token was supplied) or the
		// original snapshot.
		$q          = null;
		$used_token = '';
		if ( '' !== (string) $variant_token ) {
			$safe  = preg_replace( '/[^a-z0-9]/i', '', (string) $variant_token );
			$stash = get_transient( 'mahan_rev_' . $safe );
			if ( is_array( $stash ) && (int) $stash['review_id'] === (int) $review_id && (int) $stash['user_id'] === $user_id ) {
				$q          = $stash['q'];
				$used_token = $safe;
			}
		}
		if ( null === $q ) {
			$snap = json_decode( (string) $row['snapshot'], true );
			$q    = is_array( $snap ) ? $snap : array();
		}
		if ( ! isset( $q['type'] ) ) {
			$q['type'] = (string) $row['qtype'];
		}

		// A variant is single-use: consume it so the same token can't be
		// replayed to farm XP for 30 minutes.
		if ( '' !== $used_token ) {
			delete_transient( 'mahan_rev_' . $used_token );
		}

		// XP is capped at once per item per day. This is what stops farming:
		// a wrong answer makes the item due again immediately (so the
		// end-of-lesson re-drill can still earn XP), but a wrong→correct→
		// wrong→correct loop can't repeatedly award because today's award is
		// already recorded in last_xp_date.
		$now       = Mahan_Utils::now_mysql();
		$today     = Mahan_Utils::today();
		$was_due   = ( (string) $row['due_at'] <= $now );
		$paid_today = ( (string) $row['last_xp_date'] === $today );

		$graded  = self::grade_question( $q, $answer );
		$correct = (bool) $graded['correct'];

		$eligible = ( $correct && $was_due && ! $paid_today );

		// Reschedule using the same box logic as record().
		$box    = $correct ? min( self::MAX_BOX, (int) $row['box'] + 1 ) : 0;
		$fields = array(
			'box'         => $box,
			'reps'        => (int) $row['reps'] + 1,
			'lapses'      => (int) $row['lapses'] + ( $correct ? 0 : 1 ),
			'last_result' => $correct ? 1 : 0,
			'due_at'      => self::due_for_box( $box ),
			'updated_at'  => $now,
		);
		$formats = array( '%d', '%d', '%d', '%d', '%s', '%s' );
		if ( $eligible ) {
			$fields['last_xp_date'] = $today;
			$formats[]              = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			$fields,
			array( 'id' => (int) $row['id'] ),
			$formats,
			array( '%d' )
		);

		// A little XP for clearing a review (keeps the streak/goal loop alive).
		$awarded    = 0;
		$leveled_up = false;
		if ( $eligible ) {
			$xp = max( 1, (int) Mahan_Settings::get( 'review_xp', 5 ) );
			// record_activity() first so the streak is current when add_xp()
			// computes its streak bonus (and a lapsed streak is reset first).
			Mahan_Gamification::record_activity( $user_id );
			$award      = Mahan_Gamification::add_xp( $user_id, $xp, 'review', (int) $row['lesson_id'] );
			$awarded    = (int) $award['awarded'];
			$leveled_up = ! empty( $award['leveled_up'] );
			do_action( 'mahan_review_cleared', $user_id, (int) $review_id, $box );
		}

		return array(
			'ok'            => true,
			'is_correct'    => $correct,
			'correct_index' => $graded['correct_index'],
			'correct_text'  => (string) $graded['correct_text'],
			'mastered'      => ( $box >= self::MASTERED_BOX ),
			'box'           => $box,
			'xp_awarded'    => $awarded,
			'leveled_up'    => $leveled_up,
			'new_badges'    => Mahan_Badges::take_new( $user_id ),
			'stats'         => Mahan_Gamification::hud( $user_id ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* AI variants ("ask it a different way", from another model)          */
	/* ------------------------------------------------------------------ */

	/**
	 * A provider (with a configured key) other than the active one, so
	 * variants can come from a different model. Falls back to the active
	 * provider when only one is configured.
	 *
	 * @return string provider id.
	 */
	private static function variant_provider() {
		$active = (string) Mahan_Settings::get( 'ai_provider', 'anthropic' );
		$others = array();
		foreach ( array( 'anthropic', 'openai', 'google' ) as $p ) {
			if ( '' !== trim( (string) Mahan_AI::key_for( $p ) ) && $p !== $active ) {
				$others[] = $p;
			}
		}
		return ! empty( $others ) ? $others[0] : $active;
	}

	/**
	 * Generate an AI variant of a queued item: the same concept and type,
	 * reworded (and, when possible, from a different provider/model). The full
	 * variant (with its answer) is stashed in a short-lived transient keyed by
	 * the returned token so {@see grade()} can score it.
	 *
	 * @param int $user_id   User id.
	 * @param int $review_id Review row id.
	 * @return array { ok, item, variant_token } or { ok:false, error }
	 */
	public static function variant( $user_id, $review_id ) {
		global $wpdb;
		$table   = self::table();
		$user_id = (int) $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", (int) $review_id, $user_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( ! Mahan_Settings::ai_ready() ) {
			return array( 'ok' => false, 'error' => 'ai_unavailable' );
		}

		$snap = json_decode( (string) $row['snapshot'], true );
		$snap = is_array( $snap ) ? $snap : array();
		$type = (string) $row['qtype'];

		$variant = self::generate_variant( $type, $snap, $user_id );
		if ( ! $variant ) {
			return array( 'ok' => false, 'error' => 'generation_failed' );
		}

		$token = wp_generate_password( 24, false, false );
		set_transient(
			'mahan_rev_' . $token,
			array( 'review_id' => (int) $review_id, 'user_id' => $user_id, 'q' => $variant ),
			30 * MINUTE_IN_SECONDS
		);

		return array(
			'ok'            => true,
			'item'          => self::public_item( $row, $variant ),
			'variant_token' => $token,
		);
	}

	/**
	 * Ask the AI for a same-concept, same-type question. Returns a validated
	 * question def (with answer) or null.
	 *
	 * @param string $type Deterministic type.
	 * @param array  $orig Original question def.
	 * @return array|null
	 */
	private static function generate_variant( $type, $orig, $user_id = 0 ) {
		$question = isset( $orig['question'] ) ? (string) $orig['question'] : '';
		$provider = self::variant_provider();

		$system = 'You write practice questions for an online course. '
			. 'You are given a question the learner previously got wrong. Produce ONE fresh question that tests the '
			. 'exact same concept from a different angle (reworded, new scenario or example), '
			. 'staying within the same subject matter as the original. Keep it self-contained and unambiguous. '
			. 'Write the question in the same language as the original question.';

		// Personalize the rewrite to the learner: use a scenario from their world
		// and tune the challenge to their current level, rather than a generic
		// same-difficulty reword.
		if ( $user_id && class_exists( 'Mahan_Personalization' ) ) {
			$ctx = Mahan_Personalization::learner_context( (int) $user_id );
			if ( '' !== $ctx ) {
				$system .= "\n\n" . $ctx . "\n" . Mahan_Personalization::difficulty_directive( (int) $user_id )
					. ' Where natural, set the new question in a scenario from the learner\'s role or tools — but keep it testing the same concept.';
			}
		}

		if ( 'multiple_choice' === $type ) {
			$schema = '{"question":"<text>","options":["<a>","<b>","<c>","<d>"],"answer":<0-based index of the correct option>}';
			$rules  = 'Provide exactly 4 options with one clearly correct answer.';
		} elseif ( 'true_false' === $type ) {
			$schema = '{"question":"<a statement that is clearly true or false>","answer":<0 for True, 1 for False>}';
			$rules  = 'The question must be a single statement judged True or False.';
		} else { // fill_blank
			$schema = '{"question":"<text with ___ marking the blank>","answer_text":"<the expected word/phrase>","accept":["<synonym>","<variant>"]}';
			$rules  = 'Put ___ where the blank goes. Keep the expected answer short (1-4 words).';
		}

		$user_msg = "ORIGINAL QUESTION (do not copy it verbatim):\n{$question}\n\n"
			. "TYPE: {$type}\n{$rules}\n\n"
			. "Respond ONLY with a JSON object of this exact shape:\n{$schema}";

		$res = Mahan_AI::complete(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			array(
				'provider'    => $provider,
				'json'        => true,
				'max_tokens'  => 500,
				'temperature' => 0.8,
			)
		);
		if ( empty( $res['ok'] ) ) {
			return null;
		}
		$data = Mahan_Utils::extract_json( $res['text'] );
		if ( ! is_array( $data ) || empty( $data['question'] ) ) {
			return null;
		}

		return self::sanitize_variant( $type, $data );
	}

	/**
	 * Validate + sanitize an AI-produced variant into a gradeable question def.
	 *
	 * @param string $type Type.
	 * @param array  $data Raw AI JSON.
	 * @return array|null
	 */
	private static function sanitize_variant( $type, $data ) {
		$q = array(
			'type'     => $type,
			'question' => sanitize_textarea_field( (string) $data['question'] ),
		);
		if ( 'multiple_choice' === $type ) {
			$opts = ( isset( $data['options'] ) && is_array( $data['options'] ) ) ? array_values( array_map( 'sanitize_text_field', $data['options'] ) ) : array();
			$opts = array_values( array_filter( $opts, function ( $o ) { return '' !== trim( $o ); } ) );
			if ( count( $opts ) < 2 ) {
				return null;
			}
			$q['options'] = $opts;
			$q['answer']  = isset( $data['answer'] ) ? max( 0, min( count( $opts ) - 1, (int) $data['answer'] ) ) : 0;
		} elseif ( 'true_false' === $type ) {
			$q['options'] = array( __( 'True', 'mahan-academy' ), __( 'False', 'mahan-academy' ) );
			$q['answer']  = ( isset( $data['answer'] ) && 1 === (int) $data['answer'] ) ? 1 : 0;
		} else { // fill_blank
			$ans = isset( $data['answer_text'] ) ? sanitize_text_field( (string) $data['answer_text'] ) : '';
			if ( '' === trim( $ans ) ) {
				return null;
			}
			$q['answer_text']    = $ans;
			$q['accept']         = ( isset( $data['accept'] ) && is_array( $data['accept'] ) ) ? array_values( array_map( 'sanitize_text_field', $data['accept'] ) ) : array();
			$q['case_sensitive'] = 0;
		}
		return $q;
	}
}
