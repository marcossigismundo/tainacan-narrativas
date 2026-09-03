<?php
/**
 * Loads prompt templates from prompts/.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompts live in prompts/<name>.php, each returning a string. Names are
 * validated against a strict pattern before touching the filesystem.
 */
final class PromptLoader {

	/**
	 * Loads a prompt by name.
	 *
	 * @param string $name File base name (a-z, 0-9, hyphen, underscore).
	 * @return string Empty when missing.
	 */
	public static function load( string $name ): string {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/', $name ) ) {
			return '';
		}
		$file = TN_PLUGIN_DIR . 'prompts/' . $name . '.php';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		$prompt = include $file;
		$prompt = is_string( $prompt ) ? $prompt : '';

		/**
		 * Filters a loaded prompt template.
		 *
		 * @param string $prompt Prompt text.
		 * @param string $name   Prompt name.
		 */
		$filtered = apply_filters( 'tainacan_narrativas_prompt', $prompt, $name );
		return is_string( $filtered ) ? $filtered : $prompt;
	}

	/**
	 * System prompt (factual safety + injection rules).
	 *
	 * @param string $language Language code (e.g. pt-BR).
	 * @return string
	 */
	public static function system( string $language ): string {
		$prompt = self::load( 'system' );
		$prompt = str_replace( '{language}', $language, $prompt );

		/**
		 * Filters the system prompt sent to AI providers.
		 *
		 * @param string $prompt   Prompt.
		 * @param string $language Language.
		 */
		$filtered = apply_filters( 'tainacan_narrativas_system_prompt', $prompt, $language );
		return is_string( $filtered ) ? $filtered : $prompt;
	}

	/**
	 * Mode instructions with placeholders resolved.
	 *
	 * @param string              $mode Mode id.
	 * @param array<string,mixed> $vars Placeholders: target_words, language, collection, title.
	 * @return string
	 */
	public static function mode( string $mode, array $vars ): string {
		$prompt = self::load( $mode );
		if ( '' === $prompt ) {
			$prompt = self::load( 'documentary' );
		}
		return self::fill( $prompt, $vars );
	}

	/**
	 * Replaces {placeholders}.
	 *
	 * @param string              $text Text.
	 * @param array<string,mixed> $vars Vars.
	 * @return string
	 */
	public static function fill( string $text, array $vars ): string {
		foreach ( $vars as $key => $value ) {
			$text = str_replace( '{' . $key . '}', (string) $value, $text );
		}
		return $text;
	}
}
