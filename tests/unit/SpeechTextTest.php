<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Narrative\SpeechText;

final class SpeechTextTest extends TestCase {

	public function test_dates_abbreviations_and_states_are_spoken(): void {
		$out = SpeechText::for_speech( 'O Dr. João Silva chegou em 12/03/2020 à UBS de São Paulo (SP). Ele tinha 3.500 reais e 45% do total.' );
		$this->assertStringContainsString( 'Doutor João Silva', $out );
		$this->assertStringContainsString( '12 de março de 2020', $out );
		$this->assertStringContainsString( '(São Paulo)', $out );
		$this->assertStringContainsString( '3500 reais', $out );
		$this->assertStringContainsString( '45 por cento', $out );
		$this->assertStringNotContainsString( 'Dr.', $out );
	}

	public function test_hyphenated_codes_ranges_urls_and_emails(): void {
		$out = SpeechText::for_speech( 'A pandemia de COVID-19 atingiu o RS entre 2020-2021; ver www.exemplo.org/doc.pdf e contato@x.org.' );
		$this->assertStringContainsString( 'covid 19', $out );
		$this->assertStringContainsString( 'Rio Grande do Sul', $out );
		$this->assertStringContainsString( '2020 a 2021', $out );
		$this->assertStringContainsString( 'endereço eletrônico', $out );
		$this->assertStringContainsString( 'endereço de e-mail', $out );
		$this->assertStringNotContainsString( 'www', $out );
		$this->assertStringNotContainsString( '@', $out );
	}

	public function test_shouting_text_roman_numerals_ordinals_and_currency(): void {
		$out = SpeechText::for_speech( 'RELATÓRIO FINAL DA COMISSÃO DE SAÚDE — BRASÍLIA.' );
		$this->assertSame( 'Relatório final da comissão de saúde, brasília.', $out );

		$out = SpeechText::for_speech( 'Art. 5º e 2ª edição, Pág. 12, séc. XX, R$ 1.200,50, 14h30, Dom Pedro II e Luís XIV.' );
		$this->assertStringContainsString( 'Artigo quinto', $out );
		$this->assertStringContainsString( 'segunda edição', $out );
		$this->assertStringContainsString( 'Página 12', $out );
		$this->assertStringContainsString( 'século vinte', $out );
		$this->assertStringContainsString( '1200,50 reais', $out );
		$this->assertStringContainsString( '14 horas e 30 minutos', $out );
		$this->assertStringContainsString( 'Dom Pedro segundo', $out );
		$this->assertStringContainsString( 'Luís quatorze', $out );
	}

	public function test_never_invents_words_from_initials_or_ambiguous_abbreviations(): void {
		$this->assertSame( 'Depoimento de V. S. R. Silva.', SpeechText::for_speech( 'Depoimento de V. S. R. Silva.' ) );
		$this->assertSame( 'Depoimento de V.S.R.', SpeechText::for_speech( 'Depoimento de V.S.R.' ) );
		$this->assertSame( 'Assinado por Fulano L. e Beltrana C. em 1920.', SpeechText::for_speech( 'Assinado por Fulano L. e Beltrana C. em 1920.' ) );
		$this->assertSame( 'O Cap. João e a Sec. de Saúde; ver p. 3 e ed. anterior.', SpeechText::for_speech( 'O Cap. João e a Sec. de Saúde; ver p. 3 e ed. anterior.' ) );
		$this->assertSame( 'Art. de opinião e artigo 5 da lei.', SpeechText::for_speech( 'Art. de opinião e art. 5 da lei.' ), 'article expands only before a number' );
		$this->assertSame( 'Não sei SE ele vai PARA lá; TO cansado.', SpeechText::for_speech( 'Não sei SE ele vai PARA lá; TO cansado.' ), 'ambiguous state codes need a place context' );
		$this->assertStringContainsString( 'Belém (Pará)', SpeechText::for_speech( 'Nascida em Belém (PA).' ) );
		$this->assertStringContainsString( 'Aracaju - Sergipe', SpeechText::for_speech( 'Aracaju - SE, 2020.' ) );
		$this->assertSame( 'Ligue 3222 1234.', SpeechText::for_speech( 'Ligue 3222-1234.' ), 'phone numbers are not year ranges ("a")' );
	}

