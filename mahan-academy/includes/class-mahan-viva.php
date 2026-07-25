<?php
/**
 * Live AI oral exam ("viva") — a multi-turn, graded conversation with the
 * examiner at the end of a unit.
 *
 * A quiz asks the learner to recognise the right answer. A viva asks them to
 * say it, in their own words, and then pushes on the part they were vague
 * about. That is a different — and much harder to fake — signal, and it is
 * the one thing an LLM can assess that a static answer key cannot.
 *
 * The sitting is staged: explain → apply → judge. Each stage is one question,
 * answered in prose, graded 0–100 by the AI against a rubric the AI itself
 * wrote when it set the question. Pass the stage and the next one opens;
 * miss it and you get one focused follow-up before it counts as a failed
 * attempt.
 *
 * Three properties matter more than the prompt wording:
 *
 * 1. **The score is the server's.** The AI returns a number and an advisory
 *    verdict; PHP decides pass/fail against {@see PASS_SCORE}. The browser
 *    never sees the rubric and never submits a score, so there is nothing to
 *    forge. A `probe` is the only verdict the model actually controls, and
 *    even that is bounded by {@see MAX_TURNS}.
 * 2. **It is bounded.** Stages, attempts, and turns are all capped, and each
 *    answer is length-clamped, so a live AI session cannot turn into a
 *    runaway bill.
 * 3. **It is resumable.** The sitting lives in a row, not a transient, so
 *    closing the tab mid-exam loses nothing.
 *
 * Personalization is the point of the middle stage: the "apply" question is
 * generated from {@see Mahan_Personalization::learner_context()}, so two
 * learners finishing the same unit are examined on the same concept inside
 * their own jobs.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Viva {

	/** Score (0–100) a stage answer must reach to pass. */
	const PASS_SCORE = 70;

	/** Below this, a partly-right answer isn't worth a follow-up. */
	const PROBE_FLOOR = 40;

	/** Turns allowed per stage: the question, plus at most one probe. */
	const MAX_TURNS = 2;

	/** Failed attempts allowed per stage before the sitting ends. */
	const MAX_ATTEMPTS = 2;

	/** Hard clamp on a submitted answer (characters). */
	const ANSWER_MAX = 2000;

	/** Characters of unit material handed to the examiner. */
	const MATERIAL_MAX = 4500;

	/** XP for passing a unit's viva (first pass only). */
	const XP_AWARD = 60;

	/** Abandon an untouched sitting after this many seconds. */
	const STALE_AFTER = 86400;

	/* ------------------------------------------------------------------ */
	/* Stage definitions                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The three stages, in order. `brief` is the instruction handed to the
	 * question-writer; `label`/`blurb` are learner-facing.
	 *
	 * Kept as a method rather than a const so the labels are translatable.
	 *
	 * @return array[] { key, label, blurb, brief }
	 */
	public static function stages() {
		return array(
			array(
				'key'   => 'explain',
				'label' => __( 'Explain', 'mahan-academy' ),
				'blurb' => __( 'Say the core idea in your own words.', 'mahan-academy' ),
				'brief' => 'EXPLAIN. Ask the learner to explain one central idea from this unit in their own words, '
					. 'including *why* it works, not just what it is. A good answer cannot be produced by repeating the '
					. 'lesson\'s phrasing back.',
			),
			array(
				'key'   => 'apply',
				'label' => __( 'Apply', 'mahan-academy' ),
				'blurb' => __( 'Use it on a situation from your own work.', 'mahan-academy' ),
				'brief' => 'APPLY. Put the learner inside a concrete, realistic situation drawn from THEIR OWN role, tools '
					. 'and stated goal (see the learner context), and ask what they would actually do and why. Name their '
					. 'role or a tool they use in the scenario. This question must be impossible to answer well without '
					. 'both understanding the unit and thinking about their own work.',
			),
			array(
				'key'   => 'judge',
				'label' => __( 'Judge', 'mahan-academy' ),
				'blurb' => __( 'Weigh a trade-off, a limit, or a risk.', 'mahan-academy' ),
				'brief' => 'JUDGE. Ask for judgement: a trade-off between two defensible options, a limit of the technique, '
					. 'a failure mode, or when NOT to use it. Reward reasoning about the boundary, not enthusiasm.',
			),
		);
	}

	public static function stage_count() {
		return count( self::stages() );
	}

	/**
	 * Stage definition by 1-based index, or null.
	 */
	public static function stage( $n ) {
		$all = self::stages();
		$n   = (int) $n;
		return isset( $all[ $n - 1 ] ) ? $all[ $n - 1 ] : null;
	}

	/**
	 * Learner-facing configuration (no rubrics, no prompts).
	 *
	 * @return array
	 */
	public static function config() {
		$stages = array();
		foreach ( self::stages() as $i => $s ) {
			$stages[] = array(
				'n'     => $i + 1,
				'key'   => $s['key'],
				'label' => $s['label'],
				'blurb' => $s['blurb'],
			);
		}
		return array(
			'available'    => self::available(),
			'stages'       => $stages,
			'pass_score'   => self::PASS_SCORE,
			'max_attempts' => self::MAX_ATTEMPTS,
			'answer_max'   => self::ANSWER_MAX,
			'xp'           => self::XP_AWARD,
		);
	}

	/**
	 * The feature only exists when there's a provider to talk to. Same rule as
	 * the tutor: no key, no half-working UI.
	 */
	public static function available() {
		return class_exists( 'Mahan_Settings' ) && Mahan_Settings::ai_ready();
	}

	/* ------------------------------------------------------------------ */
	/* Eligibility                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Lessons belonging to a unit of a course, in order.
	 *
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @return WP_Post[]
	 */
	public static function unit_lessons( $course_id, $unit ) {
		$unit = (string) $unit;
		foreach ( Mahan_Courses::get_course_units( (int) $course_id ) as $u ) {
			if ( (string) $u['title'] === $unit ) {
				return $u['lessons'];
			}
		}
		return array();
	}

	/**
	 * A viva opens once every lesson in its unit is complete — it examines the
	 * unit, so there has to be a unit behind it.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @return bool
	 */
	public static function unit_ready( $user_id, $course_id, $unit ) {
		$lessons = self::unit_lessons( $course_id, $unit );
		if ( empty( $lessons ) ) {
			return false;
		}
		$status = Mahan_Progress::course_lesson_status( (int) $user_id, (int) $course_id );
		foreach ( $lessons as $lesson ) {
			$s = isset( $status[ (int) $lesson->ID ] ) ? $status[ (int) $lesson->ID ] : 'not_started';
			if ( 'completed' !== $s ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Session rows                                                        */
	/* ------------------------------------------------------------------ */

	private static function row( $id ) {
		global $wpdb;
		$table = Mahan_DB::viva();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	private static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Mahan_DB::viva(), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * The learner's live sitting for a unit, if any. A sitting nobody has
	 * touched for a day is retired rather than resumed — coming back a week
	 * later to a half-remembered question helps no one.
	 *
	 * @return array|null Raw row.
	 */
	private static function active_row( $user_id, $course_id, $unit ) {
		global $wpdb;
		$table = Mahan_DB::viva();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d AND unit = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
				(int) $user_id,
				(int) $course_id,
				(string) $unit
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$touched = strtotime( (string) $row['updated_at'] );
		if ( $touched && ( time() - $touched ) > self::STALE_AFTER ) {
			self::update( (int) $row['id'], array( 'status' => 'abandoned' ) );
			return null;
		}
		return $row;
	}

	/**
	 * Has this learner already passed this unit's viva?
	 */
	private static function passed_before( $user_id, $course_id, $unit ) {
		global $wpdb;
		$table = Mahan_DB::viva();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND course_id = %d AND unit = %s AND status = 'passed'",
				(int) $user_id,
				(int) $course_id,
				(string) $unit
			)
		) > 0;
	}

	/**
	 * Per-unit summary for the course page: is it open, has it been passed,
	 * is a sitting already in progress.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @return array
	 */
	public static function unit_state( $user_id, $course_id, $unit ) {
		$user_id = (int) $user_id;
		$out     = array(
			'available' => self::available(),
			'unlocked'  => false,
			'status'    => 'none',
			'percent'   => null,
			'stages'    => self::stage_count(),
		);
		if ( ! $user_id || ! $out['available'] ) {
			return $out;
		}
		$out['unlocked'] = self::unit_ready( $user_id, $course_id, $unit );

		$best = self::best( $user_id, $course_id, $unit );
		if ( $best ) {
			$out['status']  = 'passed';
			$out['percent'] = (int) $best['percent'];
		}
		if ( self::active_row( $user_id, $course_id, $unit ) ) {
			$out['status'] = 'passed' === $out['status'] ? 'passed' : 'active';
			$out['resume'] = true;
		}
		return $out;
	}

	/**
	 * The learner's best passing sitting for a unit.
	 *
	 * @return array|null { percent, completed_at }
	 */
	public static function best( $user_id, $course_id, $unit ) {
		global $wpdb;
		$table = Mahan_DB::viva();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT score, max_score, completed_at FROM {$table}
				 WHERE user_id = %d AND course_id = %d AND unit = %s AND status = 'passed'
				 ORDER BY score DESC, id DESC LIMIT 1",
				(int) $user_id,
				(int) $course_id,
				(string) $unit
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$max = max( 1, (int) $row['max_score'] );
		return array(
			'percent'      => (int) round( ( (int) $row['score'] ) / $max * 100 ),
			'completed_at' => (string) $row['completed_at'],
		);
	}

	/**
	 * Count of units in a course whose viva this learner has passed.
	 */
	public static function passed_count( $user_id, $course_id ) {
		global $wpdb;
		$table = Mahan_DB::viva();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT unit ) FROM {$table} WHERE user_id = %d AND course_id = %d AND status = 'passed'",
				(int) $user_id,
				(int) $course_id
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Start                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Open (or resume) a sitting.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @return array { ok, session } or { ok:false, error }
	 */
	public static function start( $user_id, $course_id, $unit ) {
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		$unit      = sanitize_text_field( (string) $unit );

		if ( ! $user_id ) {
			return array( 'ok' => false, 'error' => 'not_logged_in' );
		}
		if ( ! self::available() ) {
			return array( 'ok' => false, 'error' => 'ai_unavailable' );
		}
		if ( 'publish' !== get_post_status( $course_id ) ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( ! Mahan_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return array( 'ok' => false, 'error' => 'not_enrolled' );
		}
		$lessons = self::unit_lessons( $course_id, $unit );
		if ( empty( $lessons ) ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( ! self::unit_ready( $user_id, $course_id, $unit ) ) {
			return array( 'ok' => false, 'error' => 'locked' );
		}

		$existing = self::active_row( $user_id, $course_id, $unit );
		if ( $existing ) {
			return array( 'ok' => true, 'session' => self::public_session( $existing ), 'resumed' => true );
		}

		global $wpdb;
		$now = Mahan_Utils::now_mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Mahan_DB::viva(),
			array(
				'user_id'    => $user_id,
				'course_id'  => $course_id,
				'unit'       => $unit,
				'stage'      => 1,
				'turn'       => 1,
				'attempt'    => 1,
				'score'      => 0,
				'max_score'  => 100 * self::stage_count(),
				'status'     => 'active',
				'pending'    => '',
				'transcript' => wp_json_encode( array() ),
				'started_at' => $now,
				'updated_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return array( 'ok' => false, 'error' => 'db_error' );
		}

		$question = self::ask_question( $user_id, $course_id, $unit, 1, array() );
		if ( ! $question ) {
			// Don't leave a ghost sitting behind a failed first call.
			self::update( $id, array( 'status' => 'abandoned' ) );
			return array( 'ok' => false, 'error' => 'generation_failed' );
		}

		$transcript = array(
			array(
				'role'  => 'examiner',
				'stage' => 1,
				'text'  => $question['question'],
			),
		);
		self::update(
			$id,
			array(
				'pending'    => wp_json_encode( $question ),
				'transcript' => wp_json_encode( $transcript ),
			)
		);

		return array( 'ok' => true, 'session' => self::public_session( self::row( $id ) ) );
	}

	/**
	 * Retire a sitting the learner walked away from.
	 */
	public static function abandon( $user_id, $session_id ) {
		$row = self::row( $session_id );
		if ( ! $row || (int) $row['user_id'] !== (int) $user_id ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( 'active' === (string) $row['status'] ) {
			self::update( (int) $row['id'], array( 'status' => 'abandoned' ) );
		}
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ */
	/* Answer                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Submit one answer and advance the sitting.
	 *
	 * @param int    $user_id    User id.
	 * @param int    $session_id Session row id.
	 * @param string $text       The learner's prose answer.
	 * @return array
	 */
	public static function answer( $user_id, $session_id, $text ) {
		$user_id = (int) $user_id;
		$row     = self::row( $session_id );

		if ( ! $row || (int) $row['user_id'] !== $user_id ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		if ( 'active' !== (string) $row['status'] ) {
			return array( 'ok' => false, 'error' => 'finished' );
		}
		$pending = self::decode( $row['pending'] );
		if ( empty( $pending['question'] ) ) {
			return array( 'ok' => false, 'error' => 'no_question' );
		}

		$answer = sanitize_textarea_field( (string) $text );
		$answer = trim( $answer );
		if ( '' === $answer ) {
			return array( 'ok' => false, 'error' => 'empty_answer' );
		}
		if ( mb_strlen( $answer ) > self::ANSWER_MAX ) {
			$answer = mb_substr( $answer, 0, self::ANSWER_MAX );
		}

		$stage_n    = (int) $row['stage'];
		$turn       = (int) $row['turn'];
		$transcript = self::decode( $row['transcript'] );
		if ( ! is_array( $transcript ) ) {
			$transcript = array();
		}
		$transcript[] = array(
			'role'  => 'learner',
			'stage' => $stage_n,
			'text'  => $answer,
		);

		$grade = self::grade_answer( $user_id, $row, $pending, $answer, $transcript );
		if ( ! $grade ) {
			// The answer is already in the transcript; persisting it means a
			// retry re-grades rather than losing what they typed.
			self::update( (int) $row['id'], array( 'transcript' => wp_json_encode( $transcript ) ) );
			return array( 'ok' => false, 'error' => 'grading_failed' );
		}

		// --- The decision is PHP's, not the model's. -------------------
		$score  = (int) $grade['score'];
		$passed = $score >= self::PASS_SCORE;
		$probe  = ( ! $passed
			&& $turn < self::MAX_TURNS
			&& 'probe' === $grade['verdict']
			&& $score >= self::PROBE_FLOOR
			&& '' !== $grade['follow_up'] );

		$transcript[] = array(
			'role'      => 'grade',
			'stage'     => $stage_n,
			'score'     => $score,
			'passed'    => $passed,
			'probe'     => $probe,
			'feedback'  => $grade['feedback'],
			'strengths' => $grade['strengths'],
			'gaps'      => $grade['gaps'],
		);

		$fields = array( 'transcript' => null ); // filled in at the end.
		$result = array(
			'ok'        => true,
			'score'     => $score,
			'passed'    => $passed,
			'probe'     => $probe,
			'feedback'  => $grade['feedback'],
			'strengths' => $grade['strengths'],
			'gaps'      => $grade['gaps'],
			'stage'     => $stage_n,
		);

		if ( $probe ) {
			// Same stage, one more turn: a focused follow-up on the gap.
			$transcript[] = array(
				'role'  => 'examiner',
				'stage' => $stage_n,
				'text'  => $grade['follow_up'],
				'probe' => true,
			);
			$pending['question'] = $grade['follow_up'];
			$pending['probe']    = true;
			$fields['pending']   = wp_json_encode( $pending );
			$fields['turn']      = $turn + 1;
			$result['outcome']   = 'probe';
		} elseif ( $passed ) {
			$fields['score'] = (int) $row['score'] + $score;

			if ( $stage_n >= self::stage_count() ) {
				// Whole sitting cleared.
				$fields['status']       = 'passed';
				$fields['completed_at'] = Mahan_Utils::now_mysql();
				$fields['pending']      = '';
				$result['outcome']      = 'passed';
				$result                += self::finish_reward( $user_id, $row );
			} else {
				$next     = $stage_n + 1;
				$question = self::ask_question( $user_id, (int) $row['course_id'], (string) $row['unit'], $next, $transcript );
				if ( ! $question ) {
					self::update( (int) $row['id'], array( 'transcript' => wp_json_encode( $transcript ), 'score' => (int) $fields['score'] ) );
					return array( 'ok' => false, 'error' => 'generation_failed' );
				}
				$transcript[] = array(
					'role'  => 'examiner',
					'stage' => $next,
					'text'  => $question['question'],
				);
				$fields['pending'] = wp_json_encode( $question );
				$fields['stage']   = $next;
				$fields['turn']    = 1;
				$fields['attempt'] = 1;
				$result['outcome'] = 'stage_passed';
			}
		} else {
			// Stage missed. One more attempt gets a fresh question on the same
			// stage; running out ends the sitting.
			$attempt = (int) $row['attempt'];
			if ( $attempt >= self::MAX_ATTEMPTS ) {
				$fields['status']       = 'failed';
				$fields['completed_at'] = Mahan_Utils::now_mysql();
				$fields['pending']      = '';
				$result['outcome']      = 'failed';
			} else {
				$question = self::ask_question( $user_id, (int) $row['course_id'], (string) $row['unit'], $stage_n, $transcript );
				if ( ! $question ) {
					self::update( (int) $row['id'], array( 'transcript' => wp_json_encode( $transcript ) ) );
					return array( 'ok' => false, 'error' => 'generation_failed' );
				}
				$transcript[] = array(
					'role'  => 'examiner',
					'stage' => $stage_n,
					'text'  => $question['question'],
					'retry' => true,
				);
				$fields['pending']    = wp_json_encode( $question );
				$fields['turn']       = 1;
				$fields['attempt']    = $attempt + 1;
				$result['outcome']    = 'retry';
				$result['attempt']    = $attempt + 1;
				$result['attempts_left'] = self::MAX_ATTEMPTS - $attempt;
			}
		}

		$fields['transcript'] = wp_json_encode( $transcript );
		self::update( (int) $row['id'], $fields );

		$result['session'] = self::public_session( self::row( (int) $row['id'] ) );
		return $result;
	}

	/**
	 * XP + celebration payload for a cleared sitting. XP is paid once per unit,
	 * ever — retaking for a better score is welcome, farming it is not.
	 */
	private static function finish_reward( $user_id, $row ) {
		$out = array(
			'xp_awarded' => 0,
			'leveled_up' => false,
		);
		if ( self::passed_before( $user_id, (int) $row['course_id'], (string) $row['unit'] ) ) {
			return $out;
		}
		Mahan_Gamification::record_activity( $user_id );
		$award = Mahan_Gamification::add_xp( $user_id, self::XP_AWARD, 'viva', (int) $row['course_id'] );

		$out['xp_awarded'] = (int) $award['awarded'];
		$out['leveled_up'] = ! empty( $award['leveled_up'] );
		$out['new_badges'] = Mahan_Badges::take_new( $user_id );
		$out['stats']      = Mahan_Gamification::hud( $user_id );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* AI: setting the question                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Ask the examiner for the next question.
	 *
	 * Returns the question *and* the rubric it was written against. The rubric
	 * never leaves the server — it is what the grader is held to, and handing
	 * it to the browser would hand over the answer.
	 *
	 * @param int    $user_id    User id.
	 * @param int    $course_id  Course id.
	 * @param string $unit       Unit title.
	 * @param int    $stage_n    1-based stage.
	 * @param array  $transcript Conversation so far (to avoid repeats).
	 * @return array|null { question, looking_for, stage }
	 */
	private static function ask_question( $user_id, $course_id, $unit, $stage_n, $transcript ) {
		$stage = self::stage( $stage_n );
		if ( ! $stage ) {
			return null;
		}

		$system = 'You are a fair but demanding examiner running a short LIVE oral exam (a viva) at the end of a unit '
			. 'in an academy that teaches people to use AI in their daily work. '
			. 'Ask exactly ONE question. It must be answerable in 3–8 sentences of ordinary prose — never multiple choice, '
			. 'never a numbered list of sub-questions, never "explain X and also Y and also Z". '
			. 'It must be answerable from the unit material by someone who understood it, and unanswerable by someone who '
			. 'only skimmed it. Do not greet, do not preamble, do not number the question. '
			. 'Write in the same language as the unit material.';

		if ( class_exists( 'Mahan_Personalization' ) ) {
			$ctx = Mahan_Personalization::learner_context( $user_id );
			if ( '' !== $ctx ) {
				$system .= "\n\n" . $ctx . "\n" . Mahan_Personalization::difficulty_directive( $user_id );
			}
		}

		$material = self::unit_material( $course_id, $unit );
		$asked    = self::asked_questions( $transcript );
		$asked_ln = ! empty( $asked )
			? "\n\nALREADY ASKED IN THIS SITTING — ask about something else:\n- " . implode( "\n- ", $asked )
			: '';

		$user_msg = 'COURSE: ' . get_the_title( $course_id ) . "\nUNIT: {$unit}\n\n"
			. "UNIT MATERIAL:\n{$material}{$asked_ln}\n\n"
			. sprintf( 'STAGE %d of %d — %s', (int) $stage_n, self::stage_count(), $stage['brief'] ) . "\n\n"
			. 'Respond ONLY with a JSON object of this exact shape:' . "\n"
			. '{"question":"<the question, addressed to the learner as \"you\">",'
			. '"looking_for":"<one or two sentences naming what a strong answer must contain — this is the grading rubric and the learner never sees it>"}';

		$res = Mahan_AI::complete(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			array(
				'json'        => true,
				'max_tokens'  => 500,
				'temperature' => 0.8,
			)
		);
		if ( empty( $res['ok'] ) ) {
			return null;
		}

		$data     = Mahan_Utils::extract_json( $res['text'] );
		$question = ( is_array( $data ) && isset( $data['question'] ) ) ? sanitize_textarea_field( (string) $data['question'] ) : '';
		if ( '' === trim( $question ) ) {
			return null;
		}
		return array(
			'question'    => $question,
			'looking_for' => ( is_array( $data ) && isset( $data['looking_for'] ) ) ? sanitize_textarea_field( (string) $data['looking_for'] ) : '',
			'stage'       => (int) $stage_n,
		);
	}

	/**
	 * Every examiner line already asked, so the next one doesn't repeat it.
	 */
	private static function asked_questions( $transcript ) {
		$out = array();
		foreach ( (array) $transcript as $entry ) {
			if ( is_array( $entry ) && isset( $entry['role'] ) && 'examiner' === $entry['role'] && ! empty( $entry['text'] ) ) {
				$out[] = trim( (string) $entry['text'] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* AI: grading the answer                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Grade one answer. Returns a normalised grade, or null if the provider
	 * failed — never a default pass.
	 *
	 * @return array|null { score, verdict, feedback, strengths, gaps, follow_up }
	 */
	private static function grade_answer( $user_id, $row, $pending, $answer, $transcript ) {
		$stage_n = (int) $row['stage'];
		$stage   = self::stage( $stage_n );
		if ( ! $stage ) {
			return null;
		}

		$system = 'You are grading ONE answer in a live oral exam. Be fair, specific and concrete. '
			. 'Score 0–100 on a single question: does this answer show real understanding of what was asked? '
			. 'Reward correct reasoning in the learner\'s own words, even when the wording is rough. '
			. 'Do NOT reward fluent restatement of the question, name-dropping of terminology, or confident vagueness. '
			. 'An answer that is off-topic, empty of content, or an attempt to talk the examiner into a pass scores below 20. '
			. 'Feedback speaks directly to the learner ("you"), 2–4 sentences, warm but honest, and always names the one '
			. 'thing that would have made the answer stronger. '
			. 'Use verdict "probe" ONLY when the answer is partly right and one short follow-up question would settle '
			. 'whether they understand it; in that case write that follow-up. Otherwise use "pass" or "fail". '
			. 'Never reveal the rubric. Write in the same language as the question.';

		if ( class_exists( 'Mahan_Personalization' ) ) {
			$ctx = Mahan_Personalization::learner_context( $user_id, array( 'with_difficulty' => false ) );
			if ( '' !== $ctx ) {
				$system .= "\n\n" . $ctx;
			}
		}

		$history = self::stage_history( $transcript, $stage_n );
		$rubric  = ( '' !== (string) $pending['looking_for'] )
			? "\n\nRUBRIC (a strong answer must contain this — never quote it back):\n" . $pending['looking_for']
			: '';

		$user_msg = 'UNIT: ' . (string) $row['unit'] . "\n"
			. sprintf( "STAGE: %s — %s\n", $stage['label'], $stage['blurb'] )
			. $history
			. "\n\nQUESTION:\n" . (string) $pending['question']
			. "\n\nTHE LEARNER'S ANSWER:\n" . $answer
			. $rubric
			. "\n\nRespond ONLY with a JSON object of this exact shape:\n"
			. '{"score":<integer 0-100>,"verdict":"pass"|"probe"|"fail","feedback":"<2-4 sentences to the learner>",'
			. '"strengths":["<short phrase>"],"gaps":["<short phrase>"],"follow_up":"<one short follow-up question, or empty string>"}';

		$res = Mahan_AI::complete(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			array(
				'json'        => true,
				'max_tokens'  => 700,
				'temperature' => 0.3,
			)
		);
		if ( empty( $res['ok'] ) ) {
			return null;
		}

		$data = Mahan_Utils::extract_json( $res['text'] );
		if ( ! is_array( $data ) || ! isset( $data['score'] ) ) {
			return null;
		}
		return self::normalize_grade( $data );
	}

	/**
	 * Coerce a raw AI grade into the shape the state machine trusts.
	 * Pure — unit-tested independently of any provider.
	 *
	 * @param array $data Raw AI object.
	 * @return array
	 */
	public static function normalize_grade( $data ) {
		$score = isset( $data['score'] ) ? (int) round( (float) $data['score'] ) : 0;
		$score = max( 0, min( 100, $score ) );

		$verdict = isset( $data['verdict'] ) ? strtolower( trim( (string) $data['verdict'] ) ) : '';
		if ( ! in_array( $verdict, array( 'pass', 'probe', 'fail' ), true ) ) {
			$verdict = '';
		}

		$phrases = function ( $list ) {
			$out = array();
			foreach ( (array) $list as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}
				$s = trim( sanitize_text_field( (string) $item ) );
				if ( '' !== $s ) {
					$out[] = $s;
				}
				if ( count( $out ) >= 3 ) {
					break;
				}
			}
			return $out;
		};

		return array(
			'score'     => $score,
			'verdict'   => $verdict,
			'feedback'  => isset( $data['feedback'] ) ? trim( sanitize_textarea_field( (string) $data['feedback'] ) ) : '',
			'strengths' => $phrases( isset( $data['strengths'] ) ? $data['strengths'] : array() ),
			'gaps'      => $phrases( isset( $data['gaps'] ) ? $data['gaps'] : array() ),
			'follow_up' => isset( $data['follow_up'] ) ? trim( sanitize_textarea_field( (string) $data['follow_up'] ) ) : '',
		);
	}

	/**
	 * The current stage's exchange so far, so a probe is graded in context
	 * rather than in isolation.
	 */
	private static function stage_history( $transcript, $stage_n ) {
		$lines = array();
		foreach ( (array) $transcript as $entry ) {
			if ( ! is_array( $entry ) || (int) ( isset( $entry['stage'] ) ? $entry['stage'] : 0 ) !== (int) $stage_n ) {
				continue;
			}
			$role = isset( $entry['role'] ) ? $entry['role'] : '';
			if ( 'examiner' === $role ) {
				$lines[] = 'EXAMINER: ' . (string) $entry['text'];
			} elseif ( 'learner' === $role ) {
				$lines[] = 'LEARNER: ' . (string) $entry['text'];
			}
		}
		// The last two lines are the question being graded and the answer being
		// graded — they're passed separately, so drop them here.
		$lines = array_slice( $lines, 0, max( 0, count( $lines ) - 2 ) );
		if ( empty( $lines ) ) {
			return '';
		}
		return "\n\nEARLIER IN THIS STAGE:\n" . implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------ */
	/* Unit material                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * A compact digest of everything the unit taught: lesson titles, their
	 * concepts, and trimmed bodies, budgeted evenly so the last lesson isn't
	 * silently cut off.
	 *
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @return string
	 */
	public static function unit_material( $course_id, $unit ) {
		$lessons = self::unit_lessons( $course_id, $unit );
		if ( empty( $lessons ) ) {
			return '';
		}
		$budget = (int) floor( self::MATERIAL_MAX / max( 1, count( $lessons ) ) );
		$parts  = array();
		foreach ( $lessons as $lesson ) {
			$topics = Mahan_Courses::lesson_topics( (int) $lesson->ID );
			$plain  = wp_strip_all_tags( (string) $lesson->post_content );
			$plain  = trim( preg_replace( '/\s+/', ' ', $plain ) );
			if ( mb_strlen( $plain ) > $budget ) {
				$plain = mb_substr( $plain, 0, $budget ) . '…';
			}
			$parts[] = '## ' . get_the_title( (int) $lesson->ID )
				. ( ! empty( $topics ) ? "\nConcepts: " . implode( ', ', $topics ) : '' )
				. "\n" . $plain;
		}
		return implode( "\n\n", $parts );
	}

	/* ------------------------------------------------------------------ */
	/* Serialisation                                                       */
	/* ------------------------------------------------------------------ */

	private static function decode( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$out = json_decode( $raw, true );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * The browser's view of a sitting: the conversation and where it stands,
	 * and nothing that would let it grade itself.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function public_session( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		$pending    = self::decode( $row['pending'] );
		$transcript = self::decode( $row['transcript'] );
		$stage_n    = (int) $row['stage'];
		$stage      = self::stage( $stage_n );
		$max        = max( 1, (int) $row['max_score'] );

		$clean = array();
		foreach ( $transcript as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['role'] ) ) {
				continue;
			}
			$item = array(
				'role'  => (string) $entry['role'],
				'stage' => isset( $entry['stage'] ) ? (int) $entry['stage'] : 0,
			);
			if ( 'grade' === $entry['role'] ) {
				$item['score']     = isset( $entry['score'] ) ? (int) $entry['score'] : 0;
				$item['passed']    = ! empty( $entry['passed'] );
				$item['probe']     = ! empty( $entry['probe'] );
				$item['feedback']  = isset( $entry['feedback'] ) ? (string) $entry['feedback'] : '';
				$item['strengths'] = isset( $entry['strengths'] ) ? array_values( (array) $entry['strengths'] ) : array();
				$item['gaps']      = isset( $entry['gaps'] ) ? array_values( (array) $entry['gaps'] ) : array();
			} else {
				$item['text']  = isset( $entry['text'] ) ? (string) $entry['text'] : '';
				$item['probe'] = ! empty( $entry['probe'] );
				$item['retry'] = ! empty( $entry['retry'] );
			}
			$clean[] = $item;
		}

		return array(
			'id'            => (int) $row['id'],
			'course_id'     => (int) $row['course_id'],
			'unit'          => (string) $row['unit'],
			'status'        => (string) $row['status'],
			'stage'         => $stage_n,
			'stage_count'   => self::stage_count(),
			'stage_label'   => $stage ? $stage['label'] : '',
			'stage_blurb'   => $stage ? $stage['blurb'] : '',
			'turn'          => (int) $row['turn'],
			'attempt'       => (int) $row['attempt'],
			'max_attempts'  => self::MAX_ATTEMPTS,
			'question'      => isset( $pending['question'] ) ? (string) $pending['question'] : '',
			'transcript'    => $clean,
			'score'         => (int) $row['score'],
			'max_score'     => (int) $row['max_score'],
			'percent'       => (int) round( ( (int) $row['score'] ) / $max * 100 ),
			'stages'        => self::config()['stages'],
			'pass_score'    => self::PASS_SCORE,
			'answer_max'    => self::ANSWER_MAX,
		);
	}
}
