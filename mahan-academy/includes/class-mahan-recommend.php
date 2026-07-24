<?php
/**
 * Personalized course recommendations.
 *
 * Turns the learner profile (role, primary goal, AI level) into a fit-score for
 * every published course and bundle, so the catalog can lead with "Recommended
 * for you" instead of a flat list. Pure, deterministic scoring (no AI, no new
 * tables) built on the seeded categories/levels — unit-testable in isolation.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Recommend {

	/**
	 * Goal → preferred categories, best-first. A course in the first category
	 * scores highest, the second next, and so on.
	 */
	const GOAL_CATS = array(
		'automate' => array( 'AI at Work', 'AI Tools', 'Prompt Engineering' ),
		'content'  => array( 'Prompt Engineering', 'AI Tools', 'AI at Work' ),
		'analysis' => array( 'Machine Learning', 'AI at Work', 'Prompt Engineering' ),
		'support'  => array( 'AI Tools', 'Prompt Engineering', 'AI at Work' ),
		'coding'   => array( 'Prompt Engineering', 'Machine Learning', 'Generative AI' ),
		'learning' => array( 'Generative AI', 'AI Tools', 'Prompt Engineering' ),
	);

	/**
	 * Role → preferred categories, best-first.
	 */
	const ROLE_CATS = array(
		'engineering' => array( 'Machine Learning', 'Generative AI', 'Prompt Engineering' ),
		'product'     => array( 'Generative AI', 'Machine Learning', 'AI at Work' ),
		'marketing'   => array( 'Prompt Engineering', 'AI Tools', 'AI at Work' ),
		'sales'       => array( 'AI Tools', 'Prompt Engineering', 'AI at Work' ),
		'operations'  => array( 'AI at Work', 'AI Tools', 'Prompt Engineering' ),
		'hr'          => array( 'AI at Work', 'AI Tools', 'Prompt Engineering' ),
		'finance'     => array( 'AI at Work', 'Machine Learning', 'AI Tools' ),
		'founder'     => array( 'Generative AI', 'AI at Work', 'AI Tools' ),
		'student'     => array( 'Generative AI', 'Machine Learning', 'Prompt Engineering' ),
	);

	/* ------------------------------------------------------------------ */
	/* Pure scoring                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Preference vector derived from a raw profile.
	 *
	 * @param array $profile Raw profile (mahan_profile meta).
	 * @return array { goal_cats:string[], role_cats:string[], level:string }
	 */
	public static function prefs( $profile ) {
		$goal = isset( $profile['primary_goal'] ) ? (string) $profile['primary_goal'] : '';
		$role = isset( $profile['role'] ) ? (string) $profile['role'] : '';
		$lvl  = isset( $profile['ai_level'] ) ? (string) $profile['ai_level'] : '';
		return array(
			'goal_cats' => isset( self::GOAL_CATS[ $goal ] ) ? self::GOAL_CATS[ $goal ] : array(),
			'role_cats' => isset( self::ROLE_CATS[ $role ] ) ? self::ROLE_CATS[ $role ] : array(),
			'level'     => in_array( $lvl, array( 'beginner', 'intermediate', 'advanced' ), true ) ? $lvl : '',
		);
	}

	/**
	 * Does this preference vector carry any signal? (No role AND no goal AND no
	 * level → nothing to personalize on.)
	 */
	public static function has_signal( $prefs ) {
		return ! empty( $prefs['goal_cats'] ) || ! empty( $prefs['role_cats'] ) || '' !== $prefs['level'];
	}

	/**
	 * Fit score for one course given the learner's preferences. Higher is better.
	 *
	 * @param array $course { categories:string[], level:string, featured:bool }.
	 * @param array $prefs  From prefs().
	 * @return int
	 */
	public static function score_course( $course, $prefs ) {
		$cats  = isset( $course['categories'] ) && is_array( $course['categories'] ) ? $course['categories'] : array();
		$score = 0;

		// Category match by goal, weighted by rank (first = +3, then +2, +1).
		foreach ( array_values( $prefs['goal_cats'] ) as $i => $cat ) {
			if ( in_array( $cat, $cats, true ) ) {
				$score += max( 1, 3 - $i );
			}
		}
		// Category match by role (slightly lighter than goal).
		foreach ( array_values( $prefs['role_cats'] ) as $i => $cat ) {
			if ( in_array( $cat, $cats, true ) ) {
				$score += max( 1, 3 - $i );
			}
		}

		// Level fit.
		$level = isset( $course['level'] ) ? (string) $course['level'] : 'beginner';
		$pref  = $prefs['level'];
		if ( 'beginner' === $pref ) {
			$score += ( 'beginner' === $level ) ? 2 : ( ( 'intermediate' === $level ) ? 1 : 0 );
		} elseif ( 'advanced' === $pref ) {
			$score += ( 'advanced' === $level ) ? 2 : ( ( 'intermediate' === $level ) ? 1 : 0 );
		} elseif ( 'intermediate' === $pref ) {
			$score += ( 'intermediate' === $level ) ? 2 : 1;
		}

		// Gentle boost for featured courses so good defaults float up on ties —
		// only once there's some preference signal, so a blank profile scores 0
		// everywhere and cleanly falls back to catalog order.
		if ( ! empty( $course['featured'] ) && self::has_signal( $prefs ) ) {
			$score += 1;
		}
		return (int) $score;
	}

	/* ------------------------------------------------------------------ */
	/* For a real user                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the recommendation payload for a user.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max recommended courses.
	 * @return array { has_profile, reason, recommended:[course summaries], bundle:summary|null }
	 */
	public static function for_user( $user_id, $limit = 6 ) {
		$user_id = (int) $user_id;
		$limit   = max( 1, min( 12, (int) $limit ) );
		$profile = class_exists( 'Mahan_Profile' ) ? Mahan_Profile::get_profile( $user_id ) : array();
		$prefs   = self::prefs( $profile );
		$has     = self::has_signal( $prefs );

		$courses = Mahan_Courses::get_courses();
		$scored  = array();
		foreach ( $courses as $index => $course ) {
			$summary = Mahan_Courses::course_summary( $course );
			if ( ! $summary ) {
				continue;
			}
			// Skip courses the learner is already enrolled in — recommendations
			// are for discovering something new. (Continue-learning lives on the
			// dashboard.)
			if ( $user_id && Mahan_Enrollment::is_enrolled( $user_id, $summary['id'] ) ) {
				continue;
			}
			$score = $has ? self::score_course( $summary, $prefs ) : 0;
			$scored[] = array(
				'summary'  => $summary,
				'score'    => $score,
				'featured' => ! empty( $summary['featured'] ) ? 1 : 0,
				'order'    => (int) $index, // stable catalog order as the final tiebreak
			);
		}

		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				if ( $a['featured'] !== $b['featured'] ) {
					return $b['featured'] <=> $a['featured'];
				}
				return $a['order'] <=> $b['order'];
			}
		);

		$recommended = array();
		foreach ( array_slice( $scored, 0, $limit ) as $row ) {
			$recommended[] = $row['summary'];
		}

		return array(
			'has_profile' => $has,
			'reason'      => self::reason( $user_id, $prefs ),
			'recommended' => $recommended,
			'bundle'      => self::best_bundle( $user_id, $prefs ),
		);
	}

	/**
	 * Highest-fit bundle (learning path) for the learner, or null.
	 *
	 * @param int   $user_id User id.
	 * @param array $prefs   From prefs().
	 * @return array|null Path summary.
	 */
	private static function best_bundle( $user_id, $prefs ) {
		if ( ! self::has_signal( $prefs ) || ! class_exists( 'Mahan_Paths' ) ) {
			return null;
		}
		$best       = null;
		$best_score = -1;
		foreach ( Mahan_Paths::get_paths() as $path ) {
			$summary = Mahan_Paths::summary( $path, $user_id );
			if ( ! $summary || $summary['course_count'] < 1 ) {
				continue;
			}
			// Already finished → don't recommend.
			if ( $summary['course_count'] > 0 && $summary['completed'] >= $summary['course_count'] ) {
				continue;
			}
			$score = 0;
			foreach ( Mahan_Paths::course_ids( $summary['id'] ) as $cid ) {
				$cs = Mahan_Courses::course_summary( $cid );
				if ( $cs ) {
					$score += self::score_course( $cs, $prefs );
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $summary;
			}
		}
		return ( $best_score > 0 ) ? $best : null;
	}

	/**
	 * A short human explanation of why these are recommended.
	 *
	 * @param int   $user_id User id.
	 * @param array $prefs   From prefs().
	 * @return string
	 */
	private static function reason( $user_id, $prefs ) {
		if ( ! self::has_signal( $prefs ) || ! class_exists( 'Mahan_Profile' ) ) {
			return '';
		}
		$map  = Mahan_Profile::placeholder_map( $user_id );
		$role = isset( $map['role'] ) ? trim( (string) $map['role'] ) : '';
		$goal = isset( $map['primary_goal'] ) ? trim( (string) $map['primary_goal'] ) : '';
		$role = ( '' !== $role && '(not provided)' !== $role ) ? $role : '';
		$goal = ( '' !== $goal && '(not provided)' !== $goal ) ? $goal : '';

		if ( '' !== $role && '' !== $goal ) {
			/* translators: 1: role, 2: primary goal. */
			return sprintf( __( 'Based on your role in %1$s and your goal to %2$s.', 'mahan-academy' ), $role, mb_strtolower( $goal ) );
		}
		if ( '' !== $role ) {
			/* translators: %s: role. */
			return sprintf( __( 'Based on your role in %s.', 'mahan-academy' ), $role );
		}
		if ( '' !== $goal ) {
			/* translators: %s: primary goal. */
			return sprintf( __( 'Based on your goal to %s.', 'mahan-academy' ), mb_strtolower( $goal ) );
		}
		return '';
	}
}
