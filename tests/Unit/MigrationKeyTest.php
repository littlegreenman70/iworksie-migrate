<?php
/**
 * Key encoding and signatures.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use IWorksIE\Migrate\Support\MigrationKey;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IWorksIE\Migrate\Support\MigrationKey
 */
final class MigrationKeyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_round_trip_and_whitespace_tolerance() {
		$secret = str_repeat( 'ab', 32 );
		$key    = MigrationKey::encode( 'https://old.example/wp-json/iworksie-migrate/v1/', 'https://old.example/', 'Abc123xyz789QWER', $secret );
		$this->assertStringStartsWith( 'iwiemg1.', $key );
		$this->assertStringNotContainsString( '=', $key );

		$wrapped = substr( $key, 0, 20 ) . "\n  " . substr( $key, 20 );
		$decoded = MigrationKey::decode( $wrapped );
		$this->assertSame( 'Abc123xyz789QWER', $decoded['i'] );
		$this->assertSame( $secret, $decoded['s'] );
		$this->assertSame( 'https://old.example/wp-json/iworksie-migrate/v1/', $decoded['u'] );
	}

	public function test_rejects_garbage_and_bad_schemes() {
		$this->assertNull( MigrationKey::decode( 'hello' ) );
		$this->assertNull( MigrationKey::decode( 'iwiemg1.!!!' ) );
		$secret = str_repeat( 'ab', 32 );
		$this->assertNull( MigrationKey::decode( MigrationKey::encode( 'file:///etc/', 'x', 'Abc123xyz789QWER', $secret ) ) );
		$this->assertNull( MigrationKey::decode( MigrationKey::encode( 'https://x/', 'x', 'bad id!', $secret ) ) );
		$this->assertNull( MigrationKey::decode( MigrationKey::encode( 'https://x/', 'x', 'Abc123xyz789QWER', 'short' ) ) );
	}

	public function test_signature_covers_route_time_seq_and_body() {
		$base = MigrationKey::sign( 'secret', 'chunk', '100', '5', '{"a":1}' );
		$this->assertSame( 64, strlen( $base ) );
		$this->assertNotSame( $base, MigrationKey::sign( 'secret', 'manifest', '100', '5', '{"a":1}' ) );
		$this->assertNotSame( $base, MigrationKey::sign( 'secret', 'chunk', '101', '5', '{"a":1}' ) );
		$this->assertNotSame( $base, MigrationKey::sign( 'secret', 'chunk', '100', '6', '{"a":1}' ) );
		$this->assertNotSame( $base, MigrationKey::sign( 'secret', 'chunk', '100', '5', '{"a":2}' ) );
		$this->assertNotSame( $base, MigrationKey::sign( 'other', 'chunk', '100', '5', '{"a":1}' ) );
	}

	public function test_seq_comparison_beyond_int_range() {
		$this->assertTrue( MigrationKey::seq_greater( '1732665600000001', '1732665600000000' ) );
		$this->assertTrue( MigrationKey::seq_greater( '99999999999999999999', '9999999999999999999' ) );
		$this->assertFalse( MigrationKey::seq_greater( '5', '5' ) );
		$this->assertFalse( MigrationKey::seq_greater( '0004', '5' ) );
		$this->assertTrue( MigrationKey::seq_greater( '1', '0' ) );
		$a = MigrationKey::next_seq();
		usleep( 10 );
		$this->assertTrue( MigrationKey::seq_greater( MigrationKey::next_seq(), $a ) );
	}
}
