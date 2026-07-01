<?php
/**
 * Meta box for the Learning Path CPT: subtitle + an ordered course picker.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Path_Meta {

	const NONCE = 'mahan_path_meta_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . Mahan_CPT::PATH, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function register() {
		add_meta_box(
			'mahan_path_courses',
			__( 'Path courses', 'mahan-academy' ),
			array( __CLASS__, 'render' ),
			Mahan_CPT::PATH,
			'normal',
			'high'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$subtitle = Mahan_Utils::meta_str( $post->ID, Mahan_Paths::M_SUBTITLE, '' );

		$selected = array();
		foreach ( Mahan_Paths::course_ids( $post->ID ) as $cid ) {
			$selected[] = array( 'id' => $cid, 'title' => get_the_title( $cid ) );
		}
		$all = array();
		foreach ( Mahan_Courses::get_courses() as $c ) {
			$all[] = array( 'id' => (int) $c->ID, 'title' => get_the_title( $c->ID ) );
		}
		?>
		<p>
			<label for="mahan_path_subtitle"><strong><?php esc_html_e( 'Subtitle', 'mahan-academy' ); ?></strong></label><br />
			<input type="text" id="mahan_path_subtitle" name="mahan_path_subtitle" value="<?php echo esc_attr( $subtitle ); ?>" class="large-text" />
		</p>
		<div
			id="mahan-path-builder"
			data-selected="<?php echo esc_attr( wp_json_encode( $selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>"
			data-all="<?php echo esc_attr( wp_json_encode( $all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">
			<p><strong><?php esc_html_e( 'Courses in this path (drag to reorder)', 'mahan-academy' ); ?></strong></p>
			<ul class="mahan-path-list"></ul>
			<p class="mahan-path-add">
				<select class="mahan-path-select"></select>
				<button type="button" class="button button-secondary mahan-path-add-btn">+ <?php esc_html_e( 'Add course', 'mahan-academy' ); ?></button>
			</p>
			<input type="hidden" id="mahan_path_courses" name="mahan_path_courses" value="" />
		</div>
		<p class="description"><?php esc_html_e( 'Learners see the courses in this order with their combined progress. Paths are a guide — they do not lock courses.', 'mahan-academy' ); ?></p>
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

		$subtitle = isset( $_POST['mahan_path_subtitle'] ) ? sanitize_text_field( wp_unslash( $_POST['mahan_path_subtitle'] ) ) : '';
		update_post_meta( $post_id, Mahan_Paths::M_SUBTITLE, $subtitle );

		$raw = isset( $_POST['mahan_path_courses'] ) ? wp_unslash( (string) $_POST['mahan_path_courses'] ) : '[]';
		$ids = json_decode( $raw, true );
		$clean = array();
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id && Mahan_CPT::COURSE === get_post_type( $id ) ) {
					$clean[] = $id;
				}
			}
		}
		update_post_meta( $post_id, Mahan_Paths::M_COURSES, array_values( array_unique( $clean ) ) );
	}
}
