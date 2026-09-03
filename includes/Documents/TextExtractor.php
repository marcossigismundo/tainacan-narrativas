<?php
/**
 * Plain text / HTML / XML / CSV extractor.
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
 * Handles text-like files without external dependencies.
 */
final class TextExtractor implements ExtractorInterface {

	/**
	 * Mime types handled.
	 *
	 * @var string[]
	 */
	private const MIMES = array(
		'text/plain',
		'text/markdown',
		'text/csv',
		'text/tab-separated-values',
		'text/html',
		'application/xhtml+xml',
		'text/xml',
		'application/xml',
		'application/rss+xml',
		'application/json',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'text';
	}

	/**
	 * Whether this extractor handles the file.
	 *
	 * @param string $mime     Mime type.
	 * @param string $filename File name.
	 * @return bool
	 */
	public function supports( string $mime, string $filename ): bool {
		if ( in_array( strtolower( $mime ), self::MIMES, true ) ) {
			return true;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'txt', 'md', 'csv', 'tsv', 'html', 'htm', 'xml', 'json' ), true );
	}

	/**
	 * Extracts plain text.
	 *
	 * @param string $path      Absolute path inside uploads.
	 * @param string $mime      Mime type.
	 * @param int    $max_chars Character cap.
	 * @return ExtractionResult
	 */
	public function extract( string $path, string $mime, int $max_chars ): ExtractionResult {
		// Read at most ~4x the char cap (multibyte) to avoid loading huge logs.
		$raw = file_get_contents( $path, false, null, 0, max( 4096, $max_chars * 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside uploads (validated by the manager), not a URL.
		if ( false === $raw ) {
			return ExtractionResult::fail( ExtractionResult::ERROR, $this->id(), __( 'Não foi possível ler o arquivo.', 'tainacan-narrativas' ) );
		}
		$raw = Normalizer::to_utf8( $raw );
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $mime, array( 'text/html', 'application/xhtml+xml' ), true ) || in_array( $ext, array( 'html', 'htm' ), true ) ) {
			$text = Normalizer::html_to_text( $raw );
		} elseif ( in_array( $mime, array( 'text/csv', 'text/tab-separated-values' ), true ) || in_array( $ext, array( 'csv', 'tsv' ), true ) ) {
			$text = $this->csv_to_text( $raw, 'tsv' === $ext ? "\t" : ',' );
		} elseif ( str_contains( $mime, 'xml' ) || 'xml' === $ext ) {
			$text = Normalizer::clean( preg_replace( '/<[^>]+>/', ' ', $raw ) ?? '' );
		} elseif ( 'application/json' === $mime || 'json' === $ext ) {
			$decoded = json_decode( $raw, true );
			$text    = is_array( $decoded ) ? $this->json_to_text( $decoded ) : Normalizer::clean( $raw );
		} else {
			$text = Normalizer::clean( $raw );
		}

		$text = Normalizer::truncate( $text, $max_chars );
		if ( '' === trim( $text ) ) {
			return ExtractionResult::fail( ExtractionResult::EMPTY, $this->id(), __( 'Arquivo sem texto.', 'tainacan-narrativas' ) );
		}
		return ExtractionResult::ok( $text, $this->id() );
	}

	/**
	 * Rows → lines, cells joined by "; " (headers repeated per row would be noisy for audio).
	 *
	 * @param string $raw       CSV content.
	 * @param string $delimiter Delimiter.
	 * @return string
	 */
	private function csv_to_text( string $raw, string $delimiter ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = false === $lines ? array() : $lines;
		$out   = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, $delimiter, '"', '\\' );
			$cells = array_filter( array_map( 'trim', $cells ), static fn( $c ) => '' !== $c );
			if ( $cells ) {
				$out[] = implode( '; ', $cells );
			}
		}
		return Normalizer::clean( implode( "\n", $out ) );
	}

	/**
	 * Flattens JSON to "key: value" lines.
	 *
	 * @param array<mixed> $data   Decoded JSON.
	 * @param string       $prefix Key prefix.
	 * @param int          $depth  Recursion guard.
	 * @return string
	 */
	private function json_to_text( array $data, string $prefix = '', int $depth = 0 ): string {
		if ( $depth > 6 ) {
			return '';
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$label = '' === $prefix ? (string) $key : $prefix . ' ' . $key;
			if ( is_array( $value ) ) {
				$out[] = $this->json_to_text( $value, $label, $depth + 1 );
			} elseif ( is_scalar( $value ) ) {
				$out[] = $label . ': ' . (string) $value;
			}
		}
		return Normalizer::clean( implode( "\n", array_filter( $out ) ) );
	}
}
