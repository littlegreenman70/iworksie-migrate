<?php
/**
 * PHPStan bootstrap stubs.
 *
 * @package IWorksIE\Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'IWIEMG_VERSION' ) ) {
	define( 'IWIEMG_VERSION', '1.0.0' );
}
if ( ! defined( 'IWIEMG_FILE' ) ) {
	define( 'IWIEMG_FILE', '/tmp/iworksie-migrate/iworksie-migrate.php' );
}
if ( ! defined( 'IWIEMG_PATH' ) ) {
	define( 'IWIEMG_PATH', '/tmp/iworksie-migrate/' );
}
if ( ! defined( 'IWIEMG_URL' ) ) {
	define( 'IWIEMG_URL', 'https://example.com/wp-content/plugins/iworksie-migrate/' );
}
if ( ! defined( 'IWIEMG_MIN_PHP' ) ) {
	define( 'IWIEMG_MIN_PHP', '7.4' );
}
if ( ! defined( 'IWIEMG_MIN_WP' ) ) {
	define( 'IWIEMG_MIN_WP', '6.5' );
}

if ( ! class_exists( '\IWorksIE\Migrate\Vendor\IWorksIE\Core\Core' ) && is_readable( dirname( __DIR__ ) . '/vendor-prefixed/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
}
