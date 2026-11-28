<?php
/**
 * Uninstall cleanup for iWorks.ie Migrate.
 *
 * @package IWorksIE\Migrate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$iwiemg_prefix = 'iwiemg_';

// Remove a half-downloaded file left by an unfinished copy.
$iwiemg_job = get_option( 'iwiemg_job_migrate_pull' );
if ( is_array( $iwiemg_job ) && ! empty( $iwiemg_job['cur']['p'] ) && is_string( $iwiemg_job['cur']['p'] ) ) {
	$iwiemg_uploads = wp_upload_dir( null, false );
	$iwiemg_rel     = $iwiemg_job['cur']['p'];
	if ( empty( $iwiemg_uploads['error'] ) && false === strpos( $iwiemg_rel, '..' ) && false === strpos( $iwiemg_rel, "\0" ) ) {
		$iwiemg_part = trailingslashit( $iwiemg_uploads['basedir'] ) . $iwiemg_rel . '.iwiemg-part';
		if ( file_exists( $iwiemg_part ) ) {
			wp_delete_file( $iwiemg_part );
		}
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$iwiemg_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $iwiemg_prefix ) . '%'
	)
);

if ( is_array( $iwiemg_options ) ) {
	foreach ( $iwiemg_options as $iwiemg_option ) {
		delete_option( $iwiemg_option );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$iwiemg_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_' . $iwiemg_prefix ) . '%',
		$wpdb->esc_like( '_transient_timeout_' . $iwiemg_prefix ) . '%'
	)
);

if ( is_array( $iwiemg_transients ) ) {
	foreach ( $iwiemg_transients as $iwiemg_transient_option ) {
		if ( 0 === strpos( $iwiemg_transient_option, '_transient_timeout_' ) ) {
			$iwiemg_key = substr( $iwiemg_transient_option, strlen( '_transient_timeout_' ) );
			delete_transient( $iwiemg_key );
			continue;
		}
		if ( 0 === strpos( $iwiemg_transient_option, '_transient_' ) ) {
			$iwiemg_key = substr( $iwiemg_transient_option, strlen( '_transient_' ) );
			delete_transient( $iwiemg_key );
		}
	}
}

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$iwiemg_site_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $iwiemg_prefix ) . '%'
		)
	);
	if ( is_array( $iwiemg_site_options ) ) {
		foreach ( $iwiemg_site_options as $iwiemg_site_option ) {
			delete_site_option( $iwiemg_site_option );
		}
	}
}
