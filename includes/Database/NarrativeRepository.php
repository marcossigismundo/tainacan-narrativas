<?php
/**
 * Data access for wp_tn_narratives.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + queries for narratives. All SQL targets the plugin's own table
 * (Pattern A): table name from $wpdb->prefix, every input via prepare().
 */
final class NarrativeRepository {

	public const STATUS_QUEUED       = 'queued';
	public const STATUS_EXTRACTING   = 'extracting';
	public const STATUS_SCRIPTING    = 'scripting';
	public const STATUS_REVIEW       = 'review';
	public const STATUS_SYNTHESIZING = 'synthesizing';
	public const STATUS_READY        = 'ready';
	public const STATUS_STALE        = 'stale';
	public const STATUS_ERROR        = 'error';
	public const STATUS_REQUIRES_OCR = 'requires_ocr';
	public const STATUS_INSUFFICIENT = 'insufficient';

	/**
	 * All known statuses (for validation / labels).
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_EXTRACTING,
			self::STATUS_SCRIPTING,
			self::STATUS_REVIEW,
			self::STATUS_SYNTHESIZING,
			self::STATUS_READY,
			self::STATUS_STALE,
			self::STATUS_ERROR,
			self::STATUS_REQUIRES_OCR,
			self::STATUS_INSUFFICIENT,
		);
	}

	/**
	 * Human labels.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'none'                    => __( 'Não gerada', 'tainacan-narrativas' ),
			self::STATUS_QUEUED       => __( 'Na fila', 'tainacan-narrativas' ),
			self::STATUS_EXTRACTING   => __( 'Processando texto', 'tainacan-narrativas' ),
			self::STATUS_SCRIPTING    => __( 'Gerando roteiro', 'tainacan-narrativas' ),
			self::STATUS_REVIEW       => __( 'Aguardando revisão', 'tainacan-narrativas' ),
			self::STATUS_SYNTHESIZING => __( 'Gerando áudio', 'tainacan-narrativas' ),
			self::STATUS_READY        => __( 'Pronta', 'tainacan-narrativas' ),
			self::STATUS_STALE        => __( 'Desatualizada', 'tainacan-narrativas' ),
			self::STATUS_ERROR        => __( 'Erro', 'tainacan-narrativas' ),
			self::STATUS_REQUIRES_OCR => __( 'OCR necessário', 'tainacan-narrativas' ),
			self::STATUS_INSUFFICIENT => __( 'Conteúdo insuficiente', 'tainacan-narrativas' ),
		);
	}

	/**
	 * Columns that hold JSON.
	 *
	 * @var string[]
	 */
	private const JSON_COLUMNS = array( 'sources', 'stats' );

