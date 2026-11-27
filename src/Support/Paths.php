<?php
/**
 * Path validation and copy rules shared by both sides of a migration.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Support;

/**
 * Pure helpers: no filesystem writes.
 */
final class Paths {

	/**
	 * Suffix for partially downloaded files on the receiving site.
	 *
	 * @var string
	 */
	const PART_SUFFIX = '.iwiemg-part';

	/**
	 * Extensions that are never copied, in either direction.
	 * Uploads should only hold media; scripts there are almost always leftovers or malware.
	 *
	 * @var array<int,string>
	 */
	const EXECUTABLE_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'shtml' );

	/**
	 * Server configuration files that can change how the receiving server executes files.
	 *
	 * @var array<int,string>
	 */
	const SERVER_CONFIG_FILES = array( '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config' );

	/**
	 * Normalise and validate a relative path received from the other site.
	 *
	 * Returns null for anything that is not a plain, forward-slash, relative path
	 * made of real names (no "..", ".", empty parts, NUL bytes, backslashes, drive letters).
	 *
	 * @param mixed $path Untrusted path.
	 * @return string|null
	 */
	public static function normalize_relative( $path ) {
		if ( ! is_string( $path ) ) {
			return null;
		}
		$len = strlen( $path );
		if ( 0 === $len || $len > 1024 ) {
			return null;
		}
		if ( false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) || false !== strpos( $path, ':' ) ) {
			return null;
		}
		if ( '/' === $path[0] ) {
			return null;
		}
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return null;
			}
			if ( strlen( $part ) > 255 ) {
				return null;
			}
		}
		return implode( '/', $parts );
	}

	/**
	 * Lower-case extension of the last path part ('' when none).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function extension( $path ) {
		$base = basename( $path );
		$dot  = strrpos( $base, '.' );
		if ( false === $dot || 0 === $dot ) {
			return '';
		}
		return strtolower( substr( $base, $dot + 1 ) );
	}

	/**
	 * Reason a file must never be copied, whatever the options ('' when allowed).
	 *
	 * Checked on the giving site when listing files and again on the receiving
	 * site before anything is written, so a tampered giver cannot plant scripts.
	 *
	 * @param string $relative Relative path.
	 * @return string executable|server_config|part_file|''
	 */
	public static function blocked_reason( $relative ) {
		$base  = strtolower( basename( $relative ) );
		$lower = strtolower( $relative );
		if ( in_array( $base, self::SERVER_CONFIG_FILES, true ) ) {
			return 'server_config';
		}
		if ( substr( $lower, -strlen( self::PART_SUFFIX ) ) === self::PART_SUFFIX ) {
			return 'part_file';
		}
		if ( in_array( self::extension( $base ), self::EXECUTABLE_EXTENSIONS, true ) ) {
			return 'executable';
		}
		// Also refuse PHP hidden in a middle segment ("shell.php.jpg"), which some
		// misconfigured servers still execute. Only PHP names are checked here so
		// language variants such as "brochure.pl.pdf" are not caught.
		$segments = explode( '.', $base );
		array_shift( $segments );
		array_pop( $segments );
		foreach ( $segments as $segment ) {
			if ( 1 === preg_match( '/^(php\d?|phtml|pht|phar)$/', $segment ) ) {
				return 'executable';
			}
		}
		return '';
	}

	/**
	 * Reason a path counts as dead weight ('' when it is ordinary media).
	 *
	 * Narrower than the Health Check dead-weight rules on purpose: zip files and
	 * PDFs in uploads are often real downloads, so only clear backups, caches,
	 * logs, dumps and development folders are skipped.
	 *
	 * @param string $relative Relative path.
	 * @param bool   $is_dir   Whether the path is a directory.
	 * @return string backup|cache|vcs|log|dump|''
	 */
	public static function dead_weight_reason( $relative, $is_dir ) {
		$base = strtolower( basename( $relative ) );
		if ( $is_dir ) {
			$backup_dirs = array( 'updraft', 'ai1wm-backups', 'backupbuddy_backups', 'wpvividbackups', 'backups-dup-lite', 'backups-dup-pro', 'wp-migrate-db', 'wp-staging', 'snapshots' );
			if ( in_array( $base, $backup_dirs, true ) || 0 === strpos( $base, 'backup' ) || 0 === strpos( $base, 'backwpup-' ) ) {
				return 'backup';
			}
			if ( in_array( $base, array( 'cache', 'et-cache', 'wpo-cache', 'wp-rocket-cache', 'litespeed' ), true ) ) {
				return 'cache';
			}
			if ( in_array( $base, array( '.git', '.svn', '.hg', 'node_modules' ), true ) ) {
				return 'vcs';
			}
			return '';
		}
		if ( 'error_log' === $base || 'debug.log' === $base ) {
			return 'log';
		}
		$ext = self::extension( $base );
		if ( 'log' === $ext ) {
			return 'log';
		}
		if ( in_array( $ext, array( 'sql', 'wpress', 'bak', 'daf' ), true ) || '.sql.gz' === substr( $base, -7 ) ) {
			return 'dump';
		}
		if ( in_array( $ext, array( 'zip', 'gz', 'tgz', 'tar' ), true ) && false !== strpos( $base, 'backup' ) ) {
			return 'backup';
		}
		return '';
	}

	/**
	 * Whether $child (an existing real path) sits inside $base (a real path).
	 *
	 * @param string $child Real path.
	 * @param string $base  Real path of the base directory.
	 * @return bool
	 */
	public static function is_inside( $child, $base ) {
		$base  = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
		$child = str_replace( '\\', '/', $child );
		return 0 === strpos( $child . '/', $base );
	}

	/**
	 * Real path of this site's uploads directory, or '' when missing.
	 *
	 * @return string
	 */
	public static function uploads_base() {
		$dirs = wp_upload_dir( null, false );
		if ( ! empty( $dirs['error'] ) || empty( $dirs['basedir'] ) ) {
			return '';
		}
		$real = realpath( (string) $dirs['basedir'] );
		return false === $real ? '' : $real;
	}
}
