<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Narrative\Chunker;

final class ChunkerTest extends TestCase {

	public function test_short_text_is_single_chunk(): void {
		$this->assertSame( array( 'abc' ), Chunker::chunk( 'abc', 1000 ) );
		$this->assertSame( array(), Chunker::chunk( "  \n ", 1000 ) );
	}

	public function test_chunks_respect_size_and_prefer_paragraphs(): void {
		$para   = str_repeat( 'Palavra ', 60 ); // ~480 chars
		$text   = implode( "\n\n", array_fill( 0, 10, trim( $para ) ) );
		$chunks = Chunker::chunk( $text, 1000 );
		$this->assertGreaterThan( 3, count( $chunks ) );
		foreach ( $chunks as $c ) {
			$this->assertLessThanOrEqual( 1000, mb_strlen( $c ) );
			$this->assertStringStartsWith( 'Palavra', $c );
		}
		$this->assertSame( str_replace( "\n\n", ' ', $text ), str_replace( "\n\n", ' ', implode( ' ', $chunks ) ) );
	}

	public function test_oversized_paragraph_is_split_by_sentences_then_words(): void {
		$sentence = 'Esta é uma frase razoavelmente longa para o teste. ';
		$block    = str_repeat( $sentence, 100 ); // one paragraph, ~5100 chars
		$chunks   = Chunker::chunk( $block, 800 );
		foreach ( $chunks as $c ) {
			$this->assertLessThanOrEqual( 800, mb_strlen( $c ) );
		}
		$giant = str_repeat( 'x', 3000 );
		$parts = Chunker::chunk( $giant, 1000 );
		$this->assertCount( 3, $parts );
	}
}
