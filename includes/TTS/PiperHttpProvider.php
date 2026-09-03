<?php
/**
 * Piper HTTP server provider (WAV).
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
 * Talks to `python -m piper.http_server` (piper1-gpl, JSON body
 * {"text","voice"}) or the legacy rhasspy/piper server (raw text body).
 * Both answer with a PCM WAV; long scripts are synthesized in pieces and the
 * WAVs merged by AudioConcat.
 */
final class PiperHttpProvider extends AbstractHttpProvider implements TTSProviderInterface {

	/**
	 * Characters per request (Piper is fast but single-threaded).
	 */
	public const MAX_INPUT_CHARS = 1500;

	/**
	 * Endpoint URL.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->url = trim( (string) Options::get( 'piper_url', '' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'piper_http';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Piper (servidor HTTP local, WAV)', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->url && true === Security::validate_endpoint( $this->url );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_external(): bool {
		return $this->url_is_external( $this->url );
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
		return (string) Options::get( 'piper_voice', '' );
	}

	/**
	 * Synthesizes WAV pieces and merges them.
	 *
	 * @param string              $text    Script.
	 * @param string              $voice   Voice id.
	 * @param array<string,mixed> $options timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	public function synthesize( string $text, string $voice, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tn_tts_not_configured', __( 'URL do servidor Piper não configurada.', 'tainacan-narrativas' ) );
		}
		$timeout = (int) ( $options['timeout'] ?? Options::get( 'tts_timeout', 180 ) );
		$voice   = '' !== $voice ? $voice : $this->default_voice();
		$legacy  = 'raw' === (string) Options::get( 'piper_payload', 'json' );
		$parts   = array();

		foreach ( Chunker::chunk( $text, self::MAX_INPUT_CHARS ) as $chunk ) {
			$args = array(
				'method'  => 'POST',
				'timeout' => max( 5, $timeout ),
			);
			if ( $legacy ) {
				$args['headers'] = array( 'Content-Type' => 'text/plain; charset=utf-8' );
				$args['body']    = $chunk;
			} else {
				$payload = array( 'text' => $chunk );
				if ( '' !== $voice ) {
					$payload['voice'] = $voice;
				}
				$args['headers'] = array( 'Content-Type' => 'application/json' );
				$args['body']    = wp_json_encode( $payload );
			}
			$response = Security::remote_request( $this->url, $args );
			if ( is_wp_error( $response ) ) {
				return $this->wrap_transport_error( $response );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( $code >= 400 ) {
				return $this->http_error( $code, $body );
			}
			if ( null === AudioConcat::parse_wav( $body ) ) {
				return new WP_Error( 'tn_tts_malformed', __( 'O servidor Piper não devolveu um WAV válido.', 'tainacan-narrativas' ) );
			}
			$parts[] = $body;
		}
		$audio = AudioConcat::join( $parts, 'audio/wav' );
		if ( is_wp_error( $audio ) ) {
			return $audio;
		}
		return array(
			'audio'     => $audio,
			'mime'      => 'audio/wav',
			'extension' => 'wav',
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
				'message' => __( 'Informe a URL do servidor Piper (ex.: http://127.0.0.1:5000).', 'tainacan-narrativas' ),
				'details' => array(),
			);
		}
		$res = $this->synthesize( 'Teste de voz.', $this->default_voice(), array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return array(
				'success' => false,
				'message' => $res->get_error_message(),
				'details' => array(),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Conexão OK (WAV de teste recebido).', 'tainacan-narrativas' ),
			'details' => array( 'seconds' => AudioConcat::wav_duration( $res['audio'] ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_voices(): array {
		return array();
	}
}
