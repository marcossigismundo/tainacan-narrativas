<?php
/**
 * Ollama provider (OpenAI-compatible endpoint + native model listing).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Security\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ollama normally runs on a private address, which requires the explicit
 * "allow private endpoints" setting (see Security).
 */
final class OllamaProvider extends OpenAICompatibleProvider {

	/**
	 * Native base (without /v1).
	 *
	 * @var string
	 */
	private string $native_base;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->native_base = rtrim( trim( (string) Options::get( 'ollama_base_url', 'http://127.0.0.1:11434' ) ), '/' );
		$this->base_url    = Security::join_url( $this->native_base, '/v1' );
		$this->model_id    = trim( (string) Options::get( 'ollama_model', 'llama3.2' ) );
		$this->api_key     = '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'ollama';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Ollama (local)';
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_models(): array {
		$data = $this->get_json( Security::join_url( $this->native_base, '/api/tags' ) );
		if ( is_wp_error( $data ) || ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
			return parent::list_models();
		}
		$names = array();
		foreach ( $data['models'] as $m ) {
			if ( is_array( $m ) && isset( $m['name'] ) && is_string( $m['name'] ) ) {
				$names[] = $m['name'];
			}
		}
		sort( $names );
		return $names;
	}
}
