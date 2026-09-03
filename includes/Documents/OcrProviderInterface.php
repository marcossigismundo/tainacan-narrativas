<?php
/**
 * OCR provider contract (optional, none bundled).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When a PDF has no text layer the extraction status becomes `requires_ocr`
 * and the manager offers the file to registered OCR providers (filter
 * `tainacan_narrativas_ocr_providers`). A provider may call Tesseract, an
 * institutional API, or reuse the output of the tainacan-ocr-search plugin.
 */
interface OcrProviderInterface {

	/**
	 * Unique id.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether the provider is ready to run.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Runs OCR on a local file and returns plain text (or a failing result).
	 *
	 * @param string $path Absolute path inside uploads.
	 * @param string $mime Mime type.
	 * @param string $lang Language hint (e.g. "por").
	 * @return ExtractionResult
	 */
	public function recognize( string $path, string $mime, string $lang ): ExtractionResult;
}
