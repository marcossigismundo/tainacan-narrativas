<?php
/**
 * Plugin schema.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two tables. Narratives are not post-centric (one item has N versions, a
 * status machine, provenance and editorial fields) and jobs need atomic
 * claiming — neither fits post meta or a Tainacan repository, so this is the
 * legitimate `$wpdb` exception.
 *
 *  - wp_tn_narratives: one row per (item, version); `is_current` marks the
 *    version served on the public page.
 *  - wp_tn_jobs: the WP-Cron backed queue.
 */
final class Tables {

	public const DB_VERSION_OPTION = 'tn_db_version';

	/**
	 * Narratives table name.
	 *
	 * @return string
	 */
	public static function narratives(): string {
		global $wpdb;
		return $wpdb->prefix . 'tn_narratives';
	}

	/**
	 * Jobs table name.
	 *
	 * @return string
	 */
	public static function jobs(): string {
		global $wpdb;
		return $wpdb->prefix . 'tn_jobs';
	}

	/**
	 * Creates or upgrades both tables via dbDelta.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$narratives      = self::narratives();
		$jobs            = self::jobs();

		$sql_narratives = "CREATE TABLE $narratives (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			item_id bigint(20) UNSIGNED NOT NULL,
			collection_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			version int(10) UNSIGNED NOT NULL DEFAULT 1,
			is_current tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'queued',
			mode varchar(32) NOT NULL DEFAULT 'documentary',
			language varchar(10) NOT NULL DEFAULT 'pt-BR',
			source_hash char(64) DEFAULT NULL,
			script_hash char(64) DEFAULT NULL,
			audio_hash char(64) DEFAULT NULL,
			generated_script longtext DEFAULT NULL,
			edited_script longtext DEFAULT NULL,
			script_edited_by bigint(20) UNSIGNED DEFAULT NULL,
			script_edited_at datetime DEFAULT NULL,
			ai_provider varchar(40) DEFAULT NULL,
			ai_model varchar(120) DEFAULT NULL,
			tts_provider varchar(40) DEFAULT NULL,
			tts_voice varchar(120) DEFAULT NULL,
			audio_attachment_id bigint(20) UNSIGNED DEFAULT NULL,
			duration decimal(10,2) DEFAULT NULL,
			sources longtext DEFAULT NULL,
			stats longtext DEFAULT NULL,
			last_error text DEFAULT NULL,
			approved_by bigint(20) UNSIGNED DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			generated_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item_id (item_id),
			KEY item_current (item_id,is_current),
			KEY collection_id (collection_id),
			KEY status (status),
			KEY source_hash (source_hash)
		) $charset_collate;";

		$sql_jobs = "CREATE TABLE $jobs (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			item_id bigint(20) UNSIGNED NOT NULL,
			narrative_id bigint(20) UNSIGNED DEFAULT NULL,
			stage varchar(32) NOT NULL DEFAULT 'extract',
			status varchar(20) NOT NULL DEFAULT 'queued',
			priority tinyint(3) UNSIGNED NOT NULL DEFAULT 10,
			attempts tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
			payload longtext DEFAULT NULL,
			lock_token varchar(40) DEFAULT NULL,
			locked_at datetime DEFAULT NULL,
			run_after datetime NOT NULL,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_run_after (status,run_after),
			KEY item_id (item_id),
			KEY lock_token (lock_token)
		) $charset_collate;";

		dbDelta( $sql_narratives );
		dbDelta( $sql_jobs );

		update_option( self::DB_VERSION_OPTION, TN_VERSION );
	}

	/**
	 * Upgrades the schema when the plugin version changed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== TN_VERSION ) {
			self::create();
		}
	}

	/**
	 * Whether both tables exist (Diagnostics).
	 *
	 * @return bool
	 */
	public static function exist(): bool {
		global $wpdb;
		foreach ( array( self::narratives(), self::jobs() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence check for the plugin's own tables; not expressible via WP_Query; runs only on the diagnostics screen.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}
}
