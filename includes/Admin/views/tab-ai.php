<?php
/**
 * AI settings.
 *
 * @var array<string,mixed>  $settings
 * @var array<string,string> $ai_labels
 * @var array<string,array>  $providers_state
 * @var bool                 $can_manage
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TainacanNarrativas\Core\Options;

$tn_secret_field = static function ( string $key, string $label ): void {
	$locked = Options::secret_is_constant( $key );
	$mask   = Options::secret_mask( $key );
	echo '<div class="tn-field"><label for="tn-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
	if ( $locked ) {
		echo '<input type="text" id="tn-' . esc_attr( $key ) . '" value="' . esc_attr( $mask ) . '" disabled class="regular-text"> <span class="tn-muted">' . esc_html__( 'configurada por wp-config.php', 'tainacan-narrativas' ) . ' (' . esc_html( Options::SECRET_CONSTANTS[ $key ] ) . ')</span>';
	} else {
		echo '<input type="password" id="tn-' . esc_attr( $key ) . '" name="tn[' . esc_attr( $key ) . ']" value="" autocomplete="new-password" class="regular-text" placeholder="' . esc_attr( '' !== $mask ? $mask : __( 'não configurada', 'tainacan-narrativas' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Deixe em branco para manter a chave atual; digite __clear__ para removê-la. A chave nunca é exibida nem enviada ao navegador.', 'tainacan-narrativas' ) . '</p>';
	}
	echo '</div>';
};
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form">
	<?php wp_nonce_field( 'tn_settings' ); ?>
	<input type="hidden" name="action" value="tn_save_settings">
	<input type="hidden" name="tn_tab" value="ai">
	<?php foreach ( array( 'external_ai_attachments', 'allow_private_endpoints', 'children_mode', 'ai_analysis' ) as $tn_b ) : ?>
		<input type="hidden" name="tn[__bools][]" value="<?php echo esc_attr( $tn_b ); ?>">
	<?php endforeach; ?>
	<fieldset <?php disabled( ! $can_manage ); ?>>
		<div class="tn-panel">
			<h2><?php esc_html_e( 'Provedor de IA generativa', 'tainacan-narrativas' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Opcional. Sem IA, o roteiro é montado pelo template documental (leitura fiel). Com IA, o conteúdo documental é transformado em uma narrativa mais natural, usando exclusivamente as fontes do item.', 'tainacan-narrativas' ); ?></p>
			<div class="tn-field">
				<label for="tn-ai-provider"><?php esc_html_e( 'Provedor', 'tainacan-narrativas' ); ?></label>
				<select id="tn-ai-provider" name="tn[ai_provider]" data-tn-provider-switch="ai">
					<?php foreach ( $ai_labels as $tn_key => $tn_label ) : ?>
						<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $settings['ai_provider'], $tn_key ); ?>><?php echo esc_html( $tn_label ); ?><?php echo isset( $providers_state['ai'][ $tn_key ] ) ? ( $providers_state['ai'][ $tn_key ] ? ' ✓' : ' —' ) : ''; ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div data-tn-provider-pane="ai:openai_compatible">
				<div class="tn-field">
					<label for="tn-ai-base"><?php esc_html_e( 'URL base (terminando em /v1)', 'tainacan-narrativas' ); ?></label>
					<input type="url" id="tn-ai-base" name="tn[ai_base_url]" class="regular-text" value="<?php echo esc_attr( (string) $settings['ai_base_url'] ); ?>" placeholder="http://lmstudio.local:1234/v1">
				</div>
				<div class="tn-field">
					<label for="tn-ai-model"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label>
					<input type="text" id="tn-ai-model" name="tn[ai_model]" class="regular-text" value="<?php echo esc_attr( (string) $settings['ai_model'] ); ?>">
				</div>
				<?php $tn_secret_field( 'ai_api_key', __( 'Chave de API (opcional em servidores locais)', 'tainacan-narrativas' ) ); ?>
			</div>
			<div data-tn-provider-pane="ai:openai">
				<div class="tn-field">
					<label for="tn-openai-model"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label>
					<input type="text" id="tn-openai-model" name="tn[ai_model]" class="regular-text" value="<?php echo esc_attr( (string) $settings['ai_model'] ); ?>" placeholder="gpt-4o-mini">
				</div>
				<?php $tn_secret_field( 'ai_api_key', __( 'Chave de API da OpenAI', 'tainacan-narrativas' ) ); ?>
			</div>
			<div data-tn-provider-pane="ai:ollama">
				<div class="tn-field">
					<label for="tn-ollama-base"><?php esc_html_e( 'URL do Ollama', 'tainacan-narrativas' ); ?></label>
					<input type="url" id="tn-ollama-base" name="tn[ollama_base_url]" class="regular-text" value="<?php echo esc_attr( (string) $settings['ollama_base_url'] ); ?>">
				</div>
				<div class="tn-field">
					<label for="tn-ollama-model"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label>
					<input type="text" id="tn-ollama-model" name="tn[ollama_model]" class="regular-text" value="<?php echo esc_attr( (string) $settings['ollama_model'] ); ?>">
				</div>
			</div>
			<div data-tn-provider-pane="ai:gemini">
				<div class="tn-field">
					<label for="tn-gemini-model"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label>
					<input type="text" id="tn-gemini-model" name="tn[gemini_model]" class="regular-text" value="<?php echo esc_attr( (string) $settings['gemini_model'] ); ?>">
				</div>
				<?php $tn_secret_field( 'gemini_api_key', __( 'Chave de API do Gemini', 'tainacan-narrativas' ) ); ?>
			</div>
			<div data-tn-provider-pane="ai:wp_ai">
				<p class="description"><?php esc_html_e( 'Usa os conectores de IA configurados no próprio WordPress (7.0+). Nenhuma chave é armazenada por este plugin.', 'tainacan-narrativas' ); ?></p>
			</div>

			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-ai-timeout"><?php esc_html_e( 'Timeout (s)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-ai-timeout" name="tn[ai_timeout]" min="10" max="600" value="<?php echo (int) $settings['ai_timeout']; ?>"></div>
				<div class="tn-field"><label for="tn-ai-temp"><?php esc_html_e( 'Temperatura', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-ai-temp" name="tn[ai_temperature]" min="0" max="2" step="0.1" value="<?php echo esc_attr( (string) $settings['ai_temperature'] ); ?>"></div>
				<div class="tn-field"><label for="tn-ai-max"><?php esc_html_e( 'Máx. tokens de saída', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-ai-max" name="tn[ai_max_tokens]" min="200" max="32000" value="<?php echo (int) $settings['ai_max_tokens']; ?>"></div>
				<div class="tn-field"><label for="tn-chunk"><?php esc_html_e( 'Tamanho do chunk (caracteres)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-chunk" name="tn[chunk_size]" min="1500" max="30000" step="500" value="<?php echo (int) $settings['chunk_size']; ?>"></div>
			</div>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[ai_analysis]" value="1" <?php checked( ! empty( $settings['ai_analysis'] ) ); ?>> <strong><?php esc_html_e( 'Leitura prévia do documento (recomendado)', 'tainacan-narrativas' ); ?></strong> <span class="tn-muted"><?php esc_html_e( '— antes de escrever, a IA lê o PDF inteiro e monta um dossiê (pessoas, datas, cronologia, passagens literais, fio condutor); a narrativa sai mais rica e cobre o documento todo. Custa uma chamada a mais por item.', 'tainacan-narrativas' ); ?></span></label>
				<label><input type="checkbox" name="tn[external_ai_attachments]" value="1" <?php checked( ! empty( $settings['external_ai_attachments'] ) ); ?>> <?php esc_html_e( 'Enviar texto dos anexos a IA externa (pode ser desativado por coleção)', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[allow_private_endpoints]" value="1" <?php checked( ! empty( $settings['allow_private_endpoints'] ) ); ?>> <?php esc_html_e( 'Permitir endpoints de rede privada/local (necessário para Ollama, LM Studio, Kokoro e Piper no mesmo servidor). Redirecionamentos ficam desabilitados.', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[children_mode]" value="1" <?php checked( ! empty( $settings['children_mode'] ) ); ?>> <?php esc_html_e( 'Habilitar o modo "Público infantil" (nunca infantiliza temas sensíveis)', 'tainacan-narrativas' ); ?></label>
			</div>
			<div class="tn-actions-row">
				<?php if ( $can_manage ) : ?>
					<?php submit_button( __( 'Salvar IA', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?>
					<button type="button" class="button" data-tn-action="test-provider" data-kind="ai"><?php esc_html_e( 'Testar IA (configuração salva)', 'tainacan-narrativas' ); ?></button>
				<?php endif; ?>
			</div>
			<div class="tn-panel-output" data-tn-output hidden></div>
		</div>

		<div class="tn-panel">
			<h2><?php esc_html_e( 'O que é enviado à IA', 'tainacan-narrativas' ); ?></h2>
			<ul class="tn-list">
				<li><?php esc_html_e( 'Título, descrição, nome da coleção e metadados PÚBLICOS com valor (privados nunca).', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'Texto extraído do documento principal e, se permitido, dos anexos — delimitado como fonte não confiável (instruções dentro dos documentos são ignoradas).', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'Nunca: IDs internos desnecessários, usuários, e-mails, logs, tokens ou dados administrativos.', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'Documentos grandes são reduzidos por trechos (redução fiel por chunk → consolidação) antes do roteiro; reduções e dossiês são cacheados por hash.', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'Etapas por item: redução (se o documento for longo) → leitura prévia (dossiê) → escrita da narração → limpeza determinística (markdown, fórmulas de texto automático). Tudo na fila, nunca na visita do público.', 'tainacan-narrativas' ); ?></li>
			</ul>
		</div>
	</fieldset>
</form>
