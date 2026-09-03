<?php
/**
 * Narrative modes registry.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Narrative;

use TainacanNarrativas\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each mode maps to a prompt file in prompts/<id>.php and a target length used
 * both by the AI prompt and by the template (no-AI) builder.
 */
final class Modes {

	/**
	 * Built-in modes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$modes = array(
			'faithful'     => array(
				'label'        => __( 'Leitura fiel', 'tainacan-narrativas' ),
				'description'  => __( 'Praticamente nenhuma reescrita: metadados, descrição e documentos lidos como estão.', 'tainacan-narrativas' ),
				'target_words' => 0,
				'ai'           => false,
			),
			'documentary'  => array(
				'label'        => __( 'Narrativa documental', 'tainacan-narrativas' ),
				'description'  => __( 'Texto fluido para ser ouvido, mantendo rigor documental. Padrão recomendado.', 'tainacan-narrativas' ),
				'target_words' => 900,
				'ai'           => true,
			),
			'storytelling' => array(
				'label'        => __( 'História contextualizada', 'tainacan-narrativas' ),
				'description'  => __( 'Storytelling moderado, exclusivamente a partir das fontes fornecidas.', 'tainacan-narrativas' ),
				'target_words' => 900,
				'ai'           => true,
			),
			'summary'      => array(
				'label'        => __( 'Resumo em áudio', 'tainacan-narrativas' ),
				'description'  => __( 'Entre 1 e 3 minutos.', 'tainacan-narrativas' ),
				'target_words' => 300,
				'ai'           => true,
			),
			'detailed'     => array(
				'label'        => __( 'Narrativa detalhada', 'tainacan-narrativas' ),
				'description'  => __( 'Entre 5 e 10 minutos, conforme a quantidade de conteúdo.', 'tainacan-narrativas' ),
				'target_words' => 1500,
				'ai'           => true,
			),
			'accessible'   => array(
				'label'        => __( 'Linguagem simples', 'tainacan-narrativas' ),
				'description'  => __( 'Texto acessível ao público geral, frases curtas e vocabulário comum.', 'tainacan-narrativas' ),
				'target_words' => 600,
				'ai'           => true,
			),
		);
		if ( Options::is( 'children_mode' ) ) {
			$modes['children'] = array(
				'label'        => __( 'Público infantil', 'tainacan-narrativas' ),
				'description'  => __( 'Somente quando habilitado explicitamente. Não infantiliza temas sensíveis.', 'tainacan-narrativas' ),
				'target_words' => 400,
				'ai'           => true,
			);
		}
		/**
		 * Filters narrative modes. Keys must match a prompts/<key>.php file.
		 *
		 * @param array<string,array<string,mixed>> $modes Modes.
		 */
		$filtered = apply_filters( 'tainacan_narrativas_modes', $modes );
		return is_array( $filtered ) ? $filtered : $modes;
	}

	/**
	 * Whether a mode exists.
	 *
	 * @param string $mode Mode id.
	 * @return bool
	 */
	public static function exists( string $mode ): bool {
		return isset( self::all()[ $mode ] );
	}

	/**
	 * Mode definition, falling back to documentary.
	 *
	 * @param string $mode Mode id.
	 * @return array<string,mixed>
	 */
	public static function get( string $mode ): array {
		$all = self::all();
		return $all[ $mode ] ?? $all['documentary'];
	}

	/**
	 * Sanitizes a mode id coming from input.
	 *
	 * @param mixed $mode Raw.
	 * @return string
	 */
	public static function sanitize( $mode ): string {
		$mode = is_string( $mode ) ? sanitize_key( $mode ) : '';
		return self::exists( $mode ) ? $mode : 'documentary';
	}

	/**
	 * Labels (id => label).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$out = array();
		foreach ( self::all() as $id => $def ) {
			$out[ $id ] = (string) $def['label'];
		}
		return $out;
	}
}
