<?php
/**
 * WP-CLI: wp iworksie-migrate
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Cli;

use IWorksIE\Migrate\Giver\KeyStore;
use IWorksIE\Migrate\Plugin;
use IWorksIE\Migrate\Receiver\Preflight;
use IWorksIE\Migrate\Receiver\PullJob;
use IWorksIE\Migrate\Receiver\ReportBuilder;

/**
 * Create keys and copy uploads from the command line (no browser, no timeouts).
 */
final class Command {

	/**
	 * Register when WP-CLI is present.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'iworksie-migrate', self::class );
	}

	/**
	 * Create (or replace) this site's migration key and print it.
	 *
	 * ## OPTIONS
	 *
	 * [--expires=<lifetime>]
	 * : How long the key works.
	 * ---
	 * default: 24h
	 * options:
	 *   - 1h
	 *   - 24h
	 *   - 7d
	 * ---
	 *
	 * @subcommand key-create
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Assoc.
	 * @return void
	 */
	public function key_create( $args, $assoc ) {
		$key = KeyStore::create( isset( $assoc['expires'] ) ? (string) $assoc['expires'] : '24h' );
		\WP_CLI::line( $key );
	}

	/**
	 * Revoke this site's migration key.
	 *
	 * @subcommand key-revoke
	 * @return void
	 */
	public function key_revoke() {
		KeyStore::revoke();
		\WP_CLI::success( 'Key revoked.' );
	}

	/**
	 * Copy the uploads folder from the site that created the key.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Migration key from the other site.
	 *
	 * [--replace]
	 * : Replace files that already exist here but are different.
	 *
	 * [--include-dead-weight]
	 * : Also copy backups, caches, logs and dumps.
	 *
	 * [--no-verify]
	 * : Skip the SHA-1 verification pass.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Assoc.
	 * @return void
	 */
	public function pull( $args, $assoc ) {
		$check = Preflight::run( (string) $args[0] );
		foreach ( $check['results'] as $result ) {
			\WP_CLI::line( sprintf( '[%s] %s: %s', strtoupper( $result->status() ), $result->label(), $result->description() ) );
			if ( 'critical' === $result->status() && '' !== $result->fix() ) {
				\WP_CLI::line( '    ' . $result->fix() );
			}
		}
		if ( ! $check['ok'] ) {
			\WP_CLI::error( 'Pre-flight failed; nothing was copied.' );
		}
		update_option( PullJob::KEY_OPTION, preg_replace( '/\s+/', '', (string) $args[0] ), false );

		$job   = new PullJob();
		$state = $job->start(
			array(
				'replace'          => \WP_CLI\Utils\get_flag_value( $assoc, 'replace', false ) ? '1' : '0',
				'skip_dead_weight' => \WP_CLI\Utils\get_flag_value( $assoc, 'include-dead-weight', false ) ? '0' : '1',
				'verify'           => \WP_CLI\Utils\get_flag_value( $assoc, 'verify', true ) ? '1' : '0',
			)
		);
		// Persist immediately so a kill in the first step budget can still resume.
		$core = Plugin::instance()->core();
		if ( null !== $core ) {
			$state['job_id'] = PullJob::ID;
			$core->jobs()->save_state( PullJob::ID, $state );
		}
		$this->drive( $job, $state );
	}

	/**
	 * Resume a copy that was paused or interrupted (browser or CLI).
	 *
	 * @return void
	 */
	public function resume() {
		$core  = Plugin::instance()->core();
		$state = null === $core ? null : $core->jobs()->load_state( PullJob::ID );
		if ( ! is_array( $state ) || ! empty( $state['done'] ) || ! empty( $state['cancelled'] ) ) {
			\WP_CLI::error( 'There is no unfinished copy to resume.' );
		}
		$this->drive( new PullJob(), $state );
	}

	/**
	 * Run steps until done, saving state so the browser and CLI can hand over.
	 *
	 * @param PullJob             $job   Job.
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	private function drive( PullJob $job, array $state ) {
		$core = Plugin::instance()->core();
		if ( null === $core ) {
			\WP_CLI::error( 'Plugin core is not booted.' );
		}
		$jobs            = $core->jobs();
		$state['job_id'] = PullJob::ID;
		$last            = '';
		$waits           = 0;
		while ( empty( $state['done'] ) ) {
			$state = $job->step( $state, 20.0 );
			$jobs->save_state( PullJob::ID, $state );
			$msg = (int) $state['progress'] . '% ' . (string) $state['message'];
			if ( $msg !== $last ) {
				\WP_CLI::line( $msg );
				$last = $msg;
			}
			if ( ! empty( $state['paused'] ) ) {
				if ( ++$waits > 3 ) {
					\WP_CLI::error( 'The other site keeps failing. Run `wp iworksie-migrate resume` later to carry on.' );
				}
				sleep( 30 );
			} elseif ( (int) $state['net_failures'] > 0 ) {
				sleep( min( 30, 2 * (int) $state['net_failures'] ) );
			}
		}
		$state['result']   = $job->finish( $state );
		$state['progress'] = 100;
		$jobs->save_state( PullJob::ID, $state );

		\WP_CLI::line( '' );
		\WP_CLI::line( ReportBuilder::markdown( $state['result'] ) );
		if ( 'critical' === ReportBuilder::status( $state['result'] ) ) {
			\WP_CLI::error( 'Copy stopped.' );
		}
		\WP_CLI::success( ReportBuilder::headline( $state['result'] ) );
	}
}
