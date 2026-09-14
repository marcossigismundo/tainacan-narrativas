<?php
/**
 * Corrective pass: the deterministic checker found sentences with names,
 * numbers or claims that do not exist in the sources. The model must rewrite
 * the script without them — never add, never paraphrase them away.
 *
 * Placeholders: {title} {script} {unsupported} {target_words} {sources}
 *
 * @package TainacanNarrativas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return implode(
	"\n",
	array(
		'CORREÇÃO OBRIGATÓRIA: a narração abaixo, escrita para o registro "{title}", contém informações que NÃO existem nas fontes. Uma verificação automática encontrou, pelo menos, estas menções sem apoio nas fontes: {unsupported}.',
		'',
		'Reescreva a narração removendo TODA informação que não esteja literalmente nas fontes: nomes, números, datas, lugares, acontecimentos, sentimentos e contextualizações que as fontes não registram. Não substitua o que foi removido por outra invenção; se sobrar pouco, a narração fica mais curta. Não acrescente nada novo. Mantenha prosa corrida, sem títulos e sem listas, com no máximo {target_words} palavras.',
		'',
		'NARRAÇÃO A CORRIGIR:',
		'{script}',
		'',
		'FONTES (única base permitida):',
		'{sources}',
	)
);
