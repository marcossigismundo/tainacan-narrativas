<?php
/**
 * Text-to-speech provider contract.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\TTS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side providers return audio bytes that AudioStorage persists in the
 * Media Library. The browser provider is a marker: the player synthesizes the
 * stored transcript locally with the Web Speech API.
 */
interface TTSProviderInterface {

	/**
	 * Unique id.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the provider is ready.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Whether the text leaves the server.
	 *
	 * @return bool
	 */
	public function is_external(): bool;

	/**
	 * Whether audio is produced on the server (false = in the visitor's browser).
	 *
	 * @return bool
	 */
	public function is_server_side(): bool;

	/**
	 * Default voice id.
	 *
	 * @return string
	 */
	public function default_voice(): string;

	/**
	 * Synthesizes text.
	 *
	 * @param string              $text    Script.
	 * @param string              $voice   Voice id.
	 * @param array<string,mixed> $options speed|format|timeout.
	 * @return array{audio:string,mime:string,extension:string,voice:string}|\WP_Error
	 */
	public function synthesize( string $text, string $voice, array $options = array() );

	/**
	 * Connectivity test (no secrets in the output).
	 *
	 * @return array{success:bool,message:string,details:array<string,mixed>}
	 */
	public function test(): array;

	/**
	 * Voices (id => label); may be empty.
	 *
	 * @return array<string,string>
	 */
	public function list_voices(): array;
}
