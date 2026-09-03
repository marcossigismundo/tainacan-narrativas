<?php
/**
 * Mode: audio summary (1–3 minutes).
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
		'TAREFA: escreva um RESUMO EM ÁUDIO do registro "{title}" (coleção "{collection}"), para ser ouvido em um a três minutos.',
		'',
		'Diga o que é o registro, quem o produziu ou a quem se refere, quando e onde (somente se as fontes informarem), e os dois ou três pontos mais importantes do conteúdo documental. Nada além do que está nas fontes.',
		'',
		'EXTENSÃO: no máximo {target_words} palavras.',
		'',
		'FONTES:',
		'{sources}',
	)
);
