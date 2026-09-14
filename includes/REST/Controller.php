<?php
/**
 * REST API (tainacan-narrativas/v1).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\REST;

use TainacanNarrativas\Admin\Diagnostics;
use TainacanNarrativas\Core\Capabilities;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Database\NarrativeRepository as Repo;
use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Narrative\Modes;
use TainacanNarrativas\Narrative\NarrativeManager;
use TainacanNarrativas\Queue\QueueManager;
use TainacanNarrativas\Tainacan\CollectionSettings;
use TainacanNarrativas\Tainacan\ItemDetector;
use TainacanNarrativas\TTS\AudioStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public:
 *   GET  /public/items/{id}                  player payload (item must be readable + collection enabled)
 * Review capability:
 *   GET  /items/{id}  /items/{id}/script  PUT /items/{id}/script  POST /items/{id}/approve
 *   GET  /narratives  /stats  /jobs
 * Generate capability:
 *   GET  /items/{id}/preview  POST /items/{id}/generate|regenerate|check
 *   POST /collections/{id}/generate  POST /collections/generate-all  POST /queue/run
 *   POST /narratives/approve-all (review)  GET /coverage (review)
 * Manage capability:
 *   DELETE /items/{id}/audio  DELETE /items/{id}  POST /queue/clear-failed
 *   GET /providers  POST /providers/test  GET /diagnostics  POST /diagnostics/test-write|test-audio  GET /logs
 */
final class Controller {

