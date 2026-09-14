<?php
/**
 * Mode: audio summary (about one minute).
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
		'TAREFA: escreva uma narração CURTA do registro "{title}" (coleção "{collection}"), para ser ouvida em cerca de um minuto, a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: diga o que é o registro, quem o produziu ou a quem se refere, quando e onde (só se as fontes informarem); conte os dois ou três fatos centrais do documento com um detalhe concreto cada, se possível com uma passagem literal breve; feche com o último fato relevante. Toda frase precisa ter apoio literal nas fontes.',
		'{brevity}',
		'EXTENSÃO: no máximo {target_words} palavras. Nada além do que está nas fontes; se as fontes forem curtas, três a cinco frases bastam.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
