<?php
/**
 * PHPUnit bootstrap: loads the plugin classes with minimal WordPress stubs so
 * pure units (normalization, hashing, chunking, extractors, security checks)
 * run without a WordPress installation.
 *
 * Integration against a real site lives in tests/integration/run.php.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'TN_VERSION', '1.0.0-test' );
define( 'TN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'TN_PLUGIN_URL', 'http://example.test/wp-content/plugins/tainacan-narrativas/' );
define( 'TN_PLUGIN_FILE', TN_PLUGIN_DIR . 'tainacan-narrativas.php' );
define( 'TN_PLUGIN_BASENAME', 'tainacan-narrativas/tainacan-narrativas.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Minimal WordPress stubs (only what the pure units touch).
// ---------------------------------------------------------------------------

$GLOBALS['tn_test_options'] = array();
$GLOBALS['tn_test_filters'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Tiny WP_Error stand-in.
	 */
	class WP_Error {
		/**
		 * Code.
		 *
		 * @var string
		 */
		public $code;
		/**
		 * Message.
		 *
		 * @var string
		 */
		public $message;
		/**
		 * Data.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; } // phpcs:ignore
		public function get_error_message() { return $this->message; } // phpcs:ignore
		public function get_error_data() { return $this->data; } // phpcs:ignore
		public function add_data( $data ) { $this->data = $data; } // phpcs:ignore
	}
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; } // phpcs:ignore
function __( $text, $domain = 'default' ) { return $text; } // phpcs:ignore
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; } // phpcs:ignore
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } // phpcs:ignore
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } // phpcs:ignore
function esc_url_raw( $url ) { return trim( (string) $url ); } // phpcs:ignore
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } // phpcs:ignore
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); } // phpcs:ignore
function absint( $v ) { return abs( (int) $v ); } // phpcs:ignore
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); } // phpcs:ignore
function sanitize_text_field( $str ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ); } // phpcs:ignore
function sanitize_textarea_field( $str ) { return trim( strip_tags( (string) $str ) ); } // phpcs:ignore
function wp_strip_all_tags( $text, $remove_breaks = false ) { // phpcs:ignore
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	return trim( $text );
}
function get_option( $name, $default = false ) { return $GLOBALS['tn_test_options'][ $name ] ?? $default; } // phpcs:ignore
function update_option( $name, $value, $autoload = null ) { $GLOBALS['tn_test_options'][ $name ] = $value; return true; } // phpcs:ignore
function delete_option( $name ) { unset( $GLOBALS['tn_test_options'][ $name ] ); return true; } // phpcs:ignore
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['tn_test_filters'][ $tag ][] = $cb; return true; } // phpcs:ignore
function apply_filters( $tag, $value, ...$args ) { // phpcs:ignore
	foreach ( $GLOBALS['tn_test_filters'][ $tag ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value, ...$args );
	}
	return $value;
}
function do_action( $tag, ...$args ) {} // phpcs:ignore
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } // phpcs:ignore
function home_url( $path = '' ) { return 'http://example.test' . $path; } // phpcs:ignore
function wp_get_upload_dir() { return array( 'basedir' => sys_get_temp_dir(), 'baseurl' => 'http://example.test/uploads', 'error' => false ); } // phpcs:ignore
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); } // phpcs:ignore
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); } // phpcs:ignore
