<?php
/**
 * Builds narration scripts from a corpus (template engine, no AI).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two responsibilities:
 *
 *  - build(): the "documentary reading" script (title + description +
 *    metadata + document + attachments) using a configurable template. This
 *    is what plays when no AI is configured, and the base for the "faithful"
 *    mode. Long documents are condensed by an extractive summarizer that
 *    keeps the salient sentences in reading order (names, dates, places,
 *    numbers, quotes) instead of cutting the text after N words; metadata
 *    are phrased as sentences instead of "Label: value" (except in the
 *    faithful mode, which reads them as they are).
 *  - sources_block(): the SOURCE-delimited corpus handed to AI providers,
 *    so every sentence of a generated narrative is traceable to a source id.
 */
final class ScriptBuilder {

	/**
	 * Default template (pt-BR). Lines whose placeholders resolve to nothing are dropped.
	 *
	 * @return string
	 */
	public static function default_template(): string {
		return implode(
			"\n",
			array(
				__( 'Você está ouvindo o registro "{title}".', 'tainacan-narrativas' ),
				__( 'Este item integra a coleção {collection}.', 'tainacan-narrativas' ),
				'',
				'{description}',
				'',
				'{metadata}',
				'',
				'{document_intro}',
				'{document}',
				'',
				'{attachments}',
				'',
				'{closing}',
			)
		);
	}

