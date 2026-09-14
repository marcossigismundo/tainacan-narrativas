<?php
/**
 * Mode: contextualized story (storytelling from the sources only).
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
		'TAREFA: conte, em terceira pessoa, o que as fontes abaixo documentam sobre o registro "{title}" (coleção "{collection}"), com começo, meio e fim, como quem relata a um ouvinte uma história real que leu num documento.',
		'',
		'COMO CONSTRUIR: apresente as pessoas pelo que fazem e dizem no texto; avance no tempo com transições naturais; deixe as passagens literais mais fortes do documento falarem por si, introduzidas de forma simples. Termine no último fato registrado, não numa conclusão.',
		'{brevity}',
		'LIMITES ABSOLUTOS: toda pessoa, lugar, data, objeto e acontecimento deve existir literalmente nas fontes. Nada de cenas, ambientes, clima, gestos, diálogos ou contexto histórico que as fontes não descrevam. Não dramatize sofrimento, não especule sobre emoções não registradas, não crie um narrador-personagem. Se as fontes forem curtas, o relato é curto.',
		'',
		'Quando o registro tratar de morte, doença ou luto, mantenha tom sóbrio e respeitoso.',
		'',
		'EXTENSÃO: no máximo {target_words} palavras (cerca de dois minutos de áudio); menos se as fontes forem curtas.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
