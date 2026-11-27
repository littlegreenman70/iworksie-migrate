<?php
/**
 * The giving site's single active migration key.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Giver;

use IWorksIE\Migrate\Support\MigrationKey;

/**
 * Stores one key at a time in a non-autoloaded option.
 */
final class KeyStore {

	const OPTION     = 'iwiemg_giver_key';
	const SEQ_OPTION = 'iwiemg_giver_seq';

	/**
	 * Allowed lifetimes in seconds, keyed by form value.
	 *
	 * @return array<string,int>
	 */
	public static function lifetimes() {
		return array(
			'1h'  => HOUR_IN_SECONDS,
			'24h' => DAY_IN_SECONDS,
			'7d'  => 7 * DAY_IN_SECONDS,
		);
	}

	/**
	 * Create a new key, replacing any existing one, and return the key string.
	 *
	 * @param string $lifetime One of lifetimes() keys.
	 * @return string
	 */
	public static function create( $lifetime = '24h' ) {
		$lifetimes = self::lifetimes();
		$seconds   = isset( $lifetimes[ $lifetime ] ) ? $lifetimes[ $lifetime ] : DAY_IN_SECONDS;
		$id        = wp_generate_password( 16, false, false );
		$secret    = bin2hex( random_bytes( 32 ) );
		$now       = time();

		update_option(
			self::OPTION,
			array(
				'id'        => $id,
				'secret'    => $secret,
				'created'   => $now,
				'expires'   => $now + $seconds,
				'last_used' => 0,
			),
			false
		);
		delete_option( self::SEQ_OPTION );

		return MigrationKey::encode( self::rest_base(), home_url( '/' ), $id, $secret );
	}

	/**
	 * Delete the active key.
	 *
	 * @return void
	 */
	public static function revoke() {
		delete_option( self::OPTION );
		delete_option( self::SEQ_OPTION );
	}

	/**
	 * Active key record, or null when none / expired.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function active() {
		$key = get_option( self::OPTION, null );
		if ( ! is_array( $key ) || empty( $key['id'] ) || empty( $key['secret'] ) ) {
			return null;
		}
		if ( (int) $key['expires'] < time() ) {
			return null;
		}
		return $key;
	}

	/**
	 * Raw stored record even if expired (for the status screen).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function stored() {
		$key = get_option( self::OPTION, null );
		return is_array( $key ) && ! empty( $key['id'] ) ? $key : null;
	}

	/**
	 * Record a successful request (at most once a minute, to keep writes low).
	 *
	 * @param string $seq Sequence number just accepted.
	 * @return void
	 */
	public static function touch( $seq ) {
		update_option( self::SEQ_OPTION, (string) $seq, false );
		$key = self::stored();
		if ( null === $key ) {
			return;
		}
		if ( time() - (int) $key['last_used'] >= MINUTE_IN_SECONDS ) {
			$key['last_used'] = time();
			update_option( self::OPTION, $key, false );
		}
	}

	/**
	 * Last accepted sequence number.
	 *
	 * @return string
	 */
	public static function last_seq() {
		return (string) get_option( self::SEQ_OPTION, '0' );
	}

	/**
	 * REST base URL for our namespace on this site.
	 *
	 * @return string
	 */
	public static function rest_base() {
		return trailingslashit( rest_url( Api::REST_NAMESPACE ) );
	}
}
