<?php
/**
 * Narratives list (rendered by admin.js from the REST API).
 *
 * @var array<int,string>    $collections
 * @var array<string,string> $status_labels
 * @var bool                 $can_generate
 * @var bool                 $can_manage
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; no state mutation.
$tn_status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; no state mutation.
$tn_collection_filter = isset( $_GET['collection'] ) ? absint( wp_unslash( $_GET['collection'] ) ) : 0;
?>
<div class="tn-panel">
	<div class="tn-toolbar">
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'Status', 'tainacan-narrativas' ); ?></span>
			<select data-tn-filter="status">
				<option value=""><?php esc_html_e( 'Todos os status', 'tainacan-narrativas' ); ?></option>
				<?php foreach ( $status_labels as $tn_key => $tn_label ) : ?>
					<?php
					if ( 'none' === $tn_key ) {
						continue; }
					?>
					<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $tn_status_filter, $tn_key ); ?>><?php echo esc_html( $tn_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'Coleção', 'tainacan-narrativas' ); ?></span>
			<select data-tn-filter="collection_id">
				<option value="0"><?php esc_html_e( 'Todas as coleções', 'tainacan-narrativas' ); ?></option>
				<?php foreach ( $collections as $tn_cid => $tn_name ) : ?>
					<option value="<?php echo (int) $tn_cid; ?>" <?php selected( $tn_collection_filter, $tn_cid ); ?>><?php echo esc_html( $tn_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'ID do item', 'tainacan-narrativas' ); ?></span>
			<input type="number" min="1" data-tn-filter="item_id" placeholder="<?php esc_attr_e( 'ID do item', 'tainacan-narrativas' ); ?>">
		</label>
		<button type="button" class="button" data-tn-action="reload"><?php esc_html_e( 'Atualizar', 'tainacan-narrativas' ); ?></button>
		<?php if ( $can_generate ) : ?>
			<button type="button" class="button" data-tn-action="run-queue"><?php esc_html_e( 'Executar fila', 'tainacan-narrativas' ); ?></button>
		<?php endif; ?>
		<span class="tn-toolbar__status" data-tn-queue-status></span>
	</div>

	<?php if ( $can_manage ) : ?>
		<div class="tn-bulk" data-tn-bulk hidden>
			<span class="tn-bulk__count" data-tn-bulk-count></span>
			<button type="button" class="button" data-tn-action="bulk-delete"><?php esc_html_e( 'Excluir selecionadas', 'tainacan-narrativas' ); ?></button>
			<button type="button" class="button tn-bulk__all" data-tn-action="bulk-delete-filter" hidden><?php esc_html_e( 'Excluir todas do filtro atual', 'tainacan-narrativas' ); ?></button>
			<button type="button" class="button-link" data-tn-action="bulk-clear"><?php esc_html_e( 'Limpar seleção', 'tainacan-narrativas' ); ?></button>
		</div>
	<?php endif; ?>

	<div class="tn-table-wrap">
		<table class="widefat striped tn-table" data-tn-table data-tn-can-select="<?php echo $can_manage ? '1' : '0'; ?>">
			<thead>
				<tr>
					<?php if ( $can_manage ) : ?>
						<th class="tn-col-check"><input type="checkbox" data-tn-select-all aria-label="<?php esc_attr_e( 'Selecionar todas as narrativas desta página', 'tainacan-narrativas' ); ?>"></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Item', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'Coleção', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'Modo', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'Duração', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'Última geração', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'IA', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'TTS', 'tainacan-narrativas' ); ?></th>
					<th><?php esc_html_e( 'Ações', 'tainacan-narrativas' ); ?></th>
				</tr>
			</thead>
			<tbody data-tn-rows>
				<tr><td colspan="10"><?php esc_html_e( 'Carregando…', 'tainacan-narrativas' ); ?></td></tr>
			</tbody>
		</table>
	</div>
	<div class="tn-pagination" data-tn-pagination></div>
</div>

<div class="tn-panel" data-tn-detail hidden>
	<div class="tn-panel__head">
		<h2 data-tn-detail-title></h2>
		<button type="button" class="button-link" data-tn-action="close-detail"><?php esc_html_e( 'Fechar', 'tainacan-narrativas' ); ?></button>
	</div>
	<div class="tn-detail-tabs" role="tablist">
		<button type="button" class="tn-detail-tab is-active" role="tab" aria-selected="true" data-tn-detail-tab="script"><?php esc_html_e( 'Roteiro', 'tainacan-narrativas' ); ?></button>
		<button type="button" class="tn-detail-tab" role="tab" aria-selected="false" data-tn-detail-tab="sources"><?php esc_html_e( 'Fontes e saúde', 'tainacan-narrativas' ); ?></button>
		<button type="button" class="tn-detail-tab" role="tab" aria-selected="false" data-tn-detail-tab="info"><?php esc_html_e( 'Rastreabilidade', 'tainacan-narrativas' ); ?></button>
	</div>
	<div data-tn-detail-pane="script" class="tn-detail-pane">
		<div class="tn-detail-audio" data-tn-detail-audio></div>
		<label for="tn-script-editor" class="tn-label"><?php esc_html_e( 'Roteiro narrado (edite antes de aprovar; a edição humana nunca é sobrescrita pela IA)', 'tainacan-narrativas' ); ?></label>
		<textarea id="tn-script-editor" class="large-text code tn-script-editor" rows="18" data-tn-script></textarea>
		<p class="tn-detail-meta" data-tn-script-meta></p>
		<div class="tn-actions-row">
			<button type="button" class="button button-primary" data-tn-action="save-script"><?php esc_html_e( 'Salvar roteiro', 'tainacan-narrativas' ); ?></button>
			<button type="button" class="button" data-tn-action="approve"><?php esc_html_e( 'Aprovar e gerar áudio', 'tainacan-narrativas' ); ?></button>
			<button type="button" class="button" data-tn-action="restore-generated"><?php esc_html_e( 'Restaurar texto gerado', 'tainacan-narrativas' ); ?></button>
		</div>
	</div>
	<div data-tn-detail-pane="sources" class="tn-detail-pane" hidden></div>
	<div data-tn-detail-pane="info" class="tn-detail-pane" hidden></div>
</div>
