<?php
/**
 * Resumable pull of the giving site's uploads into this site's uploads.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Receiver;

use IWorksIE\Migrate\Support\MigrationKey;
use IWorksIE\Migrate\Support\Paths;
use IWorksIE\Migrate\Vendor\IWorksIE\Core\Jobs\Job;

/**
 * Three phases, all resumable from state:
 * 1. count  - list the giver's files, total the bytes still needed, check disk space;
 * 2. copy   - stream each missing/changed file in chunks to a .iwiemg-part file, then rename;
 * 3. verify - compare SHA-1 of every file against the giver.
 */
final class PullJob implements Job {

	const ID = 'migrate_pull';

	/**
	 * Option holding the receiving side's pasted key.
	 *
	 * @var string
	 */
	const KEY_OPTION = 'iwiemg_receiver_key';

	/**
	 * Consecutive network failures before the job pauses for the user.
	 *
	 * @var int
	 */
	const MAX_NET_FAILURES = 6;

	/**
	 * Max items kept in each report list.
	 *
	 * @var int
	 */
	const LIST_CAP = 100;

	/**
	 * Free space to leave on the disk after copying (bytes).
	 *
	 * @var int
	 */
	const DISK_MARGIN = 52428800;

	/**
	 * Files up to this size are fetched in batches (bytes).
	 *
	 * @var int
	 */
	const SMALL_FILE = 524288;

	/**
	 * Max files per batch request.
	 *
	 * @var int
	 */
	const BATCH_FILES = 50;

	/**
	 * Target bytes per batch request.
	 *
	 * @var int
	 */
	const BATCH_BYTES = 3145728;

	/**
	 * @var Client|null Injected for tests.
	 */
	private $client;

	/**
	 * @var string Uploads base override for tests.
	 */
	private $base;

	/**
	 * @param Client|null $client Client override.
	 * @param string      $base   Uploads base override.
	 */
	public function __construct( $client = null, $base = '' ) {
		$this->client = $client;
		$this->base   = (string) $base;
	}

	/**
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * @param array<string,mixed> $params replace, skip_dead_weight, verify ("1"/"0").
	 * @return array<string,mixed>
	 */
	public function start( array $params ) {
		$state = array(
			'phase'        => 'count',
			'cursor'       => '',
			'queue'        => array(),
			'cur'          => null,
			'eol'          => false,
			'opts'         => array(
				'replace'          => self::flag( $params, 'replace', false ),
				'skip_dead_weight' => self::flag( $params, 'skip_dead_weight', true ),
				'verify'           => self::flag( $params, 'verify', true ),
			),
			't'            => array(
				'files'      => 0,
				'bytes'      => 0,
				'need_bytes' => 0,
			),
			'c'            => array(
				'copied'       => 0,
				'copied_bytes' => 0,
				'replaced'     => 0,
				'same'         => 0,
				'kept'         => 0,
				'skipped'      => 0,
				'errors'       => 0,
				'done_bytes'   => 0,
				'verified'     => 0,
				'mismatched'   => 0,
				'missing'      => 0,
				'verify_bytes' => 0,
			),
			'lists'        => array(
				'kept'       => array(),
				'replaced'   => array(),
				'skipped'    => array(),
				'errors'     => array(),
				'mismatched' => array(),
				'missing'    => array(),
			),
			'skip_reasons' => array(),
			'net_failures' => 0,
			'paused'       => false,
			'last_error'   => '',
			'failed'       => '',
			'failed_fix'   => '',
			'giver'        => '',
			'started'      => time(),
			'progress'     => 0,
			'message'      => __( 'Listing files on the other site…', 'iworksie-migrate' ),
			'done'         => false,
		);

		$client = $this->client();
		if ( null === $client ) {
			return $this->fail( $state, __( 'No migration key has been checked yet.', 'iworksie-migrate' ), __( 'Paste the key from the other site and press Check connection first.', 'iworksie-migrate' ) );
		}
		$state['giver'] = $client->giver_home();
		if ( '' === $this->base() ) {
			return $this->fail( $state, __( 'This site\'s uploads folder could not be found.', 'iworksie-migrate' ), __( 'Check the uploads path in Settings > Media, or ask your host why wp-content/uploads is missing.', 'iworksie-migrate' ) );
		}
		return $state;
	}

