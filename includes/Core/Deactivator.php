<?php
/**
 * Deactivation routine.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clears scheduled events. Data and capabilities are kept (see uninstall.php).
 */
final class Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'tn_process_queue' );
		wp_clear_scheduled_hook( 'tn_stale_sweep' );
		wp_unschedule_hook( 'tn_check_item' );
		delete_option( 'tn_queue_lock' );
	}
}
