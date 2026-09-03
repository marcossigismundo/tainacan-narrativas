<?php
/**
 * Deterministic content sufficiency indicator.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Not AI, no statistics: a fixed rule on quantity and diversity of sources.
 *
 * Levels: insufficient (< 200 characters in total)
 *  basic:        < 1 500 characters, or only title/description
 *  good:         < 8 000 characters
 *  extensive:    otherwise
 */
final class ContentScore {

	public const INSUFFICIENT = 'insufficient';
	public const BASIC        = 'basic';
	public const GOOD         = 'good';
	public const EXTENSIVE    = 'extensive';

	/**
	 * Minimum characters for a narrative to be attempted.
	 */
	public const MIN_CHARS = 200;

	/**
	 * Computes the score.
	 *
	 * @param array<string,mixed> $corpus Output of ContentCollector::collect().
	 * @return array{level:string,chars:int,sources:int,label:string}
	 */
	public static function score( array $corpus ): array {
		$chars   = 0;
		$sources = 0;

		if ( '' !== trim( (string) ( $corpus['title'] ?? '' ) ) ) {
			++$sources;
			$chars += mb_strlen( (string) $corpus['title'] );
		}
		if ( '' !== trim( (string) ( $corpus['description'] ?? '' ) ) ) {
			++$sources;
			$chars += mb_strlen( (string) $corpus['description'] );
		}
		$meta_count = 0;
		foreach ( (array) ( $corpus['metadata'] ?? array() ) as $m ) {
			if ( '' !== trim( (string) ( $m['value'] ?? '' ) ) ) {
				++$meta_count;
				$chars += mb_strlen( (string) $m['value'] );
			}
		}
		if ( $meta_count > 0 ) {
			++$sources;
		}
		$doc = (array) ( $corpus['document'] ?? array() );
		if ( '' !== trim( (string) ( $doc['text'] ?? '' ) ) ) {
			++$sources;
			$chars += mb_strlen( (string) $doc['text'] );
		}
		foreach ( (array) ( $corpus['attachments'] ?? array() ) as $att ) {
			if ( '' !== trim( (string) ( $att['text'] ?? '' ) ) ) {
				++$sources;
				$chars += mb_strlen( (string) $att['text'] );
			}
		}

		if ( $chars < self::MIN_CHARS ) {
			$level = self::INSUFFICIENT;
		} elseif ( $chars < 1500 || $sources <= 2 ) {
			$level = self::BASIC;
		} elseif ( $chars < 8000 ) {
			$level = self::GOOD;
		} else {
			$level = self::EXTENSIVE;
		}

		return array(
			'level'   => $level,
			'chars'   => $chars,
			'sources' => $sources,
			'label'   => self::labels()[ $level ],
		);
	}

	/**
	 * Labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::INSUFFICIENT => __( 'Insuficiente', 'tainacan-narrativas' ),
			self::BASIC        => __( 'Básico', 'tainacan-narrativas' ),
			self::GOOD         => __( 'Bom', 'tainacan-narrativas' ),
			self::EXTENSIVE    => __( 'Extenso', 'tainacan-narrativas' ),
		);
	}
}
