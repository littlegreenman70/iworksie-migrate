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
	 * Atomically claim a sequence number if it is strictly greater than the last one.
	 *
	 * Uses a MySQL named lock so two concurrent signed requests cannot both pass
	 * against the same stored last-seq (compare-and-swap).
	 *
	 * @param string $seq Sequence number from the request.
	 * @return bool True when claimed; false on replay or lock failure.
	 */
	public static function claim_seq( $seq ) {
		global $wpdb;

		$seq  = (string) $seq;
		$lock = 'iwiemg_seq_' . (string) get_current_blog_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock for compare-and-swap.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		if ( '1' !== (string) $got ) {
			return false;
		}

		try {
			wp_cache_delete( self::SEQ_OPTION, 'options' );
			$last = self::last_seq();
			if ( ! MigrationKey::seq_greater( $seq, $last ) ) {
				return false;
			}
			update_option( self::SEQ_OPTION, $seq, false );
			$key = self::stored();
			if ( null !== $key && time() - (int) $key['last_used'] >= MINUTE_IN_SECONDS ) {
				$key['last_used'] = time();
				update_option( self::OPTION, $key, false );
			}
			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock release.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
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
