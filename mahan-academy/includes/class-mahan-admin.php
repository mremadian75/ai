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
		add_action( 'admin_post_mahan_export_csv', array( 'Mahan_Reports', 'export_csv' ) );
		add_action( 'admin_post_mahan_export_certs', array( 'Mahan_Reports', 'export_certificates_csv' ) );
		add_action( 'admin_post_mahan_seed_install', array( __CLASS__, 'handle_seed_install' ) );

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
			__( 'Reports', 'mahan-academy' ),
			__( 'Reports', 'mahan-academy' ),
			'manage_options',
			'mahan-reports',
			array( __CLASS__, 'render_reports' )
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

		// core options.php calls update_option() for EVERY registered option
		// in the group, including ones with no field on the submitted form
		// (value === null). Preserve those (current value, else the default)
		// instead of resetting them to 0/''.
		if ( null === $value ) {
			$defaults = Mahan_Settings::defaults();
			$fallback = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
			return Mahan_Settings::get( $key, $fallback );
		}

		// core options.php already wp_unslash()es posted values before this
		// filter runs, so we must NOT unslash again (it would strip
		// backslashes the admin actually typed).
		$int_keys  = array( 'max_tokens', 'xp_per_lesson', 'xp_per_exercise', 'level_curve', 'hearts_max', 'ai_cache_ttl', 'app_page_id', 'xp_streak_bonus', 'daily_goal_default', 'freeze_earn_days', 'freeze_max', 'review_xp' );
		$bool_keys = array( 'gate_enabled', 'streak_enabled', 'hearts_enabled', 'debug', 'badges_enabled', 'leaderboard_enabled', 'certificate_enabled', 'emails_enabled', 'email_welcome', 'email_complete', 'email_badge', 'email_streak', 'streak_freeze_enabled', 'review_enabled', 'learner_language' );

		if ( in_array( $key, $int_keys, true ) ) {
			return absint( $value );
		}
		if ( in_array( $key, $bool_keys, true ) ) {
			return $value ? 1 : 0;
		}
		if ( '_body' === substr( $key, -5 ) ) {
			return wp_kses_post( (string) $value );
		}
		if ( '_subject' === substr( $key, -8 ) ) {
			return sanitize_text_field( (string) $value );
		}
		if ( 'email_from_email' === $key ) {
			return sanitize_email( (string) $value );
		}

		switch ( $key ) {
			case 'ai_provider':
				$v = sanitize_key( $value );
				return in_array( $v, array( 'anthropic', 'openai', 'google' ), true ) ? $v : 'anthropic';
			case 'level_mode':
				return 'progressive' === $value ? 'progressive' : 'linear';
			case 'theme':
				return 'dark' === $value ? 'dark' : 'light';
			case 'default_language':
				// '' is a real answer here: follow whatever locale the site is
				// set to. Anything we can't actually serve resolves to that too.
				return Mahan_I18n::is_supported( $value ) ? (string) $value : '';
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
				return sanitize_textarea_field( (string) $value );
			default:
				// API keys, model ids, etc.
				return sanitize_text_field( (string) $value );
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
		if ( in_array( $hook, array( 'toplevel_page_mahan-academy', 'mahan-academy_page_mahan-settings', 'mahan-academy_page_mahan-reports' ), true ) ) {
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
			// admin.js renders this via .text(), so send plain text (stripped
			// of any tags) — esc_html() here would double-encode entities.
			$echo = wp_strip_all_tags( trim( (string) $res['text'] ) );
			wp_send_json_success( array( 'message' => __( 'Connection successful!', 'mahan-academy' ) . ' (' . $echo . ')' ) );
		}
		wp_send_json_error( array( 'message' => $res['error'] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard page                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Install the curated starter content (categories, topics, courses, quizzes,
	 * and bundles) on demand. Idempotent — safe to run more than once.
	 */
	public static function handle_seed_install() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mahan-academy' ) );
		}
		check_admin_referer( 'mahan_seed_install' );

		$result = Mahan_Seed::install();

		$redirect = add_query_arg(
			array(
				'page'          => 'mahan-academy',
				'mahan_seeded'  => 1,
				'seeded_c'      => (int) $result['courses'],
				'seeded_l'      => (int) $result['lessons'],
				'seeded_q'      => (int) $result['quizzes'],
				'seeded_b'      => (int) $result['bundles'],
				'seeded_skip'   => (int) $result['skipped'],
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function render_dashboard() {
		$course_count = wp_count_posts( Mahan_CPT::COURSE );
		$lesson_count = wp_count_posts( Mahan_CPT::LESSON );
		$app_page     = (int) Mahan_Settings::get( 'app_page_id', 0 );
		$ai_ready     = Mahan_Settings::ai_ready();

		// Confirmation notice after a starter-content install.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mahan_seeded'] ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$sc = isset( $_GET['seeded_c'] ) ? (int) $_GET['seeded_c'] : 0;
			$sl = isset( $_GET['seeded_l'] ) ? (int) $_GET['seeded_l'] : 0;
			$sq = isset( $_GET['seeded_q'] ) ? (int) $_GET['seeded_q'] : 0;
			$sb = isset( $_GET['seeded_b'] ) ? (int) $_GET['seeded_b'] : 0;
			$sk = isset( $_GET['seeded_skip'] ) ? (int) $_GET['seeded_skip'] : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$msg = ( $sc > 0 )
				? sprintf(
					/* translators: 1: courses, 2: lessons, 3: quizzes, 4: bundles. */
					esc_html__( 'Starter content installed: %1$d courses, %2$d lessons, %3$d quizzes, and %4$d bundles. Open your academy page to explore.', 'mahan-academy' ),
					$sc, $sl, $sq, $sb
				)
				: esc_html__( 'Starter content is already installed — nothing new to add.', 'mahan-academy' );
			if ( $sk > 0 && $sc > 0 ) {
				/* translators: %d: number of existing courses skipped. */
				$msg .= ' ' . sprintf( esc_html__( '(%d existing course(s) were left untouched.)', 'mahan-academy' ), $sk );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
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

			<?php
			$seed_installed = Mahan_Seed::is_installed();
			$seed_total     = count( Mahan_Seed::courses_data() );
			?>
			<div class="mahan-seed-box">
				<h2><?php esc_html_e( 'Starter content library', 'mahan-academy' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Install a ready-made catalog of professionally written courses — Prompt Engineering, Machine Learning, Generative AI, and AI for Productivity — organized into categories and Coursera-style bundles, with interactive exercises and unit quizzes. Great for launching quickly or as a template to adapt.', 'mahan-academy' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<input type="hidden" name="action" value="mahan_seed_install" />
					<?php wp_nonce_field( 'mahan_seed_install' ); ?>
					<?php if ( $seed_installed ) : ?>
						<span class="mahan-ok" style="font-weight:600">✓ <?php esc_html_e( 'Installed', 'mahan-academy' ); ?></span>
						<button type="submit" class="button button-secondary" style="margin-left:8px">
							<?php esc_html_e( 'Re-check / add new courses', 'mahan-academy' ); ?>
						</button>
						<span class="description" style="margin-left:8px"><?php esc_html_e( 'Re-running never duplicates or overwrites your content.', 'mahan-academy' ); ?></span>
					<?php else : ?>
						<button type="submit" class="button button-primary">
							<?php
							/* translators: %d: number of courses that will be installed. */
							echo esc_html( sprintf( _n( 'Install %d starter course', 'Install %d starter courses', $seed_total, 'mahan-academy' ), $seed_total ) );
							?>
						</button>
						<span class="description" style="margin-left:8px"><?php esc_html_e( 'Adds courses, bundles, categories, and topics. Nothing you already made is changed.', 'mahan-academy' ); ?></span>
					<?php endif; ?>
				</form>
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
	/* Reports page                                                        */
	/* ------------------------------------------------------------------ */

	public static function render_reports() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = Mahan_Reports::overview();
		$courses = Mahan_Reports::per_course();
		$recent  = Mahan_Reports::recent( 15 );
		$top     = Mahan_Reports::top_learners( 10 );
		$export  = wp_nonce_url( admin_url( 'admin-post.php?action=mahan_export_csv' ), 'mahan_export' );
		$certs      = Mahan_Reports::certificates( 25 );
		$cert_total = Mahan_Reports::certificate_count();
		$placement  = Mahan_Reports::placement_spread();
		$cert_export = wp_nonce_url( admin_url( 'admin-post.php?action=mahan_export_certs' ), 'mahan_export_certs' );

		$cards = array(
			array( __( 'Learners', 'mahan-academy' ), number_format_i18n( $o['learners'] ) ),
			array( __( 'Enrollments', 'mahan-academy' ), number_format_i18n( $o['enrollments'] ) ),
			array( __( 'Course completions', 'mahan-academy' ), number_format_i18n( $o['completions'] ) ),
			array( __( 'Active today', 'mahan-academy' ), number_format_i18n( $o['active_today'] ) ),
			array( __( 'Active this week', 'mahan-academy' ), number_format_i18n( $o['active_week'] ) ),
			array( __( 'Total XP', 'mahan-academy' ), number_format_i18n( $o['total_xp'] ) ),
			array( __( 'XP this week', 'mahan-academy' ), number_format_i18n( $o['xp_week'] ) ),
			array( __( 'Lessons completed', 'mahan-academy' ), number_format_i18n( $o['lessons_done'] ) ),
			array( __( 'Exercise accuracy', 'mahan-academy' ), $o['exercise_accuracy'] . '%' ),
			array( __( 'Quiz pass rate', 'mahan-academy' ), $o['quiz_pass_rate'] . '%' ),
		);
		?>
		<div class="wrap mahan-admin-wrap">
			<h1><?php esc_html_e( 'Mahan Academy — Reports', 'mahan-academy' ); ?></h1>

			<div class="mahan-cards mahan-report-cards">
				<?php foreach ( $cards as $c ) : ?>
					<div class="mahan-card">
						<span class="mahan-card-num"><?php echo esc_html( $c[1] ); ?></span>
						<span class="mahan-card-label"><?php echo esc_html( $c[0] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<h2>
				<?php esc_html_e( 'Courses', 'mahan-academy' ); ?>
				<a class="button button-secondary" style="margin-inline-start:8px" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'mahan-academy' ); ?></a>
			</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Course', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Enrolled', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Completed', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Completion', 'mahan-academy' ); ?></th>
						<th><?php esc_html_e( 'Avg progress', 'mahan-academy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $courses ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No courses yet.', 'mahan-academy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $courses as $c ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $c['id'] ) ); ?>"><?php echo esc_html( $c['title'] ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( $c['enrolled'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $c['completed'] ) ); ?></td>
								<td><?php echo esc_html( $c['completion'] . '%' ); ?></td>
								<td><?php echo esc_html( $c['avg_progress'] . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="mahan-report-cols">
				<div>
					<h2><?php esc_html_e( 'Top learners', 'mahan-academy' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th>#</th><th><?php esc_html_e( 'Learner', 'mahan-academy' ); ?></th><th>XP</th><th><?php esc_html_e( 'Level', 'mahan-academy' ); ?></th><th>🔥</th></tr></thead>
						<tbody>
							<?php if ( empty( $top ) ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No learners yet.', 'mahan-academy' ); ?></td></tr>
							<?php else : foreach ( $top as $i => $l ) : ?>
								<tr>
									<td><?php echo esc_html( $i + 1 ); ?></td>
									<td><?php echo esc_html( $l['name'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $l['xp'] ) ); ?></td>
									<td><?php echo esc_html( $l['level'] ); ?></td>
									<td><?php echo esc_html( $l['streak'] ); ?></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
				<div>
					<h2><?php esc_html_e( 'Recent completions', 'mahan-academy' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Learner', 'mahan-academy' ); ?></th><th><?php esc_html_e( 'Lesson', 'mahan-academy' ); ?></th><th><?php esc_html_e( 'When', 'mahan-academy' ); ?></th></tr></thead>
						<tbody>
							<?php if ( empty( $recent ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No activity yet.', 'mahan-academy' ); ?></td></tr>
							<?php else : foreach ( $recent as $r ) : ?>
								<tr>
									<td><?php echo esc_html( $r['user'] ); ?></td>
									<td><?php echo esc_html( $r['lesson'] ); ?> <span class="mahan-muted">· <?php echo esc_html( $r['course'] ); ?></span></td>
									<td><?php echo esc_html( human_time_diff( strtotime( $r['when'] ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'mahan-academy' ); ?></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<h2><?php esc_html_e( 'Placement levels', 'mahan-academy' ); ?></h2>
			<p class="mahan-muted">
				<?php
				printf(
					/* translators: %s: number of learners who have taken the placement test. */
					esc_html__( '%s learners have taken the placement test. This is who your catalog is actually being read by.', 'mahan-academy' ),
					esc_html( number_format_i18n( (int) $placement['tested'] ) )
				);
				?>
			</p>
			<table class="widefat striped" style="max-width:520px">
				<thead><tr><th><?php esc_html_e( 'Level', 'mahan-academy' ); ?></th><th><?php esc_html_e( 'Learners', 'mahan-academy' ); ?></th></tr></thead>
				<tbody>
					<?php
					$level_labels = array(
						'beginner'     => __( 'Beginner', 'mahan-academy' ),
						'intermediate' => __( 'Intermediate', 'mahan-academy' ),
						'advanced'     => __( 'Advanced', 'mahan-academy' ),
						'expert'       => __( 'Expert', 'mahan-academy' ),
					);
					foreach ( $level_labels as $key => $label ) :
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $placement[ $key ] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:26px">
				<?php esc_html_e( 'Certificates issued', 'mahan-academy' ); ?>
				<span class="mahan-muted">(<?php echo esc_html( number_format_i18n( $cert_total ) ); ?>)</span>
			</h2>
			<p>
				<a class="button" href="<?php echo esc_url( $cert_export ); ?>"><?php esc_html_e( 'Export certificates (CSV)', 'mahan-academy' ); ?></a>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Serial', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Course', 'mahan-academy' ); ?></th>
					<th><?php esc_html_e( 'Issued', 'mahan-academy' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $certs ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No certificates issued yet.', 'mahan-academy' ); ?></td></tr>
					<?php else : foreach ( $certs as $c ) : ?>
						<tr>
							<td><code><?php echo esc_html( $c['serial'] ); ?></code></td>
							<td><?php echo esc_html( $c['user'] ); ?></td>
							<td><?php echo esc_html( $c['course'] ); ?></td>
							<td><?php echo esc_html( $c['issued_at'] ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
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
				<a href="#tab-emails" class="nav-tab"><?php esc_html_e( 'Emails', 'mahan-academy' ); ?></a>
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
								<p class="description"><?php esc_html_e( 'Placeholders: {{name}}, {{role}}, {{company_type}}, {{seniority}}, {{ai_level}}, {{primary_goal}}, {{learning_style}}, {{daily_tools}} — filled from the learner profile. The tutor also receives a live "learner context" block (progress + an adaptive target difficulty) automatically.', 'mahan-academy' ); ?></p>
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
							<th scope="row"><label for="mahan_level_mode"><?php esc_html_e( 'Level curve', 'mahan-academy' ); ?></label></th>
							<td>
								<select id="mahan_level_mode" name="mahan_level_mode">
									<option value="linear" <?php selected( $g( 'level_mode' ), 'linear' ); ?>><?php esc_html_e( 'Linear — every level costs the same', 'mahan-academy' ); ?></option>
									<option value="progressive" <?php selected( $g( 'level_mode' ), 'progressive' ); ?>><?php esc_html_e( 'Progressive — each level costs more (RPG-style)', 'mahan-academy' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Progressive: level N costs N × "XP per level". Early levels come fast, later ones are earned.', 'mahan-academy' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_xp_streak_bonus"><?php esc_html_e( 'Streak XP bonus (%)', 'mahan-academy' ); ?></label></th>
							<td><input type="number" min="0" max="50" class="small-text" id="mahan_xp_streak_bonus" name="mahan_xp_streak_bonus" value="<?php echo esc_attr( $g( 'xp_streak_bonus' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Bonus XP per full week of streak (capped at +50%). 0 disables. Example: 10% → a 14-day streak earns +20% XP.', 'mahan-academy' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_daily_goal_default"><?php esc_html_e( 'Default daily XP goal', 'mahan-academy' ); ?></label></th>
							<td><input type="number" min="0" class="small-text" id="mahan_daily_goal_default" name="mahan_daily_goal_default" value="<?php echo esc_attr( $g( 'daily_goal_default' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Learners can pick their own goal on the dashboard; this is the starting value. 0 hides the goal.', 'mahan-academy' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Daily streak', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_streak_enabled" value="0" />
								<label><input type="checkbox" name="mahan_streak_enabled" value="1" <?php checked( (int) $g( 'streak_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Track daily learning streaks', 'mahan-academy' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Streak freezes', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_streak_freeze_enabled" value="0" />
								<label><input type="checkbox" name="mahan_streak_freeze_enabled" value="1" <?php checked( (int) $g( 'streak_freeze_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Earned freezes automatically cover missed days so the streak survives', 'mahan-academy' ); ?></label>
								<p class="description">
									<?php esc_html_e( 'Earn 1 freeze every', 'mahan-academy' ); ?>
									<input type="number" min="1" class="small-text" name="mahan_freeze_earn_days" value="<?php echo esc_attr( $g( 'freeze_earn_days' ) ); ?>" style="width:60px" />
									<?php esc_html_e( 'streak days, holding at most', 'mahan-academy' ); ?>
									<input type="number" min="0" class="small-text" name="mahan_freeze_max" value="<?php echo esc_attr( $g( 'freeze_max' ) ); ?>" style="width:60px" />
									<?php esc_html_e( 'freezes.', 'mahan-academy' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Adaptive review', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_review_enabled" value="0" />
								<label><input type="checkbox" name="mahan_review_enabled" value="1" <?php checked( (int) $g( 'review_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Re-ask questions the learner got wrong — at the end of the lesson and, with spaced repetition, on later days.', 'mahan-academy' ); ?></label>
								<p class="description">
									<?php esc_html_e( 'XP per cleared review:', 'mahan-academy' ); ?>
									<input type="number" min="1" class="small-text" name="mahan_review_xp" value="<?php echo esc_attr( $g( 'review_xp' ) ); ?>" style="width:60px" />
									<?php esc_html_e( 'Learners can also ask the AI to re-pose a missed question a different way (from a different provider when more than one is configured).', 'mahan-academy' ); ?>
								</p>
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

				<!-- EMAILS -->
				<div class="mahan-tab-panel" id="tab-emails" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Email notifications', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_emails_enabled" value="0" />
								<label><input type="checkbox" name="mahan_emails_enabled" value="1" <?php checked( (int) $g( 'emails_enabled' ), 1 ); ?> /> <?php esc_html_e( 'Send notification emails', 'mahan-academy' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_email_from_name"><?php esc_html_e( 'From name', 'mahan-academy' ); ?></label></th>
							<td><input type="text" class="regular-text" id="mahan_email_from_name" name="mahan_email_from_name" value="<?php echo esc_attr( $g( 'email_from_name' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_email_from_email"><?php esc_html_e( 'From email', 'mahan-academy' ); ?></label></th>
							<td><input type="email" class="regular-text" id="mahan_email_from_email" name="mahan_email_from_email" value="<?php echo esc_attr( $g( 'email_from_email' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></td>
						</tr>
					</table>
					<p class="description"><?php esc_html_e( 'Placeholders: {{name}}, {{course}}, {{badge}}, {{streak}}, {{site}}, {{academy_url}}, {{login_url}}. Basic HTML is allowed in the body.', 'mahan-academy' ); ?></p>
					<?php
					$emails = array(
						'welcome'  => __( 'Enrollment / welcome', 'mahan-academy' ),
						'complete' => __( 'Course completed', 'mahan-academy' ),
						'badge'    => __( 'New achievement', 'mahan-academy' ),
						'streak'   => __( 'Daily streak reminder', 'mahan-academy' ),
					);
					foreach ( $emails as $slug => $label ) :
						?>
						<h3><?php echo esc_html( $label ); ?></h3>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enabled', 'mahan-academy' ); ?></th>
								<td>
									<input type="hidden" name="mahan_email_<?php echo esc_attr( $slug ); ?>" value="0" />
									<label><input type="checkbox" name="mahan_email_<?php echo esc_attr( $slug ); ?>" value="1" <?php checked( (int) $g( 'email_' . $slug ), 1 ); ?> />
									<?php
									echo 'streak' === $slug
										? esc_html__( 'Send a daily reminder to learners with an active streak (requires wp-cron).', 'mahan-academy' )
										: esc_html__( 'Send this email', 'mahan-academy' );
									?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="mahan_email_<?php echo esc_attr( $slug ); ?>_subject"><?php esc_html_e( 'Subject', 'mahan-academy' ); ?></label></th>
								<td><input type="text" class="large-text" id="mahan_email_<?php echo esc_attr( $slug ); ?>_subject" name="mahan_email_<?php echo esc_attr( $slug ); ?>_subject" value="<?php echo esc_attr( $g( 'email_' . $slug . '_subject' ) ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="mahan_email_<?php echo esc_attr( $slug ); ?>_body"><?php esc_html_e( 'Body', 'mahan-academy' ); ?></label></th>
								<td><textarea id="mahan_email_<?php echo esc_attr( $slug ); ?>_body" name="mahan_email_<?php echo esc_attr( $slug ); ?>_body" rows="5" class="large-text"><?php echo esc_textarea( $g( 'email_' . $slug . '_body' ) ); ?></textarea></td>
							</tr>
						</table>
					<?php endforeach; ?>
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
							<th scope="row"><label for="mahan_hero_title"><?php esc_html_e( 'Catalog headline', 'mahan-academy' ); ?></label></th>
							<td>
								<input type="text" id="mahan_hero_title" name="mahan_hero_title" value="<?php echo esc_attr( $g( 'hero_title' ) ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Learn to use AI at work', 'mahan-academy' ); ?>" />
								<p class="description"><?php esc_html_e( 'The big title on the course catalog. Leave empty for the default.', 'mahan-academy' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_hero_subtitle"><?php esc_html_e( 'Catalog tagline', 'mahan-academy' ); ?></label></th>
							<td>
								<input type="text" id="mahan_hero_subtitle" name="mahan_hero_subtitle" value="<?php echo esc_attr( $g( 'hero_subtitle' ) ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Structured courses. Hands-on practice. A tutor that answers in real time.', 'mahan-academy' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mahan_custom_css"><?php esc_html_e( 'Custom CSS', 'mahan-academy' ); ?></label></th>
							<td><textarea id="mahan_custom_css" name="mahan_custom_css" rows="7" class="large-text code"><?php echo esc_textarea( $g( 'custom_css' ) ); ?></textarea></td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Language', 'mahan-academy' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'The academy interface ships in English and Spanish. Only the interface is translated — course text, quiz questions, and email templates stay exactly as they were written.', 'mahan-academy' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mahan_default_language"><?php esc_html_e( 'Academy language', 'mahan-academy' ); ?></label></th>
							<td>
								<select id="mahan_default_language" name="mahan_default_language">
									<option value="" <?php selected( $g( 'default_language' ), '' ); ?>><?php esc_html_e( 'Follow the site language', 'mahan-academy' ); ?></option>
									<?php foreach ( Mahan_I18n::available() as $locale => $meta ) : ?>
										<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $g( 'default_language' ), $locale ); ?>>
											<?php echo esc_html( $meta['native'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'What a learner sees before they choose anything.', 'mahan-academy' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Learner choice', 'mahan-academy' ); ?></th>
							<td>
								<input type="hidden" name="mahan_learner_language" value="0" />
								<label><input type="checkbox" name="mahan_learner_language" value="1" <?php checked( (int) $g( 'learner_language' ), 1 ); ?> /> <?php esc_html_e( 'Show a language switcher so learners can pick their own', 'mahan-academy' ); ?></label>
								<p class="description"><?php esc_html_e( 'Each learner\'s choice applies to the academy only — the rest of the site stays in its own language.', 'mahan-academy' ); ?></p>
							</td>
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
