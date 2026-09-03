<?php
/**
 * Content fingerprints.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SHA-256 over a canonical representation of everything that influences a
 * narrative. Same inputs ⇒ same hash ⇒ nothing to regenerate.
 *
 *  - source_hash: title + description + selected metadata + document/attachment
 *    texts (or their file signatures when text is unavailable) + narrative
 *    configuration (mode, prompt version, template, language, limits).
 *  - script_hash: the exact script sent to TTS.
 *  - audio_hash:  script_hash + TTS provider + voice + speed + format, so
 *    changing only the voice regenerates audio but reuses the script.
 */
final class SourceHasher {

	/**
	 * Bump when prompts/templates change in a way that should invalidate scripts.
	 */
	public const PROMPT_VERSION = '1';

	/**
	 * Source hash.
	 *
	 * @param array<string,mixed> $corpus Output of ContentCollector::collect().
	 * @param array<string,mixed> $config Narrative configuration (mode, language, template, ai...).
	 * @return string
	 */
	public static function source_hash( array $corpus, array $config ): string {
		$canonical = array(
			'v'        => self::PROMPT_VERSION,
			'title'    => (string) ( $corpus['title'] ?? '' ),
			'desc'     => (string) ( $corpus['description'] ?? '' ),
			'meta'     => array_map(
				static fn( array $m ) => array( (int) $m['id'], (string) $m['label'], (string) $m['value'] ),
				array_values( (array) ( $corpus['metadata'] ?? array() ) )
			),
			'document' => self::doc_key( (array) ( $corpus['document'] ?? array() ) ),
			'attach'   => array_map( array( self::class, 'doc_key' ), array_values( (array) ( $corpus['attachments'] ?? array() ) ) ),
			'lang'     => (string) ( $corpus['language'] ?? '' ),
			'config'   => array(
				'mode'      => (string) ( $config['mode'] ?? '' ),
				'ai'        => (string) ( $config['ai'] ?? '' ),
				'template'  => md5( (string) ( $config['template'] ?? '' ) ),
				'max_chars' => (int) ( $config['max_chars_item'] ?? 0 ),
			),
		);
		return hash( 'sha256', (string) wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Script hash.
	 *
	 * @param string $script Script text.
	 * @return string
	 */
	public static function script_hash( string $script ): string {
		return hash( 'sha256', trim( $script ) );
	}

	/**
	 * Audio hash.
	 *
	 * @param string $script_hash Script hash.
	 * @param string $provider    TTS provider id.
	 * @param string $voice       Voice id.
	 * @param float  $speed       Speed.
	 * @param string $format      Audio format.
	 * @return string
	 */
	public static function audio_hash( string $script_hash, string $provider, string $voice, float $speed, string $format ): string {
		return hash( 'sha256', implode( '|', array( $script_hash, $provider, $voice, (string) $speed, $format ) ) );
	}

	/**
	 * Canonical key of a document entry: text hash when available, else file signature.
	 *
	 * @param array<string,mixed> $doc Document entry.
	 * @return array<int,mixed>
	 */
	private static function doc_key( array $doc ): array {
		if ( empty( $doc ) ) {
			return array();
		}
		$text = (string) ( $doc['text'] ?? '' );
		return array(
			(int) ( $doc['id'] ?? 0 ),
			(string) ( $doc['status'] ?? '' ),
			'' !== $text ? hash( 'sha256', $text ) : (string) ( $doc['signature'] ?? '' ),
		);
	}
}
