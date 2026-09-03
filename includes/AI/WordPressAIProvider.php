<?php
/**
 * WordPress core AI Client provider (WP 7.0+).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses wp_ai_client_prompt() and the connectors the site administrator already
 * configured in WordPress — no API key ever touches this plugin. Every call is
 * guarded because the builder API is still evolving between WP releases.
 */
final class WordPressAIProvider implements AIProviderInterface {

	/**
	 * Whether the core AI client exists.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'wp_ai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'WordPress AI (conectores do próprio site, WP 7.0+)', 'tainacan-narrativas' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		if ( ! self::is_available() ) {
			return false;
		}
		try {
			$builder = wp_ai_client_prompt( 'test' );
			return (bool) $builder->isSupportedForTextGeneration();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Connectors are typically cloud services; treat as external for privacy gating.
	 *
	 * @return bool
	 */
	public function is_external(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function model(): string {
		return 'auto';
	}

	/**
	 * Generates text through the site's AI connectors.
	 *
	 * @param string              $system  System prompt.
	 * @param string              $user    User prompt.
	 * @param array<string,mixed> $options temperature|max_tokens|timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( string $system, string $user, array $options = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'tn_ai_not_configured', __( 'O AI Client do WordPress não está disponível nesta instalação.', 'tainacan-narrativas' ) );
		}
		try {
			$builder = wp_ai_client_prompt( $user )
				->usingSystemInstruction( $system )
				->usingTemperature( (float) ( $options['temperature'] ?? Options::get( 'ai_temperature', 0.3 ) ) )
				->usingMaxTokens( (int) ( $options['max_tokens'] ?? Options::get( 'ai_max_tokens', 2500 ) ) );
			$result  = $builder->generateTextResult();
			$text    = (string) $result->toText();
		} catch ( \Throwable $e ) {
			Logger::warning( 'WP AI client failure', array( 'error' => $e->getMessage() ) );
			return new WP_Error( 'tn_ai_retryable', Logger::redact( $e->getMessage() ) );
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'tn_ai_malformed', __( 'O modelo devolveu uma resposta vazia.', 'tainacan-narrativas' ) );
		}

		$model  = 'auto';
		$prompt = 0;
		$comp   = 0;
		try {
			if ( method_exists( $result, 'getModelMetadata' ) ) {
				$model = (string) $result->getModelMetadata()->getId();
			}
			if ( method_exists( $result, 'getTokenUsage' ) ) {
				$usage = $result->getTokenUsage();
				if ( method_exists( $usage, 'getPromptTokens' ) ) {
					$prompt = (int) $usage->getPromptTokens();
				}
				if ( method_exists( $usage, 'getCompletionTokens' ) ) {
					$comp = (int) $usage->getCompletionTokens();
				}
			}
		} catch ( \Throwable $e ) {
			$model = 'auto';
		}
		return array(
			'text'  => trim( $text ),
			'model' => $model,
			'usage' => array(
				'prompt_tokens'     => $prompt,
				'completion_tokens' => $comp,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test(): array {
		if ( ! self::is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Requer WordPress 7.0+ com o AI Client.', 'tainacan-narrativas' ),
				'details' => array(),
			);
		}
		$ok = $this->is_configured();
		return array(
			'success' => $ok,
			'message' => $ok
				? __( 'Um conector de IA com geração de texto está configurado no WordPress.', 'tainacan-narrativas' )
				: __( 'Nenhum conector de IA com geração de texto está configurado no WordPress (Configurações → IA).', 'tainacan-narrativas' ),
			'details' => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_models(): array {
		return array();
	}
}
