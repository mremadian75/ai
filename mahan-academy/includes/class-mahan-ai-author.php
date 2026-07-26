<?php
/**
 * AI authoring assistant for the admin.
 *
 * Helps course creators move faster: generate "what you'll learn" outcomes for
 * a course, draft lesson content from a topic, and generate interactive
 * exercises from lesson material — all through the active AI provider.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_AI_Author {

	const NONCE = 'mahan_ai_author';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_mahan_ai_outcomes', array( __CLASS__, 'ajax_outcomes' ) );
		add_action( 'wp_ajax_mahan_ai_lesson_draft', array( __CLASS__, 'ajax_lesson_draft' ) );
		add_action( 'wp_ajax_mahan_ai_exercises', array( __CLASS__, 'ajax_exercises' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lesson "AI draft" meta box                                          */
	/* ------------------------------------------------------------------ */

	public static function register() {
		add_meta_box(
			'mahan_ai_draft',
			__( 'AI assistant', 'mahan-academy' ),
			array( __CLASS__, 'render_draft' ),
			Mahan_CPT::LESSON,
			'side',
			'default'
		);
	}

	public static function render_draft( $post ) {
		if ( ! Mahan_Settings::ai_ready() ) {
			echo '<p class="description">' . esc_html__( 'Add an AI provider key in Mahan Academy → Settings to enable the assistant.', 'mahan-academy' ) . '</p>';
			return;
		}
		?>
		<div id="mahan-ai-draft" data-lesson="<?php echo esc_attr( $post->ID ); ?>">
			<p>
				<label for="mahan-ai-topic"><strong><?php esc_html_e( 'Draft this lesson from a topic', 'mahan-academy' ); ?></strong></label>
				<input type="text" id="mahan-ai-topic" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Writing effective prompts for email', 'mahan-academy' ); ?>" />
			</p>
			<p>
				<button type="button" class="button button-secondary" id="mahan-ai-draft-btn">
					✨ <?php esc_html_e( 'Generate draft', 'mahan-academy' ); ?>
				</button>
				<span class="spinner mahan-ai-spin" style="float:none"></span>
			</p>
			<div id="mahan-ai-draft-out" class="mahan-ai-out" style="display:none">
				<textarea id="mahan-ai-draft-text" rows="10" class="widefat code"></textarea>
				<p>
					<button type="button" class="button" id="mahan-ai-draft-copy"><?php esc_html_e( 'Copy', 'mahan-academy' ); ?></button>
					<span class="description"><?php esc_html_e( 'Paste into the editor (Add block → Custom HTML, or Classic).', 'mahan-academy' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Shared guard + provider-context                                     */
	/* ------------------------------------------------------------------ */

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'mahan-academy' ) ), 403 );
		}
		if ( ! Mahan_Settings::ai_ready() ) {
			wp_send_json_error( array( 'message' => __( 'AI provider is not configured.', 'mahan-academy' ) ), 400 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: course outcomes                                               */
	/* ------------------------------------------------------------------ */

	public static function ajax_outcomes() {
		self::guard();
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		if ( '' === trim( $title ) && '' === trim( $desc ) ) {
			wp_send_json_error( array( 'message' => __( 'Give the course a title first.', 'mahan-academy' ) ), 400 );
		}

		$res = Mahan_AI::complete(
			array(
				array(
					'role'    => 'system',
					'content' => 'You write concise learning outcomes for an online course that teaches people to use AI at work. '
						. 'Return 4-6 outcomes, each a short action-oriented phrase starting with a verb (e.g. "Automate weekly reports with AI"). '
						. 'Respond ONLY with a JSON object: {"outcomes": ["...", "..."]}.',
				),
				array(
					'role'    => 'user',
					'content' => "COURSE TITLE: {$title}\n\nDESCRIPTION:\n{$desc}",
				),
			),
			array( 'json' => true, 'max_tokens' => 400, 'temperature' => 0.5 )
		);
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => $res['error'] ), 502 );
		}
		$data     = Mahan_Utils::extract_json( $res['text'] );
		$outcomes = ( is_array( $data ) && isset( $data['outcomes'] ) && is_array( $data['outcomes'] ) ) ? $data['outcomes'] : array();
		$outcomes = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $outcomes ) ) ), 0, 8 );
		if ( empty( $outcomes ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not parse a result. Try again.', 'mahan-academy' ) ), 502 );
		}
		wp_send_json_success( array( 'text' => implode( "\n", $outcomes ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: lesson draft                                                  */
	/* ------------------------------------------------------------------ */

	public static function ajax_lesson_draft() {
		self::guard();
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		if ( '' === trim( $topic ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a topic.', 'mahan-academy' ) ), 400 );
		}

		$res = Mahan_AI::complete(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are an expert instructional designer for a course that teaches people to use AI in their daily work. '
						. 'Write a single lesson as clean, semantic HTML (use <h2>, <h3>, <p>, <ul>, <li>, <strong>, <blockquote>, and <pre><code> where useful — no <html>/<body> wrappers, no inline styles). '
						. 'Keep it practical and example-driven: include a short intro, 2-4 sections, at least one concrete real-work example, and a brief recap. Aim for 300-600 words.',
				),
				array( 'role' => 'user', 'content' => "LESSON TOPIC: {$topic}" ),
			),
			array( 'max_tokens' => 1500, 'temperature' => 0.6 )
		);
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => $res['error'] ), 502 );
		}
		$html = trim( $res['text'] );
		$html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/', '', $html );
		$html = wp_kses_post( $html );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: generate exercises for a lesson                               */
	/* ------------------------------------------------------------------ */

	public static function ajax_exercises() {
		self::guard();
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;
		$count     = isset( $_POST['count'] ) ? max( 1, min( 8, absint( $_POST['count'] ) ) ) : 3;
		$context   = isset( $_POST['content'] ) ? wp_strip_all_tags( wp_unslash( (string) $_POST['content'] ) ) : '';

		if ( '' === trim( $context ) && $lesson_id ) {
			$lesson  = get_post( $lesson_id );
			$context = $lesson ? wp_strip_all_tags( (string) $lesson->post_content ) : '';
			$title   = $lesson ? get_the_title( $lesson_id ) : '';
			$context = trim( $title . "\n\n" . $context );
		}
		$context = trim( preg_replace( '/\s+/', ' ', $context ) );
		if ( '' === $context ) {
			wp_send_json_error( array( 'message' => __( 'Add some lesson content first, or write a topic.', 'mahan-academy' ) ), 400 );
		}
		if ( strlen( $context ) > 4000 ) {
			$context = substr( $context, 0, 4000 );
		}

		$schema = '{"exercises":[{"type":"multiple_choice","question":"...","options":["A","B","C","D"],"answer":0,"hint":"..."},'
			. '{"type":"true_false","question":"...","answer":0,"hint":"..."},'
			. '{"type":"short_answer","question":"...","rubric":"what a good answer includes"},'
			. '{"type":"prompt_task","task":"...","rubric":"..."}]}';

		$res = Mahan_AI::complete(
			array(
				array(
					'role'    => 'system',
					'content' => "You create interactive practice exercises for a lesson that teaches using AI at work. "
						. "Generate exactly {$count} varied exercises grounded in the lesson content. "
						. 'Prefer a mix of multiple_choice (4 options, "answer" is the 0-based index of the correct option), '
						. 'true_false ("answer" is 0 for True or 1 for False), short_answer, and prompt_task. '
						. 'Keep questions concrete and answerable from the material. '
						. "Respond ONLY with JSON exactly in this shape: {$schema}",
				),
				array( 'role' => 'user', 'content' => "LESSON CONTENT:\n{$context}" ),
			),
			array( 'json' => true, 'max_tokens' => 1600, 'temperature' => 0.5 )
		);
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => $res['error'] ), 502 );
		}

		$data = Mahan_Utils::extract_json( $res['text'] );
		$list = ( is_array( $data ) && isset( $data['exercises'] ) && is_array( $data['exercises'] ) ) ? $data['exercises'] : array();
		$out  = self::normalize_exercises( $list );
		if ( empty( $out ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not parse exercises. Try again.', 'mahan-academy' ) ), 502 );
		}
		wp_send_json_success( array( 'exercises' => $out ) );
	}

	/**
	 * Coerce model output into the exercise shape the builder understands.
	 *
	 * @param array $list Raw list from the model.
	 * @return array
	 */
	private static function normalize_exercises( $list ) {
		$valid = array( 'multiple_choice', 'true_false', 'short_answer', 'reflection', 'prompt_task', 'fill_blank' );
		$out   = array();
		foreach ( (array) $list as $ex ) {
			if ( ! is_array( $ex ) ) {
				continue;
			}
			$type = isset( $ex['type'] ) ? sanitize_key( (string) $ex['type'] ) : 'multiple_choice';
			if ( ! in_array( $type, $valid, true ) ) {
				$type = 'multiple_choice';
			}
			$row = array(
				'type'     => $type,
				'question' => isset( $ex['question'] ) ? sanitize_textarea_field( (string) $ex['question'] ) : '',
				'hint'     => isset( $ex['hint'] ) ? sanitize_textarea_field( (string) $ex['hint'] ) : '',
			);
			if ( 'multiple_choice' === $type ) {
				$opts = isset( $ex['options'] ) && is_array( $ex['options'] ) ? array_values( array_map( 'sanitize_text_field', $ex['options'] ) ) : array();
				if ( count( $opts ) < 2 ) {
					continue;
				}
				$row['options'] = $opts;
				$row['answer']  = isset( $ex['answer'] ) ? max( 0, min( count( $opts ) - 1, (int) $ex['answer'] ) ) : 0;
			} elseif ( 'true_false' === $type ) {
				$row['options'] = array( __( 'True', 'mahan-academy' ), __( 'False', 'mahan-academy' ) );
				$row['answer']  = ( isset( $ex['answer'] ) && (int) $ex['answer'] === 1 ) ? 1 : 0;
			} elseif ( 'fill_blank' === $type ) {
				$row['answer_text'] = isset( $ex['answer_text'] ) ? sanitize_text_field( (string) $ex['answer_text'] ) : ( isset( $ex['answer'] ) ? sanitize_text_field( (string) $ex['answer'] ) : '' );
			} else {
				$row['rubric'] = isset( $ex['rubric'] ) ? sanitize_textarea_field( (string) $ex['rubric'] ) : '';
				if ( 'prompt_task' === $type ) {
					$row['task']     = isset( $ex['task'] ) ? sanitize_textarea_field( (string) $ex['task'] ) : $row['question'];
					$row['question'] = $row['question'] ?: $row['task'];
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}
