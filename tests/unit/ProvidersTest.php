<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\AI\ClaudeProvider;
use TainacanNarrativas\AI\DeepSeekProvider;
use TainacanNarrativas\AI\GroqProvider;
use TainacanNarrativas\AI\OpenAIProvider;
use TainacanNarrativas\AI\ProviderManager;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Narrative\Modes;

final class ProvidersTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['tn_test_options'] = array();
		Options::flush();
	}

	public function test_registry_exposes_oraculo_style_catalogs(): void {
		$m   = new ProviderManager();
		$ids = array_keys( $m->all() );
		foreach ( array( 'openai', 'claude', 'gemini', 'groq', 'deepseek', 'ollama', 'openai_compatible' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		foreach ( array( 'openai', 'claude', 'gemini', 'groq', 'deepseek' ) as $id ) {
			$catalog = $m->get( $id )->catalog();
			$this->assertNotEmpty( $catalog, $id );
			$this->assertArrayHasKey( 'id', $catalog[0] );
			$this->assertArrayHasKey( 'name', $catalog[0] );
			$this->assertSame( ProviderManager::SETTINGS[ $id ]['model'], $id . '_model' );
			$this->assertArrayHasKey( ProviderManager::SETTINGS[ $id ]['key'], Options::SECRET_CONSTANTS );
		}
		$ui = $m->ui_providers();
		$this->assertNotEmpty( $ui );
		foreach ( $ui as $row ) {
			$this->assertArrayNotHasKey( 'api_key', $row, 'descriptor never carries secrets' );
		}
	}

	public function test_make_applies_unsaved_overrides_without_persisting(): void {
		$m = new ProviderManager();
		$p = $m->make( 'claude', array( 'api_key' => 'sk-ant-test', 'model' => 'claude-opus-5' ) );
		$this->assertInstanceOf( ClaudeProvider::class, $p );
		$this->assertTrue( $p->is_configured() );
		$this->assertSame( 'claude-opus-5', $p->model() );
		$this->assertFalse( $m->get( 'claude' )->is_configured(), 'saved instance untouched' );
		$this->assertSame( '', (string) Options::get( 'claude_api_key' ) );

		$this->assertInstanceOf( GroqProvider::class, $m->make( 'groq', array( 'api_key' => 'x' ) ) );
		$this->assertInstanceOf( DeepSeekProvider::class, $m->make( 'deepseek', array( 'api_key' => 'x' ) ) );
		$this->assertSame( $m->get( 'openai' ), $m->make( 'openai', array( 'api_key' => '' ) ), 'empty overrides reuse the saved instance' );
	}

	public function test_openai_key_falls_back_to_legacy_shared_key(): void {
		$GLOBALS['tn_test_options']['tn_settings'] = array( 'ai_api_key' => 'sk-legacy', 'openai_model' => 'gpt-4o-mini' );
		Options::flush();
		$p = new OpenAIProvider();
		$this->assertTrue( $p->is_configured() );
		$this->assertSame( 'gpt-4o-mini', $p->model() );
	}

	public function test_duration_cap_applies_to_every_mode(): void {
		$GLOBALS['tn_test_options']['tn_settings'] = array( 'max_words' => 200 );
		Options::flush();
		$this->assertSame( 200, Modes::target_words_for( 'documentary' ) );
		$this->assertSame( 150, Modes::target_words_for( 'summary' ) );
		$this->assertSame( 200, Modes::target_words_for( 'faithful' ), 'unlimited modes still respect the cap' );
		$this->assertSame( 200, Modes::target_words_for( 'detailed' ) );
	}
}
