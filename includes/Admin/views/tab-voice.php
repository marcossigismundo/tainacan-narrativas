<?php
/**
 * TTS settings.
 *
 * @var array<string,mixed>  $settings
 * @var array<string,string> $tts_labels
 * @var array<string,array>  $providers_state
 * @var bool                 $can_manage
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TainacanNarrativas\Core\Options;

$tn_tts_locked = Options::secret_is_constant( 'tts_api_key' );
$tn_tts_mask   = Options::secret_mask( 'tts_api_key' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form">
	<?php wp_nonce_field( 'tn_settings' ); ?>
	<input type="hidden" name="action" value="tn_save_settings">
	<input type="hidden" name="tn_tab" value="voice">
	<fieldset <?php disabled( ! $can_manage ); ?>>
		<div class="tn-panel">
			<h2><?php esc_html_e( 'Síntese de voz (TTS)', 'tainacan-narrativas' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Estratégia: TTS neural configurado → áudio gerado e armazenado na biblioteca de mídia. Caso contrário → voz do navegador do visitante (Web Speech API), sem servidor e sem CDN.', 'tainacan-narrativas' ); ?></p>
			<div class="tn-field">
				<label for="tn-tts-provider"><?php esc_html_e( 'Mecanismo', 'tainacan-narrativas' ); ?></label>
				<select id="tn-tts-provider" name="tn[tts_provider]" data-tn-provider-switch="tts">
					<?php foreach ( $tts_labels as $tn_key => $tn_label ) : ?>
						<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $settings['tts_provider'], $tn_key ); ?>><?php echo esc_html( $tn_label ); ?><?php echo isset( $providers_state['tts'][ $tn_key ] ) ? ( $providers_state['tts'][ $tn_key ] ? ' ✓' : ' —' ) : ''; ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div data-tn-provider-pane="tts:openai_compatible">
				<p class="description"><?php esc_html_e( 'Compatível com Kokoro-FastAPI (recomendado: open source, vozes pt-BR como pf_dora/pm_alex, roda em CPU), LocalAI, OpenedAI-Speech e a própria OpenAI. Endpoint: POST {base}/audio/speech.', 'tainacan-narrativas' ); ?></p>
				<div class="tn-field">
					<label for="tn-tts-base"><?php esc_html_e( 'URL base (terminando em /v1)', 'tainacan-narrativas' ); ?></label>
					<input type="url" id="tn-tts-base" name="tn[tts_base_url]" class="regular-text" value="<?php echo esc_attr( (string) $settings['tts_base_url'] ); ?>" placeholder="http://127.0.0.1:8880/v1">
				</div>
				<div class="tn-field-row">
					<div class="tn-field"><label for="tn-tts-model"><?php esc_html_e( 'Modelo', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-tts-model" name="tn[tts_model]" value="<?php echo esc_attr( (string) $settings['tts_model'] ); ?>" placeholder="kokoro"></div>
					<div class="tn-field"><label for="tn-tts-voice"><?php esc_html_e( 'Voz', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-tts-voice" name="tn[tts_voice]" value="<?php echo esc_attr( (string) $settings['tts_voice'] ); ?>" placeholder="pf_dora"></div>
					<div class="tn-field"><label for="tn-tts-format"><?php esc_html_e( 'Formato', 'tainacan-narrativas' ); ?></label>
						<select id="tn-tts-format" name="tn[tts_format]">
							<option value="mp3" <?php selected( $settings['tts_format'], 'mp3' ); ?>>MP3</option>
							<option value="wav" <?php selected( $settings['tts_format'], 'wav' ); ?>>WAV</option>
						</select>
					</div>
				</div>
				<div class="tn-field">
					<label for="tn-tts-key"><?php esc_html_e( 'Token de API (opcional)', 'tainacan-narrativas' ); ?></label>
					<?php if ( $tn_tts_locked ) : ?>
						<input type="text" id="tn-tts-key" value="<?php echo esc_attr( $tn_tts_mask ); ?>" disabled class="regular-text"> <span class="tn-muted"><?php esc_html_e( 'configurado por wp-config.php', 'tainacan-narrativas' ); ?> (TN_TTS_API_KEY)</span>
					<?php else : ?>
						<input type="password" id="tn-tts-key" name="tn[tts_api_key]" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo esc_attr( '' !== $tn_tts_mask ? $tn_tts_mask : __( 'não configurado', 'tainacan-narrativas' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Em branco mantém o atual; __clear__ remove. Nunca é exposto ao navegador.', 'tainacan-narrativas' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div data-tn-provider-pane="tts:piper_http">
				<div class="tn-field">
					<label for="tn-piper-url"><?php esc_html_e( 'URL do servidor Piper', 'tainacan-narrativas' ); ?></label>
					<input type="url" id="tn-piper-url" name="tn[piper_url]" class="regular-text" value="<?php echo esc_attr( (string) $settings['piper_url'] ); ?>" placeholder="http://127.0.0.1:5000">
				</div>
				<div class="tn-field-row">
					<div class="tn-field"><label for="tn-piper-voice"><?php esc_html_e( 'Voz (ex.: pt_BR-faber-medium)', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-piper-voice" name="tn[piper_voice]" value="<?php echo esc_attr( (string) $settings['piper_voice'] ); ?>"></div>
					<div class="tn-field"><label for="tn-piper-payload"><?php esc_html_e( 'Formato da requisição', 'tainacan-narrativas' ); ?></label>
						<select id="tn-piper-payload" name="tn[piper_payload]">
							<option value="json" <?php selected( $settings['piper_payload'] ?? 'json', 'json' ); ?>><?php esc_html_e( 'JSON {"text","voice"} (piper1-gpl)', 'tainacan-narrativas' ); ?></option>
							<option value="raw" <?php selected( $settings['piper_payload'] ?? 'json', 'raw' ); ?>><?php esc_html_e( 'Texto puro no corpo (rhasspy/piper legado)', 'tainacan-narrativas' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<div data-tn-provider-pane="tts:wp_ai">
				<p class="description"><?php esc_html_e( 'Usa um conector do WordPress AI (7.0+) com suporte a texto-fala. A voz é definida pelo conector.', 'tainacan-narrativas' ); ?></p>
			</div>

			<div data-tn-provider-pane="tts:browser">
				<p class="description"><?php esc_html_e( 'Sem áudio no servidor: o roteiro é armazenado e o navegador do visitante o sintetiza localmente. Funciona em qualquer hospedagem.', 'tainacan-narrativas' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Voz do navegador (fallback)', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-browser-lang"><?php esc_html_e( 'Idioma', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-browser-lang" name="tn[browser_lang]" value="<?php echo esc_attr( (string) $settings['browser_lang'] ); ?>"></div>
				<div class="tn-field"><label for="tn-browser-voice"><?php esc_html_e( 'Preferência de voz (trecho do nome, ex.: "Francisca")', 'tainacan-narrativas' ); ?></label><input type="text" id="tn-browser-voice" name="tn[browser_voice_hint]" value="<?php echo esc_attr( (string) $settings['browser_voice_hint'] ); ?>"></div>
				<div class="tn-field"><label for="tn-browser-rate"><?php esc_html_e( 'Velocidade padrão', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-browser-rate" name="tn[browser_rate]" min="0.5" max="2" step="0.05" value="<?php echo esc_attr( (string) $settings['browser_rate'] ); ?>"></div>
			</div>

			<h3><?php esc_html_e( 'Parâmetros', 'tainacan-narrativas' ); ?></h3>
			<div class="tn-field-row">
				<div class="tn-field"><label for="tn-tts-speed"><?php esc_html_e( 'Velocidade da síntese', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-tts-speed" name="tn[tts_speed]" min="0.5" max="2" step="0.05" value="<?php echo esc_attr( (string) $settings['tts_speed'] ); ?>"></div>
				<div class="tn-field"><label for="tn-tts-timeout"><?php esc_html_e( 'Timeout (s)', 'tainacan-narrativas' ); ?></label><input type="number" id="tn-tts-timeout" name="tn[tts_timeout]" min="10" max="900" value="<?php echo (int) $settings['tts_timeout']; ?>"></div>
			</div>
			<div class="tn-actions-row">
				<?php if ( $can_manage ) : ?>
					<?php submit_button( __( 'Salvar voz', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?>
					<button type="button" class="button" data-tn-action="test-provider" data-kind="tts"><?php esc_html_e( 'Testar TTS', 'tainacan-narrativas' ); ?></button>
					<button type="button" class="button" data-tn-action="test-audio"><?php esc_html_e( 'Gerar áudio de teste', 'tainacan-narrativas' ); ?></button>
				<?php endif; ?>
			</div>
			<div class="tn-panel-output" data-tn-output hidden></div>
		</div>
	</fieldset>
</form>
