<?php
/**
 * Exercise grading. Multiple-choice is graded instantly server-side; open
 * answers (short_answer, reflection, prompt_task) are graded by the AI against
 * a rubric. XP is awarded only on the first correct attempt of each exercise.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Exercises {

	const AI_TYPES = array( 'short_answer', 'reflection', 'prompt_task' );
	const PASS_SCORE = 60;

	/**
	 * Grade a submitted answer.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $lesson_id Lesson id.
	 * @param string $key       Exercise key.
	 * @param mixed  $answer    Submitted answer (int index or string).
	 * @return array
	 */
	public static function grade( $user_id, $lesson_id, $key, $answer ) {
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;
		$course_id = Mahan_Courses::get_lesson_course_id( $lesson_id );

		$ex = Mahan_Courses::get_exercise( $lesson_id, $key );
		if ( ! $ex ) {
			return array( 'ok' => false, 'error' => 'unknown_exercise' );
		}
		$type = isset( $ex['type'] ) ? (string) $ex['type'] : 'multiple_choice';

		if ( 'multiple_choice' === $type ) {
			$graded = self::grade_multiple_choice( $ex, $answer );
		} elseif ( in_array( $type, self::AI_TYPES, true ) ) {
			$graded = self::grade_ai( $ex, $answer, $user_id );
		} else {
			$graded = array(
				'is_correct' => true,
				'score'      => 100,
				'feedback'   => __( 'Answer recorded.', 'mahan-academy' ),
			);
		}

		// XP: only the first correct attempt earns points.
		$xp_award = 0;
		if ( $graded['is_correct'] && ! self::has_correct_attempt( $user_id, $lesson_id, $key ) ) {
			$xp_award = isset( $ex['xp'] ) && (int) $ex['xp'] > 0
				? (int) $ex['xp']
				: (int) Mahan_Settings::get( 'xp_per_exercise', 10 );
		}

		self::store_attempt( $user_id, $lesson_id, $course_id, $key, $type, $answer, $graded, $xp_award );

		if ( $xp_award > 0 ) {
			Mahan_Gamification::add_xp( $user_id, $xp_award );
			Mahan_Gamification::record_activity( $user_id );
		}
		$stats = Mahan_Gamification::hud( $user_id );

		return array(
			'ok'          => true,
			'is_correct'  => (bool) $graded['is_correct'],
			'score'       => (int) $graded['score'],
			'feedback'    => (string) $graded['feedback'],
			'xp_awarded'  => $xp_award,
			'correct_index' => isset( $graded['correct_index'] ) ? $graded['correct_index'] : null,
			'stats'       => $stats,
		);
	}

	private static function grade_multiple_choice( $ex, $answer ) {
		$correct = isset( $ex['answer'] ) ? (int) $ex['answer'] : -1;
		$chosen  = is_numeric( $answer ) ? (int) $answer : -1;
		$is_ok   = ( $chosen >= 0 && $chosen === $correct );
		$feedback = $is_ok
			? self::pick( $ex, 'feedback_correct', __( 'Correct! Nicely done.', 'mahan-academy' ) )
			: self::pick( $ex, 'feedback_incorrect', __( 'Not quite — review the lesson and try again.', 'mahan-academy' ) );
		return array(
			'is_correct'    => $is_ok,
			'score'         => $is_ok ? 100 : 0,
			'feedback'      => $feedback,
			'correct_index' => $correct,
		);
	}

	private static function grade_ai( $ex, $answer, $user_id ) {
		$answer_str = is_array( $answer ) ? implode( "\n", $answer ) : (string) $answer;
		$answer_str = trim( $answer_str );

		if ( '' === $answer_str ) {
			return array(
				'is_correct' => false,
				'score'      => 0,
				'feedback'   => __( 'Please write an answer before submitting.', 'mahan-academy' ),
			);
		}

		// Graceful fallback if the AI provider is not configured.
		if ( ! Mahan_Settings::ai_ready() ) {
			$ok = ( strlen( $answer_str ) >= 20 );
			return array(
				'is_correct' => $ok,
				'score'      => $ok ? 70 : 30,
				'feedback'   => $ok
					? __( 'Answer recorded. (Connect an AI provider in settings for detailed feedback.)', 'mahan-academy' )
					: __( 'Try to expand your answer with more detail.', 'mahan-academy' ),
			);
		}

		$type     = isset( $ex['type'] ) ? (string) $ex['type'] : 'short_answer';
		$question = isset( $ex['question'] ) ? (string) $ex['question'] : '';
		$task     = isset( $ex['task'] ) ? (string) $ex['task'] : '';
		$rubric   = isset( $ex['rubric'] ) ? (string) $ex['rubric'] : '';

		$profile_map = Mahan_Profile::placeholder_map( $user_id );
		$profile_ctx = sprintf(
			'The learner is a %s (%s level). Their goal: %s.',
			isset( $profile_map['role'] ) ? $profile_map['role'] : 'professional',
			isset( $profile_map['ai_level'] ) ? $profile_map['ai_level'] : 'beginner',
			isset( $profile_map['primary_goal'] ) ? $profile_map['primary_goal'] : 'using AI at work'
		);

		if ( 'prompt_task' === $type ) {
			$system = 'You are an expert evaluator of AI prompts in a course that teaches people to use AI at work. '
				. 'Evaluate the prompt the student wrote for the given task. Judge clarity, context, specificity, '
				. 'constraints, and desired output format. Be encouraging but honest.';
			$user_msg = "TASK THE STUDENT WAS GIVEN:\n{$task}\n\n";
		} else {
			$system = 'You are a strict but encouraging grader for a course that teaches people to use AI at work. '
				. 'Grade the student\'s answer against the rubric.';
			$user_msg = "QUESTION:\n{$question}\n\n";
		}
		if ( '' !== trim( $rubric ) ) {
			$user_msg .= "RUBRIC / WHAT A GOOD ANSWER INCLUDES:\n{$rubric}\n\n";
		}
		$user_msg .= "STUDENT'S ANSWER:\n{$answer_str}\n\n";
		$user_msg .= "CONTEXT: {$profile_ctx}\n\n";
		$user_msg .= 'Respond ONLY with a JSON object: {"correct": true|false, "score": <0-100>, "feedback": "<under 70 words, friendly, specific, actionable>"}. '
			. 'Mark "correct" true when the score is ' . self::PASS_SCORE . ' or above.';

		$res = Mahan_AI::complete(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			array(
				'json'        => true,
				'max_tokens'  => 400,
				'temperature' => 0.3,
			)
		);

		if ( ! $res['ok'] ) {
			// Don't punish the learner for an API failure.
			return array(
				'is_correct' => true,
				'score'      => 70,
				'feedback'   => __( 'Answer recorded. (Automated feedback is temporarily unavailable.)', 'mahan-academy' ),
			);
		}

		$data = Mahan_Utils::extract_json( $res['text'] );
		if ( ! is_array( $data ) ) {
			return array(
				'is_correct' => true,
				'score'      => 70,
				'feedback'   => trim( wp_strip_all_tags( $res['text'] ) ) ?: __( 'Answer recorded.', 'mahan-academy' ),
			);
		}

		$score = isset( $data['score'] ) ? max( 0, min( 100, (int) $data['score'] ) ) : 0;
		$is_ok = isset( $data['correct'] ) ? (bool) $data['correct'] : ( $score >= self::PASS_SCORE );
		return array(
			'is_correct' => $is_ok,
			'score'      => $score,
			'feedback'   => isset( $data['feedback'] ) ? sanitize_textarea_field( (string) $data['feedback'] ) : '',
		);
	}

	private static function pick( $ex, $key, $default ) {
		return isset( $ex[ $key ] ) && '' !== trim( (string) $ex[ $key ] ) ? (string) $ex[ $key ] : $default;
	}

	private static function has_correct_attempt( $user_id, $lesson_id, $key ) {
		global $wpdb;
		$table = Mahan_DB::attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND lesson_id = %d AND exercise_key = %s AND is_correct = 1 LIMIT 1",
				(int) $user_id,
				(int) $lesson_id,
				(string) $key
			)
		);
		return ! empty( $found );
	}

	private static function store_attempt( $user_id, $lesson_id, $course_id, $key, $type, $answer, $graded, $xp_award ) {
		global $wpdb;
		$answer_str = is_array( $answer ) ? wp_json_encode( $answer ) : (string) $answer;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Mahan_DB::attempts(),
			array(
				'user_id'      => (int) $user_id,
				'lesson_id'    => (int) $lesson_id,
				'course_id'    => (int) $course_id,
				'exercise_key' => (string) $key,
				'type'         => (string) $type,
				'user_answer'  => $answer_str,
				'is_correct'   => $graded['is_correct'] ? 1 : 0,
				'score'        => (int) $graded['score'],
				'xp_awarded'   => (int) $xp_award,
				'feedback'     => (string) $graded['feedback'],
				'created_at'   => Mahan_Utils::now_mysql(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Best (correct, else latest) attempt per exercise for a lesson — so the UI
	 * can show already-solved exercises.
	 *
	 * @param int $user_id   User id.
	 * @param int $lesson_id Lesson id.
	 * @return array key => [ is_correct, score ]
	 */
	public static function lesson_attempt_map( $user_id, $lesson_id ) {
		global $wpdb;
		$table = Mahan_DB::attempts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exercise_key, MAX(is_correct) AS is_correct, MAX(score) AS score
				 FROM {$table} WHERE user_id = %d AND lesson_id = %d GROUP BY exercise_key",
				(int) $user_id,
				(int) $lesson_id
			),
			ARRAY_A
		);
		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (string) $r['exercise_key'] ] = array(
				'is_correct' => (int) $r['is_correct'] ? true : false,
				'score'      => (int) $r['score'],
			);
		}
		return $map;
	}
}
