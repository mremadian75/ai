<?php
/**
 * Real-time AI tutor over Server-Sent Events (SSE).
 *
 * The browser POSTs a message to admin-ajax (action=mahan_tutor_stream); this
 * class relays the provider's streaming tokens to the browser as they arrive,
 * then persists the exchange to the chat table. A non-streaming REST fallback
 * (Mahan_REST::tutor) covers hosts where cURL streaming is unavailable.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_AI_Stream {

	const ACTION        = 'mahan_tutor_stream';
	const HISTORY_LIMIT = 10;

	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle_guest' ) );
	}

	public static function handle_guest() {
		status_header( 403 );
		self::send_headers();
		self::emit( array( 'error' => __( 'Please log in to use the tutor.', 'mahan-academy' ) ) );
		self::emit( array( 'done' => true ) );
		exit;
	}

	public static function handle() {
		// Auth + nonce.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'mahan_ajax' ) ) {
			status_header( 403 );
			self::send_headers();
			self::emit( array( 'error' => __( 'Security check failed. Please refresh and try again.', 'mahan-academy' ) ) );
			self::emit( array( 'done' => true ) );
			exit;
		}

		$user_id = get_current_user_id();

		// Read JSON body.
		$raw  = file_get_contents( 'php://input' );
		$body = json_decode( (string) $raw, true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$message   = isset( $body['message'] ) ? trim( wp_strip_all_tags( (string) $body['message'] ) ) : '';
		$lesson_id = isset( $body['lesson_id'] ) ? absint( $body['lesson_id'] ) : 0;

		self::send_headers();

		if ( '' === $message ) {
			self::emit( array( 'error' => __( 'Empty message.', 'mahan-academy' ) ) );
			self::emit( array( 'done' => true ) );
			exit;
		}
		if ( ! Mahan_Settings::ai_ready() ) {
			self::emit( array( 'error' => __( 'The AI tutor is not configured yet. Add an API key in Mahan Academy settings.', 'mahan-academy' ) ) );
			self::emit( array( 'done' => true ) );
			exit;
		}

		$messages = self::build_messages( $user_id, $lesson_id, $message );

		if ( ! function_exists( 'curl_init' ) ) {
			// No cURL: do a single non-streaming completion and emit it whole.
			$res = Mahan_AI::complete( $messages, array( 'max_tokens' => (int) Mahan_Settings::get( 'max_tokens', 1024 ) ) );
			if ( $res['ok'] ) {
				self::emit( array( 't' => $res['text'] ) );
				self::persist( $user_id, $lesson_id, $message, $res['text'] );
			} else {
				self::emit( array( 'error' => $res['error'] ) );
			}
			self::emit( array( 'done' => true ) );
			exit;
		}

		$full = self::stream( $messages );
		if ( '' !== trim( $full ) ) {
			self::persist( $user_id, $lesson_id, $message, $full );
		}
		self::emit( array( 'done' => true ) );
		exit;
	}

	/**
	 * Non-streaming tutor reply (used by the REST fallback). Persists the
	 * exchange and returns the standard AI result array.
	 *
	 * @param int    $user_id   User id.
	 * @param int    $lesson_id Lesson id.
	 * @param string $message   Learner message.
	 * @return array { ok, text, error }
	 */
	public static function reply( $user_id, $lesson_id, $message ) {
		$message = trim( wp_strip_all_tags( (string) $message ) );
		if ( '' === $message ) {
			return array( 'ok' => false, 'text' => '', 'error' => __( 'Empty message.', 'mahan-academy' ) );
		}
		if ( ! Mahan_Settings::ai_ready() ) {
			return array( 'ok' => false, 'text' => '', 'error' => __( 'The AI tutor is not configured yet.', 'mahan-academy' ) );
		}
		$messages = self::build_messages( (int) $user_id, (int) $lesson_id, $message );
		$res      = Mahan_AI::complete( $messages, array( 'max_tokens' => (int) Mahan_Settings::get( 'max_tokens', 1024 ) ) );
		if ( $res['ok'] && '' !== trim( $res['text'] ) ) {
			self::persist( (int) $user_id, (int) $lesson_id, $message, $res['text'] );
		}
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* Message assembly                                                    */
	/* ------------------------------------------------------------------ */

	private static function build_messages( $user_id, $lesson_id, $message ) {
		$system = Mahan_Profile::render_template( (string) Mahan_Settings::get( 'tutor_system_prompt' ), $user_id );

		if ( $lesson_id ) {
			$lesson = get_post( $lesson_id );
			if ( $lesson && Mahan_CPT::LESSON === $lesson->post_type ) {
				$course_id    = Mahan_Courses::get_lesson_course_id( $lesson_id );
				$course_title = $course_id ? get_the_title( $course_id ) : '';
				$plain        = wp_strip_all_tags( (string) $lesson->post_content );
				$plain        = trim( preg_replace( '/\s+/', ' ', $plain ) );
				if ( strlen( $plain ) > 2000 ) {
					$plain = substr( $plain, 0, 2000 ) . '…';
				}
				$system .= "\n\nCURRENT LESSON CONTEXT\nCourse: {$course_title}\nLesson: " . get_the_title( $lesson_id ) . "\nLesson material:\n{$plain}";
			}
		}

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( self::recent_history( $user_id, $lesson_id ) as $h ) {
			$messages[] = array( 'role' => $h['role'], 'content' => $h['content'] );
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );
		return $messages;
	}

	private static function recent_history( $user_id, $lesson_id ) {
		global $wpdb;
		$table = Mahan_DB::chat();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$table} WHERE user_id = %d AND lesson_id = %d ORDER BY id DESC LIMIT %d",
				(int) $user_id,
				(int) $lesson_id,
				self::HISTORY_LIMIT
			),
			ARRAY_A
		);
		$rows = array_reverse( (array) $rows );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'role'    => ( 'assistant' === $r['role'] ) ? 'assistant' : 'user',
				'content' => (string) $r['content'],
			);
		}
		return $out;
	}

	private static function persist( $user_id, $lesson_id, $user_msg, $assistant_msg ) {
		global $wpdb;
		$course_id = $lesson_id ? Mahan_Courses::get_lesson_course_id( $lesson_id ) : 0;
		$now       = Mahan_Utils::now_mysql();
		$table     = Mahan_DB::chat();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'    => (int) $user_id,
				'lesson_id'  => (int) $lesson_id,
				'course_id'  => (int) $course_id,
				'role'       => 'user',
				'content'    => (string) $user_msg,
				'created_at' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'    => (int) $user_id,
				'lesson_id'  => (int) $lesson_id,
				'course_id'  => (int) $course_id,
				'role'       => 'assistant',
				'content'    => (string) $assistant_msg,
				'created_at' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* SSE plumbing                                                        */
	/* ------------------------------------------------------------------ */

	private static function send_headers() {
		if ( headers_sent() ) {
			return;
		}
		// Disable any compression/buffering so chunks flush immediately.
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		@ini_set( 'output_buffering', '0' );        // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		echo ': open' . "\n\n"; // Opening comment to establish the stream.
		self::flush();
	}

	private static function emit( $data ) {
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		self::flush();
	}

	private static function flush() {
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@flush();        // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Stream from the active provider, relaying token deltas. Returns the full text.
	 *
	 * @param array $messages Normalized messages.
	 * @return string
	 */
	private static function stream( $messages ) {
		$provider = Mahan_Settings::get( 'ai_provider', 'anthropic' );
		$model    = Mahan_AI::model_for( $provider );
		$key      = Mahan_AI::key_for( $provider );
		$max      = (int) Mahan_Settings::get( 'max_tokens', 1024 );
		$temp     = (float) Mahan_Settings::get( 'temperature', 0.7 );

		list( $system, $turns ) = Mahan_AI::split_system( $messages );
		$request = self::provider_request( $provider, $model, $key, $system, $turns, $max, $temp );
		if ( ! $request ) {
			self::emit( array( 'error' => __( 'Unsupported provider.', 'mahan-academy' ) ) );
			return '';
		}

		$buffer = '';
		$full   = '';

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $request['url'] );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $request['body'] ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $request['headers'] );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $chunk ) use ( &$buffer, &$full, $provider ) {
				$buffer .= $chunk;
				while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
					$line   = substr( $buffer, 0, $pos );
					$buffer = substr( $buffer, $pos + 1 );
					$line   = rtrim( $line, "\r" );
					if ( '' === $line || 0 === strpos( $line, ':' ) || 0 === strpos( $line, 'event:' ) ) {
						continue;
					}
					if ( 0 === strpos( $line, 'data:' ) ) {
						$payload = trim( substr( $line, 5 ) );
						if ( '[DONE]' === $payload || '' === $payload ) {
							continue;
						}
						$json = json_decode( $payload, true );
						if ( ! is_array( $json ) ) {
							continue;
						}
						$delta = self::extract_delta( $provider, $json );
						if ( null !== $delta && '' !== $delta ) {
							$full .= $delta;
							self::emit( array( 't' => $delta ) );
						}
					}
				}
				if ( connection_aborted() ) {
					return 0; // Abort the transfer.
				}
				return strlen( $chunk );
			}
		);

		$ok = curl_exec( $ch );
		if ( false === $ok && '' === $full ) {
			$err = curl_error( $ch );
			Mahan_Logger::error( 'stream curl error', array( 'msg' => $err ) );
			self::emit( array( 'error' => __( 'The tutor connection failed. Please try again.', 'mahan-academy' ) ) );
		}
		curl_close( $ch );
		return $full;
	}

	private static function provider_request( $provider, $model, $key, $system, $turns, $max, $temp ) {
		if ( 'anthropic' === $provider ) {
			$body = array(
				'model'       => $model,
				'max_tokens'  => $max,
				'temperature' => $temp,
				'stream'      => true,
				'messages'    => array_values( $turns ),
			);
			if ( '' !== trim( $system ) ) {
				$body['system'] = $system;
			}
			return array(
				'url'     => Mahan_AI::ANTHROPIC_URL,
				'headers' => array(
					'content-type: application/json',
					'x-api-key: ' . $key,
					'anthropic-version: 2023-06-01',
					'accept: text/event-stream',
				),
				'body'    => $body,
			);
		}
		if ( 'openai' === $provider ) {
			$messages = array();
			if ( '' !== trim( $system ) ) {
				$messages[] = array( 'role' => 'system', 'content' => $system );
			}
			foreach ( $turns as $t ) {
				$messages[] = array( 'role' => $t['role'], 'content' => $t['content'] );
			}
			$body = array(
				'model'       => $model,
				'messages'    => $messages,
				'max_tokens'  => $max,
				'temperature' => $temp,
				'stream'      => true,
			);
			return array(
				'url'     => Mahan_AI::OPENAI_URL,
				'headers' => array(
					'content-type: application/json',
					'authorization: Bearer ' . $key,
					'accept: text/event-stream',
				),
				'body'    => $body,
			);
		}
		if ( 'google' === $provider ) {
			$contents = array();
			foreach ( $turns as $t ) {
				$contents[] = array(
					'role'  => ( 'assistant' === $t['role'] ) ? 'model' : 'user',
					'parts' => array( array( 'text' => $t['content'] ) ),
				);
			}
			$body = array(
				'contents'         => $contents,
				'generationConfig' => array(
					'maxOutputTokens' => $max,
					'temperature'     => $temp,
				),
			);
			if ( '' !== trim( $system ) ) {
				$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
			}
			$url = Mahan_AI::GOOGLE_URL . rawurlencode( $model ) . ':streamGenerateContent?alt=sse&key=' . rawurlencode( $key );
			return array(
				'url'     => $url,
				'headers' => array(
					'content-type: application/json',
					'accept: text/event-stream',
				),
				'body'    => $body,
			);
		}
		return null;
	}

	/**
	 * Pull the incremental text delta from a single provider SSE JSON event.
	 *
	 * @param string $provider Provider id.
	 * @param array  $json     Decoded SSE data object.
	 * @return string|null
	 */
	private static function extract_delta( $provider, $json ) {
		if ( 'anthropic' === $provider ) {
			if ( isset( $json['type'] ) && 'content_block_delta' === $json['type'] && isset( $json['delta']['text'] ) ) {
				return (string) $json['delta']['text'];
			}
			return null;
		}
		if ( 'openai' === $provider ) {
			if ( isset( $json['choices'][0]['delta']['content'] ) ) {
				return (string) $json['choices'][0]['delta']['content'];
			}
			return null;
		}
		if ( 'google' === $provider ) {
			if ( isset( $json['candidates'][0]['content']['parts'] ) && is_array( $json['candidates'][0]['content']['parts'] ) ) {
				$text = '';
				foreach ( $json['candidates'][0]['content']['parts'] as $p ) {
					if ( isset( $p['text'] ) ) {
						$text .= $p['text'];
					}
				}
				return $text;
			}
			return null;
		}
		return null;
	}
}
