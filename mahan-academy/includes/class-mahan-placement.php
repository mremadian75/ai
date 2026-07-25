<?php
/**
 * Placement test — works out which rung of the ladder a learner should start
 * on, before they pick a course.
 *
 * Deliberately *not* AI-generated. A placement test is the first thing a new
 * learner meets; it has to work on a site with no API key configured, give the
 * same result twice, and be reviewable by whoever runs the academy. So the
 * bank is authored data (includes/data/placement.php) and the scoring is pure
 * arithmetic.
 *
 * The result lives in user meta, not a table: it's one small record per
 * learner that is read constantly and written rarely.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Placement {

	/** User meta holding the latest result. */
	const META = 'mahan_placement';

	/** How many questions a sitting asks. */
	const LENGTH = 12;

	/**
	 * Difficulty tiers, in order. A question's tier is what it's worth and
	 * which level answering it correctly is evidence for.
	 */
	const TIERS = array( 1 => 'beginner', 2 => 'intermediate', 3 => 'advanced', 4 => 'expert' );

	/** Cached bank so a request doesn't re-read the file. */
	private static $bank = null;

	/**
	 * The authored question bank.
	 *
	 * @return array[] questions with { key, tier, question, options, answer, topic }.
	 */
	public static function bank() {
		if ( null !== self::$bank ) {
			return self::$bank;
		}
		$file = MAHAN_DIR . 'includes/data/placement.php';
		$raw  = file_exists( $file ) ? include $file : array();
		$out  = array();
		foreach ( (array) $raw as $q ) {
			if ( empty( $q['key'] ) || empty( $q['question'] ) || empty( $q['options'] ) ) {
				continue;
			}
			$tier = isset( $q['tier'] ) ? (int) $q['tier'] : 1;
			if ( ! isset( self::TIERS[ $tier ] ) ) {
				continue;
			}
			$options = array_values( array_filter( array_map( 'strval', (array) $q['options'] ), function ( $o ) {
				return '' !== trim( $o );
			} ) );
			$answer = isset( $q['answer'] ) ? (int) $q['answer'] : -1;
			// A question whose key is out of range would silently mark every
			// learner wrong — drop it rather than ship a broken item.
			if ( count( $options ) < 2 || $answer < 0 || $answer >= count( $options ) ) {
				continue;
			}
			$out[] = array(
				'key'      => (string) $q['key'],
				'tier'     => $tier,
				'question' => (string) $q['question'],
				'options'  => $options,
				'answer'   => $answer,
				'topic'    => isset( $q['topic'] ) ? (string) $q['topic'] : '',
			);
		}
		self::$bank = $out;
		return $out;
	}

	/**
	 * A balanced sitting: an even spread across the four tiers, so the test
	 * can tell a beginner from an expert instead of only measuring one band.
	 *
	 * Options are permuted per sitting (see option_order()), so the position of
	 * the right answer carries no information.
	 *
	 * @param int $length How many questions.
	 * @param int $seed   Deterministic shuffle seed (0 = time-based).
	 * @return array[] questions with the answer key stripped.
	 */
	public static function sitting( $length = self::LENGTH, $seed = 0 ) {
		$by_tier = array( 1 => array(), 2 => array(), 3 => array(), 4 => array() );
		foreach ( self::bank() as $q ) {
			$by_tier[ $q['tier'] ][] = $q;
		}

		$per = max( 1, (int) floor( $length / 4 ) );
		$picked = array();
		foreach ( $by_tier as $tier => $items ) {
			self::shuffle_seeded( $items, $seed + $tier );
			$picked = array_merge( $picked, array_slice( $items, 0, $per ) );
		}
		// Top up from whatever's left if a tier was short, so a thin bank
		// still produces a full-length sitting.
		if ( count( $picked ) < $length ) {
			$used = array();
			foreach ( $picked as $p ) {
				$used[ $p['key'] ] = true;
			}
			$rest = array();
			foreach ( self::bank() as $q ) {
				if ( ! isset( $used[ $q['key'] ] ) ) {
					$rest[] = $q;
				}
			}
			self::shuffle_seeded( $rest, $seed + 99 );
			$picked = array_merge( $picked, array_slice( $rest, 0, $length - count( $picked ) ) );
		}

		// Easiest first: opening an assessment with an expert question makes
		// beginners quit before it can place them.
		usort( $picked, function ( $a, $b ) {
			return $a['tier'] <=> $b['tier'];
		} );

		$out = array();
		foreach ( $picked as $q ) {
			$order   = self::option_order( $q['key'], $seed, count( $q['options'] ) );
			$options = array();
			foreach ( $order as $idx ) {
				$options[] = $q['options'][ $idx ];
			}
			$out[] = array(
				'key'      => $q['key'],
				'tier'     => $q['tier'],
				'question' => $q['question'],
				'options'  => $options,
				'topic'    => $q['topic'],
			);
		}
		return $out;
	}

	/**
	 * The order this sitting shows a question's options in.
	 *
	 * Authored banks drift toward putting the right answer in the same slot —
	 * ours did, on nearly every item, which would have let "always pick B"
	 * score full marks. Permuting at serve time makes position carry no
	 * information no matter how the data is written, and deriving the
	 * permutation from (key, seed) means grading can reconstruct it without
	 * storing anything.
	 *
	 * @param string $key   Question key.
	 * @param int    $seed  Sitting seed.
	 * @param int    $count Option count.
	 * @return int[] original indices, in display order.
	 */
	public static function option_order( $key, $seed, $count ) {
		$idx = range( 0, max( 0, (int) $count - 1 ) );
		if ( $count < 2 ) {
			return $idx;
		}
		// Seed from the question key too, so two questions in one sitting
		// don't share a permutation.
		$state = (int) $seed;
		$k     = (string) $key;
		for ( $i = 0; $i < strlen( $k ); $i++ ) {
			$state = ( $state * 31 + ord( $k[ $i ] ) ) % 2147483647;
		}
		if ( $state <= 0 ) {
			$state = 1;
		}
		for ( $i = count( $idx ) - 1; $i > 0; $i-- ) {
			$state = ( $state * 1103515245 + 12345 ) % 2147483648;
			$j     = $state % ( $i + 1 );
			$tmp       = $idx[ $i ];
			$idx[ $i ] = $idx[ $j ];
			$idx[ $j ] = $tmp;
		}
		return $idx;
	}

	/**
	 * Deterministic shuffle (Fisher-Yates over a seeded LCG), so a given seed
	 * always yields the same sitting — which is what makes the flow testable.
	 *
	 * @param array $items Items, by reference.
	 * @param int   $seed  Seed; 0 falls back to a random shuffle.
	 */
	private static function shuffle_seeded( array &$items, $seed ) {
		if ( $seed <= 0 ) {
			shuffle( $items );
			return;
		}
		$state = ( $seed * 2654435761 ) % 2147483647;
		for ( $i = count( $items ) - 1; $i > 0; $i-- ) {
			$state = ( $state * 1103515245 + 12345 ) % 2147483648;
			$j     = $state % ( $i + 1 );
			$tmp        = $items[ $i ];
			$items[ $i ] = $items[ $j ];
			$items[ $j ] = $tmp;
		}
	}

	/**
	 * Grade a sitting.
	 *
	 * Scoring is weighted by tier: getting an expert question right is worth
	 * four times a beginner one. The level is then the highest tier the
	 * learner actually demonstrated — you are placed at a level when you can
	 * answer most of that level's questions, not when you scrape a total.
	 *
	 * @param array $answers key => chosen option index, in the order the
	 *                       sitting displayed them.
	 * @param int   $seed    The sitting's seed, so the option permutation can
	 *                       be reconstructed. Nothing is stored server-side.
	 * @return array { level, level_rank, score, max_score, correct, total, per_tier }
	 */
	public static function grade( array $answers, $seed = 0 ) {
		$bank = array();
		foreach ( self::bank() as $q ) {
			$bank[ $q['key'] ] = $q;
		}

		$per_tier = array(
			1 => array( 'right' => 0, 'asked' => 0 ),
			2 => array( 'right' => 0, 'asked' => 0 ),
			3 => array( 'right' => 0, 'asked' => 0 ),
			4 => array( 'right' => 0, 'asked' => 0 ),
		);
		$score = 0;
		$max   = 0;
		$right = 0;
		$total = 0;

		foreach ( $answers as $key => $chosen ) {
			if ( ! isset( $bank[ $key ] ) ) {
				continue;
			}
			$q    = $bank[ $key ];
			$tier = $q['tier'];
			$total++;
			$max += $tier;
			$per_tier[ $tier ]['asked']++;
			// The learner picked a position in the shuffled list; map it back
			// to the authored option before comparing.
			$order    = self::option_order( $q['key'], $seed, count( $q['options'] ) );
			$chosen   = (int) $chosen;
			$original = ( $chosen >= 0 && $chosen < count( $order ) ) ? $order[ $chosen ] : -1;
			if ( $original === $q['answer'] ) {
				$right++;
				$score += $tier;
				$per_tier[ $tier ]['right']++;
			}
		}

		return array_merge(
			array(
				'score'     => $score,
				'max_score' => $max,
				'correct'   => $right,
				'total'     => $total,
				'per_tier'  => $per_tier,
			),
			self::level_from( $per_tier )
		);
	}

	/**
	 * Resolve a level from per-tier performance.
	 *
	 * The rule: you place at the highest tier where you answered at least
	 * two-thirds of that tier's questions correctly, *and* did so at every
	 * tier below it. That last clause matters — someone who guesses one
	 * expert question right but misses the intermediate ones is not an
	 * expert, and a total-score rule would place them as one.
	 *
	 * @param array $per_tier tier => { right, asked }.
	 * @return array { level, level_rank }
	 */
	public static function level_from( array $per_tier ) {
		$rank = 1;
		for ( $tier = 1; $tier <= 4; $tier++ ) {
			$asked = isset( $per_tier[ $tier ]['asked'] ) ? (int) $per_tier[ $tier ]['asked'] : 0;
			$right = isset( $per_tier[ $tier ]['right'] ) ? (int) $per_tier[ $tier ]['right'] : 0;
			if ( $asked < 1 ) {
				// Nothing asked at this tier — no evidence either way, so we
				// can't claim it, and we stop climbing.
				break;
			}
			if ( ( $right / $asked ) < ( 2 / 3 ) ) {
				break;
			}
			$rank = $tier;
		}
		return array(
			'level'      => self::TIERS[ $rank ],
			'level_rank' => $rank,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Stored result                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The learner's stored placement, or null.
	 *
	 * @param int $user_id User id.
	 * @return array|null
	 */
	public static function get( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META, true );
		if ( ! is_array( $raw ) || empty( $raw['level'] ) ) {
			return null;
		}
		return array(
			'level'      => (string) $raw['level'],
			'level_rank' => isset( $raw['level_rank'] ) ? (int) $raw['level_rank'] : 1,
			'score'      => isset( $raw['score'] ) ? (int) $raw['score'] : 0,
			'max_score'  => isset( $raw['max_score'] ) ? (int) $raw['max_score'] : 0,
			'correct'    => isset( $raw['correct'] ) ? (int) $raw['correct'] : 0,
			'total'      => isset( $raw['total'] ) ? (int) $raw['total'] : 0,
			'taken_at'   => isset( $raw['taken_at'] ) ? (string) $raw['taken_at'] : '',
		);
	}

	/**
	 * Store a result and sync the profile's stated level to it, so everything
	 * already keyed off `ai_level` (tutor tone, question difficulty,
	 * recommendations) picks the placement up with no extra wiring.
	 *
	 * @param int   $user_id User id.
	 * @param array $result  Output of grade().
	 * @return array The stored record.
	 */
	public static function save( $user_id, array $result ) {
		$user_id = (int) $user_id;
		$record  = array(
			'level'      => isset( $result['level'] ) ? (string) $result['level'] : 'beginner',
			'level_rank' => isset( $result['level_rank'] ) ? (int) $result['level_rank'] : 1,
			'score'      => isset( $result['score'] ) ? (int) $result['score'] : 0,
			'max_score'  => isset( $result['max_score'] ) ? (int) $result['max_score'] : 0,
			'correct'    => isset( $result['correct'] ) ? (int) $result['correct'] : 0,
			'total'      => isset( $result['total'] ) ? (int) $result['total'] : 0,
			'taken_at'   => Mahan_Utils::now_mysql(),
		);
		update_user_meta( $user_id, self::META, $record );

		// save_profile() replaces the stored profile with what it's given, so
		// merge into the existing one — writing just the level would wipe the
		// learner's role, goal and tools.
		$profile             = Mahan_Profile::get_profile( $user_id );
		$profile             = is_array( $profile ) ? $profile : array();
		$profile['ai_level'] = $record['level'];
		Mahan_Profile::save_profile( $user_id, $profile );

		return $record;
	}

	/**
	 * Where this learner should start on a given track: the published rung
	 * matching their placement, or the closest one below it.
	 *
	 * @param string $track Track slug.
	 * @param int    $rank  Placement rank (1-4).
	 * @return array|null The recommended rung.
	 */
	public static function start_rung( $track, $rank ) {
		$ladder = Mahan_Variants::track_ladder( $track );
		if ( empty( $ladder ) ) {
			return null;
		}
		$pick = null;
		foreach ( $ladder as $rung ) {
			if ( (int) $rung['level_rank'] <= (int) $rank ) {
				$pick = $rung;
			}
		}
		// Placed below the lowest rung on this track — start at the bottom
		// rather than sending them nowhere.
		return $pick ? $pick : $ladder[0];
	}
}
