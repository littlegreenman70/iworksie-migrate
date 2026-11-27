<?php
/**
 * Path validation and copy rules.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Unit;

use IWorksIE\Migrate\Support\Paths;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IWorksIE\Migrate\Support\Paths
 */
final class PathsTest extends TestCase {

	public function test_accepts_plain_relative_paths() {
		$this->assertSame( '2024/05/photo.jpg', Paths::normalize_relative( '2024/05/photo.jpg' ) );
		$this->assertSame( 'a b/ü.png', Paths::normalize_relative( 'a b/ü.png' ) );
	}

	/**
	 * @dataProvider bad_paths
	 * @param mixed $path Path.
	 */
	public function test_rejects_traversal_and_odd_paths( $path ) {
		$this->assertNull( Paths::normalize_relative( $path ) );
	}

	/**
	 * @return array<string,array<int,mixed>>
	 */
	public function bad_paths() {
		return array(
			'parent'       => array( '../wp-config.php' ),
			'inner parent' => array( '2024/../../x.jpg' ),
			'dot'          => array( './x.jpg' ),
			'absolute'     => array( '/etc/passwd' ),
			'backslash'    => array( '2024\\x.jpg' ),
			'drive'        => array( 'C:/x.jpg' ),
			'nul'          => array( "x.jpg\0.png" ),
			'empty part'   => array( 'a//b.jpg' ),
			'trailing'     => array( 'a/' ),
			'empty'        => array( '' ),
			'not string'   => array( array( 'x' ) ),
			'too long'     => array( str_repeat( 'a/', 600 ) . 'b' ),
		);
	}

	public function test_blocks_scripts_and_server_config() {
		$this->assertSame( 'executable', Paths::blocked_reason( '2024/shell.php' ) );
		$this->assertSame( 'executable', Paths::blocked_reason( '2024/SHELL.PHTML' ) );
		$this->assertSame( 'executable', Paths::blocked_reason( '2024/shell.php.jpg' ) );
		$this->assertSame( 'executable', Paths::blocked_reason( 'x.phar' ) );
		$this->assertSame( 'server_config', Paths::blocked_reason( 'woocommerce_uploads/.htaccess' ) );
		$this->assertSame( 'server_config', Paths::blocked_reason( '.user.ini' ) );
		$this->assertSame( 'part_file', Paths::blocked_reason( 'a/b.jpg.iwiemg-part' ) );
	}

	public function test_does_not_block_ordinary_media() {
		$this->assertSame( '', Paths::blocked_reason( '2024/05/photo.jpg' ) );
		$this->assertSame( '', Paths::blocked_reason( 'brochure.pl.pdf' ) );
		$this->assertSame( '', Paths::blocked_reason( 'php-logo.png' ) );
		$this->assertSame( '', Paths::blocked_reason( 'downloads/app.zip' ) );
		$this->assertSame( '', Paths::blocked_reason( '.hidden-but-fine.svg' ) );
	}

	public function test_dead_weight_rules() {
		$this->assertSame( 'backup', Paths::dead_weight_reason( 'backup-2023', true ) );
		$this->assertSame( 'backup', Paths::dead_weight_reason( 'backwpup-abc-backups', true ) );
		$this->assertSame( 'cache', Paths::dead_weight_reason( 'et-cache', true ) );
		$this->assertSame( 'vcs', Paths::dead_weight_reason( 'theme/node_modules', true ) );
		$this->assertSame( 'log', Paths::dead_weight_reason( 'wc-logs/fatal-errors.log', false ) );
		$this->assertSame( 'dump', Paths::dead_weight_reason( 'db.sql.gz', false ) );
		$this->assertSame( 'dump', Paths::dead_weight_reason( 'site.wpress', false ) );
		$this->assertSame( 'backup', Paths::dead_weight_reason( 'site-backup-2024.zip', false ) );
		$this->assertSame( '', Paths::dead_weight_reason( 'downloads/app.zip', false ) );
		$this->assertSame( '', Paths::dead_weight_reason( '2024', true ) );
		$this->assertSame( '', Paths::dead_weight_reason( 'photo.jpg', false ) );
	}

	public function test_is_inside() {
		$this->assertTrue( Paths::is_inside( '/var/www/uploads/2024', '/var/www/uploads' ) );
		$this->assertTrue( Paths::is_inside( '/var/www/uploads', '/var/www/uploads/' ) );
		$this->assertFalse( Paths::is_inside( '/var/www/uploads-old/x', '/var/www/uploads' ) );
		$this->assertFalse( Paths::is_inside( '/var/www', '/var/www/uploads' ) );
	}
}