	/**
	 * @param array<string,mixed> $state       State.
	 * @param float               $time_budget Seconds.
	 * @return array<string,mixed>
	 */
	public function step( array $state, $time_budget ) {
		$deadline = microtime( true ) + max( 0.05, (float) $time_budget );
		$client   = $this->client();
		if ( null === $client ) {
			return $this->fail( $state, __( 'The migration key was removed while copying.', 'iworksie-migrate' ), __( 'Paste the key again and start a new copy. Files already copied will be skipped.', 'iworksie-migrate' ) );
		}
		if ( ! empty( $state['paused'] ) ) {
			// The user pressed Resume.
			$state['paused']       = false;
			$state['net_failures'] = 0;
		}

		$timeout = (int) max( 5, ceil( (float) $time_budget ) );

		while ( empty( $state['done'] ) && microtime( true ) < $deadline ) {
			$before = $state;
			switch ( $state['phase'] ) {
				case 'count':
					$state = $this->step_count( $client, $state, $timeout );
					break;
				case 'copy':
					$state = $this->step_copy( $client, $state, $timeout, $deadline );
					break;
				case 'verify':
					$state = $this->step_verify( $client, $state, $timeout, $deadline );
					break;
				default:
					$state['done'] = true;
			}
			if ( (int) $state['net_failures'] > (int) $before['net_failures'] ) {
				// Give the other server a moment; the browser waits before the next step.
				break;
			}
		}

		$state['progress'] = $this->progress( $state );
		$state['message']  = $this->message( $state );
		if ( ! empty( $state['done'] ) ) {
			$this->cleanup_part( $state );
			$state['queue'] = array();
			$state['cur']   = null;
		}
		return $state;
	}

	/**
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed>
	 */
	public function finish( array $state ) {
		$summary = self::summary( $state );
		update_option( 'iwiemg_last_report', $summary, false );
		// Drop the pasted key (including secret) after a successful run so DB
		// backups of the receiver do not keep a live giver credential.
		if ( empty( $state['cancelled'] ) && empty( $state['failed'] ) ) {
			delete_option( self::KEY_OPTION );
		}
		return $summary;
	}

	/**
	 * Lean copy of the state for the report and status screens.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function summary( array $state ) {
		return array(
			'giver'        => isset( $state['giver'] ) ? (string) $state['giver'] : '',
			'receiver'     => home_url( '/' ),
			'started'      => isset( $state['started'] ) ? (int) $state['started'] : 0,
			'finished'     => time(),
			'opts'         => isset( $state['opts'] ) ? $state['opts'] : array(),
			't'            => isset( $state['t'] ) ? $state['t'] : array(),
			'c'            => isset( $state['c'] ) ? $state['c'] : array(),
			'lists'        => isset( $state['lists'] ) ? $state['lists'] : array(),
			'skip_reasons' => isset( $state['skip_reasons'] ) ? $state['skip_reasons'] : array(),
			'failed'       => isset( $state['failed'] ) ? (string) $state['failed'] : '',
			'failed_fix'   => isset( $state['failed_fix'] ) ? (string) $state['failed_fix'] : '',
			'phase'        => isset( $state['phase'] ) ? (string) $state['phase'] : '',
		);
	}

	// ---------------------------------------------------------------------
	// Phases.
	// ---------------------------------------------------------------------

	/**
	 * Count phase: one manifest page.
	 *
	 * @param Client              $client  Client.
	 * @param array<string,mixed> $state   State.
	 * @param int                 $timeout HTTP timeout.
	 * @return array<string,mixed>
	 */
	private function step_count( Client $client, array $state, $timeout ) {
		$page = $client->call(
			'manifest',
			array(
				'cursor'           => (string) $state['cursor'],
				'limit'            => 1000,
				'skip_dead_weight' => ! empty( $state['opts']['skip_dead_weight'] ),
			),
			$timeout
		);
		if ( is_wp_error( $page ) ) {
			return $this->handle_error( $state, $page );
		}
		$state['net_failures'] = 0;

		$base = $this->base();
		foreach ( self::entries( $page ) as $entry ) {
			if ( isset( $entry['skip'] ) ) {
				$state = $this->record_skip( $state, $entry );
				continue;
			}
			$rel = Paths::normalize_relative( $entry['p'] );
			if ( null === $rel ) {
				continue;
			}
			$size  = isset( $entry['s'] ) ? max( 0, (int) $entry['s'] ) : 0;
			$mtime = isset( $entry['m'] ) ? (int) $entry['m'] : 0;
			++$state['t']['files'];
			$state['t']['bytes'] += $size;
			$local                = $base . '/' . $rel;
			$kept                 = file_exists( $local ) && empty( $state['opts']['replace'] );
			if ( ! $kept && ! self::is_identical( $local, $size, $mtime ) ) {
				$state['t']['need_bytes'] += $size;
			}
		}

		$state['cursor'] = isset( $page['cursor'] ) ? (string) $page['cursor'] : (string) $state['cursor'];
		if ( ! empty( $page['done'] ) ) {
			$state['phase']  = 'copy';
			$state['cursor'] = '';
			$state['queue']  = array();
			$state['eol']    = false;

			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
			$need = (float) $state['t']['need_bytes'];
			if ( false !== $free && $need > 0 && $need + self::DISK_MARGIN > (float) $free ) {
				return $this->fail(
					$state,
					sprintf(
						/* translators: 1: bytes needed, 2: bytes free */
						__( 'Not enough disk space on this site: the copy needs %1$s but only %2$s is free.', 'iworksie-migrate' ),
						size_format( (int) $need ),
						size_format( (int) $free )
					),
					__( 'Free up space or ask your host for more, then start again. Leave "Skip backups, caches and logs" ticked to avoid copying dead weight.', 'iworksie-migrate' )
				);
			}
		}
		return $state;
	}

