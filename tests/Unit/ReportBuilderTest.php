<?php
/**
 * Report builder notes.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use IWorksIE\Migrate\Receiver\ReportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IWorksIE\Migrate\Receiver\ReportBuilder
 */
final class ReportBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $n ) {
				return (string) $n;
			}
		);
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return (string) $bytes . 'B';
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $ts ) {
				return gmdate( $format, $ts );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_markdown_includes_nfc_note_when_count_positive() {
		$md = ReportBuilder::markdown(
			array(
				'giver'     => 'https://old.test/',
				'receiver'  => 'https://new.test/',
				'nfc_names' => 3,
				'c'         => array(
					'copied'       => 1,
					'copied_bytes' => 10,
					'same'         => 0,
				),
				't'         => array(
					'files' => 1,
					'bytes' => 10,
				),
				'opts'      => array(),
				'lists'     => array(),
			)
		);
		$this->assertStringContainsString(
			'3 file names use a different Unicode form (common when files were uploaded from a Mac). They were copied with their exact names.',
			$md
		);
	}

	public function test_markdown_omits_nfc_note_when_zero() {
		$md = ReportBuilder::markdown(
			array(
				'giver'     => 'https://old.test/',
				'receiver'  => 'https://new.test/',
				'nfc_names' => 0,
				'c'         => array(
					'copied'       => 0,
					'copied_bytes' => 0,
					'same'         => 0,
				),
				't'         => array(
					'files' => 0,
					'bytes' => 0,
				),
				'opts'      => array(),
				'lists'     => array(),
			)
		);
		$this->assertStringNotContainsString( 'different Unicode form', $md );
	}

	public function test_not_regular_reason_label() {
		$this->assertSame(
			'not a regular file (skipped for safety)',
			ReportBuilder::reason_label( 'not_regular' )
		);
	}
}
