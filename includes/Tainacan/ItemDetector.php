<?php
/**
 * Item detection helpers.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Tainacan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrappers over Tainacan's repositories (never raw SQL against core).
 */
final class ItemDetector {

	/**
	 * Whether a post type is a Tainacan item type (tnc_col_{id}_item).
	 *
	 * @param string|null $post_type Post type.
	 * @return bool
	 */
	public static function is_item_post_type( ?string $post_type ): bool {
		return is_string( $post_type ) && 1 === preg_match( '/^tnc_col_\d+_item$/', $post_type );
	}

	/**
	 * Collection ID encoded in an item post type.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	public static function collection_id_from_post_type( string $post_type ): int {
		return preg_match( '/^tnc_col_(\d+)_item$/', $post_type, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Loads a Tainacan item entity.
	 *
	 * @param int $item_id Item (post) ID.
	 * @return \Tainacan\Entities\Item|null
	 */
	public static function get_item( int $item_id ): ?\Tainacan\Entities\Item {
		if ( $item_id <= 0 || ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			return null;
		}
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post || ! self::is_item_post_type( $post->post_type ) ) {
			return null;
		}
		try {
			$item = \Tainacan\Repositories\Items::get_instance()->fetch( $item_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $item instanceof \Tainacan\Entities\Item ? $item : null;
	}

	/**
	 * Loads a Tainacan collection entity.
	 *
	 * @param int $collection_id Collection ID.
	 * @return \Tainacan\Entities\Collection|null
	 */
	public static function get_collection( int $collection_id ): ?\Tainacan\Entities\Collection {
		if ( $collection_id <= 0 || ! class_exists( '\Tainacan\Repositories\Collections' ) ) {
			return null;
		}
		try {
			$collection = \Tainacan\Repositories\Collections::get_instance()->fetch( $collection_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $collection instanceof \Tainacan\Entities\Collection ? $collection : null;
	}

	/**
	 * Whether the item is publicly readable (published and collection readable).
	 *
	 * @param \Tainacan\Entities\Item $item Item.
	 * @return bool
	 */
	public static function item_is_public( \Tainacan\Entities\Item $item ): bool {
		return 'publish' === $item->get_status();
	}

	/**
	 * Published collections (id => name) for admin selectors.
	 *
	 * @return array<int,string>
	 */
	public static function list_collections(): array {
		if ( ! class_exists( '\Tainacan\Repositories\Collections' ) ) {
			return array();
		}
		$out = array();
		try {
			$collections = \Tainacan\Repositories\Collections::get_instance()->fetch(
				array(
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin selector listing every collection of the repository (names only); repositories rarely exceed a few dozen.
					'post_status'    => array( 'publish', 'private' ),
					'orderby'        => 'title',
					'order'          => 'ASC',
				),
				'OBJECT'
			);
			foreach ( (array) $collections as $col ) {
				if ( $col instanceof \Tainacan\Entities\Collection ) {
					$out[ (int) $col->get_id() ] = (string) $col->get_name();
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	/**
	 * Published item IDs of a collection (for batch generation).
	 *
	 * @param int $collection_id Collection ID.
	 * @param int $limit         Max IDs.
	 * @return int[]
	 */
	public static function collection_item_ids( int $collection_id, int $limit = 2000 ): array {
		$collection = self::get_collection( $collection_id );
		if ( ! $collection ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => $collection->get_db_identifier(),
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 5000, $limit ) ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Public metadata of a collection (id => name), for the admin allowlist.
	 *
	 * @param int $collection_id Collection ID.
	 * @return array<int,array{name:string,type:string,status:string}>
	 */
	public static function list_collection_metadata( int $collection_id ): array {
		$collection = self::get_collection( $collection_id );
		if ( ! $collection || ! class_exists( '\Tainacan\Repositories\Metadata' ) ) {
			return array();
		}
		$out = array();
		try {
			$metadata = \Tainacan\Repositories\Metadata::get_instance()->fetch_by_collection(
				$collection,
				array( 'post_status' => array( 'publish', 'private' ) )
			);
			foreach ( (array) $metadata as $metadatum ) {
				if ( ! $metadatum instanceof \Tainacan\Entities\Metadatum ) {
					continue;
				}
				$type                              = (string) $metadatum->get_metadata_type();
				$out[ (int) $metadatum->get_id() ] = array(
					'name'   => (string) $metadatum->get_name(),
					'type'   => substr( $type, (int) strrpos( $type, '\\' ) + 1 ),
					'status' => (string) $metadatum->get_status(),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}
}
