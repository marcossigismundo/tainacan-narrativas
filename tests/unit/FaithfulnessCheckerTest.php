<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Narrative\FaithfulnessChecker;

final class FaithfulnessCheckerTest extends TestCase {

	private function corpus(): array {
		return array(
			'title'           => 'Carta de Ana Manoela',
			'description'     => 'Depoimento escrito durante a pandemia.',
			'collection_name' => 'Memorial COVID-19',
			'metadata'        => array(
				array( 'id' => 1, 'label' => 'Autoria', 'value' => 'Ana Manoela Primo dos Santos' ),
				array( 'id' => 2, 'label' => 'Data', 'value' => '2020-04-17' ),
				array( 'id' => 3, 'label' => 'Local', 'value' => 'Oiapoque' ),
			),
			'document'        => array( 'id' => 10, 'text' => 'Me chamo Ana Manoela e sou indígena Karipuna, filha de Suzana Primo dos Santos, da aldeia Santa Izabel. Em 17 de abril de 2020 o vírus chegou à aldeia. Eram 3.500 pessoas na Terra Indígena Uaçá.' ),
			'attachments'     => array(),
		);
	}

	public function test_supported_sentences_pass(): void {
		$c = new FaithfulnessChecker( $this->corpus() );
		$r = $c->check( "Ana Manoela, indígena Karipuna, é filha de Suzana Primo dos Santos e vive na aldeia Santa Izabel.\n\nEla escreve que em 17 de abril de 2020 o vírus chegou à aldeia, onde viviam 3500 pessoas." );
		$this->assertTrue( $r['ok'], wp_json_encode( $r['unsupported'] ) );
		$this->assertSame( 2, $r['total'] );
	}

	public function test_invented_names_numbers_and_filler_are_flagged_and_stripped(): void {
		$c = new FaithfulnessChecker( $this->corpus() );
		$script = "Ana Manoela é filha de Suzana Primo dos Santos.\n\nNaquela manhã fria de inverno, o silêncio pesava sobre as ruas vazias e o medo tomava conta de todos.\n\nO médico Carlos Alberto atendeu 42 pacientes em Macapá.\n\nEm 17 de abril de 2020 o vírus chegou à aldeia.";
		$r = $c->check( $script );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 2, $r['flagged'] );
		$this->assertContains( 'Carlos', $r['unsupported'] );
		$this->assertContains( 'Macapá', $r['unsupported'] );
		$this->assertContains( '42', $r['unsupported'] );

		$s = $c->strip( $script );
		$this->assertCount( 2, $s['removed'] );
		$this->assertStringContainsString( 'Ana Manoela é filha', $s['script'] );
		$this->assertStringContainsString( '17 de abril de 2020', $s['script'] );
		$this->assertStringNotContainsString( 'Carlos', $s['script'] );
		$this->assertStringNotContainsString( 'inverno', $s['script'] );
	}

	public function test_sentence_start_capitals_months_and_inflections_are_not_names(): void {
		$c = new FaithfulnessChecker( $this->corpus() );
		$r = $c->check_sentence( 'Durante a pandemia, em abril, a família de Suzana ficou na aldeia; o depoimento foi escrito por Ana.' );
		$this->assertTrue( $r['ok'], wp_json_encode( $r ) );
		$r2 = $c->check_sentence( 'Os indígenas Karipunas viviam ali.' );
		$this->assertTrue( $r2['ok'], 'stem match tolerates inflection (Karipuna/Karipunas)' );
	}

	public function test_template_boilerplate_is_trusted_and_line_breaks_split_sentences(): void {
		$c = new FaithfulnessChecker( $this->corpus(), \TainacanNarrativas\Narrative\ScriptBuilder::boilerplate_sentences() );
		$r = $c->check( "Você está ouvindo o registro \"Carta de Ana Manoela\".\nEste item integra a coleção Memorial COVID-19.\n\nEste registro contém mais informações do que as narradas aqui. Consulte a página do item para o conteúdo completo." );
		$this->assertTrue( $r['ok'], wp_json_encode( $r['sentences'] ) );
		$this->assertSame( 4, $r['total'], 'single line breaks are sentence boundaries' );
	}

	public function test_fold(): void {
		$this->assertSame( 'ana manoela 3500 pessoas', FaithfulnessChecker::fold( 'Ana Manoela — 3.500 pessoas!' ) );
	}
}