	/**
	 * Copy phase: continue the current file, or take the next one, or fetch more.
	 *
	 * @param Client              $client   Client.
	 * @param array<string,mixed> $state    State.
	 * @param int                 $timeout  HTTP timeout.
	 * @param float               $deadline Step deadline.
	 * @return array<string,mixed>
	 */
	private function step_copy( Client $client, array $state, $timeout, $deadline ) {
		if ( is_array( $state['cur'] ) ) {
			return $this->download_chunk( $client, $state, $timeout );
		}
		if ( ! empty( $state['queue'] ) ) {
			// Small files travel together in one request; per-request overhead
			// dominates on Media Libraries full of thumbnails.
			$batch = array();
			$bytes = 0;
			$files = 0;
			while ( ! empty( $state['queue'] ) && $files < self::BATCH_FILES && $bytes < self::BATCH_BYTES ) {
				$entry = (array) $state['queue'][0];
				$large = (int) $entry['s'] > self::SMALL_FILE || ! empty( $entry['nb'] );
				if ( $large && ! empty( $batch ) ) {
					break;
				}
				array_shift( $state['queue'] );
				$state = $this->begin_file( $state, $entry );
				if ( $large ) {
					return $state;
				}
				if ( is_array( $state['cur'] ) ) {
					$batch[]      = $state['cur'];
					$bytes       += (int) $state['cur']['s'];
					$state['cur'] = null;
					++$files;
				}
			}
			if ( empty( $batch ) ) {
				return $state;
			}
			return $this->download_batch( $client, $state, $batch, $timeout );
		}
		if ( ! empty( $state['eol'] ) ) {
			$state['phase']  = ! empty( $state['opts']['verify'] ) ? 'verify' : 'done';
			$state['cursor'] = '';
			$state['eol']    = false;
			if ( 'done' === $state['phase'] ) {
				$state['done'] = true;
			}
			return $state;
		}

		$page = $client->call(
			'manifest',
			array(
				'cursor'           => (string) $state['cursor'],
				'limit'            => 200,
				'skip_dead_weight' => ! empty( $state['opts']['skip_dead_weight'] ),
			),
			$timeout
		);
		if ( is_wp_error( $page ) ) {
			return $this->handle_error( $state, $page );
		}
		$state['net_failures'] = 0;
		foreach ( self::entries( $page ) as $entry ) {
			if ( ! isset( $entry['skip'] ) ) {
				$state['queue'][] = array(
					'p' => (string) $entry['p'],
					's' => isset( $entry['s'] ) ? max( 0, (int) $entry['s'] ) : 0,
					'm' => isset( $entry['m'] ) ? (int) $entry['m'] : 0,
				);
			}
		}
		$state['cursor'] = isset( $page['cursor'] ) ? (string) $page['cursor'] : (string) $state['cursor'];
		$state['eol']    = ! empty( $page['done'] );
		return $state;
	}

	/**
	 * Decide what to do with one listed file.
	 *
	 * @param array<string,mixed> $state State.
	 * @param array<string,mixed> $entry {p,s,m}.
	 * @return array<string,mixed>
	 */
	private function begin_file( array $state, array $entry ) {
		$rel  = Paths::normalize_relative( isset( $entry['p'] ) ? $entry['p'] : null );
		$size = (int) $entry['s'];
		if ( null === $rel || '' !== Paths::blocked_reason( $rel ) ) {
			return $this->record_error( $state, isset( $entry['p'] ) ? (string) $entry['p'] : '?', __( 'Refused: unsafe file name or type.', 'iworksie-migrate' ), $size );
		}
		$base  = $this->base();
		$local = $base . '/' . $rel;

		if ( is_dir( $local ) ) {
			return $this->record_kept( $state, $rel, $size );
		}
		$exists = file_exists( $local );
		if ( $exists ) {
			if ( self::is_identical( $local, $size, (int) $entry['m'] ) ) {
				++$state['c']['same'];
				$state['c']['done_bytes'] += $size;
				return $state;
			}
			if ( empty( $state['opts']['replace'] ) ) {
				return $this->record_kept( $state, $rel, $size );
			}
		}

		$dir = dirname( $local );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return $this->record_error( $state, $rel, __( 'Could not create the folder on this site.', 'iworksie-migrate' ), $size );
		}
		$real_dir = realpath( $dir );
		if ( false === $real_dir || ! Paths::is_inside( $real_dir, $base ) ) {
			return $this->record_error( $state, $rel, __( 'Refused: the folder on this site points outside the uploads folder.', 'iworksie-migrate' ), $size );
		}

