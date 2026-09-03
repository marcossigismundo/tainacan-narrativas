<?php
/**
 * Plugin capabilities.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own capabilities (never `manage_options` for everything).
 *
 * - manage:   settings, providers, API keys, deletion.
 * - generate: trigger generation / regeneration / batch.
 * - review:   read the admin screen, edit and approve scripts.
 */
final class Capabilities {

	public const MANAGE   = 'manage_tainacan_narratives';
	public const GENERATE = 'generate_tainacan_narratives';
	public const REVIEW   = 'review_tainacan_narratives';

	/**
	 * All plugin capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::MANAGE, self::GENERATE, self::REVIEW );
	}

	/**
	 * Grants capabilities on activation.
	 *
	 * Administrators receive everything. Roles that already hold Tainacan's
	 * repository-wide `manage_tainacan` capability receive generate + review, so
	 * a Tainacan manager can operate narratives without touching API keys.
	 *
	 * @return void
	 */
	public static function grant(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			if ( 'administrator' === $role_name ) {
				continue;
			}
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( 'manage_tainacan' ) ) {
				$role->add_cap( self::GENERATE );
				$role->add_cap( self::REVIEW );
			}
		}
	}

	/**
	 * Removes all plugin capabilities from every role.
	 *
	 * @return void
	 */
	public static function revoke(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
