<?php
/**
 * OpenAI provider.
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
 * Fixed endpoint + mandatory key; everything else comes from the compatible provider.
 * Catalog mirrors Oráculo Tainacan's.
 */
final class OpenAIProvider extends OpenAICompatibleProvider {

	public const BASE_URL      = 'https://api.openai.com/v1';
	public const DEFAULT_MODEL = 'gpt-5-mini';

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $config Overrides: model|api_key.
	 */
	public function __construct( array $config = array() ) {
		$this->base_url = self::BASE_URL;
		$model          = trim( (string) ( $config['model'] ?? Options::get( 'openai_model', '' ) ) );
		$this->model_id = '' !== $model ? $model : self::DEFAULT_MODEL;
		if ( isset( $config['api_key'] ) ) {
			$this->api_key = (string) $config['api_key'];
		} else {
			// Legacy installs kept the OpenAI key in the shared ai_api_key option.
			$key           = Options::secret( 'openai_api_key' );
			$this->api_key = '' !== $key ? $key : Options::secret( 'ai_api_key' );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'OpenAI (ChatGPT)';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Provedor oficial da OpenAI: família GPT-5 e modelos legados GPT-4o.', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function catalog(): array {
		return array(
			array(
				'id'          => 'gpt-5.2',
				'name'        => 'GPT-5.2',
				'description' => __( 'Topo de linha atual, melhor qualidade', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gpt-5.1',
				'name'        => 'GPT-5.1',
				'description' => __( 'Geração anterior do topo de linha', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gpt-5-mini',
				'name'        => 'GPT-5 Mini',
				'description' => __( 'Ótimo custo-benefício, recomendado', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gpt-5-nano',
				'name'        => 'GPT-5 Nano',
				'description' => __( 'O mais rápido e econômico da família GPT-5', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gpt-4o',
				'name'        => 'GPT-4o (legado)',
				'description' => __( 'Geração anterior; mantido por compatibilidade', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'gpt-4o-mini',
				'name'        => 'GPT-4o Mini (legado)',
				'description' => __( 'Geração anterior econômica', 'tainacan-narrativas' ),
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
