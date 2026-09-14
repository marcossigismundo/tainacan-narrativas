<?php
/**
 * Extraction orchestration + cache.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Documents;

use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Narrative\Normalizer;
use TainacanNarrativas\Security\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolution order for an attachment's text:
 *
 *  1. `_tainacan_ocr_text` (written by the tainacan-ocr-search plugin) — best
 *     text for scanned documents, no re-processing.
 *  2. Tainacan core `document_content_index` on the item — only when the
 *     attachment IS the item's main document and core indexing produced text.
 *  3. Bundled extractors by mime type (PDF, DOCX, ODT, text/HTML/XML/CSV).
 *  4. Registered OCR providers when the result is `requires_ocr`.
 *
 * Results are cached on the attachment (post meta `_tn_text_cache` + `_tn_text`)
 * keyed by a file signature (size + mtime + limits + extractor version), so an
 * unchanged file is never parsed twice.
 */
final class ExtractorManager {

	public const META_CACHE = '_tn_text_cache';
	public const META_TEXT  = '_tn_text';

	/**
	 * Bump when extractor behaviour changes to invalidate caches.
	 */
	public const EXTRACTOR_VERSION = '2';

	/**
	 * Registered extractors.
	 *
	 * @var ExtractorInterface[]
	 */
	private array $extractors;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$defaults = array(
			new PdfExtractor(),
			new DocxExtractor(),
			new OdtExtractor(),
			new TextExtractor(),
		);
		/**
		 * Filters the list of document extractors.
		 *
		 * @param ExtractorInterface[] $extractors Extractors, evaluated in order.
		 */
		$list             = apply_filters( 'tainacan_narrativas_extractors', $defaults );
		$this->extractors = array_values( array_filter( (array) $list, static fn( $e ) => $e instanceof ExtractorInterface ) );
	}

	/**
	 * Extractor ids (Diagnostics).
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_map( static fn( ExtractorInterface $e ) => $e->id(), $this->extractors );
	}

	/**
	 * Whether the file name/mime denotes a web archive (handled by tainacan-wacz-player).
	 *
	 * @param string $mime     Mime.
	 * @param string $filename File name.
	 * @return bool
	 */
	public static function is_web_archive( string $mime, string $filename ): bool {
		$lower = strtolower( $filename );
		return str_ends_with( $lower, '.wacz' ) || str_ends_with( $lower, '.warc' ) || str_ends_with( $lower, '.warc.gz' )
			|| in_array( strtolower( $mime ), array( 'application/wacz', 'application/warc' ), true );
	}

	/**
	 * Extracts (or serves from cache) the text of an attachment.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param int      $max_chars     Char cap.
	 * @param int|null $item_id       Owning item (enables the core index source).
	 * @param bool     $force         Ignore cache.
	 * @return ExtractionResult
	 */
	public function extract_attachment( int $attachment_id, int $max_chars, ?int $item_id = null, bool $force = false ): ExtractionResult {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return ExtractionResult::fail( ExtractionResult::ERROR, '', __( 'Anexo não encontrado.', 'tainacan-narrativas' ) );
		}
		$mime     = (string) $post->post_mime_type;
		$filename = basename( (string) get_attached_file( $attachment_id ) );

		if ( self::is_web_archive( $mime, $filename ) ) {
			return ExtractionResult::fail( ExtractionResult::WEB_ARCHIVE, 'wacz', __( 'Arquivo web archive (reproduzido pelo WACZ Player); ignorado na narrativa.', 'tainacan-narrativas' ) );
		}
		if ( wp_attachment_is_image( $attachment_id ) || str_starts_with( $mime, 'audio/' ) || str_starts_with( $mime, 'video/' ) ) {
			// Images may still carry OCR text from tainacan-ocr-search.
			$ocr = $this->from_ocr_search( $attachment_id, $max_chars );
			return $ocr ?? ExtractionResult::fail( ExtractionResult::UNSUPPORTED, '', __( 'Mídia sem camada textual.', 'tainacan-narrativas' ) );
		}

		// 1) OCR plugin output (never cached by us: it is already a cache).
		$ocr = $this->from_ocr_search( $attachment_id, $max_chars );
		if ( $ocr ) {
			return $ocr;
		}

		$path = (string) get_attached_file( $attachment_id );
		if ( '' === $path || ! file_exists( $path ) || ! Security::path_is_in_uploads( $path ) ) {
			return ExtractionResult::fail( ExtractionResult::ERROR, '', __( 'Arquivo do anexo indisponível no servidor.', 'tainacan-narrativas' ) );
		}

		$signature = $this->signature( $path, $max_chars );
		if ( ! $force ) {
			$cached = get_post_meta( $attachment_id, self::META_CACHE, true );
			if ( is_array( $cached ) && isset( $cached['sig'] ) && $cached['sig'] === $signature ) {
				$text = (string) get_post_meta( $attachment_id, self::META_TEXT, true );
				return ExtractionResult::from_array( $cached, $text );
			}
		}

		// 2) Core index for the main document.
		$result = null;
		if ( $item_id && 'application/pdf' === $mime ) {
			$result = $this->from_core_index( $item_id, $attachment_id, $max_chars );
		}

		// 3) Bundled extractors.
		if ( null === $result ) {
			$result = $this->run_extractors( $path, $mime, $filename, $max_chars );
		}

		// 4) OCR providers.
		if ( ExtractionResult::REQUIRES_OCR === $result->status ) {
			$result = $this->try_ocr_providers( $path, $mime, $result );
		}

		/**
		 * Filters the extraction result of an attachment.
		 *
		 * @param ExtractionResult $result        Result.
		 * @param int              $attachment_id Attachment ID.
		 * @param string           $mime          Mime type.
		 */
		$result = apply_filters( 'tainacan_narrativas_extraction_result', $result, $attachment_id, $mime );

		$meta        = $result->to_array();
		$meta['sig'] = $signature;
		$meta['ts']  = time();
		update_post_meta( $attachment_id, self::META_CACHE, $meta );
		update_post_meta( $attachment_id, self::META_TEXT, $result->text );

		return $result;
	}

	/**
	 * Forgets the cache of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function forget( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::META_CACHE );
		delete_post_meta( $attachment_id, self::META_TEXT );
	}

	/**
	 * Cache signature.
	 *
	 * @param string $path      File path.
	 * @param int    $max_chars Char cap.
	 * @return string
	 */
	private function signature( string $path, int $max_chars ): string {
		$size  = filesize( $path );
		$mtime = filemtime( $path );
		return md5( implode( '|', array( self::EXTRACTOR_VERSION, (string) $size, (string) $mtime, (string) $max_chars, basename( $path ) ) ) );
	}

	/**
	 * Text produced by the tainacan-ocr-search plugin, if any.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $max_chars     Char cap.
	 * @return ExtractionResult|null
	 */
	private function from_ocr_search( int $attachment_id, int $max_chars ): ?ExtractionResult {
		$text = get_post_meta( $attachment_id, '_tainacan_ocr_text', true );
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return null;
		}
		$text = Normalizer::clean( Normalizer::to_utf8( $text ) );
		if ( mb_strlen( $text ) < 20 ) {
			return null;
		}
		return ExtractionResult::ok( Normalizer::truncate( $text, $max_chars ), 'ocr_search' );
	}

	/**
	 * Tainacan core `document_content_index` for the item's main document.
	 *
	 * @param int $item_id       Item ID.
	 * @param int $attachment_id Attachment ID (must be the item's document).
	 * @param int $max_chars     Char cap.
	 * @return ExtractionResult|null
	 */
	private function from_core_index( int $item_id, int $attachment_id, int $max_chars ): ?ExtractionResult {
		$document = get_post_meta( $item_id, 'document', true );
		if ( (int) $document !== $attachment_id ) {
			return null;
		}
		$meta_key = class_exists( '\Tainacan\Media' ) && isset( \Tainacan\Media::$content_index_meta ) ? \Tainacan\Media::$content_index_meta : 'document_content_index';
		$text     = get_post_meta( $item_id, $meta_key, true );
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return null;
		}
		$text = Normalizer::clean( Normalizer::to_utf8( $text ) );
		// Apply the same scanned/garbled heuristic used by PdfExtractor.
		$letters = preg_match_all( '/\p{L}/u', $text );
		$non_ws  = mb_strlen( preg_replace( '/\s+/u', '', $text ) ?? '' );
		if ( ! is_int( $letters ) || $letters < 40 || ( $non_ws > 0 && $letters / $non_ws < 0.35 ) ) {
			return null; // Let the extractor decide (and report requires_ocr).
		}
		return ExtractionResult::ok( Normalizer::truncate( $text, $max_chars ), 'core_index' );
	}

	/**
	 * Runs the first extractor that supports the file.
	 *
	 * @param string $path      Path.
	 * @param string $mime      Mime.
	 * @param string $filename  File name.
	 * @param int    $max_chars Char cap.
	 * @return ExtractionResult
	 */
	private function run_extractors( string $path, string $mime, string $filename, int $max_chars ): ExtractionResult {
		foreach ( $this->extractors as $extractor ) {
			if ( ! $extractor->supports( $mime, $filename ) ) {
				continue;
			}
			try {
				return $extractor->extract( $path, $mime, $max_chars );
			} catch ( \Throwable $e ) {
				Logger::warning(
					'Extractor failure',
					array(
						'extractor' => $extractor->id(),
						'error'     => $e->getMessage(),
					)
				);
				return ExtractionResult::fail( ExtractionResult::ERROR, $extractor->id(), __( 'Falha na extração de texto.', 'tainacan-narrativas' ) );
			}
		}
		return ExtractionResult::fail( ExtractionResult::UNSUPPORTED, '', __( 'Formato de arquivo sem extrator de texto.', 'tainacan-narrativas' ) );
	}

	/**
	 * Offers the file to registered OCR providers.
	 *
	 * @param string           $path     Path.
	 * @param string           $mime     Mime.
	 * @param ExtractionResult $previous Result to return when no provider succeeds.
	 * @return ExtractionResult
	 */
	private function try_ocr_providers( string $path, string $mime, ExtractionResult $previous ): ExtractionResult {
		/**
		 * Filters the OCR providers available to the plugin. None are bundled.
		 *
		 * @param OcrProviderInterface[] $providers Providers.
		 */
		$providers = apply_filters( 'tainacan_narrativas_ocr_providers', array() );
		foreach ( (array) $providers as $provider ) {
			if ( ! $provider instanceof OcrProviderInterface || ! $provider->is_available() ) {
				continue;
			}
			try {
				$result = $provider->recognize( $path, $mime, 'por' );
			} catch ( \Throwable $e ) {
				Logger::warning(
					'OCR provider failure',
					array(
						'provider' => $provider->id(),
						'error'    => $e->getMessage(),
					)
				);
				continue;
			}
			if ( $result->is_ok() ) {
				$result->pages = $previous->pages;
				return $result;
			}
		}
		return $previous;
	}
}
