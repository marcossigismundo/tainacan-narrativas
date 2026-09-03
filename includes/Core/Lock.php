<?php
/**
 * Named locks with timeout.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same technique WordPress core uses for upgrades (WP_Upgrader::create_lock):
 * add_option() is atomic on the options table's unique key, so only one
 * process can own a lock name; an expired lock is reclaimed automatically.
 */
final class Lock {

	/**
	 * Tries to acquire a lock.
	 *
	 * @param string $name Lock name (letters, digits, underscore).
	 * @param int    $ttl  Seconds after which a stale lock may be stolen.
	 * @return bool
	 */
	public static function acquire( string $name, int $ttl = 600 ): bool {
		$option = self::option( $name );
		if ( add_option( $option, (string) time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $option, 0 );
		if ( $since > 0 && ( time() - $since ) > $ttl ) {
			delete_option( $option );
			return add_option( $option, (string) time(), '', false );
		}
		return false;
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( string $name ): void {
		delete_option( self::option( $name ) );
	}

	/**
	 * Whether the lock is currently held (and not expired).
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  TTL used on acquire.
	 * @return bool
	 */
	public static function is_locked( string $name, int $ttl = 600 ): bool {
		$since = (int) get_option( self::option( $name ), 0 );
		return $since > 0 && ( time() - $since ) <= $ttl;
	}

	/**
	 * Option name for a lock.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option( string $name ): string {
		return 'tn_lock_' . preg_replace( '/[^a-z0-9_]/i', '_', $name );
	}
}
