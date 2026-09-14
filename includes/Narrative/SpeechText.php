<?php
/**
 * Prepares narration text for speech synthesis.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The transcript shown to the visitor keeps the original spelling; the text
 * handed to a TTS engine (browser or server) is rewritten so it is *heard*
 * correctly: dates become words, hyphenated acronyms stop sounding like
 * subtractions, abbreviations are expanded, symbols and URLs disappear, and
 * each sentence is cut into short breath groups (Chrome silently truncates
 * long utterances and drops syllables right after a cancel()).
 *
 * Pure functions; unit-tested without WordPress.
 */
final class SpeechText {

	/**
	 * Bump when the spoken rendering changes so stored audio is re-synthesized.
	 */
	public const VERSION = '2';

	/**
	 * Max characters per utterance sent to the Web Speech API.
	 */
	public const MAX_UTTERANCE_CHARS = 170;

	/**
	 * Abbreviations (lowercase, without the final dot) => spoken form.
	 *
	 * @var array<string,string>
	 */
	private const ABBREVIATIONS = array(
		'dr'    => 'doutor',
		'dra'   => 'doutora',
		'drs'   => 'doutores',
		'sr'    => 'senhor',
		'sra'   => 'senhora',
		'srs'   => 'senhores',
		'sras'  => 'senhoras',
		'srta'  => 'senhorita',
		'prof'  => 'professor',
		'profa' => 'professora',
		'eng'   => 'engenheiro',
		'enga'  => 'engenheira',
		'exmo'  => 'excelentíssimo',
		'exma'  => 'excelentíssima',
		'ilmo'  => 'ilustríssimo',
		'ilma'  => 'ilustríssima',
		'pe'    => 'padre',
		'fr'    => 'frei',
		'gen'   => 'general',
		'cel'   => 'coronel',
		'cap'   => 'capítulo',
		'ten'   => 'tenente',
		'sgt'   => 'sargento',
		'av'    => 'avenida',
		'r'     => 'rua',
		'pç'    => 'praça',
		'trav'  => 'travessa',
		'rod'   => 'rodovia',
		'km'    => 'quilômetros',
		'art'   => 'artigo',
		'arts'  => 'artigos',
		'inc'   => 'inciso',
		'par'   => 'parágrafo',
		'séc'   => 'século',
		'sec'   => 'século',
		'pág'   => 'página',
		'pag'   => 'página',
		'págs'  => 'páginas',
		'p'     => 'página',
		'pp'    => 'páginas',
		'vol'   => 'volume',
		'vols'  => 'volumes',
		'ed'    => 'edição',
		'obs'   => 'observação',
		'ref'   => 'referência',
		'tel'   => 'telefone',
		'cx'    => 'caixa',
		'nº'    => 'número',
		'no'    => 'número',
		'num'   => 'número',
		'fl'    => 'folha',
		'fls'   => 'folhas',
		'doc'   => 'documento',
		'docs'  => 'documentos',
		'aprox' => 'aproximadamente',
		'etc'   => 'etcétera',
		'ex'    => 'exemplo',
		'min'   => 'minutos',
		'dep'   => 'departamento',
		'ltda'  => 'limitada',
		'cia'   => 'companhia',
		'univ'  => 'universidade',
		'fund'  => 'fundação',
		'inst'  => 'instituto',
		'assoc' => 'associação',
		'org'   => 'organização',
		'hosp'  => 'hospital',
		'oms'   => 'o m s',
		'uti'   => 'u t i',
		'ibge'  => 'i b g e',
		'ufba'  => 'u f b a',
		'ufmg'  => 'u f m g',
		'sp'    => 'São Paulo',
		'rj'    => 'Rio de Janeiro',
		'mg'    => 'Minas Gerais',
		'rs'    => 'Rio Grande do Sul',
		'pr'    => 'Paraná',
		'sc'    => 'Santa Catarina',
		'ba'    => 'Bahia',
		'ce'    => 'Ceará',
		'df'    => 'Distrito Federal',
		'go'    => 'Goiás',
		'es'    => 'Espírito Santo',
		'pa'    => 'Pará',
		'am'    => 'Amazonas',
		'ma'    => 'Maranhão',
		'pb'    => 'Paraíba',
		'rn'    => 'Rio Grande do Norte',
		'al'    => 'Alagoas',
		'se'    => 'Sergipe',
		'pi'    => 'Piauí',
		'mt'    => 'Mato Grosso',
		'to'    => 'Tocantins',
		'ro'    => 'Rondônia',
		'ac'    => 'Acre',
		'ap'    => 'Amapá',
		'rr'    => 'Roraima',
	);

