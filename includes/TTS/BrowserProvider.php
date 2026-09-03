<?php
/**
 * Browser (Web Speech API) provider marker.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\TTS;

use TainacanNarrativas\Core\Options;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zero-dependency fallback that works on any hosting: the script is stored
 * server-side (generate → store → serve) and the visitor's browser voices it
 * with speechSynthesis (pt-BR voices ship with Windows, macOS, iOS, Android
 * and Chrome). Nothing is downloaded from a CDN and no request leaves the
 * page. Trade-off: voice quality depends on the visitor's device.
 */
final class BrowserProvider implements TTSProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'browser';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Voz do navegador (Web Speech API, sem servidor)', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_external(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_server_side(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_voice(): string {
		return (string) Options::get( 'browser_voice_hint', '' );
	}

	/**
	 * Never produces server audio.
	 *
	 * @param string              $text    Script.
	 * @param string              $voice   Voice id.
	 * @param array<string,mixed> $options Unused.
	 * @return WP_Error
	 */
	public function synthesize( string $text, string $voice, array $options = array() ) {
		return new WP_Error( 'tn_tts_browser', __( 'A voz do navegador é sintetizada no dispositivo do visitante; não há áudio no servidor.', 'tainacan-narrativas' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function test(): array {
		return array(
			'success' => true,
			'message' => __( 'Sempre disponível. A qualidade da voz depende do navegador/sistema do visitante; para áudio idêntico para todos, configure um TTS neural.', 'tainacan-narrativas' ),
			'details' => array( 'lang' => (string) Options::get( 'browser_lang', 'pt-BR' ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_voices(): array {
		return array();
	}
}
