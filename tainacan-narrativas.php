<?php
/**
 * Plugin Name:       Tainacan Narrativas
 * Plugin URI:        https://github.com/marcossigismundo/tainacan-narrativas
 * Description:       Transforma itens Tainacan em experiências narrativas em áudio: leitura documental dos metadados e documentos, narrativa opcional assistida por IA e síntese de voz, com player acessível na página pública do item.
 * Version:           1.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  tainacan
 * Author:            Marcos Sigismundo
 * Author URI:        https://github.com/marcossigismundo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tainacan-narrativas
 * Domain Path:       /languages
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TN_VERSION', '1.1.1' );
define( 'TN_PLUGIN_FILE', __FILE__ );
define( 'TN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * Only handles the TainacanNarrativas\ prefix and returns early for anything
 * else, so it never interferes with Tainacan core or other plugins.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'TainacanNarrativas\\';
		$base_dir = TN_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Third-party runtime dependencies (smalot/pdfparser) shipped inside vendor/.
if ( file_exists( TN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once TN_PLUGIN_DIR . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, array( 'TainacanNarrativas\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TainacanNarrativas\\Core\\Deactivator', 'deactivate' ) );

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'tainacan-narrativas', false, dirname( TN_PLUGIN_BASENAME ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\TainacanNarrativas\Core\Plugin::instance()->boot();
	},
	20
);
