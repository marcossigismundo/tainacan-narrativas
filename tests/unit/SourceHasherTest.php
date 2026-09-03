<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Narrative\ContentScore;
use TainacanNarrativas\Narrative\SourceHasher;

final class SourceHasherTest extends TestCase {

	private function corpus( array $over = array() ): array {
		return array_merge(
			array(
				'title'       => 'Título',
				'description' => 'Descrição',
				'metadata'    => array( array( 'id' => 12, 'label' => 'Autor', 'value' => 'Ana' ) ),
				'document'    => array( 'id' => 456, 'status' => 'ok', 'text' => 'Texto do documento', 'signature' => 'sig' ),
				'attachments' => array(),
				'language'    => 'pt-BR',
			),
			$over
		);
	}

	public function test_hash_is_deterministic_and_sensitive_to_content(): void {
		$cfg = array( 'mode' => 'documentary', 'ai' => 'none', 'template' => '', 'max_chars_item' => 60000 );
		$a   = SourceHasher::source_hash( $this->corpus(), $cfg );
		$b   = SourceHasher::source_hash( $this->corpus(), $cfg );
		$this->assertSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );

		$changed_meta = SourceHasher::source_hash( $this->corpus( array( 'metadata' => array( array( 'id' => 12, 'label' => 'Autor', 'value' => 'Bia' ) ) ) ), $cfg );
		$this->assertNotSame( $a, $changed_meta );

		$changed_doc = SourceHasher::source_hash( $this->corpus( array( 'document' => array( 'id' => 456, 'status' => 'ok', 'text' => 'Outro texto', 'signature' => 'sig' ) ) ), $cfg );
		$this->assertNotSame( $a, $changed_doc );

		$changed_mode = SourceHasher::source_hash( $this->corpus(), array_merge( $cfg, array( 'mode' => 'summary' ) ) );
		$this->assertNotSame( $a, $changed_mode );
	}

	public function test_hash_ignores_health_notes_and_sources_order_fields(): void {
		$cfg = array( 'mode' => 'documentary', 'ai' => 'none', 'template' => '', 'max_chars_item' => 60000 );
		$a   = SourceHasher::source_hash( $this->corpus( array( 'health' => array( 'x' ) ) ), $cfg );
		$b   = SourceHasher::source_hash( $this->corpus( array( 'health' => array( 'y' ) ) ), $cfg );
		$this->assertSame( $a, $b );
	}

	public function test_script_and_audio_hashes(): void {
		$s = SourceHasher::script_hash( " Olá \n" );
		$this->assertSame( SourceHasher::script_hash( 'Olá' ), $s );
		$a1 = SourceHasher::audio_hash( $s, 'openai_compatible', 'pf_dora', 1.0, 'mp3' );
		$a2 = SourceHasher::audio_hash( $s, 'openai_compatible', 'pm_alex', 1.0, 'mp3' );
		$this->assertNotSame( $a1, $a2 );
	}

	public function test_content_score_levels(): void {
		$this->assertSame( ContentScore::INSUFFICIENT, ContentScore::score( array( 'title' => 'Curto' ) )['level'] );
		$basic = ContentScore::score( array( 'title' => 'T', 'description' => str_repeat( 'a', 300 ) ) );
		$this->assertSame( ContentScore::BASIC, $basic['level'] );
		$good = ContentScore::score( $this->corpus( array( 'document' => array( 'id' => 1, 'status' => 'ok', 'text' => str_repeat( 'texto ', 400 ) ) ) ) );
		$this->assertSame( ContentScore::GOOD, $good['level'] );
		$ext = ContentScore::score( $this->corpus( array( 'document' => array( 'id' => 1, 'status' => 'ok', 'text' => str_repeat( 'texto ', 2000 ) ) ) ) );
		$this->assertSame( ContentScore::EXTENSIVE, $ext['level'] );
	}
}
