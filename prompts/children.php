<?php
/**
 * Mode: children (only when explicitly enabled).
 *
 * Placeholders: {target_words} {title} {collection} {language} {sources} {analysis}
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return implode(
	"\n",
	array(
		'TAREFA: conte o registro "{title}" (coleção "{collection}") para crianças em idade escolar, a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: frases curtas, tom acolhedor, uma história com começo, meio e fim baseada só no que o documento conta. Não infantilize temas sensíveis: se as fontes tratarem de morte, doença, sofrimento ou violência, fale com honestidade e delicadeza, sem eufemismos enganosos e sem detalhes desnecessários. Não invente personagens, diálogos, lições de moral ou finais.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
