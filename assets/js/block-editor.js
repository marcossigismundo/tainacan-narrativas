/**
 * Block editor registration for tainacan-narrativas/player.
 *
 * @package TainacanNarrativas
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var __ = wp.i18n.__;
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'tainacan-narrativas/player', {
		title: __( 'Narrativa em áudio (Tainacan)', 'tainacan-narrativas' ),
		description: __( 'Player da narrativa em áudio de um item Tainacan. Deixe o ID em 0 para usar o item da página atual.', 'tainacan-narrativas' ),
		icon: 'controls-volumeon',
		category: 'embed',
		attributes: {
			itemId: { type: 'number', default: 0 },
			title: { type: 'string', default: '' },
			showTranscript: { type: 'boolean', default: true }
		},
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				wp.element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Narrativa', 'tainacan-narrativas' ), initialOpen: true },
						el( TextControl, {
							label: __( 'ID do item Tainacan (0 = item atual)', 'tainacan-narrativas' ),
							type: 'number',
							min: 0,
							value: a.itemId,
							onChange: function ( v ) {
								props.setAttributes( { itemId: parseInt( v, 10 ) || 0 } );
							}
						} ),
						el( TextControl, {
							label: __( 'Título do player', 'tainacan-narrativas' ),
							value: a.title,
							onChange: function ( v ) {
								props.setAttributes( { title: v } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Mostrar “Ver texto”', 'tainacan-narrativas' ),
							checked: !! a.showTranscript,
							onChange: function ( v ) {
								props.setAttributes( { showTranscript: !! v } );
							}
						} )
					)
				),
				el( 'div', { className: props.className },
					a.itemId > 0
						? el( ServerSideRender, { block: 'tainacan-narrativas/player', attributes: a } )
						: el( 'p', { style: { padding: '1em', border: '1px dashed #999' } }, __( 'Narrativa em áudio do item atual (renderizada na página pública).', 'tainacan-narrativas' ) )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
