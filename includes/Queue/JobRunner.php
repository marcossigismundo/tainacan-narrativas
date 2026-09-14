<?php
/**
 * Executes one queued job.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Queue;

use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Database\JobRepository;
use TainacanNarrativas\Database\NarrativeRepository as Repo;
use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Narrative\NarrativeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload actions: generate (default) | audio | check. Retryable errors are
 * re-queued with backoff; on the last attempt an AI outage degrades to the
 * template script instead of failing the item.
 */
final class JobRunner {

	/**
	 * Manager.
	 *
	 * @var NarrativeManager
	 */
	private NarrativeManager $manager;

	/**
	 * Jobs.
	 *
	 * @var JobRepository
	 */
	private JobRepository $jobs;

	/**
	 * Constructor.
	 *
	 * @param NarrativeManager $manager Manager.
	 */
	public function __construct( NarrativeManager $manager ) {
		$this->manager = $manager;
		$this->jobs    = $manager->jobs();
	}

	/**
	 * Runs a claimed job.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string done|retry|failed
	 */
	public function run( array $job ): string {
		$item_id = (int) $job['item_id'];
		$payload = is_array( $job['payload'] ) ? $job['payload'] : array();
		$action  = (string) ( $payload['action'] ?? 'generate' );
		$max     = max( 1, (int) Options::get( 'max_attempts', 3 ) );
		$last    = ( (int) $job['attempts'] + 1 ) >= $max;

		Logger::debug(
			'Job start',
			array(
				'job'     => (int) $job['id'],
				'item'    => $item_id,
				'action'  => $action,
				'attempt' => (int) $job['attempts'] + 1,
			)
		);

		switch ( $action ) {
			case 'check':
				$result = $this->manager->check_stale( $item_id );
				if ( ! is_wp_error( $result ) && Repo::STATUS_STALE === $result && ! empty( $payload['then_generate'] ) ) {
					$this->manager->enqueue( $item_id, array( 'action' => 'generate' ), 15 );
				}
				break;
			case 'audio':
				$this->jobs->set_stage( (int) $job['id'], 'audio' );
				$result = $this->manager->run( $item_id, array( 'audio_only' => true ) );
				break;
			case 'approve':
				$this->jobs->set_stage( (int) $job['id'], 'audio' );
				$result = $this->manager->approve( $item_id, (int) ( $payload['approved_by'] ?? 0 ) );
				if ( is_wp_error( $result ) && 'tn_no_script' === $result->get_error_code() ) {
					$result = array(); // Already handled by someone else: nothing to do.
				}
				break;
			default:
				$this->jobs->set_stage( (int) $job['id'], 'generate' );
				$result = $this->manager->run(
					$item_id,
					array(
						'force'             => ! empty( $payload['force'] ),
						'mode'              => (string) ( $payload['mode'] ?? '' ),
						'skip_review'       => ! empty( $payload['skip_review'] ),
						'allow_ai_fallback' => $last,
					)
				);
		}

		if ( ! is_wp_error( $result ) ) {
			$this->jobs->complete( (int) $job['id'] );
			return 'done';
		}

		$code = (string) $result->get_error_code();
		if ( in_array( $code, NarrativeManager::RETRYABLE, true ) ) {
			$requeued = $this->jobs->retry_or_fail( $job, $result->get_error_message(), $max );
			if ( $requeued ) {
				return 'retry';
			}
		} else {
			$this->jobs->retry_or_fail( $job, $result->get_error_message(), 1 );
		}
		$row = $this->manager->repo()->get_current( $item_id );
		if ( $row && ! in_array( $row['status'], array( Repo::STATUS_READY, Repo::STATUS_REVIEW ), true ) ) {
			$this->manager->repo()->set_status( (int) $row['id'], Repo::STATUS_ERROR, $result->get_error_message() );
		}
		Logger::error(
			'Job failed',
			array(
				'job'     => (int) $job['id'],
				'item'    => $item_id,
				'code'    => $code,
				'message' => $result->get_error_message(),
			)
		);
		return 'failed';
	}
}
