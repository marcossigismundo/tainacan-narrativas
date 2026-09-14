<?php
/**
 * Deterministic faithfulness check of a narration against its sources.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A language model can be told not to invent and still do it. This class does
 * not trust the model: every sentence of the generated script is compared
 * with the item's own sources (title, description, metadata, document and
 * attachment texts) and flagged when it carries something the sources do
 * not contain:
 *
 *  - a number (2+ digits) that appears nowhere in the sources;
 *  - a proper name (capitalised word not at sentence start) whose stem does
 *    not occur in the sources;
 *  - a sentence whose content words barely overlap with the source
 *    vocabulary (generic "narrative filler" with no anchor in the item).
 *
 * Flagged sentences can be removed deterministically (`strip()`), which is
 * what the generator does after one corrective retry. Pure PHP, no
 * WordPress dependencies, unit-tested.
 */
final class FaithfulnessChecker {

	/**
	 * Reasons.
	 */
	public const REASON_NUMBER  = 'number';
	public const REASON_NAME    = 'name';
	public const REASON_OVERLAP = 'overlap';

	/**
	 * Below this share of supported content words a sentence is considered
	 * unanchored (unless it carries a supported name or number).
	 */
	private const MIN_OVERLAP = 0.34;

	/**
	 * Capitalised words that are not names (months, honorifics, connectors
	 * that start clauses after a semicolon, etc.).
	 *
	 * @var string[]
	 */
	private const NOT_NAMES = array(
		'janeiro',
		'fevereiro',
		'marco',
		'abril',
		'maio',
		'junho',
		'julho',
		'agosto',
		'setembro',
		'outubro',
		'novembro',
		'dezembro',
		'segunda',
		'terca',
		'quarta',
		'quinta',
		'sexta',
		'sabado',
		'domingo',
		'senhor',
		'senhora',
		'dona',
		'seu',
		'doutor',
		'doutora',
		'padre',
		'irma',
		'irmao',
		'professor',
		'professora',
		'deus',
		'nossa',
		'senhora',
		'santa',
		'santo',
		'sao',
		'voce',
		'este',
		'esta',
		'esse',
		'essa',
		'isso',
		'isto',
		'aquele',
		'aquela',
		'ele',
		'ela',
		'eles',
		'elas',
		'nos',
		'registro',
		'item',
		'colecao',
		'documento',
		'acervo',
		'narrativa',
		'narracao',
	);

	/**
	 * Folded source text.
	 *
	 * @var string
	 */
	private string $source_text;

	/**
	 * Stems (first 5 chars of folded tokens with 4+ chars) present in the sources.
	 *
	 * @var array<string,true>
	 */
	private array $stems = array();

	/**
	 * Whole folded tokens present in the sources.
	 *
	 * @var array<string,true>
	 */
	private array $tokens = array();

	/**
	 * Digit strings present in the sources.
	 *
	 * @var array<string,true>
	 */
	private array $numbers = array();

	/**
	 * Stop words (flipped).
	 *
	 * @var array<string,int>
	 */
	private array $stop;

	/**
	 * Folded template boilerplate sentences that are never flagged.
	 *
	 * @var array<string,true>
	 */
	private array $trusted = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $corpus Output of ContentCollector::collect() (or any array with the same text fields).
	 */
	public function __construct( array $corpus, array $trusted = array() ) {
		$this->stop        = array_flip( array_map( array( self::class, 'fold' ), ExtractiveSummarizer::STOP_WORDS ) );
		$this->source_text = self::fold( self::corpus_text( $corpus ) );
		foreach ( $trusted as $sentence ) {
			// Placeholders ({title}) are stripped: the fixed words are what identify the sentence.
			$key = self::fold( (string) preg_replace( '/\{[a-z_]+\}/', '', (string) $sentence ) );
			if ( '' !== $key ) {
				$this->trusted[ $key ] = true;
			}
		}
		foreach ( self::word_tokens( $this->source_text ) as $tok ) {
			$this->tokens[ $tok ] = true;
			if ( mb_strlen( $tok ) >= 4 ) {
				$this->stems[ mb_substr( $tok, 0, 5 ) ] = true;
			}
		}
		foreach ( self::number_tokens( $this->source_text ) as $num ) {
			$this->numbers[ $num ] = true;
		}
	}

