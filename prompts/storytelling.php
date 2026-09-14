<?php
/**
 * Mode: contextualized story (storytelling from the sources only).
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
		'TAREFA: conte, em terceira pessoa, a história que as fontes abaixo documentam sobre o registro "{title}" (coleção "{collection}"), como um narrador de documentário conta uma história real a quem escuta.',
		'',
		'COMO CONSTRUIR: história com começo, meio e fim. Comece no meio da ação ou por uma cena concreta registrada no documento; apresente as pessoas pelo que fazem e dizem no texto; avance no tempo com transições naturais; deixe as passagens literais mais fortes do documento falarem por si, introduzidas de forma simples. Termine numa imagem ou fato do documento, não numa conclusão.',
		'',
		'LIMITES: toda cena, personagem, lugar, data e acontecimento deve existir literalmente nas fontes. Você pode organizar cronologicamente, conectar fatos registrados e explicar termos usando apenas definições presentes nas fontes. Não dramatize sofrimento, não especule sobre emoções não registradas, não crie diálogos nem um narrador-personagem.',
		'',
		'Quando o registro tratar de morte, doença ou luto, mantenha tom sóbrio e respeitoso.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras; menos se as fontes forem curtas.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
