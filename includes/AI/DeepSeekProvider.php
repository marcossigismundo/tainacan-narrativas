<?php
/**
 * DeepSeek provider (OpenAI dialect).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

use TainacanNarrativas\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog mirrors Oráculo Tainacan's.
 */
final class DeepSeekProvider extends OpenAICompatibleProvider {

	public const BASE_URL      = 'https://api.deepseek.com/v1';
	public const DEFAULT_MODEL = 'deepseek-chat';

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $config Overrides: model|api_key.
	 */
	public function __construct( array $config = array() ) {
		$this->base_url = self::BASE_URL;
		$model          = trim( (string) ( $config['model'] ?? Options::get( 'deepseek_model', '' ) ) );
		$this->model_id = '' !== $model ? $model : self::DEFAULT_MODEL;
		$this->api_key  = isset( $config['api_key'] ) ? (string) $config['api_key'] : Options::secret( 'deepseek_api_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'deepseek';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'DeepSeek';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Modelos DeepSeek (Chat e Reasoner) com bom custo-benefício.', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function catalog(): array {
		return array(
			array(
				'id'          => 'deepseek-chat',
				'name'        => 'DeepSeek Chat',
				'description' => __( 'Modelo conversacional otimizado (recomendado)', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'deepseek-reasoner',
				'name'        => 'DeepSeek Reasoner (R1)',
				'description' => __( 'Modelo de raciocínio; mais lento', 'tainacan-narrativas' ),
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
}
