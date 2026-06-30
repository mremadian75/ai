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
		// Try to find the first {...} block.
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}
		$depth = 0;
		$len   = strlen( $text );
		for ( $i = $start; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( '{' === $c ) {
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					$candidate = substr( $text, $start, $i - $start + 1 );
					$decoded   = json_decode( $candidate, true );
					if ( is_array( $decoded ) ) {
						return $decoded;
					}
					return null;
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
}
