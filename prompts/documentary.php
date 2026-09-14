<?php
/**
 * Mode: documentary narrative.
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
		'TAREFA: escreva a narração em áudio do registro "{title}", da coleção "{collection}", a partir exclusivamente das fontes abaixo. Quem ouve não está vendo o documento: a narração precisa dizer o que ele é e o que ele contém — e nada além disso.',
		'',
		'COMO CONSTRUIR: diga naturalmente o que é o documento, quem o produziu e quando e onde, apenas com o que as fontes informam e sem rótulos. Depois conte o que o documento diz, na ordem em que ele diz, com os detalhes concretos que ele traz (pessoas, lugares, números, palavras usadas) e uma ou duas passagens literais breves. Feche com o último fato relevante do documento, sem síntese e sem moral. Toda frase precisa ter apoio literal nas fontes.',
		'{brevity}',
		'TOM: sóbrio, humano e próximo; informativo sem ser burocrático. Frases de tamanho variado, ritmo de fala.',
		'',
		'EXTENSÃO: no máximo {target_words} palavras (cerca de dois minutos de áudio). Se as fontes forem curtas, escreva bem menos; nunca preencha com contexto externo, generalidades ou repetições.',
		'',
		'{analysis}',
		'FONTES:',
		'{sources}',
	)
);
