<?php
/**
 * Detects item/document changes and schedules staleness checks.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Tainacan;

use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Documents\ExtractorManager;
use TainacanNarrativas\Narrative\NarrativeManager;
use TainacanNarrativas\Queue\QueueManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing heavy runs inside these hooks: they only clear extraction caches and
 * schedule a debounced `tn_check_item` cron event (90 s), which re-hashes the
 * corpus and flips the narrative to "stale" (or queues regeneration when the
 * trigger setting says so). Metadata edited through Tainacan Colab or the DIP
 * importer flows through the same core hooks, so no coupling is needed.
 */
final class ChangeListener {

	/**
	 * Queue.
	 *
	 * @var QueueManager
	 */
	private QueueManager $queue;

	/**
	 * Manager.
	 *
	 * @var NarrativeManager
	 */
	private NarrativeManager $manager;

	/**
	 * Constructor.
	 *
	 * @param QueueManager     $queue   Queue.
	 * @param NarrativeManager $manager Manager.
	 */
	public function __construct( QueueManager $queue, NarrativeManager $manager ) {
		$this->queue   = $queue;
		$this->manager = $manager;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Tainacan repositories (items, item metadata, documents set via API).
		add_action( 'tainacan-insert', array( $this, 'on_tainacan_insert' ), 10, 1 );
		add_action( 'tainacan-api-item-updated', array( $this, 'on_api_item_updated' ), 10, 1 );
		// Generic WordPress paths (bulk edits, importers).
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
		// Attachments.
		add_action( 'add_attachment', array( $this, 'on_attachment_change' ) );
		add_action( 'edit_attachment', array( $this, 'on_attachment_change' ) );
		add_action( 'attachment_updated', array( $this, 'on_attachment_change' ) );
		add_action( 'delete_attachment', array( $this, 'on_attachment_change' ) );
	}

	/**
	 * Tainacan entity inserted/updated.
	 *
	 * @param mixed $entity Entity.
	 * @return void
	 */
	public function on_tainacan_insert( $entity ): void {
		if ( $entity instanceof \Tainacan\Entities\Item ) {
			$this->touch( (int) $entity->get_id() );
		} elseif ( $entity instanceof \Tainacan\Entities\Item_Metadata_Entity ) {
			$item = $entity->get_item();
			if ( $item instanceof \Tainacan\Entities\Item ) {
				$this->touch( (int) $item->get_id() );
			}
		}
	}

	/**
	 * Item updated through the REST API (document/thumbnail changes).
	 *
	 * @param mixed $item Item entity.
	 * @return void
	 */
	public function on_api_item_updated( $item ): void {
		if ( $item instanceof \Tainacan\Entities\Item ) {
			$this->touch( (int) $item->get_id() );
		}
	}

	/**
	 * Handles save_post for Tainacan item post types.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post instanceof \WP_Post && ItemDetector::is_item_post_type( $post->post_type ) ) {
			$this->touch( (int) $post_id );
		}
	}

	/**
	 * Item permanently deleted: remove its narratives and audio.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function on_deleted_post( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && ItemDetector::is_item_post_type( $post->post_type ) ) {
			$this->manager->delete_all( (int) $post_id );
		}
	}

	/**
	 * Attachment added/edited/deleted under an item.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_attachment_change( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$post          = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}
		// Ignore our own generated audio (parent 0 + flag).
		if ( '1' === (string) get_post_meta( $attachment_id, '_tn_generated_audio', true ) ) {
			return;
		}
		ExtractorManager::forget( $attachment_id );
		$parent = (int) $post->post_parent;
		if ( $parent > 0 ) {
			$parent_post = get_post( $parent );
			if ( $parent_post instanceof \WP_Post && ItemDetector::is_item_post_type( $parent_post->post_type ) ) {
				$this->touch( $parent );
			}
		}
	}

	/**
	 * Schedules the debounced check according to the trigger setting.
	 *
	 * @param int $item_id Item ID.
	 * @return void
	 */
	private function touch( int $item_id ): void {
		if ( $item_id <= 0 || 'none' === (string) Options::get( 'trigger_on_save', 'mark_stale' ) ) {
			return;
		}
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$collection_id = ItemDetector::collection_id_from_post_type( $post->post_type );
		if ( ! CollectionSettings::is_enabled( $collection_id ) ) {
			return;
		}
		$this->queue->schedule_check( $item_id );
	}
}
