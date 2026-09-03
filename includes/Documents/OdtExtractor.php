<?php
/**
 * ODT extractor (OpenDocument) via ZipArchive.
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
 * Reads content.xml directly.
 */
final class OdtExtractor implements ExtractorInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'odt';
	}

	/**
	 * Whether this extractor handles the file.
	 *
	 * @param string $mime     Mime type.
	 * @param string $filename File name.
	 * @return bool
	 */
	public function supports( string $mime, string $filename ): bool {
		return 'application/vnd.oasis.opendocument.text' === strtolower( $mime )
			|| 'odt' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Extracts plain text from content.xml.
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
			return ExtractionResult::fail( ExtractionResult::ERROR, $this->id(), __( 'Arquivo ODT inválido.', 'tainacan-narrativas' ) );
		}
		$xml = $zip->getFromName( 'content.xml' );
		$zip->close();
		if ( false === $xml || '' === $xml ) {
			return ExtractionResult::fail( ExtractionResult::EMPTY, $this->id(), __( 'ODT sem conteúdo principal.', 'tainacan-narrativas' ) );
		}

		$xml  = preg_replace( '/<text:tab\b[^>]*\/>/', "\t", $xml ) ?? $xml;
		$xml  = preg_replace( '/<text:s\b[^>]*\/>/', ' ', $xml ) ?? $xml;
		$xml  = preg_replace( '/<text:line-break\b[^>]*\/>/', "\n", $xml ) ?? $xml;
		$xml  = preg_replace( '/<\/text:(p|h|list-item)>/', "\n\n", $xml ) ?? $xml;
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = Normalizer::clean( $text );

		if ( '' === $text ) {
			return ExtractionResult::fail( ExtractionResult::EMPTY, $this->id(), __( 'ODT sem texto.', 'tainacan-narrativas' ) );
		}
		return ExtractionResult::ok( Normalizer::truncate( $text, $max_chars ), $this->id() );
	}
}
