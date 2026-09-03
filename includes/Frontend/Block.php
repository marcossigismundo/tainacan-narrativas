<?php
/**
 * Dynamic block tainacan-narrativas/player.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For block themes / manual placement. itemId 0 = item in the current loop.
 */
final class Block {

	public const NAME          = 'tainacan-narrativas/player';
	public const EDITOR_HANDLE = 'tn-block-editor';

	/**
	 * Player.
	 *
	 * @var Player
	 */
	private Player $player;

	/**
	 * Constructor.
	 *
	 * @param Player $player Player.
	 */
	public function __construct( Player $player ) {
		$this->player = $player;
	}

	/**
	 * Registers block + editor script.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			self::EDITOR_HANDLE,
			TN_PLUGIN_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			TN_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'tainacan-narrativas' );

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 3,
				'editor_script'   => self::EDITOR_HANDLE,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'itemId'         => array(
						'type'    => 'number',
						'default' => 0,
					),
					'title'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'showTranscript' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	/**
	 * Render callback.
	 *
	 * @param array<string,mixed> $attributes Attributes.
	 * @return string
	 */
	public function render( array $attributes ): string {
		$item_id = isset( $attributes['itemId'] ) ? (int) $attributes['itemId'] : 0;
		if ( $item_id <= 0 ) {
			$item_id = (int) get_the_ID();
		}
		$args = array( 'show_transcript' => ! isset( $attributes['showTranscript'] ) || ! empty( $attributes['showTranscript'] ) );
		if ( ! empty( $attributes['title'] ) ) {
			$args['title'] = sanitize_text_field( (string) $attributes['title'] );
		}
		$html = $this->player->render_for_item( $item_id, $args );
		if ( '' === $html && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return '<p class="tn-block-placeholder">' . esc_html__( 'Nenhuma narrativa pronta para este item (ou item não informado).', 'tainacan-narrativas' ) . '</p>';
		}
		return $html;
	}
}
