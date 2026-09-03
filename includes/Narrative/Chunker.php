<?php
/**
 * Splits long text into LLM-sized chunks.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preference order: sections (blank-line blocks) → paragraphs → sentences →
 * hard cut. Chunks never exceed $size characters.
 */
final class Chunker {

	/**
	 * Splits text.
	 *
	 * @param string $text Text.
	 * @param int    $size Max chars per chunk.
	 * @return string[]
	 */
	public static function chunk( string $text, int $size ): array {
		$size = max( 500, $size );
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		if ( mb_strlen( $text ) <= $size ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';
		$blocks  = preg_split( '/\n{2,}/u', $text );
		if ( false === $blocks ) {
			$blocks = array( $text );
		}

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			if ( mb_strlen( $block ) > $size ) {
				// Flush current, then split the oversized block by sentences.
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}
				foreach ( self::split_sentences( $block, $size ) as $piece ) {
					if ( '' === $current ) {
						$current = $piece;
					} elseif ( mb_strlen( $current ) + 1 + mb_strlen( $piece ) <= $size ) {
						$current .= ' ' . $piece;
					} else {
						$chunks[] = $current;
						$current  = $piece;
					}
				}
				continue;
			}
			if ( '' === $current ) {
				$current = $block;
			} elseif ( mb_strlen( $current ) + 2 + mb_strlen( $block ) <= $size ) {
				$current .= "\n\n" . $block;
			} else {
				$chunks[] = $current;
				$current  = $block;
			}
		}
		if ( '' !== $current ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * Sentence-level split with a hard cut for pathological sentences.
	 *
	 * @param string $block Block.
	 * @param int    $size  Max chars.
	 * @return string[]
	 */
	private static function split_sentences( string $block, int $size ): array {
		$out = array();
		foreach ( Normalizer::sentences( $block ) as $sentence ) {
			if ( mb_strlen( $sentence ) <= $size ) {
				$out[] = $sentence;
				continue;
			}
			// Hard cut at word boundaries; a single oversized "word" is sliced, never truncated.
			$words = preg_split( '/\s+/u', $sentence );
			if ( false === $words ) {
				$words = array( $sentence );
			}
			$pieces = array();
			foreach ( $words as $word ) {
				if ( mb_strlen( $word ) > $size ) {
					$pieces = array_merge( $pieces, mb_str_split( $word, $size ) );
				} else {
					$pieces[] = $word;
				}
			}
			$buf = '';
			foreach ( $pieces as $word ) {
				if ( '' === $buf ) {
					$buf = $word;
				} elseif ( mb_strlen( $buf ) + 1 + mb_strlen( $word ) <= $size ) {
					$buf .= ' ' . $word;
				} else {
					$out[] = $buf;
					$buf   = $word;
				}
			}
			if ( '' !== $buf ) {
				$out[] = $buf;
			}
		}
		return $out;
	}
}