	/**
	 * Abbreviations only expanded when followed by a dot (short tokens that
	 * are also ordinary words: "p.", "r.", "no.", "ex.", "ed.").
	 *
	 * @var string[]
	 */
	private const DOT_ONLY = array( 'p', 'pp', 'r', 'no', 'ex', 'ed', 'min', 'cap', 'ten', 'par', 'inc', 'ref', 'obs', 'sec', 'cx', 'fl', 'fls', 'pe', 'fr', 'gen', 'cel', 'sgt', 'av', 'rod', 'trav', 'vol', 'vols', 'doc', 'docs', 'dep', 'org', 'fund', 'inst', 'assoc', 'univ', 'hosp', 'cia', 'eng', 'enga', 'num', 'art', 'arts' );

	/**
	 * Codes expanded only when written in capitals as a standalone token
	 * (state codes, institutions).
	 *
	 * @var string[]
	 */
	private const UPPER_ONLY = array( 'sp', 'rj', 'mg', 'rs', 'pr', 'sc', 'ba', 'ce', 'df', 'go', 'es', 'pa', 'am', 'ma', 'pb', 'rn', 'al', 'se', 'pi', 'mt', 'to', 'ro', 'ac', 'ap', 'rr', 'oms', 'uti', 'ibge', 'ufba', 'ufmg' );

	/**
	 * Two-letter abbreviations expanded even without a dot.
	 *
	 * @var string[]
	 */
	private const SHORT_ALWAYS = array( 'km', 'nº' );

	/**
	 * Month names.
	 *
	 * @var string[]
	 */
	private const MONTHS = array( '', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' );

	/**
	 * Display sentences grouped by paragraph, each with its spoken variant and
	 * utterance parts.
	 *
	 * @param string $script Narration script (paragraphs separated by blank lines).
	 * @return array<int,array<int,array{text:string,speech:string,parts:string[]}>>
	 */
	public static function segments( string $script ): array {
		$paragraphs = preg_split( '/\n{2,}/u', trim( $script ) );
		if ( false === $paragraphs ) {
			return array();
		}
		$out = array();
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( (string) preg_replace( '/\s*\n\s*/u', ' ', $paragraph ) );
			if ( '' === $paragraph ) {
				continue;
			}
			$sentences = array();
			foreach ( self::sentences( $paragraph ) as $sentence ) {
				$speech      = self::for_speech( $sentence );
				$sentences[] = array(
					'text'   => $sentence,
					'speech' => $speech,
					'parts'  => self::utterances( $speech ),
				);
			}
			if ( $sentences ) {
				$out[] = $sentences;
			}
		}
		return $out;
	}

