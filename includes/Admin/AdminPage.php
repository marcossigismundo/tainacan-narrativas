<?php
/**
 * Admin screen: Tainacan → Narrativas.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Admin;

use TainacanNarrativas\Core\Capabilities;
use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Core\Plugin;
use TainacanNarrativas\Database\NarrativeRepository as Repo;
use TainacanNarrativas\Narrative\Modes;
use TainacanNarrativas\Tainacan\CollectionSettings;
use TainacanNarrativas\Tainacan\ItemDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends \Tainacan\Pages (submenu in the "Other" group, native shell) —
 * same pattern as tainacan-colab / tainacan-wacz-player. Markup lives in
 * views/page.php + views/tab-*.php; actions run through REST + admin.js.
 */
class AdminPage extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	/**
	 * Tabs (slug => label).
	 *
	 * @var array<string,string>
	 */
	private array $tabs = array();

	/**
	 * Page suffix.
	 *
	 * @var string
	 */
	private string $page_suffix = '';

	/**
	 * Slug.
	 *
	 * @return string
	 */
	protected function get_page_slug(): string {
		return Plugin::ADMIN_PAGE_SLUG;
	}

	/**
	 * Tab list (translated lazily).
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		if ( ! $this->tabs ) {
			$this->tabs = array(
				'dashboard'   => __( 'Painel', 'tainacan-narrativas' ),
				'narratives'  => __( 'Narrativas', 'tainacan-narrativas' ),
				'collections' => __( 'Coleções', 'tainacan-narrativas' ),
				'ai'          => __( 'IA', 'tainacan-narrativas' ),
				'voice'       => __( 'Voz', 'tainacan-narrativas' ),
				'processing'  => __( 'Processamento', 'tainacan-narrativas' ),
				'settings'    => __( 'Configurações', 'tainacan-narrativas' ),
				'diagnostics' => __( 'Diagnóstico', 'tainacan-narrativas' ),
			);
		}
		return $this->tabs;
	}

	/**
	 * Registers the submenu under Tainacan's "Other" group.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		$icon_svg    = method_exists( $this, 'get_svg_icon' ) ? $this->get_svg_icon( 'media' ) : '';
		$parent_slug = $this->has_admin_ui_option( 'hideNavigationOtherMenu' ) ? $this->tainacan_root_menu_slug : $this->tainacan_other_links_slug;

		$this->page_suffix = add_submenu_page(
			$parent_slug,
			__( 'Narrativas', 'tainacan-narrativas' ),
			'<span class="icon" aria-hidden="true">' . $icon_svg . '</span><span class="menu-text">' . esc_html__( 'Narrativas', 'tainacan-narrativas' ) . '</span>',
			Capabilities::REVIEW,
			$this->get_page_slug(),
			array( $this, 'render_page' )
		);
		if ( $this->page_suffix ) {
			add_action( 'load-' . $this->page_suffix, array( $this, 'load_page' ) );
		}
	}

	/**
	 * CSS.
	 *
	 * @return void
	 */
	public function admin_enqueue_css() {
		wp_enqueue_style( 'tn-admin', TN_PLUGIN_URL . 'assets/css/admin.css', array(), TN_VERSION );
		wp_enqueue_style( 'tn-player', TN_PLUGIN_URL . 'assets/css/player.css', array(), TN_VERSION );
	}

	/**
	 * JS.
	 *
	 * @return void
	 */
	public function admin_enqueue_js() {
		wp_enqueue_script( 'tn-admin', TN_PLUGIN_URL . 'assets/js/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), TN_VERSION, true );
		wp_localize_script(
			'tn-admin',
			'tnAdmin',
			array(
				'restNamespace' => 'tainacan-narrativas/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'tab'           => $this->current_tab(),
				'runQueue'      => isset( $_GET['run_queue'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI hint after the wizard redirect; no state mutation.
				'can'           => array(
					'generate' => current_user_can( Capabilities::GENERATE ),
					'manage'   => current_user_can( Capabilities::MANAGE ),
				),
				'statusLabels'  => Repo::status_labels(),
				'modeLabels'    => Modes::labels(),
				'i18n'          => array(
					'working'      => __( 'Processando…', 'tainacan-narrativas' ),
					'queued'       => __( 'Enfileirado. Executando a fila…', 'tainacan-narrativas' ),
					'done'         => __( 'Concluído.', 'tainacan-narrativas' ),
					'error'        => __( 'Erro', 'tainacan-narrativas' ),
					'confirmDel'   => __( 'Excluir a narrativa e o áudio deste item? Esta ação não pode ser desfeita.', 'tainacan-narrativas' ),
					'confirmAudio' => __( 'Excluir o áudio gerado? O roteiro será mantido.', 'tainacan-narrativas' ),
					'saved'        => __( 'Roteiro salvo. Aprove para gerar o áudio.', 'tainacan-narrativas' ),
					'noJobs'       => __( 'Fila vazia.', 'tainacan-narrativas' ),
					'queueRun'     => /* translators: 1: done, 2: retry, 3: failed. */ __( 'Fila: %1$d concluído(s), %2$d reagendado(s), %3$d falha(s).', 'tainacan-narrativas' ),
					'testing'      => __( 'Testando…', 'tainacan-narrativas' ),
					'copyOk'       => __( 'Copiado.', 'tainacan-narrativas' ),
					'sourcesTitle' => __( 'Fontes que entrarão na narrativa', 'tainacan-narrativas' ),
				),
			)
		);
		wp_set_script_translations( 'tn-admin', 'tainacan-narrativas' );
	}

	/**
	 * Current tab from the URL (allowlisted).
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab selector; no state mutation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'wizard' === $tab ) {
			return 'wizard';
		}
		if ( '' === $tab && ! Options::is( 'setup_done' ) ) {
			return 'wizard';
		}
		return isset( $this->tabs()[ $tab ] ) ? $tab : 'dashboard';
	}

	/**
	 * Renders the page content inside the Tainacan shell.
	 *
	 * @return void
	 */
	public function render_page_content() {
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'tainacan-narrativas' ) );
		}
		$manager = Plugin::instance()->manager();
		if ( ! $manager ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Tainacan não está ativo.', 'tainacan-narrativas' ) . '</p></div>';
			return;
		}

		$tab           = $this->current_tab();
		$tabs          = $this->tabs();
		$base_url      = Plugin::admin_url();
		$settings      = Options::all();
		$stats         = $manager->repo()->stats();
		$job_counts    = $manager->jobs()->counts();
		$collections   = ItemDetector::list_collections();
		$modes         = Modes::all();
		$ai_labels     = $manager->ai()->labels();
		$tts_labels    = $manager->tts()->labels();
		$status_labels = Repo::status_labels();
		$can_manage    = current_user_can( Capabilities::MANAGE );
		$can_generate  = current_user_can( Capabilities::GENERATE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice key after a redirect; no state mutation.
		$notice        = isset( $_GET['tn_notice'] ) ? sanitize_key( wp_unslash( $_GET['tn_notice'] ) ) : '';
		$notice_errors = array();
		if ( 'error' === $notice ) {
			$stored        = get_transient( 'tn_notice_errors_' . get_current_user_id() );
			$notice_errors = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
			delete_transient( 'tn_notice_errors_' . get_current_user_id() );
		}

		// Tab-specific data.
		$selected_collection = 0;
		$collection_entry    = array();
		$collection_metadata = array();
		if ( 'collections' === $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selector; no state mutation.
			$selected_collection = isset( $_GET['collection'] ) ? absint( wp_unslash( $_GET['collection'] ) ) : 0;
			if ( $selected_collection > 0 && isset( $collections[ $selected_collection ] ) ) {
				$collection_entry    = CollectionSettings::get( $selected_collection );
				$collection_metadata = ItemDetector::list_collection_metadata( $selected_collection );
			}
		}
		$diagnostics     = 'diagnostics' === $tab ? ( new Diagnostics( $manager ) )->run() : array();
		$providers_state = array();
		if ( in_array( $tab, array( 'ai', 'voice', 'wizard' ), true ) ) {
			foreach ( $manager->ai()->all() as $id => $p ) {
				$providers_state['ai'][ $id ] = $p->is_configured();
			}
			foreach ( $manager->tts()->all() as $id => $p ) {
				$providers_state['tts'][ $id ] = $p->is_configured();
			}
		}

		include TN_PLUGIN_DIR . 'includes/Admin/views/page.php';
	}
}
