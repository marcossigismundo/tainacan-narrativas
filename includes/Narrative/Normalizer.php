<?php
/**
 * Text normalization utilities.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions (no WordPress state) so they are unit-testable.
 */
final class Normalizer {

	/**
	 * Converts arbitrary bytes to UTF-8.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function to_utf8( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		// Strip UTF-8 BOM.
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		if ( mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}
		$encoding  = mb_detect_encoding( $text, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15' ), true );
		$converted = mb_convert_encoding( $text, 'UTF-8', $encoding ? $encoding : 'Windows-1252' );
		return is_string( $converted ) ? $converted : '';
	}

	/**
	 * Cleans text for narration: removes tags/controls, normalizes whitespace,
	 * keeps paragraph breaks, drops consecutive duplicate paragraphs.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function clean( string $text ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		// Remove control characters except tab/newline.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text ) ?? $text;
		// Soft hyphen, zero-width chars, BOM.
		$text = preg_replace( '/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $text ) ?? $text;
		// Non-breaking spaces to spaces.
		$text = preg_replace( '/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $text ) ?? $text;
		// De-hyphenate words broken at line ends ("docu-\nmento").
		$text = preg_replace( '/(\p{Ll})-\n(\p{Ll})/u', '$1$2', $text ) ?? $text;
		// Collapse horizontal whitespace.
		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		// Trim spaces around newlines.
		$text = preg_replace( '/ *\n */u', "\n", $text ) ?? $text;
		// 3+ newlines → 2.
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text ) ?? $text;

		// Drop consecutive duplicate paragraphs/lines (page headers, footers).
		$parts = explode( "\n", trim( $text ) );
		$out   = array();
		$prev  = null;
		foreach ( $parts as $line ) {
			$key = mb_strtolower( trim( $line ) );
			if ( '' !== $key && $key === $prev ) {
				continue;
			}
			$out[] = $line;
			$prev  = $key;
		}
		return trim( implode( "\n", $out ) );
	}

	/**
	 * HTML → readable text with paragraph breaks; scripts/styles removed.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$html = preg_replace( '#<(script|style|noscript|template|svg|head)\b[^>]*>.*?</\1>#is', ' ', $html ) ?? $html;
		$html = preg_replace( '#<!--.*?-->#s', ' ', $html ) ?? $html;
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		$html = preg_replace( '#</(p|div|li|h[1-6]|tr|blockquote|section|article|header|footer|dd|dt|figcaption|pre|table)>#i', "\n\n", $html ) ?? $html;
		$html = preg_replace( '#<(td|th)\b[^>]*>#i', ' ', $html ) ?? $html;
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::clean( $text );
	}

	/**
	 * Joins hard-wrapped lines (PDF text layers wrap at the page width) into
	 * flowing paragraphs so the narration does not pause mid-sentence.
	 *
	 * A single newline is replaced by a space when the previous line does not
	 * end a sentence, or when the next line starts in lowercase. Blank lines
	 * (paragraphs) and short list-like lines are preserved.
	 *
	 * @param string $text Cleaned text.
	 * @return string
	 */
	public static function unwrap_lines( string $text ): string {
		$paragraphs = preg_split( '/\n{2,}/u', $text );
		if ( false === $paragraphs ) {
			return $text;
		}
		$out = array();
		foreach ( $paragraphs as $paragraph ) {
			$lines  = explode( "\n", $paragraph );
			$joined = '';
			foreach ( $lines as $i => $line ) {
				$line = trim( $line );
				if ( 0 === $i || '' === $joined ) {
					$joined = $line;
					continue;
				}
				$prev_ends_sentence = (bool) preg_match( '/[\.\!\?…:;"”\)]$/u', $joined );
				$next_lower         = (bool) preg_match( '/^[\p{Ll}\(\[“"]/u', $line );
				$prev_short         = mb_strlen( $joined ) < 40;
				if ( $next_lower || ( ! $prev_ends_sentence && ! $prev_short ) ) {
					$joined .= ' ' . $line;
				} else {
					$joined .= "\n" . $line;
				}
			}
			$out[] = $joined;
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Truncates at a sentence/paragraph boundary near the limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters (<=0 = unlimited).
	 * @return string
	 */
	public static function truncate( string $text, int $max ): string {
		if ( $max <= 0 || mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		// Prefer the last paragraph or sentence end in the final 20%.
		$floor = (int) ( $max * 0.8 );
		$pos   = max( (int) mb_strrpos( $cut, "\n\n" ), (int) mb_strrpos( $cut, '. ' ), (int) mb_strrpos( $cut, '.' ) );
		if ( $pos >= $floor ) {
			$cut = mb_substr( $cut, 0, $pos + 1 );
		}
		return rtrim( $cut );
	}

	/**
	 * Splits text into sentences (for TTS chunking and transcript highlighting).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function sentences( string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/(?<=[\.\!\?…;:])\s+(?=[\p{Lu}\p{N}"“(\[])|\n+/u', $text );
		if ( false === $parts ) {
			$parts = array( $text );
		}
		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Word count (unicode aware).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function word_count( string $text ): int {
		$n = preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text );
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Rough token estimate (≈ 4 chars/token for pt-BR/en prose). An estimate,
	 * never presented as a cost.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function estimate_tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	/**
	 * Estimated spoken duration in seconds at ~150 words/minute.
	 *
	 * @param string $text  Text.
	 * @param float  $speed Playback speed multiplier.
	 * @return float
	 */
	public static function estimate_duration( string $text, float $speed = 1.0 ): float {
		$words = self::word_count( $text );
		$speed = $speed > 0 ? $speed : 1.0;
		return round( ( $words / 150 ) * 60 / $speed, 1 );
	}
}
