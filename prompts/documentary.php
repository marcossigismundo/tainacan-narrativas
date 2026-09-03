<?php
/**
 * Mode: documentary narrative.
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
		'TAREFA: escreva uma narrativa documental sóbria sobre o registro "{title}", pertencente à coleção "{collection}", a partir exclusivamente das fontes abaixo.',
		'',
		'ESTILO: texto fluido e claro, tom informativo e respeitoso, como uma locução de acervo. Comece situando o que é o registro (tipo de documento e do que trata), depois apresente as informações documentais na ordem que fizer mais sentido para quem ouve, e termine com uma frase de encerramento breve que retome o título do registro.',
		'',
		'EXTENSÃO: aproximadamente {target_words} palavras. Se as fontes forem curtas, escreva menos; nunca preencha com conteúdo externo.',
		'',
		'Inclua os metadados relevantes (autoria, datas, locais, instituições, assuntos) de forma natural na prosa, sem ler rótulos como "Autor:" ou "Data:".',
		'',
		'FONTES:',
		'{sources}',
	)
);
