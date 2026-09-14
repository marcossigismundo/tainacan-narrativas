<?php
/**
 * Groq provider (OpenAI dialect).
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
 * Groq serves open models (Llama, Mixtral, Gemma) behind an OpenAI-compatible
 * endpoint with very low latency. Catalog mirrors Oráculo Tainacan's.
 */
final class GroqProvider extends OpenAICompatibleProvider {

	public const BASE_URL      = 'https://api.groq.com/openai/v1';
	public const DEFAULT_MODEL = 'llama-3.3-70b-versatile';

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $config Overrides: model|api_key.
	 */
	public function __construct( array $config = array() ) {
		$this->base_url = self::BASE_URL;
		$model          = trim( (string) ( $config['model'] ?? Options::get( 'groq_model', '' ) ) );
		$this->model_id = '' !== $model ? $model : self::DEFAULT_MODEL;
		$this->api_key  = isset( $config['api_key'] ) ? (string) $config['api_key'] : Options::secret( 'groq_api_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'groq';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Groq';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Modelos abertos (Llama, Mixtral, Gemma) com resposta muito rápida e plano gratuito.', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function catalog(): array {
		return array(
			array(
				'id'          => 'meta-llama/llama-4-maverick-17b-128e-instruct',
				'name'        => 'Llama 4 Maverick',
				'description' => __( 'Llama 4 multimodal, melhor qualidade', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'meta-llama/llama-4-scout-17b-16e-instruct',
				'name'        => 'Llama 4 Scout',
				'description' => __( 'Llama 4 leve e rápido', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'llama-3.3-70b-versatile',
				'name'        => 'Llama 3.3 70B',
				'description' => __( 'Equilíbrio entre qualidade e velocidade (recomendado)', 'tainacan-narrativas' ),
			),
			array(
				'id'          => 'llama-3.1-8b-instant',
				'name'        => 'Llama 3.1 8B',
				'description' => __( 'Muito rápido e econômico', 'tainacan-narrativas' ),
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
