<?php
/**
 * Plain-text / Markdown report for a finished copy.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Receiver;

/**
 * Turns a PullJob summary into a readable report.
 */
final class ReportBuilder {

	/**
	 * Headline status for a summary.
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @return string good|recommended|critical
	 */
	public static function status( array $summary ) {
		$c = isset( $summary['c'] ) ? (array) $summary['c'] : array();
		if ( '' !== (string) ( isset( $summary['failed'] ) ? $summary['failed'] : '' ) ) {
			return 'critical';
		}
		if ( ! empty( $c['mismatched'] ) || ! empty( $c['missing'] ) || ! empty( $c['errors'] ) ) {
			return 'recommended';
		}
		return 'good';
	}

	/**
	 * One-line outcome.
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @return string
	 */
	public static function headline( array $summary ) {
		$c = isset( $summary['c'] ) ? (array) $summary['c'] : array();
		if ( '' !== (string) ( isset( $summary['failed'] ) ? $summary['failed'] : '' ) ) {
			return __( 'The copy stopped before it finished.', 'iworksie-migrate' );
		}
		$copied        = (int) ( isset( $c['copied'] ) ? $c['copied'] : 0 );
		$same          = (int) ( isset( $c['same'] ) ? $c['same'] : 0 );
		$copied_phrase = sprintf(
			/* translators: 1: number of files, 2: total size */
			_n( 'Copied %1$s file (%2$s).', 'Copied %1$s files (%2$s).', $copied, 'iworksie-migrate' ),
			number_format_i18n( $copied ),
			size_format( (int) ( isset( $c['copied_bytes'] ) ? $c['copied_bytes'] : 0 ), 1 )
		);
		$same_phrase = sprintf(
			/* translators: %s: number of files */
			_n( '%s file was already identical and was skipped.', '%s files were already identical and were skipped.', $same, 'iworksie-migrate' ),
			number_format_i18n( $same )
		);
		$text = $copied_phrase . ' ' . $same_phrase;
		if ( ! empty( $summary['opts']['verify'] ) ) {
			$verified = (int) ( isset( $c['verified'] ) ? $c['verified'] : 0 );
			$problems = (int) ( isset( $c['mismatched'] ) ? $c['mismatched'] : 0 ) + (int) ( isset( $c['missing'] ) ? $c['missing'] : 0 );
			$text    .= ' ' . ( 0 === $problems
				? sprintf(
					/* translators: %s: number of files */
					_n( '%s file was verified against the other site.', 'All %s files were verified against the other site.', $verified, 'iworksie-migrate' ),
					number_format_i18n( $verified )
				)
				: sprintf(
					/* translators: %s: number of files */
					_n( '%s file did not match the other site after copying.', '%s files did not match the other site after copying.', $problems, 'iworksie-migrate' ),
					number_format_i18n( $problems )
				) );
		}
		return $text;
	}

