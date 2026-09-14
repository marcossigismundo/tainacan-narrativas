<?php
/**
 * Claude (Anthropic) provider — Messages API.
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
 * POST https://api.anthropic.com/v1/messages with the system prompt in the
 * dedicated `system` field. Catalog mirrors Oráculo Tainacan's.
 */
final class ClaudeProvider extends AbstractHttpProvider implements AIProviderInterface {

	public const BASE_URL      = 'https://api.anthropic.com/v1';
	public const API_VERSION   = '2023-06-01';
	public const DEFAULT_MODEL = 'claude-sonnet-5';

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
		$model          = trim( (string) ( $config['model'] ?? Options::get( 'claude_model', '' ) ) );
		$this->model_id = '' !== $model ? $model : self::DEFAULT_MODEL;
		$this->api_key  = isset( $config['api_key'] ) ? (string) $config['api_key'] : Options::secret( 'claude_api_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'claude';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Claude (Anthropic)';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'IA da Anthropic, conhecida por seguir instruções com precisão e por respostas seguras.', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function catalog(): array {
		return array(
			array(
				'id'          => 'claude-opus-5',
				'name'        => 'Claude Opus 5',
				'description' => __( 'Modelo mais avançado para tarefas complexas', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'claude-sonnet-5',
				'name'        => 'Claude Sonnet 5',
				'description' => __( 'Equilíbrio ideal entre qualidade e velocidade (recomendado)', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'claude-haiku-4-5-20251001',
				'name'        => 'Claude Haiku 4.5',
				'description' => __( 'Rápido e econômico', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'claude-opus-4-5-20251101',
				'name'        => 'Claude Opus 4.5 (legado)',
				'description' => __( 'Geração anterior; mantido por compatibilidade', 'tainacan-narrativas' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key && '' !== $this->model_id;
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
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * Generates text via /messages.
	 *
	 * @param string              $system  System prompt.
	 * @param string              $user    User prompt.
	 * @param array<string,mixed> $options temperature|max_tokens|timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( string $system, string $user, array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'tn_ai_not_configured', __( 'Chave de API do Claude não configurada.', 'tainacan-narrativas' ) );
		}
		$body        = array(
			'model'      => $this->model_id,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'max_tokens' => (int) ( $options['max_tokens'] ?? Options::get( 'ai_max_tokens', 4000 ) ),
		);
		$temperature = (float) ( $options['temperature'] ?? Options::get( 'ai_temperature', 0.45 ) );
		if ( $temperature > 0 ) {
			$body['temperature'] = min( 1.0, $temperature );
		}
		$data = $this->post_json( self::BASE_URL . '/messages', $body, $this->headers(), (int) ( $options['timeout'] ?? Options::get( 'ai_timeout', 180 ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && is_string( $block['text'] ?? null ) ) {
				$text .= $block['text'];
			}
		}
		if ( '' === trim( $text ) ) {
			$reason = (string) ( $data['stop_reason'] ?? '' );
			return new WP_Error(
				'tn_ai_malformed',
				'' !== $reason
					/* translators: %s: stop reason reported by the API. */
					? sprintf( __( 'O Claude não devolveu texto (motivo: %s).', 'tainacan-narrativas' ), $reason )
					: __( 'O modelo devolveu uma resposta vazia ou em formato inesperado.', 'tainacan-narrativas' )
			);
		}
		return array(
			'text'  => trim( $text ),
			'model' => is_string( $data['model'] ?? null ) ? $data['model'] : $this->model_id,
			'usage' => array(
				'prompt_tokens'     => (int) ( $data['usage']['input_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
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
				'message' => __( 'Informe a chave de API do Claude.', 'tainacan-narrativas' ),
				'details' => array(),
			);
		}
		$models = $this->list_models();
		if ( ! $models ) {
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
		return array(
			'success' => true,
			'message' => in_array( $this->model_id, $models, true )
				? __( 'Conexão OK; modelo disponível.', 'tainacan-narrativas' )
				: __( 'Conexão OK, mas o modelo configurado não aparece na lista da conta.', 'tainacan-narrativas' ),
			'details' => array(
				'models' => array_slice( $models, 0, 30 ),
				'model'  => $this->model_id,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_models(): array {
		if ( '' === $this->api_key ) {
			return array();
		}
		$data = $this->get_json( self::BASE_URL . '/models?limit=100', $this->headers() );
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
