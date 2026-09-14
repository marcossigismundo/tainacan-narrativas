<?php
/**
 * Environment diagnostics.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Admin;

use TainacanNarrativas\AI\WordPressAIProvider;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Database\Tables;
use TainacanNarrativas\Documents\PdfExtractor;
use TainacanNarrativas\Narrative\NarrativeManager;
use TainacanNarrativas\Queue\QueueManager;
use TainacanNarrativas\Security\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a list of checks (label, status ok|warn|error|info, value) that
 * never includes secrets — endpoints are reduced to scheme + host.
 */
final class Diagnostics {

	/**
	 * Manager.
	 *
	 * @var NarrativeManager
	 */
	private NarrativeManager $manager;

	/**
	 * Constructor.
	 *
	 * @param NarrativeManager $manager Manager.
	 */
	public function __construct( NarrativeManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Runs all checks.
	 *
	 * @return array<int,array{key:string,label:string,status:string,value:string}>
	 */
	public function run(): array {
		global $wp_version;
		$checks = array();

		$checks[] = $this->check( 'wp', __( 'WordPress', 'tainacan-narrativas' ), version_compare( $wp_version, '6.5', '>=' ) ? 'ok' : 'error', $wp_version );
		$checks[] = $this->check( 'php', __( 'PHP', 'tainacan-narrativas' ), version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'error', PHP_VERSION );

		$ext = array();
		foreach ( array( 'zip', 'dom', 'mbstring', 'curl', 'json' ) as $e ) {
			$ext[] = $e . ( extension_loaded( $e ) ? ' ✓' : ' ✗' );
		}
		$missing  = array_filter( array( 'zip', 'dom', 'mbstring' ), static fn( $e ) => ! extension_loaded( $e ) );
		$checks[] = $this->check( 'ext', __( 'Extensões PHP', 'tainacan-narrativas' ), $missing ? 'warn' : 'ok', implode( ', ', $ext ) );

		$tainacan = defined( 'TAINACAN_VERSION' ) ? TAINACAN_VERSION : '';
		$checks[] = $this->check( 'tainacan', __( 'Tainacan', 'tainacan-narrativas' ), '' !== $tainacan && version_compare( $tainacan, '1.0.0', '>=' ) ? 'ok' : 'error', '' !== $tainacan ? $tainacan : __( 'não encontrado', 'tainacan-narrativas' ) );
		$checks[] = $this->check( 'https', __( 'HTTPS', 'tainacan-narrativas' ), is_ssl() ? 'ok' : 'warn', is_ssl() ? __( 'ativo', 'tainacan-narrativas' ) : __( 'inativo (o TTS do navegador e a Media Session funcionam melhor em HTTPS)', 'tainacan-narrativas' ) );

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next          = wp_next_scheduled( QueueManager::HOOK_PROCESS );
		$checks[]      = $this->check(
			'cron',
			__( 'WP-Cron', 'tainacan-narrativas' ),
			$next ? ( $cron_disabled ? 'info' : 'ok' ) : 'warn',
			$next
				/* translators: %s: human time diff. */
				? sprintf( __( 'próxima execução da fila em %s', 'tainacan-narrativas' ), human_time_diff( time(), (int) $next ) ) . ( $cron_disabled ? ' · DISABLE_WP_CRON (use cron do sistema ou "Executar fila")' : '' )
				: __( 'evento da fila não agendado (reative o plugin)', 'tainacan-narrativas' )
		);

		$checks[] = $this->check( 'rest', __( 'REST API', 'tainacan-narrativas' ), 'info', rest_url( 'tainacan-narrativas/v1' ) );

		$uploads    = wp_get_upload_dir();
		$writable   = empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] );
		$upload_msg = ! empty( $uploads['error'] ) ? (string) $uploads['error'] : __( 'não', 'tainacan-narrativas' );
		$checks[]   = $this->check( 'uploads', __( 'Diretório de uploads gravável', 'tainacan-narrativas' ), $writable ? 'ok' : 'error', $writable ? __( 'sim', 'tainacan-narrativas' ) : $upload_msg );
		$checks[]   = $this->check( 'tables', __( 'Tabelas do plugin', 'tainacan-narrativas' ), Tables::exist() ? 'ok' : 'error', Tables::exist() ? __( 'criadas', 'tainacan-narrativas' ) : __( 'ausentes (reative o plugin)', 'tainacan-narrativas' ) );
		$checks[]   = $this->check( 'pdf', __( 'Extração de PDF (smalot/pdfparser)', 'tainacan-narrativas' ), PdfExtractor::is_available() ? 'ok' : 'error', PdfExtractor::is_available() ? __( 'disponível', 'tainacan-narrativas' ) : __( 'biblioteca ausente em vendor/', 'tainacan-narrativas' ) );
		$core_idx   = defined( 'TAINACAN_INDEX_PDF_CONTENT' ) ? ( TAINACAN_INDEX_PDF_CONTENT ? 1 : 0 ) : (int) get_option( 'tainacan_option_index_pdf_content', 0 );
		$checks[]   = $this->check( 'core_index', __( 'Indexação de PDF do Tainacan', 'tainacan-narrativas' ), 'info', $core_idx ? __( 'ativa (o texto indexado do documento é reutilizado)', 'tainacan-narrativas' ) : __( 'inativa (o plugin extrai o texto por conta própria)', 'tainacan-narrativas' ) );

