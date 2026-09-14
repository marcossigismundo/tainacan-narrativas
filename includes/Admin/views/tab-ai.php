<?php
/**
 * AI settings — provider cards + per-provider panel (key, model catalog,
 * "fetch models from the account"), the same layout as Oráculo Tainacan.
 *
 * @var array<string,mixed>  $settings
 * @var array<int,array>     $ai_providers
 * @var bool                 $can_manage
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_selected = (string) ( $settings['ai_provider'] ?? 'none' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form" data-tn-ai-form>
	<?php wp_nonce_field( 'tn_settings' ); ?>
	<input type="hidden" name="action" value="tn_save_settings">
	<input type="hidden" name="tn_tab" value="ai">
	<?php foreach ( array( 'external_ai_attachments', 'allow_private_endpoints', 'children_mode', 'ai_analysis' ) as $tn_b ) : ?>
		<input type="hidden" name="tn[__bools][]" value="<?php echo esc_attr( $tn_b ); ?>">
	<?php endforeach; ?>
	<fieldset <?php disabled( ! $can_manage ); ?>>
		<div class="tn-panel">
			<h2><?php esc_html_e( 'Provedor de IA generativa', 'tainacan-narrativas' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Opcional. Sem IA, o roteiro é montado pelo template documental (fiel por construção). Com IA, o conteúdo do item vira uma narração mais natural — sempre limitada ao que está nas fontes e verificada frase a frase antes de ser publicada.', 'tainacan-narrativas' ); ?></p>

			<div class="tn-provider-cards" role="radiogroup" aria-label="<?php esc_attr_e( 'Provedor de IA', 'tainacan-narrativas' ); ?>">
				<label class="tn-provider-card <?php echo 'none' === $tn_selected ? 'is-selected' : ''; ?>">
					<input type="radio" name="tn[ai_provider]" value="none" data-tn-provider-radio="ai" <?php checked( $tn_selected, 'none' ); ?>>
					<span class="tn-provider-card__name"><?php esc_html_e( 'Sem IA', 'tainacan-narrativas' ); ?></span>
					<span class="tn-provider-card__desc"><?php esc_html_e( 'Roteiro documental por template: lê o que está no item, sem reescrever.', 'tainacan-narrativas' ); ?></span>
				</label>
				<?php foreach ( $ai_providers as $tn_p ) : ?>
					<label class="tn-provider-card <?php echo $tn_selected === $tn_p['id'] ? 'is-selected' : ''; ?>">
						<input type="radio" name="tn[ai_provider]" value="<?php echo esc_attr( $tn_p['id'] ); ?>" data-tn-provider-radio="ai" <?php checked( $tn_selected, $tn_p['id'] ); ?>>
						<span class="tn-provider-card__name"><?php echo esc_html( $tn_p['label'] ); ?></span>
						<span class="tn-provider-card__badges">
							<?php if ( $tn_p['configured'] ) : ?>
								<span class="tn-status tn-status--ready"><?php esc_html_e( 'configurado', 'tainacan-narrativas' ); ?></span>
							<?php endif; ?>
							<span class="tn-status <?php echo $tn_p['external'] ? 'tn-status--review' : 'tn-status--queued'; ?>"><?php echo $tn_p['external'] ? esc_html__( 'externo', 'tainacan-narrativas' ) : esc_html__( 'local', 'tainacan-narrativas' ); ?></span>
						</span>
						<span class="tn-provider-card__desc"><?php echo esc_html( $tn_p['description'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $ai_providers as $tn_p ) : ?>
				<?php
				$tn_pid       = $tn_p['id'];
				$tn_model_opt = (string) $tn_p['model_option'];
				$tn_key_opt   = (string) $tn_p['key_option'];
				$tn_url_opt   = (string) $tn_p['url_option'];
				$tn_current   = '' !== $tn_model_opt ? (string) ( $settings[ $tn_model_opt ] ?? '' ) : '';
				$tn_catalog   = (array) $tn_p['catalog'];
				$tn_cat_ids   = array_column( $tn_catalog, 'id' );
				if ( '' === $tn_current && $tn_cat_ids ) {
					$tn_current = (string) $tn_cat_ids[0];
				}
				?>
				<div class="tn-provider-panel" data-tn-provider-pane="ai:<?php echo esc_attr( $tn_pid ); ?>" data-tn-provider-id="<?php echo esc_attr( $tn_pid ); ?>">
					<h3><?php echo esc_html( $tn_p['label'] ); ?></h3>

					<?php if ( 'wp_ai' === $tn_pid ) : ?>
						<p class="description"><?php echo esc_html( $tn_p['description'] ); ?></p>
					<?php endif; ?>

					<?php if ( '' !== $tn_url_opt ) : ?>
						<div class="tn-field">
							<label for="tn-ai-url-<?php echo esc_attr( $tn_pid ); ?>"><?php echo 'ollama' === $tn_pid ? esc_html__( 'URL do servidor Ollama', 'tainacan-narrativas' ) : esc_html__( 'URL base (terminando em /v1)', 'tainacan-narrativas' ); ?></label>
							<input type="url" id="tn-ai-url-<?php echo esc_attr( $tn_pid ); ?>" name="tn[<?php echo esc_attr( $tn_url_opt ); ?>]" class="regular-text" data-tn-provider-url value="<?php echo esc_attr( (string) ( $settings[ $tn_url_opt ] ?? '' ) ); ?>" placeholder="<?php echo 'ollama' === $tn_pid ? 'http://127.0.0.1:11434' : 'http://lmstudio.local:1234/v1'; ?>">
						</div>
					<?php endif; ?>

					<?php if ( '' !== $tn_key_opt ) : ?>
						<div class="tn-field">
							<label for="tn-ai-key-<?php echo esc_attr( $tn_pid ); ?>"><?php echo 'openai_compatible' === $tn_pid ? esc_html__( 'Chave de API (opcional em servidores locais)', 'tainacan-narrativas' ) : esc_html__( 'Chave de API', 'tainacan-narrativas' ); ?></label>
							<?php if ( $tn_p['key_locked'] ) : ?>
								<input type="text" id="tn-ai-key-<?php echo esc_attr( $tn_pid ); ?>" value="<?php echo esc_attr( (string) $tn_p['key_mask'] ); ?>" disabled class="regular-text" data-tn-locked> <span class="tn-muted"><?php esc_html_e( 'configurada por wp-config.php', 'tainacan-narrativas' ); ?> (<?php echo esc_html( \TainacanNarrativas\Core\Options::SECRET_CONSTANTS[ $tn_key_opt ] ?? '' ); ?>)</span>
							<?php else : ?>
								<div class="tn-inline-form">
									<input type="password" id="tn-ai-key-<?php echo esc_attr( $tn_pid ); ?>" name="tn[<?php echo esc_attr( $tn_key_opt ); ?>]" value="" autocomplete="new-password" class="regular-text" data-tn-provider-key placeholder="<?php echo esc_attr( '' !== $tn_p['key_mask'] ? $tn_p['key_mask'] : __( 'não configurada', 'tainacan-narrativas' ) ); ?>">
									<?php if ( '' !== $tn_p['key_link'] ) : ?>
										<a class="button" href="<?php echo esc_url( $tn_p['key_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Obter chave', 'tainacan-narrativas' ); ?></a>
									<?php endif; ?>
								</div>
								<p class="description"><?php esc_html_e( 'Em branco mantém a chave atual; __clear__ remove. A chave nunca é exibida nem enviada ao navegador.', 'tainacan-narrativas' ); ?></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( '' !== $tn_model_opt ) : ?>
						<div class="tn-field">
							<label for="tn-ai-model-<?php echo esc_attr( $tn_pid ); ?>"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label>
							<div class="tn-inline-form">
								<?php if ( $tn_p['free_model'] ) : ?>
									<input type="text" id="tn-ai-model-<?php echo esc_attr( $tn_pid ); ?>" name="tn[<?php echo esc_attr( $tn_model_opt ); ?>]" class="regular-text" data-tn-provider-model list="tn-ai-models-<?php echo esc_attr( $tn_pid ); ?>" value="<?php echo esc_attr( $tn_current ); ?>" placeholder="<?php echo 'ollama' === $tn_pid ? 'llama3.2' : 'nome-do-modelo'; ?>">
									<datalist id="tn-ai-models-<?php echo esc_attr( $tn_pid ); ?>"></datalist>
								<?php else : ?>
									<select id="tn-ai-model-<?php echo esc_attr( $tn_pid ); ?>" name="tn[<?php echo esc_attr( $tn_model_opt ); ?>]" data-tn-provider-model>
										<?php if ( '' !== $tn_current && ! in_array( $tn_current, $tn_cat_ids, true ) ) : ?>
											<option value="<?php echo esc_attr( $tn_current ); ?>" selected>
												<?php
												/* translators: %s: model id currently saved in the settings. */
												printf( esc_html__( '%s (configurado)', 'tainacan-narrativas' ), esc_html( $tn_current ) );
												?>
											</option>
										<?php endif; ?>
										<?php foreach ( $tn_catalog as $tn_m ) : ?>
											<option value="<?php echo esc_attr( $tn_m['id'] ); ?>" <?php selected( $tn_current, $tn_m['id'] ); ?>><?php echo esc_html( $tn_m['name'] ); ?><?php echo '' !== $tn_m['description'] ? ' — ' . esc_html( $tn_m['description'] ) : ''; ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
								<?php if ( $can_manage ) : ?>
									<button type="button" class="button" data-tn-action="fetch-models" data-provider="<?php echo esc_attr( $tn_pid ); ?>"><?php echo 'ollama' === $tn_pid ? esc_html__( 'Buscar modelos instalados', 'tainacan-narrativas' ) : esc_html__( 'Buscar modelos da conta', 'tainacan-narrativas' ); ?></button>
									<button type="button" class="button" data-tn-action="test-provider" data-kind="ai" data-provider="<?php echo esc_attr( $tn_pid ); ?>"><?php esc_html_e( 'Testar conexão', 'tainacan-narrativas' ); ?></button>
								<?php endif; ?>
							</div>
							<p class="description"><?php echo 'ollama' === $tn_pid ? esc_html__( 'Lista os modelos já baixados neste servidor (ollama pull).', 'tainacan-narrativas' ) : esc_html__( 'Consulta a API com a chave acima (mesmo sem salvar) e mostra os modelos que esta conta realmente libera — inclusive modelos lançados depois desta versão do plugin.', 'tainacan-narrativas' ); ?></p>
							<p class="tn-detail-meta" data-tn-models-status="<?php echo esc_attr( $tn_pid ); ?>"></p>
						</div>
					<?php elseif ( 'wp_ai' === $tn_pid && $can_manage ) : ?>
						<div class="tn-actions-row"><button type="button" class="button" data-tn-action="test-provider" data-kind="ai" data-provider="wp_ai"><?php esc_html_e( 'Testar conexão', 'tainacan-narrativas' ); ?></button></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<h3><?php esc_html_e( 'Parâmetros', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-ai-timeout"><?php esc_html_e( 'Timeout (s)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-ai-timeout" name="tn[ai_timeout]" min="10" max="600" value="<?php echo (int) $settings['ai_timeout']; ?>"></div>
				<div class="tn-field"><label for="tn-ai-temp"><?php esc_html_e( 'Temperatura', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-ai-temp" name="tn[ai_temperature]" min="0" max="2" step="0.1" value="<?php echo esc_attr( (string) $settings['ai_temperature'] ); ?>"><p class="description"><?php esc_html_e( 'Baixa (0,2–0,5) = mais literal. Valores altos aumentam o risco de invenção.', 'tainacan-narrativas' ); ?></p></div>
				<div class="tn-field"><label for="tn-ai-max"><?php esc_html_e( 'Máx. tokens de saída', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-ai-max" name="tn[ai_max_tokens]" min="200" max="32000" value="<?php echo (int) $settings['ai_max_tokens']; ?>"></div>
				<div class="tn-field"><label for="tn-chunk"><?php esc_html_e( 'Tamanho do chunk (caracteres)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-chunk" name="tn[chunk_size]" min="1500" max="30000" step="500" value="<?php echo (int) $settings['chunk_size']; ?>"></div>
			</div>
			<div class="tn-field tn-field--check">
				<label><input type="checkbox" name="tn[ai_analysis]" value="1" <?php checked( ! empty( $settings['ai_analysis'] ) ); ?>> <strong><?php esc_html_e( 'Leitura prévia do documento (recomendado)', 'tainacan-narrativas' ); ?></strong> <span class="tn-muted"><?php esc_html_e( '— antes de escrever, a IA lê o documento inteiro e monta um dossiê (pessoas, datas, cronologia, passagens literais). Custa uma chamada a mais por item; dispensada quando as fontes são curtas.', 'tainacan-narrativas' ); ?></span></label>
				<label><input type="checkbox" name="tn[external_ai_attachments]" value="1" <?php checked( ! empty( $settings['external_ai_attachments'] ) ); ?>> <?php esc_html_e( 'Enviar texto dos anexos a IA externa (pode ser desativado por coleção)', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[allow_private_endpoints]" value="1" <?php checked( ! empty( $settings['allow_private_endpoints'] ) ); ?>> <?php esc_html_e( 'Permitir endpoints de rede privada/local (necessário para Ollama, LM Studio, Kokoro e Piper no mesmo servidor). Redirecionamentos ficam desabilitados.', 'tainacan-narrativas' ); ?></label>
				<label><input type="checkbox" name="tn[children_mode]" value="1" <?php checked( ! empty( $settings['children_mode'] ) ); ?>> <?php esc_html_e( 'Habilitar o modo "Público infantil" (nunca infantiliza temas sensíveis)', 'tainacan-narrativas' ); ?></label>
			</div>
			<div class="tn-actions-row">
				<?php if ( $can_manage ) : ?>
					<?php submit_button( __( 'Salvar IA', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?>
				<?php endif; ?>
			</div>
			<div class="tn-panel-output" data-tn-output hidden></div>
		</div>

		<div class="tn-panel">
			<h2><?php esc_html_e( 'Garantias contra invenção', 'tainacan-narrativas' ); ?></h2>
			<ul class="tn-list">
				<li><?php esc_html_e( 'A IA recebe SOMENTE título, descrição, nome da coleção, metadados públicos e o texto extraído do documento/anexos, delimitados como fonte. Ela é instruída a não usar conhecimento externo, não contextualizar e não completar lacunas; fontes curtas viram um resumo breve e literal.', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'Depois de escrita, cada frase da narração é comparada com as fontes do item: números, nomes próprios e afirmações sem apoio são detectados. A IA recebe uma chance de corrigir; o que continuar sem apoio é removido; se sobrar pouco, o texto da IA é descartado e o roteiro por template (fiel por construção) é usado.', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'O resultado da verificação fica registrado em cada narrativa (aba Narrativas → Fontes e saúde) e a nota de proveniência do player informa ao público que a narração usa apenas o que está no item.', 'tainacan-narrativas' ); ?></li>
				<li><?php esc_html_e( 'Toda narração respeita a duração máxima configurada (Configurações → Duração máxima); quando a IA excede, o texto é condensado mantendo frases originais, nunca reescrito.', 'tainacan-narrativas' ); ?></li>
			</ul>
		</div>
	</fieldset>
</form>
