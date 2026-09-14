<?php
/**
 * Extractive summarizer for the no-AI script (pure PHP, deterministic).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the sentences that carry the document — names, dates, places,
 * numbers, quoted speech, recurrent terms — and returns them in the original
 * order, so the template narration reads the heart of a long PDF instead of
 * its first N words. No statistics beyond term frequency, no external data.
 */
final class ExtractiveSummarizer {

	/**
	 * Stop words (pt-BR, plus a few EN/ES ones common in mixed corpora).
	 *
	 * @var string[]
	 */
	public const STOP_WORDS = array(
		'a',
		'à',
		'às',
		'ao',
		'aos',
		'as',
		'o',
		'os',
		'um',
		'uma',
		'uns',
		'umas',
		'de',
		'da',
		'do',
		'das',
		'dos',
		'em',
		'na',
		'no',
		'nas',
		'nos',
		'por',
		'pelo',
		'pela',
		'pelos',
		'pelas',
		'para',
		'pra',
		'com',
		'sem',
		'sob',
		'sobre',
		'entre',
		'até',
		'após',
		'e',
		'ou',
		'mas',
		'nem',
		'que',
		'se',
		'como',
		'quando',
		'onde',
		'porque',
		'pois',
		'já',
		'ainda',
		'também',
		'não',
		'sim',
		'mais',
		'menos',
		'muito',
		'muita',
		'muitos',
		'muitas',
		'pouco',
		'pouca',
		'todo',
		'toda',
		'todos',
		'todas',
		'cada',
		'outro',
		'outra',
		'outros',
		'outras',
		'mesmo',
		'mesma',
		'mesmos',
		'mesmas',
		'este',
		'esta',
		'estes',
		'estas',
		'esse',
		'essa',
		'esses',
		'essas',
		'aquele',
		'aquela',
		'aqueles',
		'aquelas',
		'isto',
		'isso',
		'aquilo',
		'ele',
		'ela',
		'eles',
		'elas',
		'eu',
		'tu',
		'você',
		'vocês',
		'nós',
		'me',
		'te',
		'lhe',
		'lhes',
		'seu',
		'sua',
		'seus',
		'suas',
		'meu',
		'minha',
		'meus',
		'minhas',
		'nosso',
		'nossa',
		'nossos',
		'nossas',
		'dele',
		'dela',
		'deles',
		'delas',
		'ser',
		'é',
		'são',
		'era',
		'eram',
		'foi',
		'foram',
		'será',
		'serão',
		'seja',
		'sejam',
		'sido',
		'sendo',
		'estar',
		'está',
		'estão',
		'estava',
		'estavam',
		'esteve',
		'estiveram',
		'ter',
		'tem',
		'têm',
		'tinha',
		'tinham',
		'teve',
		'tiveram',
		'há',
		'havia',
		'haver',
		'fazer',
		'faz',
		'fez',
		'feito',
		'ir',
		'vai',
		'vão',
		'ia',
		'iam',
		'poder',
		'pode',
		'podem',
		'podia',
		'dizer',
		'diz',
		'disse',
		'então',
		'assim',
		'aqui',
		'ali',
		'lá',
		'agora',
		'depois',
		'antes',
		'hoje',
		'ontem',
		'sempre',
		'nunca',
		'bem',
		'mal',
		'só',
		'apenas',
		'tão',
		'tanto',
		'tanta',
		'quanto',
		'qual',
		'quais',
		'quem',
		'cujo',
		'cuja',
		'the',
		'of',
		'and',
		'in',
		'to',
		'la',
		'el',
		'los',
		'las',
		'del',
		'y',
	);

	/**
	 * Words that flag document boilerplate; sentences dominated by them are skipped.
	 *
	 * @var string[]
	 */
	private const BOILERPLATE = array( 'página', 'pag', 'pág', 'sumário', 'índice', 'anexo', 'apêndice', 'copyright', 'todos os direitos', 'issn', 'isbn', 'www', 'http', 'e-mail', 'email', 'telefone', 'tel', 'cep', 'cnpj', 'impresso', 'ficha catalográfica', 'expediente', 'diagramação', 'revisão', 'tiragem' );

