<?php
/**
 * Minimal logger.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Logging;

use TainacanNarrativas\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Levels: error, warning, info, debug.
 *
 * - error/warning/info are kept in a small ring buffer (option `tn_log_recent`,
 *   last 200 entries) shown on the Diagnostics tab.
 * - debug entries are only kept when the "debug" setting is on.
 * - Every message and context goes through redact() so API keys, bearer
 *   tokens and Authorization headers can never reach storage or PHP logs.
 */
final class Logger {

	public const OPTION = 'tn_log_recent';
	public const MAX    = 200;

	/**
	 * Log an error.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a debug message (kept only when debug mode is enabled).
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! Options::is( 'debug' ) ) {
			return;
		}
		self::log( 'debug', $message, $context );
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @param int $limit Max entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_slice( array_reverse( $entries ), 0, max( 1, $limit ) );
	}

	/**
	 * Empties the ring buffer.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Writes an entry.
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private static function log( string $level, string $message, array $context ): void {
		$message = self::redact( $message );
		$context = self::redact_array( $context );

		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => mb_substr( $message, 0, 1000 ),
			'context' => $context,
		);
		if ( count( $entries ) > self::MAX ) {
			$entries = array_slice( $entries, -self::MAX );
		}
		update_option( self::OPTION, $entries, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $level, array( 'error', 'warning' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; surfaces only in dev.
			error_log( '[tainacan-narrativas][' . $level . '] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
		}
	}

	/**
	 * Redacts secrets from a string.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function redact( string $text ): string {
		$patterns = array(
			'/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i'   => '$1[REDACTED]',
			'/(sk-[A-Za-z0-9\-_]{6})[A-Za-z0-9\-_]+/'  => '$1[REDACTED]',
			'/(AIza[0-9A-Za-z\-_]{4})[0-9A-Za-z\-_]+/' => '$1[REDACTED]',
			'/([?&](?:key|api_key|token)=)[^&\s]+/i'   => '$1[REDACTED]',
			'/("?(?:api_key|apikey|authorization|x-api-key|token)"?\s*[:=]\s*"?)[^",\s]+/i' => '$1[REDACTED]',
		);
		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
	}

	/**
	 * Redacts secrets inside an array (keys and values), recursively.
	 *
	 * @param array<mixed> $data Input.
	 * @return array<mixed>
	 */
	public static function redact_array( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			$lkey = is_string( $key ) ? strtolower( $key ) : '';
			if ( is_string( $key ) && preg_match( '/(api_?key|token|secret|authorization|password)/', $lkey ) ) {
				$out[ $key ] = '[REDACTED]';
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact_array( $value );
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = self::redact( mb_substr( $value, 0, 500 ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = '[object]';
			}
		}
		return $out;
	}
}
