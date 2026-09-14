<?php
/**
 * Google Gemini provider (generateContent REST API).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

use TainacanNarrativas\Core\Options;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The key travels in the x-goog-api-key header (never in the URL, so it can
 * never land in access logs).
 */
final class GeminiProvider extends AbstractHttpProvider implements AIProviderInterface {

	public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Model id.
	 *
	 * @var string
	 */
	private string $model_id;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $config Overrides: model|api_key.
	 */
	public function __construct( array $config = array() ) {
		$model          = trim( (string) ( $config['model'] ?? Options::get( 'gemini_model', 'gemini-2.5-flash' ) ) );
		$this->model_id = '' !== $model ? $model : 'gemini-2.5-flash';
		$this->api_key  = isset( $config['api_key'] ) ? (string) $config['api_key'] : Options::secret( 'gemini_api_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Google Gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'IA do Google com contexto muito longo (documentos grandes) e plano gratuito no AI Studio.', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function catalog(): array {
		return array(
			array(
				'id'          => 'gemini-2.5-pro',
				'name'        => 'Gemini 2.5 Pro',
				'description' => __( 'Modelo mais capaz, com raciocínio', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gemini-2.5-flash',
				'name'        => 'Gemini 2.5 Flash',
				'description' => __( 'Rápido e econômico (recomendado)', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gemini-2.5-flash-lite',
				'name'        => 'Gemini 2.5 Flash-Lite',
				'description' => __( 'O mais econômico da família', 'tainacan-narrativas' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
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
	public function model(): string {
		return $this->model_id;
	}

	/**
	 * Headers.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array( 'x-goog-api-key' => $this->api_key );
	}

	/**
	 * Generates text via generateContent.
	 *
	 * @param string              $system  System prompt.
	 * @param string              $user    User prompt.
	 * @param array<string,mixed> $options temperature|max_tokens|timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( string $system, string $user, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tn_ai_not_configured', __( 'Chave do Gemini não configurada.', 'tainacan-narrativas' ) );
		}
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user ) ),
				),
			),
			'generationConfig'  => array(
				'temperature'     => (float) ( $options['temperature'] ?? Options::get( 'ai_temperature', 0.45 ) ),
				'maxOutputTokens' => (int) ( $options['max_tokens'] ?? Options::get( 'ai_max_tokens', 4000 ) ),
			),
		);
		$url  = self::BASE_URL . '/models/' . rawurlencode( $this->model_id ) . ':generateContent';
		$data = $this->post_json( $url, $body, $this->headers(), (int) ( $options['timeout'] ?? Options::get( 'ai_timeout', 180 ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$parts = $data['candidates'][0]['content']['parts'] ?? null;
		$text  = '';
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		if ( '' === trim( $text ) ) {
			$reason = (string) ( $data['candidates'][0]['finishReason'] ?? $data['promptFeedback']['blockReason'] ?? '' );
			return new WP_Error(
				'tn_ai_malformed',
				'' !== $reason
					/* translators: %s: finish/block reason reported by Gemini. */
					? sprintf( __( 'O Gemini não devolveu texto (motivo: %s).', 'tainacan-narrativas' ), $reason )
					: __( 'O modelo devolveu uma resposta vazia ou em formato inesperado.', 'tainacan-narrativas' )
			);
		}
		return array(
			'text'  => trim( $text ),
			'model' => is_string( $data['modelVersion'] ?? null ) ? $data['modelVersion'] : $this->model_id,
			'usage' => array(
				'prompt_tokens'     => (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ),
				'completion_tokens' => (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test(): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'Informe a chave de API do Gemini.', 'tainacan-narrativas' ),
				'details' => array(),
			);
		}
		$data = $this->get_json( self::BASE_URL . '/models/' . rawurlencode( $this->model_id ), $this->headers() );
		if ( is_wp_error( $data ) ) {
			return array(
				'success' => false,
				'message' => $data->get_error_message(),
				'details' => array(),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Conexão OK; modelo disponível.', 'tainacan-narrativas' ),
			'details' => array( 'model' => (string) ( $data['name'] ?? $this->model_id ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_models(): array {
		if ( ! $this->is_configured() ) {
			return array();
		}
		$data = $this->get_json( self::BASE_URL . '/models?pageSize=100', $this->headers() );
		if ( is_wp_error( $data ) || ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $data['models'] as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['name'] ) ) {
				continue;
			}
			$methods = isset( $m['supportedGenerationMethods'] ) && is_array( $m['supportedGenerationMethods'] ) ? $m['supportedGenerationMethods'] : array();
			if ( $methods && ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$out[] = (string) preg_replace( '#^models/#', '', (string) $m['name'] );
		}
		sort( $out );
		return $out;
	}
}
