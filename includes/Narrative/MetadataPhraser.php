<?php
/**
 * Turns "Label: value" metadata into spoken sentences (no AI).
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heuristics on the metadatum label (pt-BR, with common EN synonyms). Unknown
 * labels fall back to "Label: value.", which still reads acceptably.
 */
final class MetadataPhraser {

	/**
	 * Patterns (regex on the lowercase label) => sprintf template with %s = value.
	 *
	 * @var array<string,string>
	 */
	private const PATTERNS = array(
		// Only phrasings that restate the field's own meaning; anything that
		// would interpret the value ("está situado em", "vem de") is avoided
		// because it can assert something the item never said.
		'/^(autor|autoria|autores|criador|criadores|creator|author)\b/u' => 'De autoria de %s.',
		'/^(produtor|produtora|produção|realização)\b/u'   => 'Produção de %s.',
		'/^(entrevistad[oa]|depoente)\b/u'                 => 'Depoimento de %s.',
		'/^(fotógraf[oa]|fotografo)\b/u'                   => 'Fotografia de %s.',
		'/^(colaborador|colaboradores|contributor)\b/u'    => 'Colaboração de %s.',
		'/^(data|date|datas?|data de (criação|produção|publicação|registro|coleta|emissão)|ano|período|periodo|época|epoca)\b/u' => 'Data registrada: %s.',
		'/^(local|lugar|localidade|cidade|município|municipio|estado|país|pais|região|regiao|cobertura espacial|spatial|place|location|procedência|procedencia|origem)\b/u' => 'Local registrado: %s.',
		'/^(assunto|assuntos|tema|temas|temática|palavras?-chave|palavras chave|keywords?|subject|tags?|categoria|categorias|descritores?)\b/u' => 'Assuntos: %s.',
		'/^(tipo|tipologia|type|gênero|genero|natureza|espécie documental)\b/u' => 'Tipo de documento: %s.',
		'/^(formato|format|suporte|material|técnica|tecnica)\b/u' => 'Formato: %s.',
		'/^(idioma|língua|lingua|language)\b/u'            => 'Idioma: %s.',
		'/^(direitos|licença|licenca|rights|license)\b/u'  => 'Direitos de uso: %s.',
		'/^(identificador|identifier|código|codigo|número de registro|numero de registro|tombo|notação|notacao)\b/u' => 'Identificador: %s.',
		'/^(resumo|sinopse|abstract|âmbito e conteúdo|ambito e conteudo|conteúdo|conteudo|nota|notas|observações|observacoes|histórico|historico|história|historia|contexto|biografia|comentário|comentario|transcrição|transcricao|legenda|depoimento|relato)\b/u' => '%s',
		'/^(relacionad|ver também|ver tambem|relation|item relacionado|documentos relacionados)/u' => 'Item relacionado: %s.',
		'/^(url|link|endereço|site|website|permalink)\b/u' => '',
	);

	/**
	 * One spoken sentence for a metadatum.
	 *
	 * @param string $label Label.
	 * @param string $value Value (already cleaned).
	 * @param string $type  Metadata type short name (Date, Numeric, Taxonomy…).
	 * @return string Empty when the metadatum should not be narrated.
	 */
	public static function sentence( string $label, string $value, string $type = '' ): string {
		$label = trim( $label );
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$value = self::spoken_value( $value, $type );
		if ( '' === $value ) {
			return '';
		}
		$lower = mb_strtolower( $label );
		foreach ( self::PATTERNS as $pattern => $template ) {
			if ( preg_match( $pattern, $lower ) ) {
				if ( '' === $template ) {
					return '';
				}
				if ( '%s' === $template ) {
					return self::ensure_period( $value );
				}
				return sprintf( $template, rtrim( $value, '.;,' ) );
			}
		}
		return $label . ': ' . rtrim( $value, '.;,' ) . '.';
	}

	/**
	 * Multi-value lists read as "a, b e c"; dates as "12 de março de 2020".
	 *
	 * @param string $value Value.
	 * @param string $type  Metadata type.
	 * @return string
	 */
	private static function spoken_value( string $value, string $type ): string {
		if ( 'Date' === $type || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
				$months = array( '', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' );
				$month  = (int) $m[2];
				$day    = (int) $m[3];
				if ( $month >= 1 && $month <= 12 && $day >= 1 ) {
					return ( 1 === $day ? 'primeiro' : (string) $day ) . ' de ' . $months[ $month ] . ' de ' . $m[1];
				}
			}
		}
		// Tainacan joins multi-values with "|" or ", "; taxonomy paths with ">".
		if ( str_contains( $value, '|' ) ) {
			$parts = array_values( array_filter( array_map( 'trim', explode( '|', $value ) ), static fn( string $p ): bool => '' !== $p ) );
			$value = self::join_list( $parts );
		}
		$value = str_replace( ' > ', ', ', $value );
		return $value;
	}

	/**
	 * "a, b e c".
	 *
	 * @param string[] $parts Parts.
	 * @return string
	 */
	private static function join_list( array $parts ): string {
		$n = count( $parts );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $parts[0];
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ' e ' . $last;
	}

	/**
	 * Ends a free-text value with a period.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function ensure_period( string $value ): string {
		return preg_match( '/[\.\!\?…]$/u', $value ) ? $value : $value . '.';
	}
}
