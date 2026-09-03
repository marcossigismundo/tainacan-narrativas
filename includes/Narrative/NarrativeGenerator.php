<?php
/**
 * AI-assisted script generation (chunk → reduce → consolidate → mode prompt).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

use TainacanNarrativas\AI\AIProviderInterface;
use TainacanNarrativas\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-agnostic pipeline. Intermediate chunk reductions are cached in
 * transients keyed by (chunk, provider, model) so unchanged documents are
 * never re-summarized.
 */
final class NarrativeGenerator {

	/**
	 * Transient TTL for chunk reductions.
	 */
	private const CACHE_TTL = 7 * DAY_IN_SECONDS;

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
	 * @return array{script:string,model:string,stats:array<string,int>}|WP_Error
	 */
	public function generate( array $corpus, array $config, AIProviderInterface $provider ) {
		$mode     = Modes::sanitize( $config['mode'] ?? 'documentary' );
		$def      = Modes::get( $mode );
		$language = (string) ( $config['language'] ?? 'pt-BR' );
		$chunk    = max( 1500, (int) ( $config['chunk_size'] ?? 6000 ) );
		$stats    = array(
			'ai_calls'          => 0,
			'chunks'            => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'chars_in'          => 0,
		);
		$model    = $provider->model();

		// Privacy: attachments may be excluded from external AI per collection.
		$send_attachments = ! $provider->is_external() || ! empty( $config['external_ai_attachments'] );
		$working          = $corpus;
		if ( ! $send_attachments ) {
			$working['attachments'] = array();
		}

		// Reduce oversized documents before the mode prompt.
		$reduced = $this->reduce_long_texts( $working, $chunk, $provider, $language, $stats, $model );
		if ( is_wp_error( $reduced ) ) {
			return $reduced;
		}
		$working = $reduced;

		$sources            = $this->builder->sources_block( $working, true );
		$system             = PromptLoader::system( $language );
		$user               = PromptLoader::mode(
			$mode,
			array(
				'target_words' => (int) ( $def['target_words'] ?? 600 ) > 0 ? (int) $def['target_words'] : 1200,
				'title'        => (string) $working['title'],
				'collection'   => (string) $working['collection_name'],
				'language'     => $language,
				'sources'      => $sources,
			)
		);
		$stats['chars_in'] += mb_strlen( $user );

		$result = $this->call( $provider, $system, $user, $stats, $model );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$script = $this->post_process( $result );
		if ( mb_strlen( $script ) < 40 ) {
			return new WP_Error( 'tn_ai_malformed', __( 'A IA devolveu um roteiro vazio ou inutilizável.', 'tainacan-narrativas' ) );
		}
		return array(
			'script' => $script,
			'model'  => $model,
			'stats'  => $stats,
		);
	}

	/**
	 * Replaces long document/attachment texts by factual reductions.
	 *
	 * @param array<string,mixed> $corpus   Corpus copy.
	 * @param int                 $chunk    Chunk size.
	 * @param AIProviderInterface $provider Provider.
	 * @param string              $language Language.
	 * @param array<string,int>   $stats    Stats (by ref).
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
				$result             = $this->call( $provider, $system, $user, $stats, $model );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$summary     = $this->post_process( $result );
				$summaries[] = $summary;
				set_transient( $key, $summary, self::CACHE_TTL );
			}

			$merged = implode( "\n\n", $summaries );
			if ( count( $summaries ) > 1 && mb_strlen( $merged ) > $chunk ) {
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
				$result             = $this->call( $provider, $system, $user, $stats, $model );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$merged = $this->post_process( $result );
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
	 * One provider call with stats bookkeeping.
	 *
	 * @param AIProviderInterface $provider Provider.
	 * @param string              $system   System prompt.
	 * @param string              $user     User prompt.
	 * @param array<string,int>   $stats    Stats (by ref).
	 * @param string              $model    Model (by ref).
	 * @return string|WP_Error Raw text.
	 */
	private function call( AIProviderInterface $provider, string $system, string $user, array &$stats, string &$model ) {
		++$stats['ai_calls'];
		$result = $provider->generate( $system, $user );
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
	 * Cleans model output for narration: no markdown, no leaked delimiters.
	 *
	 * @param string $text Raw output.
	 * @return string
	 */
	public function post_process( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		// Drop a leading "Narrativa:"/"Roteiro:" style label first, so a heading after it is still at line start.
		$text = preg_replace( '/^\s*(narrativa|roteiro|texto|script)\s*:\s*/iu', '', $text ) ?? $text;
		// Remove any leaked SOURCE delimiters or lines that only contain them.
		$text = preg_replace( '/^.*<<<\s*(END_)?SOURCE.*$/mu', '', $text ) ?? $text;
		// Code fences, headings, emphasis, bullets.
		$text = preg_replace( '/^```[a-z]*\s*$/mu', '', $text ) ?? $text;
		$text = preg_replace( '/^[ \t]*#{1,6}\s*/mu', '', $text ) ?? $text;
		$text = preg_replace( '/(\*\*|__)(.*?)\1/su', '$2', $text ) ?? $text;
		$text = preg_replace( '/(?<!\w)[\*_](\S[^*_\n]*?)[\*_](?!\w)/u', '$1', $text ) ?? $text;
		$text = preg_replace( '/^\s*[\-\*\•]\s+/mu', '', $text ) ?? $text;
		$text = preg_replace( '/^\s*\d+[\.\)]\s+/mu', '', $text ) ?? $text;
		return Normalizer::clean( $text );
	}
}
