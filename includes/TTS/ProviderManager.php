<?php
/**
 * TTS provider registry.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\TTS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fallback strategy: a server-side provider that is not configured falls back
 * to the browser provider, so narratives keep working on simple hosting.
 */
final class ProviderManager {

	/**
	 * Instances.
	 *
	 * @var array<string,TTSProviderInterface>|null
	 */
	private ?array $providers = null;

	/**
	 * All providers.
	 *
	 * @return array<string,TTSProviderInterface>
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}
		$list = array(
			new BrowserProvider(),
			new OpenAICompatibleTTSProvider(),
			new PiperHttpProvider(),
		);
		if ( WordPressAITTSProvider::is_available() ) {
			$list[] = new WordPressAITTSProvider();
		}
		/**
		 * Filters/extends the TTS providers.
		 *
		 * @param TTSProviderInterface[] $list Providers.
		 */
		$list            = apply_filters( 'tainacan_narrativas_tts_providers', $list );
		$this->providers = array();
		foreach ( (array) $list as $provider ) {
			if ( $provider instanceof TTSProviderInterface ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
		return $this->providers;
	}

	/**
	 * Provider by id.
	 *
	 * @param string $id Id.
	 * @return TTSProviderInterface|null
	 */
	public function get( string $id ): ?TTSProviderInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Labels.
	 *
	 * @return array<string,string>
	 */
	public function labels(): array {
		$out = array();
		foreach ( $this->all() as $id => $provider ) {
			$out[ $id ] = $provider->label();
		}
		return $out;
	}

	/**
	 * Provider for a collection with fallback to the browser.
	 *
	 * @param array<string,mixed> $config   Effective collection config.
	 * @param bool                $fallback Set to true when the fallback was used.
	 * @return TTSProviderInterface
	 */
	public function for_collection( array $config, bool &$fallback = false ): TTSProviderInterface {
		$fallback = false;
		$id       = (string) ( $config['tts'] ?? 'browser' );
		$provider = $this->get( $id );
		if ( $provider && $provider->is_configured() ) {
			return $provider;
		}
		$fallback = 'browser' !== $id;
		$browser  = $this->get( 'browser' );
		return $browser ? $browser : new BrowserProvider();
	}
}
