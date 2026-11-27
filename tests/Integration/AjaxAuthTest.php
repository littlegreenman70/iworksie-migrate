<?php
/**
 * Admin AJAX guards.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Integration;

use IWorksIE\Migrate\Giver\KeyStore;
use WP_Ajax_UnitTestCase;

/**
 * Nonce and capability checks.
 */
final class AjaxAuthTest extends WP_Ajax_UnitTestCase {

	public function test_key_create_rejects_bad_nonce() {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = 'bad';
		try {
			$this->_handleAjax( 'iwiemg_key_create' );
			$this->fail( 'Expected die' );
		} catch ( \WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
		$this->assertNull( KeyStore::stored() );
	}

	public function test_key_create_rejects_editor() {
		$this->_setRole( 'editor' );
		$_POST['nonce'] = wp_create_nonce( 'iwiemg_admin' );
		try {
			$this->_handleAjax( 'iwiemg_key_create' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}
		$this->assertNull( KeyStore::stored() );
	}

	public function test_admin_can_create_key() {
		$this->_setRole( 'administrator' );
		$_POST['nonce']    = wp_create_nonce( 'iwiemg_admin' );
		$_POST['lifetime'] = '1h';
		try {
			$this->_handleAjax( 'iwiemg_key_create' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertStringStartsWith( 'iwiemg1.', $response['data']['key'] );
		$stored = KeyStore::stored();
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, (int) $stored['expires'] );
		KeyStore::revoke();
	}

	public function test_preflight_rejects_garbage_key_and_stores_nothing() {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = wp_create_nonce( 'iwiemg_admin' );
		$_POST['key']   = 'not a key';
		try {
			$this->_handleAjax( 'iwiemg_preflight' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['ok'] );
		$this->assertFalse( get_option( 'iwiemg_receiver_key', false ) );
	}
}
