<?php
/**
 * Result of a text extraction.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable-ish value object shared by every extractor.
 */
final class ExtractionResult {

	public const OK           = 'ok';
	public const EMPTY        = 'empty';
	public const REQUIRES_OCR = 'requires_ocr';
	public const UNSUPPORTED  = 'unsupported';
	public const WEB_ARCHIVE  = 'web_archive';
	public const TOO_LARGE    = 'too_large';
	public const ERROR        = 'error';

	/**
	 * Status constant.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Extracted plain text.
	 *
	 * @var string
	 */
	public string $text;

	/**
	 * Extraction method / source id (pdfparser, docx, core_index, ocr_search...).
	 *
	 * @var string
	 */
	public string $method;

	/**
	 * Page count when known.
	 *
	 * @var int
	 */
	public int $pages;

	/**
	 * Human readable note (never contains paths).
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * Constructor.
	 *
	 * @param string $status  Status.
	 * @param string $text    Text.
	 * @param string $method  Method.
	 * @param int    $pages   Pages.
	 * @param string $message Message.
	 */
	public function __construct( string $status, string $text = '', string $method = '', int $pages = 0, string $message = '' ) {
		$this->status  = $status;
		$this->text    = $text;
		$this->method  = $method;
		$this->pages   = $pages;
		$this->message = $message;
	}

	/**
	 * Shortcut for a successful result.
	 *
	 * @param string $text   Text.
	 * @param string $method Method.
	 * @param int    $pages  Pages.
	 * @return self
	 */
	public static function ok( string $text, string $method, int $pages = 0 ): self {
		return new self( self::OK, $text, $method, $pages );
	}

	/**
	 * Shortcut for a failure.
	 *
	 * @param string $status  Status.
	 * @param string $method  Method.
	 * @param string $message Message.
	 * @param int    $pages   Pages.
	 * @return self
	 */
	public static function fail( string $status, string $method = '', string $message = '', int $pages = 0 ): self {
		return new self( $status, '', $method, $pages, $message );
	}

	/**
	 * Whether usable text was produced.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return self::OK === $this->status && '' !== trim( $this->text );
	}

	/**
	 * Character count.
	 *
	 * @return int
	 */
	public function chars(): int {
		return mb_strlen( $this->text );
	}

	/**
	 * Array form (for caching / REST).
	 *
	 * @param bool $with_text Include the text.
	 * @return array<string,mixed>
	 */
	public function to_array( bool $with_text = false ): array {
		$out = array(
			'status'  => $this->status,
			'method'  => $this->method,
			'pages'   => $this->pages,
			'chars'   => $this->chars(),
			'message' => $this->message,
		);
		if ( $with_text ) {
			$out['text'] = $this->text;
		}
		return $out;
	}

	/**
	 * Rebuilds from the cached array form.
	 *
	 * @param array<string,mixed> $data Cached meta.
	 * @param string              $text Cached text.
	 * @return self
	 */
	public static function from_array( array $data, string $text = '' ): self {
		return new self(
			(string) ( $data['status'] ?? self::ERROR ),
			$text,
			(string) ( $data['method'] ?? '' ),
			(int) ( $data['pages'] ?? 0 ),
			(string) ( $data['message'] ?? '' )
		);
	}
}
