<?php
/**
 * Global settings access.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the single `tn_settings` option with safe defaults.
 *
 * Secrets (API keys) may be provided by wp-config.php constants, which always
 * win over the stored option and lock the field in the UI:
 *
 *   define( 'TN_AI_API_KEY', '...' );      // OpenAI-compatible / OpenAI
 *   define( 'TN_GEMINI_API_KEY', '...' );  // Google Gemini
 *   define( 'TN_TTS_API_KEY', '...' );     // Neural TTS endpoint
 */
final class Options {

	public const OPTION = 'tn_settings';

	/**
	 * Map of secret option keys to their wp-config constant.
	 */
	public const SECRET_CONSTANTS = array(
		'ai_api_key'     => 'TN_AI_API_KEY',
		'gemini_api_key' => 'TN_GEMINI_API_KEY',
		'tts_api_key'    => 'TN_TTS_API_KEY',
	);

	/**
	 * In-request cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values. Conservative on purpose: a fresh install never calls an
	 * external service, never generates in bulk and never touches items.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'setup_done'              => 0,
			'enabled'                 => 0,
			'autoinject'              => 1,
			'trigger_on_save'         => 'queue', // none | mark_stale | queue.
			'cron_enabled'            => 1,
			'cron_batch'              => 3,
			'cron_time_budget'        => 20,
			'max_attempts'            => 3,
			'auto_coverage'           => 1,
			'coverage_batch'          => 150,
			'default_mode'            => 'documentary',
			'default_language'        => 'pt-BR',
			'editorial_flow'          => 'auto', // auto | review.
			'allow_download'          => 0,
			'provenance_notice'       => 1,
			'keep_versions'           => 3,
			// Content limits.
			'include_description'     => 1,
			'include_document'        => 1,
			'include_attachments'     => 1,
			'max_attachments'         => 5,
			'max_chars_item'          => 60000,
			'max_chars_file'          => 40000,
			'chunk_size'              => 6000,
			// AI.
			'ai_provider'             => 'none', // none | openai_compatible | openai | ollama | gemini | wp_ai.
			'ai_base_url'             => '',
			'ai_model'                => '',
			'ai_api_key'              => '',
			'ai_timeout'              => 180,
			'ai_temperature'          => 0.45,
			'ai_max_tokens'           => 4000,
			'ai_analysis'             => 1,
			'ollama_base_url'         => 'http://127.0.0.1:11434',
			'ollama_model'            => 'llama3.2',
			'gemini_model'            => 'gemini-2.5-flash',
			'gemini_api_key'          => '',
			'external_ai_attachments' => 1,
			// TTS.
			'tts_provider'            => 'browser', // browser | openai_compatible | piper_http | wp_ai.
			'tts_base_url'            => '',
			'tts_api_key'             => '',
			'tts_model'               => 'kokoro',
			'tts_voice'               => 'pf_dora',
			'tts_speed'               => 1.0,
			'tts_format'              => 'mp3',
			'tts_timeout'             => 180,
			'piper_url'               => '',
			'piper_voice'             => '',
			'piper_payload'           => 'json', // json (piper1-gpl) | raw (legacy rhasspy server).
			'children_mode'           => 0,
			'browser_lang'            => 'pt-BR',
			'browser_voice_hint'      => '',
			'browser_voice_gender'    => 'female', // female | male | any.
			'browser_rate'            => 1.0,
			'browser_pitch'           => 1.0,
			// Security & misc.
			'allow_private_endpoints' => 0,
			'debug'                   => 0,
			'delete_on_uninstall'     => 0,
			'template_script'         => '',
		);
	}

	/**
	 * Full settings array merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Boolean accessor.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is( string $key ): bool {
		return ! empty( self::get( $key ) );
	}

	/**
	 * Persists a partial update (merge).
	 *
	 * @param array<string,mixed> $values Values to merge.
	 * @return void
	 */
	public static function update( array $values ): void {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION, $merged, true );
		self::$cache = null;
	}

	/**
	 * Clears the in-request cache (after a raw update_option elsewhere).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Resolves a secret: wp-config constant first, then stored option.
	 *
	 * @param string $key One of the SECRET_CONSTANTS keys.
	 * @return string
	 */
	public static function secret( string $key ): string {
		$constant = self::SECRET_CONSTANTS[ $key ] ?? '';
		if ( '' !== $constant && defined( $constant ) ) {
			$value = constant( $constant );
			return is_string( $value ) ? $value : '';
		}
		$value = self::get( $key, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether a secret is locked by a wp-config constant.
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function secret_is_constant( string $key ): bool {
		$constant = self::SECRET_CONSTANTS[ $key ] ?? '';
		return '' !== $constant && defined( $constant );
	}

	/**
	 * Masked representation safe for HTML/JSON (never the raw key).
	 *
	 * @param string $key Secret key.
	 * @return string
	 */
	public static function secret_mask( string $key ): string {
		$value = self::secret( $key );
		if ( '' === $value ) {
			return '';
		}
		$len = strlen( $value );
		return str_repeat( '*', 8 ) . ( $len > 4 ? substr( $value, -4 ) : '' );
	}
}
