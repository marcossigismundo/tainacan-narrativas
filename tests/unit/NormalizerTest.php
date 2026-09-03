<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Narrative\Normalizer;

final class NormalizerTest extends TestCase {

	public function test_clean_collapses_whitespace_and_keeps_paragraphs(): void {
		$in  = "Linha  um.\r\n\r\n\r\n\tLinha   dois.\n";
		$out = Normalizer::clean( $in );
		$this->assertSame( "Linha um.\n\nLinha dois.", $out );
	}

	public function test_clean_removes_control_chars_and_duplicate_lines(): void {
		$in  = "Cabeçalho\nCabeçalho\nTexto\x00 com controle\nCabeçalho";
		$out = Normalizer::clean( $in );
		$this->assertStringNotContainsString( "\x00", $out );
		$this->assertSame( "Cabeçalho\nTexto com controle\nCabeçalho", $out );
	}

	public function test_clean_dehyphenates_line_breaks(): void {
		$this->assertSame( 'documento histórico', Normalizer::clean( "docu-\nmento histórico" ) );
	}

	public function test_unwrap_lines_joins_pdf_wrapping_but_keeps_paragraphs_and_lists(): void {
		$in  = "É sufocante a sensação de não saber quando isto\nirá acabar.\nAna Manoela Primo dos Santos\nMe chamo Ana Manoela e sou indígena Karipuna, filha de Suzana\nPrimo dos Santos, da aldeia Santa Izabel.\n\nSegundo parágrafo.\nNome: Fulano\nData: 1918";
		$out = Normalizer::unwrap_lines( $in );
		$this->assertStringContainsString( 'quando isto irá acabar.', $out );
		$this->assertStringContainsString( 'filha de Suzana Primo dos Santos', $out );
		$this->assertStringContainsString( "irá acabar.\nAna Manoela Primo dos Santos", $out );
		$this->assertStringContainsString( "\n\nSegundo parágrafo.\nNome: Fulano\nData: 1918", $out );
	}

	public function test_html_to_text_strips_scripts_and_keeps_paragraphs(): void {
		$html = '<p>Olá <b>mundo</b></p><script>alert(1)</script><style>p{}</style><p>Segundo &amp; último</p>';
		$this->assertSame( "Olá mundo\n\nSegundo & último", Normalizer::html_to_text( $html ) );
	}

	public function test_to_utf8_converts_latin1(): void {
		$latin1 = mb_convert_encoding( 'ação', 'ISO-8859-1', 'UTF-8' );
		$this->assertSame( 'ação', Normalizer::to_utf8( $latin1 ) );
		$this->assertSame( 'já', Normalizer::to_utf8( "\xEF\xBB\xBFjá" ) );
	}

	public function test_truncate_prefers_sentence_boundary(): void {
		$text = str_repeat( 'Frase curta. ', 20 );
		$out  = Normalizer::truncate( $text, 100 );
		$this->assertLessThanOrEqual( 100, mb_strlen( $out ) );
		$this->assertStringEndsWith( '.', $out );
		$this->assertSame( 'abc', Normalizer::truncate( 'abc', 0 ) );
	}

	public function test_sentences_split_on_punctuation(): void {
		$s = Normalizer::sentences( 'Primeira frase. Segunda frase! Terceira? Quarta: sim.' );
		$this->assertCount( 4, $s );
		$this->assertSame( 'Segunda frase!', $s[1] );
	}

	public function test_word_count_and_estimates(): void {
		$text = 'Uma frase com sete palavras aqui mesmo.';
		$this->assertSame( 7, Normalizer::word_count( $text ) );
		$this->assertGreaterThan( 0, Normalizer::estimate_tokens( $text ) );
		$this->assertEqualsWithDelta( 2.8, Normalizer::estimate_duration( $text ), 0.1 );
	}
}
