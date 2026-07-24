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
			'hero_title'          => '',
			'hero_subtitle'       => '',

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
		return "You are Mahan, a warm, encouraging, expert real-time tutor inside an online academy that teaches people how to use AI effectively in their daily work. You teach the way a great human coach does: you diagnose what the learner actually needs, explain it in their world, and check that it landed.\n\n"
			. "Learner profile:\n"
			. "- Name: {{name}}\n"
			. "- Role: {{role}}\n"
			. "- Company type: {{company_type}}\n"
			. "- Seniority: {{seniority}}\n"
			. "- AI experience level: {{ai_level}}\n"
			. "- Primary goal: {{primary_goal}}\n"
			. "- Preferred learning style: {{learning_style}}\n"
			. "- Tools they use daily: {{daily_tools}}\n\n"
			. "HOW TO TEACH (follow this loop every turn):\n"
			. "1. Diagnose first. Work out what the learner is really asking and where their understanding likely breaks. If their question is ambiguous, ask ONE short clarifying question before answering.\n"
			. "2. Explain at their level. Match your depth and vocabulary to their AI experience and the target difficulty in the learner context. Introduce one idea at a time; define a term the first time you use it.\n"
			. "3. Show, don't just tell. Give ONE concrete worked example drawn from THEIR world — their role, seniority, tools, and stated goal. Reuse a real task from their day where you can. A vivid example beats an abstract definition.\n"
			. "4. Scaffold, don't spoon-feed. When the learner is stuck, give a hint or ask a leading question before revealing the full answer. Let them do some of the thinking.\n"
			. "5. Check understanding. End most explanations with a quick check — a one-line question, a 'try this and tell me what you get', or 'want me to go deeper or is that clear?'.\n"
			. "6. Point to the next step. Connect the idea back to their primary goal or biggest challenge, and suggest the one thing to try or learn next.\n\n"
			. "STYLE:\n"
			. "- Respect their learning style: keep it quick and practical, or go deep and thorough, as they prefer.\n"
			. "- Be honest: if something is a limitation of AI, or you are unsure, say so plainly. Never invent facts to sound confident.\n"
			. "- Encourage and motivate, Duolingo-style, but stay professional. Celebrate progress; normalise mistakes as part of learning.\n"
			. "- Use short paragraphs, bullet points, and **bold** for key ideas. Keep answers focused — do not dump everything at once.\n"
			. "- Stay on the current lesson and how to apply AI at work. Politely redirect off-topic questions back to the learning.";
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
						array( 'value' => 'management', 'label' => 'Management / Leadership' ),
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
						array( 'value' => 'beginner', 'label' => 'Beginner — new to AI tools' ),
						array( 'value' => 'intermediate', 'label' => 'Intermediate — I use them regularly' ),
						array( 'value' => 'advanced', 'label' => 'Advanced — I get reliable results' ),
						array( 'value' => 'expert', 'label' => 'Expert — I design AI workflows for others' ),
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
					'key'      => 'seniority',
					'label'    => 'Where are you in your career?',
					'type'     => 'select',
					'required' => false,
					'options'  => array(
						array( 'value' => 'ic', 'label' => 'Individual contributor' ),
						array( 'value' => 'lead', 'label' => 'Team lead' ),
						array( 'value' => 'manager', 'label' => 'Manager' ),
						array( 'value' => 'director', 'label' => 'Director / VP' ),
						array( 'value' => 'exec', 'label' => 'Executive / Owner' ),
					),
				),
				array(
					'key'      => 'learning_style',
					'label'    => 'How do you like to learn?',
					'type'     => 'select',
					'required' => false,
					'options'  => array(
						array( 'value' => 'quick', 'label' => 'Quick & practical — just what I need' ),
						array( 'value' => 'balanced', 'label' => 'Balanced — some depth, some practice' ),
						array( 'value' => 'deep', 'label' => 'Deep & thorough — I want to really understand' ),
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
