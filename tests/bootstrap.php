<?php
/**
 * PHPUnit bootstrap.
 *
 * @package IWorksIE\Migrate
 */

$iwiemg_root = dirname( __DIR__ );

require_once $iwiemg_root . '/vendor/autoload.php';

$iwiemg_prefixed = $iwiemg_root . '/vendor-prefixed/autoload.php';
if ( is_readable( $iwiemg_prefixed ) ) {
	require_once $iwiemg_prefixed;
}

$iwiemg_suite = getenv( 'IWIEMG_TESTSUITE' );
if ( ! $iwiemg_suite ) {
	$iwiemg_suite = 'unit';
}

if ( 'integration' === $iwiemg_suite ) {
	$iwiemg_wp_tests = getenv( 'WP_TESTS_DIR' );
	if ( ! $iwiemg_wp_tests ) {
		$iwiemg_wp_tests = $iwiemg_root . '/tests/wordpress/wordpress-tests-lib';
	}
	if ( ! is_readable( $iwiemg_wp_tests . '/includes/functions.php' ) ) {
		fwrite( STDERR, "WP test suite not found at {$iwiemg_wp_tests}. Run bin/install-wp-tests.sh first.\n" );
		exit( 1 );
	}
	require_once $iwiemg_wp_tests . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $iwiemg_root ) {
			$plugin = $iwiemg_root . '/iworksie-migrate.php';
			if ( is_readable( $plugin ) ) {
				require $plugin;
			}
			$ex1 = dirname( $iwiemg_root ) . '/iworks-tools-core/examples/iworksie-example-one/iworksie-example-one.php';
			$ex2 = dirname( $iwiemg_root ) . '/iworks-tools-core/examples/iworksie-example-two/iworksie-example-two.php';
			if ( is_readable( $ex1 ) ) {
				require $ex1;
			}
			if ( is_readable( $ex2 ) ) {
				require $ex2;
			}
		}
	);

	require_once $iwiemg_wp_tests . '/includes/bootstrap.php';
}
