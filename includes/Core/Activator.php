<?php
/**
 * Activation routine.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Core;

use TainacanNarrativas\Database\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates tables, seeds defaults and grants capabilities.
 */
final class Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( TN_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Tainacan Narrativas requer PHP 8.0 ou superior.', 'tainacan-narrativas' ),
				esc_html__( 'Erro de ativação', 'tainacan-narrativas' ),
				array( 'back_link' => true )
			);
		}

		Tables::create();

		$existing = get_option( Options::OPTION, array() );
		add_option( Options::OPTION, wp_parse_args( is_array( $existing ) ? $existing : array(), Options::defaults() ), '', true );
		add_option( 'tn_collection_config', array(), '', false );
		add_option( 'tn_log_recent', array(), '', false );

		Capabilities::grant();

		// The plugin is not booted yet during activation: register the custom interval here.
		add_filter( 'cron_schedules', array( Plugin::class, 'register_cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval,WordPress.WP.CronInterval.ChangeDetected -- 60 s interval is required for a responsive job queue; each run is bounded by a time budget, a lock and a small batch, and it can be disabled in the settings.
		if ( ! wp_next_scheduled( 'tn_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'tn_every_minute', 'tn_process_queue' );
		}
		if ( ! wp_next_scheduled( 'tn_stale_sweep' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tn_stale_sweep' );
		}

		set_transient( 'tn_activation_redirect', 1, 60 );
	}
}
