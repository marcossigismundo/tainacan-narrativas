<?php
/**
 * AI-assisted script generation (reduce → analyse → write → verify → clean).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

use TainacanNarrativas\AI\AIProviderInterface;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-agnostic pipeline:
 *
 *  1. reduce  — documents longer than the chunk size are reduced chunk by
 *               chunk (faithful reductions, cached in transients) and
 *               consolidated, so the whole PDF reaches the writer;
 *  2. analyse — the model reads every source and writes a working dossier;
 *  3. write   — the mode prompt receives dossier + sources and produces the
 *               narration within the duration cap (Modes::target_words_for);
 *  4. verify  — FaithfulnessChecker compares every sentence with the item's
 *               own sources. Unsupported names/numbers/claims trigger ONE
 *               corrective call; whatever is still unsupported is removed
 *               deterministically. If too little survives, the AI result is
 *               rejected (`tn_ai_unfaithful`) and the manager falls back to
 *               the template script, which is faithful by construction;
 *  5. clean   — markdown, leaked delimiters and machine formulas removed;
 *               over-length scripts are condensed extractively (never
 *               rewritten) to the word cap.
 */
final class NarrativeGenerator {

	/**
	 * Transient TTL for chunk reductions and dossiers.
	 */
	private const CACHE_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Corpora smaller than this skip the analysis call.
	 */
	private const ANALYSIS_MIN_CHARS = 1200;

	/**
	 * Sources shorter than this get a "brief literal summary" instruction and
	 * a tighter word budget.
	 */
	private const THIN_SOURCES_CHARS = 1500;

	/**
	 * Minimum surviving words for an AI script to be accepted.
	 */
	private const MIN_WORDS = 25;

	/**
	 * Openers/connectors typical of machine-written text, removed when they
	 * start a sentence. Each entry is a regex fragment (case-insensitive).
	 *
	 * @var string[]
	 */
	private const MACHINE_OPENERS = array(
		'vale (?:a pena )?(?:ressaltar|destacar|lembrar|mencionar|notar) que',
		'é (?:importante|fundamental|interessante|relevante|possível|válido|preciso) (?:ressaltar|destacar|lembrar|mencionar|notar|observar|frisar|salientar) que',
		'cabe (?:ressaltar|destacar|lembrar|mencionar|notar|observar|frisar|salientar) que',
		'convém (?:ressaltar|destacar|lembrar|mencionar|notar|observar) que',
		'em (?:suma|resumo|síntese|conclusão)',
		'para (?:concluir|finalizar|resumir)',
		'concluindo',
		'por fim',
		'nesse sentido',
		'neste sentido',
		'dessa forma',
		'desta forma',
		'sendo assim',
		'assim sendo',
		'em síntese',
		'como (?:podemos|pode-se|se pode) (?:ver|observar|perceber|notar)',
		'como (?:mencionado|dito|visto|citado|observado) (?:anteriormente|acima|antes)',
		'conforme (?:mencionado|dito|visto|citado|observado) (?:anteriormente|acima|antes)',
		'a narrativa (?:acima|a seguir)',
		'este (?:texto|áudio|registro|documento) (?:apresenta|traz|mostra|relata|aborda|descreve)',
		'esta narrativa (?:apresenta|traz|mostra|relata|aborda|descreve)',
		'neste (?:registro|documento|áudio)',
		'no presente (?:registro|documento)',
	);

