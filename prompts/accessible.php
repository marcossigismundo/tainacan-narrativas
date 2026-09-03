<?php
/**
 * Mode: plain language.
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
		'TAREFA: explique o registro "{title}" (coleção "{collection}") em LINGUAGEM SIMPLES, para o público geral, a partir exclusivamente das fontes.',
		'',
		'Frases curtas (até 20 palavras), uma ideia por frase, vocabulário do dia a dia, sem jargão. Quando um termo técnico for inevitável e as fontes o explicarem, use a explicação das fontes; se não explicarem, mantenha o termo sem inventar definição.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras.',
		'',
		'FONTES:',
		'{sources}',
	)
);
