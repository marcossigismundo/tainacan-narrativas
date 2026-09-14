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
				'description'  => __( 'Texto fluido para ser ouvido, estritamente a partir do que está no item. Padrão recomendado.', 'tainacan-narrativas' ),
				'target_words' => 280,
				'ai'           => true,
			),
			'storytelling' => array(
				'label'        => __( 'Relato encadeado', 'tainacan-narrativas' ),
				'description'  => __( 'Começo, meio e fim, exclusivamente com os fatos registrados nas fontes (sem cenas nem contexto inventado).', 'tainacan-narrativas' ),
				'target_words' => 280,
				'ai'           => true,
			),
			'summary'      => array(
				'label'        => __( 'Resumo em áudio', 'tainacan-narrativas' ),
				'description'  => __( 'Cerca de um minuto.', 'tainacan-narrativas' ),
				'target_words' => 150,
				'ai'           => true,
			),
			'detailed'     => array(
				'label'        => __( 'Narrativa detalhada', 'tainacan-narrativas' ),
				'description'  => __( 'O máximo de conteúdo do documento que cabe no limite de duração.', 'tainacan-narrativas' ),
				'target_words' => 0,
				'ai'           => true,
			),
			'accessible'   => array(
				'label'        => __( 'Linguagem simples', 'tainacan-narrativas' ),
				'description'  => __( 'Texto acessível ao público geral, frases curtas e vocabulário comum.', 'tainacan-narrativas' ),
				'target_words' => 220,
				'ai'           => true,
			),
		);
		if ( Options::is( 'children_mode' ) ) {
			$modes['children'] = array(
				'label'        => __( 'Público infantil', 'tainacan-narrativas' ),
				'description'  => __( 'Somente quando habilitado explicitamente. Não infantiliza temas sensíveis.', 'tainacan-narrativas' ),
				'target_words' => 200,
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
	 * Word budget for a mode after the global duration cap (`max_words`,
	 * ≈ 2 minutes at 150 wpm). Every path — AI prompt, template builder,
	 * post-generation trim — uses this single number.
	 *
	 * @param string $mode Mode id.
	 * @return int Always > 0.
	 */
	public static function target_words_for( string $mode ): int {
		$cap    = (int) Options::get( 'max_words', 280 );
		$cap    = $cap > 0 ? $cap : 280;
		$target = (int) ( self::get( $mode )['target_words'] ?? 0 );
		return $target > 0 ? min( $target, $cap ) : $cap;
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
