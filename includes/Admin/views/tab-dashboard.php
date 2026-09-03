<?php
/**
 * Dashboard cards.
 *
 * @var array<string,mixed> $stats
 * @var array<string,int>   $job_counts
 * @var array<string,string> $status_labels
 * @var string              $base_url
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_total_seconds = (float) $stats['total_duration'];
$tn_hours         = floor( $tn_total_seconds / 3600 );
$tn_minutes       = floor( ( $tn_total_seconds - $tn_hours * 3600 ) / 60 );
$tn_cards         = array(
	array( __( 'Narrativas prontas', 'tainacan-narrativas' ), (string) (int) $stats['ready'], 'ready', 'ok' ),
	array( __( 'Pendentes (fila / processando)', 'tainacan-narrativas' ), (string) ( (int) $stats['queued'] + (int) $stats['extracting'] + (int) $stats['scripting'] + (int) $stats['synthesizing'] + (int) $job_counts['queued'] ), 'queued', 'info' ),
	array( __( 'Aguardando revisão', 'tainacan-narrativas' ), (string) (int) $stats['review'], 'review', 'warn' ),
	array( __( 'Desatualizadas', 'tainacan-narrativas' ), (string) (int) $stats['stale'], 'stale', 'warn' ),
	array( __( 'Com erro', 'tainacan-narrativas' ), (string) (int) $stats['error'], 'error', 'danger' ),
	/* translators: 1: hours, 2: minutes. */
	array( __( 'Tempo total de áudio', 'tainacan-narrativas' ), sprintf( __( '%1$dh %2$02dmin', 'tainacan-narrativas' ), (int) $tn_hours, (int) $tn_minutes ), '', 'info' ),
	array( __( 'Itens sem texto suficiente', 'tainacan-narrativas' ), (string) (int) $stats['insufficient'], 'insufficient', 'muted' ),
	array( __( 'Itens exigindo OCR', 'tainacan-narrativas' ), (string) (int) $stats['requires_ocr'], 'requires_ocr', 'muted' ),
);
?>
<div class="tn-cards">
	<?php foreach ( $tn_cards as $tn_card ) : ?>
		<?php $tn_href = '' !== $tn_card[2] ? $base_url . '&tab=narratives&status=' . $tn_card[2] : ''; ?>
		<a class="tn-card tn-card--<?php echo esc_attr( $tn_card[3] ); ?>" <?php echo '' !== $tn_href ? 'href="' . esc_url( $tn_href ) . '"' : ''; ?>>
			<span class="tn-card__value"><?php echo esc_html( $tn_card[1] ); ?></span>
			<span class="tn-card__label"><?php echo esc_html( $tn_card[0] ); ?></span>
		</a>
	<?php endforeach; ?>
</div>

<div class="tn-panel">
	<h2><?php esc_html_e( 'Gerar narrativa de um item', 'tainacan-narrativas' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Informe o ID de um item Tainacan de uma coleção habilitada. Visualize as fontes antes de gerar para conferir exatamente o que será usado (e, se houver IA externa, enviado).', 'tainacan-narrativas' ); ?></p>
	<div class="tn-inline-form">
		<label for="tn-quick-item" class="screen-reader-text"><?php esc_html_e( 'ID do item', 'tainacan-narrativas' ); ?></label>
		<input type="number" id="tn-quick-item" class="regular-text" min="1" placeholder="<?php esc_attr_e( 'ID do item', 'tainacan-narrativas' ); ?>">
		<button type="button" class="button" data-tn-action="preview-quick"><?php esc_html_e( 'Visualizar fontes', 'tainacan-narrativas' ); ?></button>
		<?php if ( $can_generate ) : ?>
			<button type="button" class="button button-primary" data-tn-action="generate-quick"><?php esc_html_e( 'Gerar narrativa', 'tainacan-narrativas' ); ?></button>
		<?php endif; ?>
	</div>
	<div class="tn-panel-output" data-tn-output hidden></div>
</div>
