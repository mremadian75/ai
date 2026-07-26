<?php
/**
 * Tiny logger that writes to the PHP error log when debug is enabled.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Logger {

	const PREFIX = '[mahan] ';

	private static function enabled() {
		return (bool) get_option( 'mahan_debug', 0 );
	}

	public static function info( $msg, $context = array() ) {
		self::write( 'INFO', $msg, $context );
	}

	public static function warn( $msg, $context = array() ) {
		self::write( 'WARN', $msg, $context );
	}

	public static function error( $msg, $context = array() ) {
		self::write( 'ERROR', $msg, $context );
	}

	private static function write( $level, $msg, $context ) {
		if ( ! self::enabled() && 'ERROR' !== $level ) {
			return;
		}
		$line = self::PREFIX . $level . ' ' . (string) $msg;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		error_log( $line );
	}
}
