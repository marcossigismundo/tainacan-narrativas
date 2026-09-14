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
 *
 * Subclasses (OpenAI, Groq, DeepSeek, Ollama) fix the base URL and read
 * their own key/model options; the constructor accepts overrides so the
 * admin can probe a provider with values not yet saved.
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
	 *
	 * @param array<string,string> $config Overrides: base_url|model|api_key.
	 */
	public function __construct( array $config = array() ) {
		$this->base_url = rtrim( trim( (string) ( $config['base_url'] ?? Options::get( 'ai_base_url', '' ) ) ), '/' );
		$this->model_id = trim( (string) ( $config['model'] ?? Options::get( 'ai_model', '' ) ) );
		$this->api_key  = isset( $config['api_key'] ) ? (string) $config['api_key'] : Options::secret( 'ai_api_key' );
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
		return __( 'Servidor compatível com OpenAI', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Qualquer endpoint /v1/chat/completions: LM Studio, vLLM, LocalAI, OpenRouter, gateways institucionais. Informe a URL base e o nome do modelo.', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function catalog(): array {
		return array();
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
	 * Whether the model is a reasoning family that rejects `max_tokens` and
	 * a custom temperature (GPT-5, o-series). Same rule as Oráculo Tainacan.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	protected function is_reasoning_model( string $model ): bool {
		return 1 === preg_match( '/^(gpt-5|o\d)/i', $model );
	}

	/**
	 * Request body for /chat/completions.
	 *
	 * @param string              $system  System prompt.
	 * @param string              $user    User prompt.
	 * @param array<string,mixed> $options Options.
	 * @return array<string,mixed>
	 */
	protected function build_body( string $system, string $user, array $options ): array {
		$body = array(
			'model'    => $this->model_id,
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'stream'   => false,
		);
		$max  = (int) ( $options['max_tokens'] ?? Options::get( 'ai_max_tokens', 4000 ) );
		if ( $this->is_reasoning_model( $this->model_id ) ) {
			$body['max_completion_tokens'] = $max;
		} else {
			$body['max_tokens']  = $max;
			$body['temperature'] = (float) ( $options['temperature'] ?? Options::get( 'ai_temperature', 0.45 ) );
		}
		return $body;
	}

	/**
	 * Generates text via POST /chat/completions, adapting parameters the
	 * endpoint rejects (max_tokens → max_completion_tokens, temperature).
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
		$body    = $this->build_body( $system, $user, $options );
		$timeout = (int) ( $options['timeout'] ?? Options::get( 'ai_timeout', 180 ) );
		$data    = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$data = $this->post_json( Security::join_url( $this->base_url, '/chat/completions' ), $body, $this->headers(), $timeout );
			if ( ! is_wp_error( $data ) ) {
				break;
			}
			$message = $data->get_error_message();
			if ( isset( $body['max_tokens'] ) && preg_match( '/max_tokens.+max_completion_tokens/i', $message ) ) {
				$body['max_completion_tokens'] = $body['max_tokens'];
				unset( $body['max_tokens'] );
				continue;
			}
			if ( isset( $body['temperature'] ) && false !== stripos( $message, 'temperature' ) ) {
				unset( $body['temperature'] );
				continue;
			}
			return $data;
		}
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
