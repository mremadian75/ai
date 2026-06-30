<?php
/**
 * Meta boxes for the Course CPT: subtitle, level, estimated hours, outcomes.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Course_Meta {

	const NONCE = 'mahan_course_meta_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . Mahan_CPT::COURSE, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function register() {
		add_meta_box(
			'mahan_course_settings',
			__( 'Course settings', 'mahan-academy' ),
			array( __CLASS__, 'render' ),
			Mahan_CPT::COURSE,
			'normal',
			'high'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$subtitle = Mahan_Utils::meta_str( $post->ID, Mahan_Courses::M_SUBTITLE, '' );
		$level    = Mahan_Utils::meta_str( $post->ID, Mahan_Courses::M_LEVEL, 'beginner' );
		$hours    = Mahan_Utils::meta_int( $post->ID, Mahan_Courses::M_EST_HOURS, 0 );
		$outcomes = Mahan_Utils::meta_str( $post->ID, Mahan_Courses::M_OUTCOMES, '' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="mahan_subtitle"><?php esc_html_e( 'Subtitle', 'mahan-academy' ); ?></label></th>
				<td><input type="text" id="mahan_subtitle" name="mahan_subtitle" value="<?php echo esc_attr( $subtitle ); ?>" class="large-text" />
				<p class="description"><?php esc_html_e( 'Short one-line tagline shown on the course card and hero.', 'mahan-academy' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="mahan_level"><?php esc_html_e( 'Level', 'mahan-academy' ); ?></label></th>
				<td>
					<select id="mahan_level" name="mahan_level">
						<?php
						foreach ( array(
							'beginner'     => __( 'Beginner', 'mahan-academy' ),
							'intermediate' => __( 'Intermediate', 'mahan-academy' ),
							'advanced'     => __( 'Advanced', 'mahan-academy' ),
						) as $v => $l ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $level, $v, false ), esc_html( $l ) );
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mahan_est_hours"><?php esc_html_e( 'Estimated hours', 'mahan-academy' ); ?></label></th>
				<td><input type="number" id="mahan_est_hours" name="mahan_est_hours" min="0" value="<?php echo esc_attr( $hours ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="mahan_outcomes"><?php esc_html_e( "What you'll learn", 'mahan-academy' ); ?></label></th>
				<td>
					<textarea id="mahan_outcomes" name="mahan_outcomes" rows="6" class="large-text"><?php echo esc_textarea( $outcomes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One outcome per line. Shown on the course page as a checklist.', 'mahan-academy' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$subtitle = isset( $_POST['mahan_subtitle'] ) ? sanitize_text_field( wp_unslash( $_POST['mahan_subtitle'] ) ) : '';
		$level    = isset( $_POST['mahan_level'] ) ? sanitize_key( wp_unslash( $_POST['mahan_level'] ) ) : 'beginner';
		if ( ! in_array( $level, array( 'beginner', 'intermediate', 'advanced' ), true ) ) {
			$level = 'beginner';
		}
		$hours    = isset( $_POST['mahan_est_hours'] ) ? absint( $_POST['mahan_est_hours'] ) : 0;
		$outcomes = isset( $_POST['mahan_outcomes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mahan_outcomes'] ) ) : '';

		update_post_meta( $post_id, Mahan_Courses::M_SUBTITLE, $subtitle );
		update_post_meta( $post_id, Mahan_Courses::M_LEVEL, $level );
		update_post_meta( $post_id, Mahan_Courses::M_EST_HOURS, $hours );
		update_post_meta( $post_id, Mahan_Courses::M_OUTCOMES, $outcomes );
	}
}
