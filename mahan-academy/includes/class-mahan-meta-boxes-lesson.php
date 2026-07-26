<?php
/**
 * Meta boxes for the Lesson CPT: course/unit assignment, ordering, XP,
 * estimated minutes, type, plus the interactive exercise builder.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Lesson_Meta {

	const NONCE = 'mahan_lesson_meta_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . Mahan_CPT::LESSON, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function register() {
		add_meta_box(
			'mahan_lesson_settings',
			__( 'Lesson settings', 'mahan-academy' ),
			array( __CLASS__, 'render_settings' ),
			Mahan_CPT::LESSON,
			'side',
			'high'
		);
		add_meta_box(
			'mahan_lesson_exercises',
			__( 'Interactive exercises', 'mahan-academy' ),
			array( __CLASS__, 'render_exercises' ),
			Mahan_CPT::LESSON,
			'normal',
			'high'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Side panel: settings                                                */
	/* ------------------------------------------------------------------ */

	public static function render_settings( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$course_id  = Mahan_Utils::meta_int( $post->ID, Mahan_Courses::M_COURSE_ID, 0 );
		$unit       = Mahan_Utils::meta_str( $post->ID, Mahan_Courses::M_UNIT, '' );
		$unit_order = Mahan_Utils::meta_int( $post->ID, Mahan_Courses::M_UNIT_ORDER, 0 );
		$order      = Mahan_Utils::meta_int( $post->ID, Mahan_Courses::M_ORDER, 0 );
		$xp         = Mahan_Utils::meta_int( $post->ID, Mahan_Courses::M_XP, 0 );
		$est_min    = Mahan_Utils::meta_int( $post->ID, Mahan_Courses::M_EST_MIN, 0 );
		$type       = Mahan_Utils::meta_str( $post->ID, Mahan_Courses::M_TYPE, 'reading' );
		$video      = Mahan_Utils::meta_str( $post->ID, Mahan_Courses::M_VIDEO, '' );

		$courses = Mahan_Courses::get_courses();
		?>
		<p>
			<label for="mahan_course_id"><strong><?php esc_html_e( 'Course', 'mahan-academy' ); ?></strong></label><br />
			<select id="mahan_course_id" name="mahan_course_id" style="width:100%">
				<option value="0">— <?php esc_html_e( 'Select a course', 'mahan-academy' ); ?> —</option>
				<?php foreach ( $courses as $c ) : ?>
					<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $course_id, $c->ID ); ?>>
						<?php echo esc_html( get_the_title( $c->ID ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="mahan_unit"><strong><?php esc_html_e( 'Unit (section) title', 'mahan-academy' ); ?></strong></label><br />
			<input type="text" id="mahan_unit" name="mahan_unit" value="<?php echo esc_attr( $unit ); ?>" style="width:100%" />
			<small><?php esc_html_e( 'Lessons sharing a unit title are grouped together.', 'mahan-academy' ); ?></small>
		</p>
		<p>
			<label for="mahan_unit_order"><?php esc_html_e( 'Unit order', 'mahan-academy' ); ?></label>
			<input type="number" id="mahan_unit_order" name="mahan_unit_order" value="<?php echo esc_attr( $unit_order ); ?>" min="0" style="width:70px" />
			&nbsp;
			<label for="mahan_order"><?php esc_html_e( 'Lesson order', 'mahan-academy' ); ?></label>
			<input type="number" id="mahan_order" name="mahan_order" value="<?php echo esc_attr( $order ); ?>" min="0" style="width:70px" />
		</p>
		<p>
			<label for="mahan_type"><strong><?php esc_html_e( 'Type', 'mahan-academy' ); ?></strong></label><br />
			<select id="mahan_type" name="mahan_type" style="width:100%">
				<?php
				foreach ( array(
					'reading'  => __( 'Reading', 'mahan-academy' ),
					'practice' => __( 'Practice', 'mahan-academy' ),
					'video'    => __( 'Video', 'mahan-academy' ),
				) as $v => $l ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $type, $v, false ), esc_html( $l ) );
				}
				?>
			</select>
		</p>
		<p>
			<label for="mahan_video"><strong><?php esc_html_e( 'Video', 'mahan-academy' ); ?></strong></label><br />
			<input type="url" id="mahan_video" name="mahan_video" value="<?php echo esc_attr( $video ); ?>" style="width:100%" placeholder="https://www.youtube.com/watch?v=…" />
			<small><?php esc_html_e( 'YouTube, Vimeo, or a direct .mp4 / .webm link', 'mahan-academy' ); ?></small>
		</p>
		<p>
			<label for="mahan_xp"><?php esc_html_e( 'XP reward (0 = default)', 'mahan-academy' ); ?></label>
			<input type="number" id="mahan_xp" name="mahan_xp" value="<?php echo esc_attr( $xp ); ?>" min="0" style="width:90px" />
		</p>
		<p>
			<label for="mahan_est_min"><?php esc_html_e( 'Estimated minutes', 'mahan-academy' ); ?></label>
			<input type="number" id="mahan_est_min" name="mahan_est_min" value="<?php echo esc_attr( $est_min ); ?>" min="0" style="width:90px" />
		</p>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Main panel: exercise builder                                        */
	/* ------------------------------------------------------------------ */

	public static function render_exercises( $post ) {
		$exercises = Mahan_Courses::get_exercises( $post->ID );
		?>
		<div id="mahan-exercise-builder"
			data-lesson="<?php echo esc_attr( $post->ID ); ?>"
			data-exercises="<?php echo esc_attr( wp_json_encode( $exercises, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">
			<div class="mahan-ex-list"></div>
			<p>
				<button type="button" class="button button-secondary" id="mahan-add-exercise">
					+ <?php esc_html_e( 'Add exercise', 'mahan-academy' ); ?>
				</button>
			</p>
			<input type="hidden" id="mahan_exercises_json" name="mahan_exercises_json" value="" />
		</div>
		<p class="description">
			<?php esc_html_e( 'Multiple-choice is graded instantly. Short answer, reflection, and prompt-writing tasks are graded by AI against your rubric.', 'mahan-academy' ); ?>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Save                                                                */
	/* ------------------------------------------------------------------ */

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

		$course_id  = isset( $_POST['mahan_course_id'] ) ? absint( $_POST['mahan_course_id'] ) : 0;
		$unit       = isset( $_POST['mahan_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['mahan_unit'] ) ) : '';
		$unit_order = isset( $_POST['mahan_unit_order'] ) ? absint( $_POST['mahan_unit_order'] ) : 0;
		$order      = isset( $_POST['mahan_order'] ) ? absint( $_POST['mahan_order'] ) : 0;
		$xp         = isset( $_POST['mahan_xp'] ) ? absint( $_POST['mahan_xp'] ) : 0;
		$est_min    = isset( $_POST['mahan_est_min'] ) ? absint( $_POST['mahan_est_min'] ) : 0;
		$type       = isset( $_POST['mahan_type'] ) ? sanitize_key( wp_unslash( $_POST['mahan_type'] ) ) : 'reading';
		if ( ! in_array( $type, array( 'reading', 'practice', 'video' ), true ) ) {
			$type = 'reading';
		}

		update_post_meta( $post_id, Mahan_Courses::M_COURSE_ID, $course_id );
		update_post_meta( $post_id, Mahan_Courses::M_UNIT, $unit );
		update_post_meta( $post_id, Mahan_Courses::M_UNIT_ORDER, $unit_order );
		update_post_meta( $post_id, Mahan_Courses::M_ORDER, $order );
		update_post_meta( $post_id, Mahan_Courses::M_XP, $xp );
		update_post_meta( $post_id, Mahan_Courses::M_EST_MIN, $est_min );
		update_post_meta( $post_id, Mahan_Courses::M_TYPE, $type );

		// Same rule as the studio: empty clears the meta rather than storing ''.
		$video = isset( $_POST['mahan_video'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['mahan_video'] ) ) ) : '';
		if ( '' === $video ) {
			delete_post_meta( $post_id, Mahan_Courses::M_VIDEO );
		} else {
			update_post_meta( $post_id, Mahan_Courses::M_VIDEO, $video );
		}

		// Exercises (built by JS, posted as JSON).
		$raw = isset( $_POST['mahan_exercises_json'] ) ? wp_unslash( (string) $_POST['mahan_exercises_json'] ) : '';
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$clean = self::sanitize_exercises( $decoded );
			update_post_meta( $post_id, Mahan_Courses::M_EXERCISES, $clean );
		}
	}

	private static function sanitize_exercises( $list ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $i => $ex ) {
			if ( ! is_array( $ex ) ) {
				continue;
			}
			$type = isset( $ex['type'] ) ? sanitize_key( (string) $ex['type'] ) : 'multiple_choice';
			$valid_types = array( 'multiple_choice', 'true_false', 'fill_blank', 'short_answer', 'reflection', 'prompt_task' );
			if ( ! in_array( $type, $valid_types, true ) ) {
				$type = 'multiple_choice';
			}

			$key = isset( $ex['key'] ) ? sanitize_key( (string) $ex['key'] ) : '';
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$key = 'ex_' . ( $i + 1 ) . '_' . wp_generate_password( 6, false, false );
			}
			$seen[ $key ] = true;

			$row = array(
				'key'      => $key,
				'type'     => $type,
				'question' => isset( $ex['question'] ) ? sanitize_textarea_field( (string) $ex['question'] ) : '',
				'hint'     => isset( $ex['hint'] ) ? sanitize_textarea_field( (string) $ex['hint'] ) : '',
				'xp'       => isset( $ex['xp'] ) ? absint( $ex['xp'] ) : 0,
			);

			if ( 'multiple_choice' === $type || 'true_false' === $type ) {
				if ( 'true_false' === $type ) {
					$opts = array( __( 'True', 'mahan-academy' ), __( 'False', 'mahan-academy' ) );
				} else {
					$opts = isset( $ex['options'] ) && is_array( $ex['options'] ) ? $ex['options'] : array();
				}
				$row['options'] = array_values( array_map(
					function ( $o ) {
						return sanitize_text_field( (string) $o );
					},
					$opts
				) );
				$row['answer']             = isset( $ex['answer'] ) ? (int) $ex['answer'] : 0;
				$row['feedback_correct']   = isset( $ex['feedback_correct'] ) ? sanitize_textarea_field( (string) $ex['feedback_correct'] ) : '';
				$row['feedback_incorrect'] = isset( $ex['feedback_incorrect'] ) ? sanitize_textarea_field( (string) $ex['feedback_incorrect'] ) : '';
			} elseif ( 'fill_blank' === $type ) {
				$row['answer_text']    = isset( $ex['answer_text'] ) ? sanitize_text_field( (string) $ex['answer_text'] ) : '';
				$row['accept']         = isset( $ex['accept'] ) && is_array( $ex['accept'] )
					? array_values( array_map( 'sanitize_text_field', $ex['accept'] ) )
					: array();
				$row['case_sensitive'] = ! empty( $ex['case_sensitive'] ) ? 1 : 0;
			} else {
				$row['rubric']      = isset( $ex['rubric'] ) ? sanitize_textarea_field( (string) $ex['rubric'] ) : '';
				$row['placeholder'] = isset( $ex['placeholder'] ) ? sanitize_text_field( (string) $ex['placeholder'] ) : '';
				if ( 'prompt_task' === $type ) {
					$row['task'] = isset( $ex['task'] ) ? sanitize_textarea_field( (string) $ex['task'] ) : '';
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}