	/**
	 * Concatenates every text field of a corpus.
	 *
	 * @param array<string,mixed> $corpus Corpus.
	 * @return string
	 */
	public static function corpus_text( array $corpus ): string {
		$parts = array(
			(string) ( $corpus['title'] ?? '' ),
			(string) ( $corpus['description'] ?? '' ),
			(string) ( $corpus['collection_name'] ?? '' ),
		);
		foreach ( (array) ( $corpus['metadata'] ?? array() ) as $m ) {
			$parts[] = (string) ( $m['label'] ?? '' );
			$parts[] = (string) ( $m['value'] ?? '' );
		}
		$parts[] = (string) ( $corpus['document']['text'] ?? '' );
		foreach ( (array) ( $corpus['attachments'] ?? array() ) as $att ) {
			$parts[] = (string) ( $att['text'] ?? '' );
			$parts[] = (string) ( $att['filename'] ?? '' );
		}
		return implode( "\n", $parts );
	}

	/**
	 * Checks a script sentence by sentence.
	 *
	 * @param string $script Narration script.
	 * @return array{ok:bool,total:int,flagged:int,unsupported:string[],sentences:array<int,array{text:string,paragraph:int,ok:bool,reasons:string[],unsupported:string[]}>}
	 */
	public function check( string $script ): array {
		$sentences   = array();
		$flagged     = 0;
		$unsupported = array();
		$paragraphs  = preg_split( '/\n{2,}/u', trim( $script ) );
		foreach ( (array) $paragraphs as $p_index => $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			foreach ( SpeechText::sentences( $paragraph ) as $sentence ) {
				$result      = $this->check_sentence( $sentence );
				$sentences[] = array_merge(
					array(
						'text'      => $sentence,
						'paragraph' => (int) $p_index,
					),
					$result
				);
				if ( ! $result['ok'] ) {
					++$flagged;
					$unsupported = array_merge( $unsupported, $result['unsupported'] );
				}
			}
		}
		return array(
			'ok'          => 0 === $flagged,
			'total'       => count( $sentences ),
			'flagged'     => $flagged,
			'unsupported' => array_values( array_unique( $unsupported ) ),
			'sentences'   => $sentences,
		);
	}

	/**
	 * Removes flagged sentences, keeping paragraph structure.
	 *
	 * @param string $script Script.
	 * @return array{script:string,removed:string[],unsupported:string[],total:int}
	 */
	public function strip( string $script ): array {
		$report  = $this->check( $script );
		$removed = array();
		$by_par  = array();
		foreach ( $report['sentences'] as $s ) {
			if ( ! $s['ok'] ) {
				$removed[] = $s['text'];
				continue;
			}
			$by_par[ $s['paragraph'] ][] = $s['text'];
		}
		$out = array();
		foreach ( $by_par as $sentences ) {
			$out[] = implode( ' ', $sentences );
		}
		return array(
			'script'      => implode( "\n\n", $out ),
			'removed'     => $removed,
			'unsupported' => $report['unsupported'],
			'total'       => $report['total'],
		);
	}

