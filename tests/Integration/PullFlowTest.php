<?php
/**
 * End-to-end pull: real REST endpoints and signature checks, in-process.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Tests\Integration;

use IWorksIE\Migrate\Giver\KeyStore;
use IWorksIE\Migrate\Receiver\PullJob;
use IWorksIE\Migrate\Receiver\ReportBuilder;
use IWorksIE\Migrate\Support\MigrationKey;
use WP_UnitTestCase;

/**
 * Giver = this site's uploads; receiver = a temp folder.
 */
final class PullFlowTest extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $giver;

	/**
	 * @var string
	 */
	private $receiver;

	/**
	 * @var array<string,string>
	 */
	private $files = array();

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
		$uploads        = wp_upload_dir();
		$this->giver    = realpath( $uploads['basedir'] );
		$this->receiver = sys_get_temp_dir() . '/iwiemg-receiver-' . uniqid();
		mkdir( $this->receiver, 0777, true );
		$this->receiver = realpath( $this->receiver );

		$this->files = array(
			'iwiemg-test/2024/05/photo.jpg' => random_bytes( 5 * 1024 * 1024 + 123 ), // Several chunks.
			'iwiemg-test/2024/05/small.png' => 'png-bytes',
			'iwiemg-test/empty.txt'         => '',
			'iwiemg-test/docs/brochure.pdf' => str_repeat( 'pdf', 1000 ),
			'iwiemg-test/evil.php'          => '<?php echo 1;',
			'iwiemg-test/.htaccess'         => 'AddHandler php .jpg',
			'iwiemg-test/backup-2020/x.zip' => 'zip',
		);
		foreach ( $this->files as $rel => $body ) {
			$path = $this->giver . '/' . $rel;
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, $body );
		}
		add_filter( 'iwiemg_chunk_bytes', array( $this, 'one_mb' ) );
	}

	public function tear_down() {
		remove_filter( 'iwiemg_chunk_bytes', array( $this, 'one_mb' ) );
		$this->rrmdir( $this->giver . '/iwiemg-test' );
		$this->rrmdir( $this->receiver );
		KeyStore::revoke();
		parent::tear_down();
	}

	/**
	 * @return int
	 */
	public function one_mb() {
		return 1048576;
	}

	/**
	 * @param string $dir Dir.
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * @return LoopbackClient
	 */
	private function client() {
		$key = MigrationKey::decode( KeyStore::create( '1h' ) );
		$this->assertIsArray( $key );
		return new LoopbackClient( $key );
	}

	/**
	 * @param PullJob             $job   Job.
	 * @param array<string,mixed> $state State.
	 * @param int                 $max   Max steps.
	 * @return array<string,mixed>
	 */
	private function run_job( PullJob $job, array $state, $max = 500 ) {
		for ( $i = 0; $i < $max && empty( $state['done'] ); $i++ ) {
			$state = $job->step( $state, 0.05 ); // Tiny budget: forces many resumable steps.
			if ( ! empty( $state['paused'] ) ) {
				break;
			}
		}
		return $state;
	}

	/**
	 * Only our fixture files (the test uploads dir can contain others).
	 *
	 * @return array<int,string>
	 */
	private function received() {
		$out = array();
		if ( ! is_dir( $this->receiver . '/iwiemg-test' ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->receiver . '/iwiemg-test', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$out[] = substr( $f->getPathname(), strlen( $this->receiver ) + 1 );
		}
		sort( $out );
		return $out;
	}

	public function test_full_copy_is_byte_identical_and_skips_unsafe_files() {
		$job   = new PullJob( $this->client(), $this->receiver );
		$state = $this->run_job( $job, $job->start( array() ) );

		$this->assertTrue( $state['done'] );
		$this->assertSame( '', $state['failed'] );
		$this->assertSame(
			array(
				'iwiemg-test/2024/05/photo.jpg',
				'iwiemg-test/2024/05/small.png',
				'iwiemg-test/docs/brochure.pdf',
				'iwiemg-test/empty.txt',
			),
			$this->received()
		);
		foreach ( array( 'iwiemg-test/2024/05/photo.jpg', 'iwiemg-test/2024/05/small.png', 'iwiemg-test/docs/brochure.pdf', 'iwiemg-test/empty.txt' ) as $rel ) {
			$this->assertSame( sha1_file( $this->giver . '/' . $rel ), sha1_file( $this->receiver . '/' . $rel ), $rel );
			$this->assertSame( filemtime( $this->giver . '/' . $rel ), filemtime( $this->receiver . '/' . $rel ), 'mtime kept for ' . $rel );
		}
		$this->assertSame( 0, (int) $state['c']['mismatched'] );
		$this->assertSame( 0, (int) $state['c']['errors'] );
		$this->assertGreaterThanOrEqual( 4, (int) $state['c']['verified'] );
		$this->assertSame( 1, (int) $state['skip_reasons']['executable'] );
		$this->assertSame( 1, (int) $state['skip_reasons']['server_config'] );
		$this->assertSame( 1, (int) $state['skip_reasons']['backup'] );

		$summary = $job->finish( $state );
		$md      = ReportBuilder::markdown( $summary );
		$this->assertStringContainsString( 'iwiemg-test/evil.php', $md );
		$this->assertSame( 'good', ReportBuilder::status( $summary ) );
	}

	public function test_small_files_are_batched() {
		for ( $i = 0; $i < 120; $i++ ) {
			$path = $this->giver . '/iwiemg-test/thumbs/t-' . $i . '.jpg';
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, random_bytes( 2000 + $i ) );
		}
		$client = $this->client();
		$job    = new PullJob( $client, $this->receiver );
		$state  = $this->run_job( $job, $job->start( array( 'verify' => '0' ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 0, (int) $state['c']['errors'] );
		for ( $i = 0; $i < 120; $i++ ) {
			$rel = 'iwiemg-test/thumbs/t-' . $i . '.jpg';
			$this->assertSame( sha1_file( $this->giver . '/' . $rel ), sha1_file( $this->receiver . '/' . $rel ) );
		}
		$this->assertLessThan( 10, $client->calls['files'], 'Thumbnails fetched in batches' );
	}

	public function test_second_run_skips_identical_and_keeps_different_files() {
		$client = $this->client();
		$job    = new PullJob( $client, $this->receiver );
		$this->run_job( $job, $job->start( array() ) );

		// A different local file with the same name must be kept (replace is off).
		file_put_contents( $this->receiver . '/iwiemg-test/2024/05/small.png', 'local edit' );

		$state = $this->run_job( $job, $job->start( array() ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 0, (int) $state['c']['copied'] );
		$this->assertSame( 1, (int) $state['c']['kept'] );
		$this->assertSame( 'local edit', file_get_contents( $this->receiver . '/iwiemg-test/2024/05/small.png' ) );
		$this->assertSame( 0, (int) $state['c']['mismatched'], 'Kept files are not reported as mismatches' );

		// With replace on it is overwritten.
		$state = $this->run_job( $job, $job->start( array( 'replace' => '1' ) ) );
		$this->assertSame( 1, (int) $state['c']['copied'] );
		$this->assertSame( 1, (int) $state['c']['replaced'] );
		$this->assertSame( 'png-bytes', file_get_contents( $this->receiver . '/iwiemg-test/2024/05/small.png' ) );
	}

	public function test_network_failures_pause_then_resume_finishes() {
		$client = $this->client();
		$job    = new PullJob( $client, $this->receiver );
		$state  = $job->start( array( 'verify' => '0' ) );

		// Get part-way into the big file.
		for ( $i = 0; $i < 400 && ! ( is_array( $state['cur'] ) && $state['cur']['o'] > 0 ); $i++ ) {
			$state = $job->step( $state, 0.05 );
		}
		$this->assertIsArray( $state['cur'], 'Mid-file' );
		$offset = (int) $state['cur']['o'];

		$client->fail_next = 100;
		for ( $i = 0; $i < PullJob::MAX_NET_FAILURES; $i++ ) {
			$state = $job->step( $state, 0.05 );
		}
		$this->assertTrue( $state['paused'] );
		$this->assertFalse( $state['done'] );
		$this->assertSame( $offset, (int) $state['cur']['o'], 'Offset kept while paused' );

		$client->fail_next = 0;
		$state             = $this->run_job( $job, $state );
		$this->assertTrue( $state['done'] );
		$this->assertSame( '', $state['failed'] );
		$this->assertSame( sha1_file( $this->giver . '/iwiemg-test/2024/05/photo.jpg' ), sha1_file( $this->receiver . '/iwiemg-test/2024/05/photo.jpg' ) );
		$this->assertFileDoesNotExist( $this->receiver . '/iwiemg-test/2024/05/photo.jpg.iwiemg-part' );
	}

	public function test_revoked_key_stops_job_with_plain_reason() {
		$client = $this->client();
		$job    = new PullJob( $client, $this->receiver );
		$state  = $job->start( array() );
		KeyStore::revoke();
		$state = $this->run_job( $job, $state );
		$this->assertTrue( $state['done'] );
		$this->assertStringContainsString( 'no longer accepts this key', $state['failed'] );
	}

	public function test_signature_replay_and_expiry_are_rejected() {
		$client                = $this->client();
		$client->bad_signature = true;
		$res                   = $client->call( 'hello', array() );
		$this->assertWPError( $res );
		$this->assertSame( 'iwiemg_bad_signature', $res->get_error_code() );

		$client->bad_signature = false;
		$client->fixed_seq     = MigrationKey::next_seq();
		$this->assertIsArray( $client->call( 'hello', array() ) );
		$res = $client->call( 'hello', array() );
		$this->assertWPError( $res );
		$this->assertSame( 'iwiemg_replay', $res->get_error_code() );

		$client->fixed_seq = null;
		$stored            = get_option( KeyStore::OPTION );
		$stored['expires'] = time() - 1;
		update_option( KeyStore::OPTION, $stored, false );
		$res = $client->call( 'hello', array() );
		$this->assertWPError( $res );
		$this->assertSame( 'iwiemg_not_enabled', $res->get_error_code() );
	}

	public function test_chunk_endpoint_refuses_traversal_and_scripts() {
		$client = $this->client();
		foreach ( array( '../wp-config.php', 'iwiemg-test/evil.php', 'iwiemg-test/.htaccess', '/etc/passwd' ) as $path ) {
			$res = $client->call(
				'chunk',
				array(
					'path'   => $path,
					'offset' => 0,
					'length' => 100,
				)
			);
			$this->assertWPError( $res, $path );
		}
	}

	public function test_unsigned_request_is_refused() {
		KeyStore::create( '1h' );
		$request  = new \WP_REST_Request( 'POST', '/iworksie-migrate/v1/hello' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_hardlink_inside_uploads_is_refused_by_chunk() {
		$outside = sys_get_temp_dir() . '/iwiemg-secret-' . uniqid();
		file_put_contents( $outside, 'SECRET-OUTSIDE-UPLOADS' );
		$inside = $this->giver . '/iwiemg-test/hard-secret.jpg';
		wp_mkdir_p( dirname( $inside ) );
		$linked = @link( $outside, $inside );
		if ( ! $linked ) {
			unlink( $outside );
			$this->markTestSkipped( 'Hardlinks are not available on this filesystem.' );
		}

		$client = $this->client();
		$res    = $client->call(
			'chunk',
			array(
				'path'   => 'iwiemg-test/hard-secret.jpg',
				'offset' => 0,
				'length' => 100,
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'iwiemg_bad_path', $res->get_error_code() );

		$job   = new PullJob( $client, $this->receiver );
		$state = $this->run_job( $job, $job->start( array( 'verify' => '0' ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertArrayNotHasKey( 'iwiemg-test/hard-secret.jpg', array_flip( $this->received() ) );
		$this->assertGreaterThanOrEqual( 1, (int) ( $state['skip_reasons']['hardlink'] ?? 0 ) );

		@unlink( $inside );
		@unlink( $outside );
	}

	public function test_claim_seq_is_atomic_against_replay() {
		KeyStore::create( '1h' );
		$seq = MigrationKey::next_seq();
		$this->assertTrue( KeyStore::claim_seq( $seq ) );
		$this->assertFalse( KeyStore::claim_seq( $seq ) );
		$this->assertFalse( KeyStore::claim_seq( '1' ) );
		$higher = $seq . '0';
		if ( MigrationKey::seq_greater( $higher, $seq ) ) {
			$this->assertTrue( KeyStore::claim_seq( $higher ) );
		}
	}

	public function test_finish_clears_receiver_key_after_success() {
		$key = KeyStore::create( '1h' );
		update_option( PullJob::KEY_OPTION, $key, false );
		$job   = new PullJob( new LoopbackClient( MigrationKey::decode( $key ) ), $this->receiver );
		$state = $this->run_job( $job, $job->start( array( 'verify' => '0' ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( '', (string) $state['failed'] );
		$job->finish( $state );
		$this->assertFalse( (bool) get_option( PullJob::KEY_OPTION, false ) );
	}

	public function test_custom_upload_path_is_used_on_both_sides() {
		$custom = sys_get_temp_dir() . '/iwiemg-custom-uploads-' . uniqid();
		mkdir( $custom, 0777, true );
		$custom = realpath( $custom );
		file_put_contents( $custom . '/from-custom.png', 'custom-bytes' );

		$filter = static function ( $dirs ) use ( $custom ) {
			$dirs['path']    = $custom;
			$dirs['basedir'] = $custom;
			$dirs['url']     = 'http://example.test/custom-uploads';
			$dirs['baseurl'] = 'http://example.test/custom-uploads';
			$dirs['error']   = false;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );

		$this->assertSame( $custom, \IWorksIE\Migrate\Support\Paths::uploads_base() );

		$client = $this->client();
		$hello  = $client->call( 'hello', array() );
		$this->assertIsArray( $hello );
		$this->assertTrue( ! empty( $hello['uploads_readable'] ) );

		$receiver = sys_get_temp_dir() . '/iwiemg-custom-receiver-' . uniqid();
		mkdir( $receiver, 0777, true );
		$receiver = realpath( $receiver );
		$job      = new PullJob( $client, $receiver );
		$state    = $this->run_job( $job, $job->start( array( 'verify' => '0' ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( '', (string) $state['failed'] );
		$this->assertFileExists( $receiver . '/from-custom.png' );
		$this->assertSame( 'custom-bytes', file_get_contents( $receiver . '/from-custom.png' ) );

		remove_filter( 'upload_dir', $filter );
		$this->rrmdir( $custom );
		$this->rrmdir( $receiver );
	}
}
