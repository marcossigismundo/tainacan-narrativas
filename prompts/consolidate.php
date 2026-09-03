<?php
/**
 * Intermediate step for large documents: merges chunk reductions into one
 * consolidated source before the mode prompt runs.
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
		'TAREFA: as fontes abaixo são reduções factuais de trechos consecutivos de um mesmo documento do registro "{title}". Consolide-as em um único texto contínuo e coerente, eliminando repetições e mantendo a ordem original, sem acrescentar nenhum fato que não esteja nelas e sem interpretar.',
		'',
		'Devolva apenas o texto consolidado.',
		'',
		'FONTES:',
		'{sources}',
	)
);