	/**
	 * Script builder (for the SOURCE blocks).
	 *
	 * @var ScriptBuilder
	 */
	private ScriptBuilder $builder;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builder = new ScriptBuilder();
	}

	/**
	 * Generates a narrative script.
	 *
	 * @param array<string,mixed> $corpus   Corpus.
	 * @param array<string,mixed> $config   Effective config (mode, language, chunk_size, external_ai_attachments).
	 * @param AIProviderInterface $provider Provider.
	 * @return array{script:string,model:string,stats:array<string,mixed>}|WP_Error
	 */
	public function generate( array $corpus, array $config, AIProviderInterface $provider ) {
		$mode     = Modes::sanitize( $config['mode'] ?? 'documentary' );
		$language = (string) ( $config['language'] ?? 'pt-BR' );
		$chunk    = max( 1500, (int) ( $config['chunk_size'] ?? 6000 ) );
		$target   = Modes::target_words_for( $mode );
		$thin     = ContentScore::score( $corpus )['chars'] < self::THIN_SOURCES_CHARS;
		if ( $thin ) {
			$target = min( $target, 120 );
		}
		$stats = array(
			'ai_calls'          => 0,
			'chunks'            => 0,
			'analysis'          => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'chars_in'          => 0,
			'target_words'      => $target,
			'thin_sources'      => $thin ? 1 : 0,
		);
		$model = $provider->model();

		// Privacy: attachments may be excluded from external AI per collection.
		$send_attachments = ! $provider->is_external() || ! empty( $config['external_ai_attachments'] );
		$working          = $corpus;
		if ( ! $send_attachments ) {
			$working['attachments'] = array();
		}

		// 1. Reduce oversized documents so the whole text reaches the writer.
		$reduced = $this->reduce_long_texts( $working, $chunk, $provider, $language, $stats, $model );
		if ( is_wp_error( $reduced ) ) {
			return $reduced;
		}
		$working = $reduced;

		$sources = $this->builder->sources_block( $working, true );
		$system  = PromptLoader::system( $language );

		// 2. Dossier: forces a full reading before writing.
		$analysis_block = '';
		if ( ! $thin && Options::is( 'ai_analysis' ) && mb_strlen( $sources ) >= self::ANALYSIS_MIN_CHARS ) {
			$dossier = $this->analyse( $working, $sources, $system, $provider, $stats, $model );
			if ( is_wp_error( $dossier ) ) {
				return $dossier;
			}
			if ( '' !== $dossier ) {
				$analysis_block = "LEITURA PRÉVIA DAS FONTES (dossiê de trabalho; use-o para não esquecer nada, mas escreva a narração a partir das fontes e nunca acrescente o que não estiver nelas):\n" . $dossier . "\n";
			}
		}

		// 3. Write.
		$vars = array(
			'target_words' => $target,
			'title'        => (string) $working['title'],
			'collection'   => (string) $working['collection_name'],
			'language'     => $language,
			'analysis'     => $analysis_block,
			'brevity'      => $thin ? "\nFONTES CURTAS: há pouca informação neste registro. Faça um resumo breve e literal, em três a seis frases, dizendo apenas o que as fontes registram. Não desenvolva, não contextualize, não descreva nada que não esteja escrito.\n" : '',
			'sources'      => $sources,
		);
		$user = PromptLoader::mode( $mode, $vars );

		$stats['chars_in'] += mb_strlen( $user );
		$result             = $this->call( $provider, $system, $user, $stats, $model, $this->max_tokens_for( $target ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$script = $this->post_process( $result );
		if ( Normalizer::word_count( $script ) < self::MIN_WORDS ) {
			return new WP_Error( 'tn_ai_malformed', __( 'A IA devolveu um roteiro vazio ou inutilizável.', 'tainacan-narrativas' ) );
		}

		// 4. Verify against the ORIGINAL sources (attachments included, this is local).
		$checker = new FaithfulnessChecker( $corpus );
		$report  = $checker->check( $script );
		$faith   = array(
			'checked'     => 1,
			'total'       => $report['total'],
			'flagged'     => $report['flagged'],
			'retried'     => 0,
			'removed'     => 0,
			'unsupported' => array_slice( $report['unsupported'], 0, 20 ),
		);
		if ( ! $report['ok'] ) {
			$faith['retried']   = 1;
			$fix                = PromptLoader::fill(
				PromptLoader::load( 'faithfulness-fix' ),
				array(
					'title'        => (string) $working['title'],
					'script'       => $script,
					'unsupported'  => implode( ', ', array_slice( $report['unsupported'], 0, 20 ) ),
					'target_words' => $target,
					'sources'      => $sources,
				)
			);
			$stats['chars_in'] += mb_strlen( $fix );
			$retry              = $this->call( $provider, $system, $fix, $stats, $model, $this->max_tokens_for( $target ) );
			if ( ! is_wp_error( $retry ) ) {
				$candidate = $this->post_process( $retry );
				if ( Normalizer::word_count( $candidate ) >= self::MIN_WORDS ) {
					$script = $candidate;
					$report = $checker->check( $script );
				}
			}
			if ( ! $report['ok'] ) {
				$stripped                   = $checker->strip( $script );
				$script                     = $stripped['script'];
				$faith['removed']           = count( $stripped['removed'] );
				$faith['removed_sentences'] = array_slice( $stripped['removed'], 0, 10 );
				$faith['unsupported']       = array_slice( $stripped['unsupported'], 0, 20 );
				Logger::warning(
					'Faithfulness check removed sentences',
					array(
						'item'        => (int) ( $corpus['item_id'] ?? 0 ),
						'removed'     => $faith['removed'],
						'unsupported' => $faith['unsupported'],
					)
				);
			}
		}
		$stats['faithfulness'] = $faith;
		if ( Normalizer::word_count( $script ) < self::MIN_WORDS ) {
			return new WP_Error( 'tn_ai_unfaithful', __( 'A IA produziu texto sem apoio nas fontes do item; o roteiro documental por template será usado.', 'tainacan-narrativas' ) );
		}

		// 5. Duration cap: condense extractively, never rewrite.
		$words = Normalizer::word_count( $script );
		if ( $words > $target ) {
			$script                = ExtractiveSummarizer::summarize( $script, $target );
			$stats['trimmed_from'] = $words;
		}

		return array(
			'script' => $script,
			'model'  => $model,
			'stats'  => $stats,
		);
	}

	/**
	 * Working dossier of the sources (cached by content + provider + model).
	 *
	 * @param array<string,mixed> $corpus   Corpus.
	 * @param string              $sources  SOURCE blocks.
	 * @param string              $system   System prompt.
	 * @param AIProviderInterface $provider Provider.
	 * @param array<string,mixed> $stats    Stats (by ref).
	 * @param string              $model    Model (by ref).
	 * @return string|WP_Error Dossier text ('' when the model returned nothing usable).
	 */
	private function analyse( array $corpus, string $sources, string $system, AIProviderInterface $provider, array &$stats, string &$model ) {
		$key    = 'tn_dossier_' . md5( $sources . '|' . $provider->id() . '|' . $model . '|' . SourceHasher::PROMPT_VERSION );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			$stats['analysis'] = 1;
			return $cached;
		}
		$user = PromptLoader::fill(
			PromptLoader::load( 'analysis' ),
			array(
				'title'      => (string) $corpus['title'],
				'collection' => (string) $corpus['collection_name'],
				'sources'    => $sources,
			)
		);
		if ( '' === trim( $user ) ) {
			return '';
		}
		$stats['chars_in'] += mb_strlen( $user );
		$result             = $this->call( $provider, $system, $user, $stats, $model, $this->max_tokens_for( 1200 ) );
		if ( is_wp_error( $result ) ) {
			return in_array( $result->get_error_code(), array( 'tn_ai_retryable', 'tn_locked' ), true ) ? $result : '';
		}
		$dossier = trim( $this->strip_markup( $result ) );
		if ( mb_strlen( $dossier ) < 80 ) {
			return '';
		}
		$stats['analysis'] = 1;
		set_transient( $key, $dossier, self::CACHE_TTL );
		return $dossier;
	}

	/**
	 * Replaces long document/attachment texts by faithful reductions.
	 *
	 * @param array<string,mixed> $corpus   Corpus copy.
	 * @param int                 $chunk    Chunk size.
	 * @param AIProviderInterface $provider Provider.
	 * @param string              $language Language.
	 * @param array<string,mixed> $stats    Stats (by ref).
	 * @param string              $model    Model (by ref, updated from responses).
	 * @return array<string,mixed>|WP_Error
	 */
	private function reduce_long_texts( array $corpus, int $chunk, AIProviderInterface $provider, string $language, array &$stats, string &$model ) {
		$targets = array();
		if ( ! empty( $corpus['document']['text'] ) ) {
			$targets[] = array( 'document' );
		}
		foreach ( array_keys( (array) $corpus['attachments'] ) as $i ) {
			if ( ! empty( $corpus['attachments'][ $i ]['text'] ) ) {
				$targets[] = array( 'attachments', $i );
			}
		}
		$system = PromptLoader::system( $language );

		foreach ( $targets as $path ) {
			$text = 1 === count( $path ) ? (string) $corpus['document']['text'] : (string) $corpus['attachments'][ $path[1] ]['text'];
			if ( mb_strlen( $text ) <= $chunk ) {
				continue;
			}
			$pieces           = Chunker::chunk( $text, $chunk );
			$total            = count( $pieces );
			$stats['chunks'] += $total;
			$summaries        = array();
			foreach ( $pieces as $index => $piece ) {
				$key    = 'tn_chunk_' . md5( $piece . '|' . $provider->id() . '|' . $model . '|' . SourceHasher::PROMPT_VERSION );
				$cached = get_transient( $key );
				if ( is_string( $cached ) && '' !== $cached ) {
					$summaries[] = $cached;
					continue;
				}
				$user               = PromptLoader::fill(
					PromptLoader::load( 'chunk-summary' ),
					array(
						'index'   => $index + 1,
						'total'   => $total,
						'title'   => (string) $corpus['title'],
						'sources' => '<<<SOURCE chunk:' . ( $index + 1 ) . ">>>\n" . $piece . "\n<<<END_SOURCE>>>",
					)
				);
				$stats['chars_in'] += mb_strlen( $user );
				$result             = $this->call( $provider, $system, $user, $stats, $model, $this->max_tokens_for( (int) ceil( Normalizer::word_count( $piece ) / 2 ) + 100 ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$summary     = $this->strip_markup( $result );
				$summaries[] = $summary;
				set_transient( $key, $summary, self::CACHE_TTL );
			}

			$merged = implode( "\n\n", $summaries );
			if ( count( $summaries ) > 1 && mb_strlen( $merged ) > $chunk * 2 ) {
				$blocks = array();
				foreach ( $summaries as $i => $s ) {
					$blocks[] = '<<<SOURCE reduction:' . ( $i + 1 ) . ">>>\n" . $s . "\n<<<END_SOURCE>>>";
				}
				$user               = PromptLoader::fill(
					PromptLoader::load( 'consolidate' ),
					array(
						'title'   => (string) $corpus['title'],
						'sources' => implode( "\n\n", $blocks ),
					)
				);
				$stats['chars_in'] += mb_strlen( $user );
				$result             = $this->call( $provider, $system, $user, $stats, $model, $this->max_tokens_for( (int) ceil( Normalizer::word_count( $merged ) * 0.8 ) + 100 ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$merged = $this->strip_markup( $result );
			}

			if ( 1 === count( $path ) ) {
				$corpus['document']['text'] = $merged;
			} else {
				$corpus['attachments'][ $path[1] ]['text'] = $merged;
			}
		}
		return $corpus;
	}

	/**
	 * Output budget for a target length (pt-BR ≈ 2.4 tokens/word + margin).
	 *
	 * @param int $words Target words.
	 * @return int
	 */
	private function max_tokens_for( int $words ): int {
		$configured = (int) Options::get( 'ai_max_tokens', 4000 );
		return max( min( $configured, 4000 ), (int) ceil( $words * 2.4 ) + 400 );
	}

	/**
	 * One provider call with stats bookkeeping.
	 *
	 * @param AIProviderInterface $provider   Provider.
	 * @param string              $system     System prompt.
	 * @param string              $user       User prompt.
	 * @param array<string,mixed> $stats      Stats (by ref).
	 * @param string              $model      Model (by ref).
	 * @param int                 $max_tokens Output budget.
	 * @return string|WP_Error Raw text.
	 */
	private function call( AIProviderInterface $provider, string $system, string $user, array &$stats, string &$model, int $max_tokens ) {
		++$stats['ai_calls'];
		$result = $provider->generate( $system, $user, array( 'max_tokens' => $max_tokens ) );
		if ( is_wp_error( $result ) ) {
			Logger::warning(
				'AI call failed',
				array(
					'provider' => $provider->id(),
					'code'     => $result->get_error_code(),
				)
			);
			return $result;
		}
		$stats['prompt_tokens']     += (int) ( $result['usage']['prompt_tokens'] ?? 0 );
		$stats['completion_tokens'] += (int) ( $result['usage']['completion_tokens'] ?? 0 );
		if ( ! empty( $result['model'] ) ) {
			$model = (string) $result['model'];
		}
		return (string) $result['text'];
	}

	/**
	 * Removes markdown, code fences, headings, bullets and leaked delimiters.
	 *
	 * @param string $text Raw output.
	 * @return string
	 */
	private function strip_markup( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/^\s*(narrativa|roteiro|texto|script|narração|dossiê)\s*:\s*/iu', '', $text ) ?? $text;
		$text = preg_replace( '/^\s*(claro|certo|ok|perfeito|com certeza|segue|aqui está|aqui vai)[^\n]{0,80}:\s*\n+/iu', '', $text ) ?? $text;
		$text = preg_replace( '/<think>.*?<\/think>/isu', '', $text ) ?? $text;
		$text = preg_replace( '/^.*<<<\s*(END_)?SOURCE.*$/mu', '', $text ) ?? $text;
		$text = preg_replace( '/^```[a-z]*\s*$/mu', '', $text ) ?? $text;
		$text = preg_replace( '/^[ \t]*#{1,6}\s*/mu', '', $text ) ?? $text;
		$text = preg_replace( '/(\*\*|__)(.*?)\1/su', '$2', $text ) ?? $text;
		$text = preg_replace( '/(?<!\w)[\*_](\S[^*_\n]*?)[\*_](?!\w)/u', '$1', $text ) ?? $text;
		$text = preg_replace( '/^\s*[\-\*\•]\s+/mu', '', $text ) ?? $text;
		$text = preg_replace( '/^\s*\d+[\.\)]\s+/mu', '', $text ) ?? $text;
		$text = preg_replace( '/\n+\s*(fim(?: da narrativa| do roteiro| da narração)?|fim\.)\s*$/iu', '', $text ) ?? $text;
		return Normalizer::clean( $text );
	}

	/**
	 * Cleans model output for narration: markup removed, machine-sounding
	 * formulas dropped, duplicated title line removed.
	 *
	 * @param string $text Raw output.
	 * @return string
	 */
	public function post_process( string $text ): string {
		$text = $this->strip_markup( $text );

		$lines = explode( "\n", $text );
		if ( count( $lines ) > 1 ) {
			$first = trim( $lines[0] );
			if ( '' !== $first && mb_strlen( $first ) <= 90 && ! preg_match( '/[\.\!\?…:;,]$/u', $first ) && Normalizer::word_count( $first ) <= 14 ) {
				array_shift( $lines );
				$text = ltrim( implode( "\n", $lines ) );
			}
		}

		$openers = implode( '|', self::MACHINE_OPENERS );
		$text    = preg_replace_callback(
			'/(^|(?<=[\.\!\?…]\s)|(?<=\n))(?:' . $openers . ')\s*,?\s*(\p{L})/imu',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2] ),
			$text
		) ?? $text;

		return Normalizer::clean( $text );
	}
}
