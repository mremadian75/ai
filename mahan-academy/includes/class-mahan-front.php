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
				// Base login URL so the SPA can send learners back to the
				// exact course/lesson they were viewing (deep-link safe).
				'loginBase'    => wp_login_url(),
				'registerUrl'  => wp_registration_url(),
				'canRegister'  => (bool) get_option( 'users_can_register' ),
				'user'         => $user ? array(
					'name'   => $user->display_name,
					'avatar' => get_avatar_url( $user_id, array( 'size' => 96 ) ),
				) : null,
				'theme'        => $theme,
				'gateEnabled'  => (int) Mahan_Settings::get( 'gate_enabled', 1 ),
				'aiReady'      => Mahan_Settings::ai_ready(),
				'leaderboard'  => (bool) Mahan_Settings::get( 'leaderboard_enabled', 0 ),
				'hasPaths'     => ( (int) wp_count_posts( Mahan_CPT::PATH )->publish > 0 ),
				'siteName'     => get_bloginfo( 'name' ),
				// Catalog hero copy — admin-configurable, translatable default.
				'heroTitle'    => (string) Mahan_Settings::get( 'hero_title', '' ) ?: __( 'Learn to use AI at work', 'mahan-academy' ),
				'heroSub'      => (string) Mahan_Settings::get( 'hero_subtitle', '' ) ?: __( 'Structured courses. Hands-on practice. A tutor that answers in real time.', 'mahan-academy' ),
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
			'profileIntro'     => __( 'The more you share, the more your lessons, exercises, and AI tutor adapt to your role, level, and goals.', 'mahan-academy' ),
			'profileSaved'     => __( 'Your lessons, exercises, and tutor are now personalized to you.', 'mahan-academy' ),
			'save'             => __( 'Save & continue', 'mahan-academy' ),
			'notNow'           => __( 'Not now', 'mahan-academy' ),
			'required'         => __( 'Please fill in the required fields.', 'mahan-academy' ),
			'submit'           => __( 'Submit', 'mahan-academy' ),
			'writeAnswer'      => __( 'Write your answer…', 'mahan-academy' ),
			'typeAnswer'       => __( 'Type your answer…', 'mahan-academy' ),
			'hint'             => __( 'Hint', 'mahan-academy' ),
			'empty'            => __( 'Nothing here yet.', 'mahan-academy' ),
			'emptyCatalog'     => __( 'No courses are available yet. Check back soon!', 'mahan-academy' ),
			'emptyDashboard'   => __( 'Pick your first course and start earning XP today.', 'mahan-academy' ),
			'browseCourses'    => __( 'Browse courses', 'mahan-academy' ),
			'error'            => __( 'Something went wrong. Please try again.', 'mahan-academy' ),
			'levelUp'          => __( 'Level up!', 'mahan-academy' ),
			'allLevels'        => __( 'All levels', 'mahan-academy' ),
			'allTopics'        => __( 'All topics', 'mahan-academy' ),
			'browseByTopic'    => __( 'Browse by topic', 'mahan-academy' ),
			'recommendedForYou' => __( 'Recommended for you', 'mahan-academy' ),
			'topicFilterLabel' => __( 'Topic:', 'mahan-academy' ),
			// Catalog filter summary: result count + one removable pill per
			// active filter.
			'showingCount'     => __( 'Showing %1$s of %2$s courses', 'mahan-academy' ),
			'courseOne'        => __( 'course', 'mahan-academy' ),
			'courseMany'       => __( 'courses', 'mahan-academy' ),
			'filterSearch'     => __( 'Search', 'mahan-academy' ),
			'filterLevel'      => __( 'Level', 'mahan-academy' ),
			'filterCategory'   => __( 'Category', 'mahan-academy' ),
			'filterTopic'      => __( 'Topic', 'mahan-academy' ),
			'removeFilter'     => __( 'Remove filter: %s', 'mahan-academy' ),
			'stepOfTrack'      => __( 'Step %1$s of %2$s', 'mahan-academy' ),
			// Lesson contents drawer.
			'contents'         => __( 'Contents', 'mahan-academy' ),
			'youAreHere'       => __( 'You are here', 'mahan-academy' ),
			'emptyCourse'      => __( 'No lessons yet.', 'mahan-academy' ),
			// Keyboard shortcuts.
			'keyboardShortcuts' => __( 'Keyboard shortcuts', 'mahan-academy' ),
			'scSearch'         => __( 'Search courses', 'mahan-academy' ),
			'scLesson'         => __( 'Previous / next lesson', 'mahan-academy' ),
			'scContinue'       => __( 'Continue learning', 'mahan-academy' ),
			'scClear'          => __( 'Clear filters / close', 'mahan-academy' ),
			'scHelp'           => __( 'Show this list', 'mahan-academy' ),
			'viewAll'          => __( 'View all', 'mahan-academy' ),
			'otherCourses'     => __( 'More courses', 'mahan-academy' ),
			'pathBadge'        => __( 'Path', 'mahan-academy' ),
			'beginner'         => __( 'Beginner', 'mahan-academy' ),
			'intermediate'     => __( 'Intermediate', 'mahan-academy' ),
			'advanced'         => __( 'Advanced', 'mahan-academy' ),
			'expert'           => __( 'Expert', 'mahan-academy' ),
			'chooseLevel'      => __( 'Choose your level', 'mahan-academy' ),
			/* translators: %s is the learner's department, e.g. Marketing. */
			'tailoredFor'      => __( 'Tailored for %s', 'mahan-academy' ),
			'whatYouLearn'     => __( "What you'll learn", 'mahan-academy' ),
			'furtherReading'   => __( 'Further reading & sources', 'mahan-academy' ),
			'furtherReadingNote' => __( 'This course is grounded in these references — go deeper any time.', 'mahan-academy' ),
			'courseContent'    => __( 'Course content', 'mahan-academy' ),
			'practice'         => __( 'Practice', 'mahan-academy' ),
			'topics'           => __( 'Topics', 'mahan-academy' ),
			// Per-lesson "For you" personalization.
			'foryouTitle'      => __( 'For you', 'mahan-academy' ),
			'foryouLoading'    => __( 'Personalizing this lesson for you…', 'mahan-academy' ),
			// Smart practice (on-demand AI-generated questions).
			'smartPractice'    => __( 'Smart practice', 'mahan-academy' ),
			'smartPracticeSub' => __( 'Generate fresh questions tuned to this lesson and your level.', 'mahan-academy' ),
			'generatePractice' => __( 'Generate practice', 'mahan-academy' ),
			'generateMore'     => __( 'Generate more', 'mahan-academy' ),
			'practiceFailed'   => __( "Couldn't generate practice right now — please try again.", 'mahan-academy' ),
			'reading'          => __( 'Reading', 'mahan-academy' ),
			'locked'           => __( 'Complete the previous lesson to unlock', 'mahan-academy' ),
			'featured'         => __( 'Featured', 'mahan-academy' ),
			'leaderboard'      => __( 'Leaderboard', 'mahan-academy' ),
			'achievements'     => __( 'Achievements', 'mahan-academy' ),
			'noBadges'         => __( 'Earn badges by completing lessons and courses, keeping streaks, and leveling up.', 'mahan-academy' ),
			'prereqNote'       => __( 'Recommended first:', 'mahan-academy' ),
			'certificate'      => __( 'Certificate of completion', 'mahan-academy' ),
			'viewCertificate'  => __( 'View certificate', 'mahan-academy' ),
			'completed'        => __( 'Completed', 'mahan-academy' ),
			'certAwarded'      => __( 'This certifies that', 'mahan-academy' ),
			'certCompleted'    => __( 'has successfully completed', 'mahan-academy' ),
			'print'            => __( 'Print / Save as PDF', 'mahan-academy' ),
			'rank'             => __( 'Rank', 'mahan-academy' ),
			'learner'          => __( 'Learner', 'mahan-academy' ),
			'you'              => __( 'You', 'mahan-academy' ),
			'emptyLeaderboard' => __( 'No ranked learners yet — earn some XP!', 'mahan-academy' ),
			'quiz'             => __( 'Quiz', 'mahan-academy' ),
			'questions'        => __( 'questions', 'mahan-academy' ),
			'passMark'         => __( 'pass', 'mahan-academy' ),
			'submitQuiz'       => __( 'Submit quiz', 'mahan-academy' ),
			'quizPassed'       => __( 'Passed!', 'mahan-academy' ),
			'quizFailed'       => __( 'Keep going', 'mahan-academy' ),
			'correctCount'     => __( 'correct', 'mahan-academy' ),
			'done'             => __( 'Done', 'mahan-academy' ),
			'retry'            => __( 'Try again', 'mahan-academy' ),
			'paths'            => __( 'Paths', 'mahan-academy' ),
			'learningPaths'    => __( 'Learning paths', 'mahan-academy' ),
			'pathsSub'         => __( 'Guided programs — courses in a recommended order.', 'mahan-academy' ),
			'courses'          => __( 'courses', 'mahan-academy' ),
			'emptyPaths'       => __( 'No learning paths yet.', 'mahan-academy' ),
			'viewPath'         => __( 'View path', 'mahan-academy' ),
			'coursesInPath'    => __( 'Courses in this path', 'mahan-academy' ),
			'pathComplete'     => __( 'completed', 'mahan-academy' ),
			'openCourse'       => __( 'Open', 'mahan-academy' ),
			'badgeEarned'      => __( 'Achievement unlocked:', 'mahan-academy' ),
			'dailyGoal'        => __( 'Daily goal', 'mahan-academy' ),
			'goalLabel'        => __( 'Goal:', 'mahan-academy' ),
			'goalDone'         => __( 'Goal reached — nice work! Everything extra is a bonus.', 'mahan-academy' ),
			'freezes'          => __( 'streak freezes', 'mahan-academy' ),
			'thisWeek'         => __( 'This week', 'mahan-academy' ),
			'allTime'          => __( 'All time', 'mahan-academy' ),
			'close'            => __( 'Close', 'mahan-academy' ),
			'loading'          => __( 'Loading…', 'mahan-academy' ),
			'menu'             => __( 'Menu', 'mahan-academy' ),
			'askTutor'         => __( 'Ask the AI tutor', 'mahan-academy' ),
			'searchCourses'    => __( 'Search courses…', 'mahan-academy' ),
			'noResults'        => __( 'No courses match your search.', 'mahan-academy' ),
			'clearFilters'     => __( 'Clear filters', 'mahan-academy' ),
			'jumpBackIn'       => __( 'Jump back in', 'mahan-academy' ),
			'nextUp'           => __( 'Next up:', 'mahan-academy' ),
			'continueLearning' => __( 'Continue learning', 'mahan-academy' ),
			'lesson'           => __( 'Lesson', 'mahan-academy' ),
			'of'               => __( 'of', 'mahan-academy' ),
			'answered'         => __( 'answered', 'mahan-academy' ),
			'unansweredWarn'   => __( 'Some questions are unanswered — tap Submit again to send anyway.', 'mahan-academy' ),
			'courseCompleteTitle' => __( 'Course complete!', 'mahan-academy' ),
			'courseCompleteMsg'   => __( 'You finished', 'mahan-academy' ),
			'offline'          => __( 'You appear to be offline. Check your connection and try again.', 'mahan-academy' ),
			'backOnline'       => __( 'Back online!', 'mahan-academy' ),
			'thisWeekActivity' => __( 'This week', 'mahan-academy' ),
			'enterToSend'      => __( 'Enter to send · Shift+Enter for a new line', 'mahan-academy' ),
			'keepLearning'     => __( 'Keep learning', 'mahan-academy' ),
			'reviewCourse'     => __( 'Review course', 'mahan-academy' ),
			'notFound'         => __( 'This content is no longer available.', 'mahan-academy' ),
			'backToCatalog'    => __( 'Back to catalog', 'mahan-academy' ),
			'unitQuizNext'     => __( 'Unit quiz unlocked!', 'mahan-academy' ),
			'takeQuiz'         => __( 'Take the quiz', 'mahan-academy' ),
			'goalHit'          => __( 'Daily goal reached!', 'mahan-academy' ),
			'pickGoal'         => __( 'Set a daily XP goal to build a habit.', 'mahan-academy' ),
			'closeQuizWarn'    => __( 'Your answers will be lost — close again to confirm.', 'mahan-academy' ),
			'goalSaved'        => __( 'Daily goal updated', 'mahan-academy' ),
			'tutorTimeout'     => __( 'The tutor took too long to respond. Please try again.', 'mahan-academy' ),
			'notEnrolled'      => __( 'Enroll in this course to access its lessons.', 'mahan-academy' ),
			'longestStreak'    => __( 'Longest', 'mahan-academy' ),
			'streakAtRisk'     => __( 'Study today to keep it!', 'mahan-academy' ),
			'lockedLesson'     => __( 'Complete the previous lesson to unlock this one.', 'mahan-academy' ),
			'reviewsDue'       => __( 'reviews due', 'mahan-academy' ),
			'minShort'         => __( 'min', 'mahan-academy' ),
			'enrollFirst'      => __( 'Enroll first — it takes one tap and it\'s free.', 'mahan-academy' ),
			'pickAnswer'       => __( 'Pick an answer first.', 'mahan-academy' ),
			'skippedCount'     => __( 'skipped', 'mahan-academy' ),
			'showOriginal'     => __( 'Show the original', 'mahan-academy' ),
			'exitReview'       => __( 'Exit review', 'mahan-academy' ),
			// Adaptive review.
			'review'           => __( 'Review', 'mahan-academy' ),
			'reviewTitle'      => __( 'Practice your mistakes', 'mahan-academy' ),
			'reviewSub'        => __( 'Questions you missed come back here until you master them.', 'mahan-academy' ),
			'reviewDue'        => __( 'due for review', 'mahan-academy' ),
			'reviewNow'        => __( 'Review now', 'mahan-academy' ),
			'reviewLesson'     => __( 'Review your misses', 'mahan-academy' ),
			'reviewNone'       => __( "You're all caught up — no reviews due. Nice!", 'mahan-academy' ),
			'reviewMastered'   => __( 'mastered', 'mahan-academy' ),
			'reviewLearning'   => __( 'learning', 'mahan-academy' ),
			'reviewDone'       => __( 'Review complete!', 'mahan-academy' ),
			'reviewCleared'    => __( 'cleared', 'mahan-academy' ),
			'reviewMasteredMsg' => __( 'Mastered! You won\'t see this one for a while.', 'mahan-academy' ),
			'reviewAgainSoon'  => __( "We'll ask you again soon.", 'mahan-academy' ),
			'askDifferently'   => __( 'Ask a different way', 'mahan-academy' ),
			'answerWas'        => __( 'Answer:', 'mahan-academy' ),
			'reviewProgress'   => __( 'of', 'mahan-academy' ),
			'skip'             => __( 'Skip', 'mahan-academy' ),
			'variantFailed'    => __( "Couldn't rephrase that one — here's the original.", 'mahan-academy' ),
			'backToDashboard'  => __( 'Back to My Learning', 'mahan-academy' ),
			'skipToContent'    => __( 'Skip to content', 'mahan-academy' ),
			// v1.8.0 — UX round 3.
			/* translators: %s is the number of due review items. */
			'reviewFading'     => __( '%s fading — a quick review locks them in', 'mahan-academy' ),
			/* translators: %s is the number of missed questions. */
			'reviewMissedToast' => __( "%s missed — let's fix them before moving on", 'mahan-academy' ),
			'reviewSubLesson'  => __( 'A quick check on the questions you missed in this lesson.', 'mahan-academy' ),
			'reviewEnded'      => __( 'Session ended', 'mahan-academy' ),
			/* translators: %s is the number of skipped review items. */
			'reviewSkipped'    => __( 'Review %s skipped', 'mahan-academy' ),
			'seeAgainThisSession' => __( "You'll see this one again before the end of this session.", 'mahan-academy' ),
			'revisitLesson'    => __( 'Revisit lesson', 'mahan-academy' ),
			'tutorReady'       => __( 'Online', 'mahan-academy' ),
			'tutorUnavailable' => __( 'Unavailable', 'mahan-academy' ),
			'tutorConversation' => __( 'Tutor conversation', 'mahan-academy' ),
			'coursesHeading'   => __( 'Courses', 'mahan-academy' ),
			'weekGoalMet'      => __( 'goal met', 'mahan-academy' ),
			'weekStudied'      => __( 'studied', 'mahan-academy' ),
			'weekNoActivity'   => __( 'no activity', 'mahan-academy' ),
			'today'            => __( 'today', 'mahan-academy' ),
			'badgeEarnedState' => __( 'Earned:', 'mahan-academy' ),
			/* translators: %1$s current progress, %2$s target. */
			'badgeLockedState' => __( 'Locked, %1$s of %2$s:', 'mahan-academy' ),
			'correctAnswer'    => __( 'correct answer', 'mahan-academy' ),
			'submitFeedback'   => __( 'Submit for feedback', 'mahan-academy' ),
			'lessonOne'        => __( 'lesson', 'mahan-academy' ),
			'questionOne'      => __( 'question', 'mahan-academy' ),
			// Rotating reinforcement copy — kept fresh so the reward loop
			// doesn't go stale. Each entry is independently translatable.
			'correctVariants'   => array(
				__( 'Correct!', 'mahan-academy' ),
				__( 'Nailed it!', 'mahan-academy' ),
				__( 'Exactly right!', 'mahan-academy' ),
				__( 'Spot on!', 'mahan-academy' ),
			),
			'incorrectVariants' => array(
				__( 'Not quite', 'mahan-academy' ),
				__( 'Close — try again', 'mahan-academy' ),
				__( 'Almost there', 'mahan-academy' ),
			),
			'goalVariants'      => array(
				__( 'Daily goal reached!', 'mahan-academy' ),
				__( 'Goal smashed!', 'mahan-academy' ),
				__( "You hit today's goal!", 'mahan-academy' ),
			),
			'levelUpVariants'   => array(
				__( 'Level up!', 'mahan-academy' ),
				__( 'New level unlocked!', 'mahan-academy' ),
				__( 'You leveled up — keep going!', 'mahan-academy' ),
			),
		);
	}
}
