<?php
/**
 * Admin-post handlers for settings, collection config and the wizard.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Admin;

use TainacanNarrativas\Core\Capabilities;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Core\Plugin;
use TainacanNarrativas\Narrative\Modes;
use TainacanNarrativas\Security\Security;
use TainacanNarrativas\Tainacan\CollectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic forms (no JS dependency): nonce + capability + sanitizer + redirect.
 * Secrets: an empty field keeps the stored value; "__clear__" removes it;
 * a wp-config constant makes the field read-only.
 */
final class SettingsHandler {

	/**
	 * Registers admin-post actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_tn_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_tn_save_collection', array( $this, 'save_collection' ) );
		add_action( 'admin_post_tn_wizard', array( $this, 'wizard' ) );
	}

	/**
	 * Saves global settings.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		$this->guard( 'tn_settings', Capabilities::MANAGE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in guard() via check_admin_referer(); the array is sanitized field-by-field (types, ranges, allowlists) in sanitize_settings().
		$raw = isset( $_POST['tn'] ) && is_array( $_POST['tn'] ) ? wp_unslash( $_POST['tn'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$tab = isset( $_POST['tn_tab'] ) ? sanitize_key( wp_unslash( $_POST['tn_tab'] ) ) : 'settings';

		$errors = array();
		Options::update( self::sanitize_settings( $raw, $errors ) );
		$this->redirect( $tab, $errors ? 'error' : 'saved', $errors );
	}

	/**
	 * Saves one collection entry.
	 *
	 * @return void
	 */
	public function save_collection(): void {
		$this->guard( 'tn_collection', Capabilities::MANAGE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$cid = isset( $_POST['collection_id'] ) ? absint( wp_unslash( $_POST['collection_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in guard(); the array is sanitized field-by-field in CollectionSettings::sanitize_entry().
		$raw = isset( $_POST['tnc'] ) && is_array( $_POST['tnc'] ) ? wp_unslash( $_POST['tnc'] ) : array();
		if ( $cid <= 0 ) {
			$this->redirect( 'collections', 'error', array( __( 'Coleção inválida.', 'tainacan-narrativas' ) ) );
		}
		CollectionSettings::save( $cid, CollectionSettings::sanitize_entry( $raw ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'collection' => $cid,
					'tn_notice'  => 'saved',
				),
				Plugin::admin_url( 'collections' )
			)
		);
		exit;
	}

	/**
	 * First-run wizard.
	 *
	 * @return void
	 */
	public function wizard(): void {
		$this->guard( 'tn_wizard', Capabilities::MANAGE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$post = wp_unslash( $_POST );

		if ( ! empty( $post['skip'] ) ) {
			Options::update( array( 'setup_done' => 1 ) );
			$this->redirect( '', 'wizard_skipped' );
		}

		$collections = isset( $post['collections'] ) && is_array( $post['collections'] ) ? array_map( 'absint', $post['collections'] ) : array();
		foreach ( array_filter( $collections ) as $cid ) {
			$entry            = CollectionSettings::get( $cid );
			$entry['enabled'] = 1;
			CollectionSettings::save( $cid, $entry );
		}

		$update                 = array(
			'setup_done'   => 1,
			'enabled'      => 1,
			'default_mode' => Modes::sanitize( $post['mode'] ?? 'documentary' ),
		);
		$tts                    = isset( $post['tts_provider'] ) ? sanitize_key( (string) $post['tts_provider'] ) : 'browser';
		$update['tts_provider'] = in_array( $tts, array( 'browser', 'openai_compatible', 'piper_http', 'wp_ai' ), true ) ? $tts : 'browser';
		if ( ! empty( $post['tts_base_url'] ) ) {
			$update['tts_base_url'] = esc_url_raw( (string) $post['tts_base_url'] );
		}
		if ( ! empty( $post['tts_voice'] ) ) {
			$update['tts_voice'] = sanitize_text_field( (string) $post['tts_voice'] );
		}
		$ai                    = isset( $post['ai_provider'] ) ? sanitize_key( (string) $post['ai_provider'] ) : 'none';
		$update['ai_provider'] = in_array( $ai, array( 'none', 'openai', 'claude', 'gemini', 'groq', 'deepseek', 'ollama', 'openai_compatible', 'wp_ai' ), true ) ? $ai : 'none';
		if ( ! empty( $post['ai_base_url'] ) ) {
			$update['ai_base_url'] = esc_url_raw( (string) $post['ai_base_url'] );
		}
		if ( ! empty( $post['ai_model'] ) ) {
			$update['ai_model'] = sanitize_text_field( (string) $post['ai_model'] );
		}
		if ( ! empty( $post['allow_private_endpoints'] ) ) {
			$update['allow_private_endpoints'] = 1;
		}
		$flow                     = isset( $post['editorial_flow'] ) ? sanitize_key( (string) $post['editorial_flow'] ) : 'review';
		$update['editorial_flow'] = in_array( $flow, array( 'auto', 'review' ), true ) ? $flow : 'review';
		Options::update( $update );

		$test_item = isset( $post['test_item'] ) ? absint( $post['test_item'] ) : 0;
		if ( $test_item > 0 ) {
			$manager = Plugin::instance()->manager();
			if ( $manager ) {
				$job = $manager->enqueue(
					$test_item,
					array(
						'action' => 'generate',
						'force'  => true,
					),
					1
				);
				if ( is_wp_error( $job ) ) {
					$this->redirect( 'narratives', 'error', array( $job->get_error_message() ) );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'tn_notice' => 'wizard_done',
							'run_queue' => 1,
						),
						Plugin::admin_url( 'narratives' )
					)
				);
				exit;
			}
		}
		$this->redirect( '', 'wizard_done' );
	}

	/**
	 * Nonce + capability guard.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param string $cap          Capability.
	 * @return void
	 */
	private function guard( string $nonce_action, string $cap ): void {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Você não tem permissão para esta ação.', 'tainacan-narrativas' ), 403 );
		}
	}

	/**
	 * Redirects back to the plugin page with a notice.
	 *
	 * @param string   $tab    Tab.
	 * @param string   $notice Notice key.
	 * @param string[] $errors Error messages (stored in a short transient).
	 * @return void
	 */
	private function redirect( string $tab, string $notice, array $errors = array() ): void {
		if ( $errors ) {
			set_transient( 'tn_notice_errors_' . get_current_user_id(), $errors, 60 );
		}
		wp_safe_redirect( add_query_arg( 'tn_notice', $notice, Plugin::admin_url( $tab ) ) );
		exit;
	}

	/**
	 * Sanitizes the global settings form.
	 *
	 * @param array<string,mixed> $in     Raw (unslashed) input.
	 * @param string[]            $errors Collected validation messages (by ref).
	 * @return array<string,mixed> Values to merge into the option.
	 */
	public static function sanitize_settings( array $in, array &$errors ): array {
		$out = array();

		$bools = array( 'enabled', 'autoinject', 'cron_enabled', 'auto_coverage', 'ai_analysis', 'allow_download', 'provenance_notice', 'include_description', 'include_document', 'include_attachments', 'external_ai_attachments', 'allow_private_endpoints', 'debug', 'delete_on_uninstall', 'children_mode' );
		if ( isset( $in['__bools'] ) && is_array( $in['__bools'] ) ) {
			foreach ( $in['__bools'] as $key ) {
				$key = sanitize_key( (string) $key );
				if ( in_array( $key, $bools, true ) ) {
					$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
				}
			}
		}

		$ints = array(
			'cron_batch'       => array( 1, 20 ),
			'cron_time_budget' => array( 5, 120 ),
			'max_attempts'     => array( 1, 10 ),
			'coverage_batch'   => array( 1, 2000 ),
			'max_words'        => array( 60, 1500 ),
			'keep_versions'    => array( 0, 50 ),
			'max_attachments'  => array( 0, 50 ),
			'max_chars_item'   => array( 1000, 500000 ),
			'max_chars_file'   => array( 500, 300000 ),
			'chunk_size'       => array( 1500, 30000 ),
			'ai_timeout'       => array( 10, 600 ),
			'ai_max_tokens'    => array( 200, 32000 ),
			'tts_timeout'      => array( 10, 900 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $in[ $key ] ) && '' !== $in[ $key ] ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $in[ $key ] ) );
			}
		}
		$floats = array(
			'ai_temperature' => array( 0.0, 2.0 ),
			'tts_speed'      => array( 0.5, 2.0 ),
			'browser_rate'   => array( 0.5, 2.0 ),
			'browser_pitch'  => array( 0.5, 2.0 ),
		);
		foreach ( $floats as $key => $range ) {
			if ( isset( $in[ $key ] ) && '' !== $in[ $key ] ) {
				$out[ $key ] = max( $range[0], min( $range[1], (float) str_replace( ',', '.', (string) $in[ $key ] ) ) );
			}
		}

		$enums = array(
			'trigger_on_save'      => array( 'none', 'mark_stale', 'queue' ),
			'editorial_flow'       => array( 'auto', 'review' ),
			'ai_provider'          => array( 'none', 'openai', 'claude', 'gemini', 'groq', 'deepseek', 'ollama', 'openai_compatible', 'wp_ai' ),
			'tts_provider'         => array( 'browser', 'openai_compatible', 'piper_http', 'wp_ai' ),
			'tts_format'           => array( 'mp3', 'wav' ),
			'piper_payload'        => array( 'json', 'raw' ),
			'browser_voice_gender' => array( 'female', 'male', 'any' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $in[ $key ] ) ) {
				$val = sanitize_key( (string) $in[ $key ] );
				if ( in_array( $val, $allowed, true ) ) {
					$out[ $key ] = $val;
				}
			}
		}
		if ( isset( $in['default_mode'] ) ) {
			$out['default_mode'] = Modes::sanitize( $in['default_mode'] );
		}

		$texts = array( 'ai_model', 'openai_model', 'claude_model', 'gemini_model', 'groq_model', 'deepseek_model', 'ollama_model', 'tts_model', 'tts_voice', 'piper_voice', 'browser_voice_hint', 'default_language', 'browser_lang' );
		foreach ( $texts as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $in[ $key ] );
			}
		}
		if ( isset( $in['template_script'] ) ) {
			$out['template_script'] = sanitize_textarea_field( (string) $in['template_script'] );
		}

		$allow_private = isset( $out['allow_private_endpoints'] ) ? (bool) $out['allow_private_endpoints'] : Options::is( 'allow_private_endpoints' );
		foreach ( array( 'ai_base_url', 'ollama_base_url', 'tts_base_url', 'piper_url' ) as $key ) {
			if ( ! isset( $in[ $key ] ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) $in[ $key ] ) );
			if ( '' !== $url ) {
				$valid = Security::validate_endpoint( $url, $allow_private );
				if ( is_wp_error( $valid ) ) {
					/* translators: 1: field key, 2: validation message. */
					$errors[] = sprintf( __( '%1$s: %2$s', 'tainacan-narrativas' ), $key, $valid->get_error_message() );
					continue;
				}
			}
			$out[ $key ] = $url;
		}

		foreach ( array_keys( Options::SECRET_CONSTANTS ) as $key ) {
			if ( Options::secret_is_constant( $key ) || ! isset( $in[ $key ] ) ) {
				continue;
			}
			$val = trim( (string) $in[ $key ] );
			if ( '' === $val ) {
				continue; // Keep stored value.
			}
			$out[ $key ] = '__clear__' === $val ? '' : sanitize_text_field( $val );
		}

		return $out;
	}
}
