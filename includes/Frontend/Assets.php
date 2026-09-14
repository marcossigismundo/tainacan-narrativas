<?php
/**
 * Front-end asset registration.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything is served from the plugin folder — no CDN, no web fonts.
 */
final class Assets {

	public const HANDLE_STYLE  = 'tn-player';
	public const HANDLE_SCRIPT = 'tn-player';

	/**
	 * Registers (idempotent).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( wp_script_is( self::HANDLE_SCRIPT, 'registered' ) ) {
			return;
		}
		wp_register_style( self::HANDLE_STYLE, TN_PLUGIN_URL . 'assets/css/player.css', array(), TN_VERSION );
		wp_register_script( self::HANDLE_SCRIPT, TN_PLUGIN_URL . 'assets/js/player.js', array(), TN_VERSION, true );
		wp_localize_script(
			self::HANDLE_SCRIPT,
			'tnPlayerI18n',
			array(
				'play'          => __( 'Reproduzir', 'tainacan-narrativas' ),
				'pause'         => __( 'Pausar', 'tainacan-narrativas' ),
				'resume'        => __( 'Continuar', 'tainacan-narrativas' ),
				'back'          => __( 'Voltar 10 segundos', 'tainacan-narrativas' ),
				'forward'       => __( 'Avançar 10 segundos', 'tainacan-narrativas' ),
				'progress'      => __( 'Posição da reprodução', 'tainacan-narrativas' ),
				'volume'        => __( 'Volume', 'tainacan-narrativas' ),
				'speed'         => __( 'Velocidade', 'tainacan-narrativas' ),
				'showText'      => __( 'Ver texto', 'tainacan-narrativas' ),
				'hideText'      => __( 'Ocultar texto', 'tainacan-narrativas' ),
				'download'      => __( 'Baixar áudio', 'tainacan-narrativas' ),
				'unsupported'   => __( 'Seu navegador não oferece síntese de voz. O texto da narrativa está disponível abaixo.', 'tainacan-narrativas' ),
				'loadingVoices' => __( 'Preparando a voz…', 'tainacan-narrativas' ),
				'voice'         => __( 'Voz', 'tainacan-narrativas' ),
				'browserVoice'  => __( 'Voz do navegador', 'tainacan-narrativas' ),
				'timeOf'        => /* translators: 1: current time, 2: total duration. */ __( '%1$s de %2$s', 'tainacan-narrativas' ),
				'error'         => __( 'Não foi possível reproduzir o áudio.', 'tainacan-narrativas' ),
			)
		);
		wp_set_script_translations( self::HANDLE_SCRIPT, 'tainacan-narrativas' );
	}

	/**
	 * Enqueues both assets.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$this->register();
		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_SCRIPT );
	}
}
