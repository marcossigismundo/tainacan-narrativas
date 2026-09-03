<?php
/**
 * Player markup. Included by Player::render_for_item() inside ob_start().
 *
 * Treated as untrusted input: everything is escaped here, at the output point.
 *
 * @var int                 $item_id    Item ID.
 * @var array<string,mixed> $payload    Public payload.
 * @var array<string,mixed> $config     JS config.
 * @var array<string,mixed> $args       title|show_transcript.
 * @var string              $mode_label Mode label.
 * @var string[]            $paragraphs Transcript paragraphs.
 * @var string              $duration   Formatted duration.
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_uid = 'tn-player-' . (int) $item_id;
?>
<section class="tn-player" id="<?php echo esc_attr( $tn_uid ); ?>" data-tn-player="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>" aria-labelledby="<?php echo esc_attr( $tn_uid ); ?>-title" lang="<?php echo esc_attr( (string) $payload['language'] ); ?>">
	<header class="tn-player__header">
		<span class="tn-player__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path fill="currentColor" d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.5 4.5 0 0 0 16.5 12zM14 3.23v2.06a7 7 0 0 1 0 13.42v2.06A9 9 0 0 0 14 3.23z"/></svg>
		</span>
		<h2 class="tn-player__title" id="<?php echo esc_attr( $tn_uid ); ?>-title"><?php echo esc_html( (string) $args['title'] ); ?></h2>
		<p class="tn-player__meta">
			<?php if ( '' !== $mode_label ) : ?>
				<span class="tn-player__mode"><?php echo esc_html( $mode_label ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $duration ) : ?>
				<span class="tn-player__duration" data-tn-duration><?php echo esc_html( $duration ); ?></span>
			<?php endif; ?>
			<?php if ( 'browser' === $payload['playback'] ) : ?>
				<span class="tn-player__badge"><?php esc_html_e( 'Voz do navegador', 'tainacan-narrativas' ); ?></span>
			<?php endif; ?>
		</p>
	</header>

	<div class="tn-player__controls" data-tn-controls>
		<?php if ( 'audio' === $payload['playback'] && '' !== $payload['audio_url'] ) : ?>
			<audio class="tn-player__audio" preload="metadata" controls data-tn-audio>
				<source src="<?php echo esc_url( (string) $payload['audio_url'] ); ?>" <?php echo '' !== $payload['audio_mime'] ? 'type="' . esc_attr( (string) $payload['audio_mime'] ) . '"' : ''; ?>>
			</audio>
		<?php else : ?>
			<noscript><p class="tn-player__notice"><?php esc_html_e( 'Ative o JavaScript para ouvir a narrativa com a voz do navegador. O texto completo está disponível abaixo.', 'tainacan-narrativas' ); ?></p></noscript>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $args['show_transcript'] ) ) : ?>
		<details class="tn-player__transcript" data-tn-transcript>
			<summary class="tn-player__transcript-toggle"><?php esc_html_e( 'Ver texto', 'tainacan-narrativas' ); ?></summary>
			<div class="tn-player__transcript-body" data-tn-transcript-body>
				<?php foreach ( $paragraphs as $tn_paragraph ) : ?>
					<?php if ( '' !== trim( $tn_paragraph ) ) : ?>
						<p><?php echo esc_html( $tn_paragraph ); ?></p>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</details>
	<?php endif; ?>

	<?php if ( ! empty( $payload['allow_download'] ) && '' !== $payload['audio_url'] ) : ?>
		<p class="tn-player__download">
			<a href="<?php echo esc_url( (string) $payload['audio_url'] ); ?>" download><?php esc_html_e( 'Baixar áudio', 'tainacan-narrativas' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( '' !== (string) $payload['provenance'] ) : ?>
		<footer class="tn-player__provenance"><?php echo esc_html( (string) $payload['provenance'] ); ?></footer>
	<?php endif; ?>
</section>
