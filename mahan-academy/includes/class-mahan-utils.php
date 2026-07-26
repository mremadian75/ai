<?php
/**
 * Small shared helpers used across the plugin.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Utils {

	/**
	 * MySQL-formatted current time in WordPress's site timezone.
	 */
	public static function now_mysql() {
		return current_time( 'mysql' );
	}

	/**
	 * Today's date (YYYY-MM-DD) in the site's timezone.
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Date difference in whole days between two YYYY-MM-DD strings.
	 *
	 * @param string $earlier Earlier date.
	 * @param string $later   Later date.
	 * @return int
	 */
	public static function date_diff_days( $earlier, $later ) {
		$a = strtotime( (string) $earlier . ' 00:00:00' );
		$b = strtotime( (string) $later . ' 00:00:00' );
		if ( ! $a || ! $b ) {
			return 0;
		}
		return (int) floor( ( $b - $a ) / DAY_IN_SECONDS );
	}

	/**
	 * Trimmed string meta with a fallback.
	 */
	public static function meta_str( $post_id, $key, $default = '' ) {
		$raw = get_post_meta( (int) $post_id, $key, true );
		if ( is_array( $raw ) ) {
			return $default;
		}
		$raw = (string) $raw;
		return '' === trim( $raw ) ? $default : $raw;
	}

	/**
	 * Integer meta with a fallback.
	 */
	public static function meta_int( $post_id, $key, $default = 0 ) {
		$raw = get_post_meta( (int) $post_id, $key, true );
		if ( '' === $raw || null === $raw || is_array( $raw ) ) {
			return (int) $default;
		}
		return (int) $raw;
	}

	/**
	 * Pull the first JSON object out of a model response, even when wrapped
	 * in code fences or surrounded by chat text.
	 *
	 * @param string $text Raw model output.
	 * @return array|null
	 */
	public static function extract_json( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return null;
		}
		// Strip ``` fences.
		$text = preg_replace( '/^\s*```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', $text );
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Try to find the first balanced {...} block. The scanner is
		// string-aware: braces inside JSON string values (and escaped quotes)
		// are ignored, so a value like "close with }" doesn't end the object
		// early. It keeps scanning past a candidate that fails to decode.
		$len = strlen( $text );
		for ( $start = strpos( $text, '{' ); false !== $start; $start = strpos( $text, '{', $start + 1 ) ) {
			$depth     = 0;
			$in_string = false;
			$escaped   = false;
			for ( $i = $start; $i < $len; $i++ ) {
				$c = $text[ $i ];
				if ( $in_string ) {
					if ( $escaped ) {
						$escaped = false;
					} elseif ( '\\' === $c ) {
						$escaped = true;
					} elseif ( '"' === $c ) {
						$in_string = false;
					}
					continue;
				}
				if ( '"' === $c ) {
					$in_string = true;
				} elseif ( '{' === $c ) {
					$depth++;
				} elseif ( '}' === $c ) {
					$depth--;
					if ( 0 === $depth ) {
						$decoded = json_decode( substr( $text, $start, $i - $start + 1 ), true );
						if ( is_array( $decoded ) ) {
							return $decoded;
						}
						break; // This candidate failed; try the next '{'.
					}
				}
			}
		}
		return null;
	}

	/**
	 * Replace {{placeholder}} tokens in a string from a map.
	 *
	 * @param string $template Template string.
	 * @param array  $map      key => value.
	 * @return string
	 */
	public static function render_placeholders( $template, $map ) {
		$out = (string) $template;
		foreach ( (array) $map as $k => $v ) {
			$out = str_replace( '{{' . $k . '}}', is_scalar( $v ) ? (string) $v : '', $out );
		}
		// Empty any unfilled tokens so the AI doesn't see literal braces.
		$out = preg_replace( '/\{\{[a-z0-9_]+\}\}/i', '', $out );
		return $out;
	}

	/**
	 * Term names, ready for a client that renders them as text.
	 *
	 * WordPress stores taxonomy names HTML-escaped — `sanitize_term()` runs the
	 * name through `esc_html()` on the way into the database — because core
	 * prints them straight into markup. Our SPA sets them with `textContent`,
	 * so the stored form arrives as the literal characters `&amp;` and a topic
	 * called "Email & writing" renders as "Email &amp; writing" on the lesson
	 * chip and in the tutor's suggested questions.
	 *
	 * Decoding at the JSON boundary is the fix: the value is text by the time
	 * it leaves, and nothing downstream re-encodes it.
	 *
	 * @param string[] $names Raw term names.
	 * @return string[]
	 */
	public static function decode_term_names( $names ) {
		$out = array();
		foreach ( (array) $names as $name ) {
			$out[] = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * The order a question's options are shown in for a given sitting.
	 *
	 * Authored question banks drift toward putting the right answer in the same
	 * slot — measured across this plugin's own catalog, 77% of multiple-choice
	 * answers sat in option B, which means "always tap the second one" scored
	 * 77% against a 70% pass mark. Permuting at serve time makes position carry
	 * no information however the data is written.
	 *
	 * The permutation is derived from (key, seed) rather than stored, so
	 * grading can reconstruct it with no server-side state between serving a
	 * question and receiving its answer.
	 *
	 * @param string $key   Question key (so two questions in one sitting do not
	 *                      share a permutation).
	 * @param int    $seed  Sitting seed.
	 * @param int    $count Number of options.
	 * @return int[] Original indices, in display order.
	 */
	public static function option_order( $key, $seed, $count ) {
		$idx = range( 0, max( 0, (int) $count - 1 ) );
		if ( $count < 2 ) {
			return $idx;
		}
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
}
