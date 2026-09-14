<?php
/**
 * Mode: detailed narrative (longest allowed by the duration cap).
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
		'TAREFA: escreva a narração em áudio mais completa possível, dentro do limite de palavras, do registro "{title}" (coleção "{collection}"), a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: percorra o documento do começo ao fim, na ordem em que ele se desenvolve, escolhendo os fatos e passagens que melhor representam cada parte (o limite não permite tudo: prefira o que é concreto e literal). Diga naturalmente o que é o registro, quem o produziu, quando e onde; conte o conteúdo com os detalhes que o documento traz; cite literalmente uma ou duas passagens expressivas, introduzidas com "o documento diz", "ela escreve" ou similar; feche com o último fato relevante.',
		'{brevity}',
		'TOM: sóbrio, humano, próximo. Prosa corrida, frases de tamanho variado, sem estrutura de relatório. Toda frase precisa ter apoio literal nas fontes.',
		'',
		'EXTENSÃO: no máximo {target_words} palavras (cerca de dois minutos de áudio); escreva menos se as fontes forem curtas. Nunca preencha com generalidades ou contexto externo.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
