<?php
/**
 * Data access for wp_tn_jobs.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue persistence. Claiming is atomic: an UPDATE ... LIMIT stamps a random
 * token on the next runnable rows, then the worker SELECTs by that token, so
 * two overlapping cron runs never process the same job.
 */
final class JobRepository {

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	/**
	 * Minutes after which a "running" job is considered abandoned.
	 */
	public const STUCK_MINUTES = 15;

	/**
	 * Adds a job for an item, or refreshes the existing pending one.
	 *
	 * @param int                 $item_id  Item ID.
	 * @param array<string,mixed> $payload  Options for the runner.
	 * @param int                 $priority Lower runs first.
	 * @param int                 $delay    Seconds before it may run.
	 * @return int Job ID.
	 */
	public function enqueue( int $item_id, array $payload = array(), int $priority = 10, int $delay = 0 ): int {
		global $wpdb;
		$table = Tables::jobs();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; write path; dedupe lookup via %d/%s placeholders.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, payload FROM {$table} WHERE item_id = %d AND status IN (%s,%s) ORDER BY id DESC LIMIT 1", $item_id, self::STATUS_QUEUED, self::STATUS_RUNNING ), ARRAY_A );

		$now = current_time( 'mysql', true );
		if ( $existing ) {
			$old = json_decode( (string) $existing['payload'], true );
			$new = array_merge( is_array( $old ) ? $old : array(), $payload );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
			$wpdb->update(
				$table,
				array(
					'payload'    => wp_json_encode( $new ),
					'priority'   => $priority,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return (int) $existing['id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$wpdb->insert(
			$table,
			array(
				'item_id'    => $item_id,
				'stage'      => 'extract',
				'status'     => self::STATUS_QUEUED,
				'priority'   => $priority,
				'attempts'   => 0,
				'payload'    => wp_json_encode( $payload ),
				'run_after'  => gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Atomically claims up to $limit runnable jobs.
	 *
	 * @param int    $limit Max jobs.
	 * @param string $token Worker token.
	 * @return array<int,array<string,mixed>>
	 */
	public function claim( int $limit, string $token ): array {
		global $wpdb;
		$table = Tables::jobs();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Atomic claim (UPDATE ... ORDER BY ... LIMIT) on the plugin's own queue table; not expressible via WP APIs; write path.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, lock_token = %s, locked_at = %s, updated_at = %s WHERE status = %s AND run_after <= %s ORDER BY priority ASC, id ASC LIMIT %d", self::STATUS_RUNNING, $token, $now, $now, self::STATUS_QUEUED, $now, max( 1, $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; rows were just claimed by this worker token; write path.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token = %s AND status = %s ORDER BY priority ASC, id ASC", $token, self::STATUS_RUNNING ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns abandoned running jobs to the queue.
	 *
	 * @return int Rows affected.
	 */
	public function release_stuck(): int {
		global $wpdb;
		$table  = Tables::jobs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own queue table; maintenance write path.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, lock_token = NULL, locked_at = NULL, last_error = %s WHERE status = %s AND locked_at < %s", self::STATUS_QUEUED, 'Released after lock timeout', self::STATUS_RUNNING, $cutoff ) );
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Advances the stage of a running job (keeps it claimed).
	 *
	 * @param int    $id    Job ID.
	 * @param string $stage New stage.
	 * @return void
	 */
	public function set_stage( int $id, string $stage ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$wpdb->update(
			Tables::jobs(),
			array(
				'stage'      => $stage,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a job done.
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public function complete( int $id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$wpdb->update(
			Tables::jobs(),
			array(
				'status'      => self::STATUS_DONE,
				'lock_token'  => null,
				'finished_at' => $now,
				'updated_at'  => $now,
				'last_error'  => '',
			),
			array( 'id' => $id ),
			array( '%s', null, '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Re-queues a job with backoff, or fails it permanently.
	 *
	 * @param array<string,mixed> $job          Job row.
	 * @param string              $error        Error message.
	 * @param int                 $max_attempts Retry limit.
	 * @return bool True when re-queued, false when failed for good.
	 */
	public function retry_or_fail( array $job, string $error, int $max_attempts ): bool {
		global $wpdb;
		$attempts = (int) $job['attempts'] + 1;
		$now      = current_time( 'mysql', true );
		if ( $attempts >= $max_attempts ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
			$wpdb->update(
				Tables::jobs(),
				array(
					'status'      => self::STATUS_FAILED,
					'attempts'    => $attempts,
					'lock_token'  => null,
					'last_error'  => mb_substr( $error, 0, 2000 ),
					'finished_at' => $now,
					'updated_at'  => $now,
				),
				array( 'id' => (int) $job['id'] ),
				array( '%s', '%d', null, '%s', '%s', '%s' ),
				array( '%d' )
			);
			return false;
		}
		$delay = (int) ( 60 * pow( 3, $attempts - 1 ) ); // 1 min, 3 min, 9 min...
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$wpdb->update(
			Tables::jobs(),
			array(
				'status'     => self::STATUS_QUEUED,
				'attempts'   => $attempts,
				'lock_token' => null,
				'locked_at'  => null,
				'last_error' => mb_substr( $error, 0, 2000 ),
				'run_after'  => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'updated_at' => $now,
			),
			array( 'id' => (int) $job['id'] ),
			array( '%s', '%d', null, null, '%s', '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Cancels pending jobs of an item.
	 *
	 * @param int $item_id Item ID.
	 * @return void
	 */
	public function cancel_for_item( int $item_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$wpdb->delete(
			Tables::jobs(),
			array(
				'item_id' => $item_id,
				'status'  => self::STATUS_QUEUED,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Counters per status.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		global $wpdb;
		$table = Tables::jobs();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT/GROUP BY on the plugin's own table; not expressible via WP_Query.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		$out  = array(
			self::STATUS_QUEUED  => 0,
			self::STATUS_RUNNING => 0,
			self::STATUS_DONE    => 0,
			self::STATUS_FAILED  => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Recent jobs for the Processing tab.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = Tables::jobs();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; admin listing; queue state changes every run so caching would be stale.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, min( 200, $limit ) ) ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Last finished/attempted job (Diagnostics).
	 *
	 * @return array<string,mixed>|null
	 */
	public function last(): ?array {
		$rows = $this->recent( 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Deletes done/failed jobs older than N days.
	 *
	 * @param int $days Age in days.
	 * @return int Rows deleted.
	 */
	public function purge_finished( int $days = 7 ): int {
		global $wpdb;
		$table  = Tables::jobs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own queue table; maintenance write path.
		$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN (%s,%s) AND updated_at < %s", self::STATUS_DONE, self::STATUS_FAILED, $cutoff ) );
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Deletes all failed jobs.
	 *
	 * @return int
	 */
	public function clear_failed(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$n = $wpdb->delete( Tables::jobs(), array( 'status' => self::STATUS_FAILED ), array( '%s' ) );
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Casts a row.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$payload        = isset( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : null;
		$row['payload'] = is_array( $payload ) ? $payload : array();
		foreach ( array( 'id', 'item_id', 'narrative_id', 'priority', 'attempts' ) as $col ) {
			if ( isset( $row[ $col ] ) ) {
				$row[ $col ] = (int) $row[ $col ];
			}
		}
		return $row;
	}
}
