<?php
/**
 * Public player rendering and item-page injection.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Frontend;

use TainacanNarrativas\Narrative\Modes;
use TainacanNarrativas\Narrative\NarrativeManager;
use TainacanNarrativas\Narrative\Normalizer;
use TainacanNarrativas\Tainacan\CollectionSettings;
use TainacanNarrativas\Tainacan\ItemDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injection points (same pattern as tainacan-wacz-player), guarded per request:
 *
 *  1. tainacan-interface-single-item-after-attachments — official Tainacan
 *     theme (tainacan-interface / tainacan-theme), right below Attachments.
 *  2. tainacan_single_item_content — core's default single-item content for
 *     themes without their own template (blocksy, twenty-*).
 *  3. the_content — generic fallback for classic themes.
 *
 * Block themes use the tainacan-narrativas/player block or the shortcode.
 * The page only reproduces previously generated content; nothing is
 * generated on a public request.
 */
final class Player {

	/**
	 * Manager.
	 *
	 * @var NarrativeManager
	 */
	private NarrativeManager $manager;

	/**
	 * Assets.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Items rendered in this request.
	 *
	 * @var array<int,bool>
	 */
	private array $rendered = array();

	/**
	 * Constructor.
	 *
	 * @param NarrativeManager $manager Manager.
	 * @param Assets           $assets  Assets.
	 */
	public function __construct( NarrativeManager $manager, Assets $assets ) {
		$this->manager = $manager;
		$this->assets  = $assets;
	}

	/**
	 * Registers injection hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'tainacan-interface-single-item-after-attachments', array( $this, 'echo_after_attachments' ) );
		add_filter( 'tainacan_single_item_content', array( $this, 'append_to_item_content' ), 20, 2 );
		add_filter( 'the_content', array( $this, 'append_to_post_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueues assets early on single item pages of enabled collections.
	 *
	 * @return void
	 */
	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_type = get_post_type();
		if ( ! ItemDetector::is_item_post_type( is_string( $post_type ) ? $post_type : null ) ) {
			return;
		}
		$config = CollectionSettings::effective( ItemDetector::collection_id_from_post_type( (string) $post_type ) );
		if ( $config['enabled'] && $config['autoinject'] ) {
			$this->assets->enqueue();
		}
	}

	/**
	 * Theme action: echo below attachments.
	 *
	 * @return void
	 */
	public function echo_after_attachments(): void {
		$html = $this->render_auto( (int) get_the_ID() );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped at every output point inside Player::render().
	}

	/**
	 * Core filter: append to default single content.
	 *
	 * @param string $content Content.
	 * @param mixed  $item    Item entity.
	 * @return string
	 */
	public function append_to_item_content( $content, $item = null ) {
		$item_id = ( is_object( $item ) && method_exists( $item, 'get_id' ) ) ? (int) $item->get_id() : (int) get_the_ID();
		return $content . $this->render_auto( $item_id );
	}

	/**
	 * Generic the_content fallback.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function append_to_post_content( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post_type = get_post_type();
		if ( ! ItemDetector::is_item_post_type( is_string( $post_type ) ? $post_type : null ) ) {
			return $content;
		}
		return $content . $this->render_auto( (int) get_the_ID() );
	}

	/**
	 * Auto-injection wrapper (respects autoinject + double render guard).
	 *
	 * @param int $item_id Item ID.
	 * @return string
	 */
	private function render_auto( int $item_id ): string {
		if ( $item_id <= 0 || isset( $this->rendered[ $item_id ] ) ) {
			return '';
		}
		$post = get_post( $item_id );
		if ( ! $post instanceof \WP_Post || ! ItemDetector::is_item_post_type( $post->post_type ) ) {
			return '';
		}
		$config = CollectionSettings::effective( ItemDetector::collection_id_from_post_type( $post->post_type ) );
		if ( ! $config['enabled'] || ! $config['autoinject'] ) {
			return '';
		}
		return $this->render_for_item( $item_id );
	}

	/**
	 * Renders the player for an item (shortcode/block/auto). Empty when there
	 * is nothing ready to play.
	 *
	 * @param int                 $item_id Item ID.
	 * @param array<string,mixed> $args    title|show_transcript.
	 * @return string
	 */
	public function render_for_item( int $item_id, array $args = array() ): string {
		if ( $item_id <= 0 ) {
			return '';
		}
		$payload = $this->manager->public_payload( $item_id );
		if ( ! $payload ) {
			return '';
		}
		$this->rendered[ $item_id ] = true;
		$this->assets->enqueue();

		$args = wp_parse_args(
			$args,
			array(
				'title'           => __( 'Ouvir este item', 'tainacan-narrativas' ),
				'show_transcript' => true,
			)
		);

		$config = array(
			'itemId'        => $item_id,
			'playback'      => $payload['playback'],
			'audioUrl'      => $payload['audio_url'],
			'audioMime'     => $payload['audio_mime'],
			'duration'      => (float) $payload['duration'],
			'allowDownload' => (bool) $payload['allow_download'],
			'lang'          => $payload['browser']['lang'],
			'voiceHint'     => $payload['browser']['voice'],
			'rate'          => (float) $payload['browser']['rate'],
			'title'         => get_the_title( $item_id ),
			'restUrl'       => rest_url( 'tainacan-narrativas/v1/public/items/' . $item_id ),
		);

		$mode_label = Modes::labels()[ $payload['mode'] ] ?? '';
		$paragraphs = preg_split( '/\n{2,}/u', (string) $payload['transcript'] );
		$paragraphs = false === $paragraphs ? array() : $paragraphs;
		$duration   = $this->format_duration( (float) $payload['duration'] );

		ob_start();
		include TN_PLUGIN_DIR . 'includes/Frontend/views/player.php';
		return (string) ob_get_clean();
	}

	/**
	 * Formats seconds as m:ss (or h:mm:ss).
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	private function format_duration( float $seconds ): string {
		$seconds = (int) round( $seconds );
		if ( $seconds <= 0 ) {
			return '';
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	/**
	 * Sentence list for the transcript (exposed for tests).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function sentences( string $text ): array {
		return Normalizer::sentences( $text );
	}
}
