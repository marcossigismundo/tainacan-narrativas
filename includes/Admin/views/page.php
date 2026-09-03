<?php
/**
 * Admin shell: subheader + tabs + grid (content + sidebar).
 *
 * Included by AdminPage::render_page_content() (class scope). Variables:
 *
 * @var string               $tab                 Active tab.
 * @var array<string,string> $tabs                Tabs.
 * @var string               $base_url            Page URL.
 * @var array<string,mixed>  $settings            Global settings.
 * @var array<string,mixed>  $stats               Narrative stats.
 * @var array<string,int>    $job_counts          Queue counters.
 * @var array<int,string>    $collections         Collections.
 * @var array<string,array>  $modes               Modes.
 * @var array<string,string> $ai_labels           AI provider labels.
 * @var array<string,string> $tts_labels          TTS provider labels.
 * @var array<string,string> $status_labels       Status labels.
 * @var bool                 $can_manage          Manage capability.
 * @var bool                 $can_generate        Generate capability.
 * @var string               $notice              Notice key.
 * @var string[]             $notice_errors       Error messages.
 * @var int                  $selected_collection Selected collection (collections tab).
 * @var array<string,mixed>  $collection_entry    Collection entry.
 * @var array<int,array>     $collection_metadata Collection metadata.
 * @var array<int,array>     $diagnostics         Diagnostics rows.
 * @var array<string,array>  $providers_state     Provider configured flags.
 * @var \TainacanNarrativas\Narrative\NarrativeManager $manager Manager.
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_notices = array(
	'saved'          => array( 'success', __( 'Configurações salvas.', 'tainacan-narrativas' ) ),
	'error'          => array( 'error', __( 'Algumas configurações não foram salvas:', 'tainacan-narrativas' ) ),
	'wizard_done'    => array( 'success', __( 'Configuração inicial concluída. As narrativas estão habilitadas para as coleções escolhidas.', 'tainacan-narrativas' ) ),
	'wizard_skipped' => array( 'info', __( 'Configuração inicial pulada. O plugin permanece desativado até você habilitar as coleções em Coleções e marcar "Ativar narrativas" em Configurações.', 'tainacan-narrativas' ) ),
);
?>
<div class="wrap tainacan-page-container-content tn-wrap" data-tn-tab="<?php echo esc_attr( $tab ); ?>">
	<div class="tainacan-fixed-subheader">
		<h1 class="tainacan-page-title">
			<?php esc_html_e( 'Tainacan Narrativas', 'tainacan-narrativas' ); ?>
			<span class="tn-version">v<?php echo esc_html( TN_VERSION ); ?></span>
		</h1>
		<p class="tainacan-page-description"><?php esc_html_e( 'Itens Tainacan como experiências narrativas em áudio: leitura documental, narrativa assistida por IA e síntese de voz.', 'tainacan-narrativas' ); ?></p>
	</div>

	<?php if ( isset( $tn_notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $tn_notices[ $notice ][0] ); ?> is-dismissible tn-notice">
			<p><?php echo esc_html( $tn_notices[ $notice ][1] ); ?></p>
			<?php if ( $notice_errors ) : ?>
				<ul>
					<?php foreach ( $notice_errors as $tn_err ) : ?>
						<li><?php echo esc_html( $tn_err ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $settings['enabled'] && 'wizard' !== $tab ) : ?>
		<div class="notice notice-warning tn-notice"><p>
			<?php esc_html_e( 'O plugin está em modo sem configuração: nenhuma narrativa é gerada e nenhum serviço externo é chamado até você ativar as narrativas.', 'tainacan-narrativas' ); ?>
			<a href="<?php echo esc_url( $base_url . '&tab=wizard' ); ?>"><?php esc_html_e( 'Abrir a configuração inicial', 'tainacan-narrativas' ); ?></a>
		</p></div>
	<?php endif; ?>

	<?php if ( 'wizard' !== $tab ) : ?>
		<h2 class="nav-tab-wrapper tn-tabs">
			<?php foreach ( $tabs as $tn_slug => $tn_label ) : ?>
				<a href="<?php echo esc_url( $base_url . '&tab=' . $tn_slug ); ?>" class="nav-tab <?php echo $tab === $tn_slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $tn_label ); ?>
					<?php if ( 'narratives' === $tn_slug && ! empty( $stats['review'] ) ) : ?>
						<span class="tn-badge" title="<?php esc_attr_e( 'Aguardando revisão', 'tainacan-narrativas' ); ?>"><?php echo (int) $stats['review']; ?></span>
					<?php endif; ?>
					<?php if ( 'processing' === $tn_slug && ! empty( $job_counts['failed'] ) ) : ?>
						<span class="tn-badge tn-badge--danger"><?php echo (int) $job_counts['failed']; ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</h2>
	<?php endif; ?>

	<div class="tn-grid <?php echo 'wizard' === $tab ? 'tn-grid--single' : ''; ?>">
		<div class="tn-main">
			<?php
			$tn_view = 'wizard' === $tab ? 'wizard' : 'tab-' . $tab;
			require __DIR__ . '/' . $tn_view . '.php';
			?>
		</div>

		<?php if ( 'wizard' !== $tab ) : ?>
			<aside class="tn-sidebar">
				<div class="tn-info-box">
					<h3><?php esc_html_e( 'Resumo', 'tainacan-narrativas' ); ?></h3>
					<ul class="tn-summary">
						<li><span><?php echo esc_html( $status_labels['ready'] ); ?></span><strong><?php echo (int) $stats['ready']; ?></strong></li>
						<li><span><?php echo esc_html( $status_labels['review'] ); ?></span><strong><?php echo (int) $stats['review']; ?></strong></li>
						<li><span><?php echo esc_html( $status_labels['stale'] ); ?></span><strong><?php echo (int) $stats['stale']; ?></strong></li>
						<li><span><?php echo esc_html( $status_labels['error'] ); ?></span><strong><?php echo (int) $stats['error']; ?></strong></li>
						<li><span><?php esc_html_e( 'Na fila', 'tainacan-narrativas' ); ?></span><strong><?php echo (int) $job_counts['queued']; ?></strong></li>
					</ul>
				</div>
				<div class="tn-info-box">
					<h3><?php esc_html_e( 'Como funciona', 'tainacan-narrativas' ); ?></h3>
					<p><?php esc_html_e( 'gerar → armazenar → servir. A página pública só reproduz conteúdo já gerado; a IA e o TTS rodam na fila, nunca a cada visita.', 'tainacan-narrativas' ); ?></p>
					<p><?php esc_html_e( 'Shortcode:', 'tainacan-narrativas' ); ?> <code>[tainacan_narrativa item_id="123"]</code></p>
					<p><?php esc_html_e( 'Bloco:', 'tainacan-narrativas' ); ?> <code>tainacan-narrativas/player</code></p>
				</div>
				<div class="tn-info-box">
					<h3><?php esc_html_e( 'Privacidade', 'tainacan-narrativas' ); ?></h3>
					<p><?php esc_html_e( 'Somente metadados públicos entram na narrativa. Cada coleção pode proibir o envio de conteúdo a IA externa e o envio de anexos.', 'tainacan-narrativas' ); ?></p>
				</div>
			</aside>
		<?php endif; ?>
	</div>
	<div class="tn-toasts" aria-live="polite" aria-atomic="true"></div>
</div>
