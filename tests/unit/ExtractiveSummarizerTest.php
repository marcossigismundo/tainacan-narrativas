<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Narrative\ExtractiveSummarizer;
use TainacanNarrativas\Narrative\MetadataPhraser;
use TainacanNarrativas\Narrative\Normalizer;

final class ExtractiveSummarizerTest extends TestCase {

	private function document(): string {
		$filler = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$filler[] = 'Este parágrafo contém apenas considerações gerais sem nomes ou datas que se repetem ao longo do texto.';
		}
		return implode(
			"\n\n",
			array(
				'Me chamo Ana Manoela e sou indígena Karipuna, filha de Suzana Primo dos Santos, da aldeia Santa Izabel.',
				implode( ' ', array_slice( $filler, 0, 20 ) ),
				'Em 17 de abril de 2020, o primeiro caso de covid chegou à aldeia Santa Izabel, no Oiapoque, e Suzana Primo dos Santos adoeceu.',
				'Página 3 de 12. Copyright 2021. Todos os direitos reservados.',
				implode( ' ', array_slice( $filler, 20, 20 ) ),
				'"É sufocante a sensação de não saber quando isto irá acabar", escreveu Ana Manoela no diário da aldeia em maio de 2020.',
			)
		);
	}

	public function test_keeps_salient_sentences_in_order_within_budget(): void {
		$doc = $this->document();
		$out = ExtractiveSummarizer::summarize( $doc, 80 );
		$this->assertLessThanOrEqual( 110, Normalizer::word_count( $out ) );
		$this->assertStringStartsWith( 'Me chamo Ana Manoela', $out );
		$this->assertStringContainsString( '17 de abril de 2020', $out );
		$this->assertStringContainsString( 'sufocante', $out );
		$this->assertStringNotContainsString( 'Copyright', $out );
		$this->assertLessThan( strpos( $out, 'sufocante' ), strpos( $out, '17 de abril' ) );
	}

	public function test_short_text_is_returned_unchanged(): void {
		$this->assertSame( 'Uma frase. Outra frase.', ExtractiveSummarizer::summarize( 'Uma frase. Outra frase.', 100 ) );
		$this->assertSame( 'abc', ExtractiveSummarizer::summarize( 'abc', 0 ) );
	}

	public function test_metadata_phraser(): void {
		$this->assertSame( 'De autoria de Maria Silva.', MetadataPhraser::sentence( 'Autoria', 'Maria Silva.' ) );
		$this->assertSame( 'O registro data de 12 de outubro de 1918.', MetadataPhraser::sentence( 'Data', '1918-10-12', 'Date' ) );
		$this->assertSame( 'Trata de saúde, memória e pandemia.', MetadataPhraser::sentence( 'Assuntos', 'saúde|memória|pandemia' ) );
		$this->assertSame( 'Está situado em Oiapoque, Amapá.', MetadataPhraser::sentence( 'Local', 'Oiapoque > Amapá' ) );
		$this->assertSame( 'Campo raro: valor.', MetadataPhraser::sentence( 'Campo raro', 'valor' ) );
		$this->assertSame( '', MetadataPhraser::sentence( 'URL', 'https://x.org' ) );
		$this->assertSame( 'Relato completo do depoimento.', MetadataPhraser::sentence( 'Âmbito e conteúdo', 'Relato completo do depoimento' ) );
	}
}
