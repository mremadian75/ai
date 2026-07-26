<?php
/**
 * Provider-agnostic AI client for non-streaming completions.
 *
 * Supports Anthropic (Claude), OpenAI (GPT), and Google (Gemini). The active
 * provider/model/key come from settings, but any call may override them.
 *
 * Messages use a normalized shape:
 *   [ [ 'role' => 'system'|'user'|'assistant', 'content' => '...' ], ... ]
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_AI {

	const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
	const OPENAI_URL    = 'https://api.openai.com/v1/chat/completions';
	const GOOGLE_URL    = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Run a completion.
	 *
	 * @param array $messages Normalized messages.
	 * @param array $opts     provider, model, key, max_tokens, temperature, json, timeout.
	 * @return array { ok, text, error }
	 */
	public static function complete( array $messages, $opts = array() ) {
		$provider = isset( $opts['provider'] ) ? $opts['provider'] : Mahan_Settings::get( 'ai_provider', 'anthropic' );
		$key      = isset( $opts['key'] ) ? $opts['key'] : self::key_for( $provider );
		$model    = isset( $opts['model'] ) ? $opts['model'] : self::model_for( $provider );

		if ( '' === trim( (string) $key ) ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'error' => sprintf( 'No API key configured for provider "%s".', $provider ),
			);
		}

		$max_tokens  = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : (int) Mahan_Settings::get( 'max_tokens', 1024 );
		$temperature = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : (float) Mahan_Settings::get( 'temperature', 0.7 );
		$json        = ! empty( $opts['json'] );
		$timeout     = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 45;

		switch ( $provider ) {
			case 'openai':
				return self::call_openai( $messages, $model, $key, $max_tokens, $temperature, $json, $timeout );
			case 'google':
				return self::call_google( $messages, $model, $key, $max_tokens, $temperature, $json, $timeout );
			case 'anthropic':
			default:
				return self::call_anthropic( $messages, $model, $key, $max_tokens, $temperature, $timeout );
		}
	}

	public static function key_for( $provider ) {
		switch ( $provider ) {
			case 'openai':
				return (string) Mahan_Settings::get( 'openai_key' );
			case 'google':
				return (string) Mahan_Settings::get( 'google_key' );
			case 'anthropic':
			default:
				return (string) Mahan_Settings::get( 'anthropic_key' );
		}
	}

	public static function model_for( $provider ) {
		switch ( $provider ) {
			case 'openai':
				return (string) Mahan_Settings::get( 'openai_model' );
			case 'google':
				return (string) Mahan_Settings::get( 'google_model' );
			case 'anthropic':
			default:
				return (string) Mahan_Settings::get( 'anthropic_model' );
		}
	}

	/**
	 * Split normalized messages into a system prompt + conversation turns.
	 *
	 * @param array $messages Normalized messages.
	 * @return array [ string $system, array $turns ]
	 */
	public static function split_system( array $messages ) {
		$system = array();
		$turns  = array();
		foreach ( $messages as $m ) {
			$role    = isset( $m['role'] ) ? $m['role'] : 'user';
			$content = isset( $m['content'] ) ? (string) $m['content'] : '';
			if ( 'system' === $role ) {
				$system[] = $content;
			} else {
				$turns[] = array(
					'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
					'content' => $content,
				);
			}
		}
		return array( implode( "\n\n", $system ), $turns );
	}

	/* ------------------------------------------------------------------ */
	/* Anthropic                                                           */
	/* ------------------------------------------------------------------ */

	private static function call_anthropic( $messages, $model, $key, $max_tokens, $temperature, $timeout ) {
		list( $system, $turns ) = self::split_system( $messages );

		$body = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => array_map(
				function ( $t ) {
					return array(
						'role'    => $t['role'],
						'content' => $t['content'],
					);
				},
				$turns
			),
		);
		if ( '' !== trim( $system ) ) {
			$body['system'] = $system;
		}

		$res = wp_remote_post(
			self::ANTHROPIC_URL,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$parsed = self::handle_response( $res );
		if ( ! $parsed['ok'] ) {
			return $parsed;
		}
		$data = $parsed['json'];
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
		}
		return array(
			'ok'    => true,
			'text'  => $text,
			'error' => '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* OpenAI                                                              */
	/* ------------------------------------------------------------------ */

	private static function call_openai( $messages, $model, $key, $max_tokens, $temperature, $json, $timeout ) {
		$payload_messages = array_map(
			function ( $m ) {
				$role = isset( $m['role'] ) ? $m['role'] : 'user';
				if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) ) {
					$role = 'user';
				}
				return array(
					'role'    => $role,
					'content' => isset( $m['content'] ) ? (string) $m['content'] : '',
				);
			},
			$messages
		);

		$body = array(
			'model'       => $model,
			'messages'    => $payload_messages,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);
		if ( $json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$res = wp_remote_post(
			self::OPENAI_URL,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$parsed = self::handle_response( $res );
		if ( ! $parsed['ok'] ) {
			return $parsed;
		}
		$data = $parsed['json'];
		$text = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		return array(
			'ok'    => true,
			'text'  => $text,
			'error' => '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Google Gemini                                                       */
	/* ------------------------------------------------------------------ */

	private static function call_google( $messages, $model, $key, $max_tokens, $temperature, $json, $timeout ) {
		list( $system, $turns ) = self::split_system( $messages );

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
				'maxOutputTokens' => $max_tokens,
				'temperature'     => $temperature,
			),
		);
		if ( '' !== trim( $system ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}
		if ( $json ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}

		$url = self::GOOGLE_URL . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'content-type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		$parsed = self::handle_response( $res );
		if ( ! $parsed['ok'] ) {
			return $parsed;
		}
		$data = $parsed['json'];
		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		return array(
			'ok'    => true,
			'text'  => $text,
			'error' => '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Shared response handling                                            */
	/* ------------------------------------------------------------------ */

	private static function handle_response( $res ) {
		if ( is_wp_error( $res ) ) {
			Mahan_Logger::error( 'AI request error', array( 'msg' => $res->get_error_message() ) );
			return array(
				'ok'    => false,
				'text'  => '',
				'error' => $res->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$raw    = (string) wp_remote_retrieve_body( $res );
		$json   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$msg = 'HTTP ' . $status;
			if ( is_array( $json ) && isset( $json['error'] ) ) {
				if ( is_array( $json['error'] ) && isset( $json['error']['message'] ) ) {
					$msg = (string) $json['error']['message'];
				} elseif ( is_string( $json['error'] ) ) {
					$msg = $json['error'];
				}
			}
			Mahan_Logger::error( 'AI provider error', array( 'status' => $status, 'msg' => $msg ) );
			return array(
				'ok'    => false,
				'text'  => '',
				'error' => $msg,
			);
		}
		if ( ! is_array( $json ) ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'error' => 'Invalid JSON from provider.',
			);
		}
		return array(
			'ok'   => true,
			'json' => $json,
		);
	}

	/**
	 * Quick connectivity test for the admin "Test connection" button.
	 *
	 * @param string $provider Provider id.
	 * @return array { ok, text|error }
	 */
	public static function test_connection( $provider ) {
		return self::complete(
			array(
				array( 'role' => 'user', 'content' => 'Reply with exactly the word: OK' ),
			),
			array(
				'provider'   => $provider,
				'max_tokens' => 16,
				'timeout'    => 20,
			)
		);
	}
}
