<?php
/**
 * Transactional email notifications.
 *
 * Sends templated HTML emails on key events (enrollment, course completion,
 * new badge) and an optional daily streak reminder via wp-cron. Every message
 * is gated by its own setting and supports {{placeholders}}.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Emails {

	const CRON_HOOK = 'mahan_daily_cron';

	public static function init() {
		add_action( 'mahan_enrolled', array( __CLASS__, 'on_enrolled' ), 10, 2 );
		add_action( 'mahan_course_completed', array( __CLASS__, 'on_course_completed' ), 10, 2 );
		add_action( 'mahan_badge_awarded', array( __CLASS__, 'on_badge_awarded' ), 10, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );

		self::sync_cron();
	}

	private static function on() {
		return (bool) Mahan_Settings::get( 'emails_enabled', 1 );
	}

	/**
	 * Keep the daily cron in sync with the streak-reminder setting.
	 */
	public static function sync_cron() {
		$want = self::on() && (int) Mahan_Settings::get( 'email_streak', 0 );
		$has  = (bool) wp_next_scheduled( self::CRON_HOOK );
		if ( $want && ! $has ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( ! $want && $has ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Event handlers                                                      */
	/* ------------------------------------------------------------------ */

	public static function on_enrolled( $user_id, $course_id ) {
		if ( ! self::on() || ! (int) Mahan_Settings::get( 'email_welcome', 1 ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$map = self::base_map( $user );
		$map['course'] = get_the_title( $course_id );
		self::send(
			$user->user_email,
			Mahan_Settings::get( 'email_welcome_subject' ),
			Mahan_Settings::get( 'email_welcome_body' ),
			$map
		);
	}

	public static function on_course_completed( $user_id, $course_id ) {
		if ( ! self::on() || ! (int) Mahan_Settings::get( 'email_complete', 1 ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$map = self::base_map( $user );
		$map['course'] = get_the_title( $course_id );
		self::send(
			$user->user_email,
			Mahan_Settings::get( 'email_complete_subject' ),
			Mahan_Settings::get( 'email_complete_body' ),
			$map
		);
	}

	public static function on_badge_awarded( $user_id, $badge_key ) {
		if ( ! self::on() || ! (int) Mahan_Settings::get( 'email_badge', 0 ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$title = $badge_key;
		foreach ( Mahan_Badges::defs() as $def ) {
			if ( $def['key'] === $badge_key ) {
				$title = $def['icon'] . ' ' . $def['title'];
				break;
			}
		}
		$map = self::base_map( $user );
		$map['badge'] = $title;
		self::send(
			$user->user_email,
			Mahan_Settings::get( 'email_badge_subject' ),
			Mahan_Settings::get( 'email_badge_body' ),
			$map
		);
	}

	/* ------------------------------------------------------------------ */
	/* Daily streak reminder                                               */
	/* ------------------------------------------------------------------ */

	public static function run_daily() {
		if ( ! self::on() || ! (int) Mahan_Settings::get( 'email_streak', 0 ) ) {
			return;
		}
		global $wpdb;
		$table = Mahan_DB::stats();
		$today = Mahan_Utils::today();
		// Learners with a live streak who haven't been active today.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, streak FROM {$table} WHERE streak > 0 AND ( last_active_date IS NULL OR last_active_date < %s ) LIMIT 200",
				$today
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$user = get_userdata( (int) $r['user_id'] );
			if ( ! $user ) {
				continue;
			}
			$map = self::base_map( $user );
			$map['streak'] = (int) $r['streak'];
			self::send(
				$user->user_email,
				Mahan_Settings::get( 'email_streak_subject' ),
				Mahan_Settings::get( 'email_streak_body' ),
				$map
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	private static function base_map( $user ) {
		return array(
			'name'         => $user->display_name,
			'site'         => get_bloginfo( 'name' ),
			'login_url'    => wp_login_url(),
			'academy_url'  => Mahan_Front::app_url(),
		);
	}

	/**
	 * Render + send one HTML email.
	 */
	public static function send( $to, $subject, $body, $map ) {
		$subject = Mahan_Utils::render_placeholders( (string) $subject, $map );
		$body    = Mahan_Utils::render_placeholders( (string) $body, $map );
		if ( '' === trim( $subject ) || '' === trim( $body ) ) {
			return false;
		}

		$from_name  = trim( (string) Mahan_Settings::get( 'email_from_name' ) );
		$from_email = trim( (string) Mahan_Settings::get( 'email_from_email' ) );
		if ( '' === $from_name ) {
			$from_name = get_bloginfo( 'name' );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$html = self::wrap( $subject, wpautop( wp_kses_post( $body ) ) );
		return wp_mail( $to, wp_strip_all_tags( $subject ), $html, $headers );
	}

	/**
	 * Minimal responsive HTML wrapper.
	 */
	private static function wrap( $subject, $inner ) {
		$primary = sanitize_hex_color( (string) Mahan_Settings::get( 'primary_color', '#4f46e5' ) ) ?: '#4f46e5';
		$site    = esc_html( get_bloginfo( 'name' ) );
		$url     = esc_url( Mahan_Front::app_url() );
		ob_start();
		?>
		<div style="background:#f6f7fb;padding:24px 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
			<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e7e9f0;">
				<div style="background:<?php echo esc_attr( $primary ); ?>;color:#fff;padding:20px 28px;font-weight:800;font-size:18px;"><?php echo $site; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<div style="padding:26px 28px;color:#1f2433;font-size:15px;line-height:1.6;"><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<div style="padding:16px 28px;border-top:1px solid #e7e9f0;font-size:12px;color:#6b7280;">
					<a href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="color:<?php echo esc_attr( $primary ); ?>;text-decoration:none;"><?php echo $site; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Default templates                                                   */
	/* ------------------------------------------------------------------ */

	public static function default_templates() {
		return array(
			'email_welcome_subject'  => __( 'Welcome to {{course}} 🎉', 'mahan-academy' ),
			'email_welcome_body'     => __( "Hi {{name}},\n\nYou're enrolled in <strong>{{course}}</strong> on {{site}}. Jump back in whenever you're ready — your AI tutor is standing by.\n\nHappy learning!", 'mahan-academy' ),
			'email_complete_subject' => __( 'You completed {{course}}! 🎓', 'mahan-academy' ),
			'email_complete_body'    => __( "Congratulations {{name}}!\n\nYou finished <strong>{{course}}</strong>. That's a real milestone. Keep the momentum going and explore what's next on {{site}}.", 'mahan-academy' ),
			'email_badge_subject'    => __( 'New achievement unlocked: {{badge}}', 'mahan-academy' ),
			'email_badge_body'       => __( "Nice work {{name}}!\n\nYou just earned <strong>{{badge}}</strong>. Check your dashboard to see all your badges.", 'mahan-academy' ),
			'email_streak_subject'   => __( "Keep your {{streak}}-day streak alive 🔥", 'mahan-academy' ),
			'email_streak_body'      => __( "Hi {{name}},\n\nYou're on a <strong>{{streak}}-day streak</strong> — don't let it slip! A few minutes of learning today keeps it going.", 'mahan-academy' ),
		);
	}
}
