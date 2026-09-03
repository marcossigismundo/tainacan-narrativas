<?php
/**
 * WordPress core AI Client text-to-speech (WP 7.0+).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\TTS;

use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Narrative\Chunker;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses the site's configured AI connectors through
 * wp_ai_client_prompt()->convert_text_to_speech_result(). Voice choice is
 * left to the connector; output format is whatever the connector returns.
 *
 * IMPORTANT: WP_AI_Client_Prompt_Builder only recognizes the snake_case
 * spelling of its generating/support-check methods
 * (convert_text_to_speech_result, is_supported_for_text_to_speech_conversion)
 * to convert an internal failure into a WP_Error. Calling the vendor SDK's
 * camelCase names instead (convertTextToSpeechResult…) still resolves via
 * __call(), but on failure it silently returns the builder object itself
 * instead of a WP_Error — which then blows up as soon as it is used as a
 * result ("Object of class WP_AI_Client_Prompt_Builder could not be
 * converted to string"). Always use the snake_case names and check
 * is_wp_error() on what a generating method returns.
 */
final class WordPressAITTSProvider implements TTSProviderInterface {

	/**
	 * Whether the core AI client exists.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'wp_ai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'WordPress AI (conectores do próprio site, WP 7.0+)', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		if ( ! self::is_available() ) {
			return false;
		}
		try {
			return (bool) wp_ai_client_prompt( 'test' )->is_supported_for_text_to_speech_conversion();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_external(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_server_side(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_voice(): string {
		return 'auto';
	}

	/**
	 * Synthesizes through the site's AI connectors.
	 *
	 * @param string              $text    Script.
	 * @param string              $voice   Ignored (connector decides).
	 * @param array<string,mixed> $options Unused.
	 * @return array<string,mixed>|WP_Error
	 */
	public function synthesize( string $text, string $voice, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tn_tts_not_configured', __( 'Nenhum conector do WordPress AI com conversão texto-fala está configurado.', 'tainacan-narrativas' ) );
		}
		$parts = array();
		$mime  = '';
		foreach ( Chunker::chunk( $text, 3800 ) as $chunk ) {
			try {
				$result = wp_ai_client_prompt( $chunk )->convert_text_to_speech_result();
			} catch ( \Throwable $e ) {
				Logger::warning( 'WP AI TTS failure', array( 'error' => $e->getMessage() ) );
				return new WP_Error( 'tn_ai_retryable', Logger::redact( $e->getMessage() ) );
			}
			// convert_text_to_speech_result() reports failures as WP_Error, not exceptions.
			if ( is_wp_error( $result ) ) {
				Logger::warning(
					'WP AI TTS error',
					array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					)
				);
				return new WP_Error( 'tn_ai_retryable', Logger::redact( $result->get_error_message() ) );
			}
			try {
				$file = $result->toAudioFile();
				$mime = (string) $file->getMimeType();
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the inline audio payload returned by the WordPress AI Client; not code.
				$data = $file->isInline() ? base64_decode( (string) $file->getBase64Data(), true ) : false;
			} catch ( \Throwable $e ) {
				Logger::warning( 'WP AI TTS failure', array( 'error' => $e->getMessage() ) );
				return new WP_Error( 'tn_ai_retryable', Logger::redact( $e->getMessage() ) );
			}
			if ( false === $data || '' === $data ) {
				return new WP_Error( 'tn_tts_malformed', __( 'O conector devolveu áudio vazio ou remoto (não suportado).', 'tainacan-narrativas' ) );
			}
			$parts[] = $data;
		}
		$audio = AudioConcat::join( $parts, $mime );
		if ( is_wp_error( $audio ) ) {
			return $audio;
		}
		$ext = str_contains( $mime, 'wav' ) ? 'wav' : ( str_contains( $mime, 'ogg' ) ? 'ogg' : 'mp3' );
		return array(
			'audio'     => $audio,
			'mime'      => '' !== $mime ? $mime : 'audio/mpeg',
			'extension' => $ext,
			'voice'     => 'auto',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test(): array {
		$ok = $this->is_configured();
		return array(
			'success' => $ok,
			'message' => $ok
				? __( 'Um conector com conversão texto-fala está configurado no WordPress.', 'tainacan-narrativas' )
				: __( 'Requer WordPress 7.0+ e um conector de IA com suporte a texto-fala.', 'tainacan-narrativas' ),
			'details' => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_voices(): array {
		return array();
	}
}
