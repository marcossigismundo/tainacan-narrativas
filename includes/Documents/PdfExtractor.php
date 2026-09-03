<?php
/**
 * PDF text extractor (smalot/pdfparser) with scanned-PDF detection.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Documents;

use TainacanNarrativas\Narrative\Normalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses the same library Tainacan core uses for `document_content_index`
 * (bundled in vendor/ so the plugin does not depend on core's vendor dir).
 *
 * A PDF with pages but (almost) no letters is reported as `requires_ocr`
 * instead of silently empty; garbled output (fonts without ToUnicode maps)
 * is also treated as needing OCR.
 */
final class PdfExtractor implements ExtractorInterface {

	/**
	 * Files above this size are skipped (memory safety on shared hosts).
	 */
	public const MAX_BYTES = 60 * 1024 * 1024;

	/**
	 * Fewer letters per page than this ⇒ probably scanned.
	 */
	private const MIN_LETTERS_PER_PAGE = 40;

	/**
	 * Below this letter ratio the text is considered garbled.
	 */
	private const MIN_LETTER_RATIO = 0.35;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'pdfparser';
	}

	/**
	 * Whether this extractor handles the file.
	 *
	 * @param string $mime     Mime type.
	 * @param string $filename File name.
	 * @return bool
	 */
	public function supports( string $mime, string $filename ): bool {
		return 'application/pdf' === strtolower( $mime ) || 'pdf' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Whether the parser library is loadable.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( '\Smalot\PdfParser\Parser' );
	}

	/**
	 * Extracts the text layer, flagging scanned/garbled PDFs as requires_ocr.
	 *
	 * @param string $path      Absolute path inside uploads.
	 * @param string $mime      Mime type.
	 * @param int    $max_chars Character cap.
	 * @return ExtractionResult
	 */
	public function extract( string $path, string $mime, int $max_chars ): ExtractionResult {
		if ( ! self::is_available() ) {
			return ExtractionResult::fail( ExtractionResult::UNSUPPORTED, $this->id(), __( 'Biblioteca de PDF indisponível.', 'tainacan-narrativas' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return ExtractionResult::fail( ExtractionResult::TOO_LARGE, $this->id(), __( 'PDF grande demais para extração em PHP.', 'tainacan-narrativas' ) );
		}

		$pages = 0;
		try {
			$config = new \Smalot\PdfParser\Config();
			$config->setRetainImageContent( false );
			$config->setIgnoreEncryption( true );
			$parser   = new \Smalot\PdfParser\Parser( array(), $config );
			$document = $parser->parseFile( $path );
			$pages    = count( $document->getPages() );
			$text     = (string) $document->getText();
		} catch ( \Throwable $e ) {
			return ExtractionResult::fail( ExtractionResult::ERROR, $this->id(), __( 'Falha ao ler o PDF.', 'tainacan-narrativas' ), $pages );
		}

		$text = Normalizer::unwrap_lines( Normalizer::clean( Normalizer::to_utf8( $text ) ) );

		$letters  = preg_match_all( '/\p{L}/u', $text );
		$letters  = is_int( $letters ) ? $letters : 0;
		$non_ws   = mb_strlen( preg_replace( '/\s+/u', '', $text ) ?? '' );
		$per_page = $pages > 0 ? $letters / $pages : $letters;

		if ( $pages > 0 && $per_page < self::MIN_LETTERS_PER_PAGE ) {
			return ExtractionResult::fail( ExtractionResult::REQUIRES_OCR, $this->id(), __( 'PDF provavelmente digitalizado: camada de texto ausente.', 'tainacan-narrativas' ), $pages );
		}
		if ( $non_ws > 0 && ( $letters / $non_ws ) < self::MIN_LETTER_RATIO ) {
			return ExtractionResult::fail( ExtractionResult::REQUIRES_OCR, $this->id(), __( 'Texto do PDF ilegível (fontes sem mapeamento); recomenda-se OCR.', 'tainacan-narrativas' ), $pages );
		}
		if ( '' === trim( $text ) ) {
			return ExtractionResult::fail( ExtractionResult::EMPTY, $this->id(), __( 'PDF sem texto.', 'tainacan-narrativas' ), $pages );
		}

		return ExtractionResult::ok( Normalizer::truncate( $text, $max_chars ), $this->id(), $pages );
	}
}
