<?php
/**
 * Mode: children (only when explicitly enabled).
 *
 * Placeholders: {target_words} {title} {collection} {language} {sources}
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return implode(
	"\n",
	array(
		'TAREFA: apresente o registro "{title}" (coleção "{collection}") para crianças em idade escolar, a partir exclusivamente das fontes.',
		'',
		'Use frases curtas e tom acolhedor, mas NÃO infantilize temas sensíveis: se as fontes tratarem de morte, doença, sofrimento ou violência, fale com honestidade e delicadeza, sem eufemismos enganosos e sem detalhes desnecessários. Não invente personagens, diálogos, lições de moral ou finais.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras.',
		'',
		'FONTES:',
		'{sources}',
	)
);
