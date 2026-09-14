<?php
/**
 * Narrative lifecycle orchestration.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

use TainacanNarrativas\AI\ProviderManager as AIProviders;
use TainacanNarrativas\Core\Lock;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Database\JobRepository;
use TainacanNarrativas\Database\NarrativeRepository as Repo;
use TainacanNarrativas\Documents\ExtractionResult;
use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Tainacan\CollectionSettings;
use TainacanNarrativas\Tainacan\ContentCollector;
use TainacanNarrativas\Tainacan\ItemDetector;
use TainacanNarrativas\TTS\AudioStorage;
use TainacanNarrativas\TTS\ProviderManager as TTSProviders;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pipeline: generate → store → serve.
 *
 * Collect corpus → score/hash → script (AI or template) → [review gate]
 * → TTS (server audio or browser marker) → persist version → prune.
 *
 * Every public method is safe to call from REST, WP-CLI or the queue worker;
 * long operations are meant to run in the worker, never on a public request.
 */
final class NarrativeManager {

	/**
	 * Error codes the queue may retry.
	 *
	 * @var string[]
	 */
	public const RETRYABLE = array( 'tn_ai_retryable', 'tn_ai_malformed', 'tn_ai_invalid_json', 'tn_locked', 'tn_tts_retryable' );

	/**
	 * Narratives repository.
	 *
	 * @var Repo
	 */
	private Repo $repo;

	/**
	 * Jobs repository.
	 *
	 * @var JobRepository
	 */
	private JobRepository $jobs;

	/**
	 * Collector.
	 *
	 * @var ContentCollector
	 */
	private ContentCollector $collector;

	/**
	 * Template builder.
	 *
	 * @var ScriptBuilder
	 */
	private ScriptBuilder $builder;

	/**
	 * AI generator.
	 *
	 * @var NarrativeGenerator
	 */
	private NarrativeGenerator $generator;

	/**
	 * AI providers.
	 *
	 * @var AIProviders
	 */
	private AIProviders $ai;

