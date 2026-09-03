<?php
/**
 * Uninstall routine.
 *
 * By default, scripts, audio files and settings are PRESERVED: an accidental
 * uninstall must never destroy editorial work. Data is removed only when the
 * administrator explicitly enabled "Delete all data on uninstall".
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$tn_settings = get_option( 'tn_settings', array() );
if ( ! is_array( $tn_settings ) || empty( $tn_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Remove generated audio attachments (regular Media Library items tagged by post meta).
$tn_audio_ids = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_tn_generated_audio', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off uninstall cleanup.
		'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-off uninstall cleanup.
	)
);
foreach ( $tn_audio_ids as $tn_audio_id ) {
	wp_delete_attachment( (int) $tn_audio_id, true );
}

$tn_tables = array( $wpdb->prefix . 'tn_narratives', $wpdb->prefix . 'tn_jobs' );
foreach ( $tn_tables as $tn_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup of the plugin's own tables; table name is $wpdb->prefix (trusted); DROP cannot use prepared placeholders.
	$wpdb->query( "DROP TABLE IF EXISTS {$tn_table}" );
}

$tn_options = array(
	'tn_settings',
	'tn_collection_config',
	'tn_db_version',
	'tn_log_recent',
	'tn_queue_lock',
);
foreach ( $tn_options as $tn_option ) {
	delete_option( $tn_option );
}

// Extraction caches stored on attachments and check markers on items.
delete_metadata( 'post', 0, '_tn_text_cache', '', true );
delete_metadata( 'post', 0, '_tn_text', '', true );
delete_metadata( 'post', 0, '_tn_generated_audio', '', true );
delete_metadata( 'post', 0, '_tn_narrative_check', '', true );

wp_clear_scheduled_hook( 'tn_process_queue' );
wp_clear_scheduled_hook( 'tn_stale_sweep' );
wp_unschedule_hook( 'tn_check_item' );

$tn_roles = wp_roles();
foreach ( array_keys( $tn_roles->roles ) as $tn_role_name ) {
	$tn_role = get_role( $tn_role_name );
	if ( ! $tn_role ) {
		continue;
	}
	foreach ( array( 'manage_tainacan_narratives', 'generate_tainacan_narratives', 'review_tainacan_narratives' ) as $tn_cap ) {
		$tn_role->remove_cap( $tn_cap );
	}
}