	/**
	 * Current narrative for an item.
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|null
	 */
	public function get_current( int $item_id ): ?array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; not available via WP_Query; $wpdb->prefix is trusted; input via %d. Cache invalidates per generation event.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %d AND is_current = 1 ORDER BY version DESC LIMIT 1", $item_id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Narrative by ID.
	 *
	 * @param int $id Row ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; not available via WP_Query; $wpdb->prefix is trusted; input via %d.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * All versions of an item, newest first.
	 *
	 * @param int $item_id Item ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function versions( int $item_id ): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; not available via WP_Query; $wpdb->prefix is trusted; input via %d.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %d ORDER BY version DESC", $item_id ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Creates a new version for an item and marks it current.
	 *
	 * @param int                 $item_id       Item ID.
	 * @param int                 $collection_id Collection ID.
	 * @param array<string,mixed> $data          Column values.
	 * @return int New row ID (0 on failure).
	 */
	public function create_version( int $item_id, int $collection_id, array $data = array() ): int {
		global $wpdb;
		$table = Tables::narratives();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate MAX on the plugin's own table; not expressible via WP_Query; write path.
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE item_id = %d", $item_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$wpdb->update( $table, array( 'is_current' => 0 ), array( 'item_id' => $item_id ), array( '%d' ), array( '%d' ) );

		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'status' => self::STATUS_QUEUED,
			),
			$this->serialize( $data ),
			array(
				'item_id'       => $item_id,
				'collection_id' => $collection_id,
				'version'       => $max + 1,
				'is_current'    => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$ok = $wpdb->insert( $table, $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates columns of a narrative.
	 *
	 * @param int                 $id   Row ID.
	 * @param array<string,mixed> $data Column values.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data               = $this->serialize( $data );
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		$result = $wpdb->update( Tables::narratives(), $data, array( 'id' => $id ), null, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Convenience: set status (+ optional error).
	 *
	 * @param int         $id     Row ID.
	 * @param string      $status Status.
	 * @param string|null $error  Error message (kept when null).
	 * @return bool
	 */
	public function set_status( int $id, string $status, ?string $error = null ): bool {
		$data = array( 'status' => $status );
		if ( null !== $error ) {
			$data['last_error'] = $error;
		} elseif ( self::STATUS_ERROR !== $status ) {
			$data['last_error'] = '';
		}
		return $this->update( $id, $data );
	}

	/**
	 * Deletes one narrative row (the caller removes the audio attachment).
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path.
		return false !== $wpdb->delete( Tables::narratives(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Old versions beyond the retention limit (never the current one).
	 *
	 * @param int $item_id Item ID.
	 * @param int $keep    Versions to keep (0 = all).
	 * @return array<int,array<string,mixed>> Rows to prune.
	 */
	public function prunable_versions( int $item_id, int $keep ): array {
		if ( $keep <= 0 ) {
			return array();
		}
		$versions = $this->versions( $item_id );
		$kept     = 0;
		$prune    = array();
		foreach ( $versions as $row ) {
			if ( 1 === (int) $row['is_current'] ) {
				continue;
			}
			++$kept;
			if ( $kept >= $keep ) {
				$prune[] = $row;
			}
		}
		return $prune;
	}

	/**
	 * Paginated listing for the admin table.
	 *
	 * @param array<string,mixed> $filters status|collection_id|search|item_id.
	 * @param int                 $limit   Page size.
	 * @param int                 $offset  Offset.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public function search( array $filters, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table = Tables::narratives();
		$where = array( 'is_current = 1' );
		$args  = array();

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::statuses(), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['collection_id'] ) ) {
			$where[] = 'collection_id = %d';
			$args[]  = (int) $filters['collection_id'];
		}
		if ( ! empty( $filters['item_id'] ) ) {
			$where[] = 'item_id = %d';
			$args[]  = (int) $filters['item_id'];
		}
		if ( ! empty( $filters['item_ids'] ) && is_array( $filters['item_ids'] ) ) {
			$ids = array_map( 'intval', $filters['item_ids'] );
			if ( $ids ) {
				$where[] = 'item_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, $ids );
			}
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, min( 200, $limit ) );
		$offset    = max( 0, $offset );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders -- Plugin's own table; not available via WP_Query; $wpdb->prefix is trusted; WHERE built from an allowlist of columns with %s/%d placeholders (IN-clause via array_fill) whose count the sniff cannot follow; listing invalidates per generation event.
		if ( $args ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}
		// phpcs:enable

		return array(
			'rows'  => array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Dashboard counters.
	 *
	 * @return array<string,int|float>
	 */
	public function stats(): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT/GROUP BY on the plugin's own table; not expressible via WP_Query.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} WHERE is_current = 1 GROUP BY status", ARRAY_A );
		$out  = array_fill_keys( self::statuses(), 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate SUM on the plugin's own table; not expressible via WP_Query.
		$out['total_duration'] = (float) $wpdb->get_var( "SELECT COALESCE(SUM(duration),0) FROM {$table} WHERE is_current = 1 AND status = 'ready'" );
		$out['total']          = array_sum( array_intersect_key( $out, array_flip( self::statuses() ) ) );
		return $out;
	}

	/**
	 * Current narratives that should be re-checked for staleness (cron sweep).
	 *
	 * @param int $limit  Batch size.
	 * @param int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function ready_batch( int $limit, int $offset ): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; cron sweep path; caching is irrelevant.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_id, collection_id, source_hash, status FROM {$table} WHERE is_current = 1 AND status IN ('ready','review') ORDER BY id ASC LIMIT %d OFFSET %d", max( 1, $limit ), max( 0, $offset ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Current narrative IDs for a collection (bulk operations).
	 *
	 * @param int $collection_id Collection ID.
	 * @return int[] Item IDs that already have a narrative row.
	 */
	public function item_ids_for_collection( int $collection_id ): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; write/bulk path.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM {$table} WHERE is_current = 1 AND collection_id = %d", $collection_id ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Items of a collection whose current narrative is a template fallback
	 * produced while the AI failed (stats.ai_fallback present).
	 *
	 * @param int $collection_id Collection ID.
	 * @param int $limit         Max IDs.
	 * @return int[]
	 */
	public function fallback_item_ids( int $collection_id, int $limit = 200 ): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; bulk/cron path; JSON column probed with LIKE on a %s placeholder.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM {$table} WHERE is_current = 1 AND collection_id = %d AND status = %s AND ai_provider = %s AND stats LIKE %s ORDER BY id ASC LIMIT %d", $collection_id, self::STATUS_READY, 'template', '%' . $wpdb->esc_like( '"ai_fallback"' ) . '%', max( 1, $limit ) ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Item IDs whose current narrative has a given status.
	 *
	 * @param string $status Status.
	 * @param int    $limit  Max IDs.
	 * @return int[]
	 */
	public function item_ids_by_status( string $status, int $limit = 5000 ): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; bulk path; input via %s/%d placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM {$table} WHERE is_current = 1 AND status = %s ORDER BY id ASC LIMIT %d", $status, max( 1, $limit ) ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Status counters per collection (dashboard coverage).
	 *
	 * @return array<int,array<string,int>>
	 */
	public function coverage_counts(): array {
		global $wpdb;
		$table = Tables::narratives();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT/GROUP BY on the plugin's own table; not expressible via WP_Query.
		$rows = $wpdb->get_results( "SELECT collection_id, status, COUNT(*) AS c FROM {$table} WHERE is_current = 1 GROUP BY collection_id, status", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['collection_id'] ][ (string) $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Encodes JSON columns.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return array<string,mixed>
	 */
	private function serialize( array $data ): array {
		foreach ( self::JSON_COLUMNS as $col ) {
			if ( array_key_exists( $col, $data ) && ! is_string( $data[ $col ] ) ) {
				$data[ $col ] = wp_json_encode( $data[ $col ] );
			}
		}
		return $data;
	}

	/**
	 * Decodes JSON columns and casts ints.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( self::JSON_COLUMNS as $col ) {
			$decoded     = isset( $row[ $col ] ) && is_string( $row[ $col ] ) ? json_decode( $row[ $col ], true ) : null;
			$row[ $col ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'item_id', 'collection_id', 'version', 'is_current', 'audio_attachment_id', 'approved_by', 'script_edited_by' ) as $int_col ) {
			if ( isset( $row[ $int_col ] ) ) {
				$row[ $int_col ] = (int) $row[ $int_col ];
			}
		}
		$row['duration'] = isset( $row['duration'] ) ? (float) $row['duration'] : 0.0;
		return $row;
	}
}
