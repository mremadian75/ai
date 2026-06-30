<?php
/**
 * Schema-driven learner profile stored in user meta. The profile feeds the AI
 * tutor's system prompt and the personalization context used during grading.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Profile {

	const META_KEY = 'mahan_profile';

	/* ------------------------------------------------------------------ */
	/* Read / write                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Return the user's profile as an associative array (always an array).
	 */
	public static function get_profile( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_KEY, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Sanitize against the active schema and save.
	 *
	 * @param int   $user_id User id.
	 * @param array $input   Raw input.
	 * @return array Stored profile.
	 */
	public static function save_profile( $user_id, $input ) {
		$schema = self::get_schema();
		$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();

		$out = array();
		foreach ( $fields as $f ) {
			$key  = isset( $f['key'] ) ? (string) $f['key'] : '';
			$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
			if ( '' === $key || ! array_key_exists( $key, (array) $input ) ) {
				continue;
			}
			$val = $input[ $key ];

			switch ( $type ) {
				case 'textarea':
					$out[ $key ] = sanitize_textarea_field( (string) $val );
					break;
				case 'select':
					$out[ $key ] = sanitize_text_field( (string) $val );
					break;
				case 'multiselect':
					$arr = is_array( $val ) ? $val : array();
					$out[ $key ] = array_values( array_map( 'sanitize_text_field', $arr ) );
					break;
				case 'text':
				default:
					$out[ $key ] = sanitize_text_field( (string) $val );
					break;
			}
		}

		update_user_meta( (int) $user_id, self::META_KEY, $out );
		return $out;
	}

	public static function get_schema() {
		return Mahan_Settings::get_schema();
	}

	/**
	 * Has the learner completed every required field?
	 *
	 * @param array $profile Profile data.
	 * @return bool
	 */
	public static function is_complete( $profile ) {
		$schema = self::get_schema();
		$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
		foreach ( $fields as $f ) {
			if ( empty( $f['required'] ) ) {
				continue;
			}
			$key = isset( $f['key'] ) ? (string) $f['key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$v = isset( $profile[ $key ] ) ? $profile[ $key ] : '';
			if ( is_array( $v ) ) {
				if ( empty( $v ) ) {
					return false;
				}
			} elseif ( '' === trim( (string) $v ) ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Placeholders / templating                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a {{placeholder}} => display-value map for a user.
	 *
	 * Values are turned into human-readable labels (using the schema's
	 * option labels for select/multiselect fields) so the AI sees friendly text.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function placeholder_map( $user_id ) {
		$user_id = (int) $user_id;
		$profile = self::get_profile( $user_id );
		$schema  = self::get_schema();
		$fields  = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();

		$map = array();
		$user = $user_id ? get_userdata( $user_id ) : null;
		$map['name']  = $user ? $user->display_name : '';
		$map['email'] = $user ? $user->user_email   : '';

		foreach ( $fields as $f ) {
			$key  = isset( $f['key'] ) ? (string) $f['key'] : '';
			$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
			if ( '' === $key ) {
				continue;
			}
			$value = isset( $profile[ $key ] ) ? $profile[ $key ] : '';

			if ( 'select' === $type || 'multiselect' === $type ) {
				$labels = self::option_labels( $f );
				if ( is_array( $value ) ) {
					$mapped = array();
					foreach ( $value as $v ) {
						$mapped[] = isset( $labels[ $v ] ) ? $labels[ $v ] : $v;
					}
					$map[ $key ] = implode( ', ', array_filter( $mapped ) );
				} else {
					$map[ $key ] = isset( $labels[ $value ] ) ? $labels[ $value ] : (string) $value;
				}
			} else {
				$map[ $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}

			// Empty default for cleanliness in the prompt.
			if ( '' === trim( (string) $map[ $key ] ) ) {
				$map[ $key ] = '(not provided)';
			}
		}

		return $map;
	}

	/**
	 * Render a template string by substituting {{placeholders}}.
	 *
	 * @param string $template Template.
	 * @param int    $user_id  User id.
	 * @return string
	 */
	public static function render_template( $template, $user_id ) {
		return Mahan_Utils::render_placeholders( (string) $template, self::placeholder_map( (int) $user_id ) );
	}

	private static function option_labels( $field ) {
		$labels = array();
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return $labels;
		}
		foreach ( $field['options'] as $o ) {
			if ( is_string( $o ) ) {
				$labels[ $o ] = $o;
			} elseif ( is_array( $o ) && isset( $o['value'] ) ) {
				$labels[ $o['value'] ] = isset( $o['label'] ) ? $o['label'] : $o['value'];
			}
		}
		return $labels;
	}
}
