<?php
/**
 * Diagnostics.
 *
 * @var array<int,array> $diagnostics
 * @var bool             $can_manage
 * @var bool             $can_generate
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tn-panel">
	<div class="tn-panel__head">
		<h2><?php esc_html_e( 'Diagnóstico', 'tainacan-narrativas' ); ?></h2>
		<?php if ( $can_manage ) : ?>
			<div class="tn-actions-row">
				<button type="button" class="button" data-tn-action="test-provider" data-kind="ai"><?php esc_html_e( 'Testar IA', 'tainacan-narrativas' ); ?></button>
				<button type="button" class="button" data-tn-action="test-provider" data-kind="tts"><?php esc_html_e( 'Testar TTS', 'tainacan-narrativas' ); ?></button>
				<button type="button" class="button" data-tn-action="test-audio"><?php esc_html_e( 'Gerar áudio de teste', 'tainacan-narrativas' ); ?></button>
				<button type="button" class="button" data-tn-action="test-write"><?php esc_html_e( 'Testar escrita', 'tainacan-narrativas' ); ?></button>
				<?php if ( $can_generate ) : ?>
					<button type="button" class="button" data-tn-action="run-queue"><?php esc_html_e( 'Executar fila', 'tainacan-narrativas' ); ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<p class="tn-toolbar__status" data-tn-queue-status></p>
	<div class="tn-panel-output" data-tn-output hidden></div>
	<table class="widefat striped tn-table tn-diagnostics">
		<tbody>
			<?php foreach ( $diagnostics as $tn_row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $tn_row['label'] ); ?></th>
					<td><span class="tn-dot tn-dot--<?php echo esc_attr( $tn_row['status'] ); ?>" aria-hidden="true"></span> <span class="screen-reader-text"><?php echo esc_html( $tn_row['status'] ); ?></span> <?php echo esc_html( $tn_row['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class="tn-panel">
	<h2><?php esc_html_e( 'Log recente (sem segredos)', 'tainacan-narrativas' ); ?></h2>
	<?php $tn_logs = \TainacanNarrativas\Logging\Logger::recent( 60 ); ?>
	<?php if ( ! $tn_logs ) : ?>
		<p class="tn-muted"><?php esc_html_e( 'Nenhum registro.', 'tainacan-narrativas' ); ?></p>
	<?php else : ?>
		<div class="tn-table-wrap">
			<table class="widefat striped tn-table tn-log">
				<thead><tr><th><?php esc_html_e( 'Quando (UTC)', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Nível', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Mensagem', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Contexto', 'tainacan-narrativas' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $tn_logs as $tn_log ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $tn_log['time'] ); ?></td>
							<td><span class="tn-level tn-level--<?php echo esc_attr( (string) $tn_log['level'] ); ?>"><?php echo esc_html( (string) $tn_log['level'] ); ?></span></td>
							<td><?php echo esc_html( (string) $tn_log['message'] ); ?></td>
							<td><code><?php echo esc_html( (string) wp_json_encode( $tn_log['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
