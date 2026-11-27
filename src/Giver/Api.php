<?php
/**
 * Read-only REST endpoints on the giving site.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Giver;

use IWorksIE\Migrate\Support\MigrationKey;
use IWorksIE\Migrate\Support\Paths;
use IWorksIE\Migrate\Support\Walker;

/**
 * Endpoints the receiving site calls. Every request must carry a valid HMAC
 * signature made with the active key; nothing here writes files or content.
 */
final class Api {

	const REST_NAMESPACE = 'iworksie-migrate/v1';

	/**
	 * Largest chunk we hand out in one response (bytes).
	 *
	 * @var int
	 */
	const MAX_CHUNK = 8388608;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function routes() {
		foreach ( array( 'hello', 'manifest', 'chunk', 'files' ) as $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_' . $route ),
					'permission_callback' => array( $this, 'authorize' ),
				)
			);
		}
	}

	/**
	 * Verify the signature on a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authorize( $request ) {
		$key = KeyStore::active();
		if ( null === $key ) {
			return new \WP_Error( 'iwiemg_not_enabled', 'No active migration key on this site. Create one under iWorks Tools > Migrate.', array( 'status' => 403 ) );
		}

		$id   = (string) $request->get_header( 'x_iwiemg_key' );
		$time = (string) $request->get_header( 'x_iwiemg_time' );
		$seq  = (string) $request->get_header( 'x_iwiemg_seq' );
		$sig  = (string) $request->get_header( 'x_iwiemg_signature' );

		if ( '' === $id || ! hash_equals( (string) $key['id'], $id ) ) {
			return new \WP_Error( 'iwiemg_unknown_key', 'This migration key is not the active key on this site. It may have been replaced or revoked.', array( 'status' => 403 ) );
		}
		if ( 1 !== preg_match( '/^\d{1,20}$/', $time ) || 1 !== preg_match( '/^\d{1,30}$/', $seq ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			return new \WP_Error( 'iwiemg_bad_request', 'Malformed signature headers.', array( 'status' => 401 ) );
		}

		$route    = $this->route_name( $request );
		$expected = MigrationKey::sign( (string) $key['secret'], $route, $time, $seq, (string) $request->get_body() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new \WP_Error( 'iwiemg_bad_signature', 'The request signature did not match this site\'s migration key.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $time ) > MigrationKey::MAX_SKEW ) {
			return new \WP_Error(
				'iwiemg_clock_skew',
				'The clocks on the two sites are too far apart.',
				array(
					'status'      => 401,
					'server_time' => time(),
				)
			);
		}
		if ( ! KeyStore::claim_seq( $seq ) ) {
			return new \WP_Error( 'iwiemg_replay', 'Request sequence number already used.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Endpoint name from the matched route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function route_name( $request ) {
		$route = (string) $request->get_route();
		$pos   = strrpos( $route, '/' );
		return false === $pos ? $route : substr( $route, $pos + 1 );
	}

	/**
	 * JSON body as an array.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function body( $request ) {
		$data = json_decode( (string) $request->get_body(), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Site facts for the receiving site's pre-flight.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_hello( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		global $wp_version;
		$base = Paths::uploads_base();
		$dirs = wp_upload_dir( null, false );
		return new \WP_REST_Response(
			array(
				'protocol'         => MigrationKey::PROTOCOL,
				'plugin_version'   => IWIEMG_VERSION,
				'home'             => home_url( '/' ),
				'site_url'         => site_url( '/' ),
				'uploads_url'      => isset( $dirs['baseurl'] ) ? (string) $dirs['baseurl'] : '',
				'uploads_readable' => '' !== $base && is_readable( $base ),
				'wp_version'       => (string) $wp_version,
				'php_version'      => PHP_VERSION,
				'php_int_size'     => PHP_INT_SIZE,
				'has_intl'         => class_exists( '\Normalizer' ),
				'name'             => get_bloginfo( 'name' ),
				'time'             => time(),
				'key_expires'      => (int) ( KeyStore::active()['expires'] ?? 0 ),
			)
		);
	}

	/**
	 * One page of the uploads listing, optionally with SHA-1 hashes for verification.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_manifest( $request ) {
		$base = Paths::uploads_base();
		if ( '' === $base ) {
			return new \WP_Error( 'iwiemg_no_uploads', 'The uploads folder on this site could not be found.', array( 'status' => 500 ) );
		}
		$body   = $this->body( $request );
		$cursor = isset( $body['cursor'] ) && is_string( $body['cursor'] ) ? $body['cursor'] : '';
		if ( '' !== $cursor && null === Paths::normalize_relative( $cursor ) ) {
			return new \WP_Error( 'iwiemg_bad_cursor', 'Invalid cursor.', array( 'status' => 400 ) );
		}
		$limit     = isset( $body['limit'] ) ? max( 1, min( 2000, (int) $body['limit'] ) ) : 500;
		$hash      = ! empty( $body['hash'] );
		$skip_dead = ! isset( $body['skip_dead_weight'] ) || ! empty( $body['skip_dead_weight'] );

		$walker = new Walker( $base, $skip_dead );
		$page   = $walker->page( $cursor, $limit, $this->budget( 4.0 ) );

		if ( $hash ) {
			$page = $this->add_hashes( $base, $page );
		}

		return new \WP_REST_Response( $page );
	}

	/**
	 * Add sha1 to file entries, trimming the page if hashing runs out of time.
	 *
	 * @param string                                                                     $base Base path.
	 * @param array{entries: array<int,array<string,mixed>>, cursor: string, done: bool} $page Page.
	 * @return array{entries: array<int,array<string,mixed>>, cursor: string, done: bool}
	 */
	private function add_hashes( $base, array $page ) {
		$deadline = microtime( true ) + $this->budget( 6.0 );
		$out      = array();
		foreach ( $page['entries'] as $entry ) {
			if ( ! isset( $entry['skip'] ) ) {
				$hash       = @sha1_file( $base . '/' . $entry['p'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$entry['h'] = false === $hash ? '' : $hash;
			}
			$out[] = $entry;
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		if ( count( $out ) < count( $page['entries'] ) ) {
			$last           = end( $out );
			$page['cursor'] = is_array( $last ) ? (string) $last['p'] : $page['cursor'];
			$page['done']   = false;
		}
		$page['entries'] = $out;
		return $page;
	}

	/**
	 * A slice of one file, base64 encoded.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_chunk( $request ) {
		$base = Paths::uploads_base();
		$body = $this->body( $request );
		$rel  = isset( $body['path'] ) ? Paths::normalize_relative( $body['path'] ) : null;
		if ( '' === $base || null === $rel || '' !== Paths::blocked_reason( $rel ) ) {
			return new \WP_Error( 'iwiemg_bad_path', 'That file cannot be sent.', array( 'status' => 400 ) );
		}
		$path   = $base . '/' . $rel;
		$unsafe = Paths::unsafe_read_reason( $path, $base );
		if ( '' !== $unsafe ) {
			$code = 'hardlink' === $unsafe || 'symlink' === $unsafe ? 'iwiemg_bad_path' : 'iwiemg_missing';
			return new \WP_Error( $code, 'That file cannot be sent.', array( 'status' => 'iwiemg_missing' === $code ? 404 : 400 ) );
		}
		$real = realpath( $path );
		if ( false === $real ) {
			return new \WP_Error( 'iwiemg_missing', 'That file no longer exists on this site.', array( 'status' => 404 ) );
		}

		$offset = isset( $body['offset'] ) ? max( 0, (int) $body['offset'] ) : 0;
		$length = isset( $body['length'] ) ? max( 1, min( self::MAX_CHUNK, (int) $body['length'] ) ) : 1048576;
		$size   = Paths::safe_filesize( $real );
		if ( null === $size ) {
			return new \WP_Error( 'iwiemg_too_large', 'That file is too large for this PHP build (64-bit PHP is required for files over about 2 GB).', array( 'status' => 400 ) );
		}

		$data = '';
		if ( $offset < $size ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a byte range; WP_Filesystem has no ranged read.
			$handle = fopen( $real, 'rb' );
			if ( false === $handle ) {
				return new \WP_Error( 'iwiemg_unreadable', 'That file could not be read.', array( 'status' => 500 ) );
			}
			fseek( $handle, $offset );
			$read = fread( $handle, $length );
			fclose( $handle );
			// phpcs:enable WordPress.WP.AlternativeFunctions
			$data = false === $read ? '' : $read;
		}

		return new \WP_REST_Response(
			array(
				'size'   => $size,
				'offset' => $offset,
				'data'   => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe JSON transport.
				'sha1'   => sha1( $data ),
			)
		);
	}

	/**
	 * Several small files at once, base64 encoded.
	 *
	 * Each item is { p, size, data, sha1 } or { p, error } where error is
	 * missing (gone or not allowed), too_big (use chunk) or later (response full).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_files( $request ) {
		$base = Paths::uploads_base();
		$body = $this->body( $request );
		if ( '' === $base || ! isset( $body['paths'] ) || ! is_array( $body['paths'] ) ) {
			return new \WP_Error( 'iwiemg_bad_request', 'Missing paths.', array( 'status' => 400 ) );
		}
		$paths = array_slice( $body['paths'], 0, 100 );
		$total = 0;
		$out   = array();
		foreach ( $paths as $raw ) {
			$rel = Paths::normalize_relative( $raw );
			if ( null === $rel ) {
				continue;
			}
			if ( $total >= self::MAX_CHUNK ) {
				$out[] = array(
					'p'     => $rel,
					'error' => 'later',
				);
				continue;
			}
			$path = $base . '/' . $rel;
			if ( '' !== Paths::blocked_reason( $rel ) || '' !== Paths::unsafe_read_reason( $path, $base ) ) {
				$out[] = array(
					'p'     => $rel,
					'error' => 'missing',
				);
				continue;
			}
			$real = realpath( $path );
			$size = false === $real ? null : Paths::safe_filesize( $real );
			if ( null === $size ) {
				$out[] = array(
					'p'     => $rel,
					'error' => 'missing',
				);
				continue;
			}
			if ( $size > 1048576 ) {
				$out[] = array(
					'p'     => $rel,
					'error' => 'too_big',
				);
				continue;
			}
			$data = $size > 0 ? file_get_contents( $real ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, small.
			if ( false === $data ) {
				$out[] = array(
					'p'     => $rel,
					'error' => 'missing',
				);
				continue;
			}
			$total += strlen( $data );
			$out[]  = array(
				'p'    => $rel,
				'size' => strlen( $data ),
				'data' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe JSON transport.
				'sha1' => sha1( $data ),
			);
		}
		return new \WP_REST_Response( array( 'files' => $out ) );
	}

	/**
	 * Seconds to spend, capped by this server's own execution limit.
	 *
	 * @param float $wanted Preferred seconds.
	 * @return float
	 */
	private function budget( $wanted ) {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			return (float) max( 1.0, min( $wanted, $max * 0.4 ) );
		}
		return (float) $wanted;
	}
}
