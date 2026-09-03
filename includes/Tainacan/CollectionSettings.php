<?php
/**
 * Per-collection configuration.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Tainacan;

use TainacanNarrativas\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option `tn_collection_config` (autoload off):
 *
 *   [ collection_id => [
 *       'enabled'              => 0|1,
 *       'autoinject'           => 0|1,
 *       'metadata'             => int[]   (allowlist; empty = all public metadata),
 *       'metadata_order'       => int[]   (explicit order; others follow),
 *       'include_description'  => 0|1,
 *       'include_document'     => 0|1,
 *       'include_attachments'  => 0|1,
 *       'max_attachments'      => int,
 *       'max_chars_item'       => int,
 *       'mode'                 => 'inherit'|mode key,
 *       'ai'                   => 'inherit'|'none'|provider id,
 *       'tts'                  => 'inherit'|provider id,
 *       'tts_voice'            => string ('' = inherit),
 *       'editorial_flow'       => 'inherit'|'auto'|'review',
 *       'allow_download'       => 'inherit'|'0'|'1',
 *       'sensitivity'          => 'auto'|'review'|'none',
 *       'external_ai'          => 0|1  (allow sending to external AI),
 *       'external_ai_attachments' => 0|1,
 *   ] ]
 *
 * A collection absent from the map is NOT enabled: narratives are opt-in per
 * collection (a fresh install never touches items).
 */
final class CollectionSettings {

	public const OPTION = 'tn_collection_config';

	/**
	 * Defaults for a collection entry.
	 *
	 * @return array<string,mixed>
	 */
	public static function entry_defaults(): array {
		return array(
			'enabled'                 => 0,
			'autoinject'              => 1,
			'metadata'                => array(),
			'metadata_order'          => array(),
			'include_description'     => 1,
			'include_document'        => 1,
			'include_attachments'     => 1,
			'max_attachments'         => 0, // 0 = inherit.
			'max_chars_item'          => 0, // 0 = inherit.
			'mode'                    => 'inherit',
			'ai'                      => 'inherit',
			'tts'                     => 'inherit',
			'tts_voice'               => '',
			'editorial_flow'          => 'inherit',
			'allow_download'          => 'inherit',
			'sensitivity'             => 'auto',
			'external_ai'             => 1,
			'external_ai_attachments' => 1,
		);
	}

	/**
	 * Whole map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Raw entry (with entry defaults) for a collection.
	 *
	 * @param int $collection_id Collection ID.
	 * @return array<string,mixed>
	 */
	public static function get( int $collection_id ): array {
		$all   = self::all();
		$entry = isset( $all[ $collection_id ] ) && is_array( $all[ $collection_id ] ) ? $all[ $collection_id ] : array();
		return wp_parse_args( $entry, self::entry_defaults() );
	}

