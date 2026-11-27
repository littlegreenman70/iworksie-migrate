<?php
/**
 * Plugin AJAX endpoints for the admin screens.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Admin;

use IWorksIE\Migrate\Giver\KeyStore;
use IWorksIE\Migrate\Plugin;
use IWorksIE\Migrate\Receiver\PullJob;
use IWorksIE\Migrate\Receiver\Preflight;
use IWorksIE\Migrate\Receiver\ReportBuilder;
use IWorksIE\Migrate\Vendor\IWorksIE\Core\UI;

/**
 * Key management, pre-flight and job status.
 */
final class Ajax {

	const NONCE = 'iwiemg_admin';

	/**
	 * @return void
	 */
	public function hooks() {
		foreach ( array( 'preflight', 'key_create', 'key_revoke', 'pull_status', 'pull_cleanup', 'forget_key' ) as $action ) {
			add_action( 'wp_ajax_iwiemg_' . $action, array( $this, $action ) );
		}
	}

	/**
	 * Nonce + capability.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'iworksie-migrate' ) ), 403 );
		}
	}

	/**
	 * Check a pasted key and both sites. Stores the key only when everything passes.
	 *
	 * @return void
	 */
	public function preflight() {
		$this->guard();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard().
		if ( $this->job_running() ) {
			wp_send_json_error( array( 'message' => __( 'A copy is already running. Cancel it first.', 'iworksie-migrate' ) ), 409 );
		}
		$check = Preflight::run( $key );
		if ( $check['ok'] ) {
			update_option( PullJob::KEY_OPTION, preg_replace( '/\s+/', '', $key ), false );
		}
		$html = '';
		foreach ( $check['results'] as $result ) {
			$html .= UI::result_card( $result, 'critical' === $result->status() );
		}
		wp_send_json_success(
			array(
				'ok'    => $check['ok'],
				'html'  => $html,
				'giver' => isset( $check['giver']['home'] ) ? esc_url_raw( (string) $check['giver']['home'] ) : '',
			)
		);
	}

	/**
	 * Create a key on this (giving) site.
	 *
	 * @return void
	 */
	public function key_create() {
		$this->guard();
		$lifetime = isset( $_POST['lifetime'] ) ? sanitize_key( wp_unslash( (string) $_POST['lifetime'] ) ) : '24h'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard().
		if ( ! array_key_exists( $lifetime, KeyStore::lifetimes() ) ) {
			$lifetime = '24h';
		}
		$key    = KeyStore::create( $lifetime );
		$stored = KeyStore::stored();
		wp_send_json_success(
			array(
				'key'     => $key,
				'expires' => null === $stored ? '' : Screens::expires_label( (int) $stored['expires'] ),
			)
		);
	}

	/**
	 * Revoke this site's key.
	 *
	 * @return void
	 */
	public function key_revoke() {
		$this->guard();
		KeyStore::revoke();
		wp_send_json_success( array() );
	}

	/**
	 * Forget the key pasted on this (receiving) site.
	 *
	 * @return void
	 */
	public function forget_key() {
		$this->guard();
		if ( $this->job_running() ) {
			wp_send_json_error( array( 'message' => __( 'A copy is running. Cancel it first.', 'iworksie-migrate' ) ), 409 );
		}
		delete_option( PullJob::KEY_OPTION );
		wp_send_json_success( array() );
	}

	/**
	 * Detailed job status for the live counters.
	 *
	 * @return void
	 */
	public function pull_status() {
		$this->guard();
		wp_send_json_success( self::status_payload() );
	}

	/**
	 * Remove a half-downloaded file after Cancel.
	 *
	 * @return void
	 */
	public function pull_cleanup() {
		$this->guard();
		$core  = Plugin::instance()->core();
		$state = null === $core ? null : $core->jobs()->load_state( PullJob::ID );
		if ( is_array( $state ) && ! empty( $state['cancelled'] ) ) {
			$job = new PullJob();
			$job->cleanup_part( $state );
			$state['cur']   = null;
			$state['queue'] = array();
			$core->jobs()->save_state( PullJob::ID, $state );
		}
		wp_send_json_success( array() );
	}

	/**
	 * Whether a pull is currently in progress (not finished, cancelled or stale).
	 *
	 * @return bool
	 */
	private function job_running() {
		$core  = Plugin::instance()->core();
		$state = null === $core ? null : $core->jobs()->load_state( PullJob::ID );
		return is_array( $state ) && empty( $state['done'] ) && empty( $state['cancelled'] ) && ! $core->jobs()->is_stale( $state );
	}

	/**
	 * Status array used by AJAX and the first page render.
	 *
	 * @return array<string,mixed>
	 */
	public static function status_payload() {
		$core  = Plugin::instance()->core();
		$state = null === $core ? null : $core->jobs()->load_state( PullJob::ID );
		if ( ! is_array( $state ) ) {
			return array( 'exists' => false );
		}
		$c       = isset( $state['c'] ) ? (array) $state['c'] : array();
		$payload = array(
			'exists'       => true,
			'done'         => ! empty( $state['done'] ),
			'cancelled'    => ! empty( $state['cancelled'] ),
			'paused'       => ! empty( $state['paused'] ),
			'net_failures' => isset( $state['net_failures'] ) ? (int) $state['net_failures'] : 0,
			'phase'        => isset( $state['phase'] ) ? (string) $state['phase'] : '',
			'progress'     => isset( $state['progress'] ) ? (int) $state['progress'] : 0,
			'message'      => isset( $state['message'] ) ? (string) $state['message'] : '',
			'stats'        => array(
				'copied'  => number_format_i18n( (int) ( isset( $c['copied'] ) ? $c['copied'] : 0 ) ),
				'bytes'   => size_format( (int) ( isset( $c['copied_bytes'] ) ? $c['copied_bytes'] : 0 ), 1 ),
				'same'    => number_format_i18n( (int) ( isset( $c['same'] ) ? $c['same'] : 0 ) ),
				'kept'    => number_format_i18n( (int) ( isset( $c['kept'] ) ? $c['kept'] : 0 ) ),
				'skipped' => number_format_i18n( (int) ( isset( $c['skipped'] ) ? $c['skipped'] : 0 ) ),
				'errors'  => number_format_i18n( (int) ( isset( $c['errors'] ) ? $c['errors'] : 0 ) ),
			),
		);
		if ( ! empty( $state['done'] ) && empty( $state['cancelled'] ) && isset( $state['result'] ) && is_array( $state['result'] ) ) {
			$payload['status']   = ReportBuilder::status( $state['result'] );
			$payload['headline'] = ReportBuilder::headline( $state['result'] );
			$payload['failed']   = isset( $state['result']['failed_fix'] ) ? (string) $state['result']['failed_fix'] : '';
		}
		return $payload;
	}
}
