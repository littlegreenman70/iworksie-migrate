<?php
/**
 * Resumable listing.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Unit;

use IWorksIE\Migrate\Support\Walker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IWorksIE\Migrate\Support\Walker
 */
final class WalkerTest extends TestCase {

	/**
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/iwiemg-walker-' . uniqid();
		$files     = array(
			'a.txt'                  => 'a',
			'a/b.jpg'                => 'bb',
			'a/c/d.png'              => 'ddd',
			'a.b/e.gif'              => 'e',
			'B.jpg'                  => 'B',
			'z/shell.php'            => '<?php',
			'z/.htaccess'            => 'deny',
			'backup-old/big.zip'     => 'zip',
			'2024/05/photo.jpg'      => 'photo',
			'2024/05/photo-150.jpg'  => 'p',
			'logs/debug.log'         => 'log',
			'empty-dir/.keep'        => '',
		);
		foreach ( $files as $rel => $body ) {
			$path = $this->dir . '/' . $rel;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $body );
		}
		if ( function_exists( 'symlink' ) ) {
			@symlink( '/etc', $this->dir . '/link-out' );
		}
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isLink() || $f->isFile() ) {
				unlink( $f->getPathname() );
			} else {
				rmdir( $f->getPathname() );
			}
		}
		rmdir( $this->dir );
	}

	/**
	 * Collect all entries with a given page size.
	 *
	 * @param int  $limit Page size.
	 * @param bool $skip  Skip dead weight.
	 * @return array<int,array<string,mixed>>
	 */
	private function all( $limit, $skip = true ) {
		$walker = new Walker( $this->dir, $skip );
		$cursor = '';
		$out    = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$page   = $walker->page( $cursor, $limit, 10 );
			$out    = array_merge( $out, $page['entries'] );
			$cursor = $page['cursor'];
			if ( $page['done'] ) {
				return $out;
			}
		}
		$this->fail( 'Walker never finished' );
	}

	public function test_pages_of_any_size_give_the_same_complete_listing() {
		$full  = array_column( $this->all( 1000 ), 'p' );
		$one   = array_column( $this->all( 1 ), 'p' );
		$three = array_column( $this->all( 3 ), 'p' );
		$this->assertSame( $full, $one );
		$this->assertSame( $full, $three );
		$this->assertSame( count( $full ), count( array_unique( $full ) ), 'No duplicates' );
	}

	public function test_lists_media_and_reports_skips() {
		$entries = array();
		foreach ( $this->all( 1000 ) as $e ) {
			$entries[ $e['p'] ] = $e;
		}
		$this->assertArrayHasKey( 'a/c/d.png', $entries );
		$this->assertSame( 3, $entries['a/c/d.png']['s'] );
		$this->assertArrayNotHasKey( 'skip', $entries['2024/05/photo.jpg'] );
		$this->assertSame( 'executable', $entries['z/shell.php']['skip'] );
		$this->assertSame( 'server_config', $entries['z/.htaccess']['skip'] );
		$this->assertSame( 'backup', $entries['backup-old']['skip'] );
		$this->assertArrayNotHasKey( 'backup-old/big.zip', $entries );
		$this->assertSame( 'log', $entries['logs/debug.log']['skip'] );
		if ( isset( $entries['link-out'] ) ) {
			$this->assertSame( 'symlink', $entries['link-out']['skip'] );
		}
		foreach ( array_keys( $entries ) as $p ) {
			$this->assertStringStartsNotWith( 'link-out/', $p, 'Never follows symlinks' );
		}
	}

	public function test_including_dead_weight_lists_backups() {
		$paths = array_column( $this->all( 1000, false ), 'p' );
		$this->assertContains( 'backup-old/big.zip', $paths );
		$this->assertContains( 'logs/debug.log', $paths );
	}

	public function test_resumes_after_cursor_that_no_longer_exists() {
		$walker = new Walker( $this->dir, true );
		$page   = $walker->page( 'a/bb-deleted.jpg', 1000, 10 );
		$paths  = array_column( $page['entries'], 'p' );
		$this->assertSame( 'a/c/d.png', $paths[0] );
		$this->assertNotContains( 'a/b.jpg', $paths );
	}
}
