<?php
/**
 * Mode: contextualized story (moderate storytelling, sources only).
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
		'TAREFA: conte, em terceira pessoa (nunca inventando um narrador-personagem), a história que as fontes abaixo documentam sobre o registro "{title}" (coleção "{collection}").',
		'',
		'ESTILO: narrativa com começo, meio e fim, ritmo de conversa, mas com storytelling MODERADO: toda cena, personagem, lugar, data e acontecimento deve existir literalmente nas fontes. Você pode organizar cronologicamente, conectar fatos registrados e explicar termos usando apenas definições presentes nas fontes. Não dramatize sofrimento, não especule sobre emoções não registradas, não crie diálogos.',
		'',
		'Quando o registro tratar de morte, doença ou luto, mantenha tom sóbrio e respeitoso.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras.',
		'',
		'FONTES:',
		'{sources}',
	)
);
