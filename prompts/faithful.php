<?php
/**
 * Mode: faithful reading. Almost no rewriting; used when the AI is asked only
 * to smooth the reading of the raw documentary script.
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
		'TAREFA: prepare uma LEITURA FIEL em voz alta do registro "{title}" (coleção "{collection}").',
		'',
		'Não resuma, não reordene, não interprete e não acrescente nada. Apenas: (a) transcreva o conteúdo das fontes na ordem em que aparecem; (b) transforme rótulos de metadados em frases mínimas do tipo "Autoria: fulano."; (c) remova ruídos de extração (números de página soltos, cabeçalhos repetidos, quebras de linha no meio de frases).',
		'',
		'Mantenha todo o restante literalmente, incluindo nomes, datas, números e citações.',
		'',
		'FONTES:',
		'{sources}',
	)
);