	public function test_acronyms_without_vowels_are_spelled_and_words_kept(): void {
		$out = SpeechText::for_speech( 'A OMS e a UBS avisaram; o PDF do CNPJ chegou. Não se sabe se ele voltou.' );
		$this->assertStringContainsString( 'o m s', $out );
		$this->assertStringContainsString( 'UBS', $out );
		$this->assertStringContainsString( 'P D F', $out );
		$this->assertStringContainsString( 'C N P J', $out );
		$this->assertStringContainsString( 'Não se sabe se ele voltou.', $out );
	}

	public function test_sentences_keep_abbreviations_initials_and_decimals_together(): void {
		$s = SpeechText::sentences( 'O Dr. Silva e J. Souza gastaram 3.500 reais em 1918. Depois, o silêncio. Autoria: Maria Silva.' );
		$this->assertSame(
			array( 'O Dr. Silva e J. Souza gastaram 3.500 reais em 1918.', 'Depois, o silêncio.', 'Autoria: Maria Silva.' ),
			$s
		);
		$this->assertSame( array( 'Ele disse: "Isso vai passar".', 'Fim.' ), SpeechText::sentences( 'Ele disse: "Isso vai passar". Fim.' ) );
		$this->assertSame(
			array( '58. É sufocante a sensação de não saber quando isto irá acabar', 'Ana Manoela Primo dos Santos', 'Me chamo Ana Manoela e sou indígena.' ),
			SpeechText::sentences( "58. É sufocante a sensação de não saber quando isto irá acabar\nAna Manoela Primo dos Santos\nMe chamo Ana Manoela e sou indígena." ),
			'surviving line breaks (titles, signatures) are boundaries'
		);
	}

	public function test_utterances_cut_at_clauses_and_never_exceed_limit(): void {
		$long  = 'Me chamo Ana Manoela e sou indígena Karipuna, filha de Suzana Primo dos Santos, da aldeia Santa Izabel, uma comunidade que fica próxima ao rio Oiapoque, no estado do Amapá, e que enfrentou a chegada do vírus com muito medo e pouca informação.';
		$parts = SpeechText::utterances( $long, 120 );
		$this->assertGreaterThan( 1, count( $parts ) );
		foreach ( $parts as $p ) {
			$this->assertLessThanOrEqual( 140, mb_strlen( $p ) );
			$this->assertMatchesRegularExpression( '/[,;:\)\.]$/u', $p );
		}
		$this->assertSame( $long, implode( ' ', $parts ) );
		$this->assertSame( array( 'Curta.' ), SpeechText::utterances( 'Curta.' ) );
	}

	public function test_segments_group_by_paragraph_and_expose_speech_variants(): void {
		$seg = SpeechText::segments( "Você está ouvindo o registro \"Carta de 1918\".\nEste item integra a coleção X.\n\nData: 1918-10-12." );
		$this->assertCount( 2, $seg );
		$this->assertCount( 2, $seg[0] );
		$this->assertSame( 'Você está ouvindo o registro "Carta de 1918".', $seg[0][0]['text'] );
		$this->assertSame( 'Você está ouvindo o registro Carta de 1918.', $seg[0][0]['speech'] );
		$this->assertSame( 'Data: 12 de outubro de 1918.', $seg[1][0]['speech'] );
		$this->assertSame( array( 'Data: 12 de outubro de 1918.' ), $seg[1][0]['parts'] );
		$this->assertStringContainsString( "\n\n", SpeechText::script_for_speech( "Um.\n\nDois." ) );
	}

	public function test_number_helpers(): void {
		$this->assertSame( 1998, SpeechText::roman_to_int( 'MCMXCVIII' ) );
		$this->assertSame( 0, SpeechText::roman_to_int( 'IIII' ) );
		$this->assertSame( 'mil novecentos e noventa e oito', SpeechText::cardinal_words( 1998 ) );
		$this->assertSame( 'cem', SpeechText::cardinal_words( 100 ) );
		$this->assertSame( 'vinte e um', SpeechText::cardinal_words( 21 ) );
	}
}
