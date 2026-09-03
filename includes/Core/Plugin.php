<?php
/**
 * Plugin bootstrap / service wiring.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Core;

use TainacanNarrativas\Admin\AdminPage;
use TainacanNarrativas\Admin\SettingsHandler;
use TainacanNarrativas\CLI\Command;
use TainacanNarrativas\Database\Tables;
use TainacanNarrativas\Frontend\Assets;
use TainacanNarrativas\Frontend\Block;
use TainacanNarrativas\Frontend\Player;
use TainacanNarrativas\Frontend\Shortcode;
use TainacanNarrativas\Narrative\NarrativeManager;
use TainacanNarrativas\Queue\QueueManager;
use TainacanNarrativas\REST\Controller;
use TainacanNarrativas\Tainacan\ChangeListener;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton. The constructor does nothing; boot() registers `init` and the
 * real wiring happens there, only when Tainacan is confirmed active.
 */
final class Plugin {

	public const ADMIN_PAGE_SLUG = 'tainacan_narrativas';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Manager.
	 *
	 * @var NarrativeManager|null
	 */
	private ?NarrativeManager $manager = null;

	/**
	 * Queue.
	 *
	 * @var QueueManager|null
	 */
	private ?QueueManager $queue = null;

	/**
	 * Player.
	 *
	 * @var Player|null
	 */
	private ?Player $player = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Schedules wiring.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'cron_schedules', array( self::class, 'register_cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval,WordPress.WP.CronInterval.ChangeDetected -- 60 s interval is required for a responsive job queue; each run is bounded by a time budget, a lock and a small batch, and it can be disabled in the settings.
		add_action( 'init', array( $this, 'init_plugin' ), 11 );
		add_filter( 'plugin_action_links_' . TN_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Adds the one-minute schedule.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cron_schedules( array $schedules ): array {
		$schedules['tn_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS, // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 60 s interval is required for a responsive job queue; each run is bounded by a time budget, a lock and a small batch, and it can be disabled in the settings.
			'display'  => __( 'A cada minuto (Tainacan Narrativas)', 'tainacan-narrativas' ),
		);
		return $schedules;
	}

	/**
	 * Wires all services.
	 *
	 * @return void
	 */
	public function init_plugin(): void {
		if ( ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_tainacan' ) );
			return;
		}
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			Tables::maybe_upgrade();
		}

		$this->manager = new NarrativeManager();
		$this->queue   = new QueueManager( $this->manager );
		$this->queue->register();
		( new ChangeListener( $this->queue, $this->manager ) )->register();

		$api = new Controller( $this->manager, $this->queue );
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );

		$assets       = new Assets();
		$this->player = new Player( $this->manager, $assets );
		$this->player->register_hooks();
		( new Shortcode( $this->player ) )->register();
		( new Block( $this->player ) )->register();

		if ( is_admin() ) {
			( new SettingsHandler() )->register();
			if ( class_exists( '\Tainacan\Pages' ) ) {
				AdminPage::get_instance();
			}
			add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'tainacan-narrativas', new Command( $this->manager, $this->queue ) );
		}
	}

	/**
	 * Manager accessor.
	 *
	 * @return NarrativeManager|null
	 */
	public function manager(): ?NarrativeManager {
		return $this->manager;
	}

	/**
	 * Queue accessor.
	 *
	 * @return QueueManager|null
	 */
	public function queue(): ?QueueManager {
		return $this->queue;
	}

	/**
	 * Player accessor.
	 *
	 * @return Player|null
	 */
	public function player(): ?Player {
		return $this->player;
	}

	/**
	 * Admin URL of the plugin page.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	public static function admin_url( string $tab = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::ADMIN_PAGE_SLUG );
		return '' !== $tab ? $url . '&tab=' . rawurlencode( $tab ) : $url;
	}

	/**
	 * Redirects to the wizard right after activation (single request).
	 *
	 * @return void
	 */
	public function maybe_activation_redirect(): void {
		if ( ! get_transient( 'tn_activation_redirect' ) || wp_doing_ajax() || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		delete_transient( 'tn_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the bulk-activation flag; no state mutation.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}
		wp_safe_redirect( self::admin_url( Options::is( 'setup_done' ) ? '' : 'wizard' ) );
		exit;
	}

	/**
	 * Settings link in the plugins list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::admin_url() ) . '">' . esc_html__( 'Narrativas', 'tainacan-narrativas' ) . '</a>' );
		return $links;
	}

	/**
	 * Missing Tainacan notice.
	 *
	 * @return void
	 */
	public function notice_missing_tainacan(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Tainacan Narrativas requer o plugin Tainacan ativo.', 'tainacan-narrativas' ) . '</p></div>';
	}
}
