<?php
/**
 * Front-end: the [mahan_academy] SPA mount, asset loading, and JS bootstrap.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Front {

	const SHORTCODE = 'mahan_academy';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_app' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Does the current singular view need the app assets?
	 */
	private static function needs_assets() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		$app_page = (int) Mahan_Settings::get( 'app_page_id', 0 );
		if ( $app_page && (int) $post->ID === $app_page ) {
			return true;
		}
		return has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	public static function maybe_enqueue() {
		if ( ! self::needs_assets() ) {
			return;
		}
		self::enqueue();
	}

	public static function enqueue() {
		wp_register_style( 'mahan-app', MAHAN_URL . 'assets/css/app.css', array(), MAHAN_VERSION );
		wp_register_script( 'mahan-app', MAHAN_URL . 'assets/js/app.js', array(), MAHAN_VERSION, true );

		wp_enqueue_style( 'mahan-app' );
		wp_enqueue_script( 'mahan-app' );

		// Theme variables + optional custom CSS.
		$primary = sanitize_hex_color( (string) Mahan_Settings::get( 'primary_color', '#4f46e5' ) ) ?: '#4f46e5';
		$accent  = sanitize_hex_color( (string) Mahan_Settings::get( 'accent_color', '#22c55e' ) ) ?: '#22c55e';
		$theme   = 'dark' === Mahan_Settings::get( 'theme', 'light' ) ? 'dark' : 'light';

		$inline = ':root{--mahan-primary:' . $primary . ';--mahan-accent:' . $accent . ';}';
		$custom = (string) Mahan_Settings::get( 'custom_css', '' );
		if ( '' !== trim( $custom ) ) {
			$inline .= "\n" . wp_strip_all_tags( $custom );
		}
		wp_add_inline_style( 'mahan-app', $inline );

		$user_id = get_current_user_id();
		$user    = $user_id ? wp_get_current_user() : null;

		wp_localize_script(
			'mahan-app',
			'MahanData',
			array(
				'restUrl'      => esc_url_raw( rest_url( Mahan_REST::NS ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'      => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'streamAction' => Mahan_AI_Stream::ACTION,
				'streamNonce'  => wp_create_nonce( 'mahan_ajax' ),
				'appUrl'       => self::app_url(),
				'loggedIn'     => (bool) $user_id,
				'loginUrl'     => wp_login_url( self::app_url() ),
				'registerUrl'  => wp_registration_url(),
				'canRegister'  => (bool) get_option( 'users_can_register' ),
				'user'         => $user ? array(
					'name'   => $user->display_name,
					'avatar' => get_avatar_url( $user_id, array( 'size' => 96 ) ),
				) : null,
				'theme'        => $theme,
				'gateEnabled'  => (int) Mahan_Settings::get( 'gate_enabled', 1 ),
				'aiReady'      => Mahan_Settings::ai_ready(),
				'i18n'         => self::strings(),
			)
		);
	}

	/**
	 * Canonical URL of the page that hosts the app.
	 */
	public static function app_url() {
		$app_page = (int) Mahan_Settings::get( 'app_page_id', 0 );
		if ( $app_page && get_post( $app_page ) ) {
			return get_permalink( $app_page );
		}
		return is_singular() ? get_permalink() : home_url( '/' );
	}

	public static function render_app( $atts = array() ) {
		// Ensure assets are present even if the shortcode lives somewhere unexpected.
		if ( ! wp_script_is( 'mahan-app', 'enqueued' ) ) {
			self::enqueue();
		}
		$initial = is_singular() ? esc_attr( get_the_title( get_the_ID() ) ) : '';
		ob_start();
		?>
		<div id="mahan-app" class="mahan-app mahan-theme-<?php echo esc_attr( 'dark' === Mahan_Settings::get( 'theme', 'light' ) ? 'dark' : 'light' ); ?>" data-initial-title="<?php echo $initial; ?>">
			<div class="mahan-boot">
				<div class="mahan-boot-spinner" aria-hidden="true"></div>
				<p><?php esc_html_e( 'Loading the academy…', 'mahan-academy' ); ?></p>
			</div>
			<noscript><?php esc_html_e( 'Please enable JavaScript to use the academy.', 'mahan-academy' ); ?></noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * UI strings handed to the JS app (so the front-end stays translatable).
	 */
	private static function strings() {
		return array(
			'catalog'          => __( 'Explore', 'mahan-academy' ),
			'dashboard'        => __( 'My Learning', 'mahan-academy' ),
			'continue'         => __( 'Continue', 'mahan-academy' ),
			'start'            => __( 'Start course', 'mahan-academy' ),
			'enroll'           => __( 'Enroll — free', 'mahan-academy' ),
			'enrolled'         => __( 'Enrolled', 'mahan-academy' ),
			'resume'           => __( 'Resume', 'mahan-academy' ),
			'level'            => __( 'Level', 'mahan-academy' ),
			'xp'               => __( 'XP', 'mahan-academy' ),
			'streak'           => __( 'day streak', 'mahan-academy' ),
			'lessons'          => __( 'lessons', 'mahan-academy' ),
			'check'            => __( 'Check', 'mahan-academy' ),
			'continueBtn'      => __( 'Continue', 'mahan-academy' ),
			'completeLesson'   => __( 'Complete lesson', 'mahan-academy' ),
			'lessonComplete'   => __( 'Lesson complete!', 'mahan-academy' ),
			'courseComplete'   => __( 'Course complete! 🎉', 'mahan-academy' ),
			'nextLesson'       => __( 'Next lesson', 'mahan-academy' ),
			'prevLesson'       => __( 'Previous', 'mahan-academy' ),
			'backToCourse'     => __( 'Back to course', 'mahan-academy' ),
			'correct'          => __( 'Correct!', 'mahan-academy' ),
			'incorrect'        => __( 'Not quite', 'mahan-academy' ),
			'tutorTitle'       => __( 'AI Tutor', 'mahan-academy' ),
			'tutorPlaceholder' => __( 'Ask the tutor anything about this lesson…', 'mahan-academy' ),
			'tutorIntro'       => __( "Hi! I'm your AI tutor. Ask me anything about this lesson, or how to apply it to your work.", 'mahan-academy' ),
			'tutorOffline'     => __( 'The AI tutor is not configured yet.', 'mahan-academy' ),
			'send'             => __( 'Send', 'mahan-academy' ),
			'thinking'         => __( 'Thinking…', 'mahan-academy' ),
			'loginToLearn'     => __( 'Log in to start learning', 'mahan-academy' ),
			'login'            => __( 'Log in', 'mahan-academy' ),
			'register'         => __( 'Create account', 'mahan-academy' ),
			'profileTitle'     => __( 'Tell us about you', 'mahan-academy' ),
			'profileIntro'     => __( 'This personalizes your lessons, exercises, and AI tutor.', 'mahan-academy' ),
			'save'             => __( 'Save & continue', 'mahan-academy' ),
			'notNow'           => __( 'Not now', 'mahan-academy' ),
			'required'         => __( 'Please fill in the required fields.', 'mahan-academy' ),
			'submit'           => __( 'Submit', 'mahan-academy' ),
			'writeAnswer'      => __( 'Write your answer…', 'mahan-academy' ),
			'typeAnswer'       => __( 'Type your answer…', 'mahan-academy' ),
			'hint'             => __( 'Hint', 'mahan-academy' ),
			'empty'            => __( 'Nothing here yet.', 'mahan-academy' ),
			'emptyCatalog'     => __( 'No courses are available yet. Check back soon!', 'mahan-academy' ),
			'emptyDashboard'   => __( "You haven't enrolled in any courses yet.", 'mahan-academy' ),
			'browseCourses'    => __( 'Browse courses', 'mahan-academy' ),
			'error'            => __( 'Something went wrong. Please try again.', 'mahan-academy' ),
			'levelUp'          => __( 'Level up!', 'mahan-academy' ),
			'allLevels'        => __( 'All levels', 'mahan-academy' ),
			'beginner'         => __( 'Beginner', 'mahan-academy' ),
			'intermediate'     => __( 'Intermediate', 'mahan-academy' ),
			'advanced'         => __( 'Advanced', 'mahan-academy' ),
			'whatYouLearn'     => __( "What you'll learn", 'mahan-academy' ),
			'courseContent'    => __( 'Course content', 'mahan-academy' ),
			'practice'         => __( 'Practice', 'mahan-academy' ),
			'reading'          => __( 'Reading', 'mahan-academy' ),
			'locked'           => __( 'Complete the previous lesson to unlock', 'mahan-academy' ),
		);
	}
}
