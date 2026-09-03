<?php
/**
 * OpenAI-compatible speech endpoint (Kokoro-FastAPI, LocalAI, OpenAI…).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\TTS;

use TainacanNarrativas\AI\AbstractHttpProvider;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Narrative\Chunker;
use TainacanNarrativas\Security\Security;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST {base}/audio/speech { model, input, voice, response_format, speed }.
 *
 * Kokoro-FastAPI (https://github.com/remsky/Kokoro-FastAPI) exposes exactly
 * this contract with pt-BR voices (pf_dora, pm_alex, pm_santa) and
 * GET /audio/voices for discovery — the recommended neural setup:
 *
 *   WordPress → HTTP interno → Kokoro → MP3 → Media Library
 */
final class OpenAICompatibleTTSProvider extends AbstractHttpProvider implements TTSProviderInterface {

	/**
	 * Max characters per request (OpenAI caps input at 4096).
	 */
	public const MAX_INPUT_CHARS = 3800;

	/**
	 * Base URL (…/v1).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * API key (optional for local servers).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->base_url = rtrim( trim( (string) Options::get( 'tts_base_url', '' ) ), '/' );
		$this->api_key  = Options::secret( 'tts_api_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'openai_compatible';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'TTS neural via API compatível com OpenAI (Kokoro-FastAPI, LocalAI, OpenAI…)', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->base_url && true === Security::validate_endpoint( $this->base_url );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_external(): bool {
		return $this->url_is_external( $this->base_url );
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
		return (string) Options::get( 'tts_voice', 'pf_dora' );
	}

	/**
	 * Headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return '' !== $this->api_key ? array( 'Authorization' => 'Bearer ' . $this->api_key ) : array();
	}

	/**
	 * Synthesizes (chunked when needed) and concatenates the audio.
	 *
	 * @param string              $text    Script.
	 * @param string              $voice   Voice id.
	 * @param array<string,mixed> $options speed|format|timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	public function synthesize( string $text, string $voice, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tn_tts_not_configured', __( 'Endpoint de TTS não configurado.', 'tainacan-narrativas' ) );
		}
		$format  = in_array( (string) ( $options['format'] ?? Options::get( 'tts_format', 'mp3' ) ), array( 'mp3', 'wav', 'opus', 'aac', 'flac' ), true ) ? (string) ( $options['format'] ?? Options::get( 'tts_format', 'mp3' ) ) : 'mp3';
		$speed   = (float) ( $options['speed'] ?? Options::get( 'tts_speed', 1.0 ) );
		$speed   = max( 0.5, min( 2.0, $speed ) );
		$timeout = (int) ( $options['timeout'] ?? Options::get( 'tts_timeout', 180 ) );
		$model   = trim( (string) Options::get( 'tts_model', 'kokoro' ) );
		$voice   = '' !== $voice ? $voice : $this->default_voice();
		$mime    = 'wav' === $format ? 'audio/wav' : ( 'mp3' === $format ? 'audio/mpeg' : 'audio/' . $format );

		$chunks = Chunker::chunk( $text, self::MAX_INPUT_CHARS );
		if ( count( $chunks ) > 1 && ! in_array( $format, array( 'mp3', 'wav' ), true ) ) {
			return new WP_Error( 'tn_tts_format', __( 'Roteiros longos exigem formato MP3 ou WAV para concatenação.', 'tainacan-narrativas' ) );
		}
		$parts = array();
		foreach ( $chunks as $chunk ) {
			$body = array(
				'model'           => '' !== $model ? $model : 'kokoro',
				'input'           => $chunk,
				'voice'           => $voice,
				'response_format' => $format,
				'speed'           => $speed,
			);
			$res  = $this->post_binary( Security::join_url( $this->base_url, '/audio/speech' ), $body, $this->headers(), $timeout );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( '' === $res['body'] || str_contains( $res['content_type'], 'json' ) ) {
				return new WP_Error( 'tn_tts_malformed', __( 'O serviço de voz não devolveu áudio.', 'tainacan-narrativas' ) );
			}
			$parts[] = $res['body'];
		}
		$audio = AudioConcat::join( $parts, $mime );
		if ( is_wp_error( $audio ) ) {
			return $audio;
		}
		return array(
			'audio'     => $audio,
			'mime'      => $mime,
			'extension' => $format,
			'voice'     => $voice,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test(): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'Informe a URL base do serviço de voz (ex.: http://kokoro:8880/v1).', 'tainacan-narrativas' ),
				'details' => array(),
			);
		}
		$voices = $this->list_voices();
		if ( $voices ) {
			$voice = $this->default_voice();
			return array(
				'success' => true,
				'message' => isset( $voices[ $voice ] )
					? __( 'Conexão OK; voz disponível.', 'tainacan-narrativas' )
					: __( 'Conexão OK, mas a voz configurada não aparece na lista do serviço.', 'tainacan-narrativas' ),
				'details' => array(
					'voices' => array_slice( array_keys( $voices ), 0, 50 ),
					'voice'  => $voice,
				),
			);
		}
		$res = $this->synthesize( 'Teste.', $this->default_voice(), array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return array(
				'success' => false,
				'message' => $res->get_error_message(),
				'details' => array(),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Conexão OK (áudio de teste recebido).', 'tainacan-narrativas' ),
			'details' => array( 'bytes' => strlen( $res['audio'] ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_voices(): array {
		if ( '' === $this->base_url ) {
			return array();
		}
		$data = $this->get_json( Security::join_url( $this->base_url, '/audio/voices' ), $this->headers() );
		$out  = array();
		if ( ! is_wp_error( $data ) && isset( $data['voices'] ) && is_array( $data['voices'] ) ) {
			foreach ( $data['voices'] as $v ) {
				if ( is_string( $v ) ) {
					$out[ $v ] = $v;
				} elseif ( is_array( $v ) && isset( $v['id'] ) ) {
					$out[ (string) $v['id'] ] = (string) ( $v['name'] ?? $v['id'] );
				}
			}
		}
		if ( ! $out && str_contains( $this->base_url, 'api.openai.com' ) ) {
			foreach ( array( 'alloy', 'ash', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer' ) as $v ) {
				$out[ $v ] = $v;
			}
		}
		ksort( $out );
		return $out;
	}
}
