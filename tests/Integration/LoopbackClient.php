<?php
/**
 * Client that dispatches signed requests to this WordPress's own REST server.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Integration;

use IWorksIE\Migrate\Receiver\Client;
use IWorksIE\Migrate\Support\MigrationKey;

/**
 * Same signing as the real client, no HTTP.
 */
class LoopbackClient extends Client {

	/**
	 * @var array<string,mixed>
	 */
	private $raw_key;

	/**
	 * @var int Fail this many calls with a network error first.
	 */
	public $fail_next = 0;

	/**
	 * @var array<string,int> Calls per route.
	 */
	public $calls = array();

	/**
	 * @var bool Tamper with the signature.
	 */
	public $bad_signature = false;

	/**
	 * @var string|null Reuse this sequence number (replay).
	 */
	public $fixed_seq = null;

	/**
	 * @param array{u: string, h: string, i: string, s: string} $key Key.
	 */
	public function __construct( array $key ) {
		parent::__construct( $key );
		$this->raw_key = $key;
	}

	/**
	 * @param string              $route   Route.
	 * @param array<string,mixed> $payload Payload.
	 * @param int                 $timeout Unused.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function call( $route, array $payload, $timeout = 20 ) {
		$this->calls[ $route ] = ( isset( $this->calls[ $route ] ) ? $this->calls[ $route ] : 0 ) + 1;
		if ( $this->fail_next > 0 ) {
			--$this->fail_next;
			return new \WP_Error( 'iwiemg_http', 'cURL error 28: simulated timeout' );
		}
		$body = (string) wp_json_encode( $payload );
		$time = (string) time();
		$seq  = null === $this->fixed_seq ? MigrationKey::next_seq() : $this->fixed_seq;
		usleep( 2 );
		$sig = MigrationKey::sign( $this->raw_key['s'], $route, $time, $seq, $body );
		if ( $this->bad_signature ) {
			$sig = str_repeat( '0', 64 );
		}
		$request = new \WP_REST_Request( 'POST', '/iworksie-migrate/v1/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Iwiemg-Key', $this->raw_key['i'] );
		$request->set_header( 'X-Iwiemg-Time', $time );
		$request->set_header( 'X-Iwiemg-Seq', $seq );
		$request->set_header( 'X-Iwiemg-Signature', $sig );
		$request->set_body( $body );
		$response = rest_get_server()->dispatch( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		$data     = json_decode( (string) wp_json_encode( $data ), true );
		if ( $response->get_status() >= 400 ) {
			$code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'iwiemg_http';
			return new \WP_Error( $code, is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '', array( 'status' => $response->get_status() ) );
		}
		return is_array( $data ) ? $data : new \WP_Error( 'iwiemg_bad_response', 'not json' );
	}
}
