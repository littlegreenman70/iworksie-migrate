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
	 * Suffix for a destination file moved aside during Replace (fixed; no random part).
	 *
	 * @var string
	 */
	const OLD_SUFFIX = '.iwiemg-old';

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
		if ( substr( $lower, -strlen( self::OLD_SUFFIX ) ) === self::OLD_SUFFIX ) {
			return 'part_file';
		}
		// Leftover aside files from builds that used a random 8-char suffix.
		if ( 1 === preg_match( '/\.iwiemg-old-[a-z0-9]{8}$/', $lower ) ) {
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
	 * Why a path must not be read as a media file ('' when safe).
	 *
	 * Refuses symlinks and hardlinks (nlink > 1). A hardlink under uploads that
	 * shares an inode with a file outside uploads would otherwise pass realpath
	 * and is_inside checks and leak that file over the giver API.
	 *
	 * @param string $path Absolute path as requested (the uploads join, not yet realpath'd).
	 * @param string $base Real path of the uploads base directory.
	 * @return string symlink|hardlink|not_regular|missing|''
	 */
	public static function unsafe_read_reason( $path, $base ) {
		if ( is_link( $path ) ) {
			return 'symlink';
		}
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing paths are reported, not fatal.
		if ( ! is_array( $stat ) ) {
			return 'missing';
		}
		// Regular file only (S_IFREG = 0100000).
		if ( ! isset( $stat['mode'] ) || ( (int) $stat['mode'] & 0170000 ) !== 0100000 ) {
			return 'not_regular';
		}
		if ( isset( $stat['nlink'] ) && (int) $stat['nlink'] > 1 ) {
			/**
			 * Whether hardlinked files under uploads may be listed and served.
			 *
			 * Default false. Some hosts hard-link uploads for deduplicating backups;
			 * those setups can return true deliberately.
			 *
			 * @param bool   $allow Default false.
			 * @param string $path  Absolute path being checked.
			 */
			$allow = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'iwiemg_allow_hardlinks', false, $path ) : false;
			if ( ! $allow ) {
				return 'hardlink';
			}
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! self::is_inside( $real, $base ) ) {
			return 'missing';
		}
		return '';
	}

	/**
	 * Effective PHP_INT_SIZE (filterable for tests).
	 *
	 * @return int
	 */
	public static function php_int_size() {
		$size = PHP_INT_SIZE;
		if ( function_exists( 'apply_filters' ) ) {
			$size = (int) apply_filters( 'iwiemg_php_int_size', $size );
		}
		return $size;
	}

	/**
	 * Largest addressable file size for the effective int size, as digit string.
	 *
	 * @return string
	 */
	public static function php_int_max_digits() {
		return self::php_int_size() < 8 ? '2147483647' : (string) PHP_INT_MAX;
	}

	/**
	 * Whether a size (int, float or digit string) is greater than this PHP's int max.
	 *
	 * @param mixed $size Size value from a listing entry.
	 * @return bool
	 */
	public static function size_exceeds_int_max( $size ) {
		if ( is_int( $size ) || is_float( $size ) ) {
			$digits = sprintf( '%.0f', (float) $size );
		} else {
			$digits = preg_replace( '/\D/', '', (string) $size );
		}
		if ( ! is_string( $digits ) || '' === $digits ) {
			return false;
		}
		$max = self::php_int_max_digits();
		$a   = ltrim( $digits, '0' );
		$b   = ltrim( $max, '0' );
		if ( '' === $a ) {
			$a = '0';
		}
		if ( '' === $b ) {
			$b = '0';
		}
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) > strlen( $b );
		}
		return strcmp( $a, $b ) > 0;
	}

	/**
	 * Whether a string is already Unicode NFC (or cannot be checked).
	 *
	 * Detect-only: callers may warn, but must not rewrite names.
	 *
	 * @param string $value Path segment or relative path.
	 * @return bool True when Normalizer is missing or the value is already NFC.
	 */
	public static function is_nfc( $value ) {
		if ( ! class_exists( '\Normalizer' ) ) {
			return true;
		}
		$normalized = \Normalizer::normalize( (string) $value, \Normalizer::FORM_C );
		if ( false === $normalized ) {
			return true;
		}
		return $normalized === (string) $value;
	}

	/**
	 * Whether a host/URL points at a private, loopback or link-local address.
	 *
	 * Used for a pre-flight notice only; the client still allows these targets
	 * so LAN and local migrations keep working.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function url_is_private( $url ) {
		// Native parse_url so unit tests without a WP stub can call this helper.
		$host = parse_url( (string) $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- host-only check; no WP scheme normalisation needed.
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$lower = strtolower( $host );
		if ( 'localhost' === $lower || '.local' === substr( $lower, -6 ) || '.test' === substr( $lower, -5 ) ) {
			return true;
		}
		if ( self::ip_is_private( $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$resolved = gethostbyname( $host );
		if ( ! is_string( $resolved ) || $resolved === $host ) {
			return false;
		}
		return self::ip_is_private( $resolved );
	}

	/**
	 * Whether an IP string is private or reserved.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function ip_is_private( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Read a file size safely for this PHP build.
	 *
	 * On 32-bit PHP, files at or above 2 GB cannot be addressed reliably; return null.
	 * filesize() on those builds can wrap to a negative or a small positive, so a probe
	 * seeks near 2 GB and checks whether two more bytes can be read.
	 *
	 * @param string $path Absolute path.
	 * @return int|null Size in bytes, or null when unknown / too large for this PHP.
	 */
	public static function safe_filesize( $path ) {
		clearstatcache( true, $path );
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size ) {
			return null;
		}
		if ( self::php_int_size() < 8 ) {
			// On real 32-bit PHP, oversized files can wrap filesize() to a negative int.
			$as_int = (int) $size;
			// PHPStan on 64-bit hosts thinks filesize() is always non-negative; keep the check for 32-bit.
			if ( ( $as_int < 0 ) || ! self::probe_under_2gb( $path ) ) { // @phpstan-ignore smaller.alwaysFalse
				return null;
			}
		}
		return (int) $size;
	}

	/**
	 * Whether a file is smaller than 2 GB (probe used on 32-bit PHP).
	 *
	 * @param string $path Absolute path.
	 * @return bool True when under 2 GB (or unreadable in a way that looks small).
	 */
	public static function probe_under_2gb( $path ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- ranged size probe; WP_Filesystem has no fseek.
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}
		$int_max = (int) self::php_int_max_digits();
		$seek_ok = 0 === @fseek( $handle, $int_max - 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$bytes   = $seek_ok ? @fread( $handle, 2 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		if ( ! $seek_ok ) {
			return false;
		}
		if ( false === $bytes ) {
			return false;
		}
		// Two bytes means the file reaches at least 2 GB.
		return 2 !== strlen( $bytes );
	}

	/**
	 * Real path of this site's uploads directory, or '' when missing.
	 *
	 * @param bool $create Whether to let WordPress create the folder (wp_upload_dir default).
	 * @return string
	 */
	public static function uploads_base( $create = false ) {
		$dirs = wp_upload_dir( null, (bool) $create );
		if ( ! empty( $dirs['error'] ) || empty( $dirs['basedir'] ) ) {
			return '';
		}
		$real = realpath( (string) $dirs['basedir'] );
		return false === $real ? '' : $real;
	}

	/**
	 * Uploads basedir as a path relative to ABSPATH when possible, else the absolute path.
	 *
	 * @param string $basedir Absolute basedir from wp_upload_dir().
	 * @return string
	 */
	public static function uploads_display_path( $basedir ) {
		$basedir = wp_normalize_path( (string) $basedir );
		if ( defined( 'ABSPATH' ) ) {
			$root = trailingslashit( wp_normalize_path( ABSPATH ) );
			if ( 0 === strpos( $basedir, $root ) ) {
				$rel = substr( $basedir, strlen( $root ) );
				return '' === $rel ? $basedir : $rel;
			}
		}
		return $basedir;
	}
}
