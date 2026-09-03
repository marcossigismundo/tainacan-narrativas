<?php
/**
 * Per-collection configuration.
 *
 * @var array<int,string>    $collections
 * @var int                  $selected_collection
 * @var array<string,mixed>  $collection_entry
 * @var array<int,array>     $collection_metadata
 * @var array<string,array>  $modes
 * @var array<string,string> $ai_labels
 * @var array<string,string> $tts_labels
 * @var array<string,mixed>  $settings
 * @var string               $base_url
 * @var bool                 $can_manage
 * @var bool                 $can_generate
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tn_all = \TainacanNarrativas\Tainacan\CollectionSettings::all();
?>
<div class="tn-panel">
	<h2><?php esc_html_e( 'Coleções', 'tainacan-narrativas' ); ?></h2>
	<p class="description"><?php esc_html_e( 'As narrativas são opt-in por coleção. A configuração global serve como padrão; cada coleção pode sobrescrever.', 'tainacan-narrativas' ); ?></p>
	<table class="widefat striped tn-table">
		<thead><tr><th><?php esc_html_e( 'Coleção', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Narrativa', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Modo', 'tainacan-narrativas' ); ?></th><th><?php esc_html_e( 'Fluxo', 'tainacan-narrativas' ); ?></th><th></th></tr></thead>
		<tbody>
			<?php foreach ( $collections as $tn_cid => $tn_name ) : ?>
				<?php
				$tn_entry = isset( $tn_all[ $tn_cid ] ) && is_array( $tn_all[ $tn_cid ] ) ? wp_parse_args( $tn_all[ $tn_cid ], \TainacanNarrativas\Tainacan\CollectionSettings::entry_defaults() ) : \TainacanNarrativas\Tainacan\CollectionSettings::entry_defaults();
				$tn_mode  = 'inherit' === $tn_entry['mode'] ? (string) $settings['default_mode'] : (string) $tn_entry['mode'];
				$tn_flow  = 'inherit' === $tn_entry['editorial_flow'] ? (string) $settings['editorial_flow'] : (string) $tn_entry['editorial_flow'];
				?>
				<tr class="<?php echo (int) $tn_cid === $selected_collection ? 'tn-row--selected' : ''; ?>">
					<td><strong><?php echo esc_html( $tn_name ); ?></strong> <span class="tn-muted">#<?php echo (int) $tn_cid; ?></span></td>
					<td><?php echo ! empty( $tn_entry['enabled'] ) ? '<span class="tn-status tn-status--ready">' . esc_html__( 'habilitada', 'tainacan-narrativas' ) . '</span>' : '<span class="tn-status tn-status--none">' . esc_html__( 'desabilitada', 'tainacan-narrativas' ) . '</span>'; ?></td>
					<td><?php echo esc_html( $modes[ $tn_mode ]['label'] ?? $tn_mode ); ?></td>
					<td><?php echo 'review' === $tn_flow ? esc_html__( 'revisão humana', 'tainacan-narrativas' ) : esc_html__( 'automático', 'tainacan-narrativas' ); ?></td>
					<td class="tn-actions-cell">
						<a class="button button-small" href="<?php echo esc_url( $base_url . '&tab=collections&collection=' . (int) $tn_cid ); ?>"><?php esc_html_e( 'Configurar', 'tainacan-narrativas' ); ?></a>
						<?php if ( $can_generate && ! empty( $tn_entry['enabled'] ) && ! empty( $settings['enabled'] ) ) : ?>
							<button type="button" class="button button-small" data-tn-action="bulk-generate" data-collection="<?php echo (int) $tn_cid; ?>"><?php esc_html_e( 'Gerar narrativas dos itens', 'tainacan-narrativas' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<div class="tn-panel-output" data-tn-output hidden></div>
</div>

<?php if ( $selected_collection > 0 && isset( $collections[ $selected_collection ] ) ) : ?>
	<?php $tn_c = $collection_entry; ?>
	<div class="tn-panel">
		<h2>
			<?php
			/* translators: %s: collection name. */
			printf( esc_html__( 'Configuração da coleção: %s', 'tainacan-narrativas' ), esc_html( $collections[ $selected_collection ] ) );
			?>
		</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tn-form">
			<?php wp_nonce_field( 'tn_collection' ); ?>
			<input type="hidden" name="action" value="tn_save_collection">
			<input type="hidden" name="collection_id" value="<?php echo (int) $selected_collection; ?>">
			<input type="hidden" name="tnc[__form]" value="1">
			<fieldset <?php disabled( ! $can_manage ); ?>>
				<div class="tn-field tn-field--check">
					<label><input type="checkbox" name="tnc[enabled]" value="1" <?php checked( ! empty( $tn_c['enabled'] ) ); ?>> <strong><?php esc_html_e( 'Narrativa habilitada nesta coleção', 'tainacan-narrativas' ); ?></strong></label>
					<label><input type="checkbox" name="tnc[autoinject]" value="1" <?php checked( ! empty( $tn_c['autoinject'] ) ); ?>> <?php esc_html_e( 'Player automático na página do item', 'tainacan-narrativas' ); ?></label>
				</div>

				<h3><?php esc_html_e( 'Fontes', 'tainacan-narrativas' ); ?></h3>
				<div class="tn-field tn-field--check">
					<label><input type="checkbox" name="tnc[include_description]" value="1" <?php checked( ! empty( $tn_c['include_description'] ) ); ?>> <?php esc_html_e( 'Incluir descrição', 'tainacan-narrativas' ); ?></label>
					<label><input type="checkbox" name="tnc[include_document]" value="1" <?php checked( ! empty( $tn_c['include_document'] ) ); ?>> <?php esc_html_e( 'Incluir documento principal', 'tainacan-narrativas' ); ?></label>
					<label><input type="checkbox" name="tnc[include_attachments]" value="1" <?php checked( ! empty( $tn_c['include_attachments'] ) ); ?>> <?php esc_html_e( 'Incluir anexos', 'tainacan-narrativas' ); ?></label>
				</div>
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tnc-max-att"><?php esc_html_e( 'Máximo de anexos (0 = padrão global)', 'tainacan-narrativas' ); ?></label>
						<input type="number" id="tnc-max-att" name="tnc[max_attachments]" min="0" max="50" value="<?php echo (int) $tn_c['max_attachments']; ?>">
					</div>
					<div class="tn-field">
						<label for="tnc-max-chars"><?php esc_html_e( 'Limite de caracteres do item (0 = padrão global)', 'tainacan-narrativas' ); ?></label>
						<input type="number" id="tnc-max-chars" name="tnc[max_chars_item]" min="0" max="500000" step="1000" value="<?php echo (int) $tn_c['max_chars_item']; ?>">
					</div>
				</div>

				<h3><?php esc_html_e( 'Metadados utilizados', 'tainacan-narrativas' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Somente metadados públicos podem entrar na narrativa. Sem nenhum marcado, todos os públicos são usados. A ordem dos marcados define a ordem de leitura (arraste não é necessário: a ordem numérica dos IDs marcados segue a ordem da coleção).', 'tainacan-narrativas' ); ?></p>
				<div class="tn-checklist">
					<?php foreach ( $collection_metadata as $tn_mid => $tn_m ) : ?>
						<?php
						$tn_is_core    = in_array( $tn_m['type'], array( 'Core_Title', 'Core_Description' ), true );
						$tn_is_private = 'publish' !== $tn_m['status'];
						?>
						<label class="<?php echo $tn_is_private || $tn_is_core ? 'tn-muted' : ''; ?>">
							<input type="checkbox" name="tnc[metadata][]" value="<?php echo (int) $tn_mid; ?>" <?php checked( in_array( (int) $tn_mid, array_map( 'intval', (array) $tn_c['metadata'] ), true ) ); ?> <?php disabled( $tn_is_private || $tn_is_core ); ?>>
							<?php echo esc_html( $tn_m['name'] ); ?>
							<span class="tn-muted">(<?php echo esc_html( $tn_m['type'] ); ?><?php echo $tn_is_private ? ', ' . esc_html__( 'privado — nunca usado', 'tainacan-narrativas' ) : ''; ?><?php echo $tn_is_core ? ', ' . esc_html__( 'campo dedicado', 'tainacan-narrativas' ) : ''; ?>)</span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="tn-field">
					<label for="tnc-order"><?php esc_html_e( 'Ordem dos metadados (IDs separados por vírgula, opcional)', 'tainacan-narrativas' ); ?></label>
					<input type="text" id="tnc-order" class="regular-text" name="tnc[metadata_order_csv]" value="<?php echo esc_attr( implode( ',', array_map( 'intval', (array) $tn_c['metadata_order'] ) ) ); ?>" data-tn-csv-target="tnc-order-hidden">
					<span id="tnc-order-hidden" data-tn-csv-name="tnc[metadata_order][]"></span>
				</div>

				<h3><?php esc_html_e( 'Narrativa', 'tainacan-narrativas' ); ?></h3>
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tnc-mode"><?php esc_html_e( 'Modo narrativo', 'tainacan-narrativas' ); ?></label>
						<select id="tnc-mode" name="tnc[mode]">
							<option value="inherit" <?php selected( $tn_c['mode'], 'inherit' ); ?>><?php esc_html_e( 'Padrão global', 'tainacan-narrativas' ); ?></option>
							<?php foreach ( $modes as $tn_key => $tn_def ) : ?>
								<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $tn_c['mode'], $tn_key ); ?>><?php echo esc_html( $tn_def['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="tn-field">
						<label for="tnc-flow"><?php esc_html_e( 'Fluxo editorial', 'tainacan-narrativas' ); ?></label>
						<select id="tnc-flow" name="tnc[editorial_flow]">
							<option value="inherit" <?php selected( $tn_c['editorial_flow'], 'inherit' ); ?>><?php esc_html_e( 'Padrão global', 'tainacan-narrativas' ); ?></option>
							<option value="review" <?php selected( $tn_c['editorial_flow'], 'review' ); ?>><?php esc_html_e( 'Revisão humana antes do áudio (recomendado)', 'tainacan-narrativas' ); ?></option>
							<option value="auto" <?php selected( $tn_c['editorial_flow'], 'auto' ); ?>><?php esc_html_e( 'Automático: roteiro → áudio', 'tainacan-narrativas' ); ?></option>
						</select>
					</div>
					<div class="tn-field">
						<label for="tnc-sens"><?php esc_html_e( 'Conteúdo sensível', 'tainacan-narrativas' ); ?></label>
						<select id="tnc-sens" name="tnc[sensitivity]">
							<option value="auto" <?php selected( $tn_c['sensitivity'], 'auto' ); ?>><?php esc_html_e( 'Narrativa automática', 'tainacan-narrativas' ); ?></option>
							<option value="review" <?php selected( $tn_c['sensitivity'], 'review' ); ?>><?php esc_html_e( 'Revisão obrigatória', 'tainacan-narrativas' ); ?></option>
							<option value="none" <?php selected( $tn_c['sensitivity'], 'none' ); ?>><?php esc_html_e( 'Não gerar narrativa', 'tainacan-narrativas' ); ?></option>
						</select>
					</div>
				</div>

				<h3><?php esc_html_e( 'IA e voz', 'tainacan-narrativas' ); ?></h3>
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tnc-ai"><?php esc_html_e( 'IA', 'tainacan-narrativas' ); ?></label>
						<select id="tnc-ai" name="tnc[ai]">
							<option value="inherit" <?php selected( $tn_c['ai'], 'inherit' ); ?>><?php esc_html_e( 'Padrão global', 'tainacan-narrativas' ); ?></option>
							<?php foreach ( $ai_labels as $tn_key => $tn_label ) : ?>
								<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $tn_c['ai'], $tn_key ); ?>><?php echo esc_html( $tn_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="tn-field">
						<label for="tnc-tts"><?php esc_html_e( 'TTS', 'tainacan-narrativas' ); ?></label>
						<select id="tnc-tts" name="tnc[tts]">
							<option value="inherit" <?php selected( $tn_c['tts'], 'inherit' ); ?>><?php esc_html_e( 'Padrão global', 'tainacan-narrativas' ); ?></option>
							<?php foreach ( $tts_labels as $tn_key => $tn_label ) : ?>
								<option value="<?php echo esc_attr( $tn_key ); ?>" <?php selected( $tn_c['tts'], $tn_key ); ?>><?php echo esc_html( $tn_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="tn-field">
						<label for="tnc-voice"><?php esc_html_e( 'Voz (vazio = padrão global)', 'tainacan-narrativas' ); ?></label>
						<input type="text" id="tnc-voice" name="tnc[tts_voice]" value="<?php echo esc_attr( (string) $tn_c['tts_voice'] ); ?>">
					</div>
				</div>
				<div class="tn-field tn-field--check">
					<label><input type="checkbox" name="tnc[external_ai]" value="1" <?php checked( ! empty( $tn_c['external_ai'] ) ); ?>> <?php esc_html_e( 'Permitir envio do conteúdo a IA externa (fora do servidor)', 'tainacan-narrativas' ); ?></label>
					<label><input type="checkbox" name="tnc[external_ai_attachments]" value="1" <?php checked( ! empty( $tn_c['external_ai_attachments'] ) ); ?>> <?php esc_html_e( 'Incluir texto dos anexos no envio à IA externa', 'tainacan-narrativas' ); ?></label>
				</div>
				<div class="tn-field">
					<label for="tnc-dl"><?php esc_html_e( 'Permitir download do áudio', 'tainacan-narrativas' ); ?></label>
					<select id="tnc-dl" name="tnc[allow_download]">
						<option value="inherit" <?php selected( (string) $tn_c['allow_download'], 'inherit' ); ?>><?php esc_html_e( 'Padrão global', 'tainacan-narrativas' ); ?></option>
						<option value="1" <?php selected( (string) $tn_c['allow_download'], '1' ); ?>><?php esc_html_e( 'Sim', 'tainacan-narrativas' ); ?></option>
						<option value="0" <?php selected( (string) $tn_c['allow_download'], '0' ); ?>><?php esc_html_e( 'Não', 'tainacan-narrativas' ); ?></option>
					</select>
				</div>
			</fieldset>
			<?php if ( $can_manage ) : ?>
				<p class="tn-actions-row"><?php submit_button( __( 'Salvar coleção', 'tainacan-narrativas' ), 'primary', 'submit', false ); ?></p>
			<?php endif; ?>
		</form>
	</div>
<?php endif; ?>
