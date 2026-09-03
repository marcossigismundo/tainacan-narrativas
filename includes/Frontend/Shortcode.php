<?php
/**
 * [tainacan_narrativa] shortcode.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [tainacan_narrativa] (current item) or [tainacan_narrativa item_id="123"].
 * Optional: title="..." transcript="0".
 */
final class Shortcode {

	public const TAG = 'tainacan_narrativa';

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
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Renders.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts    = shortcode_atts(
			array(
				'item_id'    => 0,
				'title'      => '',
				'transcript' => '1',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$item_id = absint( $atts['item_id'] );
		if ( 0 === $item_id ) {
			$item_id = (int) get_the_ID();
		}
		$args = array( 'show_transcript' => '0' !== (string) $atts['transcript'] );
		if ( '' !== trim( (string) $atts['title'] ) ) {
			$args['title'] = sanitize_text_field( (string) $atts['title'] );
		}
		return $this->player->render_for_item( $item_id, $args );
	}
}