	/**
	 * Checks one sentence.
	 *
	 * @param string $sentence Sentence (original casing).
	 * @return array{ok:bool,reasons:string[],unsupported:string[]}
	 */
	public function check_sentence( string $sentence ): array {
		$reasons     = array();
		$unsupported = array();
		$folded      = self::fold( $sentence );

		// Template boilerplate ("Consulte a página do item…") describes the
		// narration, not the item; a trusted sentence may also carry the title
		// or collection name, so compare after dropping the words that are in
		// the sources.
		if ( $this->trusted ) {
			$words = array_filter( self::word_tokens( $folded ), fn( string $w ): bool => ! isset( $this->tokens[ $w ] ) );
			$key   = implode( ' ', $words );
			if ( isset( $this->trusted[ $folded ] ) || isset( $this->trusted[ $key ] ) ) {
				return array(
					'ok'          => true,
					'reasons'     => array(),
					'unsupported' => array(),
				);
			}
		}

		// Numbers with 2+ digits must exist in the sources.
		foreach ( self::number_tokens( $folded ) as $num ) {
			if ( strlen( $num ) >= 2 && ! isset( $this->numbers[ $num ] ) ) {
				$reasons[]     = self::REASON_NUMBER;
				$unsupported[] = $num;
			}
		}

		// Proper names: capitalised words not at sentence start.
		$supported_names = 0;
		if ( preg_match_all( '/(?<!^)(?<![\.\!\?…]\s)(?<=\s|[\(\"“‘\'])(\p{Lu}[\p{L}\-]{2,})/u', $sentence, $m ) ) {
			foreach ( $m[1] as $word ) {
				$fw = self::fold( $word );
				if ( '' === $fw || isset( $this->stop[ $fw ] ) || in_array( $fw, self::NOT_NAMES, true ) ) {
					continue;
				}
				// Names appearing in ALL CAPS elsewhere in the sentence are acronyms, handled by tokens.
				if ( isset( $this->tokens[ $fw ] ) || isset( $this->stems[ mb_substr( $fw, 0, 5 ) ] ) ) {
					++$supported_names;
					continue;
				}
				$reasons[]     = self::REASON_NAME;
				$unsupported[] = $word;
			}
		}

		// Overlap of content words.
		$content   = 0;
		$supported = 0;
		foreach ( self::word_tokens( $folded ) as $tok ) {
			if ( mb_strlen( $tok ) < 4 || isset( $this->stop[ $tok ] ) ) {
				continue;
			}
			++$content;
			if ( isset( $this->tokens[ $tok ] ) || isset( $this->stems[ mb_substr( $tok, 0, 5 ) ] ) ) {
				++$supported;
			}
		}
		$supported_numbers = 0;
		foreach ( self::number_tokens( $folded ) as $num ) {
			if ( isset( $this->numbers[ $num ] ) ) {
				++$supported_numbers;
			}
		}
		if ( $content >= 4 && ( $supported / $content ) < self::MIN_OVERLAP && 0 === $supported_names && 0 === $supported_numbers ) {
			$reasons[] = self::REASON_OVERLAP;
		}

		return array(
			'ok'          => array() === $reasons,
			'reasons'     => array_values( array_unique( $reasons ) ),
			'unsupported' => array_values( array_unique( $unsupported ) ),
		);
	}

	/**
	 * Lowercase, accent-free, punctuation-free text; thousands dots removed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fold( string $text ): string {
		$map  = array(
			'á' => 'a',
			'à' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'í' => 'i',
			'ì' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ó' => 'o',
			'ò' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'ú' => 'u',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ç' => 'c',
			'ñ' => 'n',
			'ý' => 'y',
			'ÿ' => 'y',
		);
		$text = mb_strtolower( $text );
		$text = strtr( $text, $map );
		$text = preg_replace( '/(?<=\d)\.(?=\d{3}\b)/u', '', $text ) ?? $text;
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

	/**
	 * Letter tokens of folded text.
	 *
	 * @param string $folded Folded text.
	 * @return string[]
	 */
	private static function word_tokens( string $folded ): array {
		return preg_match_all( '/\p{L}+/u', $folded, $m ) ? $m[0] : array();
	}

	/**
	 * Digit tokens of folded text.
	 *
	 * @param string $folded Folded text.
	 * @return string[]
	 */
	private static function number_tokens( string $folded ): array {
		return preg_match_all( '/\d+/u', $folded, $m ) ? $m[0] : array();
	}
}
