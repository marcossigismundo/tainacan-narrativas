<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Security\Security;

final class SecurityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['tn_test_options'] = array();
		Options::flush();
	}

	public function test_private_hosts_are_detected(): void {
		$this->assertTrue( Security::host_is_private( 'localhost' ) );
		$this->assertTrue( Security::host_is_private( '127.0.0.1' ) );
		$this->assertTrue( Security::host_is_private( '10.1.2.3' ) );
		$this->assertTrue( Security::host_is_private( '192.168.0.10' ) );
		$this->assertTrue( Security::host_is_private( '172.16.5.5' ) );
		$this->assertTrue( Security::host_is_private( 'kokoro.internal' ) );
		$this->assertFalse( Security::host_is_private( '8.8.8.8' ) );
	}

	public function test_validate_endpoint_rejects_bad_schemes_userinfo_ports_and_private_by_default(): void {
		$this->assertSame( 'tn_endpoint_empty', Security::validate_endpoint( '' )->get_error_code() );
		$this->assertSame( 'tn_endpoint_scheme', Security::validate_endpoint( 'ftp://example.com/x' )->get_error_code() );
		$this->assertContains( Security::validate_endpoint( 'file:///etc/passwd' )->get_error_code(), array( 'tn_endpoint_scheme', 'tn_endpoint_invalid' ) );
		$this->assertSame( 'tn_endpoint_userinfo', Security::validate_endpoint( 'https://user:pw@example.com/v1' )->get_error_code() );
		$this->assertSame( 'tn_endpoint_port', Security::validate_endpoint( 'http://example.com:22/v1' )->get_error_code() );
		$this->assertSame( 'tn_endpoint_private', Security::validate_endpoint( 'http://127.0.0.1:11434/v1' )->get_error_code() );
		$this->assertTrue( Security::validate_endpoint( 'https://api.openai.com/v1' ) );
	}

	public function test_validate_endpoint_allows_private_when_explicitly_enabled(): void {
		$this->assertTrue( Security::validate_endpoint( 'http://127.0.0.1:11434/v1', true ) );
		$GLOBALS['tn_test_options']['tn_settings'] = array( 'allow_private_endpoints' => 1 );
		Options::flush();
		$this->assertTrue( Security::validate_endpoint( 'http://192.168.1.20:8880/v1' ) );
	}

	public function test_logger_redacts_secrets(): void {
		$this->assertSame( 'Bearer [REDACTED]', Logger::redact( 'Bearer sk-abcdef1234567890' ) );
		$this->assertStringNotContainsString( 'AIzaSyD-9tSrke72PouQMnMX-a7eZSW0jkFMBWY', Logger::redact( 'key=AIzaSyD-9tSrke72PouQMnMX-a7eZSW0jkFMBWY' ) );
		$this->assertStringContainsString( '[REDACTED]', Logger::redact( '{"api_key":"secret123"}' ) );
		$arr = Logger::redact_array( array( 'Authorization' => 'Bearer x', 'nested' => array( 'token' => 'y', 'ok' => 'fine' ) ) );
		$this->assertSame( '[REDACTED]', $arr['Authorization'] );
		$this->assertSame( '[REDACTED]', $arr['nested']['token'] );
		$this->assertSame( 'fine', $arr['nested']['ok'] );
	}

	public function test_secret_mask_and_constants(): void {
		$GLOBALS['tn_test_options']['tn_settings'] = array( 'ai_api_key' => 'sk-1234567890abcd' );
		Options::flush();
		$this->assertSame( 'sk-1234567890abcd', Options::secret( 'ai_api_key' ) );
		$this->assertSame( '********abcd', Options::secret_mask( 'ai_api_key' ) );
		$this->assertFalse( Options::secret_is_constant( 'ai_api_key' ) );
	}

	public function test_join_url(): void {
		$this->assertSame( 'http://h/v1/chat/completions', Security::join_url( 'http://h/v1/', '/chat/completions' ) );
		$this->assertSame( 'http://h/v1/models', Security::join_url( 'http://h/v1', 'models' ) );
	}
}
