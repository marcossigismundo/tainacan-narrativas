<?php
/**
 * Security helpers: SSRF-safe HTTP, endpoint validation, path safety.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\Security;

use TainacanNarrativas\Core\Options;
use TainacanNarrativas\Logging\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes outbound HTTP so every provider goes through the same checks.
 *
 * Default path: wp_safe_remote_request() (WordPress rejects loopback/private
 * IPs, odd ports and unsafe redirects). Ollama, Kokoro and Piper legitimately
 * live on private networks, so an explicit, off-by-default setting
 * ("allow_private_endpoints", manage capability only) switches to
 * wp_remote_request() — but only after our own validation and with redirects
 * disabled, so a private endpoint cannot bounce the request elsewhere.
 */
final class Security {

	/**
	 * Validates an administrator-configured endpoint URL.
	 *
	 * @param string    $url           URL to validate.
	 * @param bool|null $allow_private Override for the setting (null = read setting).
	 * @return true|WP_Error
	 */
	public static function validate_endpoint( string $url, ?bool $allow_private = null ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new WP_Error( 'tn_endpoint_empty', __( 'Endpoint não configurado.', 'tainacan-narrativas' ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'tn_endpoint_invalid', __( 'Endpoint inválido.', 'tainacan-narrativas' ) );
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'tn_endpoint_scheme', __( 'Somente http:// e https:// são permitidos.', 'tainacan-narrativas' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'tn_endpoint_userinfo', __( 'Credenciais na URL não são permitidas.', 'tainacan-narrativas' ) );
		}
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( $port < 1 || $port > 65535 || ( $port < 1024 && ! in_array( $port, array( 80, 443 ), true ) ) ) {
				return new WP_Error( 'tn_endpoint_port', __( 'Porta não permitida.', 'tainacan-narrativas' ) );
			}
		}

		$allow_private = null === $allow_private ? Options::is( 'allow_private_endpoints' ) : $allow_private;
		if ( ! $allow_private && self::host_is_private( $parts['host'] ) ) {
			return new WP_Error(
				'tn_endpoint_private',
				__( 'O endpoint aponta para uma rede privada/local. Habilite "Permitir endpoints de rede privada" nas configurações se isso for intencional.', 'tainacan-narrativas' )
			);
		}
		return true;
	}

	/**
	 * Whether a host name/IP resolves to a loopback, private or link-local address.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function host_is_private( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return true;
		}
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$resolved = gethostbyname( $host );
			if ( $resolved !== $host ) {
				$ips[] = $resolved;
			}
		}
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Performs an outbound HTTP request with SSRF protections.
	 *
	 * @param string              $url  URL (already configured by an administrator).
	 * @param array<string,mixed> $args wp_remote_request args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function remote_request( string $url, array $args = array() ) {
		$valid = self::validate_endpoint( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'user-agent'  => 'TainacanNarrativas/' . TN_VERSION . '; ' . home_url( '/' ),
			)
		);

		$started = microtime( true );
		if ( Options::is( 'allow_private_endpoints' ) ) {
			$args['reject_unsafe_urls'] = false;
			$response                   = wp_remote_request( $url, $args );
		} else {
			$response = wp_safe_remote_request( $url, $args );
		}

		Logger::debug(
			'HTTP request',
			array(
				'url'      => self::redact_url( $url ),
				'method'   => $args['method'] ?? 'GET',
				'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'is_error' => is_wp_error( $response ),
				'status'   => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
			)
		);

		return $response;
	}

	/**
	 * Removes query-string secrets from a URL for logging.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function redact_url( string $url ): string {
		return Logger::redact( $url );
	}

	/**
	 * Joins a base URL with a path without producing double slashes.
	 *
	 * @param string $base Base URL.
	 * @param string $path Path starting with "/".
	 * @return string
	 */
	public static function join_url( string $base, string $path ): string {
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Whether a file path lives inside the uploads directory (anti path traversal).
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function path_is_in_uploads( string $path ): bool {
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$base    = wp_normalize_path( false === $base ? $uploads['basedir'] : $base );
		$real    = realpath( $path );
		if ( false === $real ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		return str_starts_with( $real, rtrim( $base, '/' ) . '/' );
	}
}
