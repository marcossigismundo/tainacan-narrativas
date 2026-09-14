<?php
/**
 * Mode: audio summary (1–3 minutes).
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
		'TAREFA: escreva uma narração CURTA do registro "{title}" (coleção "{collection}"), para ser ouvida em um a três minutos, a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: mesmo curta, é uma narração e não uma ficha: abra com o fato ou a frase mais marcante do documento; diga naturalmente o que é o registro, quem o produziu ou a quem se refere, quando e onde (só se as fontes informarem); conte os dois ou três acontecimentos ou ideias centrais do documento com um detalhe concreto cada; feche com uma imagem ou fato do documento.',
		'',
		'EXTENSÃO: no máximo {target_words} palavras. Nada além do que está nas fontes.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
