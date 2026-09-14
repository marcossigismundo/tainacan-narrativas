<?php
/**
 * Dashboard cards.
 *
 * @var array<string,mixed> $stats
 * @var array<string,int>   $job_counts
 * @var array<string,string> $status_labels
 * @var string              $base_url
 * @var array<int,array>    $coverage
 * @var array<string,mixed> $settings
 * @var bool                $can_generate
 * @var bool                $can_manage
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_cov_items   = array_sum( array_column( $coverage, 'items' ) );
$tn_cov_ready   = array_sum( array_column( $coverage, 'ready' ) );
$tn_cov_review  = array_sum( array_column( $coverage, 'review' ) );
$tn_cov_missing = array_sum( array_column( $coverage, 'missing' ) ) + array_sum( array_column( $coverage, 'stale' ) ) + array_sum( array_column( $coverage, 'error' ) );

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
		<?php $tn_tag = '' !== $tn_href ? 'a' : 'span'; ?>
		<<?php echo esc_html( $tn_tag ); ?> class="tn-card tn-card--<?php echo esc_attr( $tn_card[3] ); ?>" <?php echo 'a' === $tn_tag ? 'href="' . esc_url( $tn_href ) . '"' : ''; ?>>
			<span class="tn-card__icon" aria-hidden="true"></span>
			<span class="tn-card__body">
				<span class="tn-card__value"><?php echo esc_html( $tn_card[1] ); ?></span>
				<span class="tn-card__label"><?php echo esc_html( $tn_card[0] ); ?></span>
			</span>
		</<?php echo esc_html( $tn_tag ); ?>>
	<?php endforeach; ?>
</div>

<div class="tn-panel">
	<div class="tn-panel__head">
		<h2><?php esc_html_e( 'Cobertura das coleções', 'tainacan-narrativas' ); ?></h2>
		<div class="tn-actions-row">
			<?php if ( $can_generate && $coverage ) : ?>
				<button type="button" class="button button-primary" data-tn-action="generate-all"><?php esc_html_e( 'Gerar todas as narrativas pendentes', 'tainacan-narrativas' ); ?></button>
			<?php endif; ?>
			<?php if ( $tn_cov_review > 0 ) : ?>
				<button type="button" class="button" data-tn-action="approve-all"><?php esc_html_e( 'Aprovar todas em revisão', 'tainacan-narrativas' ); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php if ( ! $coverage ) : ?>
		<p class="description"><?php esc_html_e( 'Nenhuma coleção habilitada. Habilite coleções na aba Coleções para que as narrativas sejam geradas.', 'tainacan-narrativas' ); ?></p>
	<?php else : ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: ready narratives, 2: published items, 3: pending items. */
				esc_html__( '%1$d de %2$d itens publicados já têm narrativa pronta; %3$d pendentes.', 'tainacan-narrativas' ),
				(int) $tn_cov_ready,
				(int) $tn_cov_items,
				(int) $tn_cov_missing
			);
			echo ' ';
			if ( ! empty( $settings['auto_coverage'] ) && ! empty( $settings['cron_enabled'] ) ) {
				esc_html_e( 'A varredura automática (a cada hora) enfileira os pendentes sozinha; o botão acima apenas antecipa.', 'tainacan-narrativas' );
			} else {
				esc_html_e( 'A varredura automática está desligada (aba Processamento): use o botão acima para enfileirar os pendentes.', 'tainacan-narrativas' );
			}
			?>
		</p>
		<div class="tn-table-wrap">
			<table class="widefat striped tn-table">
				<thead><tr><th><?php esc_html_e( 'Coleção', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Itens', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Prontas', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Em revisão', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Processando', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Pendentes', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Sem texto / OCR', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Cobertura', 'tainacan-narrativas' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $coverage as $tn_row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $base_url . '&tab=narratives&collection=' . (int) $tn_row['collection_id'] ); ?>"><?php echo esc_html( $tn_row['name'] ); ?></a> <span class="tn-muted">#<?php echo (int) $tn_row['collection_id']; ?></span></td>
							<td><?php echo (int) $tn_row['items']; ?></td>
							<td><?php echo (int) $tn_row['ready']; ?></td>
							<td><?php echo (int) $tn_row['review']; ?></td>
							<td><?php echo (int) $tn_row['busy']; ?></td>
							<td><?php echo (int) ( $tn_row['missing'] + $tn_row['stale'] + $tn_row['error'] ); ?></td>
							<td><?php echo (int) $tn_row['skipped']; ?></td>
							<td><div class="tn-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $tn_row['percent']; ?>"><span style="width:<?php echo (int) $tn_row['percent']; ?>%"></span></div> <?php echo (int) $tn_row['percent']; ?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<p class="tn-toolbar__status" data-tn-queue-status></p>
	<div class="tn-panel-output" data-tn-output hidden></div>
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