	/**
	 * TTS providers.
	 *
	 * @var TTSProviders
	 */
	private TTSProviders $tts;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo      = new Repo();
		$this->jobs      = new JobRepository();
		$this->collector = new ContentCollector();
		$this->builder   = new ScriptBuilder();
		$this->generator = new NarrativeGenerator();
		$this->ai        = new AIProviders();
		$this->tts       = new TTSProviders();
	}

	/**
	 * Narratives repository accessor.
	 *
	 * @return Repo
	 */
	public function repo(): Repo {
		return $this->repo;
	}

	/**
	 * Jobs repository accessor.
	 *
	 * @return JobRepository
	 */
	public function jobs(): JobRepository {
		return $this->jobs;
	}

	/**
	 * AI providers accessor.
	 *
	 * @return AIProviders
	 */
	public function ai(): AIProviders {
		return $this->ai;
	}

	/**
	 * TTS providers accessor.
	 *
	 * @return TTSProviders
	 */
	public function tts(): TTSProviders {
		return $this->tts;
	}

	/**
	 * Script that is actually narrated (human edit wins).
	 *
	 * @param array<string,mixed> $row Narrative row.
	 * @return string
	 */
	public static function final_script( array $row ): string {
		$edited = (string) ( $row['edited_script'] ?? '' );
		return '' !== trim( $edited ) ? $edited : (string) ( $row['generated_script'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Read side
	// -------------------------------------------------------------------------

	/**
	 * Admin status for an item: current row + collection config summary.
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function status( int $item_id ) {
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'tn_not_found', __( 'Item Tainacan não encontrado.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		$config  = CollectionSettings::effective( (int) $item->get_collection_id() );
		$row     = $this->repo->get_current( $item_id );
		$pending = $this->jobs->counts();
		return array(
			'item_id'       => $item_id,
			'item_title'    => (string) $item->get_title(),
			'item_url'      => (string) get_permalink( $item_id ),
			'item_edit_url' => (string) $item->get_edit_url(),
			'collection_id' => (int) $item->get_collection_id(),
			'enabled'       => (bool) $config['enabled'],
			'config'        => array(
				'mode'           => $config['mode'],
				'ai'             => $config['ai'],
				'tts'            => $config['tts'],
				'editorial_flow' => $config['editorial_flow'],
				'sensitivity'    => $config['sensitivity'],
				'allow_download' => $config['allow_download'],
			),
			'narrative'     => $row ? $this->public_safe_row( $row ) : null,
			'versions'      => count( $this->repo->versions( $item_id ) ),
			'queue'         => $pending,
		);
	}

	/**
	 * Pre-generation preview: exactly what would be used, with health and score.
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview( int $item_id ) {
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'tn_not_found', __( 'Item Tainacan não encontrado.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		$config = CollectionSettings::effective( (int) $item->get_collection_id() );
		$corpus = $this->collector->collect( $item, $config );
		$score  = ContentScore::score( $corpus );
		$reason = null;
		$ai     = $this->ai->for_collection( $config, $reason );
		$hash   = SourceHasher::source_hash( $corpus, $this->hash_config( $config ) );
		$row    = $this->repo->get_current( $item_id );

		$sources_text = $this->builder->sources_block( $corpus, ! $ai || ! $ai->is_external() || ! empty( $config['external_ai_attachments'] ) );

		$trim = static function ( array $entry ): array {
			$entry['excerpt'] = mb_substr( (string) ( $entry['text'] ?? '' ), 0, 400 );
			unset( $entry['text'] );
			return $entry;
		};

		return array(
			'item_id'     => $item_id,
			'title'       => $corpus['title'],
			'collection'  => $corpus['collection_name'],
			'description' => mb_substr( (string) $corpus['description'], 0, 600 ),
			'metadata'    => $corpus['metadata'],
			'document'    => $corpus['document'] ? $trim( $corpus['document'] ) : null,
			'attachments' => array_map( $trim, $corpus['attachments'] ),
			'sources'     => $corpus['sources'],
			'health'      => $corpus['health'],
			'score'       => $score,
			'source_hash' => $hash,
			'is_current'  => $row && $row['source_hash'] === $hash,
			'ai'          => array(
				'provider' => $ai ? $ai->id() : 'none',
				'label'    => $ai ? $ai->label() : (string) $reason,
				'model'    => $ai ? $ai->model() : '',
				'external' => $ai ? $ai->is_external() : false,
			),
			'estimate'    => array(
				'chars'  => mb_strlen( $sources_text ),
				'tokens' => Normalizer::estimate_tokens( $sources_text ),
				'chunks' => count( Chunker::chunk( (string) ( $corpus['document']['text'] ?? '' ), (int) $config['chunk_size'] ) ),
			),
			'mode'        => $config['mode'],
		);
	}

	/**
	 * Faithfulness report of the current (final) script against the item's
	 * sources — for the review screen, including after human edits.
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function faithfulness( int $item_id ) {
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'tn_not_found', __( 'Item Tainacan não encontrado.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		$row = $this->repo->get_current( $item_id );
		if ( ! $row || '' === trim( self::final_script( $row ) ) ) {
			return new WP_Error( 'tn_no_script', __( 'Não há roteiro para verificar.', 'tainacan-narrativas' ), array( 'status' => 404 ) );
		}
		$config               = CollectionSettings::effective( (int) $item->get_collection_id() );
		$corpus               = $this->collector->collect( $item, $config );
		$checker              = new FaithfulnessChecker( $corpus, ScriptBuilder::boilerplate_sentences() );
		$report               = $checker->check( self::final_script( $row ) );
		$report['sentences']  = array_values(
			array_filter(
				$report['sentences'],
				static fn( array $s ): bool => ! $s['ok']
			)
		);
		$report['generation'] = $row['stats']['faithfulness'] ?? null;
		$report['words']      = Normalizer::word_count( self::final_script( $row ) );
		$report['max_words']  = Modes::target_words_for( (string) $row['mode'] );
		return $report;
	}

	/**
	 * Data for the public player. Null when nothing should be shown.
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|null
	 */
	public function public_payload( int $item_id ): ?array {
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item || ! $item->can_read() ) {
			return null;
		}
		$collection_id = (int) $item->get_collection_id();
		if ( ! CollectionSettings::is_enabled( $collection_id ) ) {
			return null;
		}
		$row = $this->repo->get_current( $item_id );
		if ( ! $row || ! in_array( $row['status'], array( Repo::STATUS_READY, Repo::STATUS_STALE ), true ) ) {
			return null;
		}
		$config = CollectionSettings::effective( $collection_id );
		$script = self::final_script( $row );
		if ( '' === trim( $script ) ) {
			return null;
		}
		$audio_url = $row['audio_attachment_id'] ? AudioStorage::url( (int) $row['audio_attachment_id'] ) : '';
		$is_ai     = ! empty( $row['ai_provider'] ) && 'template' !== $row['ai_provider'];

		return array(
			'item_id'        => $item_id,
			'status'         => $row['status'],
			'mode'           => $row['mode'],
			'playback'       => '' !== $audio_url ? 'audio' : 'browser',
			'audio_url'      => $audio_url,
			'audio_mime'     => $row['audio_attachment_id'] ? (string) get_post_mime_type( (int) $row['audio_attachment_id'] ) : '',
			'duration'       => (float) $row['duration'],
			'transcript'     => $script,
			'language'       => (string) $row['language'],
			'allow_download' => (bool) $config['allow_download'] && '' !== $audio_url,
			'provenance'     => Options::is( 'provenance_notice' )
				? ( $is_ai
					? __( 'Narração gerada automaticamente apenas com as informações registradas neste item (metadados e documentos), verificada frase a frase contra essas fontes. Em caso de dúvida, consulte o documento original.', 'tainacan-narrativas' )
					: __( 'Narração montada exclusivamente com as informações registradas neste item.', 'tainacan-narrativas' ) )
				: '',
			'browser'        => array(
				'lang'   => (string) Options::get( 'browser_lang', 'pt-BR' ),
				'voice'  => (string) Options::get( 'browser_voice_hint', '' ),
				'gender' => (string) Options::get( 'browser_voice_gender', 'female' ),
				'rate'   => (float) Options::get( 'browser_rate', 1.0 ),
				'pitch'  => (float) Options::get( 'browser_pitch', 1.0 ),
			),
			'version'        => (int) $row['version'],
		);
	}

	// -------------------------------------------------------------------------
	// Write side
	// -------------------------------------------------------------------------

	/**
	 * Full pipeline for an item.
	 *
	 * @param int                 $item_id Item ID.
	 * @param array<string,mixed> $opts    force|audio_only|mode|skip_review|allow_ai_fallback|requested_by.
	 * @return array<string,mixed>|WP_Error Narrative row (public-safe) or error.
	 */
	public function run( int $item_id, array $opts = array() ) {
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'tn_not_found', __( 'Item Tainacan não encontrado.', 'tainacan-narrativas' ) );
		}
		$collection_id = (int) $item->get_collection_id();
		$config        = CollectionSettings::effective( $collection_id );
		if ( ! $config['enabled'] ) {
			return new WP_Error( 'tn_collection_disabled', __( 'Narrativas não estão habilitadas para a coleção deste item.', 'tainacan-narrativas' ) );
		}
		if ( 'none' === $config['sensitivity'] ) {
			return new WP_Error( 'tn_sensitive', __( 'A coleção está marcada como sensível: não gerar narrativa.', 'tainacan-narrativas' ) );
		}
		if ( ! empty( $opts['mode'] ) && Modes::exists( (string) $opts['mode'] ) ) {
			$config['mode'] = (string) $opts['mode'];
		}
		if ( ! Lock::acquire( 'item_' . $item_id, 900 ) ) {
			return new WP_Error( 'tn_locked', __( 'Já existe uma geração em andamento para este item.', 'tainacan-narrativas' ) );
		}

		try {
			/**
			 * Fires before a narrative is generated.
			 *
			 * @param int $item_id Item ID.
			 */
			do_action( 'tainacan_narrativas_before_generate', $item_id );

			$current = $this->repo->get_current( $item_id );

			// Audio-only: reuse the script of the current version.
			if ( ! empty( $opts['audio_only'] ) && $current && '' !== trim( self::final_script( $current ) ) ) {
				$result = $this->synthesize( $current, $config );
				return is_wp_error( $result ) ? $result : $this->public_safe_row( $result );
			}

			$corpus = $this->collector->collect( $item, $config );
			$hash   = SourceHasher::source_hash( $corpus, $this->hash_config( $config ) );

			if ( empty( $opts['force'] ) && $current && $current['source_hash'] === $hash && in_array( $current['status'], array( Repo::STATUS_READY, Repo::STATUS_REVIEW ), true ) ) {
				return $this->public_safe_row( $current ); // Nothing changed.
			}

			$score  = ContentScore::score( $corpus );
			$row_id = $this->repo->create_version(
				$item_id,
				$collection_id,
				array(
					'status'      => Repo::STATUS_SCRIPTING,
					'mode'        => $config['mode'],
					'language'    => $config['language'],
					'source_hash' => $hash,
					'sources'     => $corpus['sources'],
					'stats'       => array(
						'score'  => $score,
						'health' => $corpus['health'],
						'chars'  => $score['chars'],
					),
				)
			);
			if ( $row_id <= 0 ) {
				return new WP_Error( 'tn_db', __( 'Não foi possível gravar a narrativa.', 'tainacan-narrativas' ) );
			}

			if ( ContentScore::INSUFFICIENT === $score['level'] ) {
				$needs_ocr = $this->corpus_needs_ocr( $corpus );
				$this->repo->set_status(
					$row_id,
					$needs_ocr ? Repo::STATUS_REQUIRES_OCR : Repo::STATUS_INSUFFICIENT,
					$needs_ocr
						? __( 'Documento digitalizado sem camada de texto: é necessário OCR para narrar o conteúdo.', 'tainacan-narrativas' )
						: __( 'Conteúdo textual insuficiente para uma narrativa.', 'tainacan-narrativas' )
				);
				$this->prune( $item_id );
				$row = $this->repo->get( $row_id );
				return $row ? $this->public_safe_row( $row ) : new WP_Error( 'tn_db', 'row' );
			}

			// --- Script ---
			$reason   = null;
			$provider = $this->ai->for_collection( $config, $reason );
			$ai_id    = 'template';
			$ai_model = '';
			$stats    = array( 'ai' => $reason );
			$script   = '';

			if ( $provider && 'faithful' !== $config['mode'] ) {
				$gen = $this->generator->generate( $corpus, $config, $provider );
				if ( is_wp_error( $gen ) ) {
					$retryable = in_array( $gen->get_error_code(), self::RETRYABLE, true );
					if ( $retryable && empty( $opts['allow_ai_fallback'] ) ) {
						$this->repo->set_status( $row_id, Repo::STATUS_ERROR, $gen->get_error_message() );
						return $gen; // The queue retries with backoff.
					}
					Logger::warning(
						'AI failed; falling back to template script',
						array(
							'item' => $item_id,
							'code' => $gen->get_error_code(),
						)
					);
					// Unfaithful output is not retried by the coverage sweep: the template is the safe answer.
					$stats[ 'tn_ai_unfaithful' === $gen->get_error_code() ? 'ai_unfaithful' : 'ai_fallback' ] = $gen->get_error_message();
				} else {
					$script   = $gen['script'];
					$ai_id    = $provider->id();
					$ai_model = $gen['model'];
					$stats    = array_merge( $stats, $gen['stats'] );
				}
			}
			if ( '' === $script ) {
				$script = $this->builder->build( $corpus, $config['mode'], (string) Options::get( 'template_script', '' ) );
			}

			/**
			 * Filters the generated script before it is stored.
			 *
			 * @param string $script  Script.
			 * @param int    $item_id Item ID.
			 */
			$script = (string) apply_filters( 'tainacan_narrativas_script', $script, $item_id );
			if ( '' === trim( $script ) ) {
				$this->repo->set_status( $row_id, Repo::STATUS_ERROR, __( 'Roteiro vazio.', 'tainacan-narrativas' ) );
				return new WP_Error( 'tn_empty_script', __( 'Roteiro vazio.', 'tainacan-narrativas' ) );
			}

			$this->repo->update(
				$row_id,
				array(
					'generated_script' => $script,
					'script_hash'      => SourceHasher::script_hash( $script ),
					'ai_provider'      => $ai_id,
					'ai_model'         => $ai_model,
					'generated_at'     => current_time( 'mysql', true ),
					'stats'            => array_merge(
						array(
							'score'  => $score,
							'health' => $corpus['health'],
							'chars'  => $score['chars'],
						),
						$stats
					),
				)
			);

			// --- Editorial gate --- also forced when the previous version carries a
			// human edit: a regenerated script must never replace it silently.
			$had_human_edit = $current && '' !== trim( (string) ( $current['edited_script'] ?? '' ) );
			if ( ( 'review' === $config['editorial_flow'] || $had_human_edit ) && empty( $opts['skip_review'] ) ) {
				$this->repo->set_status( $row_id, Repo::STATUS_REVIEW );
				$this->prune( $item_id );
				$row = $this->repo->get( $row_id );
				$this->after( $item_id, $row );
				return $this->public_safe_row( $row );
			}

			$row    = $this->repo->get( $row_id );
			$result = $this->synthesize( $row, $config );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->prune( $item_id );
			$this->after( $item_id, $result );
			return $this->public_safe_row( $result );
		} finally {
			Lock::release( 'item_' . $item_id );
		}
	}

	/**
	 * Approves the pending script and synthesizes audio.
	 *
	 * @param int $item_id Item ID.
	 * @param int $user_id Reviewer.
	 * @return array<string,mixed>|WP_Error
	 */
	public function approve( int $item_id, int $user_id ) {
		$row = $this->repo->get_current( $item_id );
		if ( ! $row || '' === trim( self::final_script( $row ) ) ) {
			return new WP_Error( 'tn_no_script', __( 'Não há roteiro para aprovar.', 'tainacan-narrativas' ) );
		}
		if ( ! Lock::acquire( 'item_' . $item_id, 900 ) ) {
			return new WP_Error( 'tn_locked', __( 'Já existe uma geração em andamento para este item.', 'tainacan-narrativas' ) );
		}
		try {
			$this->repo->update(
				(int) $row['id'],
				array(
					'approved_by' => $user_id,
					'approved_at' => current_time( 'mysql', true ),
				)
			);
			$config = CollectionSettings::effective( (int) $row['collection_id'] );
			$result = $this->synthesize( $this->repo->get( (int) $row['id'] ), $config );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->after( $item_id, $result );
			return $this->public_safe_row( $result );
		} finally {
			Lock::release( 'item_' . $item_id );
		}
	}

	/**
	 * Stores a human edit. Never overwrites silently: generated_script is kept
	 * and the narrative goes back to review so the audio is re-synthesized on
	 * approval.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $script  Edited script.
	 * @param int    $user_id Editor.
	 * @return array<string,mixed>|WP_Error
	 */
	public function save_script( int $item_id, string $script, int $user_id ) {
		$row = $this->repo->get_current( $item_id );
		if ( ! $row ) {
			return new WP_Error( 'tn_no_script', __( 'Não há narrativa para editar.', 'tainacan-narrativas' ) );
		}
		$script = Normalizer::clean( wp_strip_all_tags( $script ) );
		if ( '' === $script ) {
			return new WP_Error( 'tn_empty_script', __( 'O roteiro não pode ficar vazio.', 'tainacan-narrativas' ) );
		}
		$this->repo->update(
			(int) $row['id'],
			array(
				'edited_script'    => $script,
				'script_edited_by' => $user_id,
				'script_edited_at' => current_time( 'mysql', true ),
				'script_hash'      => SourceHasher::script_hash( $script ),
				'status'           => Repo::STATUS_REVIEW,
			)
		);
		$row = $this->repo->get( (int) $row['id'] );
		return $row ? $this->public_safe_row( $row ) : new WP_Error( 'tn_db', 'row' );
	}

	/**
	 * Deletes the audio of the current version (script kept; back to review).
	 *
	 * @param int $item_id Item ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete_audio( int $item_id ) {
		$row = $this->repo->get_current( $item_id );
		if ( ! $row ) {
			return new WP_Error( 'tn_no_script', __( 'Não há narrativa.', 'tainacan-narrativas' ) );
		}
		if ( $row['audio_attachment_id'] ) {
			AudioStorage::delete( (int) $row['audio_attachment_id'] );
		}
		$this->repo->update(
			(int) $row['id'],
			array(
				'audio_attachment_id' => null,
				'audio_hash'          => null,
				'duration'            => null,
				'status'              => Repo::STATUS_REVIEW,
			)
		);
		$row = $this->repo->get( (int) $row['id'] );
		return $row ? $this->public_safe_row( $row ) : new WP_Error( 'tn_db', 'row' );
	}

	/**
	 * Deletes every version (and audio) of an item.
	 *
	 * @param int $item_id Item ID.
	 * @return int Versions removed.
	 */
	public function delete_all( int $item_id ): int {
		$this->jobs->cancel_for_item( $item_id );
		$n = 0;
		foreach ( $this->repo->versions( $item_id ) as $row ) {
			if ( $row['audio_attachment_id'] ) {
				AudioStorage::delete( (int) $row['audio_attachment_id'] );
			}
			$this->repo->delete( (int) $row['id'] );
			++$n;
		}
		return $n;
	}

	/**
	 * Re-computes the source hash and marks the narrative stale if it changed.
	 *
	 * @param int $item_id Item ID.
	 * @return string|WP_Error New status ('none' when there is no narrative).
	 */
	public function check_stale( int $item_id ) {
		$row = $this->repo->get_current( $item_id );
		if ( ! $row ) {
			return 'none';
		}
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'tn_not_found', __( 'Item Tainacan não encontrado.', 'tainacan-narrativas' ) );
		}
		if ( ! in_array( $row['status'], array( Repo::STATUS_READY, Repo::STATUS_REVIEW, Repo::STATUS_STALE, Repo::STATUS_INSUFFICIENT, Repo::STATUS_REQUIRES_OCR ), true ) ) {
			return (string) $row['status'];
		}
		$config = CollectionSettings::effective( (int) $item->get_collection_id() );
		$corpus = $this->collector->collect( $item, $config );
		$hash   = SourceHasher::source_hash( $corpus, $this->hash_config( $config ) );
		if ( $hash !== $row['source_hash'] ) {
			if ( in_array( $row['status'], array( Repo::STATUS_READY, Repo::STATUS_REVIEW ), true ) ) {
				$this->repo->set_status( (int) $row['id'], Repo::STATUS_STALE );
				Logger::info( 'Narrative marked stale', array( 'item' => $item_id ) );
			}
			return Repo::STATUS_STALE;
		}
		return (string) $row['status'];
	}

	/**
	 * Queues generation for an item (creates/updates the row as queued).
	 *
	 * @param int                 $item_id  Item ID.
	 * @param array<string,mixed> $payload  Job payload (force, mode, skip_review…).
	 * @param int                 $priority Priority.
	 * @return int|WP_Error Job ID.
	 */
	public function enqueue( int $item_id, array $payload = array(), int $priority = 10 ) {
		$item = ItemDetector::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'tn_not_found', __( 'Item Tainacan não encontrado.', 'tainacan-narrativas' ) );
		}
		$collection_id = (int) $item->get_collection_id();
		if ( ! CollectionSettings::is_enabled( $collection_id ) ) {
			return new WP_Error( 'tn_collection_disabled', __( 'Narrativas não estão habilitadas para a coleção deste item.', 'tainacan-narrativas' ) );
		}
		$payload['action'] = $payload['action'] ?? 'generate';
		$job_id            = $this->jobs->enqueue( $item_id, $payload, $priority );

		$row = $this->repo->get_current( $item_id );
		if ( $row && ! in_array( $row['status'], array( Repo::STATUS_READY, Repo::STATUS_REVIEW ), true ) ) {
			$this->repo->set_status( (int) $row['id'], Repo::STATUS_QUEUED );
		} elseif ( ! $row ) {
			$this->repo->create_version( $item_id, $collection_id, array( 'status' => Repo::STATUS_QUEUED ) );
		}
		return $job_id;
	}

	/**
	 * Queues a whole collection.
	 *
	 * @param int  $collection_id Collection ID.
	 * @param bool $only_pending  Skip items whose narrative is ready/review.
	 * @param bool $force         Force regeneration.
	 * @param int  $limit         Max items to queue (0 = all).
	 * @return int Items queued.
	 */
	public function enqueue_collection( int $collection_id, bool $only_pending = true, bool $force = false, int $limit = 0 ): int {
		if ( ! CollectionSettings::is_enabled( $collection_id ) ) {
			return 0;
		}
		$n = 0;
		foreach ( ItemDetector::collection_item_ids( $collection_id ) as $item_id ) {
			if ( $limit > 0 && $n >= $limit ) {
				break;
			}
			if ( $only_pending && ! $force ) {
				$row = $this->repo->get_current( $item_id );
				if ( $row && in_array( $row['status'], array( Repo::STATUS_READY, Repo::STATUS_REVIEW, Repo::STATUS_QUEUED, Repo::STATUS_SCRIPTING, Repo::STATUS_SYNTHESIZING, Repo::STATUS_EXTRACTING ), true ) ) {
					continue;
				}
			}
			$job = $this->enqueue( $item_id, array( 'force' => $force ), 20 );
			if ( ! is_wp_error( $job ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Queues pending items of every enabled collection (bulk button / cron).
	 *
	 * Pending = no narrative, stale, error, or a template fallback produced
	 * while the AI was down (those are upgraded when AI is available).
	 *
	 * @param int $limit Max items to queue in this pass (0 = all).
	 * @return array{queued:int,collections:int}
	 */
	public function enqueue_pending_everywhere( int $limit = 0 ): array {
		$queued = 0;
		$cols   = 0;
		foreach ( CollectionSettings::enabled_ids() as $collection_id ) {
			++$cols;
			$remaining = $limit > 0 ? $limit - $queued : 0;
			if ( $limit > 0 && $remaining <= 0 ) {
				break;
			}
			$queued += $this->enqueue_collection( $collection_id, true, false, $remaining );

			// Upgrade template fallbacks once an AI provider is usable for the collection.
			$config = CollectionSettings::effective( $collection_id );
			$reason = null;
			if ( $this->ai->for_collection( $config, $reason ) && 'faithful' !== $config['mode'] ) {
				$remaining = $limit > 0 ? $limit - $queued : 0;
				if ( $limit > 0 && $remaining <= 0 ) {
					break;
				}
				foreach ( $this->repo->fallback_item_ids( $collection_id, $limit > 0 ? $remaining : 200 ) as $item_id ) {
					$job = $this->enqueue( $item_id, array( 'force' => true ), 30 );
					if ( ! is_wp_error( $job ) ) {
						++$queued;
					}
				}
			}
		}
		return array(
			'queued'      => $queued,
			'collections' => $cols,
		);
	}

	/**
	 * Queues approval (script → audio) for every narrative waiting for review.
	 *
	 * @param int $user_id Approver recorded on each row.
	 * @param int $limit   Max items (0 = all).
	 * @return int Jobs queued.
	 */
	public function approve_all_pending( int $user_id, int $limit = 0 ): int {
		$n = 0;
		foreach ( $this->repo->item_ids_by_status( Repo::STATUS_REVIEW, $limit > 0 ? $limit : 5000 ) as $item_id ) {
			$job = $this->jobs->enqueue(
				$item_id,
				array(
					'action'      => 'approve',
					'approved_by' => $user_id,
				),
				8
			);
			if ( $job > 0 ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Coverage per enabled collection (dashboard).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function coverage(): array {
		$out    = array();
		$by_col = $this->repo->coverage_counts();
		foreach ( CollectionSettings::enabled_ids() as $collection_id ) {
			$collection = ItemDetector::get_collection( $collection_id );
			if ( ! $collection ) {
				continue;
			}
			$items  = count( ItemDetector::collection_item_ids( $collection_id, 5000 ) );
			$counts = $by_col[ $collection_id ] ?? array();
			$ready  = (int) ( $counts[ Repo::STATUS_READY ] ?? 0 );
			$review = (int) ( $counts[ Repo::STATUS_REVIEW ] ?? 0 );
			$stale  = (int) ( $counts[ Repo::STATUS_STALE ] ?? 0 );
			$error  = (int) ( $counts[ Repo::STATUS_ERROR ] ?? 0 );
			$skip   = (int) ( $counts[ Repo::STATUS_INSUFFICIENT ] ?? 0 ) + (int) ( $counts[ Repo::STATUS_REQUIRES_OCR ] ?? 0 );
			$busy   = (int) ( $counts[ Repo::STATUS_QUEUED ] ?? 0 ) + (int) ( $counts[ Repo::STATUS_SCRIPTING ] ?? 0 ) + (int) ( $counts[ Repo::STATUS_SYNTHESIZING ] ?? 0 ) + (int) ( $counts[ Repo::STATUS_EXTRACTING ] ?? 0 );
			$out[]  = array(
				'collection_id' => $collection_id,
				'name'          => (string) $collection->get_name(),
				'items'         => $items,
				'ready'         => $ready,
				'review'        => $review,
				'stale'         => $stale,
				'error'         => $error,
				'skipped'       => $skip,
				'busy'          => $busy,
				'missing'       => max( 0, $items - $ready - $review - $stale - $error - $skip - $busy ),
				'percent'       => $items > 0 ? (int) round( 100 * $ready / $items ) : 0,
			);
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Synthesizes (or marks browser playback) for a row.
	 *
	 * @param array<string,mixed>|null $row    Row.
	 * @param array<string,mixed>      $config Effective config.
	 * @return array<string,mixed>|WP_Error Updated row.
	 */
	private function synthesize( ?array $row, array $config ) {
		if ( ! $row ) {
			return new WP_Error( 'tn_db', 'row' );
		}
		$row_id   = (int) $row['id'];
		$script   = self::final_script( $row );
		$fallback = false;
		$provider = $this->tts->for_collection( $config, $fallback );
		$voice    = (string) ( $config['tts_voice'] ?? '' );
		$speed    = (float) Options::get( 'tts_speed', 1.0 );
		$format   = (string) Options::get( 'tts_format', 'mp3' );
		$s_hash   = SourceHasher::script_hash( $script );

		if ( ! $provider->is_server_side() ) {
			if ( $row['audio_attachment_id'] ) {
				AudioStorage::delete( (int) $row['audio_attachment_id'] );
			}
			$this->repo->update(
				$row_id,
				array(
					'status'              => Repo::STATUS_READY,
					'tts_provider'        => 'browser',
					'tts_voice'           => (string) Options::get( 'browser_voice_hint', '' ),
					'audio_attachment_id' => null,
					'audio_hash'          => null,
					'duration'            => Normalizer::estimate_duration( $script, (float) Options::get( 'browser_rate', 1.0 ) ),
					'script_hash'         => $s_hash,
					'last_error'          => $fallback ? __( 'TTS neural indisponível; usando a voz do navegador.', 'tainacan-narrativas' ) : '',
				)
			);
			return $this->repo->get( $row_id );
		}

		$a_hash = SourceHasher::audio_hash( $s_hash, $provider->id(), $voice, $speed, $format );
		if ( $row['audio_attachment_id'] && $row['audio_hash'] === $a_hash && AudioStorage::url( (int) $row['audio_attachment_id'] ) ) {
			$this->repo->set_status( $row_id, Repo::STATUS_READY );
			return $this->repo->get( $row_id ); // Same script, same voice: reuse audio.
		}

		$this->repo->set_status( $row_id, Repo::STATUS_SYNTHESIZING );
		$audio = $provider->synthesize(
			SpeechText::script_for_speech( $script ),
			$voice,
			array(
				'speed'  => $speed,
				'format' => $format,
			)
		);
		if ( is_wp_error( $audio ) ) {
			$code = 'tn_ai_retryable' === $audio->get_error_code() ? 'tn_tts_retryable' : $audio->get_error_code();
			$this->repo->set_status( $row_id, Repo::STATUS_ERROR, $audio->get_error_message() );
			return new WP_Error( $code, $audio->get_error_message() );
		}

		$item  = ItemDetector::get_item( (int) $row['item_id'] );
		$title = $item ? (string) $item->get_title() : (string) $row['item_id'];
		/* translators: 1: item title, 2: version number. */
		$att_id = AudioStorage::store( (int) $row['item_id'], (int) $row['version'], $audio['audio'], $audio['extension'], $audio['mime'], sprintf( __( 'Narrativa: %1$s (v%2$d)', 'tainacan-narrativas' ), $title, (int) $row['version'] ) );
		if ( is_wp_error( $att_id ) ) {
			$this->repo->set_status( $row_id, Repo::STATUS_ERROR, $att_id->get_error_message() );
			return $att_id;
		}
		if ( $row['audio_attachment_id'] && (int) $row['audio_attachment_id'] !== (int) $att_id ) {
			AudioStorage::delete( (int) $row['audio_attachment_id'] );
		}
		$this->repo->update(
			$row_id,
			array(
				'status'              => Repo::STATUS_READY,
				'tts_provider'        => $provider->id(),
				'tts_voice'           => $audio['voice'],
				'audio_attachment_id' => (int) $att_id,
				'audio_hash'          => $a_hash,
				'script_hash'         => $s_hash,
				'duration'            => AudioStorage::duration( (int) $att_id, $script ),
				'last_error'          => '',
			)
		);
		return $this->repo->get( $row_id );
	}

	/**
	 * Removes versions beyond the retention setting.
	 *
	 * @param int $item_id Item ID.
	 * @return void
	 */
	private function prune( int $item_id ): void {
		$keep = (int) Options::get( 'keep_versions', 3 );
		foreach ( $this->repo->prunable_versions( $item_id, $keep ) as $old ) {
			if ( $old['audio_attachment_id'] ) {
				AudioStorage::delete( (int) $old['audio_attachment_id'] );
			}
			$this->repo->delete( (int) $old['id'] );
		}
	}

	/**
	 * Fires the after-generate action.
	 *
	 * @param int                      $item_id Item ID.
	 * @param array<string,mixed>|null $row     Row.
	 * @return void
	 */
	private function after( int $item_id, ?array $row ): void {
		if ( ! $row ) {
			return;
		}
		/**
		 * Fires after a narrative version was generated (script and/or audio).
		 *
		 * @param int                 $item_id   Item ID.
		 * @param array<string,mixed> $narrative Narrative row.
		 */
		do_action( 'tainacan_narrativas_after_generate', $item_id, $row );
	}

	/**
	 * Configuration fields that participate in the source hash.
	 *
	 * @param array<string,mixed> $config Effective config.
	 * @return array<string,mixed>
	 */
	private function hash_config( array $config ): array {
		return array(
			'mode'           => $config['mode'],
			'ai'             => $config['ai'],
			'template'       => (string) Options::get( 'template_script', '' ),
			'max_chars_item' => (int) $config['max_chars_item'],
		);
	}

	/**
	 * Whether the only potential content is a scanned document.
	 *
	 * @param array<string,mixed> $corpus Corpus.
	 * @return bool
	 */
	private function corpus_needs_ocr( array $corpus ): bool {
		if ( ExtractionResult::REQUIRES_OCR === (string) ( $corpus['document']['status'] ?? '' ) ) {
			return true;
		}
		foreach ( (array) $corpus['attachments'] as $att ) {
			if ( ExtractionResult::REQUIRES_OCR === (string) ( $att['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Row without anything the admin UI does not need raw (kept small).
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public function public_safe_row( array $row ): array {
		$row['audio_url']    = $row['audio_attachment_id'] ? AudioStorage::url( (int) $row['audio_attachment_id'] ) : '';
		$row['final_script'] = self::final_script( $row );
		$row['status_label'] = Repo::status_labels()[ $row['status'] ] ?? $row['status'];
		$row['mode_label']   = Modes::labels()[ $row['mode'] ] ?? $row['mode'];
		return $row;
	}
}
