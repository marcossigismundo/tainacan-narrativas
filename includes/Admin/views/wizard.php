<?php
/**
 * First-run wizard (single form, five steps).
 *
 * @var array<int,string>    $collections
 * @var array<string,array>  $modes
 * @var array<string,string> $ai_labels
 * @var array<string,string> $tts_labels
 * @var array<string,mixed>  $settings
 * @var bool                 $can_manage
 * @var string               $base_url
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tn-panel tn-wizard">
	<h2><?php esc_html_e( 'Bem-vindo ao Tainacan Narrativas', 'tainacan-narrativas' ); ?></h2>
	<p><?php esc_html_e( 'Cinco passos para transformar itens em narrativas em áudio. Nada é gerado nem enviado a serviços externos antes de você concluir esta configuração.', 'tainacan-narrativas' ); ?></p>
	<?php if ( ! $can_manage ) : ?>
		<p class="tn-muted"><?php esc_html_e( 'Peça a um administrador para concluir a configuração inicial.', 'tainacan-narrativas' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form">
			<?php wp_nonce_field( 'tn_wizard' ); ?>
			<input type="hidden" name="action" value="tn_wizard">

			<h3><span class="tn-step">1</span> <?php esc_html_e( 'Escolha as coleções', 'tainacan-narrativas' ); ?></h3>
			<?php if ( ! $collections ) : ?>
				<p class="tn-muted"><?php esc_html_e( 'Nenhuma coleção encontrada. Crie uma coleção no Tainacan primeiro.', 'tainacan-narrativas' ); ?></p>
			<?php endif; ?>
			<div class="tn-checklist">
				<?php foreach ( $collections as $tn_cid => $tn_name ) : ?>
					<label><input type="checkbox" name="collections[]" value="<?php echo (int) $tn_cid; ?>"> <?php echo esc_html( $tn_name ); ?> <span class="tn-muted">#<?php echo (int) $tn_cid; ?></span></label>
				<?php endforeach; ?>
			</div>

			<h3><span class="tn-step">2</span> <?php esc_html_e( 'Escolha o modo de narrativa', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-radio-cards">
				<?php foreach ( $modes as $tn_key => $tn_def ) : ?>
					<label class="tn-radio-card">
						<input type="radio" name="mode" value="<?php echo esc_attr( $tn_key ); ?>" <?php checked( $tn_key, 'documentary' ); ?>>
						<strong><?php echo esc_html( $tn_def['label'] ); ?></strong>
						<span><?php echo esc_html( $tn_def['description'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<div class="tn-field">
				<label for="tn-w-flow"><?php esc_html_e( 'Fluxo editorial', 'tainacan-narrativas' ); ?></label>
				<select id="tn-w-flow" name="editorial_flow">
					<option value="review"><?php esc_html_e( 'Revisão humana antes do áudio (recomendado para acervos históricos)', 'tainacan-narrativas' ); ?></option>
					<option value="auto"><?php esc_html_e( 'Automático: roteiro → áudio', 'tainacan-narrativas' ); ?></option>
				</select>
			</div>

			<h3><span class="tn-step">3</span> <?php esc_html_e( 'Escolha a voz', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field">
				<select name="tts_provider" data-tn-provider-switch="wtts">
					<?php foreach ( $tts_labels as $tn_key => $tn_label ) : ?>
						<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $tn_key, 'browser' ); ?>><?php echo esc_html( $tn_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div data-tn-provider-pane="wtts:openai_compatible">
				<div class="tn-field-row">
					<div class="tn-field"><label for="tn-w-tts-url"><?php esc_html_e( 'URL base do serviço (ex.: Kokoro-FastAPI)', 'tainacan-narrativas' ); ?></label><input type="url" id="tn-w-tts-url" name="tts_base_url" class="regular-text" placeholder="http://127.0.0.1:8880/v1"></div>
					<div class="tn-field"><label for="tn-w-tts-voice"><?php esc_html_e( 'Voz', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-w-tts-voice" name="tts_voice" value="pf_dora"></div>
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'Sem TTS neural, a voz do navegador do visitante é usada — funciona em qualquer hospedagem. Você pode configurar Piper/Kokoro depois na aba Voz.', 'tainacan-narrativas' ); ?></p>

			<h3><span class="tn-step">4</span> <?php esc_html_e( 'Configure a IA (opcional)', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field">
				<select name="ai_provider" data-tn-provider-switch="wai">
					<?php foreach ( $ai_labels as $tn_key => $tn_label ) : ?>
						<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $tn_key, 'none' ); ?>><?php echo esc_html( $tn_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div data-tn-provider-pane="wai:openai_compatible">
				<div class="tn-field-row">
					<div class="tn-field"><label for="tn-w-ai-url"><?php esc_html_e( 'URL base (…/v1)', 'tainacan-narrativas' ); ?></label><input type="url" id="tn-w-ai-url" name="ai_base_url" class="regular-text"></div>
					<div class="tn-field"><label for="tn-w-ai-model"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-w-ai-model" name="ai_model"></div>
				</div>
			</div>
			<div data-tn-provider-pane="wai:openai"><p class="description"><?php esc_html_e( 'Informe a chave na aba IA (ou TN_AI_API_KEY no wp-config.php) depois de concluir.', 'tainacan-narrativas' ); ?></p></div>
			<div data-tn-provider-pane="wai:gemini"><p class="description"><?php esc_html_e( 'Informe a chave na aba IA (ou TN_GEMINI_API_KEY no wp-config.php) depois de concluir.', 'tainacan-narrativas' ); ?></p></div>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="allow_private_endpoints" value="1"> <?php esc_html_e( 'Meus serviços (Ollama/Kokoro/Piper) rodam em rede privada/local — permitir endpoints privados', 'tainacan-narrativas' ); ?></label>
			</div>

			<h3><span class="tn-step">5</span> <?php esc_html_e( 'Gere um item de teste', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field">
				<label for="tn-w-test"><?php esc_html_e( 'ID de um item (de uma coleção marcada acima). Opcional.', 'tainacan-narrativas' ); ?></label>
				<input type="number" id="tn-w-test" name="test_item" min="1" class="regular-text">
			</div>

			<div class="tn-actions-row">
				<?php submit_button( __( 'Concluir configuração', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?>
				<button type="submit" name="skip" value="1" class="button"><?php esc_html_e( 'Pular configuração', 'tainacan-narrativas' ); ?></button>
			</div>
		</form>
	<?php endif; ?>
</div>
