<?php
/**
 * Options store + defaults + the schema-driven profile definition.
 *
 * All options are stored individually as wp_options under the `mahan_` prefix.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Settings {

	const PREFIX        = 'mahan_';
	const OPTION_GROUP  = 'mahan_settings';
	const SCHEMA_OPTION = 'mahan_profile_schema';

	/**
	 * Default values keyed by short name.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			// AI provider configuration.
			'ai_provider'         => 'anthropic',
			'anthropic_key'       => '',
			'anthropic_model'     => 'claude-sonnet-4-6',
			'openai_key'          => '',
			'openai_model'        => 'gpt-4o',
			'google_key'          => '',
			'google_model'        => 'gemini-1.5-flash',
			'max_tokens'          => 1024,
			'temperature'         => '0.7',

			// Prompts (support {{placeholders}} from the learner profile).
			'tutor_system_prompt' => self::default_tutor_prompt(),

			// Learning flow.
			'gate_enabled'        => 1,

			// Gamification.
			'xp_per_lesson'       => 20,
			'xp_per_exercise'     => 10,
			'streak_enabled'      => 1,
			'hearts_enabled'      => 0,
			'hearts_max'          => 5,
			'level_curve'         => 100,
			'badges_enabled'      => 1,
			'leaderboard_enabled' => 0,
			'level_titles'        => '',
			'certificate_enabled' => 1,
			'level_mode'          => 'linear',
			'xp_streak_bonus'     => 10,
			'daily_goal_default'  => 30,
			'streak_freeze_enabled' => 1,
			'freeze_earn_days'    => 7,
			'freeze_max'          => 2,

			// Adaptive review (spaced repetition of wrong answers).
			'review_enabled'      => 1,
			'review_xp'           => 5,

			// Appearance.
			'primary_color'       => '#4f46e5',
			'accent_color'        => '#22c55e',
			'theme'               => 'light',
			'custom_css'          => '',

			// Pages (auto-created on activation).
			'app_page_id'         => 0,

			// Email notifications.
			'emails_enabled'      => 1,
			'email_from_name'     => '',
			'email_from_email'    => '',
			'email_welcome'       => 1,
			'email_complete'      => 1,
			'email_badge'         => 0,
			'email_streak'        => 0,

			// Misc.
			'ai_cache_ttl'        => 0,
			'debug'               => 0,
		);

		if ( class_exists( 'Mahan_Emails' ) ) {
			$defaults = array_merge( $defaults, Mahan_Emails::default_templates() );
		}

		return $defaults;
	}

	/**
	 * Get an option by short key.
	 *
	 * @param string $key     Short key (without prefix).
	 * @param mixed  $default Optional explicit default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$defaults = self::defaults();
		if ( null === $default && array_key_exists( $key, $defaults ) ) {
			$default = $defaults[ $key ];
		}
		return get_option( self::PREFIX . $key, $default );
	}

	/**
	 * Update an option by short key.
	 */
	public static function set( $key, $value ) {
		update_option( self::PREFIX . $key, $value );
	}

	/**
	 * Ensure all default options exist (called on activation).
	 */
	public static function install_defaults() {
		foreach ( self::defaults() as $key => $value ) {
			if ( null === get_option( self::PREFIX . $key, null ) ) {
				add_option( self::PREFIX . $key, $value );
			}
		}
		if ( null === get_option( self::SCHEMA_OPTION, null ) ) {
			add_option( self::SCHEMA_OPTION, wp_json_encode( self::default_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * Resolve the active provider's model id.
	 */
	public static function active_model() {
		$provider = self::get( 'ai_provider', 'anthropic' );
		switch ( $provider ) {
			case 'openai':
				return self::get( 'openai_model' );
			case 'google':
				return self::get( 'google_model' );
			case 'anthropic':
			default:
				return self::get( 'anthropic_model' );
		}
	}

	/**
	 * Resolve the active provider's API key.
	 */
	public static function active_key() {
		$provider = self::get( 'ai_provider', 'anthropic' );
		switch ( $provider ) {
			case 'openai':
				return (string) self::get( 'openai_key' );
			case 'google':
				return (string) self::get( 'google_key' );
			case 'anthropic':
			default:
				return (string) self::get( 'anthropic_key' );
		}
	}

	/**
	 * Is the active provider configured with a key?
	 */
	public static function ai_ready() {
		return '' !== trim( self::active_key() );
	}

	/* ------------------------------------------------------------------ */
	/* Prompts                                                             */
	/* ------------------------------------------------------------------ */

	public static function default_tutor_prompt() {
		return "You are Mahan, a friendly, encouraging real-time AI tutor inside an online academy that teaches people how to use AI effectively in their daily work.\n\n"
			. "Learner profile:\n"
			. "- Name: {{name}}\n"
			. "- Role: {{role}}\n"
			. "- Company type: {{company_type}}\n"
			. "- AI experience level: {{ai_level}}\n"
			. "- Primary goal: {{primary_goal}}\n"
			. "- Tools they use daily: {{daily_tools}}\n\n"
			. "Guidelines:\n"
			. "- Adapt examples and tone to the learner's role, level, and goal.\n"
			. "- Be concise and practical. Prefer concrete, work-relevant examples over theory.\n"
			. "- When the learner is stuck, give a hint before the full answer.\n"
			. "- Encourage and motivate, Duolingo-style, but stay professional.\n"
			. "- Use short paragraphs, bullet points, and **bold** for key ideas.\n"
			. "- Only discuss the current lesson and how to apply AI at work. Politely redirect off-topic questions.";
	}

	/* ------------------------------------------------------------------ */
	/* Profile schema                                                      */
	/* ------------------------------------------------------------------ */

	public static function default_schema() {
		return array(
			'version' => 1,
			'fields'  => array(
				array(
					'key'      => 'role',
					'label'    => 'Your role',
					'type'     => 'select',
					'required' => true,
					'options'  => array(
						array( 'value' => 'sales', 'label' => 'Sales' ),
						array( 'value' => 'marketing', 'label' => 'Marketing' ),
						array( 'value' => 'operations', 'label' => 'Operations' ),
						array( 'value' => 'product', 'label' => 'Product' ),
						array( 'value' => 'engineering', 'label' => 'Engineering' ),
						array( 'value' => 'hr', 'label' => 'HR / People' ),
						array( 'value' => 'finance', 'label' => 'Finance' ),
						array( 'value' => 'founder', 'label' => 'Founder / CEO' ),
						array( 'value' => 'student', 'label' => 'Student' ),
						array( 'value' => 'other', 'label' => 'Other' ),
					),
				),
				array(
					'key'      => 'company_type',
					'label'    => 'Company type',
					'type'     => 'select',
					'required' => false,
					'options'  => array(
						array( 'value' => 'startup', 'label' => 'Startup' ),
						array( 'value' => 'sme', 'label' => 'Small / Medium business' ),
						array( 'value' => 'enterprise', 'label' => 'Enterprise' ),
						array( 'value' => 'agency', 'label' => 'Agency' ),
						array( 'value' => 'freelance', 'label' => 'Freelance / Solo' ),
					),
				),
				array(
					'key'      => 'ai_level',
					'label'    => 'Your AI experience',
					'type'     => 'select',
					'required' => true,
					'options'  => array(
						array( 'value' => 'beginner', 'label' => 'Beginner' ),
						array( 'value' => 'intermediate', 'label' => 'Intermediate' ),
						array( 'value' => 'advanced', 'label' => 'Advanced' ),
					),
				),
				array(
					'key'      => 'primary_goal',
					'label'    => 'What do you most want to improve?',
					'type'     => 'select',
					'required' => true,
					'options'  => array(
						array( 'value' => 'automate', 'label' => 'Automate repetitive tasks' ),
						array( 'value' => 'content', 'label' => 'Write content & copy faster' ),
						array( 'value' => 'analysis', 'label' => 'Analyze data & make decisions' ),
						array( 'value' => 'support', 'label' => 'Improve customer support' ),
						array( 'value' => 'coding', 'label' => 'Code & build faster' ),
						array( 'value' => 'learning', 'label' => 'Learn & research faster' ),
					),
				),
				array(
					'key'      => 'daily_tools',
					'label'    => 'Tools you use daily (comma separated)',
					'type'     => 'text',
					'required' => false,
				),
				array(
					'key'      => 'biggest_challenge',
					'label'    => 'Your biggest challenge right now',
					'type'     => 'textarea',
					'required' => false,
				),
			),
		);
	}

	public static function get_schema() {
		$raw = get_option( self::SCHEMA_OPTION, '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['fields'] ) ) {
			return $decoded;
		}
		return self::default_schema();
	}

	public static function set_schema( array $schema ) {
		update_option( self::SCHEMA_OPTION, wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
