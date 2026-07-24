<?php
/**
 * Personalization "brain": turns the learner profile + their live performance
 * signal into (a) a compact context block injected into every AI prompt and
 * (b) an adaptive target difficulty that tunes generated questions and the
 * tutor's pitch. Centralises what used to be an ad-hoc profile sprintf.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Personalization {

	/* ------------------------------------------------------------------ */
	/* Adaptive difficulty (pure computation — unit-tested)                */
	/* ------------------------------------------------------------------ */

	/**
	 * Target difficulty 1–5 from the stated AI level + live progress signal.
	 * Pure function of its inputs so it can be reasoned about and tested.
	 *
	 * @param string $ai_level 'beginner'|'intermediate'|'advanced' (raw value).
	 * @param int    $level    Gamification level.
	 * @param int    $mastered Reviews in a mastered box.
	 * @param int    $learning Reviews still being learned.
	 * @return int   1 (foundational) … 5 (advanced).
	 */
	public static function difficulty_from( $ai_level, $level, $mastered, $learning ) {
		$base = 3;
		if ( 'beginner' === $ai_level ) {
			$base = 2;
		} elseif ( 'advanced' === $ai_level ) {
			$base = 4;
		} elseif ( 'expert' === $ai_level ) {
			$base = 5;
		}
		// As the learner racks up levels, lift the floor a notch.
		if ( (int) $level >= 8 ) {
			$base += 1;
		}
		// Once there's a real review history, let mastery pull difficulty
		// up or down so the questions track actual performance.
		$total = (int) $mastered + (int) $learning;
		if ( $total >= 6 ) {
			$ratio = $mastered / $total;
			if ( $ratio >= 0.7 ) {
				$base += 1;
			} elseif ( $ratio < 0.3 ) {
				$base -= 1;
			}
		}
		return max( 1, min( 5, (int) $base ) );
	}

	/**
	 * Human label for a 1–5 difficulty.
	 */
	public static function difficulty_label( $level ) {
		$level = (int) $level;
		if ( $level <= 2 ) {
			return 'foundational';
		}
		if ( 3 === $level ) {
			return 'intermediate';
		}
		return 'advanced';
	}

	/* ------------------------------------------------------------------ */
	/* Live signal + difficulty for a real user                            */
	/* ------------------------------------------------------------------ */

	/**
	 * The learner's live progress signal (one gamification query).
	 *
	 * @return array { level, mastered, learning }
	 */
	public static function signals( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! class_exists( 'Mahan_Gamification' ) ) {
			return array(
				'level'    => 1,
				'mastered' => 0,
				'learning' => 0,
			);
		}
		$stats = Mahan_Gamification::hud( $user_id );
		$rev   = ( isset( $stats['reviews'] ) && is_array( $stats['reviews'] ) ) ? $stats['reviews'] : array();
		return array(
			'level'    => isset( $stats['level'] ) ? (int) $stats['level'] : 1,
			'mastered' => isset( $rev['mastered'] ) ? (int) $rev['mastered'] : 0,
			'learning' => isset( $rev['learning'] ) ? (int) $rev['learning'] : 0,
		);
	}

	/**
	 * Adaptive difficulty for a user.
	 *
	 * @param int        $user_id User id.
	 * @param array|null $signals Optional pre-fetched signals to avoid a query.
	 * @return array { level:int, label:string }
	 */
	public static function difficulty( $user_id, $signals = null ) {
		$profile  = class_exists( 'Mahan_Profile' ) ? Mahan_Profile::get_profile( (int) $user_id ) : array();
		$ai_level = isset( $profile['ai_level'] ) ? (string) $profile['ai_level'] : '';
		$s        = is_array( $signals ) ? $signals : self::signals( $user_id );
		$level    = self::difficulty_from( $ai_level, $s['level'], $s['mastered'], $s['learning'] );
		return array(
			'level' => $level,
			'label' => self::difficulty_label( $level ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Learner context block (injected into every AI prompt)               */
	/* ------------------------------------------------------------------ */

	/**
	 * A compact, structured context block describing who the learner is and
	 * where they are, so the tutor / grader / question-writer can personalize.
	 * Only lines with a real value are emitted.
	 *
	 * @param int   $user_id User id.
	 * @param array $opts    { with_difficulty:bool (default true) }.
	 * @return string Multi-line block, or '' when there's nothing to say.
	 */
	public static function learner_context( $user_id, $opts = array() ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! class_exists( 'Mahan_Profile' ) ) {
			return '';
		}
		$map            = Mahan_Profile::placeholder_map( $user_id );
		$with_difficulty = ! isset( $opts['with_difficulty'] ) || $opts['with_difficulty'];

		$lines = array();
		$add   = function ( $label, $key ) use ( &$lines, $map ) {
			$v = isset( $map[ $key ] ) ? trim( (string) $map[ $key ] ) : '';
			if ( '' !== $v && '(not provided)' !== $v ) {
				$lines[] = '- ' . $label . ': ' . $v;
			}
		};

		if ( ! empty( $map['name'] ) ) {
			$lines[] = '- Name: ' . $map['name'];
		}
		$add( 'Role', 'role' );
		$add( 'Organisation', 'company_type' );
		$add( 'Seniority', 'seniority' );
		$add( 'AI experience', 'ai_level' );
		$add( 'Primary goal', 'primary_goal' );
		$add( 'Tools they use', 'daily_tools' );
		$add( 'Preferred learning style', 'learning_style' );
		$add( 'Biggest challenge', 'biggest_challenge' );

		$s = self::signals( $user_id );
		$lines[] = sprintf(
			'- Progress: level %d, %d concept(s) mastered, %d still in review',
			$s['level'],
			$s['mastered'],
			$s['learning']
		);

		if ( $with_difficulty ) {
			$diff    = self::difficulty( $user_id, $s );
			$lines[] = sprintf(
				'- Target difficulty: %s (%d of 5) — pitch questions and explanations at this level.',
				$diff['label'],
				$diff['level']
			);
		}

		if ( empty( $lines ) ) {
			return '';
		}
		return "LEARNER CONTEXT (use it to tailor examples, wording, and difficulty; never quote it back):\n"
			. implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------ */
	/* Per-lesson "For you" personalization                                */
	/* ------------------------------------------------------------------ */

	/**
	 * A short, learner-specific note that connects a lesson to THIS person's job
	 * — so every lesson is personalized, not just the tutor. Generated once per
	 * (lesson, profile-state) and cached in the AI cache; regenerated when the
	 * learner updates their profile.
	 *
	 * @param int $user_id   User id.
	 * @param int $lesson_id Lesson id.
	 * @return array { ok:bool, text?:string, reason?:string }
	 */
	public static function for_you( $user_id, $lesson_id ) {
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;

		if ( ! class_exists( 'Mahan_Profile' ) ) {
			return array( 'ok' => false, 'reason' => 'unavailable' );
		}
		$sig = Mahan_Profile::signature( $user_id );
		if ( '' === $sig ) {
			// Nothing in the profile to personalize with — nudge to complete it.
			return array( 'ok' => false, 'reason' => 'no_profile' );
		}
		if ( ! Mahan_Settings::ai_ready() ) {
			return array( 'ok' => false, 'reason' => 'ai_unavailable' );
		}

		$lesson = get_post( $lesson_id );
		if ( ! $lesson || Mahan_CPT::LESSON !== $lesson->post_type || 'publish' !== $lesson->post_status ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}

		$cache_key = md5( 'mahan_foryou:' . $lesson_id . ':' . $sig );
		$cached    = Mahan_DB::cache_get( $cache_key, 0 );
		if ( null !== $cached && '' !== trim( (string) $cached ) ) {
			return array( 'ok' => true, 'text' => (string) $cached, 'cached' => true );
		}

		$title  = get_the_title( $lesson_id );
		$topics = Mahan_Courses::lesson_topics( $lesson_id );
		$plain  = wp_strip_all_tags( (string) $lesson->post_content );
		$plain  = trim( preg_replace( '/\s+/', ' ', $plain ) );
		if ( strlen( $plain ) > 1800 ) {
			$plain = substr( $plain, 0, 1800 ) . '…';
		}

		$system = 'You connect what a lesson teaches to THIS specific learner\'s job. '
			. 'In 2–3 short sentences (max ~55 words), tell them concretely how to apply this lesson in their own work — '
			. 'name their role, a tool they use, or their stated goal where natural. Warm, specific, second person ("you"). '
			. 'No preamble, no headings, no restating the lesson — just the personal connection. '
			. 'Write in the same language as the lesson.';
		$ctx      = self::learner_context( $user_id, array( 'with_difficulty' => false ) );
		$topics_line = ! empty( $topics ) ? "\nConcepts: " . implode( ', ', $topics ) : '';
		$user_msg = ( '' !== $ctx ? $ctx . "\n\n" : '' )
			. "LESSON: {$title}{$topics_line}\n\nLESSON SUMMARY:\n{$plain}\n\n"
			. 'Write the personalized "how this applies to your work" note now.';

		$res = Mahan_AI::complete(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			array( 'max_tokens' => 200, 'temperature' => 0.5 )
		);
		if ( empty( $res['ok'] ) || '' === trim( (string) $res['text'] ) ) {
			return array( 'ok' => false, 'reason' => 'generation_failed' );
		}

		$text = trim( wp_strip_all_tags( (string) $res['text'] ) );
		if ( '' === $text ) {
			return array( 'ok' => false, 'reason' => 'generation_failed' );
		}
		Mahan_DB::cache_set( $cache_key, $text );
		return array( 'ok' => true, 'text' => $text, 'cached' => false );
	}

	/**
	 * One-line teaching directive derived from difficulty + learning style,
	 * appended to generation/grading prompts.
	 */
	public static function difficulty_directive( $user_id ) {
		$diff  = self::difficulty( $user_id );
		$style = '';
		if ( class_exists( 'Mahan_Profile' ) ) {
			$p     = Mahan_Profile::get_profile( (int) $user_id );
			$style = isset( $p['learning_style'] ) ? (string) $p['learning_style'] : '';
		}
		$out = sprintf( 'Aim for a %s (%d/5) level of challenge.', $diff['label'], $diff['level'] );
		if ( 'deep' === $style ) {
			$out .= ' The learner prefers thorough, in-depth treatment.';
		} elseif ( 'quick' === $style ) {
			$out .= ' The learner prefers quick, practical, to-the-point material.';
		}
		return $out;
	}
}
