<?php
/**
 * Intermediate step for large documents: merges chunk reductions into one
 * consolidated source before the analysis/mode prompts run.
 *
 * Placeholders: {title} {sources}
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return implode(
	"\n",
	array(
		'TAREFA: as fontes abaixo são reduções fiéis de trechos consecutivos de um mesmo documento do registro "{title}". Consolide-as em um único texto contínuo e coerente, mantendo a ordem original, eliminando repetições e preservando todos os nomes, datas, lugares, números, acontecimentos, detalhes concretos e as passagens literais entre aspas. Não acrescente nenhum fato que não esteja nelas e não interprete.',
		'',
		'Devolva apenas o texto consolidado.',
		'',
		'FONTES:',
		'{sources}',
	)
);
