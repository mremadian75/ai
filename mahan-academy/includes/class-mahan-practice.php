<?php
/**
 * On-demand AI practice generator.
 *
 * Given a lesson, asks the active AI provider for a fresh set of practice
 * questions that test the lesson's concepts (its {@see Mahan_CPT::TOPIC}s),
 * tuned to the learner's adaptive difficulty and written with
 * misconception-targeting distractors. Only deterministic types are produced
 * (multiple choice, true/false, fill-in-the-blank) so every question can be
 * graded instantly and re-fed into the spaced-repetition review queue.
 *
 * The generated set (with answers) is stashed in a short-lived per-user
 * transient keyed by an opaque token; the browser only ever sees the
 * answer-stripped questions and grades one at a time via {@see grade()}. XP is
 * awarded at most once per question (tracked in the stash) so it can't be
 * farmed.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Practice {

	/** Deterministic, instantly-gradeable question types. */
	const TYPES = array( 'multiple_choice', 'true_false', 'fill_blank' );

	const MAX_COUNT     = 5;
	const DEFAULT_COUNT = 3;
	const TTL           = 1800; // 30 minutes.

	/* ------------------------------------------------------------------ */
	/* Generation                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Generate a practice set for a lesson.
	 *
	 * @param int $user_id   User id.
	 * @param int $lesson_id Lesson id.
	 * @param int $count     Requested question count (clamped 1..MAX_COUNT).
	 * @return array { ok, token, lesson_id, topics, questions } or { ok:false, error }.
	 */
	public static function generate( $user_id, $lesson_id, $count ) {
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;
		$count     = (int) $count;
		$count     = $count > 0 ? min( self::MAX_COUNT, $count ) : self::DEFAULT_COUNT;

		if ( ! Mahan_Settings::ai_ready() ) {
			return array( 'ok' => false, 'error' => 'ai_unavailable' );
		}

		$lesson = get_post( $lesson_id );
		if ( ! $lesson || Mahan_CPT::LESSON !== $lesson->post_type || 'publish' !== $lesson->post_status ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}

		// Only for lessons the learner may actually see (enrolled + unlocked) —
		// mirrors the tutor/lesson gating so practice can't leak locked material.
		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );
		if ( ! $course_id || ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return array( 'ok' => false, 'error' => 'not_enrolled' );
		}
		if ( Mahan_Courses::lesson_locked( $user_id, $lesson_id ) ) {
			return array( 'ok' => false, 'error' => 'locked' );
		}

		$title  = get_the_title( $lesson_id );
		$topics = Mahan_Courses::lesson_topics( $lesson_id );
		$plain  = wp_strip_all_tags( (string) $lesson->post_content );
		$plain  = trim( preg_replace( '/\s+/', ' ', $plain ) );
		if ( strlen( $plain ) > 3000 ) {
			$plain = substr( $plain, 0, 3000 ) . '…';
		}

		$questions = self::ask_ai( $user_id, $title, $topics, $plain, $count );
		if ( empty( $questions ) ) {
			return array( 'ok' => false, 'error' => 'generation_failed' );
		}

		$token = wp_generate_password( 24, false, false );
		set_transient(
			'mahan_practice_' . $token,
			array(
				'user_id'   => $user_id,
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
				'questions' => $questions,
				'graded'    => array(),
			),
			self::TTL
		);

		$public = array();
		foreach ( $questions as $i => $q ) {
			$public[] = self::public_question( $i, $q );
		}

		return array(
			'ok'        => true,
			'token'     => $token,
			'lesson_id' => $lesson_id,
			'topics'    => $topics,
			'questions' => $public,
		);
	}

	/**
	 * Ask the AI for a validated set of deterministic questions.
	 *
	 * @return array[] sanitized question defs (with answers).
	 */
	private static function ask_ai( $user_id, $title, $topics, $content, $count ) {
		$system = 'You are an expert assessment writer for an online course that teaches people to use AI at work. '
			. 'Write fresh practice questions that test whether the learner truly understands the lesson\'s concepts — '
			. 'not whether they memorised its wording. '
			. 'Use ONLY these types: multiple_choice (exactly 4 options), true_false, and fill_blank. '
			. 'Vary the types and vary which option is correct. '
			. 'For multiple choice, write plausible distractors that reflect the specific misconceptions a learner who '
			. 'does NOT understand the concept would fall for, with exactly ONE clearly correct answer. '
			. 'Keep every question self-contained and answerable from the lesson material. '
			. 'Write in the same language as the lesson.';

		// Personalize: pitch the questions at the learner's adaptive difficulty
		// and, where natural, their world.
		if ( class_exists( 'Mahan_Personalization' ) ) {
			$ctx = Mahan_Personalization::learner_context( $user_id );
			if ( '' !== $ctx ) {
				$system .= "\n\n" . $ctx . "\n" . Mahan_Personalization::difficulty_directive( $user_id );
			}
		}

		$topics_line = ! empty( $topics ) ? "\nKey concepts to test: " . implode( ', ', $topics ) : '';
		$schema      = '{"questions":[{"type":"multiple_choice","question":"<text>","options":["<a>","<b>","<c>","<d>"],"answer":<0-based index of the correct option>},'
			. '{"type":"true_false","question":"<a statement>","answer":<0 for True, 1 for False>},'
			. '{"type":"fill_blank","question":"<text with ___ for the blank>","answer_text":"<expected 1-4 words>","accept":["<synonym>"]}]}';

		$user_msg = "LESSON: {$title}{$topics_line}\n\nLESSON MATERIAL:\n{$content}\n\n"
			. "Generate exactly {$count} questions. Respond ONLY with a JSON object of this exact shape:\n{$schema}";

		$res = Mahan_AI::complete(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			array(
				'json'        => true,
				'max_tokens'  => 1200,
				'temperature' => 0.7,
			)
		);
		if ( empty( $res['ok'] ) ) {
			return array();
		}

		$data = Mahan_Utils::extract_json( $res['text'] );
		$list = ( is_array( $data ) && isset( $data['questions'] ) && is_array( $data['questions'] ) ) ? $data['questions'] : array();

		$out = array();
		foreach ( $list as $raw ) {
			$q = self::sanitize( $raw );
			if ( $q ) {
				$out[] = $q;
			}
			if ( count( $out ) >= $count ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Validate + coerce one AI question into a gradeable def (with answer), or null.
	 *
	 * @param array $data Raw AI question.
	 * @return array|null
	 */
	private static function sanitize( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : 'multiple_choice';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}
		$question = isset( $data['question'] ) ? sanitize_textarea_field( (string) $data['question'] ) : '';
		if ( '' === trim( $question ) ) {
			return null;
		}
		$q = array( 'type' => $type, 'question' => $question );

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

	/**
	 * Answer-stripped shape sent to the browser.
	 */
	private static function public_question( $index, $q ) {
		$out = array(
			'index'    => (int) $index,
			'type'     => (string) $q['type'],
			'question' => (string) $q['question'],
		);
		if ( isset( $q['options'] ) ) {
			$out['options'] = array_values( array_map( 'strval', $q['options'] ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Grading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Grade one practice answer against the stashed set.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Stash token.
	 * @param int    $index   Question index within the set.
	 * @param mixed  $answer  Submitted answer.
	 * @return array
	 */
	public static function grade( $user_id, $token, $index, $answer ) {
		$user_id = (int) $user_id;
		$index   = (int) $index;
		$safe    = preg_replace( '/[^a-z0-9]/i', '', (string) $token );
		$key     = 'mahan_practice_' . $safe;

		$stash = get_transient( $key );
		if ( ! is_array( $stash ) || (int) $stash['user_id'] !== $user_id ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( ! isset( $stash['questions'][ $index ] ) ) {
			return array( 'ok' => false, 'error' => 'bad_index' );
		}

		$q       = $stash['questions'][ $index ];
		$graded  = Mahan_Reviews::grade_question( $q, $answer );
		$correct = (bool) $graded['correct'];

		$awarded    = 0;
		$leveled_up = false;

		// Score each question only once (XP + review enqueue) so re-submitting
		// the same token/index can't farm.
		if ( empty( $stash['graded'][ $index ] ) ) {
			$stash['graded'][ $index ] = true;
			set_transient( $key, $stash, self::TTL );

			if ( $correct ) {
				$xp = max( 1, (int) Mahan_Settings::get( 'review_xp', 5 ) );
				Mahan_Gamification::record_activity( $user_id );
				$award      = Mahan_Gamification::add_xp( $user_id, $xp, 'practice', (int) $stash['lesson_id'] );
				$awarded    = (int) $award['awarded'];
				$leveled_up = ! empty( $award['leveled_up'] );
			}

			// Wrong practice answers join the spaced-repetition queue so they
			// come back later; a first-try-correct question is not queued.
			Mahan_Reviews::record(
				$user_id,
				array(
					'source'    => 'practice',
					'course_id' => (int) $stash['course_id'],
					'lesson_id' => (int) $stash['lesson_id'],
					'item_key'  => 'pr:' . (int) $stash['lesson_id'] . ':' . md5( (string) $q['question'] ),
					'concept'   => (string) $q['question'],
					'type'      => (string) $q['type'],
					'snapshot'  => $q,
				),
				$correct
			);
		}

		return array(
			'ok'            => true,
			'is_correct'    => $correct,
			'correct_index' => $graded['correct_index'],
			'correct_text'  => (string) $graded['correct_text'],
			'xp_awarded'    => $awarded,
			'leveled_up'    => $leveled_up,
			'new_badges'    => Mahan_Badges::take_new( $user_id ),
			'stats'         => Mahan_Gamification::hud( $user_id ),
		);
	}
}
