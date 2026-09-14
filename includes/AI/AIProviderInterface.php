<?php
/**
 * Generative AI provider contract.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One text-generation call at a time; the narrative pipeline (chunking,
 * consolidation, mode prompt, faithfulness check) lives in NarrativeGenerator
 * and is provider-agnostic.
 *
 * Providers are constructed from the saved options; ProviderManager::make()
 * can also build one with overrides (an API key typed but not yet saved) so
 * the admin can list a provider's models before saving.
 */
interface AIProviderInterface {

	/**
	 * Unique id (stored in the narrative row).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * One-sentence description for the provider card.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Whether the provider has what it needs (URL, key, model…).
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Whether data leaves the server (privacy gate for collections).
	 *
	 * @return bool
	 */
	public function is_external(): bool;

	/**
	 * Model identifier that will be used.
	 *
	 * @return string
	 */
	public function model(): string;

	/**
	 * Built-in model catalog (may be empty for free-form providers).
	 *
	 * @return array<int,array{id:string,name:string,description:string}>
	 */
	public function catalog(): array;

	/**
	 * Generates text.
	 *
	 * @param string              $system  System prompt.
	 * @param string              $user    User prompt (contains the SOURCE blocks).
	 * @param array<string,mixed> $options temperature|max_tokens|timeout overrides.
	 * @return array{text:string,model:string,usage:array{prompt_tokens:int,completion_tokens:int}}|\WP_Error
	 */
	public function generate( string $system, string $user, array $options = array() );

	/**
	 * Connectivity/credentials test that never reveals secrets.
	 *
	 * @return array{success:bool,message:string,details:array<string,mixed>}
	 */
	public function test(): array;

	/**
	 * Models the account/endpoint actually offers (remote list; may be empty).
	 *
	 * @return string[] Model ids.
	 */
	public function list_models(): array;
}
