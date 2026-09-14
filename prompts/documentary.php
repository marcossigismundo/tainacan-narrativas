<?php
/**
 * Mode: documentary narrative.
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
		'TAREFA: escreva a narração em áudio do registro "{title}", da coleção "{collection}", a partir exclusivamente das fontes abaixo. Quem ouve não está vendo o documento: a narração precisa contar o que ele contém, com riqueza de detalhes, como um documentário de rádio conta uma história real.',
		'',
		'COMO CONSTRUIR: abra com algo concreto do próprio documento (uma cena, uma pessoa, uma frase, um fato) e não com uma apresentação do registro. Situe, na sequência e de forma natural, o que é o documento, quem o produziu e quando e onde, sem rótulos. Depois conduza o ouvinte pelo conteúdo do documento inteiro, na ordem em que os fatos acontecem, com as passagens mais expressivas citadas literalmente e brevemente. Feche com um fato ou imagem do documento, sem síntese e sem moral.',
		'',
		'TOM: sóbrio, humano e próximo; informativo sem ser burocrático. Frases de tamanho variado, ritmo de fala.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras. Se as fontes forem curtas, escreva menos; nunca preencha com conteúdo externo, generalidades ou repetições.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