	public const NS = 'tainacan-narrativas/v1';

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
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$id_arg = array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
		);
		$bool   = array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		);

		register_rest_route(
			self::NS,
			'/public/items/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'public_item' ),
				'permission_callback' => array( $this, 'public_item_permission' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'item_status' ),
					'permission_callback' => array( $this, 'can_review' ),
					'args'                => array( 'id' => $id_arg ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'item_delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => $id_arg ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'item_preview' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);

		$generate_args = array(
			'id'          => $id_arg,
			'force'       => $bool,
			'sync'        => $bool,
			'mode'        => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $v ) => '' === $v || Modes::exists( (string) $v ),
			),
			'audio_only'  => $bool,
			'skip_review' => $bool,
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'item_generate' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => $generate_args,
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/regenerate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'item_regenerate' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => $generate_args,
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'item_check' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/script',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'script_get' ),
					'permission_callback' => array( $this, 'can_review' ),
					'args'                => array( 'id' => $id_arg ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'script_put' ),
					'permission_callback' => array( $this, 'can_review' ),
					'args'                => array(
						'id'     => $id_arg,
						'script' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'validate_callback' => static fn( $v ) => is_string( $v ) && '' !== trim( $v ) && mb_strlen( $v ) <= 200000,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'item_approve' ),
				'permission_callback' => array( $this, 'can_review' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/audio',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'audio_delete' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/narratives',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'narratives_list' ),
				'permission_callback' => array( $this, 'can_review' ),
				'args'                => array(
					'status'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $v ) => '' === $v || in_array( (string) $v, Repo::statuses(), true ),
					),
					'collection_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'item_id'       => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'page'          => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'      => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'can_review' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'jobs' ),
				'permission_callback' => array( $this, 'can_review' ),
			)
		);
		register_rest_route(
			self::NS,
			'/queue/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'queue_run' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/queue/clear-failed',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'queue_clear_failed' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/collections/(?P<id>\d+)/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'collection_generate' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'id'           => $id_arg,
					'only_pending' => array_merge( $bool, array( 'default' => true ) ),
					'force'        => $bool,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/collections/generate-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'collections_generate_all' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/narratives/approve-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'narratives_approve_all' ),
				'permission_callback' => array( $this, 'can_review' ),
			)
		);
		register_rest_route(
			self::NS,
			'/coverage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'coverage' ),
				'permission_callback' => array( $this, 'can_review' ),
			)
		);

		register_rest_route(
			self::NS,
			'/providers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'providers' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/providers/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'providers_test' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'kind'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $v ) => in_array( (string) $v, array( 'ai', 'tts' ), true ),
					),
					'provider' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/diagnostics/test-write',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'diagnostics_test_write' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/diagnostics/test-audio',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'diagnostics_test_audio' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Public player data: the item must exist, be readable by the visitor and
	 * belong to an enabled collection. Never `__return_true`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function public_item_permission( WP_REST_Request $request ) {
		$item = ItemDetector::get_item( (int) $request['id'] );
		if ( ! $item || ! $item->can_read() || 'publish' !== $item->get_status() ) {
			return new WP_Error( 'tn_forbidden', __( 'Item indisponível.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		if ( ! CollectionSettings::is_enabled( (int) $item->get_collection_id() ) ) {
			return new WP_Error( 'tn_forbidden', __( 'Narrativas não habilitadas para esta coleção.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Review capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_review() {
		return $this->cap( Capabilities::REVIEW );
	}

	/**
	 * Generate capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_generate() {
		return $this->cap( Capabilities::GENERATE );
	}

	/**
	 * Manage capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		return $this->cap( Capabilities::MANAGE );
	}

	/**
	 * Capability check with a proper status code.
	 *
	 * @param string $cap Capability.
	 * @return bool|WP_Error
	 */
	private function cap( string $cap ) {
		if ( current_user_can( $cap ) ) {
			return true;
		}
		return new WP_Error( 'tn_forbidden', __( 'Você não tem permissão para esta ação.', 'tainacan-narrativas' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Public payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function public_item( WP_REST_Request $request ) {
		$payload = $this->manager->public_payload( (int) $request['id'] );
		if ( ! $payload ) {
			return new WP_Error( 'tn_not_ready', __( 'Nenhuma narrativa disponível para este item.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		$response = new WP_REST_Response( $payload );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Admin status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_status( WP_REST_Request $request ) {
		return rest_ensure_response( $this->manager->status( (int) $request['id'] ) );
	}

	/**
	 * Preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_preview( WP_REST_Request $request ) {
		return rest_ensure_response( $this->manager->preview( (int) $request['id'] ) );
	}

	/**
	 * Generate (queue by default; sync when requested).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_generate( WP_REST_Request $request ) {
		return $this->generate( $request, (bool) $request['force'] );
	}

	/**
	 * Regenerate = generate with force.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_regenerate( WP_REST_Request $request ) {
		return $this->generate( $request, true );
	}

	/**
	 * Shared generate implementation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $force   Force.
	 * @return mixed
	 */
	private function generate( WP_REST_Request $request, bool $force ) {
		$item_id = (int) $request['id'];
		$opts    = array(
			'force'       => $force,
			'mode'        => (string) $request['mode'],
			'audio_only'  => (bool) $request['audio_only'],
			'skip_review' => (bool) $request['skip_review'],
		);
		if ( $request['sync'] ) {
			$opts['allow_ai_fallback'] = false;
			$result                    = $this->manager->run( $item_id, $opts );
			if ( is_wp_error( $result ) ) {
				return $this->error( $result );
			}
			return rest_ensure_response(
				array(
					'mode'      => 'sync',
					'narrative' => $result,
				)
			);
		}
		$payload = array_merge(
			$opts,
			array(
				'action'       => $opts['audio_only'] ? 'audio' : 'generate',
				'requested_by' => get_current_user_id(),
			)
		);
		$job     = $this->manager->enqueue( $item_id, $payload, 5 );
		if ( is_wp_error( $job ) ) {
			return $this->error( $job );
		}
		return rest_ensure_response(
			array(
				'mode'      => 'queued',
				'job_id'    => (int) $job,
				'narrative' => $this->current( $item_id ),
			)
		);
	}

	/**
	 * Stale check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_check( WP_REST_Request $request ) {
		$status = $this->manager->check_stale( (int) $request['id'] );
		if ( is_wp_error( $status ) ) {
			return $this->error( $status );
		}
		return rest_ensure_response(
			array(
				'status'    => $status,
				'narrative' => $this->current( (int) $request['id'] ),
			)
		);
	}

	/**
	 * Script read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function script_get( WP_REST_Request $request ) {
		$row = $this->manager->repo()->get_current( (int) $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'tn_not_found', __( 'Sem narrativa para este item.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'generated_script' => (string) $row['generated_script'],
				'edited_script'    => (string) $row['edited_script'],
				'final_script'     => NarrativeManager::final_script( $row ),
				'status'           => $row['status'],
				'script_edited_by' => $row['script_edited_by'],
				'script_edited_at' => $row['script_edited_at'],
			)
		);
	}

	/**
	 * Script write.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function script_put( WP_REST_Request $request ) {
		$result = $this->manager->save_script( (int) $request['id'], (string) $request['script'], get_current_user_id() );
		return is_wp_error( $result ) ? $this->error( $result ) : rest_ensure_response( array( 'narrative' => $result ) );
	}

	/**
	 * Approve → synthesize.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_approve( WP_REST_Request $request ) {
		$result = $this->manager->approve( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $result ) ? $this->error( $result ) : rest_ensure_response( array( 'narrative' => $result ) );
	}

	/**
	 * Delete audio.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function audio_delete( WP_REST_Request $request ) {
		$result = $this->manager->delete_audio( (int) $request['id'] );
		return is_wp_error( $result ) ? $this->error( $result ) : rest_ensure_response( array( 'narrative' => $result ) );
	}

	/**
	 * Delete narrative (all versions).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function item_delete( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'deleted' => $this->manager->delete_all( (int) $request['id'] ) ) );
	}

	/**
	 * Listing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function narratives_list( WP_REST_Request $request ) {
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );
		$result   = $this->manager->repo()->search(
			array(
				'status'        => (string) $request['status'],
				'collection_id' => (int) $request['collection_id'],
				'item_id'       => (int) $request['item_id'],
			),
			$per_page,
			( $page - 1 ) * $per_page
		);
		$rows     = array();
		foreach ( $result['rows'] as $row ) {
			$row = $this->manager->public_safe_row( $row );
			unset( $row['generated_script'], $row['edited_script'], $row['final_script'] );
			$row['item_title']      = (string) get_the_title( (int) $row['item_id'] );
			$row['item_url']        = (string) get_permalink( (int) $row['item_id'] );
			$row['collection_name'] = (string) get_the_title( (int) $row['collection_id'] );
			$rows[]                 = $row;
		}
		return rest_ensure_response(
			array(
				'rows'     => $rows,
				'total'    => (int) $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Dashboard stats.
	 *
	 * @return mixed
	 */
	public function stats() {
		return rest_ensure_response(
			array(
				'narratives' => $this->manager->repo()->stats(),
				'jobs'       => $this->manager->jobs()->counts(),
			)
		);
	}

	/**
	 * Jobs.
	 *
	 * @return mixed
	 */
	public function jobs() {
		return rest_ensure_response(
			array(
				'counts' => $this->manager->jobs()->counts(),
				'recent' => $this->manager->jobs()->recent( 50 ),
			)
		);
	}

	/**
	 * Runs the queue synchronously (admin button / hosts without cron).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function queue_run( WP_REST_Request $request ) {
		$limit   = max( 1, min( 5, (int) $request['limit'] ) );
		$summary = $this->queue->process( $limit, 25 );
		return rest_ensure_response( array_merge( $summary, array( 'counts' => $this->manager->jobs()->counts() ) ) );
	}

	/**
	 * Clears failed jobs.
	 *
	 * @return mixed
	 */
	public function queue_clear_failed() {
		return rest_ensure_response( array( 'cleared' => $this->manager->jobs()->clear_failed() ) );
	}

	/**
	 * Bulk enqueue for a collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function collection_generate( WP_REST_Request $request ) {
		$n = $this->manager->enqueue_collection( (int) $request['id'], (bool) $request['only_pending'], (bool) $request['force'] );
		return rest_ensure_response(
			array(
				'queued' => $n,
				'counts' => $this->manager->jobs()->counts(),
			)
		);
	}

	/**
	 * Bulk enqueue of pending items across every enabled collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function collections_generate_all( WP_REST_Request $request ) {
		$result = $this->manager->enqueue_pending_everywhere( (int) $request['limit'] );
		return rest_ensure_response( array_merge( $result, array( 'counts' => $this->manager->jobs()->counts() ) ) );
	}

	/**
	 * Queues approval for every narrative waiting for review.
	 *
	 * @return mixed
	 */
	public function narratives_approve_all() {
		$n = $this->manager->approve_all_pending( get_current_user_id() );
		return rest_ensure_response(
			array(
				'queued' => $n,
				'counts' => $this->manager->jobs()->counts(),
			)
		);
	}

	/**
	 * Coverage per collection.
	 *
	 * @return mixed
	 */
	public function coverage() {
		return rest_ensure_response( array( 'collections' => $this->manager->coverage() ) );
	}

	/**
	 * Providers overview (never the raw keys).
	 *
	 * @return mixed
	 */
	public function providers() {
		$ai  = array();
		$tts = array();
		foreach ( $this->manager->ai()->all() as $id => $p ) {
			$ai[ $id ] = array(
				'label'      => $p->label(),
				'configured' => $p->is_configured(),
				'external'   => $p->is_external(),
				'model'      => $p->model(),
			);
		}
		foreach ( $this->manager->tts()->all() as $id => $p ) {
			$tts[ $id ] = array(
				'label'       => $p->label(),
				'configured'  => $p->is_configured(),
				'external'    => $p->is_external(),
				'server_side' => $p->is_server_side(),
				'voice'       => $p->default_voice(),
			);
		}
		return rest_ensure_response(
			array(
				'ai'      => $ai,
				'tts'     => $tts,
				'secrets' => array(
					'ai_api_key'     => array(
						'mask'     => Options::secret_mask( 'ai_api_key' ),
						'constant' => Options::secret_is_constant( 'ai_api_key' ),
					),
					'gemini_api_key' => array(
						'mask'     => Options::secret_mask( 'gemini_api_key' ),
						'constant' => Options::secret_is_constant( 'gemini_api_key' ),
					),
					'tts_api_key'    => array(
						'mask'     => Options::secret_mask( 'tts_api_key' ),
						'constant' => Options::secret_is_constant( 'tts_api_key' ),
					),
				),
			)
		);
	}

	/**
	 * Tests a provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function providers_test( WP_REST_Request $request ) {
		$kind = (string) $request['kind'];
		$id   = (string) $request['provider'];
		if ( 'ai' === $kind ) {
			$id       = '' !== $id ? $id : (string) Options::get( 'ai_provider', 'none' );
			$provider = $this->manager->ai()->get( $id );
		} else {
			$id       = '' !== $id ? $id : (string) Options::get( 'tts_provider', 'browser' );
			$provider = $this->manager->tts()->get( $id );
		}
		if ( ! $provider ) {
			return new WP_Error( 'tn_not_found', __( 'Provedor não encontrado ou não selecionado.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		$result             = $provider->test();
		$result['details']  = Logger::redact_array( is_array( $result['details'] ?? null ) ? $result['details'] : array() );
		$result['provider'] = $id;
		return rest_ensure_response( $result );
	}

	/**
	 * Diagnostics.
	 *
	 * @return mixed
	 */
	public function diagnostics() {
		return rest_ensure_response( ( new Diagnostics( $this->manager ) )->run() );
	}

	/**
	 * Write test.
	 *
	 * @return mixed
	 */
	public function diagnostics_test_write() {
		$result = AudioStorage::test_write();
		return is_wp_error( $result ) ? $this->error( $result ) : rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Gravação no diretório de uploads OK.', 'tainacan-narrativas' ),
			)
		);
	}

	/**
	 * Generates a short test audio with the configured TTS.
	 *
	 * @return mixed
	 */
	public function diagnostics_test_audio() {
		$provider = $this->manager->tts()->get( (string) Options::get( 'tts_provider', 'browser' ) );
		if ( ! $provider || ! $provider->is_server_side() ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'browser' => true,
					'message' => __( 'O TTS selecionado roda no navegador; use o player de teste abaixo.', 'tainacan-narrativas' ),
				)
			);
		}
		$audio = $provider->synthesize( __( 'Este é um áudio de teste do Tainacan Narrativas.', 'tainacan-narrativas' ), $provider->default_voice(), array( 'timeout' => 90 ) );
		if ( is_wp_error( $audio ) ) {
			return $this->error( $audio );
		}
		$previous = (int) get_option( 'tn_test_audio_id', 0 );
		if ( $previous > 0 ) {
			AudioStorage::delete( $previous );
		}
		$att = AudioStorage::store( 0, 0, $audio['audio'], $audio['extension'], $audio['mime'], 'tn-test-audio' );
		if ( is_wp_error( $att ) ) {
			return $this->error( $att );
		}
		update_option( 'tn_test_audio_id', (int) $att, false );
		return rest_ensure_response(
			array(
				'success'  => true,
				'url'      => AudioStorage::url( (int) $att ),
				'voice'    => $audio['voice'],
				'duration' => AudioStorage::duration( (int) $att ),
			)
		);
	}

	/**
	 * Recent log entries.
	 *
	 * @return mixed
	 */
	public function logs() {
		return rest_ensure_response( array( 'entries' => Logger::recent( 100 ) ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Current narrative (public-safe) or null.
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|null
	 */
	private function current( int $item_id ): ?array {
		$row = $this->manager->repo()->get_current( $item_id );
		return $row ? $this->manager->public_safe_row( $row ) : null;
	}

	/**
	 * Ensures every WP_Error carries an HTTP status.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private function error( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$code   = (string) $error->get_error_code();
			$status = 'tn_not_found' === $code ? 404 : ( 'tn_locked' === $code ? 409 : 400 );
			$error->add_data( array( 'status' => $status ) );
		}
		return $error;
	}
}
