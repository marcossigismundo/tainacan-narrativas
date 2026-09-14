<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Admin\SettingsHandler;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Tainacan\CollectionSettings;

final class SettingsSanitizerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['tn_test_options'] = array();
		Options::flush();
	}

	public function test_settings_ranges_enums_and_secrets(): void {
		$errors = array();
		$out    = SettingsHandler::sanitize_settings(
			array(
				'__bools'         => array( 'enabled', 'debug', 'bogus' ),
				'enabled'         => '1',
				'cron_batch'      => '999',
				'ai_temperature'  => '1,5',
				'trigger_on_save' => 'hack',
				'editorial_flow'  => 'auto',
				'default_mode'    => 'summary',
				'ai_base_url'     => 'https://api.example.com/v1',
				'tts_base_url'    => 'http://127.0.0.1:8880/v1',
				'ai_api_key'      => 'sk-new',
				'gemini_api_key'  => '',
				'tts_api_key'     => '__clear__',
				'ai_model'        => "<b>gpt</b>\n",
				'ai_provider'     => 'claude',
				'claude_api_key'  => 'sk-ant-1',
				'claude_model'    => 'claude-sonnet-5',
				'max_words'       => '20',
			),
			$errors
		);
		$this->assertSame( 'claude', $out['ai_provider'] );
		$this->assertSame( 'sk-ant-1', $out['claude_api_key'] );
		$this->assertSame( 'claude-sonnet-5', $out['claude_model'] );
		$this->assertSame( 60, $out['max_words'], 'duration cap is clamped to the minimum' );
		$this->assertSame( 1, $out['enabled'] );
		$this->assertSame( 0, $out['debug'] );
		$this->assertArrayNotHasKey( 'bogus', $out );
		$this->assertSame( 20, $out['cron_batch'] );
		$this->assertSame( 1.5, $out['ai_temperature'] );
		$this->assertArrayNotHasKey( 'trigger_on_save', $out );
		$this->assertSame( 'auto', $out['editorial_flow'] );
		$this->assertSame( 'summary', $out['default_mode'] );
		$this->assertSame( 'https://api.example.com/v1', $out['ai_base_url'] );
		$this->assertArrayNotHasKey( 'tts_base_url', $out, 'private endpoint rejected while allow_private is off' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'sk-new', $out['ai_api_key'] );
		$this->assertArrayNotHasKey( 'gemini_api_key', $out, 'empty keeps stored value' );
		$this->assertSame( '', $out['tts_api_key'] );
		$this->assertSame( 'gpt', $out['ai_model'] );
	}

	public function test_private_endpoint_accepted_when_flag_in_same_form(): void {
		$errors = array();
		$out    = SettingsHandler::sanitize_settings(
			array(
				'__bools'                 => array( 'allow_private_endpoints' ),
				'allow_private_endpoints' => '1',
				'ollama_base_url'         => 'http://127.0.0.1:11434',
			),
			$errors
		);
		$this->assertSame( 'http://127.0.0.1:11434', $out['ollama_base_url'] );
		$this->assertSame( array(), $errors );
	}

	public function test_collection_entry_sanitizer(): void {
		$entry = CollectionSettings::sanitize_entry(
			array(
				'enabled'        => 'yes',
				'metadata'       => array( '3', '3', 'x', '7' ),
				'metadata_order' => array( 7, 3 ),
				'mode'           => 'Story<telling>',
				'editorial_flow' => 'weird',
				'allow_download' => '1',
				'sensitivity'    => 'none',
				'max_attachments' => '500',
			)
		);
		$this->assertSame( 1, $entry['enabled'] );
		$this->assertSame( array( 3, 7 ), $entry['metadata'] );
		$this->assertSame( array( 7, 3 ), $entry['metadata_order'] );
		$this->assertSame( 'storytelling', $entry['mode'] );
		$this->assertSame( 'inherit', $entry['editorial_flow'] );
		$this->assertSame( '1', $entry['allow_download'] );
		$this->assertSame( 'none', $entry['sensitivity'] );
		$this->assertSame( 50, $entry['max_attachments'] );
		$this->assertSame( 1, $entry['autoinject'], 'partial (non-form) save keeps defaults for absent booleans' );

		$form = CollectionSettings::sanitize_entry( array( '__form' => '1', 'enabled' => '1' ) );
		$this->assertSame( 0, $form['autoinject'], 'HTML form: unchecked box means 0' );
		$this->assertSame( 0, $form['include_document'] );
	}

	public function test_effective_config_inherits_and_overrides(): void {
		$GLOBALS['tn_test_options']['tn_settings'] = array( 'enabled' => 1, 'default_mode' => 'documentary', 'editorial_flow' => 'auto', 'allow_download' => 0, 'tts_voice' => 'pf_dora' );
		$GLOBALS['tn_test_options']['tn_collection_config'] = array(
			5 => array( 'enabled' => 1, 'mode' => 'summary', 'sensitivity' => 'review', 'tts_voice' => '' ),
		);
		Options::flush();
		$eff = CollectionSettings::effective( 5 );
		$this->assertTrue( $eff['enabled'] );
		$this->assertSame( 'summary', $eff['mode'] );
		$this->assertSame( 'review', $eff['editorial_flow'], 'sensitive collections force review' );
		$this->assertFalse( $eff['allow_download'] );
		$this->assertSame( 'pf_dora', $eff['tts_voice'] );
		$this->assertFalse( CollectionSettings::effective( 6 )['enabled'] );
		$this->assertSame( array( 5 ), CollectionSettings::enabled_ids() );
	}
}
