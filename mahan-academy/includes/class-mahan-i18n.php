<?php
/**
 * Language layer: which catalog the academy speaks, and who gets to decide.
 *
 * WordPress already picks a locale for the site, and since 4.7 a per-user
 * locale — but that one only applies inside wp-admin. Neither covers the case
 * an academy has to handle: a learner reading in Spanish on a site whose
 * theme, admin, and every other plugin stay in English.
 *
 * So the switch is deliberately narrow. The `plugin_locale` filter moves only
 * this plugin's textdomain; nothing else on the page changes language. That
 * narrowness is exactly what makes it safe to hand the control to the learner
 * instead of reserving it for an administrator.
 *
 * What this does NOT translate: course prose, lesson bodies, quiz questions
 * and email templates. Those are rows in the database written by whoever runs
 * the site — the plugin has no business rewriting them, and pretending
 * otherwise would be worse than saying so.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_I18n {

	const DOMAIN = 'mahan-academy';
	const META   = 'mahan_lang';
	const COOKIE = 'mahan_lang';

	/**
	 * Set while rendering deliberately in someone else's language.
	 *
	 * @var string
	 */
	private static $override = '';

	/**
	 * Locales this plugin ships an interface for.
	 *
	 * Names are written in the language itself: a Spanish speaker hunting for
	 * their language is looking for "Español", not for the English word
	 * "Spanish" translated into a language they can't yet read.
	 */
	public static function languages() {
		return array(
			'en_US' => array(
				'native' => 'English',
				'short'  => 'EN',
				'dir'    => 'ltr',
			),
			'es_ES' => array(
				'native' => 'Español',
				'short'  => 'ES',
				'dir'    => 'ltr',
			),
		);
	}

	/**
	 * Languages actually offerable on this install.
	 *
	 * English needs no catalog — it is the source language. Everything else
	 * has to have a compiled .mo on disk, because listing a language whose
	 * catalog was never built just serves English under a foreign label.
	 */
	public static function available() {
		$out = array();
		foreach ( self::languages() as $locale => $meta ) {
			if ( 'en_US' === $locale || file_exists( self::mo_path( $locale ) ) ) {
				$out[ $locale ] = $meta;
			}
		}
		return $out;
	}

	private static function mo_path( $locale ) {
		return MAHAN_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo';
	}

	public static function is_supported( $locale ) {
		$locale = (string) $locale;
		return '' !== $locale && isset( self::available()[ $locale ] );
	}

	/**
	 * The closest catalog we can serve for a locale.
	 *
	 * A site set to es_MX or es_AR would otherwise fall all the way back to
	 * English, because WordPress looks for an exact filename match. Matching
	 * on the language family instead gives those sites Spanish, which is
	 * plainly what they asked for.
	 *
	 * @return string Supported locale, or '' when nothing in the family fits.
	 */
	public static function nearest( $locale ) {
		$available = self::available();
		if ( isset( $available[ $locale ] ) ) {
			return $locale;
		}
		$family = strtolower( substr( (string) $locale, 0, 2 ) );
		if ( '' === $family ) {
			return '';
		}
		foreach ( array_keys( $available ) as $candidate ) {
			if ( strtolower( substr( $candidate, 0, 2 ) ) === $family ) {
				return $candidate;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Runtime                                                             */
	/* ------------------------------------------------------------------ */

	public static function init() {
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_locale' ), 10, 2 );

		// A guest who picked Spanish, then registered, should stay in Spanish.
		// Their choice lives in a cookie until there's an account to put it on.
		add_action( 'wp_login', array( __CLASS__, 'adopt_guest_choice' ), 10, 2 );

		// Operators need to be able to see and fix a learner's language.
		add_action( 'show_user_profile', array( __CLASS__, 'user_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_field' ) );
	}

	/**
	 * Scope the override to our own textdomain — never anyone else's.
	 *
	 * @param string $locale Locale WordPress settled on.
	 * @param string $domain Textdomain being loaded.
	 */
	public static function filter_locale( $locale, $domain ) {
		if ( self::DOMAIN !== $domain ) {
			return $locale;
		}
		return self::resolve( $locale );
	}

	/**
	 * Resolve the locale the academy should speak for this request.
	 *
	 * @param string $locale WordPress's own answer, used as the floor.
	 */
	public static function resolve( $locale ) {
		if ( '' !== self::$override ) {
			return self::$override;
		}

		// wp-admin keeps WordPress's rules: an editor's admin screens should
		// not switch language because they once read a course in Spanish.
		if ( ! self::is_admin_screen() ) {
			$preference = self::preference();
			if ( '' !== $preference ) {
				return $preference;
			}
			$forced = (string) Mahan_Settings::get( 'default_language', '' );
			if ( self::is_supported( $forced ) ) {
				return $forced;
			}
		}

		$nearest = self::nearest( $locale );
		return '' !== $nearest ? $nearest : $locale;
	}

	/**
	 * The academy's current locale (front-end resolution rules applied).
	 */
	public static function current() {
		return self::resolve( determine_locale() );
	}

	public static function direction( $locale = null ) {
		$locale    = null === $locale ? self::current() : $locale;
		$languages = self::languages();
		return isset( $languages[ $locale ]['dir'] ) ? $languages[ $locale ]['dir'] : 'ltr';
	}

	/**
	 * Is this a wp-admin screen?
	 *
	 * Checked at `plugins_loaded`, where REST_REQUEST isn't defined yet — but
	 * a REST request already reports `is_admin() === false` there, which is
	 * the answer we want. admin-ajax is the one that lies: it sets is_admin()
	 * even though it is serving the front end, so it gets ruled out by hand.
	 */
	private static function is_admin_screen() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		return is_admin();
	}

	/* ------------------------------------------------------------------ */
	/* The learner's choice                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * The stored preference that applies to this request, if any.
	 *
	 * @return string Supported locale, or '' to fall through.
	 */
	public static function preference() {
		if ( ! (int) Mahan_Settings::get( 'learner_language', 1 ) ) {
			return '';
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			// Signed in: the account is the source of truth. A leftover guest
			// cookie must not outrank what this person actually chose.
			return self::user_locale( $user_id );
		}

		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( self::is_supported( $cookie ) ) {
				return $cookie;
			}
		}
		return '';
	}

	/**
	 * A specific learner's stored language, ignoring the current request.
	 */
	public static function user_locale( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return '';
		}
		$saved = (string) get_user_meta( $user_id, self::META, true );
		return self::is_supported( $saved ) ? $saved : '';
	}

	/**
	 * Record a choice. An empty locale clears it and returns to the default.
	 *
	 * Guests are recorded too — in the cookie rather than on an account — so a
	 * locale still comes back for them. Only '' means "no choice in effect".
	 *
	 * @return string The locale now in effect ('' when cleared or refused).
	 */
	public static function set_preference( $user_id, $locale ) {
		$locale  = self::is_supported( $locale ) ? (string) $locale : '';
		$user_id = (int) $user_id;

		if ( $user_id ) {
			if ( '' === $locale ) {
				delete_user_meta( $user_id, self::META );
			} else {
				update_user_meta( $user_id, self::META, $locale );
			}
		}

		self::set_cookie( $locale );
		return $locale;
	}

	/**
	 * Mirror the choice into a cookie.
	 *
	 * Signed-in learners get it too, so the very next page load is already in
	 * the new language even if the account write lands on a replica lagging
	 * behind. It is only ever read as a fallback, never as an override.
	 */
	private static function set_cookie( $locale ) {
		if ( headers_sent() ) {
			return;
		}
		$expires = ( '' === $locale ) ? time() - YEAR_IN_SECONDS : time() + YEAR_IN_SECONDS;
		setcookie(
			self::COOKIE,
			$locale,
			$expires,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	/**
	 * Carry a guest's language choice onto the account they just signed into.
	 *
	 * @param string  $user_login Login name (unused).
	 * @param WP_User $user       The user.
	 */
	public static function adopt_guest_choice( $user_login, $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		if ( '' !== self::user_locale( $user->ID ) ) {
			return; // Already decided — don't overwrite it with a stale cookie.
		}
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( self::is_supported( $cookie ) ) {
			update_user_meta( $user->ID, self::META, $cookie );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rendering for someone else                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Run a callback with the academy speaking a given learner's language.
	 *
	 * Only this plugin's catalog is swapped — `switch_to_locale()` would move
	 * the whole site, which is far more than a background job needs.
	 *
	 * @param int      $user_id  Learner whose language to use.
	 * @param callable $callback Work to run.
	 * @return mixed Whatever the callback returns.
	 */
	public static function with_user( $user_id, $callback ) {
		$locale = self::user_locale( $user_id );
		if ( '' === $locale || $locale === self::current() ) {
			return call_user_func( $callback );
		}

		$previous       = self::$override;
		self::$override = $locale;
		self::reload();
		try {
			return call_user_func( $callback );
		} finally {
			self::$override = $previous;
			self::reload();
		}
	}

	private static function reload() {
		unload_textdomain( self::DOMAIN );
		load_plugin_textdomain( self::DOMAIN, false, dirname( MAHAN_BASENAME ) . '/languages' );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: user profile field                                           */
	/* ------------------------------------------------------------------ */

	public static function user_field( $user ) {
		$available = self::available();
		if ( count( $available ) < 2 ) {
			return;
		}
		$current = self::user_locale( $user->ID );
		?>
		<h2><?php esc_html_e( 'Mahan Academy', 'mahan-academy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="mahan_lang"><?php esc_html_e( 'Academy language', 'mahan-academy' ); ?></label></th>
				<td>
					<select name="mahan_lang" id="mahan_lang">
						<option value=""><?php esc_html_e( 'Site default', 'mahan-academy' ); ?></option>
						<?php foreach ( $available as $locale => $meta ) : ?>
							<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $current, $locale ); ?>>
								<?php echo esc_html( $meta['native'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The language this learner reads the academy in. Does not change the rest of the site.', 'mahan-academy' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_user_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['mahan_lang'] ) ) {
			return;
		}
		check_admin_referer( 'update-user_' . $user_id );
		$locale = sanitize_text_field( wp_unslash( $_POST['mahan_lang'] ) );
		if ( '' === $locale ) {
			delete_user_meta( $user_id, self::META );
			return;
		}
		if ( self::is_supported( $locale ) ) {
			update_user_meta( $user_id, self::META, $locale );
		}
	}

	/* ------------------------------------------------------------------ */
	/* AI                                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * The instruction that makes the tutor answer in the learner's language.
	 *
	 * Translating the buttons around a tutor that only speaks English is a
	 * half-finished job: the part of the product a learner actually talks to
	 * has to answer them back.
	 *
	 * @param int $user_id Learner.
	 * @return string Instruction line, or '' when nothing needs saying.
	 */
	public static function ai_instruction( $user_id ) {
		$locale = self::user_locale( $user_id );
		if ( '' === $locale ) {
			$locale = self::current();
		}
		if ( '' === $locale || 0 === strpos( $locale, 'en' ) ) {
			return '';
		}
		$languages = self::languages();
		$name      = isset( $languages[ $locale ]['native'] ) ? $languages[ $locale ]['native'] : $locale;
		return 'Reply in ' . $name . '. Keep technical terms, code, and product names in their original form.';
	}
}
