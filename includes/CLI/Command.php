<?php
/**
 * WP-CLI commands.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\CLI;

use TainacanNarrativas\Narrative\NarrativeManager;
use TainacanNarrativas\Queue\QueueManager;
use TainacanNarrativas\Tainacan\ItemDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: `wp tainacan-narrativas <generate|queue|status|check>`.
 */
final class Command {

	/**
	 * Manager.
	 *
	 * @var NarrativeManager
	 */
	private NarrativeManager $manager;

	/**
	 * Queue.
	 *
	 * @var QueueManager
	 */
	private QueueManager $queue;

	/**
	 * Constructor.
	 *
	 * @param NarrativeManager $manager Manager.
	 * @param QueueManager     $queue   Queue.
	 */
	public function __construct( NarrativeManager $manager, QueueManager $queue ) {
		$this->manager = $manager;
		$this->queue   = $queue;
	}

	/**
	 * Generates the narrative of one item or of a whole collection.
	 *
	 * ## OPTIONS
	 *
	 * [<item_id>]
	 * : Tainacan item ID.
	 *
	 * [--collection=<id>]
	 * : Queue every published item of the collection.
	 *
	 * [--sync]
	 * : Run now instead of queueing (item) / drain the queue after queueing (collection).
	 *
	 * [--force]
	 * : Regenerate even when the source hash did not change.
	 *
	 * [--mode=<mode>]
	 * : Narrative mode override.
	 *
	 * [--skip-review]
	 * : Skip the editorial gate and synthesize immediately.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tainacan-narrativas generate 123 --sync
	 *     wp tainacan-narrativas generate --collection=45
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function generate( array $args, array $assoc_args ): void {
		$collection = isset( $assoc_args['collection'] ) ? (int) $assoc_args['collection'] : 0;
		$sync       = isset( $assoc_args['sync'] );
		$force      = isset( $assoc_args['force'] );

		if ( $collection > 0 ) {
			$n = $this->manager->enqueue_collection( $collection, ! $force, $force );
			\WP_CLI::log( sprintf( '%d item(s) enfileirado(s).', $n ) );
			if ( $sync ) {
				$this->queue( array(), array( 'limit' => (string) max( 1, $n ) ) );
			}
			return;
		}

		$item_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $item_id <= 0 ) {
			\WP_CLI::error( 'Informe o ID do item ou --collection=<id>.' );
		}
		$opts = array(
			'force'             => $force,
			'mode'              => (string) ( $assoc_args['mode'] ?? '' ),
			'skip_review'       => isset( $assoc_args['skip-review'] ),
			'allow_ai_fallback' => true,
		);
		if ( ! $sync ) {
			$job = $this->manager->enqueue( $item_id, array_merge( $opts, array( 'action' => 'generate' ) ), 5 );
			if ( is_wp_error( $job ) ) {
				\WP_CLI::error( $job->get_error_message() );
			}
			\WP_CLI::success( sprintf( 'Job #%d enfileirado para o item %d.', (int) $job, $item_id ) );
			return;
		}
		$result = $this->manager->run( $item_id, $opts );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Item %d: status=%s versão=%d duração=%ss', $item_id, $result['status'], (int) $result['version'], (string) $result['duration'] ) );
	}

	/**
	 * Processes queued jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max jobs to run (default 10).
	 *
	 * [--budget=<seconds>]
	 * : Time budget in seconds (default 300).
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function queue( array $args, array $assoc_args ): void {
		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 10;
		$budget = isset( $assoc_args['budget'] ) ? max( 10, (int) $assoc_args['budget'] ) : 300;
		$total  = array(
			'processed' => 0,
			'done'      => 0,
			'retry'     => 0,
			'failed'    => 0,
		);
		$start  = time();
		while ( $total['processed'] < $limit && ( time() - $start ) < $budget ) {
			$summary = $this->queue->process( min( 3, $limit - $total['processed'] ), $budget );
			if ( $summary['skipped'] ) {
				\WP_CLI::warning( 'Fila travada por outro processo; tente novamente.' );
				break;
			}
			if ( 0 === $summary['processed'] ) {
				break;
			}
			foreach ( array( 'processed', 'done', 'retry', 'failed' ) as $k ) {
				$total[ $k ] += $summary[ $k ];
			}
			\WP_CLI::log( sprintf( 'processados=%d ok=%d retry=%d falhas=%d', $total['processed'], $total['done'], $total['retry'], $total['failed'] ) );
		}
		$counts = $this->manager->jobs()->counts();
		\WP_CLI::success( sprintf( 'Fila: %d na fila, %d em execução, %d concluídos, %d com falha.', $counts['queued'], $counts['running'], $counts['done'], $counts['failed'] ) );
	}

	/**
	 * Shows the narrative status of an item.
	 *
	 * ## OPTIONS
	 *
	 * <item_id>
	 * : Tainacan item ID.
	 *
	 * @param string[] $args Positional args.
	 * @return void
	 */
	public function status( array $args ): void {
		$item_id = (int) ( $args[0] ?? 0 );
		$status  = $this->manager->status( $item_id );
		if ( is_wp_error( $status ) ) {
			\WP_CLI::error( $status->get_error_message() );
		}
		$row = $status['narrative'];
		if ( ! $row ) {
			\WP_CLI::log( 'Sem narrativa.' );
			return;
		}
		\WP_CLI::log( sprintf( 'status=%s versão=%d modo=%s ia=%s tts=%s duração=%ss áudio=%s', $row['status'], (int) $row['version'], $row['mode'], (string) $row['ai_provider'], (string) $row['tts_provider'], (string) $row['duration'], $row['audio_url'] ? $row['audio_url'] : '-' ) );
		if ( ! empty( $row['last_error'] ) ) {
			\WP_CLI::warning( $row['last_error'] );
		}
	}

	/**
	 * Re-hashes an item and marks its narrative stale when the content changed.
	 *
	 * ## OPTIONS
	 *
	 * <item_id>
	 * : Tainacan item ID.
	 *
	 * @param string[] $args Positional args.
	 * @return void
	 */
	public function check( array $args ): void {
		$item_id = (int) ( $args[0] ?? 0 );
		if ( ! ItemDetector::get_item( $item_id ) ) {
			\WP_CLI::error( 'Item não encontrado.' );
		}
		$status = $this->manager->check_stale( $item_id );
		if ( is_wp_error( $status ) ) {
			\WP_CLI::error( $status->get_error_message() );
		}
		\WP_CLI::success( 'status=' . $status );
	}
}