	/**
	 * Sentence split that does not break on abbreviations, initials, decimals
	 * or ordinals ("Dr. Silva", "J. Souza", "3.500 pessoas").
	 *
	 * @param string $paragraph One paragraph.
	 * @return string[]
	 */
	public static function sentences( string $paragraph ): array {
		$paragraph = trim( $paragraph );
		if ( '' === $paragraph ) {
			return array();
		}
		$mark = "\u{E000}";
		// A short word + dot followed by a lowercase word never ends a sentence.
		$protected = preg_replace_callback(
			'/\b(\p{L}{1,5})\.(?=\s+\p{Ll})/u',
			static fn( array $m ): string => $m[1] . $mark,
			$paragraph
		) ?? $paragraph;
		// Known abbreviations and single-letter initials, even before capitals.
		$protected = preg_replace_callback(
			'/(?<![\p{L}\p{N}])(dr|dra|drs|sr|sra|srs|sras|srta|prof|profa|eng|enga|exmo|exma|ilmo|ilma|pe|fr|gen|cel|cap|ten|sgt|av|r|pç|trav|rod|art|arts|inc|par|séc|sec|pág|pag|págs|p|pp|vol|vols|ed|obs|ref|tel|cx|nº|no|num|fl|fls|doc|docs|aprox|ex|min|dep|ltda|cia|univ|fund|inst|assoc|org|hosp|jr|st|sta|sto|\p{Lu})\./iu',
			static fn( array $m ): string => $m[1] . $mark,
			$protected
		) ?? $protected;
		$protected = preg_replace( '/(\p{N})\.(?=\p{N})/u', '$1' . $mark, $protected ) ?? $protected;

		$parts = preg_split( '/(?:(?<=[\.\!\?…])|(?<=[\.\!\?…]["”’\)\]]))\s+(?=["“‘\(\[]?[\p{Lu}\p{N}])/u', $protected );
		if ( false === $parts ) {
			$parts = array( $protected );
		}
		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( str_replace( $mark, '.', $p ) );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Rewrites one sentence for a speech engine.
	 *
	 * @param string $text Display sentence.
	 * @return string
	 */
	public static function for_speech( string $text ): string {
		$t = trim( $text );
		if ( '' === $t ) {
			return '';
		}

		// Shouting text (PDF headings) would be spelled letter by letter.
		$letters = preg_match_all( '/\p{L}/u', $t );
		$uppers  = preg_match_all( '/\p{Lu}/u', $t );
		if ( is_int( $letters ) && is_int( $uppers ) && $letters >= 12 && $uppers / $letters >= 0.6 ) {
			$t = mb_strtolower( $t );
			$t = mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
		}

		// Roman numerals after "século", "capítulo", "Dom", proper names ("Pedro II").
		$t = preg_replace_callback(
			'/\b(século|séc\.|capítulo|cap\.|volume|vol\.|tomo|parte|livro|título|artigo|Dom|D\.|Papa|Rei|Rainha|\p{Lu}\p{Ll}{2,})\s+([IVXLCDMivxlcdm]{1,7})\b(?!\.\p{L})/u',
			static function ( array $m ): string {
				$numeral = mb_strtoupper( $m[2] );
				$value   = self::roman_to_int( $numeral );
				if ( $value <= 0 ) {
					return $m[0];
				}
				$context   = mb_strtolower( $m[1] );
				$is_person = ! in_array( $context, array( 'século', 'séc.', 'capítulo', 'cap.', 'volume', 'vol.', 'tomo', 'parte', 'livro', 'título', 'artigo' ), true );
				if ( $is_person && $m[2] !== $numeral ) {
					return $m[0]; // Lowercase "vi"/"mi" after a name is a word, not a numeral.
				}
				$words = ( $is_person && $value <= 10 ) ? self::ordinal_words( $value, false ) : self::cardinal_words( $value );
				return $m[1] . ' ' . $words;
			},
			$t
		) ?? $t;

		// Typographic noise.
		$t = str_replace( array( '“', '”', '„', '«', '»', '"' ), '', $t );
		$t = str_replace( array( '‘', '’', '`', '´' ), "'", $t );
		$t = preg_replace( '/\s*[—–]\s*/u', ', ', $t ) ?? $t;
		$t = preg_replace( '/\s*\.{3,}\s*|\s*…\s*/u', '... ', $t ) ?? $t;
		$t = preg_replace( '/[\*_#`~^|<>{}\[\]]+/u', ' ', $t ) ?? $t;
		$t = preg_replace( '/[•·●▪■□◦→←↑↓]/u', ', ', $t ) ?? $t;

		// URLs and e-mails are unreadable aloud.
		$t = preg_replace( '~(?:https?://|www\.)[^\s]+~iu', 'endereço eletrônico', $t ) ?? $t;
		$t = preg_replace( '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', 'endereço de e-mail', $t ) ?? $t;

		// Dates.
		$t = preg_replace_callback(
			'/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/u',
			static fn( array $m ): string => self::date_words( (int) $m[3], (int) $m[2], (int) $m[1] ),
			$t
		) ?? $t;
		$t = preg_replace_callback(
			'/\b(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})\b/u',
			static fn( array $m ): string => self::date_words( (int) $m[1], (int) $m[2], (int) $m[3] ),
			$t
		) ?? $t;
		$t = preg_replace_callback(
			'/\b(\d{1,2})\/(\d{4})\b/u',
			static function ( array $m ): string {
				$month = (int) $m[1];
				return ( $month >= 1 && $month <= 12 ) ? self::MONTHS[ $month ] . ' de ' . $m[2] : $m[0];
			},
			$t
		) ?? $t;
		// Times.
		$t = preg_replace( '/\b(\d{1,2})[h:](\d{2})\b/u', '$1 horas e $2 minutos', $t ) ?? $t;
		$t = preg_replace( '/\b(\d{1,2})h\b/u', '$1 horas', $t ) ?? $t;

		// Ranges: 2020-2021 → 2020 a 2021.
		$t = preg_replace( '/\b(\d{3,4})\s*[-–]\s*(\d{3,4})\b/u', '$1 a $2', $t ) ?? $t;
		// Hyphenated codes: COVID-19, SARS-CoV-2 → spaces (never "minus").
		$t = preg_replace( '/(?<=[\p{L}\p{N}])-(?=\p{N})|(?<=\p{N})-(?=\p{L})/u', ' ', $t ) ?? $t;
		// Thousands separators: 1.234.567 → 1234567.
		$t = preg_replace_callback(
			'/\b\d{1,3}(?:\.\d{3})+\b(?!,\d)/u',
			static fn( array $m ): string => str_replace( '.', '', $m[0] ),
			$t
		) ?? $t;
		$t = preg_replace( '/(\d)\s*%/u', '$1 por cento', $t ) ?? $t;
		// Ordinals.
		$t = preg_replace_callback(
			'/\b(\d{1,3})\s*([ºª°])/u',
			static fn( array $m ): string => self::ordinal_words( (int) $m[1], 'ª' === $m[2] ),
			$t
		) ?? $t;
		// Currency (thousands dots removed so "1.200,50" is not read as a decimal).
		$t = preg_replace_callback(
			'/(R\$|US\$|€)\s*(\d[\d\.]*(?:,\d+)?)/iu',
			static function ( array $m ): string {
				$unit = array(
					'R$'  => 'reais',
					'US$' => 'dólares',
					'€'   => 'euros',
				);
				return str_replace( '.', '', $m[2] ) . ' ' . $unit[ mb_strtoupper( $m[1] ) ];
			},
			$t
		) ?? $t;
		// Slashes.
		$t = preg_replace( '/\bkm\/h\b/iu', 'quilômetros por hora', $t ) ?? $t;
		$t = preg_replace( '/(?<=\p{L})\/(?=\p{L})/u', ' ou ', $t ) ?? $t;
		$t = preg_replace( '/(?<=\p{N})\/(?=\p{N})/u', ' barra ', $t ) ?? $t;
		$t = str_replace( array( '§', '&', '+', '=' ), array( ' parágrafo ', ' e ', ' mais ', ' igual a ' ), $t );

		// Abbreviations.
		$t = preg_replace_callback(
			'/(?<![\p{L}\p{N}])([\p{L}º]{1,7})(\.)?(?![\p{L}])/u',
			static function ( array $m ): string {
				$raw   = $m[1];
				$lower = mb_strtolower( $raw );
				$dot   = isset( $m[2] ) && '.' === $m[2];
				$upper = mb_strtoupper( $raw ) === $raw && mb_strlen( $raw ) >= 2;
				if ( ! isset( self::ABBREVIATIONS[ $lower ] ) ) {
					return $m[0];
				}
				if ( in_array( $lower, self::UPPER_ONLY, true ) ) {
					return $upper ? self::ABBREVIATIONS[ $lower ] : $m[0];
				}
				if ( in_array( $lower, self::DOT_ONLY, true ) && ! $dot ) {
					return $m[0];
				}
				if ( ! $dot && mb_strlen( $raw ) <= 2 && ! in_array( $lower, self::SHORT_ALWAYS, true ) ) {
					return $m[0];
				}
				$word  = self::ABBREVIATIONS[ $lower ];
				$first = mb_substr( $raw, 0, 1 );
				if ( mb_strtoupper( $first ) === $first && $lower !== $raw ) {
					$word = mb_strtoupper( mb_substr( $word, 0, 1 ) ) . mb_substr( $word, 1 );
				}
				return $word;
			},
			$t
		) ?? $t;

		// Long ALL-CAPS words are spelled by some engines: lowercase them.
		$t = preg_replace_callback(
			'/\b\p{Lu}{5,}\b/u',
			static fn( array $m ): string => mb_strtolower( $m[0] ),
			$t
		) ?? $t;
		// Short acronyms without vowels are spelled letter by letter.
		$t = preg_replace_callback(
			'/\b(\p{Lu}{2,4})\b/u',
			static function ( array $m ): string {
				$a = $m[1];
				if ( preg_match( '/[AEIOUÁÉÍÓÚÂÊÔÃÕ]/u', $a ) ) {
					return $a;
				}
				return implode( ' ', mb_str_split( $a ) );
			},
			$t
		) ?? $t;

		// Cleanup.
		$t = preg_replace( '/\(\s*\)|\[\s*\]/u', '', $t ) ?? $t;
		$t = preg_replace( '/\s+([,;:\.\!\?])/u', '$1', $t ) ?? $t;
		$t = preg_replace( '/([,;:])\1+/u', '$1', $t ) ?? $t;
		$t = preg_replace( '/,\s*\./u', '.', $t ) ?? $t;
		$t = preg_replace( '/\s{2,}/u', ' ', $t ) ?? $t;
		$t = trim( $t, " \t,;:" );
		if ( '' !== $t && ! preg_match( '/[\.\!\?…]$/u', $t ) ) {
			$t .= '.';
		}
		return $t;
	}

	/**
	 * Splits a spoken sentence into utterances of at most $max characters,
	 * cutting at clause boundaries first, then at spaces.
	 *
	 * @param string $speech Spoken sentence.
	 * @param int    $max    Max chars per utterance.
	 * @return string[]
	 */
	public static function utterances( string $speech, int $max = self::MAX_UTTERANCE_CHARS ): array {
		$speech = trim( $speech );
		if ( '' === $speech ) {
			return array();
		}
		if ( mb_strlen( $speech ) <= $max ) {
			return array( $speech );
		}
		$clauses = preg_split( '/(?<=[,;:\)])\s+/u', $speech );
		if ( false === $clauses ) {
			$clauses = array( $speech );
		}
		$out = array();
		$buf = '';
		foreach ( $clauses as $clause ) {
			foreach ( self::split_words( $clause, $max ) as $piece ) {
				if ( '' === $buf ) {
					$buf = $piece;
				} elseif ( mb_strlen( $buf ) + 1 + mb_strlen( $piece ) <= $max ) {
					$buf .= ' ' . $piece;
				} else {
					$out[] = $buf;
					$buf   = $piece;
				}
			}
		}
		if ( '' !== $buf ) {
			$out[] = $buf;
		}
		// Avoid a tiny orphan at the end ("2020.").
		$n = count( $out );
		if ( $n >= 2 && mb_strlen( $out[ $n - 1 ] ) < 12 ) {
			$out[ $n - 2 ] .= ' ' . $out[ $n - 1 ];
			array_pop( $out );
		}
		return $out;
	}

	/**
	 * Whole script rewritten for a server-side TTS engine (sentence by
	 * sentence, paragraph breaks preserved so the engine pauses).
	 *
	 * @param string $script Script.
	 * @return string
	 */
	public static function script_for_speech( string $script ): string {
		$paragraphs = array();
		foreach ( self::segments( $script ) as $sentences ) {
			$paragraphs[] = implode( ' ', array_column( $sentences, 'speech' ) );
		}
		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Word-level split for clauses longer than the limit.
	 *
	 * @param string $clause Clause.
	 * @param int    $max    Max chars.
	 * @return string[]
	 */
	private static function split_words( string $clause, int $max ): array {
		$clause = trim( $clause );
		if ( '' === $clause ) {
			return array();
		}
		if ( mb_strlen( $clause ) <= $max ) {
			return array( $clause );
		}
		$words = preg_split( '/\s+/u', $clause );
		if ( false === $words ) {
			return array( $clause );
		}
		$out = array();
		$buf = '';
		foreach ( $words as $word ) {
			if ( '' === $buf ) {
				$buf = $word;
			} elseif ( mb_strlen( $buf ) + 1 + mb_strlen( $word ) <= $max ) {
				$buf .= ' ' . $word;
			} else {
				$out[] = $buf;
				$buf   = $word;
			}
		}
		if ( '' !== $buf ) {
			$out[] = $buf;
		}
		return $out;
	}

	/**
	 * "12 de março de 2020".
	 *
	 * @param int $day   Day.
	 * @param int $month Month.
	 * @param int $year  Year (2 or 4 digits).
	 * @return string
	 */
	private static function date_words( int $day, int $month, int $year ): string {
		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return sprintf( '%d/%d/%d', $day, $month, $year );
		}
		if ( $year < 100 ) {
			$year += $year < 40 ? 2000 : 1900;
		}
		$day_word = 1 === $day ? 'primeiro' : (string) $day;
		return $day_word . ' de ' . self::MONTHS[ $month ] . ' de ' . $year;
	}

	/**
	 * Roman numeral → integer (0 when invalid).
	 *
	 * @param string $roman Uppercase numeral.
	 * @return int
	 */
	public static function roman_to_int( string $roman ): int {
		if ( ! preg_match( '/^M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/', $roman ) || '' === $roman ) {
			return 0;
		}
		$map   = array(
			'M' => 1000,
			'D' => 500,
			'C' => 100,
			'L' => 50,
			'X' => 10,
			'V' => 5,
			'I' => 1,
		);
		$total = 0;
		$len   = strlen( $roman );
		for ( $i = 0; $i < $len; $i++ ) {
			$cur    = $map[ $roman[ $i ] ];
			$next   = $i + 1 < $len ? $map[ $roman[ $i + 1 ] ] : 0;
			$total += $cur < $next ? -$cur : $cur;
		}
		return $total;
	}

	/**
	 * Cardinal number words (pt-BR) for 1–3999.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	public static function cardinal_words( int $n ): string {
		if ( $n <= 0 || $n >= 4000 ) {
			return (string) $n;
		}
		$units    = array( '', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove' );
		$tens     = array( '', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa' );
		$hundreds = array( '', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos' );
		$parts    = array();
		$thousand = intdiv( $n, 1000 );
		$rest     = $n % 1000;
		if ( $thousand > 0 ) {
			$parts[] = 1 === $thousand ? 'mil' : $units[ $thousand ] . ' mil';
		}
		if ( 0 === $rest ) {
			return implode( ' e ', $parts );
		}
		if ( 100 === $rest ) {
			$parts[] = 'cem';
			return implode( ' e ', $parts );
		}
		$h   = intdiv( $rest, 100 );
		$r   = $rest % 100;
		$sub = array();
		if ( $h > 0 ) {
			$sub[] = $hundreds[ $h ];
		}
		if ( $r > 0 ) {
			if ( $r < 20 ) {
				$sub[] = $units[ $r ];
			} else {
				$u     = $r % 10;
				$sub[] = $tens[ intdiv( $r, 10 ) ] . ( $u > 0 ? ' e ' . $units[ $u ] : '' );
			}
		}
		$parts[] = implode( ' e ', $sub );
		// Portuguese joins thousands with "e" only when the remainder is below 100 or a round hundred.
		$joiner = ( $rest < 100 || 0 === $rest % 100 ) ? ' e ' : ' ';
		return implode( $joiner, $parts );
	}

	/**
	 * Ordinal number words up to 99.
	 *
	 * @param int  $n        Number.
	 * @param bool $feminine Feminine form.
	 * @return string
	 */
	private static function ordinal_words( int $n, bool $feminine ): string {
		$units = array( '', 'primeir', 'segund', 'terceir', 'quart', 'quint', 'sext', 'sétim', 'oitav', 'non' );
		$tens  = array( '', 'décim', 'vigésim', 'trigésim', 'quadragésim', 'quinquagésim', 'sexagésim', 'septuagésim', 'octogésim', 'nonagésim' );
		$suf   = $feminine ? 'a' : 'o';
		if ( $n <= 0 || $n >= 100 ) {
			return 'número ' . $n;
		}
		$t = intdiv( $n, 10 );
		$u = $n % 10;
		$w = '';
		if ( $t > 0 ) {
			$w = $tens[ $t ] . $suf;
		}
		if ( $u > 0 ) {
			$w .= ( '' !== $w ? ' ' : '' ) . $units[ $u ] . $suf;
		}
		return $w;
	}
}
