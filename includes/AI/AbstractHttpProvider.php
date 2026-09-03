<?php
/**
 * Shared HTTP/JSON plumbing for AI and TTS providers.
 *
 * @package TainacanNarrativas
 */

declare(strict_types=1);

namespace TainacanNarrativas\AI;

use TainacanNarrativas\Logging\Logger;
use TainacanNarrativas\Security\Security;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All requests go through Security::remote_request() (SSRF policy) and every
 * error path is a WP_Error with a user-facing message and no secrets.
 */
abstract class AbstractHttpProvider {

	/**
	 * POSTs JSON and decodes a JSON response.
	 *
	 * @param string               $url     URL.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @param int                  $timeout Timeout in seconds.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function post_json( string $url, array $body, array $headers, int $timeout ) {
		$response = Security::remote_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => max( 5, $timeout ),
				'headers' => array_merge(
					array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					$headers
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		return $this->decode( $response );
	}

	/**
	 * GETs JSON.
	 *
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 * @param int                  $timeout Timeout.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function get_json( string $url, array $headers = array(), int $timeout = 20 ) {
		$response = Security::remote_request(
			$url,
			array(
				'method'  => 'GET',
				'timeout' => max( 5, $timeout ),
				'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);
		return $this->decode( $response );
	}

	/**
	 * POSTs JSON and returns the raw binary body (audio).
	 *
	 * @param string               $url     URL.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @param int                  $timeout Timeout.
	 * @return array{body:string,content_type:string}|WP_Error
	 */
	protected function post_binary( string $url, array $body, array $headers, int $timeout ) {
		$response = Security::remote_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => max( 5, $timeout ),
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->wrap_transport_error( $response );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 400 ) {
			return $this->http_error( $code, $raw );
		}
		return array(
			'body'         => $raw,
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}

	/**
	 * Turns a wp_remote_* response into decoded JSON or WP_Error.
	 *
	 * @param array<string,mixed>|WP_Error $response Response.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $this->wrap_transport_error( $response );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 400 ) {
			return $this->http_error( $code, $raw );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tn_ai_invalid_json', __( 'Resposta inválida do serviço (JSON malformado).', 'tainacan-narrativas' ), array( 'status' => $code ) );
		}
		return $data;
	}

	/**
	 * Maps an HTTP error to a WP_Error with a concise message.
	 *
	 * @param int    $code HTTP status.
	 * @param string $raw  Body.
	 * @return WP_Error
	 */
	protected function http_error( int $code, string $raw ): WP_Error {
		$data    = json_decode( $raw, true );
		$message = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			} elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( isset( $data['detail'] ) && is_string( $data['detail'] ) ) {
				$message = $data['detail'];
			}
		}
		if ( '' === $message ) {
			$message = wp_strip_all_tags( mb_substr( $raw, 0, 200 ) );
		}
		$message = Logger::redact( $message );
		Logger::warning(
			'Provider HTTP error',
			array(
				'status'  => $code,
				'message' => $message,
			)
		);

		$retryable = in_array( $code, array( 408, 409, 425, 429, 500, 502, 503, 504 ), true );
		return new WP_Error(
			$retryable ? 'tn_ai_retryable' : 'tn_ai_http',
			/* translators: 1: HTTP status code, 2: error message from the service. */
			sprintf( __( 'Serviço respondeu HTTP %1$d: %2$s', 'tainacan-narrativas' ), $code, $message ),
			array( 'status' => $code )
		);
	}

	/**
	 * Transport errors (timeouts, DNS, SSRF policy) are retryable except policy ones.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	protected function wrap_transport_error( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();
		if ( str_starts_with( $code, 'tn_endpoint' ) ) {
			return $error;
		}
		Logger::warning(
			'Provider transport error',
			array(
				'code'    => $code,
				'message' => $error->get_error_message(),
			)
		);
		return new WP_Error( 'tn_ai_retryable', Logger::redact( $error->get_error_message() ) );
	}

	/**
	 * Whether a URL points outside the server's private networks.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected function url_is_external( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host && ! Security::host_is_private( $host );
	}
}