		// AI.
		$ai_id = (string) Options::get( 'ai_provider', 'none' );
		if ( 'none' === $ai_id ) {
			$checks[] = $this->check( 'ai', __( 'IA selecionada', 'tainacan-narrativas' ), 'info', __( 'nenhuma (roteiro documental por template)', 'tainacan-narrativas' ) );
		} else {
			$ai       = $this->manager->ai()->get( $ai_id );
			$checks[] = $this->check( 'ai', __( 'IA selecionada', 'tainacan-narrativas' ), $ai && $ai->is_configured() ? 'ok' : 'warn', $ai ? $ai->label() . ' · ' . $ai->model() . ( $ai->is_configured() ? '' : ' · ' . __( 'não configurada', 'tainacan-narrativas' ) ) : __( 'provedor desconhecido', 'tainacan-narrativas' ) );
			$checks[] = $this->check( 'ai_endpoint', __( 'Endpoint IA', 'tainacan-narrativas' ), 'info', $this->host_only( $this->ai_endpoint( $ai_id ) ) );
		}
		$checks[] = $this->check( 'wp_ai', __( 'WordPress AI Client', 'tainacan-narrativas' ), 'info', WordPressAIProvider::is_available() ? __( 'disponível (WP 7.0+)', 'tainacan-narrativas' ) : __( 'indisponível nesta versão do WordPress', 'tainacan-narrativas' ) );

		// TTS.
		$tts_id   = (string) Options::get( 'tts_provider', 'browser' );
		$tts      = $this->manager->tts()->get( $tts_id );
		$checks[] = $this->check( 'tts', __( 'TTS selecionado', 'tainacan-narrativas' ), $tts && $tts->is_configured() ? 'ok' : 'warn', $tts ? $tts->label() . ( $tts->is_configured() ? '' : ' · ' . __( 'não configurado (cai para a voz do navegador)', 'tainacan-narrativas' ) ) : __( 'provedor desconhecido', 'tainacan-narrativas' ) );
		if ( $tts && $tts->is_server_side() ) {
			$checks[] = $this->check( 'tts_endpoint', __( 'Endpoint TTS', 'tainacan-narrativas' ), 'info', $this->host_only( 'piper_http' === $tts_id ? (string) Options::get( 'piper_url', '' ) : (string) Options::get( 'tts_base_url', '' ) ) );
		}
		$checks[] = $this->check( 'private', __( 'Endpoints de rede privada', 'tainacan-narrativas' ), Options::is( 'allow_private_endpoints' ) ? 'info' : 'ok', Options::is( 'allow_private_endpoints' ) ? __( 'permitidos (necessário para Ollama/Kokoro/Piper locais)', 'tainacan-narrativas' ) : __( 'bloqueados (padrão seguro)', 'tainacan-narrativas' ) );

		// Queue.
		$counts   = $this->manager->jobs()->counts();
		$checks[] = $this->check( 'queue', __( 'Fila', 'tainacan-narrativas' ), $counts['failed'] > 0 ? 'warn' : 'ok', sprintf( 'queued=%d running=%d done=%d failed=%d', $counts['queued'], $counts['running'], $counts['done'], $counts['failed'] ) );
		$last     = $this->manager->jobs()->last();
		$checks[] = $this->check( 'last_job', __( 'Último job', 'tainacan-narrativas' ), 'info', $last ? sprintf( '#%d item %d · %s · %s', (int) $last['id'], (int) $last['item_id'], (string) $last['status'], (string) $last['updated_at'] ) : __( 'nenhum', 'tainacan-narrativas' ) );

		$checks[] = $this->check( 'limits', __( 'Limites PHP', 'tainacan-narrativas' ), 'info', 'memory_limit=' . ini_get( 'memory_limit' ) . ' · max_execution_time=' . ini_get( 'max_execution_time' ) );

		return $checks;
	}

	/**
	 * Builds a check row.
	 *
	 * @param string $key    Key.
	 * @param string $label  Label.
	 * @param string $status Status.
	 * @param string $value  Value.
	 * @return array{key:string,label:string,status:string,value:string}
	 */
	private function check( string $key, string $label, string $status, string $value ): array {
		return array(
			'key'    => $key,
			'label'  => $label,
			'status' => $status,
			'value'  => $value,
		);
	}

	/**
	 * Endpoint of an AI provider id.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	private function ai_endpoint( string $id ): string {
		switch ( $id ) {
			case 'openai':
				return 'https://api.openai.com/v1';
			case 'claude':
				return 'https://api.anthropic.com/v1';
			case 'groq':
				return 'https://api.groq.com/openai/v1';
			case 'deepseek':
				return 'https://api.deepseek.com/v1';
			case 'gemini':
				return 'https://generativelanguage.googleapis.com';
			case 'ollama':
				return (string) Options::get( 'ollama_base_url', '' );
			case 'wp_ai':
				return '';
			default:
				return (string) Options::get( 'ai_base_url', '' );
		}
	}

	/**
	 * Scheme + host (+ private flag) only.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function host_only( string $url ): string {
		if ( '' === trim( $url ) ) {
			return __( 'não configurado', 'tainacan-narrativas' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return __( 'inválido', 'tainacan-narrativas' );
		}
		$host = ( $parts['scheme'] ?? 'http' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return $host . ( Security::host_is_private( (string) $parts['host'] ) ? ' (' . __( 'rede privada', 'tainacan-narrativas' ) . ')' : '' );
	}
}
