<?php
/**
 * AI provider registry.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the provider for a collection, applying the privacy gate: an
 * external provider is only used when the collection allows external AI.
 */
final class ProviderManager {

	/**
	 * Instances, keyed by id.
	 *
	 * @var array<string,AIProviderInterface>|null
	 */
	private ?array $providers = null;

	/**
	 * All providers (built-in + registered through the filter).
	 *
	 * @return array<string,AIProviderInterface>
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}
		$list = array(
			new OpenAICompatibleProvider(),
			new OpenAIProvider(),
			new OllamaProvider(),
			new GeminiProvider(),
		);
		if ( WordPressAIProvider::is_available() ) {
			$list[] = new WordPressAIProvider();
		}
		/**
		 * Filters/extends the AI providers.
		 *
		 * @param AIProviderInterface[] $list Providers.
		 */
		$list            = apply_filters( 'tainacan_narrativas_ai_providers', $list );
		$this->providers = array();
		foreach ( (array) $list as $provider ) {
			if ( $provider instanceof AIProviderInterface ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
		return $this->providers;
	}

	/**
	 * Provider by id.
	 *
	 * @param string $id Provider id.
	 * @return AIProviderInterface|null
	 */
	public function get( string $id ): ?AIProviderInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Labels (id => label), with "none" first.
	 *
	 * @return array<string,string>
	 */
	public function labels(): array {
		$out = array( 'none' => __( 'Sem IA (roteiro documental por template)', 'tainacan-narrativas' ) );
		foreach ( $this->all() as $id => $provider ) {
			$out[ $id ] = $provider->label();
		}
		return $out;
	}

	/**
	 * Provider to use for a collection, or null (with reason) when none applies.
	 *
	 * @param array<string,mixed> $config Effective collection config.
	 * @param string|null         $reason Set with a human reason when null is returned.
	 * @return AIProviderInterface|null
	 */
	public function for_collection( array $config, ?string &$reason = null ): ?AIProviderInterface {
		$id = (string) ( $config['ai'] ?? 'none' );
		if ( '' === $id || 'none' === $id ) {
			$reason = __( 'IA desativada para esta coleção.', 'tainacan-narrativas' );
			return null;
		}
		$provider = $this->get( $id );
		if ( ! $provider ) {
			$reason = __( 'Provedor de IA configurado não existe.', 'tainacan-narrativas' );
			return null;
		}
		if ( ! $provider->is_configured() ) {
			$reason = __( 'Provedor de IA não configurado (URL, modelo ou chave ausentes).', 'tainacan-narrativas' );
			return null;
		}
		if ( $provider->is_external() && empty( $config['external_ai'] ) ) {
			$reason = __( 'A coleção não permite envio de conteúdo a IA externa.', 'tainacan-narrativas' );
			return null;
		}
		return $provider;
	}
}