	/**
	 * Saves an entry (already sanitized by the caller).
	 *
	 * @param int                 $collection_id Collection ID.
	 * @param array<string,mixed> $entry         Entry.
	 * @return void
	 */
	public static function save( int $collection_id, array $entry ): void {
		$all                   = self::all();
		$all[ $collection_id ] = self::sanitize_entry( $entry );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether narratives are enabled for a collection.
	 *
	 * @param int $collection_id Collection ID.
	 * @return bool
	 */
	public static function is_enabled( int $collection_id ): bool {
		return Options::is( 'enabled' ) && ! empty( self::get( $collection_id )['enabled'] );
	}

	/**
	 * IDs of enabled collections.
	 *
	 * @return int[]
	 */
	public static function enabled_ids(): array {
		$ids = array();
		foreach ( self::all() as $cid => $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['enabled'] ) ) {
				$ids[] = (int) $cid;
			}
		}
		return $ids;
	}

	/**
	 * Effective configuration for a collection: global defaults overridden by
	 * the collection entry where it does not say "inherit".
	 *
	 * @param int $collection_id Collection ID.
	 * @return array<string,mixed>
	 */
	public static function effective( int $collection_id ): array {
		$g = Options::all();
		$c = self::get( $collection_id );

		$mode = 'inherit' === $c['mode'] ? (string) $g['default_mode'] : (string) $c['mode'];
		$ai   = 'inherit' === $c['ai'] ? (string) $g['ai_provider'] : (string) $c['ai'];
		$tts  = 'inherit' === $c['tts'] ? (string) $g['tts_provider'] : (string) $c['tts'];

		$flow = 'inherit' === $c['editorial_flow'] ? (string) $g['editorial_flow'] : (string) $c['editorial_flow'];
		if ( 'review' === $c['sensitivity'] ) {
			$flow = 'review';
		}

		$download = 'inherit' === (string) $c['allow_download'] ? ! empty( $g['allow_download'] ) : '1' === (string) $c['allow_download'];

		return array(
			'collection_id'           => $collection_id,
			'enabled'                 => self::is_enabled( $collection_id ),
			'autoinject'              => ! empty( $g['autoinject'] ) && ! empty( $c['autoinject'] ),
			'metadata'                => array_map( 'intval', (array) $c['metadata'] ),
			'metadata_order'          => array_map( 'intval', (array) $c['metadata_order'] ),
			'include_description'     => ! empty( $g['include_description'] ) && ! empty( $c['include_description'] ),
			'include_document'        => ! empty( $g['include_document'] ) && ! empty( $c['include_document'] ),
			'include_attachments'     => ! empty( $g['include_attachments'] ) && ! empty( $c['include_attachments'] ),
			'max_attachments'         => (int) $c['max_attachments'] > 0 ? (int) $c['max_attachments'] : (int) $g['max_attachments'],
			'max_chars_item'          => (int) $c['max_chars_item'] > 0 ? (int) $c['max_chars_item'] : (int) $g['max_chars_item'],
			'max_chars_file'          => (int) $g['max_chars_file'],
			'chunk_size'              => (int) $g['chunk_size'],
			'mode'                    => $mode,
			'ai'                      => $ai,
			'tts'                     => $tts,
			'tts_voice'               => '' !== (string) $c['tts_voice'] ? (string) $c['tts_voice'] : (string) $g['tts_voice'],
			'editorial_flow'          => $flow,
			'allow_download'          => $download,
			'sensitivity'             => (string) $c['sensitivity'],
			'external_ai'             => ! empty( $c['external_ai'] ),
			'external_ai_attachments' => ! empty( $g['external_ai_attachments'] ) && ! empty( $c['external_ai_attachments'] ),
			'language'                => (string) $g['default_language'],
		);
	}

	/**
	 * Sanitizes an entry coming from a form / REST.
	 *
	 * @param array<string,mixed> $entry Raw entry.
	 * @return array<string,mixed>
	 */
	public static function sanitize_entry( array $entry ): array {
		$d   = self::entry_defaults();
		$out = array();

		// HTML forms send `__form` and omit unchecked boxes (⇒ 0). Programmatic
		// partial saves (wizard, CLI, filters) keep the defaults for absent keys.
		$is_form = ! empty( $entry['__form'] );
		foreach ( array( 'enabled', 'autoinject', 'include_description', 'include_document', 'include_attachments', 'external_ai', 'external_ai_attachments' ) as $bool ) {
			if ( array_key_exists( $bool, $entry ) || $is_form ) {
				$out[ $bool ] = empty( $entry[ $bool ] ) ? 0 : 1;
			}
		}
		foreach ( array( 'metadata', 'metadata_order' ) as $list ) {
			$vals         = isset( $entry[ $list ] ) && is_array( $entry[ $list ] ) ? $entry[ $list ] : array();
			$out[ $list ] = array_values( array_unique( array_filter( array_map( 'absint', $vals ) ) ) );
			$out[ $list ] = array_slice( $out[ $list ], 0, 500 );
		}
		$out['max_attachments'] = isset( $entry['max_attachments'] ) ? min( 50, absint( $entry['max_attachments'] ) ) : 0;
		$out['max_chars_item']  = isset( $entry['max_chars_item'] ) ? min( 500000, absint( $entry['max_chars_item'] ) ) : 0;

		$out['mode'] = isset( $entry['mode'] ) ? sanitize_key( (string) $entry['mode'] ) : 'inherit';
		if ( '' === $out['mode'] ) {
			$out['mode'] = 'inherit';
		}
		$out['ai']  = isset( $entry['ai'] ) ? sanitize_key( (string) $entry['ai'] ) : 'inherit';
		$out['tts'] = isset( $entry['tts'] ) ? sanitize_key( (string) $entry['tts'] ) : 'inherit';
		if ( '' === $out['ai'] ) {
			$out['ai'] = 'inherit';
		}
		if ( '' === $out['tts'] ) {
			$out['tts'] = 'inherit';
		}
		$out['tts_voice'] = isset( $entry['tts_voice'] ) ? sanitize_text_field( (string) $entry['tts_voice'] ) : '';

		$flow                  = isset( $entry['editorial_flow'] ) ? sanitize_key( (string) $entry['editorial_flow'] ) : 'inherit';
		$out['editorial_flow'] = in_array( $flow, array( 'inherit', 'auto', 'review' ), true ) ? $flow : 'inherit';

		$dl                    = isset( $entry['allow_download'] ) ? (string) $entry['allow_download'] : 'inherit';
		$out['allow_download'] = in_array( $dl, array( 'inherit', '0', '1' ), true ) ? $dl : 'inherit';

		$sens               = isset( $entry['sensitivity'] ) ? sanitize_key( (string) $entry['sensitivity'] ) : 'auto';
		$out['sensitivity'] = in_array( $sens, array( 'auto', 'review', 'none' ), true ) ? $sens : 'auto';

		return wp_parse_args( $out, $d );
	}
}
