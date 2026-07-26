<?php
/**
 * End-of-unit quizzes.
 *
 * A quiz is attached to a course unit (keyed by the unit's title) and stored in
 * the course meta `_mahan_unit_quizzes`. Questions reuse the deterministic
 * exercise types (multiple choice, true/false, fill-in-the-blank) so a quiz can
 * be graded instantly server-side. A quiz has a passing score and an XP reward
 * granted the first time a learner passes.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Quizzes {

	const TYPES        = array( 'multiple_choice', 'true_false', 'fill_blank', 'multi_select' );
	const DEFAULT_PASS = 70;

	/**
	 * Fill-in-the-blank answers at least this long tolerate one typo.
	 *
	 * Short answers ("0", "RAG", "true") must stay exact — at three characters
	 * a single edit reaches a genuinely different answer. Longer ones are being
	 * marked wrong for a slipped key, which measures typing, not knowledge.
	 */
	const TYPO_MIN_LEN = 6;

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * All quizzes for a course, keyed by unit title.
	 *
	 * @param int $course_id Course id.
	 * @return array
	 */
	public static function all( $course_id ) {
		$raw = get_post_meta( (int) $course_id, Mahan_Courses::M_UNIT_QUIZZES, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * A single unit quiz definition (with answers), or null.
	 *
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @return array|null
	 */
	public static function get( $course_id, $unit ) {
		$all = self::all( $course_id );
		$q   = isset( $all[ $unit ] ) ? $all[ $unit ] : null;
		if ( ! is_array( $q ) || empty( $q['questions'] ) ) {
			return null;
		}
		return $q;
	}

	/**
	 * Replace the whole quizzes map for a course (used by the Course Builder).
	 *
	 * @param int   $course_id Course id.
	 * @param array $map       unit title => quiz definition.
	 */
	public static function save_all( $course_id, array $map ) {
		$clean = array();
		foreach ( $map as $unit => $def ) {
			$unit = sanitize_text_field( (string) $unit );
			if ( '' === $unit || ! is_array( $def ) ) {
				continue;
			}
			$q = self::sanitize( $def );
			if ( ! empty( $q['questions'] ) ) {
				$clean[ $unit ] = $q;
			}
		}
		if ( empty( $clean ) ) {
			delete_post_meta( (int) $course_id, Mahan_Courses::M_UNIT_QUIZZES );
		} else {
			update_post_meta( (int) $course_id, Mahan_Courses::M_UNIT_QUIZZES, $clean );
		}
	}

	/**
	 * Sanitize a single quiz definition.
	 *
	 * @param array $def Raw definition.
	 * @return array
	 */
	public static function sanitize( $def ) {
		$out = array(
			'title'    => isset( $def['title'] ) ? sanitize_text_field( (string) $def['title'] ) : '',
			'passing'  => isset( $def['passing'] ) ? max( 0, min( 100, (int) $def['passing'] ) ) : self::DEFAULT_PASS,
			'xp'       => isset( $def['xp'] ) ? absint( $def['xp'] ) : 0,
			'questions' => array(),
		);
		$questions = isset( $def['questions'] ) && is_array( $def['questions'] ) ? $def['questions'] : array();
		$i = 0;
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$type = isset( $q['type'] ) ? sanitize_key( (string) $q['type'] ) : 'multiple_choice';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$type = 'multiple_choice';
			}
			$i++;
			$row = array(
				'key'      => isset( $q['key'] ) && '' !== trim( (string) $q['key'] ) ? sanitize_key( $q['key'] ) : 'q' . $i,
				'type'     => $type,
				'question' => isset( $q['question'] ) ? sanitize_textarea_field( (string) $q['question'] ) : '',
				// Why the answer is the answer. Never served before grading;
				// shown once the attempt is in, which is the moment a learner
				// is actually receptive to it.
				'explain'  => isset( $q['explain'] ) ? sanitize_textarea_field( (string) $q['explain'] ) : '',
			);
			// Escape hatch for the rare option set whose order carries meaning
			// ("all of the above"). Everything else is permuted at serve time.
			if ( ! empty( $q['no_shuffle'] ) ) {
				$row['no_shuffle'] = 1;
			}
			if ( 'multiple_choice' === $type ) {
				$opts = isset( $q['options'] ) && is_array( $q['options'] ) ? array_values( array_map( 'sanitize_text_field', $q['options'] ) ) : array();
				if ( count( $opts ) < 2 ) {
					continue;
				}
				$row['options'] = $opts;
				$row['answer']  = isset( $q['answer'] ) ? max( 0, min( count( $opts ) - 1, (int) $q['answer'] ) ) : 0;
			} elseif ( 'multi_select' === $type ) {
				$opts = isset( $q['options'] ) && is_array( $q['options'] ) ? array_values( array_map( 'sanitize_text_field', $q['options'] ) ) : array();
				if ( count( $opts ) < 2 ) {
					continue;
				}
				$raw = isset( $q['answers'] ) && is_array( $q['answers'] ) ? $q['answers'] : array();
				$set = array();
				foreach ( $raw as $a ) {
					$a = (int) $a;
					if ( $a >= 0 && $a < count( $opts ) ) {
						$set[ $a ] = true;
					}
				}
				$set = array_keys( $set );
				sort( $set );
				// A "select all that apply" with nothing correct cannot be
				// answered; drop it rather than serve an unanswerable question.
				if ( empty( $set ) ) {
					continue;
				}
				$row['options'] = $opts;
				$row['answers'] = $set;
			} elseif ( 'true_false' === $type ) {
				$row['options'] = array( __( 'True', 'mahan-academy' ), __( 'False', 'mahan-academy' ) );
				$row['answer']  = ( isset( $q['answer'] ) && 1 === (int) $q['answer'] ) ? 1 : 0;
			} else { // fill_blank
				$row['answer_text']    = isset( $q['answer_text'] ) ? sanitize_text_field( (string) $q['answer_text'] ) : '';
				$row['accept']         = isset( $q['accept'] ) && is_array( $q['accept'] ) ? array_values( array_map( 'sanitize_text_field', $q['accept'] ) ) : array();
				$row['case_sensitive'] = ! empty( $q['case_sensitive'] ) ? 1 : 0;
			}
			$out['questions'][] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Public (answer-stripped) shape                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Browser-safe representation: no correct answers, no explanations, and
	 * options permuted for this sitting.
	 *
	 * Nothing that decides or justifies the answer may leave the server before
	 * the attempt is graded — an explanation sitting in the payload is a hint
	 * anyone can read with the network tab open.
	 *
	 * @param array $def  Quiz definition.
	 * @param int   $seed Sitting seed; 0 leaves the authored order alone.
	 * @return array
	 */
	public static function public_quiz( $def, $seed = 0 ) {
		$questions = array();
		foreach ( (array) $def['questions'] as $q ) {
			$pub = array(
				'key'      => $q['key'],
				'type'     => $q['type'],
				'question' => $q['question'],
			);
			if ( isset( $q['options'] ) ) {
				$opts  = array_values( array_map( 'strval', $q['options'] ) );
				$order = self::order_for( $q, $seed, count( $opts ) );
				$shown = array();
				foreach ( $order as $orig ) {
					$shown[] = $opts[ $orig ];
				}
				$pub['options'] = $shown;
			}
			if ( 'multi_select' === $q['type'] ) {
				// The learner has to know how many to pick — "select all that
				// apply" with no count is a guessing game about scope, not
				// knowledge.
				$pub['pick'] = count( $q['answers'] );
			}
			$questions[] = $pub;
		}
		return array(
			'title'     => '' !== $def['title'] ? $def['title'] : __( 'Unit quiz', 'mahan-academy' ),
			'passing'   => (int) $def['passing'],
			'xp'        => (int) $def['xp'],
			'count'     => count( $questions ),
			'questions' => $questions,
		);
	}

	/**
	 * The display order of one question's options for a sitting.
	 *
	 * @param array $q     Question definition.
	 * @param int   $seed  Sitting seed.
	 * @param int   $count Option count.
	 * @return int[] Original indices in display order.
	 */
	private static function order_for( $q, $seed, $count ) {
		if ( $seed <= 0 || ! empty( $q['no_shuffle'] ) ) {
			return range( 0, max( 0, (int) $count - 1 ) );
		}
		return Mahan_Utils::option_order( (string) $q['key'], (int) $seed, (int) $count );
	}

	/* ------------------------------------------------------------------ */
	/* Grading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Grade one question.
	 *
	 * Choice answers arrive as *display* indices — the learner clicked the
	 * third button they saw, and which original option that was depends on this
	 * sitting's permutation. Everything is mapped back here, so the rest of the
	 * grader only ever deals in authored indices.
	 *
	 * @param array $q      Question definition (with answers).
	 * @param mixed $answer Learner's answer, in display space.
	 * @param int   $seed   Sitting seed.
	 * @return array {correct, correct_index, correct_indexes, typo}
	 */
	public static function grade_question( $q, $answer, $seed = 0 ) {
		$type = $q['type'];

		if ( 'multiple_choice' === $type || 'true_false' === $type ) {
			$count   = isset( $q['options'] ) ? count( $q['options'] ) : 0;
			$order   = self::order_for( $q, $seed, $count );
			$correct = isset( $q['answer'] ) ? (int) $q['answer'] : -1;
			$shown   = is_numeric( $answer ) ? (int) $answer : -1;
			$chosen  = ( $shown >= 0 && isset( $order[ $shown ] ) ) ? (int) $order[ $shown ] : -1;
			return array(
				'correct' => ( $chosen >= 0 && $chosen === $correct ),
				// Reported in display space, because that is what the browser
				// has to highlight.
				'correct_index' => self::display_index( $order, $correct ),
			);
		}

		if ( 'multi_select' === $type ) {
			$count = isset( $q['options'] ) ? count( $q['options'] ) : 0;
			$order = self::order_for( $q, $seed, $count );
			$want  = isset( $q['answers'] ) ? array_map( 'intval', (array) $q['answers'] ) : array();
			sort( $want );

			$got = array();
			foreach ( (array) $answer as $shown ) {
				$shown = (int) $shown;
				if ( isset( $order[ $shown ] ) ) {
					$got[ (int) $order[ $shown ] ] = true;
				}
			}
			$got = array_keys( $got );
			sort( $got );

			// All or nothing: "select all that apply" is answered correctly
			// only when the set matches. Partial credit would reward shotgunning
			// every option, which is the failure mode this type exists to catch.
			$display = array();
			foreach ( $want as $orig ) {
				$display[] = self::display_index( $order, $orig );
			}
			sort( $display );
			return array(
				'correct'         => ( $want === $got ),
				'correct_index'   => null,
				'correct_indexes' => $display,
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
		$gn   = $norm( $given );
		$ok   = false;
		$typo = false;
		foreach ( $accepted as $a ) {
			$an = $norm( $a );
			if ( '' === $an ) {
				continue;
			}
			if ( $an === $gn ) {
				$ok = true;
				break;
			}
			// One slipped key on a long answer is a typing error, not a wrong
			// answer. Case-sensitive questions opt out: there, exactness is the
			// point being tested.
			if ( ! $case && '' !== $gn
				&& mb_strlen( $an ) >= self::TYPO_MIN_LEN
				&& abs( mb_strlen( $an ) - mb_strlen( $gn ) ) <= 1
				&& 1 === levenshtein( $an, $gn ) ) {
				$ok   = true;
				$typo = true;
				break;
			}
		}
		return array( 'correct' => $ok, 'correct_index' => null, 'typo' => $typo );
	}

	/**
	 * Where an authored option index ended up on screen.
	 *
	 * @param int[] $order Original indices in display order.
	 * @param int   $orig  Authored index.
	 * @return int|null Display index, or null when it is not on screen.
	 */
	private static function display_index( $order, $orig ) {
		$pos = array_search( (int) $orig, $order, true );
		return false === $pos ? null : (int) $pos;
	}

	/**
	 * Grade a submitted quiz.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $course_id Course id.
	 * @param string $unit      Unit title.
	 * @param array  $answers   key => answer (in display space).
	 * @param int    $seed      The sitting's seed, so grading can reconstruct
	 *                          the option permutation the learner saw.
	 * @return array
	 */
	public static function grade( $user_id, $course_id, $unit, $answers, $seed = 0 ) {
		$def = self::get( $course_id, $unit );
		if ( ! $def ) {
			return array( 'ok' => false, 'error' => 'no_quiz' );
		}
		$answers = is_array( $answers ) ? $answers : array();
		$total   = count( $def['questions'] );
		$correct = 0;
		$results = array();
		foreach ( $def['questions'] as $q ) {
			$key = $q['key'];
			$ans = isset( $answers[ $key ] ) ? $answers[ $key ] : '';
			$g   = self::grade_question( $q, $ans, $seed );
			if ( $g['correct'] ) {
				$correct++;
			}
			$row = array(
				'key'           => $key,
				'correct'       => (bool) $g['correct'],
				'correct_index' => $g['correct_index'],
			);
			if ( isset( $g['correct_indexes'] ) ) {
				$row['correct_indexes'] = $g['correct_indexes'];
			}
			// The explanation is released here and nowhere earlier: the attempt
			// is graded, so it teaches instead of giving the answer away.
			if ( '' !== (string) $q['explain'] ) {
				$row['explain'] = (string) $q['explain'];
			}
			if ( ! empty( $g['typo'] ) ) {
				$row['typo']        = true;
				$row['answer_text'] = isset( $q['answer_text'] ) ? (string) $q['answer_text'] : '';
			}
			$results[] = $row;

			// Queue each wrong quiz question for spaced-repetition review.
			Mahan_Reviews::record(
				$user_id,
				array(
					'source'    => 'quiz',
					'course_id' => (int) $course_id,
					'lesson_id' => 0,
					'item_key'  => Mahan_Reviews::key_quiz( $course_id, $unit, $key ),
					'concept'   => isset( $q['question'] ) ? (string) $q['question'] : '',
					'type'      => (string) $q['type'],
					'snapshot'  => $q,
				),
				(bool) $g['correct']
			);
		}
		$score  = $total > 0 ? (int) round( ( $correct / $total ) * 100 ) : 0;
		$passed = $score >= (int) $def['passing'];

		$already = self::best( $user_id, $course_id, $unit );
		$was_passed = $already && ! empty( $already['is_correct'] );

		$xp_award = 0;
		if ( $passed && ! $was_passed ) {
			$xp_award = (int) $def['xp'] > 0 ? (int) $def['xp'] : ( $total * (int) Mahan_Settings::get( 'xp_per_exercise', 10 ) );
		}

		self::store_attempt( $user_id, $course_id, $unit, $answers, $passed, $score, $xp_award, $results );

		$awarded    = 0;
		$leveled_up = false;
		if ( $xp_award > 0 ) {
			// record_activity() first so add_xp()'s streak bonus reads today's
			// streak (and a lapsed streak is reset before the bonus applies).
			Mahan_Gamification::record_activity( $user_id );
			$award      = Mahan_Gamification::add_xp( $user_id, $xp_award, 'quiz', $course_id );
			$awarded    = (int) $award['awarded'];
			$leveled_up = ! empty( $award['leveled_up'] );
			do_action( 'mahan_quiz_passed', $user_id, $course_id, $unit, $score );
		}

		return array(
			'ok'         => true,
			'score'      => $score,
			'passed'     => $passed,
			'passing'    => (int) $def['passing'],
			'correct'    => $correct,
			'total'      => $total,
			'results'    => $results,
			'xp_awarded' => $awarded,
			'leveled_up' => $leveled_up,
			'new_badges' => Mahan_Badges::take_new( $user_id ),
			'stats'      => Mahan_Gamification::hud( $user_id ),
		);
	}

	private static function key_for( $unit ) {
		return 'quiz:' . md5( (string) $unit );
	}

	private static function store_attempt( $user_id, $course_id, $unit, $answers, $passed, $score, $xp_award, $results ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Mahan_DB::attempts(),
			array(
				'user_id'      => (int) $user_id,
				'lesson_id'    => 0,
				'course_id'    => (int) $course_id,
				'exercise_key' => self::key_for( $unit ),
				'type'         => 'quiz',
				'user_answer'  => wp_json_encode( $answers ),
				'is_correct'   => $passed ? 1 : 0,
				'score'        => (int) $score,
				'xp_awarded'   => (int) $xp_award,
				'feedback'     => wp_json_encode( array( 'unit' => $unit, 'results' => $results ) ),
				'created_at'   => Mahan_Utils::now_mysql(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Best attempt (passed if any, else highest score) for a unit quiz.
	 *
	 * @return array|null
	 */
	public static function best( $user_id, $course_id, $unit ) {
		global $wpdb;
		$table = Mahan_DB::attempts();
		$key   = self::key_for( $unit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Pure aggregate query (no bare non-grouped column) so it stays valid
		// under MySQL's ONLY_FULL_GROUP_BY — otherwise the query errors, best()
		// returns null, and quiz XP is re-awarded on every pass.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MAX(score) AS score, MAX(is_correct) AS ever_passed, COUNT(*) AS attempts
				 FROM {$table} WHERE user_id = %d AND course_id = %d AND exercise_key = %s",
				(int) $user_id,
				(int) $course_id,
				$key
			),
			ARRAY_A
		);
		if ( ! $row || null === $row['score'] ) {
			return null;
		}
		return array(
			'score'      => (int) $row['score'],
			'is_correct' => (int) $row['ever_passed'] ? 1 : 0,
			'attempts'   => (int) $row['attempts'],
		);
	}
}
