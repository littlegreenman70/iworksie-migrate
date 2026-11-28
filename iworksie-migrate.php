<?php
/**
 * Plugin Name:       iWorks.ie Migrate
 * Plugin URI:        https://www.iworks.ie
 * Description:       Copy your Media Library straight from one WordPress site to another. No archive, no third party, resumable, with a verification report.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            iWorks.ie
 * Author URI:        https://www.iworks.ie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       iworksie-migrate
 * Domain Path:       /languages
 *
 * @package IWorksIE\Migrate
 */

defined( 'ABSPATH' ) || exit;

define( 'IWIEMG_VERSION', '1.0.0' );
define( 'IWIEMG_FILE', __FILE__ );
define( 'IWIEMG_PATH', plugin_dir_path( __FILE__ ) );
define( 'IWIEMG_URL', plugin_dir_url( __FILE__ ) );
define( 'IWIEMG_MIN_PHP', '7.4' );
define( 'IWIEMG_MIN_WP', '6.5' );

/**
 * Admin notice when requirements are not met.
 *
 * @param string $message Notice text.
 * @return void
 */
function iwiemg_requirements_notice( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	);
}

if ( version_compare( PHP_VERSION, IWIEMG_MIN_PHP, '<' ) ) {
	iwiemg_requirements_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'iWorks.ie Migrate requires PHP %1$s or higher. This site is running PHP %2$s.', 'iworksie-migrate' ),
			IWIEMG_MIN_PHP,
			PHP_VERSION
		)
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, IWIEMG_MIN_WP, '<' ) ) {
	iwiemg_requirements_notice(
		sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'iWorks.ie Migrate requires WordPress %1$s or higher. This site is running WordPress %2$s.', 'iworksie-migrate' ),
			IWIEMG_MIN_WP,
			$wp_version
		)
	);
	return;
}

$iwiemg_prefixed = IWIEMG_PATH . 'vendor-prefixed/autoload.php';
if ( ! is_readable( $iwiemg_prefixed ) ) {
	iwiemg_requirements_notice(
		__( 'iWorks.ie Migrate is missing its vendor-prefixed library. Run composer install, or install the release zip from wordpress.org.', 'iworksie-migrate' )
	);
	return;
}
require_once $iwiemg_prefixed;

$iwiemg_autoload = IWIEMG_PATH . 'vendor/autoload.php';
if ( is_readable( $iwiemg_autoload ) ) {
	require_once $iwiemg_autoload;
} else {
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'IWorksIE\\Migrate\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = IWIEMG_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\IWorksIE\Migrate\Vendor\IWorksIE\Core\Core' ) ) {
			iwiemg_requirements_notice(
				__( 'iWorks.ie Migrate could not load its shared tools library.', 'iworksie-migrate' )
			);
			return;
		}
		if ( is_multisite() ) {
			iwiemg_requirements_notice(
				__( 'iWorks.ie Migrate does not support multisite networks yet, so it has not loaded. It works on single WordPress sites.', 'iworksie-migrate' )
			);
			return;
		}
		// Boot after init so __() / translate() are not called too early (WP 6.7+).
		add_action(
			'init',
			static function () {
				\IWorksIE\Migrate\Plugin::instance()->boot();
			},
			5
		);
	},
	5
);