		$state['cur'] = array(
			'p'      => $rel,
			's'      => $size,
			'm'      => (int) $entry['m'],
			'o'      => 0,
			'exists' => $exists,
		);
		if ( 0 === $size ) {
			if ( false === @file_put_contents( $local . Paths::PART_SUFFIX, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- empty file inside uploads.
				$state['cur'] = null;
				return $this->record_error( $state, $rel, __( 'Could not write the file on this site.', 'iworksie-migrate' ), 0 );
			}
			return $this->complete_file( $state );
		}
		return $state;
	}

	/**
	 * Fetch several small files in one request.
	 *
	 * @param Client                         $client  Client.
	 * @param array<string,mixed>            $state   State.
	 * @param array<int,array<string,mixed>> $batch   Prepared files (cur records).
	 * @param int                            $timeout HTTP timeout.
	 * @return array<string,mixed>
	 */
	private function download_batch( Client $client, array $state, array $batch, $timeout ) {
		$paths = array();
		foreach ( $batch as $cur ) {
			$paths[] = (string) $cur['p'];
		}
		$resp = $client->call( 'files', array( 'paths' => $paths ), $timeout );
		if ( is_wp_error( $resp ) ) {
			$requeue = array();
			foreach ( $batch as $cur ) {
				$requeue[] = array(
					'p' => $cur['p'],
					's' => $cur['s'],
					'm' => $cur['m'],
				);
			}
			$state['queue'] = array_merge( $requeue, $state['queue'] );
			return $this->handle_error( $state, $resp );
		}
		$state['net_failures'] = 0;

		$by_path = array();
		if ( isset( $resp['files'] ) && is_array( $resp['files'] ) ) {
			foreach ( $resp['files'] as $item ) {
				if ( is_array( $item ) && isset( $item['p'] ) && is_string( $item['p'] ) ) {
					$by_path[ $item['p'] ] = $item;
				}
			}
		}

		$retry = array();
		foreach ( $batch as $cur ) {
			$item  = isset( $by_path[ $cur['p'] ] ) ? $by_path[ $cur['p'] ] : array( 'error' => 'later' );
			$entry = array(
				'p' => $cur['p'],
				's' => $cur['s'],
				'm' => $cur['m'],
			);
			if ( isset( $item['error'] ) ) {
				if ( 'missing' === $item['error'] ) {
					$state = $this->record_error( $state, (string) $cur['p'], __( 'That file no longer exists on the other site.', 'iworksie-migrate' ), (int) $cur['s'] );
					continue;
				}
				// "later" (response full) or anything unexpected: fetch it on its own.
				$entry['nb'] = 'later' === $item['error'] ? 0 : 1;
				$retry[]     = $entry;
				continue;
			}
			$data = isset( $item['data'] ) && is_string( $item['data'] ) ? base64_decode( $item['data'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary-safe JSON transport.
			if ( false === $data || ! isset( $item['sha1'] ) || ! hash_equals( (string) $item['sha1'], sha1( $data ) ) || strlen( $data ) !== (int) $cur['s'] ) {
				$entry['nb'] = 1;
				$retry[]     = $entry;
				continue;
			}
			$part = $this->base() . '/' . $cur['p'] . Paths::PART_SUFFIX;
			if ( false === @file_put_contents( $part, $data ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing inside uploads.
				$state = $this->record_error( $state, (string) $cur['p'], __( 'Could not write the file on this site.', 'iworksie-migrate' ), (int) $cur['s'] );
				continue;
			}
			$cur['o']                  = (int) $cur['s'];
			$state['c']['done_bytes'] += (int) $cur['s'];
			$state['cur']              = $cur;
			$state                     = $this->complete_file( $state );
		}
		if ( ! empty( $retry ) ) {
			$state['queue'] = array_merge( $retry, $state['queue'] );
		}
		return $state;
	}

	/**
	 * Fetch and append one chunk of the current file.
	 *
	 * @param Client              $client  Client.
	 * @param array<string,mixed> $state   State.
	 * @param int                 $timeout HTTP timeout.
	 * @return array<string,mixed>
	 */
	private function download_chunk( Client $client, array $state, $timeout ) {
		$cur    = $state['cur'];
		$local  = $this->base() . '/' . $cur['p'];
		$part   = $local . Paths::PART_SUFFIX;
		$offset = (int) $cur['o'];

		// The part file is the source of truth if a previous request died mid-write.
		clearstatcache( true, $part );
		$on_disk = file_exists( $part ) ? (int) filesize( $part ) : 0;
		if ( $on_disk < $offset ) {
			$state['c']['done_bytes'] -= ( $offset - $on_disk );
			$offset                    = $on_disk;
		}

		/**
		 * Bytes requested per chunk. Bigger is faster on good connections.
		 *
		 * @param int $bytes Default 2 MB.
		 */
		$max_chunk = (int) apply_filters( 'iwiemg_chunk_bytes', 2097152 );
		$max_chunk = max( 65536, min( 8388608, $max_chunk ) );
		// Adapts to the connection: halves after a failed chunk, grows back after fast ones.
		$chunk  = isset( $state['chunk'] ) ? max( 65536, min( $max_chunk, (int) $state['chunk'] ) ) : $max_chunk;
		$length = (int) min( $chunk, (int) $cur['s'] - $offset );
		$t0     = microtime( true );

		$resp = $client->call(
			'chunk',
			array(
				'path'   => $cur['p'],
				'offset' => $offset,
				'length' => $length,
			),
			$timeout
		);
		if ( is_wp_error( $resp ) ) {
			$code = (string) $resp->get_error_code();
			if ( in_array( $code, array( 'iwiemg_missing', 'iwiemg_bad_path', 'iwiemg_unreadable' ), true ) ) {
				$state = $this->abandon_file( $state );
				return $this->record_error( $state, (string) $cur['p'], self::first( Client::explain( $resp ) ), 0 );
			}
			if ( Client::is_transient( $resp ) ) {
				$state['chunk'] = max( 65536, (int) floor( $chunk / 2 ) );
			}
			return $this->handle_error( $state, $resp );
		}
		$state['net_failures'] = 0;
		if ( microtime( true ) - $t0 < $timeout / 4 && $length >= $chunk ) {
			$state['chunk'] = min( $max_chunk, $chunk * 2 );
		}

		$data = isset( $resp['data'] ) && is_string( $resp['data'] ) ? base64_decode( $resp['data'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary-safe JSON transport.
		if ( false === $data || ! isset( $resp['sha1'] ) || ! hash_equals( (string) $resp['sha1'], sha1( $data ) ) ) {
			$state['net_failures'] = (int) $state['net_failures'] + 1;
			$state['last_error']   = __( 'A chunk arrived damaged; retrying.', 'iworksie-migrate' );
			return $state;
		}
		if ( (int) ( isset( $resp['size'] ) ? $resp['size'] : -1 ) !== (int) $cur['s'] || '' === $data || strlen( $data ) > $length ) {
			$state = $this->abandon_file( $state );
			return $this->record_error( $state, (string) $cur['p'], __( 'The file changed on the other site while it was being copied. Run the copy again to pick up the new version.', 'iworksie-migrate' ), 0 );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- appending a byte range inside uploads; WP_Filesystem has no ranged write.
		$handle = @fopen( $part, 0 === $offset ? 'wb' : 'cb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ok     = false;
		if ( false !== $handle ) {
			$ok = ftruncate( $handle, $offset ) && 0 === fseek( $handle, $offset ) && strlen( $data ) === fwrite( $handle, $data );
			fclose( $handle );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( ! $ok ) {
			$state = $this->abandon_file( $state );
			$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->base() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $free && (float) $free < 1048576 * 5 ) {
				return $this->fail( $state, __( 'This site ran out of disk space while copying.', 'iworksie-migrate' ), __( 'Free up space, then start again. Files already copied will be skipped.', 'iworksie-migrate' ) );
			}
			return $this->record_error( $state, (string) $cur['p'], __( 'Could not write the file on this site.', 'iworksie-migrate' ), 0 );
		}

		$state['cur']['o']         = $offset + strlen( $data );
		$state['c']['done_bytes'] += strlen( $data );
		if ( (int) $state['cur']['o'] >= (int) $cur['s'] ) {
			return $this->complete_file( $state );
		}
		return $state;
	}

	/**
	 * Move the finished part file into place.
	 *
	 * @param array<string,mixed> $state State with cur fully downloaded.
	 * @return array<string,mixed>
	 */
	private function complete_file( array $state ) {
		$cur   = $state['cur'];
		$local = $this->base() . '/' . $cur['p'];
		$part  = $local . Paths::PART_SUFFIX;
		clearstatcache( true, $part );
		if ( ! file_exists( $part ) || (int) filesize( $part ) !== (int) $cur['s'] ) {
			$state = $this->abandon_file( $state );
			return $this->record_error( $state, (string) $cur['p'], __( 'The copied file was the wrong size and was discarded.', 'iworksie-migrate' ), 0 );
		}
		// Replace without a delete-before-rename window: move the existing file
		// aside, rename the part into place, then delete the aside copy. A crash
		// between the renames leaves either the old file or the new one.
		$aside = '';
		if ( file_exists( $local ) && ! is_dir( $local ) ) {
			$aside = $local . '.iwiemg-old-' . wp_generate_password( 8, false, false );
			if ( ! @rename( $local, $aside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic move.
				$state = $this->abandon_file( $state );
				return $this->record_error( $state, (string) $cur['p'], __( 'Could not move the copied file into place.', 'iworksie-migrate' ), 0 );
			}
		}
		if ( ! @rename( $part, $local ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic move within the same folder.
			if ( '' !== $aside && file_exists( $aside ) ) {
				@rename( $aside, $local ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- restore previous file after failed replace.
			}
			$state = $this->abandon_file( $state );
			return $this->record_error( $state, (string) $cur['p'], __( 'Could not move the copied file into place.', 'iworksie-migrate' ), 0 );
		}
		if ( '' !== $aside && file_exists( $aside ) ) {
			wp_delete_file( $aside );
		}
		$stat = @stat( dirname( $local ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $stat ) ) {
			@chmod( $local, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions WordPress gives uploads.
		}
		if ( (int) $cur['m'] > 0 ) {
			@touch( $local, (int) $cur['m'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- keep the original date so re-runs can skip it.
		}

		++$state['c']['copied'];
		$state['c']['copied_bytes'] += (int) $cur['s'];
		if ( ! empty( $cur['exists'] ) ) {
			++$state['c']['replaced'];
			$state['lists']['replaced'] = self::push( $state['lists']['replaced'], array( 'p' => (string) $cur['p'] ) );
		}
		$state['cur'] = null;
		return $state;
	}

	/**
	 * Verify phase.
	 *
	 * @param Client              $client   Client.
	 * @param array<string,mixed> $state    State.
	 * @param int                 $timeout  HTTP timeout.
	 * @param float               $deadline Step deadline.
	 * @return array<string,mixed>
	 */
	private function step_verify( Client $client, array $state, $timeout, $deadline ) {
		if ( ! empty( $state['queue'] ) ) {
			$base = $this->base();
			while ( ! empty( $state['queue'] ) && microtime( true ) < $deadline ) {
				$entry = (array) array_shift( $state['queue'] );
				$rel   = Paths::normalize_relative( isset( $entry['p'] ) ? $entry['p'] : null );
				if ( null === $rel ) {
					continue;
				}
				$size                        = isset( $entry['s'] ) ? (int) $entry['s'] : 0;
				$local                       = $base . '/' . $rel;
				$state['c']['verify_bytes'] += $size;

				if ( ! is_file( $local ) ) {
					if ( ! self::listed( $state['lists']['errors'], $rel ) ) {
						++$state['c']['missing'];
						$state['lists']['missing'] = self::push( $state['lists']['missing'], array( 'p' => $rel ) );
					}
					continue;
				}
				clearstatcache( true, $local );
				if ( (int) filesize( $local ) !== $size && empty( $state['opts']['replace'] ) ) {
					// A different file already here that was kept on purpose: not a copy fault.
					continue;
				}
				$theirs = isset( $entry['h'] ) ? (string) $entry['h'] : '';
				$ours   = @sha1_file( $local ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( '' !== $theirs && false !== $ours && hash_equals( $theirs, $ours ) ) {
					++$state['c']['verified'];
					continue;
				}
				if ( empty( $state['opts']['replace'] ) && self::listed( $state['lists']['kept'], $rel ) ) {
					continue;
				}
				if ( self::listed( $state['lists']['errors'], $rel ) ) {
					continue;
				}
				++$state['c']['mismatched'];
				$state['lists']['mismatched'] = self::push( $state['lists']['mismatched'], array( 'p' => $rel ) );
			}
			return $state;
		}
		if ( ! empty( $state['eol'] ) ) {
			$state['phase'] = 'done';
			$state['done']  = true;
			return $state;
		}

		$page = $client->call(
			'manifest',
			array(
				'cursor'           => (string) $state['cursor'],
				'limit'            => 100,
				'hash'             => true,
				'skip_dead_weight' => ! empty( $state['opts']['skip_dead_weight'] ),
			),
			$timeout
		);
		if ( is_wp_error( $page ) ) {
			return $this->handle_error( $state, $page );
		}
		$state['net_failures'] = 0;
		foreach ( self::entries( $page ) as $entry ) {
			if ( ! isset( $entry['skip'] ) ) {
				$state['queue'][] = array(
					'p' => (string) $entry['p'],
					's' => isset( $entry['s'] ) ? (int) $entry['s'] : 0,
					'm' => isset( $entry['m'] ) ? (int) $entry['m'] : 0,
					'h' => isset( $entry['h'] ) ? (string) $entry['h'] : '',
				);
			}
		}
		$state['cursor'] = isset( $page['cursor'] ) ? (string) $page['cursor'] : (string) $state['cursor'];
		$state['eol']    = ! empty( $page['done'] );
		return $state;
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * Network / remote error: retry later, or stop for good.
	 *
	 * @param array<string,mixed> $state State.
	 * @param \WP_Error           $error Error.
	 * @return array<string,mixed>
	 */
	private function handle_error( array $state, \WP_Error $error ) {
		$explained = Client::explain( $error );
		if ( Client::is_transient( $error ) ) {
			$state['net_failures'] = (int) $state['net_failures'] + 1;
			$state['last_error']   = $explained[0];
			if ( (int) $state['net_failures'] >= self::MAX_NET_FAILURES ) {
				$state['paused'] = true;
			}
			return $state;
		}
		return $this->fail( $state, $explained[0], $explained[1] );
	}

	/**
	 * Stop the job with a reason.
	 *
	 * @param array<string,mixed> $state State.
	 * @param string              $why   What went wrong.
	 * @param string              $fix   What to do.
	 * @return array<string,mixed>
	 */
	private function fail( array $state, $why, $fix ) {
		$this->cleanup_part( $state );
		$state['failed']     = (string) $why;
		$state['failed_fix'] = (string) $fix;
		$state['done']       = true;
		$state['cur']        = null;
		$state['queue']      = array();
		$state['message']    = (string) $why;
		return $state;
	}

	/**
	 * Drop the current file's partial download.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	private function abandon_file( array $state ) {
		if ( is_array( $state['cur'] ) ) {
			$state['c']['done_bytes'] += max( 0, (int) $state['cur']['s'] - (int) $state['cur']['o'] );
		}
		$this->cleanup_part( $state );
		$state['cur'] = null;
		return $state;
	}

	/**
	 * Delete the current .iwiemg-part file if any. Public so a cancel can tidy up.
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	public function cleanup_part( array $state ) {
		if ( empty( $state['cur'] ) || ! is_array( $state['cur'] ) || '' === $this->base() ) {
			return;
		}
		$rel = Paths::normalize_relative( isset( $state['cur']['p'] ) ? $state['cur']['p'] : null );
		if ( null === $rel ) {
			return;
		}
		$part = $this->base() . '/' . $rel . Paths::PART_SUFFIX;
		if ( file_exists( $part ) ) {
			wp_delete_file( $part );
		}
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @param array<string,mixed> $entry Skip entry from the giver.
	 * @return array<string,mixed>
	 */
	private function record_skip( array $state, array $entry ) {
		$reason = isset( $entry['skip'] ) ? sanitize_key( (string) $entry['skip'] ) : 'other';
		++$state['c']['skipped'];
		if ( ! isset( $state['skip_reasons'][ $reason ] ) ) {
			$state['skip_reasons'][ $reason ] = 0;
		}
		++$state['skip_reasons'][ $reason ];
		$state['lists']['skipped'] = self::push(
			$state['lists']['skipped'],
			array(
				'p'   => (string) $entry['p'],
				'r'   => $reason,
				's'   => isset( $entry['s'] ) ? (int) $entry['s'] : -1,
				'dir' => ! empty( $entry['dir'] ),
			)
		);
		return $state;
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @param string              $rel   Path.
	 * @param int                 $size  Bytes.
	 * @return array<string,mixed>
	 */
	private function record_kept( array $state, $rel, $size ) {
		++$state['c']['kept'];
		$state['c']['done_bytes'] += $size;
		$state['lists']['kept']    = self::push( $state['lists']['kept'], array( 'p' => $rel ) );
		return $state;
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @param string              $rel   Path.
	 * @param string              $msg   Message.
	 * @param int                 $size  Bytes not copied (counted as processed for progress).
	 * @return array<string,mixed>
	 */
	private function record_error( array $state, $rel, $msg, $size ) {
		++$state['c']['errors'];
		$state['c']['done_bytes'] += max( 0, (int) $size );
		$state['lists']['errors']  = self::push(
			$state['lists']['errors'],
			array(
				'p' => (string) $rel,
				'e' => (string) $msg,
			)
		);
		return $state;
	}

	/**
	 * Append to a capped list.
	 *
	 * @param array<int,array<string,mixed>> $items List.
	 * @param array<string,mixed>            $item Item.
	 * @return array<int,array<string,mixed>>
	 */
	private static function push( array $items, array $item ) {
		if ( count( $items ) < self::LIST_CAP ) {
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Whether a path is in a capped list.
	 *
	 * @param array<int,array<string,mixed>> $items List.
	 * @param string                         $rel  Path.
	 * @return bool
	 */
	private static function listed( array $items, $rel ) {
		foreach ( $items as $item ) {
			if ( isset( $item['p'] ) && $item['p'] === $rel ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Same size and modification time.
	 *
	 * @param string $path  Local path.
	 * @param int    $size  Expected bytes.
	 * @param int    $mtime Expected mtime.
	 * @return bool
	 */
	private static function is_identical( $path, $size, $mtime ) {
		if ( ! is_file( $path ) ) {
			return false;
		}
		clearstatcache( true, $path );
		return (int) filesize( $path ) === (int) $size && (int) filemtime( $path ) === (int) $mtime;
	}

	/**
	 * Valid entries from a manifest page.
	 *
	 * @param array<string,mixed> $page Page.
	 * @return array<int,array<string,mixed>>
	 */
	private static function entries( array $page ) {
		$out = array();
		if ( empty( $page['entries'] ) || ! is_array( $page['entries'] ) ) {
			return $out;
		}
		foreach ( $page['entries'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['p'] ) && is_string( $entry['p'] ) && '' !== $entry['p'] ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @param string              $name   Key.
	 * @param bool                $fallback Default.
	 * @return bool
	 */
	private static function flag( array $params, $name, $fallback ) {
		if ( ! isset( $params[ $name ] ) ) {
			return $fallback;
		}
		$value = $params[ $name ];
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}

	/**
	 * @param array{0: string, 1: string} $pair Pair.
	 * @return string
	 */
	private static function first( array $pair ) {
		return $pair[0];
	}

	/**
	 * 0-100.
	 *
	 * @param array<string,mixed> $state State.
	 * @return int
	 */
	private function progress( array $state ) {
		if ( ! empty( $state['done'] ) ) {
			return 100;
		}
		$total = max( 1, (int) $state['t']['bytes'] );
		switch ( $state['phase'] ) {
			case 'count':
				return 2;
			case 'copy':
				return (int) min( 89, 3 + floor( 86 * ( (float) $state['c']['done_bytes'] / $total ) ) );
			case 'verify':
				return (int) min( 99, 90 + floor( 9 * ( (float) $state['c']['verify_bytes'] / $total ) ) );
		}
		return 0;
	}

	/**
	 * Status line.
	 *
	 * @param array<string,mixed> $state State.
	 * @return string
	 */
	private function message( array $state ) {
		if ( '' !== (string) $state['failed'] ) {
			return (string) $state['failed'];
		}
		if ( ! empty( $state['paused'] ) ) {
			return __( 'Paused: the other site stopped answering.', 'iworksie-migrate' ) . ' ' . (string) $state['last_error'];
		}
		if ( (int) $state['net_failures'] > 0 ) {
			/* translators: 1: attempt number, 2: max attempts */
			return sprintf( __( 'Connection problem, retrying (%1$d of %2$d)…', 'iworksie-migrate' ), (int) $state['net_failures'], self::MAX_NET_FAILURES );
		}
		if ( ! empty( $state['done'] ) ) {
			return __( 'Finished.', 'iworksie-migrate' );
		}
		switch ( $state['phase'] ) {
			case 'count':
				/* translators: 1: number of files, 2: size */
				return sprintf( __( 'Listing files on the other site… %1$s files, %2$s so far', 'iworksie-migrate' ), number_format_i18n( (int) $state['t']['files'] ), size_format( (int) $state['t']['bytes'] ) );
			case 'copy':
				return sprintf(
					/* translators: 1: bytes processed, 2: total bytes, 3: current file */
					__( 'Copying… %1$s of %2$s%3$s', 'iworksie-migrate' ),
					size_format( (int) $state['c']['done_bytes'] ),
					size_format( (int) max( 1, (int) $state['t']['bytes'] ) ),
					is_array( $state['cur'] ) ? ' · ' . (string) $state['cur']['p'] : ''
				);
			case 'verify':
				/* translators: %d: files verified */
				return sprintf( __( 'Verifying copied files… %d checked', 'iworksie-migrate' ), (int) $state['c']['verified'] + (int) $state['c']['mismatched'] + (int) $state['c']['missing'] );
		}
		return '';
	}

	/**
	 * Client from the stored key.
	 *
	 * @return Client|null
	 */
	private function client() {
		if ( null !== $this->client ) {
			return $this->client;
		}
		$key = MigrationKey::decode( (string) get_option( self::KEY_OPTION, '' ) );
		return null === $key ? null : new Client( $key );
	}

	/**
	 * Uploads base path.
	 *
	 * @return string
	 */
	private function base() {
		if ( '' === $this->base ) {
			$this->base = Paths::uploads_base();
		}
		return $this->base;
	}
}
