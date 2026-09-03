<?php
/**
 * DOCX extractor (Office Open XML) via ZipArchive.
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
 * Reads word/document.xml directly — no PHPWord dependency needed for text.
 */
final class DocxExtractor implements ExtractorInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'docx';
	}

	/**
	 * Whether this extractor handles the file.
	 *
	 * @param string $mime     Mime type.
	 * @param string $filename File name.
	 * @return bool
	 */
	public function supports( string $mime, string $filename ): bool {
		return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === strtolower( $mime )
			|| 'docx' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Extracts plain text from word/document.xml.
	 *
	 * @param string $path      Absolute path inside uploads.
	 * @param string $mime      Mime type.
	 * @param int    $max_chars Character cap.
	 * @return ExtractionResult
	 */
	public function extract( string $path, string $mime, int $max_chars ): ExtractionResult {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return ExtractionResult::fail( ExtractionResult::UNSUPPORTED, $this->id(), __( 'Extensão PHP zip indisponível.', 'tainacan-narrativas' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return ExtractionResult::fail( ExtractionResult::ERROR, $this->id(), __( 'Arquivo DOCX inválido.', 'tainacan-narrativas' ) );
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( false === $xml || '' === $xml ) {
			return ExtractionResult::fail( ExtractionResult::EMPTY, $this->id(), __( 'DOCX sem conteúdo principal.', 'tainacan-narrativas' ) );
		}

		$xml  = preg_replace( '/<w:(tab)\b[^>]*\/>/', "\t", $xml ) ?? $xml;
		$xml  = preg_replace( '/<w:(br|cr)\b[^>]*\/>/', "\n", $xml ) ?? $xml;
		$xml  = preg_replace( '/<\/w:p>/', "\n\n", $xml ) ?? $xml;
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = Normalizer::clean( $text );

		if ( '' === $text ) {
			return ExtractionResult::fail( ExtractionResult::EMPTY, $this->id(), __( 'DOCX sem texto.', 'tainacan-narrativas' ) );
		}
		return ExtractionResult::ok( Normalizer::truncate( $text, $max_chars ), $this->id() );
	}
}
