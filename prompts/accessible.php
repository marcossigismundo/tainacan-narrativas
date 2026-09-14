<?php
/**
 * Mode: plain language.
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
		'TAREFA: conte o registro "{title}" (coleção "{collection}") em LINGUAGEM SIMPLES, para o público geral, a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: frases curtas (até 20 palavras), uma ideia por frase, vocabulário do dia a dia, sem jargão. Diga o que é o documento, de quem e de quando (só o que as fontes informam), depois o que ele conta, na ordem em que conta, com pessoas, lugares e datas como aparecem no texto. Quando um termo técnico for inevitável e as fontes o explicarem, use a explicação das fontes; se não explicarem, mantenha o termo sem inventar definição. Toda frase precisa ter apoio literal nas fontes.',
		'{brevity}',
		'EXTENSÃO: no máximo {target_words} palavras; menos se as fontes forem curtas.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
