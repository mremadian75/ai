<?php
/**
 * Admin area: menu, settings page (tabbed), asset loading, AI connection test.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_mahan_test_ai', array( __CLASS__, 'ajax_test_ai' ) );

		Mahan_Course_Meta::init();
		Mahan_Lesson_Meta::init();
		Mahan_Path_Meta::init();
		Mahan_Course_Builder::init();
		Mahan_AI_Author::init();
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                                */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_menu_page(
			__( 'Mahan Academy', 'mahan-academy' ),
			__( 'Mahan Academy', 'mahan-academy' ),
			'manage_options',
			'mahan-academy',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-welcome-learn-more',
			56
		);
		add_submenu_page(
			'mahan-academy',
			__( 'Dashboard', 'mahan-academy' ),
			__( 'Dashboard', 'mahan-academy' ),
			'manage_options',
			'mahan-academy',
			array( __CLASS__, 'render_dashboard' )
		);
		add_submenu_page(
			'mahan-academy',
			__( 'Settings', 'mahan-academy' ),
			__( 'Settings', 'mahan-academy' ),
			'manage_options',
			'mahan-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings registration                                               */
	/* ------------------------------------------------------------------ */

	public static function register_settings() {
		foreach ( array_keys( Mahan_Settings::defaults() ) as $key ) {
			register_setting(
				Mahan_Settings::OPTION_GROUP,
				Mahan_Settings::PREFIX . $key,
				array( 'sanitize_callback' => array( __CLASS__, 'sanitize_option' ) )
			);
		}
		// Profile schema saves with the same form.
		register_setting(
			Mahan_Settings::OPTION_GROUP,
			Mahan_Settings::SCHEMA_OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_schema' ) )
		);
	}

	/**
	 * One sanitizer to rule them all — dispatches by the option being saved.
	 *
	 * @param mixed $value Incoming value.
	 * @return mixed
	 */
	public static function sanitize_option( $value ) {
		$filter = current_filter(); // sanitize_option_mahan_xxx.
		$key    = preg_replace( '/^sanitize_option_' . preg_quote( Mahan_Settings::PREFIX, '/' ) . '/', '', $filter );

		$int_keys  = array( 'max_tokens', 'xp_per_lesson', 'xp_per_exercise', 'level_curve', 'hearts_max', 'ai_cache_ttl', 'app_page_id' );
		$bool_keys = array( 'gate_enabled', 'streak_enabled', 'hearts_enabled', 'debug', 'badges_enabled', 'leaderboard_enabled', 'certificate_enabled' );

		if ( in_array( $key, $int_keys, true ) ) {
			return absint( $value );
		}
		if ( in_array( $key, $bool_keys, true ) ) {
			return $value ? 1 : 0;
		}

		switch ( $key ) {
			case 'ai_provider':
				$v = sanitize_key( $value );
				return in_array( $v, array( 'anthropic', 'openai', 'google' ), true ) ? $v : 'anthropic';
			case 'theme':
				return 'dark' === $value ? 'dark' : 'light';
			case 'primary_color':
			case 'accent_color':
				$c = sanitize_hex_color( (string) $value );
				return $c ? $c : ( 'primary_color' === $key ? '#4f46e5' : '#22c55e' );
			case 'temperature':
				$t = (float) $value;
				$t = max( 0, min( 2, $t ) );
				return (string) $t;
			case 'custom_css':
				return self::sanitize_css( (string) $value );
			case 'tutor_system_prompt':
			case 'level_titles':
				return sanitize_textarea_field( wp_unslash( $value ) );
			default:
				// API keys, model ids, etc.
				return sanitize_text_field( wp_unslash( (string) $value ) );
		}
	}

	public static function sanitize_schema( $value ) {
		$decoded = json_decode( (string) wp_unslash( $value ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['fields'] ) ) {
			// Keep existing schema on invalid input.
			return get_option( Mahan_Settings::SCHEMA_OPTION, wp_json_encode( Mahan_Settings::default_schema() ) );
		}
		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function sanitize_css( $css ) {
		$css = preg_replace( '#</?\w+[^>]*>#', '', (string) $css );
		$css = str_replace( array( '<', '>' ), '', $css );
		return $css;
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_our = false;
		if ( $screen && in_array( $screen->post_type, array( Mahan_CPT::COURSE, Mahan_CPT::LESSON, Mahan_CPT::PATH ), true ) ) {
			$is_our = true;
		}
		if ( in_array( $hook, array( 'toplevel_page_mahan-academy', 'mahan-academy_page_mahan-settings' ), true ) ) {
			$is_our = true;
		}
		if ( ! $is_our ) {
			return;
		}

		wp_enqueue_style( 'mahan-admin', MAHAN_URL . 'assets/css/admin.css', array(), MAHAN_VERSION );
		wp_enqueue_script( 'mahan-admin', MAHAN_URL . 'assets/js/admin.js', array( 'jquery' ), MAHAN_VERSION, true );

		// Course Builder — only on the Course edit screen.
		if ( $screen && Mahan_CPT::COURSE === $screen->post_type && 'post' === $screen->base ) {
			wp_enqueue_style( 'mahan-course-builder', MAHAN_URL . 'assets/css/course-builder.css', array(), MAHAN_VERSION );
			wp_enqueue_script( 'mahan-course-builder', MAHAN_URL . 'assets/js/course-builder.js', array( 'jquery', 'jquery-ui-sortable' ), MAHAN_VERSION, true );
			wp_localize_script(
				'mahan-course-builder',
				'MahanCB',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Mahan_Course_Builder::NONCE ),
					'i18n'    => array(
						'addUnit'      => __( 'Add unit', 'mahan-academy' ),
						'addLesson'    => __( 'Add lesson', 'mahan-academy' ),
						'newUnit'      => __( 'New unit', 'mahan-academy' ),
						'unitName'     => __( 'Unit name', 'mahan-academy' ),
						'lessonTitle'  => __( 'Lesson title', 'mahan-academy' ),
						'lessons'      => __( 'lessons', 'mahan-academy' ),
						'exercises'    => __( 'exercises', 'mahan-academy' ),
						'type'         => __( 'Type', 'mahan-academy' ),
						'minutes'      => __( 'Min', 'mahan-academy' ),
						'reading'      => __( 'Reading', 'mahan-academy' ),
						'practice'     => __( 'Practice', 'mahan-academy' ),
						'video'        => __( 'Video', 'mahan-academy' ),
						'editContent'  => __( 'Edit content', 'mahan-academy' ),
						'duplicate'    => __( 'Duplicate', 'mahan-academy' ),
						'delete'       => __( 'Delete', 'mahan-academy' ),
						'dragUnit'     => __( 'Drag to reorder unit', 'mahan-academy' ),
						'dragLesson'   => __( 'Drag to reorder', 'mahan-academy' ),
						'deleteUnit'   => __( 'Delete empty unit', 'mahan-academy' ),
						'unitNotEmpty' => __( 'Move or delete its lessons first.', 'mahan-academy' ),
						'confirmDelete'=> __( 'Delete this lesson? It will be moved to Trash.', 'mahan-academy' ),
						'saved'        => __( 'Saved', 'mahan-academy' ),
						'deleted'      => __( 'Deleted', 'mahan-academy' ),
						'duplicated'   => __( 'Duplicated', 'mahan-academy' ),
						'lessonAdded'  => __( 'Lesson added', 'mahan-academy' ),
						'quiz'         => __( 'Quiz', 'mahan-academy' ),
						'unitQuiz'     => __( 'Unit quiz', 'mahan-academy' ),
						'noQuestions'  => __( 'No questions yet. Add one below.', 'mahan-academy' ),
						'addQuestion'  => __( 'Add question', 'mahan-academy' ),
						'passingScore' => __( 'Passing score (%)', 'mahan-academy' ),
						'quizXp'       => __( 'XP on pass (0 = auto)', 'mahan-academy' ),
						'question'     => __( 'Question', 'mahan-academy' ),
						'option'       => __( 'Option', 'mahan-academy' ),
						'addOption'    => __( 'Add option', 'mahan-academy' ),
						'correct'      => __( 'Correct', 'mahan-academy' ),
						'remove'       => __( 'Remove', 'mahan-academy' ),
						'answer'       => __( 'Answer', 'mahan-academy' ),
						'answerText'   => __( 'Expected answer', 'mahan-academy' ),
						'alsoAccept'   => __( 'Also accept', 'mahan-academy' ),
						'multiple_choice' => __( 'Multiple choice', 'mahan-academy' ),
						'true_false'   => __( 'True / False', 'mahan-academy' ),
						'fill_blank'   => __( 'Fill in the blank', 'mahan-academy' ),
						'true_'        => __( 'True', 'mahan-academy' ),
						'false_'       => __( 'False', 'mahan-academy' ),
						'save'         => __( 'Save quiz', 'mahan-academy' ),
						'cancel'       => __( 'Cancel', 'mahan-academy' ),
					),
				)
			);
		}

		// Learning Path course picker — on the Path edit screen.
		if ( $screen && Mahan_CPT::PATH === $screen->post_type && 'post' === $screen->base ) {
			wp_enqueue_script( 'mahan-path-admin', MAHAN_URL . 'assets/js/path-admin.js', array( 'jquery', 'jquery-ui-sortable' ), MAHAN_VERSION, true );
			wp_localize_script(
				'mahan-path-admin',
				'MahanPath',
				array(
					'i18n' => array(
						'choose'   => __( '— choose a course —', 'mahan-academy' ),
						'allAdded' => __( '— all courses added —', 'mahan-academy' ),
						'empty'    => __( 'No courses yet — add some below.', 'mahan-academy' ),
					),
				)
			);
		}

		// AI authoring assistant — on both Course and Lesson edit screens.
		if ( $screen && in_array( $screen->post_type, array( Mahan_CPT::COURSE, Mahan_CPT::LESSON ), true ) && 'post' === $screen->base ) {
			wp_enqueue_script( 'mahan-ai-author', MAHAN_URL . 'assets/js/ai-author.js', array( 'jquery', 'mahan-admin' ), MAHAN_VERSION, true );
			wp_localize_script(
				'mahan-ai-author',
				'MahanAIAuthor',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => Mahan_Settings::ai_ready() ? wp_create_nonce( Mahan_AI_Author::NONCE ) : '',
					'i18n'    => array(
						'genOutcomes'   => __( 'Generate with AI', 'mahan-academy' ),
						'genExercises'  => __( 'Generate exercises with AI', 'mahan-academy' ),
						'exercisesHint' => __( "from this lesson's content", 'mahan-academy' ),
						'copy'          => __( 'Copy', 'mahan-academy' ),
						'copied'        => __( 'Copied!', 'mahan-academy' ),
					),
				)
			);
		}

		wp_localize_script(
			'mahan-admin',
			'MahanAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mahan_admin' ),
				'i18n'    => array(
					'addExercise'     => __( 'Add exercise', 'mahan-academy' ),
					'remove'          => __( 'Remove', 'mahan-academy' ),
					'question'        => __( 'Question', 'mahan-academy' ),
					'type'            => __( 'Type', 'mahan-academy' ),
					'option'          => __( 'Option', 'mahan-academy' ),
					'addOption'       => __( 'Add option', 'mahan-academy' ),
					'correct'         => __( 'Correct', 'mahan-academy' ),
					'rubric'          => __( 'Rubric (how the AI should grade)', 'mahan-academy' ),
					'task'            => __( 'Task for the student', 'mahan-academy' ),
					'hint'            => __( 'Hint (optional)', 'mahan-academy' ),
					'xp'              => __( 'XP', 'mahan-academy' ),
					'multiple_choice' => __( 'Multiple choice', 'mahan-academy' ),
					'true_false'      => __( 'True / False', 'mahan-academy' ),
					'fill_blank'      => __( 'Fill in the blank', 'mahan-academy' ),
					'short_answer'    => __( 'Short answer (AI graded)', 'mahan-academy' ),
					'reflection'      => __( 'Reflection (AI graded)', 'mahan-academy' ),
					'prompt_task'     => __( 'Prompt-writing task (AI graded)', 'mahan-academy' ),
					'true_'           => __( 'True', 'mahan-academy' ),
					'false_'          => __( 'False', 'mahan-academy' ),
					'answer'          => __( 'Answer', 'mahan-academy' ),
					'answerText'      => __( 'The exact word/phrase for the blank', 'mahan-academy' ),
					'alsoAccept'      => __( 'Also accept (comma separated)', 'mahan-academy' ),
					'caseSensitive'   => __( 'Case sensitive', 'mahan-academy' ),
					'blankHint'       => __( 'Tip: use ___ (three underscores) in the question to mark the blank.', 'mahan-academy' ),
					'noExercises'     => __( 'No exercises yet. Add one to make this lesson interactive.', 'mahan-academy' ),
					'testing'         => __( 'Testing…', 'mahan-academy' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: test AI connection                                            */
	/* ------------------------------------------------------------------ */

	public static function ajax_test_ai() {
		check_ajax_referer( 'mahan_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ) );
		}
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : Mahan_Settings::get( 'ai_provider' );
		$res      = Mahan_AI::test_connection( $provider );
		if ( $res['ok'] ) {
			wp_send_json_success( array( 'message' => __( 'Connection successful!', 'mahan-academy' ) . ' (' . esc_html( trim( $res['text'] ) ) . ')' ) );
		}
		wp_send_json_error( array( 'message' => $res['error'] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard page                                                      */
	/* ------------------------------------------------------------------ */

	public static function render_dashboard() {
		$course_count = wp_count_posts( Mahan_CPT::COURSE );
		$lesson_count = wp_count_posts( Mahan_CPT::LESSON );
		$app_page     = (int) Mahan_Settings::get( 'app_page_id', 0 );
		$ai_ready     = Mahan_Settings::ai_ready();
		?>
		<div class="wrap mahan-admin-wrap">
			<h1><?php esc_html_e( 'Mahan Academy', 'mahan-academy' ); ?> <span class="mahan-ver">v<?php echo esc_html( MAHAN_VERSION ); ?></span></h1>
			<p class="mahan-tagline"><?php esc_html_e( 'A standalone AI-learning platform — Coursera structure, Duolingo practice, a real-time AI tutor.', 'mahan-academy' ); ?></p>

			<div class="mahan-cards">
				<div class="mahan-card">
					<span class="mahan-card-num"><?php echo esc_html( isset( $course_count->publish ) ? $course_count->publish : 0 ); ?></span>
					<span class="mahan-card-label"><?php esc_html_e( 'Published courses', 'mahan-academy' ); ?></span>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Mahan_CPT::COURSE ) ); ?>"><?php esc_html_e( 'Manage courses', 'mahan-academy' ); ?></a>
				</div>
				<div class="mahan-card">
					<span class="mahan-card-num"><?php echo esc_html( isset( $lesson_count->publish ) ? $lesson_count->publish : 0 ); ?></span>
					<span class="mahan-card-label"><?php esc_html_e( 'Published lessons', 'mahan-academy' ); ?></span>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Mahan_CPT::LESSON ) ); ?>"><?php esc_html_e( 'Manage lessons', 'mahan-academy' ); ?></a>
				</div>
				<div class="mahan-card">
					<span class="mahan-card-num mahan-<?php echo $ai_ready ? 'ok' : 'warn'; ?>"><?php echo $ai_ready ? '✓' : '!'; ?></span>
					<span class="mahan-card-label"><?php echo $ai_ready ? esc_html__( 'AI provider connected', 'mahan-academy' ) : esc_html__( 'AI not configured', 'mahan-academy' ); ?></span>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mahan-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'mahan-academy' ); ?></a>
				</div>
			</div>

			<h2><?php esc_html_e( 'Getting started', 'mahan-academy' ); ?></h2>
			<ol class="mahan-steps">
				<li><?php esc_html_e( 'Add your AI provider API key in Settings (Anthropic, OpenAI, or Google).', 'mahan-academy' ); ?></li>
				<li><?php esc_html_e( 'Create a Course, then add Lessons and assign them to the course.', 'mahan-academy' ); ?></li>
				<li><?php esc_html_e( 'Add interactive exercises to lessons to make them Duolingo-style.', 'mahan-academy' ); ?></li>
				<li>
					<?php
					if ( $app_page ) {
						printf(
							/* translators: %s: link to the academy page */
							esc_html__( 'Your academy page is ready: %s', 'mahan-academy' ),
							'<a href="' . esc_url( get_permalink( $app_page ) ) . '" target="_blank">' . esc_html( get_the_title( $app_page ) ) . '</a>'
						);
					} else {
						printf(
							/* translators: %s: shortcode */
							esc_html__( 'Place the shortcode %s on any page to render the academy.', 'mahan-academy' ),
							'<code>[mahan_academy]</code>'
						);
					}
					?>
				</li>
			</ol>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Settings page                                                       */
	/* ------------------------------------------------------------------ */

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$g = function ( $k ) {
			return Mahan_Settings::get( $k );
		};
		?>
		<div class="wrap mahan-admin-wrap">
			<h1><?php esc_html_e( 'Mahan Academy — Settings', 'mahan-academy' ); ?></h1>
			<h2 class="nav-tab-wrapper mahan-tabs">
				<a href="#tab-ai" class="nav-tab nav-tab-active"><?php esc_html_e( 'AI Provider', 'mahan-academy' ); ?></a>
				<a href="#tab-game" class="nav-tab"><?php esc_html_e( 'Gamification', 'mahan-academy' ); ?></a>
				<a href="#tab-appearance" class="nav-tab"><?php esc_html_e( 'Appearance', 'mahan-academy' ); ?></a>
				<a href="#tab-profile" class="nav-tab"><?php esc_html_e( 'Profile Form', 'mahan-academy' ); ?></a>
				<a href="#tab-advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'mahan-academy' ); ?></a>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( Mahan_Settings::OPTION_GROUP ); ?>

				<!-- AI PROVIDER -->
				<div class="mahan-tab-panel" id="tab-ai">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_ai_provider"><?php esc_html_e( 'Active provider', 'mahan-academy' ); ?></label></th>
							<td>
								<select id="mahan_ai_provider" name="mahan_ai_provider">
									<?php
									$providers = array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'google' => 'Google (Gemini)' );
									foreach ( $providers as $v => $l ) {
										printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $g( 'ai_provider' ), $v, false ), esc_html( $l ) );
									}
									?>
								</select>
								<button type="button" class="button" id="mahan-test-ai"><?php esc_html_e( 'Test connection', 'mahan-academy' ); ?></button>
								<span id="mahan-test-result" class="mahan-test-result"></span>
								<p class="description"><?php esc_html_e( 'The tutor and AI grading use this provider. Save your key first, then test.', 'mahan-academy' ); ?></p>
							</td>
						</tr>
					</table>

					<h3>Anthropic (Claude)</h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_anthropic_key"><?php esc_html_e( 'API key', 'mahan-academy' ); ?></label></th>
							<td><input type="password" class="regular-text" id="mahan_anthropic_key" name="mahan_anthropic_key" value="<?php echo esc_attr( $g( 'anthropic_key' ) ); ?>" autocomplete="new-password" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_anthropic_model"><?php esc_html_e( 'Model', 'mahan-academy' ); ?></label></th>
							<td><input type="text" class="regular-text" id="mahan_anthropic_model" name="mahan_anthropic_model" value="<?php echo esc_attr( $g( 'anthropic_model' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'e.g. claude-sonnet-4-6, claude-opus-4-8, claude-haiku-4-5', 'mahan-academy' ); ?></p></td>
						</tr>
					</table>

					<h3>OpenAI (GPT)</h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_openai_key"><?php esc_html_e( 'API key', 'mahan-academy' ); ?></label></th>
							<td><input type="password" class="regular-text" id="mahan_openai_key" name="mahan_openai_key" value="<?php echo esc_attr( $g( 'openai_key' ) ); ?>" autocomplete="new-password" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_openai_model"><?php esc_html_e( 'Model', 'mahan-academy' ); ?></label></th>
							<td><input type="text" class="regular-text" id="mahan_openai_model" name="mahan_openai_model" value="<?php echo esc_attr( $g( 'openai_model' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'e.g. gpt-4o, gpt-4o-mini', 'mahan-academy' ); ?></p></td>
						</tr>
					</table>

					<h3>Google (Gemini)</h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_google_key"><?php esc_html_e( 'API key', 'mahan-academy' ); ?></label></th>
							<td><input type="password" class="regular-text" id="mahan_google_key" name="mahan_google_key" value="<?php echo esc_attr( $g( 'google_key' ) ); ?>" autocomplete="new-password" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_google_model"><?php esc_html_e( 'Model', 'mahan-academy' ); ?></label></th>
							<td><input type="text" class="regular-text" id="mahan_google_model" name="mahan_google_model" value="<?php echo esc_attr( $g( 'google_model' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'e.g. gemini-1.5-flash, gemini-1.5-pro', 'mahan-academy' ); ?></p></td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Generation', 'mahan-academy' ); ?></h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_max_tokens"><?php esc_html_e( 'Max tokens', 'mahan-academy' ); ?></label></th>
							<td><input type="number" min="64" class="small-text" id="mahan_max_tokens" name="mahan_max_tokens" value="<?php echo esc_attr( $g( 'max_tokens' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_temperature"><?php esc_html_e( 'Temperature', 'mahan-academy' ); ?></label></th>
							<td><input type="number" step="0.1" min="0" max="2" class="small-text" id="mahan_temperature" name="mahan_temperature" value="<?php echo esc_attr( $g( 'temperature' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_tutor_system_prompt"><?php esc_html_e( 'Tutor system prompt', 'mahan-academy' ); ?></label></th>
							<td>
								<textarea id="mahan_tutor_system_prompt" name="mahan_tutor_system_prompt" rows="10" class="large-text code"><?php echo esc_textarea( $g( 'tutor_system_prompt' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Placeholders: {{name}}, {{role}}, {{company_type}}, {{ai_level}}, {{primary_goal}}, {{daily_tools}} — filled from the learner profile.', 'mahan-academy' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- GAMIFICATION -->
				<div class="mahan-tab-panel" id="tab-game" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_xp_per_lesson"><?php esc_html_e( 'XP per lesson', 'mahan-academy' ); ?></label></th>
							<td><input type="number" min="0" class="small-text" id="mahan_xp_per_lesson" name="mahan_xp_per_lesson" value="<?php echo esc_attr( $g( 'xp_per_lesson' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_xp_per_exercise"><?php esc_html_e( 'XP per exercise', 'mahan-academy' ); ?></label></th>
							<td><input type="number" min="0" class="small-text" id="mahan_xp_per_exercise" name="mahan_xp_per_exercise" value="<?php echo esc_attr( $g( 'xp_per_exercise' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_level_curve"><?php esc_html_e( 'XP per level', 'mahan-academy' ); ?></label></th>
							<td><input type="number" min="10" class="small-text" id="mahan_level_curve" name="mahan_level_curve" value="<?php echo esc_attr( $g( 'level_curve' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Learners gain a level for every this-many XP.', 'mahan-academy' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Daily streak', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_streak_enabled" value="0" />
								<label><input type="checkbox" name="mahan_streak_enabled" value="1" <?php checked( (int) $g( 'streak_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Track daily learning streaks', 'mahan-academy' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Achievements', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_badges_enabled" value="0" />
								<label><input type="checkbox" name="mahan_badges_enabled" value="1" <?php checked( (int) $g( 'badges_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Award badges for milestones (lessons, courses, streaks, levels)', 'mahan-academy' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Leaderboard', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_leaderboard_enabled" value="0" />
								<label><input type="checkbox" name="mahan_leaderboard_enabled" value="1" <?php checked( (int) $g( 'leaderboard_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Show a public XP leaderboard (top 20 learners)', 'mahan-academy' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Certificates', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_certificate_enabled" value="0" />
								<label><input type="checkbox" name="mahan_certificate_enabled" value="1" <?php checked( (int) $g( 'certificate_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Enable completion certificates (per-course toggle in the course editor)', 'mahan-academy' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_level_titles"><?php esc_html_e( 'Level titles', 'mahan-academy' ); ?></label></th>
							<td>
								<textarea id="mahan_level_titles" name="mahan_level_titles" rows="5" class="large-text code" placeholder="Novice&#10;Explorer&#10;Practitioner&#10;Specialist&#10;Expert"><?php echo esc_textarea( $g( 'level_titles' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optional. One title per line, mapped to levels 1, 2, 3… The last title is reused for higher levels. Leave empty to show "Level N".', 'mahan-academy' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- APPEARANCE -->
				<div class="mahan-tab-panel" id="tab-appearance" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_primary_color"><?php esc_html_e( 'Primary color', 'mahan-academy' ); ?></label></th>
							<td><input type="text" id="mahan_primary_color" name="mahan_primary_color" value="<?php echo esc_attr( $g( 'primary_color' ) ); ?>" class="mahan-color regular-text" placeholder="#4f46e5" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_accent_color"><?php esc_html_e( 'Accent color', 'mahan-academy' ); ?></label></th>
							<td><input type="text" id="mahan_accent_color" name="mahan_accent_color" value="<?php echo esc_attr( $g( 'accent_color' ) ); ?>" class="mahan-color regular-text" placeholder="#22c55e" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_theme"><?php esc_html_e( 'Theme', 'mahan-academy' ); ?></label></th>
							<td>
								<select id="mahan_theme" name="mahan_theme">
									<option value="light" <?php selected( $g( 'theme' ), 'light' ); ?>><?php esc_html_e( 'Light', 'mahan-academy' ); ?></option>
									<option value="dark" <?php selected( $g( 'theme' ), 'dark' ); ?>><?php esc_html_e( 'Dark', 'mahan-academy' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_custom_css"><?php esc_html_e( 'Custom CSS', 'mahan-academy' ); ?></label></th>
							<td><textarea id="mahan_custom_css" name="mahan_custom_css" rows="7" class="large-text code"><?php echo esc_textarea( $g( 'custom_css' ) ); ?></textarea></td>
						</tr>
					</table>
				</div>

				<!-- PROFILE -->
				<div class="mahan-tab-panel" id="tab-profile" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Profile gate', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_gate_enabled" value="0" />
								<label><input type="checkbox" name="mahan_gate_enabled" value="1" <?php checked( (int) $g( 'gate_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Ask learners to complete their profile before their first lesson', 'mahan-academy' ); ?></label>
							</td>
						</tr>
					</table>
					<h3><?php esc_html_e( 'Profile form schema (JSON)', 'mahan-academy' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Defines the personalization questions. Each field: key, label, type (text|textarea|select|multiselect), required, options.', 'mahan-academy' ); ?></p>
					<textarea name="<?php echo esc_attr( Mahan_Settings::SCHEMA_OPTION ); ?>" rows="16" class="large-text code"><?php echo esc_textarea( wp_json_encode( Mahan_Settings::get_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
				</div>

				<!-- ADVANCED -->
				<div class="mahan-tab-panel" id="tab-advanced" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_app_page_id"><?php esc_html_e( 'Academy page', 'mahan-academy' ); ?></label></th>
							<td>
								<?php
								wp_dropdown_pages(
									array(
										'name'              => 'mahan_app_page_id',
										'id'                => 'mahan_app_page_id',
										'selected'          => (int) $g( 'app_page_id' ),
										'show_option_none'  => __( '— None —', 'mahan-academy' ),
										'option_none_value' => 0,
									)
								);
								?>
								<p class="description"><?php esc_html_e( 'The page that contains the [mahan_academy] shortcode.', 'mahan-academy' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Debug logging', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_debug" value="0" />
								<label><input type="checkbox" name="mahan_debug" value="1" <?php checked( (int) $g( 'debug' ), 1 ); ?> /> <?php esc_html_e( 'Write logs to the PHP error log', 'mahan-academy' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
