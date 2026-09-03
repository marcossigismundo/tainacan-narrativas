<?php
/**
 * Extractor contract.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One adapter per file family. Register more through the
 * `tainacan_narrativas_extractors` filter (see AGENTS.md).
 */
interface ExtractorInterface {

	/**
	 * Unique id (also used as ExtractionResult::$method).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether this extractor handles the given mime type / file name.
	 *
	 * @param string $mime     Mime type.
	 * @param string $filename File name (for extension checks).
	 * @return bool
	 */
	public function supports( string $mime, string $filename ): bool;

	/**
	 * Extracts plain text from a local file.
	 *
	 * @param string $path      Absolute path inside uploads.
	 * @param string $mime      Mime type.
	 * @param int    $max_chars Hard cap on returned characters.
	 * @return ExtractionResult
	 */
	public function extract( string $path, string $mime, int $max_chars ): ExtractionResult;
}