	/**
	 * Human label for a skip reason.
	 *
	 * @param string $reason Reason key.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$labels = array(
			'executable'    => __( 'script file (never copied, for safety)', 'iworksie-migrate' ),
			'server_config' => __( 'server config file such as .htaccess (never copied, for safety)', 'iworksie-migrate' ),
			'part_file'     => __( 'unfinished download', 'iworksie-migrate' ),
			'symlink'       => __( 'symbolic link', 'iworksie-migrate' ),
			'hardlink'      => __( 'hard link (never copied, for safety)', 'iworksie-migrate' ),
			'not_regular'   => __( 'not a regular file (skipped for safety)', 'iworksie-migrate' ),
			'too_large'     => __( 'larger than this PHP build can address (needs 64-bit PHP for files over about 2 GB)', 'iworksie-migrate' ),
			'unreadable'    => __( 'unreadable on the other site', 'iworksie-migrate' ),
			'bad_name'      => __( 'unsupported file name', 'iworksie-migrate' ),
			'backup'        => __( 'backup', 'iworksie-migrate' ),
			'cache'         => __( 'cache', 'iworksie-migrate' ),
			'vcs'           => __( 'development folder', 'iworksie-migrate' ),
			'log'           => __( 'log file', 'iworksie-migrate' ),
			'dump'          => __( 'database dump or archive', 'iworksie-migrate' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	/**
	 * Full Markdown report.
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @return string
	 */
	public static function markdown( array $summary ) {
		$c     = isset( $summary['c'] ) ? (array) $summary['c'] : array();
		$t     = isset( $summary['t'] ) ? (array) $summary['t'] : array();
		$lists = isset( $summary['lists'] ) ? (array) $summary['lists'] : array();
		$opts  = isset( $summary['opts'] ) ? (array) $summary['opts'] : array();
		$num   = static function ( $key ) use ( $c ) {
			return number_format_i18n( (int) ( isset( $c[ $key ] ) ? $c[ $key ] : 0 ) );
		};

		$lines   = array();
		$lines[] = '# ' . __( 'iWorks.ie Migrate report', 'iworksie-migrate' );
		$lines[] = '';
		$lines[] = '- ' . __( 'From:', 'iworksie-migrate' ) . ' ' . ( isset( $summary['giver'] ) ? (string) $summary['giver'] : '' );
		$lines[] = '- ' . __( 'To:', 'iworksie-migrate' ) . ' ' . ( isset( $summary['receiver'] ) ? (string) $summary['receiver'] : '' );
		if ( ! empty( $summary['started'] ) ) {
			$lines[] = '- ' . __( 'Started:', 'iworksie-migrate' ) . ' ' . wp_date( 'Y-m-d H:i:s T', (int) $summary['started'] );
		}
		if ( ! empty( $summary['finished'] ) ) {
			$lines[] = '- ' . __( 'Finished:', 'iworksie-migrate' ) . ' ' . wp_date( 'Y-m-d H:i:s T', (int) $summary['finished'] );
		}
		$lines[] = '- ' . __( 'Options:', 'iworksie-migrate' ) . ' '
			. ( ! empty( $opts['replace'] ) ? __( 'replace different files', 'iworksie-migrate' ) : __( 'keep different files already here', 'iworksie-migrate' ) ) . ', '
			. ( ! empty( $opts['skip_dead_weight'] ) ? __( 'skip backups/caches/logs', 'iworksie-migrate' ) : __( 'include backups/caches/logs', 'iworksie-migrate' ) ) . ', '
			. ( ! empty( $opts['verify'] ) ? __( 'verify', 'iworksie-migrate' ) : __( 'no verification', 'iworksie-migrate' ) );
		$lines[] = '';
		$lines[] = '## ' . __( 'Result', 'iworksie-migrate' );
		$lines[] = '';
		$lines[] = self::headline( $summary );
		if ( ! empty( $summary['failed'] ) ) {
			$lines[] = '';
			$lines[] = '**' . __( 'Stopped:', 'iworksie-migrate' ) . '** ' . (string) $summary['failed'];
			if ( ! empty( $summary['failed_fix'] ) ) {
				$lines[] = '**' . __( 'What to do:', 'iworksie-migrate' ) . '** ' . (string) $summary['failed_fix'];
			}
		}
		$nfc = isset( $summary['nfc_names'] ) ? (int) $summary['nfc_names'] : 0;
		if ( $nfc > 0 ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: number of file names */
				_n(
					'%s file name uses a different Unicode form (common when files were uploaded from a Mac). It was copied with its exact name.',
					'%s file names use a different Unicode form (common when files were uploaded from a Mac). They were copied with their exact names.',
					$nfc,
					'iworksie-migrate'
				),
				number_format_i18n( $nfc )
			);
		}
		$lines[] = '';
		$lines[] = '| | ' . __( 'Files', 'iworksie-migrate' ) . ' |';
		$lines[] = '|---|---|';
		$lines[] = '| ' . __( 'On the other site (after skips)', 'iworksie-migrate' ) . ' | ' . number_format_i18n( (int) ( isset( $t['files'] ) ? $t['files'] : 0 ) ) . ' (' . size_format( (int) ( isset( $t['bytes'] ) ? $t['bytes'] : 0 ), 1 ) . ') |';
		$lines[] = '| ' . __( 'Copied', 'iworksie-migrate' ) . ' | ' . $num( 'copied' ) . ' |';
		$lines[] = '| ' . __( 'Of which replaced a different file', 'iworksie-migrate' ) . ' | ' . $num( 'replaced' ) . ' |';
		$lines[] = '| ' . __( 'Already identical, skipped', 'iworksie-migrate' ) . ' | ' . $num( 'same' ) . ' |';
		$lines[] = '| ' . __( 'Different file already here, kept', 'iworksie-migrate' ) . ' | ' . $num( 'kept' ) . ' |';
		$lines[] = '| ' . __( 'Skipped (safety / dead weight)', 'iworksie-migrate' ) . ' | ' . $num( 'skipped' ) . ' |';
		$lines[] = '| ' . __( 'Errors', 'iworksie-migrate' ) . ' | ' . $num( 'errors' ) . ' |';
		if ( ! empty( $opts['verify'] ) ) {
			$lines[] = '| ' . __( 'Verified (SHA-1 match)', 'iworksie-migrate' ) . ' | ' . $num( 'verified' ) . ' |';
			$lines[] = '| ' . __( 'Did not match', 'iworksie-migrate' ) . ' | ' . $num( 'mismatched' ) . ' |';
			$lines[] = '| ' . __( 'Missing after copy', 'iworksie-migrate' ) . ' | ' . $num( 'missing' ) . ' |';
		}

