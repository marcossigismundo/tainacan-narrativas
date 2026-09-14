<?php
/**
 * Mode: detailed narrative (5–10 minutes).
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
		'TAREFA: escreva uma narração em áudio LONGA e DETALHADA do registro "{title}" (coleção "{collection}"), para ser ouvida em cinco a dez minutos, a partir exclusivamente das fontes.',
		'',
		'COMO CONSTRUIR: percorra o documento inteiro, do começo ao fim, na ordem em que ele se desenvolve, sem pular trechos e sem se concentrar só no início. Abra com uma cena ou fato concreto do documento; situe naturalmente o que é o registro, quem o produziu, quando e onde; conte cada parte do conteúdo com seus detalhes (pessoas, lugares, números, condições, gestos, palavras usadas); cite literalmente as passagens mais expressivas, introduzidas com "o documento diz", "ela escreve" ou similar; feche com um fato ou imagem do documento.',
		'',
		'TOM: sóbrio, humano, próximo. Prosa corrida, frases de tamanho variado, sem estrutura de relatório.',
		'',
		'EXTENSÃO: até {target_words} palavras; escreva menos se as fontes forem curtas. Nunca preencha com generalidades.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
