<?php
/**
 * WP-Cron backed queue.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Queue;

use TainacanNarrativas\Core\Lock;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Database\JobRepository;
use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Narrative\NarrativeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events:
 *  - tn_process_queue (every minute): claims a small batch within a time budget.
 *  - tn_check_item (single, debounced): re-hashes one item after a change.
 *  - tn_stale_sweep (daily): walks ready narratives in pages to catch changes
 *    that bypassed the hooks (direct DB edits, file replacements…).
 *
 * "Executar fila" in the admin and `wp tainacan-narrativas queue` call process()
 * directly, which is how sites without a reliable WP-Cron drain the queue.
 */
final class QueueManager {

	public const HOOK_PROCESS = 'tn_process_queue';
	public const HOOK_CHECK   = 'tn_check_item';
	public const HOOK_SWEEP   = 'tn_stale_sweep';

	/**
	 * Manager.
	 *
	 * @var NarrativeManager
	 */
	private NarrativeManager $manager;

	/**
	 * Constructor.
	 *
	 * @param NarrativeManager $manager Manager.
	 */
	public function __construct( NarrativeManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Registers cron hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK_PROCESS, array( $this, 'cron_process' ) );
		add_action( self::HOOK_CHECK, array( $this, 'cron_check_item' ) );
		add_action( self::HOOK_SWEEP, array( $this, 'cron_sweep' ) );

		if ( Options::is( 'cron_enabled' ) && ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_event( time() + 60, 'tn_every_minute', self::HOOK_PROCESS );
		}
	}

	/**
	 * Cron entry point.
	 *
	 * @return void
	 */
	public function cron_process(): void {
		if ( ! Options::is( 'cron_enabled' ) ) {
			return;
		}
		$this->process( (int) Options::get( 'cron_batch', 3 ), (int) Options::get( 'cron_time_budget', 20 ) );
	}

	/**
	 * Processes up to $limit jobs within $budget seconds.
	 *
	 * @param int $limit  Max jobs.
	 * @param int $budget Seconds.
	 * @return array{processed:int,done:int,retry:int,failed:int,skipped:bool}
	 */
	public function process( int $limit = 3, int $budget = 20 ): array {
		$summary = array(
			'processed' => 0,
			'done'      => 0,
			'retry'     => 0,
			'failed'    => 0,
			'skipped'   => false,
		);
		if ( ! Lock::acquire( 'queue', 15 * MINUTE_IN_SECONDS ) ) {
			$summary['skipped'] = true;
			return $summary;
		}
		$started = microtime( true );
		try {
			$jobs = $this->manager->jobs();
			$jobs->release_stuck();
			$runner = new JobRunner( $this->manager );
			$token  = wp_generate_password( 20, false, false );
			foreach ( $jobs->claim( max( 1, $limit ), $token ) as $job ) {
				if ( ( microtime( true ) - $started ) > $budget && $summary['processed'] > 0 ) {
					// Out of time: give the job back untouched.
					$jobs->retry_or_fail( array_merge( $job, array( 'attempts' => (int) $job['attempts'] - 1 ) ), 'Deferred: time budget exhausted', PHP_INT_MAX );
					continue;
				}
				$outcome = $runner->run( $job );
				++$summary['processed'];
				++$summary[ $outcome ];
			}
			if ( 0 === wp_rand( 0, 20 ) ) {
				$jobs->purge_finished( 7 );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Queue crash', array( 'error' => $e->getMessage() ) );
		} finally {
			Lock::release( 'queue' );
		}
		return $summary;
	}

	/**
	 * Debounced staleness check for one item.
	 *
	 * @param int $item_id Item ID.
	 * @return void
	 */
	public function schedule_check( int $item_id ): void {
		if ( $item_id <= 0 || wp_next_scheduled( self::HOOK_CHECK, array( $item_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + 90, self::HOOK_CHECK, array( $item_id ) );
	}

	/**
	 * Cron: check one item; optionally queue regeneration.
	 *
	 * @param int $item_id Item ID.
	 * @return void
	 */
	public function cron_check_item( $item_id ): void {
		$item_id = (int) $item_id;
		$status  = $this->manager->check_stale( $item_id );
		if ( is_wp_error( $status ) ) {
			return;
		}
		if ( 'queue' === (string) Options::get( 'trigger_on_save', 'mark_stale' ) && in_array( $status, array( 'stale', 'none' ), true ) ) {
			$this->manager->enqueue( $item_id, array( 'action' => 'generate' ), 15 );
		}
	}

	/**
	 * Cron: daily sweep over ready narratives (50 per run, resumable offset).
	 *
	 * @return void
	 */
	public function cron_sweep(): void {
		$offset = (int) get_option( 'tn_sweep_offset', 0 );
		$batch  = $this->manager->repo()->ready_batch( 50, $offset );
		if ( ! $batch ) {
			update_option( 'tn_sweep_offset', 0, false );
			return;
		}
		foreach ( $batch as $row ) {
			$this->manager->check_stale( (int) $row['item_id'] );
		}
		update_option( 'tn_sweep_offset', $offset + count( $batch ), false );
		if ( 50 === count( $batch ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK_SWEEP );
		}
	}
}