	/**
	 * Summarizes text to about $target_words words.
	 *
	 * @param string $text         Cleaned document text.
	 * @param int    $target_words Target length (<= 0 returns the text unchanged).
	 * @return string Paragraph-structured summary.
	 */
	public static function summarize( string $text, int $target_words ): string {
		$text = trim( $text );
		if ( '' === $text || $target_words <= 0 || Normalizer::word_count( $text ) <= $target_words ) {
			return $text;
		}

		$sentences = self::dedupe( self::sentences( $text ) );
		$n         = count( $sentences );
		$deduped   = implode( ' ', array_column( $sentences, 'text' ) );
		if ( Normalizer::word_count( $deduped ) <= $target_words ) {
			return $deduped; // Repetition alone made it long (refrains, running headers).
		}
		if ( $n <= 2 ) {
			return Normalizer::truncate( $deduped, $target_words * 6 );
		}

		$stop  = array_flip( self::STOP_WORDS );
		$freq  = self::term_frequencies( $sentences, $stop );
		$max_f = $freq ? log( 1 + max( $freq ) ) : 1.0;
		$total = 0;

		$scored = array();
		foreach ( $sentences as $i => $s ) {
			$words = self::tokens( $s['text'] );
			$count = count( $words );
			if ( $count < 4 || $count > 90 ) {
				continue;
			}
			if ( self::is_boilerplate( $s['text'] ) ) {
				continue;
			}
			$score = 0.0;
			$seen  = array();
			foreach ( $words as $w ) {
				if ( isset( $stop[ $w ] ) || isset( $seen[ $w ] ) || mb_strlen( $w ) < 3 ) {
					continue;
				}
				$seen[ $w ] = true;
				$score     += log( 1 + ( $freq[ $w ] ?? 0 ) ) / $max_f;
			}
			// Density rather than raw sum, softened so mid-length sentences win.
			$score = $score / sqrt( max( 1, $count ) );

			// Concrete signals weigh more than recurrence: names, dates, numbers, quoted speech.
			$caps = preg_match_all( '/(?<=\s|^)\p{Lu}\p{Ll}{2,}/u', $s['text'] );
			$nums = preg_match_all( '/\b\d{1,4}\b/u', $s['text'] );
			if ( is_int( $caps ) ) {
				$score += min( 4, $caps ) * 0.25;
			}
			if ( is_int( $nums ) && $nums > 0 ) {
				$score += 0.2;
			}
			if ( preg_match( '/\b(1[6-9]\d{2}|20\d{2})\b/u', $s['text'] ) ) {
				$score += 0.5; // A year.
			}
			if ( preg_match( '/["“”]/u', $s['text'] ) ) {
				$score += 0.4;
			}
			// Position: openings and closings of the document and of paragraphs matter.
			if ( 0 === $i ) {
				$score += 0.6;
			} elseif ( $i < 3 ) {
				$score += 0.2;
			}
			if ( $i === $n - 1 ) {
				$score += 0.2;
			}
			if ( $s['first_in_paragraph'] ) {
				$score += 0.1;
			}
			$scored[ $i ] = $score;
			++$total;
		}
		if ( 0 === $total ) {
			return Normalizer::truncate( $text, $target_words * 6 );
		}

		arsort( $scored );
		$picked = array();
		$words  = 0;
		foreach ( $scored as $i => $score ) {
			$len = Normalizer::word_count( $sentences[ $i ]['text'] );
			if ( $words + $len > $target_words && $words > $target_words * 0.6 ) {
				continue;
			}
			$picked[ $i ] = true;
			$words       += $len;
			if ( $words >= $target_words ) {
				break;
			}
		}
		// Always keep the very first sentence: it anchors the reader.
		$picked[0] = true;
		ksort( $picked );

		// Rebuild in reading order, grouping by original paragraph.
		$out       = array();
		$paragraph = array();
		$last_par  = null;
		foreach ( array_keys( $picked ) as $i ) {
			if ( ! isset( $sentences[ $i ] ) ) {
				continue;
			}
			$s = $sentences[ $i ];
			if ( null !== $last_par && $s['paragraph'] !== $last_par && $paragraph ) {
				$out[]     = implode( ' ', $paragraph );
				$paragraph = array();
			}
			$paragraph[] = $s['text'];
			$last_par    = $s['paragraph'];
		}
		if ( $paragraph ) {
			$out[] = implode( ' ', $paragraph );
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Sentences with paragraph position metadata.
	 *
	 * @param string $text Text.
	 * @return array<int,array{text:string,paragraph:int,first_in_paragraph:bool}>
	 */
	private static function sentences( string $text ): array {
		$out        = array();
		$paragraphs = preg_split( '/\n{2,}/u', $text );
		if ( false === $paragraphs ) {
			return $out;
		}
		foreach ( $paragraphs as $p_index => $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			foreach ( SpeechText::sentences( $paragraph ) as $k => $sentence ) {
				$out[] = array(
					'text'               => $sentence,
					'paragraph'          => $p_index,
					'first_in_paragraph' => 0 === $k,
				);
			}
		}
		return $out;
	}

	/**
	 * Lowercase word tokens.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function tokens( string $text ): array {
		if ( ! preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', mb_strtolower( $text ), $m ) ) {
			return array();
		}
		return $m[0];
	}

	/**
	 * Drops repeated sentences (running headers, refrains), keeping the first.
	 *
	 * @param array<int,array{text:string,paragraph:int,first_in_paragraph:bool}> $sentences Sentences.
	 * @return array<int,array{text:string,paragraph:int,first_in_paragraph:bool}>
	 */
	private static function dedupe( array $sentences ): array {
		$seen = array();
		$out  = array();
		foreach ( $sentences as $s ) {
			$key = mb_strtolower( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s['text'] ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $s;
		}
		return $out;
	}

	/**
	 * Document-level term frequencies (stop words excluded).
	 *
	 * @param array<int,array{text:string}> $sentences Sentences.
	 * @param array<string,int>             $stop      Stop words (flipped).
	 * @return array<string,int>
	 */
	private static function term_frequencies( array $sentences, array $stop ): array {
		$freq = array();
		foreach ( $sentences as $s ) {
			foreach ( self::tokens( $s['text'] ) as $w ) {
				if ( isset( $stop[ $w ] ) || mb_strlen( $w ) < 3 || is_numeric( $w ) ) {
					continue;
				}
				$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
			}
		}
		return $freq;
	}

	/**
	 * Whether a sentence looks like publication boilerplate.
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	private static function is_boilerplate( string $sentence ): bool {
		$lower = mb_strtolower( $sentence );
		foreach ( self::BOILERPLATE as $marker ) {
			if ( str_contains( $lower, $marker ) ) {
				return true;
			}
		}
		// Mostly digits/punctuation (tables, page footers).
		$letters = preg_match_all( '/\p{L}/u', $sentence );
		$non_ws  = mb_strlen( (string) preg_replace( '/\s+/u', '', $sentence ) );
		return is_int( $letters ) && $non_ws > 0 && $letters / $non_ws < 0.6;
	}
}