	/**
	 * Builds the no-AI script for a mode.
	 *
	 * @param array<string,mixed> $corpus   Corpus.
	 * @param string              $mode     Mode id.
	 * @param string              $template Template (empty = default).
	 * @return string
	 */
	public function build( array $corpus, string $mode, string $template = '' ): string {
		$def          = Modes::get( $mode );
		$target_words = (int) ( $def['target_words'] ?? 0 );
		$faithful     = 'faithful' === $mode;
		$template     = '' !== trim( $template ) ? $template : self::default_template();

		$description = trim( (string) ( $corpus['description'] ?? '' ) );
		$doc_text    = trim( (string) ( $corpus['document']['text'] ?? '' ) );
		$truncated   = false;

		// Budget: the document gets what is left after description and metadata.
		$doc_budget = $target_words;
		if ( $target_words > 0 ) {
			$doc_budget = max( 120, $target_words - Normalizer::word_count( $description ) - 40 );
		}
		if ( $doc_budget > 0 && '' !== $doc_text && Normalizer::word_count( $doc_text ) > $doc_budget ) {
			$doc_text  = $faithful ? $this->limit_words( $doc_text, $doc_budget ) : ExtractiveSummarizer::summarize( $doc_text, $doc_budget );
			$truncated = true;
		}

		$attachments = array();
		$remaining   = $target_words > 0 ? max( 0, $target_words - Normalizer::word_count( $doc_text ) - Normalizer::word_count( $description ) ) : 0;
		foreach ( (array) ( $corpus['attachments'] ?? array() ) as $i => $att ) {
			$text = trim( (string) ( $att['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( $target_words > 0 ) {
				if ( $remaining < 40 ) {
					$truncated = true;
					break;
				}
				if ( Normalizer::word_count( $text ) > $remaining ) {
					$text      = $faithful ? $this->limit_words( $text, $remaining ) : ExtractiveSummarizer::summarize( $text, $remaining );
					$truncated = true;
				}
				$remaining -= Normalizer::word_count( $text );
			}
			$name = (string) ( $att['filename'] ?? '' );
			/* translators: 1: attachment number, 2: file name. */
			$attachments[] = sprintf( __( 'Anexo %1$d, %2$s:', 'tainacan-narrativas' ), (int) $i + 1, $this->speakable_filename( $name ) ) . "\n" . $text;
		}

		$vars = array(
			'title'          => (string) ( $corpus['title'] ?? '' ),
			'collection'     => (string) ( $corpus['collection_name'] ?? '' ),
			'description'    => $description,
			'metadata'       => $faithful ? $this->metadata_sentences( $corpus ) : $this->metadata_prose( $corpus ),
			'document_intro' => '' !== $doc_text ? ( $truncated && ! $faithful ? __( 'Dos documentos que acompanham este registro, destacam-se as seguintes passagens:', 'tainacan-narrativas' ) : __( 'A documentação associada ao item registra o seguinte:', 'tainacan-narrativas' ) ) : '',
			'document'       => $doc_text,
			'attachments'    => implode( "\n\n", $attachments ),
			'closing'        => $truncated ? __( 'Este registro contém mais informações do que as narradas aqui. Consulte a página do item para o conteúdo completo.', 'tainacan-narrativas' ) : '',
		);

		$lines = explode( "\n", $template );
		$out   = array();
		foreach ( $lines as $line ) {
			if ( preg_match_all( '/\{([a-z_]+)\}/', $line, $m ) ) {
				$empty = true;
				foreach ( $m[1] as $key ) {
					if ( '' !== trim( (string) ( $vars[ $key ] ?? '' ) ) ) {
						$empty = false;
					}
				}
				if ( $empty ) {
					continue;
				}
			}
			$out[] = PromptLoader::fill( $line, $vars );
		}
		return Normalizer::clean( implode( "\n", $out ) );
	}

	/**
	 * "Label: value." sentences for the metadata (faithful reading).
	 *
	 * @param array<string,mixed> $corpus Corpus.
	 * @return string
	 */
	public function metadata_sentences( array $corpus ): string {
		$lines = array();
		foreach ( (array) ( $corpus['metadata'] ?? array() ) as $m ) {
			$label = trim( (string) $m['label'] );
			$value = trim( (string) $m['value'] );
			if ( '' === $value ) {
				continue;
			}
			$value   = rtrim( $value, '.;,' );
			$lines[] = $label . ': ' . $value . '.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Metadata phrased as spoken sentences (documentary reading).
	 *
	 * @param array<string,mixed> $corpus Corpus.
	 * @return string
	 */
	public function metadata_prose( array $corpus ): string {
		$lines = array();
		foreach ( (array) ( $corpus['metadata'] ?? array() ) as $m ) {
			$sentence = MetadataPhraser::sentence( (string) ( $m['label'] ?? '' ), (string) ( $m['value'] ?? '' ), (string) ( $m['type'] ?? '' ) );
			if ( '' !== $sentence ) {
				$lines[] = $sentence;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * SOURCE-delimited corpus for AI. Sources are untrusted data; the system
	 * prompt tells the model never to follow instructions found inside them.
	 *
	 * @param array<string,mixed> $corpus              Corpus.
	 * @param bool                $include_attachments Whether attachment texts may be sent.
	 * @return string
	 */
	public function sources_block( array $corpus, bool $include_attachments = true ): string {
		$blocks   = array();
		$blocks[] = $this->source( 'metadata:title', (string) $corpus['title'] );
		if ( '' !== trim( (string) $corpus['description'] ) ) {
			$blocks[] = $this->source( 'metadata:description', (string) $corpus['description'] );
		}
		if ( '' !== trim( (string) ( $corpus['collection_name'] ?? '' ) ) ) {
			$blocks[] = $this->source( 'metadata:collection', (string) $corpus['collection_name'] );
		}
		foreach ( (array) $corpus['metadata'] as $m ) {
			$blocks[] = $this->source( 'metadata:' . $m['id'] . ' (' . $m['label'] . ')', (string) $m['value'] );
		}
		if ( ! empty( $corpus['document']['text'] ) ) {
			$blocks[] = $this->source( 'document:' . (int) $corpus['document']['id'], (string) $corpus['document']['text'] );
		}
		if ( $include_attachments ) {
			foreach ( (array) $corpus['attachments'] as $att ) {
				if ( ! empty( $att['text'] ) ) {
					$blocks[] = $this->source( 'attachment:' . (int) $att['id'], (string) $att['text'] );
				}
			}
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * One delimited source.
	 *
	 * @param string $id   Source id.
	 * @param string $text Text.
	 * @return string
	 */
	private function source( string $id, string $text ): string {
		$text = str_replace( array( '<<<SOURCE', 'END_SOURCE>>>' ), array( '< < <SOURCE', 'END_SOURCE> > >' ), $text );
		return "<<<SOURCE {$id}>>>\n" . trim( $text ) . "\n<<<END_SOURCE>>>";
	}

	/**
	 * Cuts text to ~N words at a sentence boundary (faithful mode only).
	 *
	 * @param string $text  Text.
	 * @param int    $words Target words.
	 * @return string
	 */
	private function limit_words( string $text, int $words ): string {
		if ( $words <= 0 || Normalizer::word_count( $text ) <= $words ) {
			return $text;
		}
		$out   = array();
		$count = 0;
		foreach ( Normalizer::sentences( $text ) as $sentence ) {
			$n = Normalizer::word_count( $sentence );
			if ( $count + $n > $words && $count > 0 ) {
				break;
			}
			$out[]  = $sentence;
			$count += $n;
			if ( $count >= $words ) {
				break;
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Makes a file name pleasant to hear ("relatorio_final.pdf" → "relatorio final, PDF").
	 *
	 * @param string $filename File name.
	 * @return string
	 */
	private function speakable_filename( string $filename ): string {
		if ( '' === $filename ) {
			return __( 'arquivo', 'tainacan-narrativas' );
		}
		$ext  = strtoupper( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$base = pathinfo( $filename, PATHINFO_FILENAME );
		$base = preg_replace( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-/i', '', $base ) ?? $base; // DIP importer UUID prefix.
		$base = trim( (string) preg_replace( '/[_\-\.]+/', ' ', $base ) );
		return '' !== $ext ? $base . ', ' . $ext : $base;
	}
}
