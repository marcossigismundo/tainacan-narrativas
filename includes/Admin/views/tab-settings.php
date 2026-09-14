<?php
/**
 * General settings.
 *
 * @var array<string,mixed> $settings
 * @var array<string,array> $modes
 * @var bool                $can_manage
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_template = '' !== trim( (string) $settings['template_script'] ) ? (string) $settings['template_script'] : \TainacanNarrativas\Narrative\ScriptBuilder::default_template();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form">
	<?php wp_nonce_field( 'tn_settings' ); ?>
	<input type="hidden" name="action" value="tn_save_settings">
	<input type="hidden" name="tn_tab" value="settings">
	<?php foreach ( array( 'enabled', 'autoinject', 'allow_download', 'provenance_notice', 'include_description', 'include_document', 'include_attachments', 'debug', 'delete_on_uninstall' ) as $tn_b ) : ?>
		<input type="hidden" name="tn[__bools][]" value="<?php echo esc_attr( $tn_b ); ?>">
	<?php endforeach; ?>
	<fieldset <?php disabled( ! $can_manage ); ?>>
		<div class="tn-panel">
			<h2><?php esc_html_e( 'Geral', 'tainacan-narrativas' ); ?></h2>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <strong><?php esc_html_e( 'Ativar narrativas', 'tainacan-narrativas' ); ?></strong> <span class="tn-muted"><?php esc_html_e( '(sem isso nada é gerado nem exibido)', 'tainacan-narrativas' ); ?></span></label>
				<label><input type="checkbox" name="tn[autoinject]" value="1" <?php checked( ! empty( $settings['autoinject'] ) ); ?>> <?php esc_html_e( 'Player automático na página pública do item (após os anexos)', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[allow_download]" value="1" <?php checked( ! empty( $settings['allow_download'] ) ); ?>> <?php esc_html_e( 'Permitir download do áudio (padrão; a coleção pode sobrescrever)', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[provenance_notice]" value="1" <?php checked( ! empty( $settings['provenance_notice'] ) ); ?>> <?php esc_html_e( 'Exibir nota de proveniência discreta no player', 'tainacan-narrativas' ); ?></label>
			</div>
			<div class="tn-field-row">
				<div class="tn-field">
					<label for="tn-mode"><?php esc_html_e( 'Modo narrativo padrão', 'tainacan-narrativas' ); ?></label>
					<select id="tn-mode" name="tn[default_mode]">
						<?php foreach ( $modes as $tn_key => $tn_def ) : ?>
							<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $settings['default_mode'], $tn_key ); ?>><?php echo esc_html( $tn_def['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html( $modes[ $settings['default_mode'] ]['description'] ?? '' ); ?></p>
				</div>
				<div class="tn-field">
					<label for="tn-flow"><?php esc_html_e( 'Fluxo editorial padrão', 'tainacan-narrativas' ); ?></label>
					<select id="tn-flow" name="tn[editorial_flow]">
						<option value="auto" <?php selected( $settings['editorial_flow'], 'auto' ); ?>><?php esc_html_e( 'Automático — cada item fica com narrativa pronta sem intervenção (padrão); o roteiro pode ser editado depois', 'tainacan-narrativas' ); ?></option>
						<option value="review" <?php selected( $settings['editorial_flow'], 'review' ); ?>><?php esc_html_e( 'Revisão humana — o roteiro fica pendente até um revisor aprovar', 'tainacan-narrativas' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Em ambos os fluxos nada é gerado na visita do público: IA e voz rodam na fila e o resultado fica armazenado.', 'tainacan-narrativas' ); ?></p>
				</div>
				<div class="tn-field">
					<label for="tn-lang"><?php esc_html_e( 'Idioma das narrativas', 'tainacan-narrativas' ); ?></label>
					<input type="text" id="tn-lang" name="tn[default_language]" value="<?php echo esc_attr( (string) $settings['default_language'] ); ?>">
				</div>
			</div>
			<div class="tn-field-row">
				<div class="tn-field">
					<label for="tn-max-words"><?php esc_html_e( 'Duração máxima da narração (palavras)', 'tainacan-narrativas' ); ?></label>
					<input type="number" id="tn-max-words" name="tn[max_words]" min="60" max="1500" step="10" value="<?php echo (int) ( $settings['max_words'] ?? 280 ); ?>">
					<p class="description">
						<?php
						$tn_secs = (int) round( (int) ( $settings['max_words'] ?? 280 ) / 150 * 60 );
						printf(
							/* translators: 1: minutes, 2: seconds. */
							esc_html__( 'Limite absoluto para todos os modos: ≈ %1$d min %2$02d s a 150 palavras por minuto. 280 palavras = cerca de 2 minutos. Roteiros mais longos são condensados mantendo as frases originais.', 'tainacan-narrativas' ),
							(int) floor( $tn_secs / 60 ),
							(int) ( $tn_secs % 60 )
						);
						?>
					</p>
				</div>
			</div>
			<h3><?php esc_html_e( 'Fontes padrão', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[include_description]" value="1" <?php checked( ! empty( $settings['include_description'] ) ); ?>> <?php esc_html_e( 'Descrição', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[include_document]" value="1" <?php checked( ! empty( $settings['include_document'] ) ); ?>> <?php esc_html_e( 'Documento principal', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[include_attachments]" value="1" <?php checked( ! empty( $settings['include_attachments'] ) ); ?>> <?php esc_html_e( 'Anexos', 'tainacan-narrativas' ); ?></label>
			</div>
		</div>

		<div class="tn-panel">
			<h2><?php esc_html_e( 'Template da leitura documental (sem IA)', 'tainacan-narrativas' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Placeholders: {title} {collection} {description} {metadata} {document_intro} {document} {attachments} {closing}. Linhas cujos placeholders ficarem vazios são omitidas. Deixe em branco para usar o padrão.', 'tainacan-narrativas' ); ?></p>
			<textarea name="tn[template_script]" class="large-text code" rows="10"><?php echo esc_textarea( '' !== trim( (string) $settings['template_script'] ) ? (string) $settings['template_script'] : '' ); ?></textarea>
			<details class="tn-details"><summary><?php esc_html_e( 'Ver template padrão', 'tainacan-narrativas' ); ?></summary><pre class="tn-pre"><?php echo esc_html( $tn_template ); ?></pre></details>
		</div>

		<div class="tn-panel">
			<h2><?php esc_html_e( 'Avançado', 'tainacan-narrativas' ); ?></h2>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[debug]" value="1" <?php checked( ! empty( $settings['debug'] ) ); ?>> <?php esc_html_e( 'Modo debug (registra requisições HTTP sem segredos no log interno)', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[delete_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Apagar todos os dados (roteiros, áudios, tabelas e opções) ao desinstalar o plugin', 'tainacan-narrativas' ); ?></label>
			</div>
			<p class="description"><?php esc_html_e( 'Chaves de API também podem ser definidas no wp-config.php: TN_AI_API_KEY, TN_GEMINI_API_KEY, TN_TTS_API_KEY. Quando definidas, o campo fica bloqueado no painel.', 'tainacan-narrativas' ); ?></p>
			<?php if ( $can_manage ) : ?>
				<p class="tn-actions-row"><?php submit_button( __( 'Salvar configurações', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?></p>
			<?php endif; ?>
		</div>
	</fieldset>
</form>
