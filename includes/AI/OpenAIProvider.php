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
 */
final class OpenAIProvider extends OpenAICompatibleProvider {

	public const BASE_URL      = 'https://api.openai.com/v1';
	public const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->base_url = self::BASE_URL;
		$model          = trim( (string) Options::get( 'ai_model', '' ) );
		$this->model_id = '' !== $model ? $model : self::DEFAULT_MODEL;
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
		return 'OpenAI';
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
