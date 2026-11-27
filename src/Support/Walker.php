<?php
/**
 * Resumable, deterministic directory listing.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Support;

/**
 * Lists files under a base directory in a stable depth-first order, one page
 * at a time. The cursor is the last relative path returned, so a listing can
 * resume on a later request (or after a dropped connection) without storing
 * the whole file list anywhere.
 */
final class Walker {

	/**
	 * @var string
	 */
	private $base;

	/**
	 * @var bool
	 */
	private $skip_dead_weight;

	/**
	 * @var int
	 */
	private $limit = 500;

	/**
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private $entries = array();

	/**
	 * @var string
	 */
	private $last = '';

	/**
	 * @var bool
	 */
	private $stopped = false;

	/**
	 * @param string $base             Real path of the directory to list.
	 * @param bool   $skip_dead_weight Whether backups/caches/logs are reported as skipped.
	 */
	public function __construct( $base, $skip_dead_weight = true ) {
		$this->base             = rtrim( $base, '/\\' );
		$this->skip_dead_weight = (bool) $skip_dead_weight;
	}

	/**
	 * List the next page after $cursor.
	 *
	 * Each entry is either a file: { p, s, m } or a skipped item: { p, skip, s?, dir? }.
	 *
	 * @param string $cursor  Last relative path from the previous page ('' to start).
	 * @param int    $limit   Max entries in this page.
	 * @param float  $seconds Time budget for this page.
	 * @return array{entries: array<int,array<string,mixed>>, cursor: string, done: bool}
	 */
	public function page( $cursor, $limit, $seconds ) {
		$this->entries  = array();
		$this->last     = (string) $cursor;
		$this->stopped  = false;
		$this->limit    = max( 1, (int) $limit );
		$this->deadline = microtime( true ) + max( 0.1, (float) $seconds );

		$parts = '' === $cursor ? null : explode( '/', (string) $cursor );
		$this->walk( $this->base, '', $parts );

		return array(
			'entries' => $this->entries,
			'cursor'  => $this->last,
			'done'    => ! $this->stopped,
		);
	}

	/**
	 * Walk one directory.
	 *
	 * @param string                 $abs    Absolute directory path.
	 * @param string                 $prefix Relative prefix ('' or 'a/b/').
	 * @param array<int,string>|null $after  Remaining cursor parts to skip past, or null.
	 * @return void
	 */
	private function walk( $abs, $prefix, $after ) {
		$names = @scandir( $abs, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable dirs are reported, not fatal.
		if ( false === $names ) {
			if ( '' !== $prefix ) {
				$this->emit(
					array(
						'p'    => rtrim( $prefix, '/' ),
						'skip' => 'unreadable',
						'dir'  => 1,
					)
				);
			}
			return;
		}
		// Byte order, so the order never depends on locale.
		usort( $names, 'strcmp' );

		foreach ( $names as $name ) {
			if ( $this->stopped ) {
				return;
			}
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$descend_after = null;
			if ( null !== $after ) {
				$cmp = strcmp( $name, $after[0] );
				if ( $cmp < 0 ) {
					continue;
				}
				if ( 0 === $cmp ) {
					if ( count( $after ) > 1 ) {
						$descend_after = array_slice( $after, 1 );
					} else {
						// This exact entry was the last one returned.
						$after = null;
						continue;
					}
				}
				$after = null;
			}

			$rel  = $prefix . $name;
			$path = $abs . '/' . $name;

			if ( is_link( $path ) ) {
				if ( null === $descend_after ) {
					$this->emit(
						array(
							'p'    => $rel,
							'skip' => 'symlink',
						)
					);
				}
				continue;
			}

			if ( is_dir( $path ) ) {
				$reason = $this->skip_dead_weight ? Paths::dead_weight_reason( $rel, true ) : '';
				if ( '' !== $reason ) {
					if ( null === $descend_after ) {
						$this->emit(
							array(
								'p'    => $rel,
								'skip' => $reason,
								'dir'  => 1,
							)
						);
					}
					continue;
				}
				$this->walk( $path, $rel . '/', $descend_after );
				continue;
			}

			if ( null !== $descend_after || ! is_file( $path ) ) {
				continue;
			}

			$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$skip = Paths::blocked_reason( $rel );
			if ( '' === $skip && $this->skip_dead_weight ) {
				$skip = Paths::dead_weight_reason( $rel, false );
			}
			if ( '' === $skip && Paths::normalize_relative( $rel ) !== $rel ) {
				$skip = 'bad_name';
			}
			if ( '' === $skip && ! is_readable( $path ) ) {
				$skip = 'unreadable';
			}
			if ( '' !== $skip ) {
				$this->emit(
					array(
						'p'    => $rel,
						'skip' => $skip,
						's'    => $size,
					)
				);
				continue;
			}

			$this->emit(
				array(
					'p' => $rel,
					's' => $size,
					'm' => (int) @filemtime( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				)
			);
		}
	}

	/**
	 * Add an entry and stop when the page is full or out of time.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return void
	 */
	private function emit( array $entry ) {
		$this->entries[] = $entry;
		$this->last      = (string) $entry['p'];
		if ( count( $this->entries ) >= $this->limit || microtime( true ) >= $this->deadline ) {
			$this->stopped = true;
		}
	}
}
