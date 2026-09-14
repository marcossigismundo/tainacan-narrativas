<?php
/**
 * Mode: plain language.
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
		'TAREFA: conte o registro "{title}" (coleção "{collection}") em LINGUAGEM SIMPLES, para o público geral, a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: frases curtas (até 20 palavras), uma ideia por frase, vocabulário do dia a dia, sem jargão. Ainda assim é uma história, não uma lista: comece por algo concreto do documento, conte os acontecimentos na ordem em que aconteceram, com pessoas, lugares e datas como aparecem no texto, e termine com um fato ou imagem do documento. Quando um termo técnico for inevitável e as fontes o explicarem, use a explicação das fontes; se não explicarem, mantenha o termo sem inventar definição.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
