<?php
/**
 * AI provider registry.
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
 * Resolves the provider for a collection, applying the privacy gate: an
 * external provider is only used when the collection allows external AI.
 *
 * Also describes, for the settings screen, which option holds each
 * provider's key/URL/model — the same "one card per provider, key + model
 * select + fetch models from the account" layout Oráculo Tainacan uses.
 */
final class ProviderManager {

	/**
	 * Settings descriptor per provider id.
	 *
	 * @var array<string,array{key:string,url:string,model:string,key_link:string,free_model:bool}>
	 */
	public const SETTINGS = array(
		'openai'            => array(
			'key'        => 'openai_api_key',
			'url'        => '',
			'model'      => 'openai_model',
			'key_link'   => 'https://platform.openai.com/api-keys',
			'free_model' => false,
		),
		'claude'            => array(
			'key'        => 'claude_api_key',
			'url'        => '',
			'model'      => 'claude_model',
			'key_link'   => 'https://console.anthropic.com/settings/keys',
			'free_model' => false,
		),
		'gemini'            => array(
			'key'        => 'gemini_api_key',
			'url'        => '',
			'model'      => 'gemini_model',
			'key_link'   => 'https://aistudio.google.com/app/apikey',
			'free_model' => false,
		),
		'groq'              => array(
			'key'        => 'groq_api_key',
			'url'        => '',
			'model'      => 'groq_model',
			'key_link'   => 'https://console.groq.com/keys',
			'free_model' => false,
		),
		'deepseek'          => array(
			'key'        => 'deepseek_api_key',
			'url'        => '',
			'model'      => 'deepseek_model',
			'key_link'   => 'https://platform.deepseek.com/api_keys',
			'free_model' => false,
		),
		'ollama'            => array(
			'key'        => '',
			'url'        => 'ollama_base_url',
			'model'      => 'ollama_model',
			'key_link'   => '',
			'free_model' => true,
		),
		'openai_compatible' => array(
			'key'        => 'ai_api_key',
			'url'        => 'ai_base_url',
			'model'      => 'ai_model',
			'key_link'   => '',
			'free_model' => true,
		),
		'wp_ai'             => array(
			'key'        => '',
			'url'        => '',
			'model'      => '',
			'key_link'   => '',
			'free_model' => true,
		),
	);

	/**
	 * Instances, keyed by id.
	 *
	 * @var array<string,AIProviderInterface>|null
	 */
	private ?array $providers = null;

	/**
	 * Provider classes in display order.
	 *
	 * @return array<string,class-string<AIProviderInterface>>
	 */
	private static function classes(): array {
		$classes = array(
			'openai'            => OpenAIProvider::class,
			'claude'            => ClaudeProvider::class,
			'gemini'            => GeminiProvider::class,
			'groq'              => GroqProvider::class,
			'deepseek'          => DeepSeekProvider::class,
			'ollama'            => OllamaProvider::class,
			'openai_compatible' => OpenAICompatibleProvider::class,
		);
		if ( WordPressAIProvider::is_available() ) {
			$classes['wp_ai'] = WordPressAIProvider::class;
		}
		return $classes;
	}

	/**
	 * All providers (built-in + registered through the filter).
	 *
	 * @return array<string,AIProviderInterface>
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}
		$list = array();
		foreach ( self::classes() as $class ) {
			$list[] = new $class();
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
	 * Fresh instance with overrides (a key or URL typed but not yet saved),
	 * so the settings screen can list models / test before saving.
	 *
	 * @param string               $id     Provider id.
	 * @param array<string,string> $config Overrides: api_key|base_url|model (empty values ignored).
	 * @return AIProviderInterface|null
	 */
	public function make( string $id, array $config = array() ): ?AIProviderInterface {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) ) {
			return $this->get( $id );
		}
		$config = array_filter(
			$config,
			static fn( $v ): bool => is_string( $v ) && '' !== trim( $v )
		);
		if ( ! $config ) {
			return $this->get( $id );
		}
		$class = $classes[ $id ];
		return 'wp_ai' === $id ? new $class() : new $class( $config );
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
	 * Descriptor for the settings screen (never includes secrets).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ui_providers(): array {
		$out = array();
		foreach ( $this->all() as $id => $provider ) {
			$settings = self::SETTINGS[ $id ] ?? array(
				'key'        => '',
				'url'        => '',
				'model'      => '',
				'key_link'   => '',
				'free_model' => true,
			);
			$out[]    = array(
				'id'           => $id,
				'label'        => $provider->label(),
				'description'  => $provider->description(),
				'configured'   => $provider->is_configured(),
				'external'     => $provider->is_external(),
				'model'        => $provider->model(),
				'catalog'      => $provider->catalog(),
				'key_option'   => $settings['key'],
				'url_option'   => $settings['url'],
				'model_option' => $settings['model'],
				'key_link'     => $settings['key_link'],
				'free_model'   => $settings['free_model'],
				'key_locked'   => '' !== $settings['key'] && Options::secret_is_constant( $settings['key'] ),
				'key_mask'     => '' !== $settings['key'] ? Options::secret_mask( $settings['key'] ) : '',
			);
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