		$sections = array(
			'errors'     => __( 'Errors', 'iworksie-migrate' ),
			'mismatched' => __( 'Did not match after copying', 'iworksie-migrate' ),
			'missing'    => __( 'Missing after copying', 'iworksie-migrate' ),
			'kept'       => __( 'Different file already on this site (kept; tick "Replace" and run again to overwrite)', 'iworksie-migrate' ),
			'replaced'   => __( 'Replaced', 'iworksie-migrate' ),
			'skipped'    => __( 'Skipped', 'iworksie-migrate' ),
		);
		foreach ( $sections as $key => $title ) {
			$items = isset( $lists[ $key ] ) && is_array( $lists[ $key ] ) ? $lists[ $key ] : array();
			if ( empty( $items ) ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '## ' . $title;
			$lines[] = '';
			foreach ( $items as $item ) {
				$line = '- ' . ( isset( $item['p'] ) ? (string) $item['p'] : '' );
				if ( ! empty( $item['dir'] ) ) {
					$line .= '/';
				}
				if ( isset( $item['r'] ) ) {
					$line .= ' (' . self::reason_label( (string) $item['r'] );
					if ( isset( $item['s'] ) && (int) $item['s'] >= 0 && empty( $item['dir'] ) ) {
						$line .= ', ' . size_format( (int) $item['s'], (int) $item['s'] >= 1024 ? 1 : 0 );
					}
					$line .= ')';
				}
				if ( isset( $item['e'] ) ) {
					$line .= ': ' . (string) $item['e'];
				}
				$lines[] = $line;
			}
			$total = (int) ( isset( $c[ $key ] ) ? $c[ $key ] : count( $items ) );
			if ( $total > count( $items ) ) {
				/* translators: %s: number of further items */
				$lines[] = '- ' . sprintf( __( '…and %s more', 'iworksie-migrate' ), number_format_i18n( $total - count( $items ) ) );
			}
		}

		$lines[] = '';
		$lines[] = '## ' . __( 'Next steps', 'iworksie-migrate' );
		$lines[] = '';
		$lines[] = '- ' . __( 'This copies files only. Media Library entries live in the database: move the database first (or afterwards) with your usual tool.', 'iworksie-migrate' );
		$lines[] = '- ' . __( 'If the site address changed, update old URLs in the database, for example with the free iWorks.ie Search & Replace plugin ("Change domain").', 'iworksie-migrate' );
		$lines[] = '- ' . __( 'Delete or let expire the migration key on the other site when you are done.', 'iworksie-migrate' );
		$lines[] = '';
		$lines[] = '_' . __( 'Generated by iWorks.ie Migrate. Need a custom plugin? iWorks builds them: https://www.iworks.ie', 'iworksie-migrate' ) . '_';

		return implode( "\n", $lines ) . "\n";
	}
}
