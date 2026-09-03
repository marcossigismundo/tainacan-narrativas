<?php
/**
 * Mode: detailed narrative (5–10 minutes).
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
		'TAREFA: escreva uma NARRATIVA DETALHADA do registro "{title}" (coleção "{collection}"), para ser ouvida em cinco a dez minutos.',
		'',
		'Percorra as fontes de forma completa e organizada: apresente o registro, seus metadados relevantes e, em seguida, o conteúdo do documento e dos anexos em profundidade, preservando a ordem interna dos documentos quando ela for significativa. Mantenha citações literais curtas quando forem essenciais, sinalizando-as com "segundo o documento" ou "conforme o registro".',
		'',
		'EXTENSÃO: até {target_words} palavras; escreva menos se as fontes forem curtas.',
		'',
		'FONTES:',
		'{sources}',
	)
);
