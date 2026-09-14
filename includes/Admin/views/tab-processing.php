<?php
/**
 * Queue / processing.
 *
 * @var array<string,mixed> $settings
 * @var array<string,int>   $job_counts
 * @var bool                $can_manage
 * @var bool                $can_generate
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tn-panel">
	<div class="tn-panel__head">
		<h2><?php esc_html_e( 'Fila de processamento', 'tainacan-narrativas' ); ?></h2>
		<div class="tn-actions-row">
			<?php if ( $can_generate ) : ?>
				<button type="button" class="button button-primary" data-tn-action="run-queue"><?php esc_html_e( 'Executar fila agora', 'tainacan-narrativas' ); ?></button>
			<?php endif; ?>
			<?php if ( $can_manage ) : ?>
				<button type="button" class="button" data-tn-action="clear-failed"><?php esc_html_e( 'Limpar jobs com falha', 'tainacan-narrativas' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" data-tn-action="reload-jobs"><?php esc_html_e( 'Atualizar', 'tainacan-narrativas' ); ?></button>
		</div>
	</div>
	<p class="tn-toolbar__status" data-tn-queue-status></p>
	<p class="description"><?php esc_html_e( 'Cada job percorre extração → roteiro → áudio de forma idempotente. Falhas temporárias são reagendadas com backoff (1, 3, 9 min); na última tentativa, uma IA indisponível cai para o roteiro por template. Um lock impede duas gerações simultâneas do mesmo item.', 'tainacan-narrativas' ); ?></p>
	<div class="tn-table-wrap">
		<table class="widefat striped tn-table" data-tn-jobs>
			<thead><tr><th>#</th><th><?php esc_html_e( 'Item', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Ação', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Etapa', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Status', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Tentativas', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Atualizado', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Erro', 'tainacan-narrativas' ); ?></th></tr></thead>
			<tbody data-tn-job-rows><tr><td colspan="8"><?php esc_html_e( 'Carregando…', 'tainacan-narrativas' ); ?></td></tr></tbody>
		</table>
	</div>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form">
	<?php wp_nonce_field( 'tn_settings' ); ?>
	<input type="hidden" name="action" value="tn_save_settings">
	<input type="hidden" name="tn_tab" value="processing">
	<input type="hidden" name="tn[__bools][]" value="cron_enabled">
	<input type="hidden" name="tn[__bools][]" value="auto_coverage">
	<fieldset <?php disabled( ! $can_manage ); ?>>
		<div class="tn-panel">
			<h2><?php esc_html_e( 'Gatilhos e limites', 'tainacan-narrativas' ); ?></h2>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[auto_coverage]" value="1" <?php checked( ! empty( $settings['auto_coverage'] ) ); ?>> <strong><?php esc_html_e( 'Manter todas as coleções habilitadas cobertas automaticamente', 'tainacan-narrativas' ); ?></strong> <span class="tn-muted"><?php esc_html_e( '(varredura a cada hora: itens sem narrativa, desatualizados ou com erro entram na fila sozinhos)', 'tainacan-narrativas' ); ?></span></label>
			</div>
			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-coverage-batch"><?php esc_html_e( 'Itens enfileirados por varredura (a fila processa até "jobs por execução" por minuto)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-coverage-batch" name="tn[coverage_batch]" min="1" max="2000" value="<?php echo (int) ( $settings['coverage_batch'] ?? 150 ); ?>"></div>
			</div>
			<div class="tn-field">
				<label for="tn-trigger"><?php esc_html_e( 'Ao salvar um item / documento / metadado', 'tainacan-narrativas' ); ?></label>
				<select id="tn-trigger" name="tn[trigger_on_save]">
					<option value="queue" <?php selected( $settings['trigger_on_save'], 'queue' ); ?>><?php esc_html_e( 'Marcar como desatualizada e colocar regeneração na fila (padrão)', 'tainacan-narrativas' ); ?></option>
					<option value="mark_stale" <?php selected( $settings['trigger_on_save'], 'mark_stale' ); ?>><?php esc_html_e( 'Apenas recalcular hash e marcar como desatualizada', 'tainacan-narrativas' ); ?></option>
					<option value="none" <?php selected( $settings['trigger_on_save'], 'none' ); ?>><?php esc_html_e( 'Não fazer nada (somente manual / varredura diária)', 'tainacan-narrativas' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'A verificação roda 90 s depois, via WP-Cron, nunca dentro do save_post.', 'tainacan-narrativas' ); ?></p>
			</div>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[cron_enabled]" value="1" <?php checked( ! empty( $settings['cron_enabled'] ) ); ?>> <?php esc_html_e( 'Processar a fila automaticamente via WP-Cron (a cada minuto, com lock e orçamento de tempo)', 'tainacan-narrativas' ); ?></label>
			</div>
			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-cron-batch"><?php esc_html_e( 'Jobs por execução', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-cron-batch" name="tn[cron_batch]" min="1" max="20" value="<?php echo (int) $settings['cron_batch']; ?>"></div>
				<div class="tn-field"><label for="tn-cron-budget"><?php esc_html_e( 'Orçamento de tempo por execução (s)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-cron-budget" name="tn[cron_time_budget]" min="5" max="120" value="<?php echo (int) $settings['cron_time_budget']; ?>"></div>
				<div class="tn-field"><label for="tn-max-attempts"><?php esc_html_e( 'Máximo de tentativas', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-max-attempts" name="tn[max_attempts]" min="1" max="10" value="<?php echo (int) $settings['max_attempts']; ?>"></div>
			</div>
			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-max-item"><?php esc_html_e( 'Máx. caracteres por item', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-max-item" name="tn[max_chars_item]" min="1000" max="500000" step="1000" value="<?php echo (int) $settings['max_chars_item']; ?>"></div>
				<div class="tn-field"><label for="tn-max-file"><?php esc_html_e( 'Máx. caracteres por arquivo', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-max-file" name="tn[max_chars_file]" min="500" max="300000" step="500" value="<?php echo (int) $settings['max_chars_file']; ?>"></div>
				<div class="tn-field"><label for="tn-max-att"><?php esc_html_e( 'Máx. anexos por item', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-max-att" name="tn[max_attachments]" min="0" max="50" value="<?php echo (int) $settings['max_attachments']; ?>"></div>
				<div class="tn-field"><label for="tn-keep"><?php esc_html_e( 'Versões mantidas por item (0 = todas)', 'tainacan-narrativas' ); ?></label>
					<select id="tn-keep" name="tn[keep_versions]">
						<?php foreach ( array( 1, 3, 5, 0 ) as $tn_k ) : ?>
							<option value="<?php echo (int) $tn_k; ?>" <?php selected( (int) $settings['keep_versions'], $tn_k ); ?>><?php echo 0 === $tn_k ? esc_html__( 'todas', 'tainacan-narrativas' ) : (int) $tn_k; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<?php if ( $can_manage ) : ?>
				<p class="tn-actions-row"><?php submit_button( __( 'Salvar processamento', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?></p>
			<?php endif; ?>
		</div>
	</fieldset>
</form>
