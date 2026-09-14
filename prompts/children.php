<?php
/**
 * Mode: children (only when explicitly enabled).
 *
 * Placeholders: {target_words} {title} {collection} {language} {sources} {analysis} {brevity}
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
		'COMO CONSTRUIR: frases curtas, tom acolhedor, contando só o que o documento diz, na ordem em que diz. Não infantilize temas sensíveis: se as fontes tratarem de morte, doença, sofrimento ou violência, fale com honestidade e delicadeza, sem eufemismos enganosos e sem detalhes desnecessários. Não invente personagens, cenas, diálogos, lições de moral ou finais. Toda frase precisa ter apoio literal nas fontes.',
		'{brevity}',
		'EXTENSÃO: no máximo {target_words} palavras; menos se as fontes forem curtas.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
