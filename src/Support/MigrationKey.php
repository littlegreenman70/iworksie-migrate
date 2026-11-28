<?php
/**
 * Migration key encoding and request signing.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Support;

/**
 * A migration key is "iwiemg1." + base64url(JSON) holding the giving site's
 * REST base URL, a key id and a shared secret. The secret never travels in a
 * request: every request carries an HMAC-SHA256 signature instead.
 */
final class MigrationKey {

	/**
	 * Wire protocol version.
	 *
	 * @var int
	 */
	const PROTOCOL = 1;

	/**
	 * Key string prefix.
	 *
	 * @var string
	 */
	const PREFIX = 'iwiemg1.';

	/**
	 * Allowed clock difference between the two sites, in seconds.
	 *
	 * @var int
	 */
	const MAX_SKEW = 900;

	/**
	 * Encode a key for display.
	 *
	 * @param string $rest_base Giving site REST base for our namespace (ends with "/").
	 * @param string $home      Giving site home URL (display only).
	 * @param string $id        Key id.
	 * @param string $secret    Shared secret.
	 * @return string
	 */
	public static function encode( $rest_base, $home, $id, $secret ) {
		$json = (string) wp_json_encode(
			array(
				'u' => (string) $rest_base,
				'h' => (string) $home,
				'i' => (string) $id,
				's' => (string) $secret,
			)
		);
		return self::PREFIX . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for the key string.
	}

	/**
	 * Decode a pasted key. Whitespace (from copy/paste line wraps) is ignored.
	 *
	 * @param string $key Pasted key.
	 * @return array{u: string, h: string, i: string, s: string}|null
	 */
	public static function decode( $key ) {
		$key = preg_replace( '/\s+/', '', (string) $key );
		if ( ! is_string( $key ) || 0 !== strpos( $key, self::PREFIX ) || strlen( $key ) > 4096 ) {
			return null;
		}
		$b64 = strtr( substr( $key, strlen( self::PREFIX ) ), '-_', '+/' );
		$raw = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport encoding for the key string.
		if ( false === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'u', 'h', 'i', 's' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === $data[ $field ] ) {
				return null;
			}
		}
		$scheme = wp_parse_url( $data['u'], PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{8,64}$/', $data['i'] ) || strlen( $data['s'] ) < 32 ) {
			return null;
		}
		return array(
			'u' => $data['u'],
			'h' => $data['h'],
			'i' => $data['i'],
			's' => $data['s'],
		);
	}

	/**
	 * String that is signed for one request.
	 *
	 * @param string $route Endpoint name, e.g. "chunk".
	 * @param string $time  Unix time as a string.
	 * @param string $seq   Increasing sequence number (digits).
	 * @param string $body  Raw request body.
	 * @return string
	 */
	public static function canonical( $route, $time, $seq, $body ) {
		return 'iwiemg' . self::PROTOCOL . "\n" . $route . "\n" . $time . "\n" . $seq . "\n" . hash( 'sha256', (string) $body );
	}

	/**
	 * HMAC signature.
	 *
	 * @param string $secret Shared secret.
	 * @param string $route  Endpoint name.
	 * @param string $time   Unix time.
	 * @param string $seq    Sequence number.
	 * @param string $body   Raw body.
	 * @return string
	 */
	public static function sign( $secret, $route, $time, $seq, $body ) {
		return hash_hmac( 'sha256', self::canonical( $route, $time, $seq, $body ), (string) $secret );
	}

	/**
	 * Whether digit string $a is greater than digit string $b (works beyond PHP_INT_MAX on 32-bit).
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 * @return bool
	 */
	public static function seq_greater( $a, $b ) {
		$a = ltrim( (string) $a, '0' );
		$b = ltrim( (string) $b, '0' );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) > strlen( $b );
		}
		return strcmp( $a, $b ) > 0;
	}

	/**
	 * A new sequence number: microseconds since the epoch, as digits.
	 *
	 * @return string
	 */
	public static function next_seq() {
		return sprintf( '%.0f', floor( microtime( true ) * 1000000 ) );
	}
}
