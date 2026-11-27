<?php
/**
 * Pre-flight checks on both sites before copying.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Receiver;

use IWorksIE\Migrate\Support\MigrationKey;
use IWorksIE\Migrate\Support\Paths;
use IWorksIE\Migrate\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Validates a pasted key, contacts the giving site and checks this site can receive.
 */
final class Preflight {

	/**
	 * Run all checks.
	 *
	 * @param string      $key_string Pasted key.
	 * @param Client|null $client     Client override for tests.
	 * @return array{ok: bool, results: array<int,Result>, giver: array<string,mixed>}
	 */
	public static function run( $key_string, $client = null ) {
		$results = array();
		$giver   = array();
		$key     = MigrationKey::decode( $key_string );

		if ( null === $key && null === $client ) {
			$results[] = Result::critical(
				__( 'Migration key', 'iworksie-migrate' ),
				__( 'That does not look like an iWorks.ie Migrate key.', 'iworksie-migrate' ),
				array(
					'fix' => __( 'On the site you are copying from, go to iWorks Tools > Migrate > Let another site copy this one, create a key, press Copy and paste it here. Keys start with "iwiemg1."', 'iworksie-migrate' ),
				)
			);
			return array(
				'ok'      => false,
				'results' => $results,
				'giver'   => $giver,
			);
		}
		if ( null === $client && null !== $key ) {
			$client = new Client( $key );
		}

		$hello = null === $client ? new \WP_Error( 'iwiemg_http', 'no client' ) : $client->call( 'hello', array(), 15 );
		if ( is_wp_error( $hello ) ) {
			$explained = Client::explain( $hello );
			$results[] = Result::critical(
				__( 'Connection to the other site', 'iworksie-migrate' ),
				$explained[0],
				array( 'fix' => $explained[1] )
			);
		} else {
			$giver     = $hello;
			$results[] = Result::good(
				__( 'Connection to the other site', 'iworksie-migrate' ),
				sprintf(
					/* translators: 1: site name, 2: site address */
					__( 'Connected to %1$s (%2$s) and the key was accepted.', 'iworksie-migrate' ),
					isset( $hello['name'] ) ? (string) $hello['name'] : '',
					isset( $hello['home'] ) ? (string) $hello['home'] : ''
				)
			);

			if ( ! isset( $hello['protocol'] ) || MigrationKey::PROTOCOL !== (int) $hello['protocol'] ) {
				$results[] = Result::critical(
					__( 'Plugin versions', 'iworksie-migrate' ),
					__( 'The two sites run versions of iWorks.ie Migrate that cannot talk to each other.', 'iworksie-migrate' ),
					array( 'fix' => __( 'Update iWorks.ie Migrate on both sites to the latest version.', 'iworksie-migrate' ) )
				);
			}

			$giver_home = isset( $hello['home'] ) ? untrailingslashit( (string) $hello['home'] ) : '';
			if ( '' !== $giver_home && self::same_site( $giver_home, home_url() ) ) {
				$results[] = Result::critical(
					__( 'Same site', 'iworksie-migrate' ),
					__( 'This key belongs to this same site. Copying a site onto itself would do nothing useful.', 'iworksie-migrate' ),
					array( 'fix' => __( 'Create the key on the site you want to copy from, and paste it into the site you want to copy to.', 'iworksie-migrate' ) )
				);
			}

			if ( empty( $hello['uploads_readable'] ) ) {
				$results[] = Result::critical(
					__( 'Uploads folder on the other site', 'iworksie-migrate' ),
					__( 'The other site cannot read its own uploads folder.', 'iworksie-migrate' ),
					array( 'fix' => __( 'Ask the other site\'s host to check the permissions of wp-content/uploads.', 'iworksie-migrate' ) )
				);
			}

			if ( 'https' !== wp_parse_url( $client->giver_rest(), PHP_URL_SCHEME ) ) {
				$results[] = Result::recommended(
					__( 'Encryption', 'iworksie-migrate' ),
					__( 'The other site uses http, not https, so files travel unencrypted.', 'iworksie-migrate' ),
					array(
						'why' => __( 'Anyone on the network path could read the files as they are copied. The key itself is never sent, so they cannot reuse it.', 'iworksie-migrate' ),
						'fix' => __( 'Fine for a local or private network. For public sites, enable HTTPS on the other site and create a new key.', 'iworksie-migrate' ),
					)
				);
			}
		}

		$base = Paths::uploads_base();
		if ( '' === $base || ! wp_is_writable( $base ) ) {
			$results[] = Result::critical(
				__( 'Uploads folder on this site', 'iworksie-migrate' ),
				__( 'This site\'s uploads folder is missing or not writable.', 'iworksie-migrate' ),
				array( 'fix' => __( 'Ask your host to make wp-content/uploads writable by the web server.', 'iworksie-migrate' ) )
			);
		} else {
			$results[] = Result::good(
				__( 'Uploads folder on this site', 'iworksie-migrate' ),
				__( 'This site\'s uploads folder is writable.', 'iworksie-migrate' )
			);
			$free      = function_exists( 'disk_free_space' ) ? @disk_free_space( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$results[] = Result::info(
				__( 'Disk space on this site', 'iworksie-migrate' ),
				false === $free
					? __( 'This server does not report free disk space. The copy will stop cleanly if the disk fills up.', 'iworksie-migrate' )
					/* translators: %s: free space */
					: sprintf( __( '%s free. The exact space needed is checked after the files are listed, before anything is copied.', 'iworksie-migrate' ), size_format( (int) $free ) )
			);
		}

		$ok = true;
		foreach ( $results as $result ) {
			if ( 'critical' === $result->status() ) {
				$ok = false;
			}
		}

		return array(
			'ok'      => $ok,
			'results' => $results,
			'giver'   => $giver,
		);
	}

	/**
	 * Whether two URLs point to the same site (ignores scheme and trailing slash).
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_site( $a, $b ) {
		$norm = static function ( $url ) {
			$url = strtolower( untrailingslashit( (string) $url ) );
			return (string) preg_replace( '#^https?://(www\.)?#', '', $url );
		};
		return $norm( $a ) === $norm( $b );
	}
}
