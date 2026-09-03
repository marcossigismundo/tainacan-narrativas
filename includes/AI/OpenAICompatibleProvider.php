<?php
/**
 * OpenAI-compatible chat completions provider.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Security\Security;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works with any server exposing POST {base}/chat/completions: Ollama,
 * LM Studio, vLLM, LocalAI, OpenRouter, institutional gateways, OpenAI itself.
 */
class OpenAICompatibleProvider extends AbstractHttpProvider implements AIProviderInterface {

	/**
	 * Base URL (…/v1).
	 *
	 * @var string
	 */
	protected string $base_url;

	/**
	 * Model id.
	 *
	 * @var string
	 */
	protected string $model_id;

	/**
	 * API key (may be empty for local servers).
	 *
	 * @var string
	 */
	protected string $api_key;

	/**
	 * Constructor reads the global settings; subclasses override the defaults.
	 */
	public function __construct() {
		$this->base_url = rtrim( trim( (string) Options::get( 'ai_base_url', '' ) ), '/' );
		$this->model_id = trim( (string) Options::get( 'ai_model', '' ) );
		$this->api_key  = Options::secret( 'ai_api_key' );
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
		return __( 'Servidor compatível com OpenAI (Ollama, LM Studio, vLLM, LocalAI…)', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->base_url && '' !== $this->model_id && true === Security::validate_endpoint( $this->base_url );
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
	public function model(): string {
		return $this->model_id;
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string,string>
	 */
	protected function headers(): array {
		return '' !== $this->api_key ? array( 'Authorization' => 'Bearer ' . $this->api_key ) : array();
	}

	/**
	 * Generates text via POST /chat/completions.
	 *
	 * @param string              $system  System prompt.
	 * @param string              $user    User prompt.
	 * @param array<string,mixed> $options temperature|max_tokens|timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( string $system, string $user, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tn_ai_not_configured', __( 'Provedor de IA não configurado.', 'tainacan-narrativas' ) );
		}
		$body = array(
			'model'       => $this->model_id,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'temperature' => (float) ( $options['temperature'] ?? Options::get( 'ai_temperature', 0.3 ) ),
			'max_tokens'  => (int) ( $options['max_tokens'] ?? Options::get( 'ai_max_tokens', 2500 ) ),
			'stream'      => false,
		);
		$data = $this->post_json( Security::join_url( $this->base_url, '/chat/completions' ), $body, $this->headers(), (int) ( $options['timeout'] ?? Options::get( 'ai_timeout', 120 ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = $data['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new WP_Error( 'tn_ai_malformed', __( 'O modelo devolveu uma resposta vazia ou em formato inesperado.', 'tainacan-narrativas' ) );
		}
		return array(
			'text'  => trim( $text ),
			'model' => is_string( $data['model'] ?? null ) ? $data['model'] : $this->model_id,
			'usage' => array(
				'prompt_tokens'     => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
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
				'message' => __( 'Informe a URL base e o modelo.', 'tainacan-narrativas' ),
				'details' => array(),
			);
		}
		$models = $this->list_models();
		if ( $models ) {
			$known = in_array( $this->model_id, $models, true );
			return array(
				'success' => true,
				'message' => $known
					? __( 'Conexão OK; modelo disponível.', 'tainacan-narrativas' )
					: __( 'Conexão OK, mas o modelo configurado não aparece na lista do servidor.', 'tainacan-narrativas' ),
				'details' => array(
					'models' => array_slice( $models, 0, 30 ),
					'model'  => $this->model_id,
				),
			);
		}
		// Some gateways hide /models: fall back to a minimal completion.
		$result = $this->generate(
			'Responda apenas OK.',
			'OK?',
			array(
				'max_tokens' => 5,
				'timeout'    => 30,
			)
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
				'details' => array(),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Conexão OK (resposta de teste recebida).', 'tainacan-narrativas' ),
			'details' => array( 'model' => $result['model'] ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_models(): array {
		if ( '' === $this->base_url ) {
			return array();
		}
		$data = $this->get_json( Security::join_url( $this->base_url, '/models' ), $this->headers() );
		if ( is_wp_error( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $data['data'] as $m ) {
			if ( is_array( $m ) && isset( $m['id'] ) && is_string( $m['id'] ) ) {
				$ids[] = $m['id'];
			}
		}
		sort( $ids );
		return $ids;
	}
}
