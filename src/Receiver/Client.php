<?php
/**
 * Signed HTTP client for talking to the giving site.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Receiver;

use IWorksIE\Migrate\Support\MigrationKey;

/**
 * Sends signed JSON POSTs to the giving site's REST endpoints. Not final so tests can route calls in-process.
 */
class Client {

	/**
	 * @var array{u: string, h: string, i: string, s: string}
	 */
	private $key;

	/**
	 * @param array{u: string, h: string, i: string, s: string} $key Decoded migration key.
	 */
	public function __construct( array $key ) {
		$this->key = $key;
	}

	/**
	 * Giving site home URL from the key.
	 *
	 * @return string
	 */
	public function giver_home() {
		return $this->key['h'];
	}

	/**
	 * Giving site REST base from the key.
	 *
	 * @return string
	 */
	public function giver_rest() {
		return $this->key['u'];
	}

	/**
	 * Call an endpoint.
	 *
	 * Returns the decoded JSON on success, or a WP_Error whose code is either the
	 * giving site's error code (e.g. iwiemg_unknown_key) or one of:
	 * iwiemg_http (network), iwiemg_redirect, iwiemg_no_route, iwiemg_bad_response.
	 *
	 * @param string              $route   Endpoint name.
	 * @param array<string,mixed> $payload Body.
	 * @param int                 $timeout Seconds.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function call( $route, array $payload, $timeout = 20 ) {
		$body = (string) wp_json_encode( $payload );
		$time = (string) time();
		$seq  = MigrationKey::next_seq();
		$url  = $this->key['u'] . $route;

		/**
		 * Whether to verify the giving site's TLS certificate. Turn off only for
		 * local development sites with self-signed certificates.
		 *
		 * @param bool   $verify Default true.
		 * @param string $url    Request URL.
		 */
		$sslverify = (bool) apply_filters( 'iwiemg_sslverify', true, $url );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => max( 3, (int) $timeout ),
				'redirection' => 0,
				'sslverify'   => $sslverify,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'Accept'             => 'application/json',
					'X-Iwiemg-Key'       => $this->key['i'],
					'X-Iwiemg-Time'      => $time,
					'X-Iwiemg-Seq'       => $seq,
					'X-Iwiemg-Signature' => MigrationKey::sign( $this->key['s'], $route, $time, $seq, $body ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'iwiemg_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code >= 300 && $code < 400 ) {
			return new \WP_Error( 'iwiemg_redirect', (string) wp_remote_retrieve_header( $response, 'location' ), array( 'status' => $code ) );
		}
		if ( $code >= 400 ) {
			if ( is_array( $json ) && isset( $json['code'] ) && is_string( $json['code'] ) ) {
				$err_code = 'rest_no_route' === $json['code'] ? 'iwiemg_no_route' : $json['code'];
				$data     = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
				return new \WP_Error( $err_code, isset( $json['message'] ) ? (string) $json['message'] : '', array_merge( $data, array( 'status' => $code ) ) );
			}
			return new \WP_Error( 404 === $code ? 'iwiemg_no_route' : 'iwiemg_http', 'HTTP ' . $code, array( 'status' => $code ) );
		}
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'iwiemg_bad_response', 'The other site did not return JSON. A security plugin, firewall or maintenance page may be in the way.', array( 'status' => $code ) );
		}
		return $json;
	}

	/**
	 * Whether an error is worth retrying (network blips, overloaded server, replayed sequence).
	 *
	 * @param \WP_Error $error Error.
	 * @return bool
	 */
	public static function is_transient( \WP_Error $error ) {
		$code = (string) $error->get_error_code();
		if ( 'iwiemg_replay' === $code ) {
			return true;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 0 === $status && 'iwiemg_http' === $code ) {
			return true;
		}
		return 408 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Plain-English explanation of an error for screens and reports.
	 *
	 * @param \WP_Error $error Error.
	 * @return array{0: string, 1: string} Description and how to fix.
	 */
	public static function explain( \WP_Error $error ) {
		$code = (string) $error->get_error_code();
		$msg  = (string) $error->get_error_message();
		switch ( $code ) {
			case 'iwiemg_http':
				return array(
					/* translators: %s: technical error message */
					sprintf( __( 'This site could not reach the other site (%s).', 'iworksie-migrate' ), $msg ),
					__( 'Check the other site is online and that this server can make outgoing HTTPS requests. If the other site is a local development site with a self-signed certificate, see the FAQ about the iwiemg_sslverify filter.', 'iworksie-migrate' ),
				);
			case 'iwiemg_redirect':
				return array(
					/* translators: %s: redirect target URL */
					sprintf( __( 'The other site redirected the request to %s.', 'iworksie-migrate' ), '' !== $msg ? $msg : __( 'another address', 'iworksie-migrate' ) ),
					__( 'This usually means its WordPress Address setting does not match the address it is really served from (for example http vs https, or www vs no www). Fix the address in Settings > General on the other site, then create a new key.', 'iworksie-migrate' ),
				);
			case 'iwiemg_no_route':
				return array(
					__( 'The other site answered, but iWorks.ie Migrate is not active there, or its REST API is blocked.', 'iworksie-migrate' ),
					__( 'Install and activate iWorks.ie Migrate on the other site. If it is already active, check for a security plugin that disables the WordPress REST API for visitors and allow the iworksie-migrate route.', 'iworksie-migrate' ),
				);
			case 'iwiemg_not_enabled':
			case 'iwiemg_unknown_key':
				return array(
					__( 'The other site no longer accepts this key. It has expired, been revoked or been replaced by a newer key.', 'iworksie-migrate' ),
					__( 'On the other site go to iWorks Tools > Migrate > Let another site copy this one, create a new key and paste it here.', 'iworksie-migrate' ),
				);
			case 'iwiemg_bad_signature':
				return array(
					__( 'The other site rejected the key\'s signature. The key may have been copied incompletely.', 'iworksie-migrate' ),
					__( 'Copy the whole key again with the Copy button on the other site and paste it here.', 'iworksie-migrate' ),
				);
			case 'iwiemg_clock_skew':
				return array(
					__( 'The clocks on the two servers are more than 15 minutes apart, so requests are refused for safety.', 'iworksie-migrate' ),
					__( 'Ask your host to correct the server time (NTP) on whichever server is wrong.', 'iworksie-migrate' ),
				);
			case 'iwiemg_bad_response':
				return array(
					__( 'The other site sent back something that was not a valid answer.', 'iworksie-migrate' ),
					__( 'A firewall, CDN challenge page, maintenance mode or security plugin on the other site may be intercepting requests. Allow requests to /wp-json/iworksie-migrate/ and try again.', 'iworksie-migrate' ),
				);
			default:
				return array(
					/* translators: 1: error code, 2: error message */
					sprintf( __( 'The other site returned an error: %1$s %2$s', 'iworksie-migrate' ), $code, $msg ),
					__( 'Try again in a few minutes. If it keeps happening, run iWorks.ie Health Check on both sites.', 'iworksie-migrate' ),
				);
		}
	}
}
