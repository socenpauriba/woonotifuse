<?php
/**
 * Notifuse REST/RPC API client.
 *
 * @package WooNotifuse
 */

namespace WooNotifuse\Api;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Notifuse API.
 *
 * The API is RPC-over-HTTP: endpoints look like `api/contacts.upsert` and are
 * called with POST (JSON body) or GET (query string). Every request carries a
 * Bearer token; most also need a `workspace_id`.
 *
 * All public methods return the decoded response array on success, or a
 * WP_Error on transport failure, a non-2xx status, or an API `{error}` body.
 */
class Client {

	/**
	 * Notifuse domain, e.g. "demo.notifuse.com".
	 *
	 * @var string
	 */
	private $domain;

	/**
	 * Bearer API token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Workspace ID injected into requests that need it.
	 *
	 * @var string
	 */
	private $workspace_id;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 15;

	/**
	 * Constructor.
	 *
	 * @param string $domain       Notifuse domain (with or without scheme).
	 * @param string $token        Bearer API token.
	 * @param string $workspace_id Workspace ID.
	 */
	public function __construct( $domain, $token, $workspace_id = '' ) {
		$this->domain       = $this->normalize_domain( $domain );
		$this->token        = trim( (string) $token );
		$this->workspace_id = trim( (string) $workspace_id );
	}

	/**
	 * Whether the client has the minimum config to make authenticated calls.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->domain && '' !== $this->token;
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string $endpoint Endpoint path, e.g. "api/contacts.count".
	 * @param array  $query    Query-string args (workspace_id added automatically).
	 * @return array|WP_Error
	 */
	public function get( $endpoint, array $query = array() ) {
		$query = $this->with_workspace( $query );

		return $this->request( 'GET', $endpoint, null, $query );
	}

	/**
	 * Perform a POST request with a JSON body.
	 *
	 * @param string $endpoint Endpoint path, e.g. "api/contacts.upsert".
	 * @param array  $body     Request body (workspace_id added automatically).
	 * @return array|WP_Error
	 */
	public function post( $endpoint, array $body = array() ) {
		$body = $this->with_workspace( $body );

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Lightweight authenticated call used to validate credentials.
	 *
	 * Hits contacts.count, which requires a valid token + workspace.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		if ( '' === $this->workspace_id ) {
			return new WP_Error(
				'woonotifuse_missing_workspace',
				__( 'A workspace ID is required to test the connection.', 'woonotifuse' )
			);
		}

		return $this->get( 'api/contacts.count' );
	}

	/**
	 * Core request dispatcher.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Endpoint path.
	 * @param array|null $body     Body for JSON encoding (POST), or null.
	 * @param array      $query    Query-string args.
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, $body = null, array $query = array() ) {
		if ( '' === $this->domain ) {
			return new WP_Error( 'woonotifuse_no_domain', __( 'Notifuse domain is not configured.', 'woonotifuse' ) );
		}

		if ( '' === $this->token ) {
			return new WP_Error( 'woonotifuse_no_token', __( 'Notifuse API token is not configured.', 'woonotifuse' ) );
		}

		$url = 'https://' . $this->domain . '/' . ltrim( $endpoint, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( $response );
	}

	/**
	 * Turn a wp_remote_* response into a decoded array or WP_Error.
	 *
	 * @param array $response Raw response from wp_remote_request().
	 * @return array|WP_Error
	 */
	private function parse_response( $response ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			// Empty body is a valid success for some endpoints.
			return is_array( $data ) ? $data : array();
		}

		// Notifuse errors come back as { "error": "message" }.
		$message = '';
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$message = (string) $data['error'];
		}

		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Notifuse API request failed with status %d.', 'woonotifuse' ),
				$status
			);
		}

		return new WP_Error(
			'woonotifuse_api_error',
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * Merge the workspace ID into a payload unless already present.
	 *
	 * @param array $payload Body or query args.
	 * @return array
	 */
	private function with_workspace( array $payload ) {
		if ( '' !== $this->workspace_id && ! isset( $payload['workspace_id'] ) ) {
			$payload['workspace_id'] = $this->workspace_id;
		}

		return $payload;
	}

	/**
	 * Strip scheme, path and trailing slashes from a configured domain.
	 *
	 * Accepts "https://demo.notifuse.com/", "demo.notifuse.com", etc.
	 *
	 * @param string $domain Raw domain value.
	 * @return string Bare host, or empty string.
	 */
	private function normalize_domain( $domain ) {
		$domain = trim( (string) $domain );

		if ( '' === $domain ) {
			return '';
		}

		// If a scheme is present, extract just the host.
		if ( false !== strpos( $domain, '://' ) ) {
			$host = wp_parse_url( $domain, PHP_URL_HOST );
			if ( $host ) {
				return $host;
			}
		}

		// Otherwise drop any path/query and trailing slash.
		$domain = preg_replace( '#/.*$#', '', $domain );

		return rtrim( $domain, '/' );
	}
}
