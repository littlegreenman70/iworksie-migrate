<?php
/**
 * Plugin bootstrap.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate;

use IWorksIE\Migrate\Admin\Ajax;
use IWorksIE\Migrate\Admin\Screens;
use IWorksIE\Migrate\Cli\Command;
use IWorksIE\Migrate\Giver\Api;
use IWorksIE\Migrate\Receiver\PullJob;
use IWorksIE\Migrate\Vendor\IWorksIE\Core\Core;

/**
 * Singleton plugin entry.
 */
final class Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var Core|null
	 */
	private $core = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return Core|null
	 */
	public function core() {
		return $this->core;
	}

	/**
	 * Boot core, REST API, job, menu.
	 *
	 * @return void
	 */
	public function boot() {
		if ( null !== $this->core ) {
			return;
		}

		$this->core = Core::boot(
			array(
				'plugin_file' => IWIEMG_FILE,
				'slug'        => 'iworksie-migrate',
				'prefix'      => 'iwiemg',
				'name'        => __( 'Migrate', 'iworksie-migrate' ),
				'version'     => IWIEMG_VERSION,
				'text_domain' => 'iworksie-migrate',
				'capability'  => 'manage_options',
				'wporg_url'   => 'https://wordpress.org/plugins/iworksie-migrate/',
				'description' => __( 'Copy the Media Library straight from one site to another: no archive, no third party, resumable, verified.', 'iworksie-migrate' ),
				'icon'        => 'dashicons-migrate',
				'tagline'     => __( 'Site to site, no archive, no third party. Never overwrites files unless you ask.', 'iworksie-migrate' ),
			)
		);

		$this->core->jobs()->add( new PullJob() );

		$screens = new Screens( $this->core );
		$this->core->menu()->add_submenu(
			__( 'Migrate', 'iworksie-migrate' ),
			__( 'Migrate', 'iworksie-migrate' ),
			'iworksie-migrate',
			array( $screens, 'render' )
		);

		$api = new Api();
		$api->hooks();

		$ajax = new Ajax();
		$ajax->hooks();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		Command::register();
	}

	/**
	 * Plugin JS/CSS on our screen only.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( null === $this->core || false === strpos( (string) $hook, 'iworksie-migrate' ) ) {
			return;
		}
		wp_enqueue_script(
			'iwiemg-admin',
			IWIEMG_URL . 'assets/js/admin.js',
			array( 'iwiemg-iwt-core' ),
			IWIEMG_VERSION,
			true
		);
		wp_localize_script(
			'iwiemg-admin',
			'iwiemgAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax::NONCE ),
				'jobsNonce' => wp_create_nonce( 'iwiemg_jobs' ),
				'jobId'     => PullJob::ID,
				'strings'   => array(
					'checking'      => __( 'Checking…', 'iworksie-migrate' ),
					'check'         => __( 'Check connection', 'iworksie-migrate' ),
					'pasteKey'      => __( 'Paste the key from the other site first.', 'iworksie-migrate' ),
					'failedRequest' => __( 'The request to this site failed. Reload the page and try again.', 'iworksie-migrate' ),
					'creating'      => __( 'Creating…', 'iworksie-migrate' ),
					'createKey'     => __( 'Create key', 'iworksie-migrate' ),
					'replaceKey'    => __( 'Replace key', 'iworksie-migrate' ),
					'keyActive'     => __( 'A key is active.', 'iworksie-migrate' ),
					'keyRevoked'    => __( 'Key revoked. Nobody can copy this site until you create a new one.', 'iworksie-migrate' ),
					'confirmRevoke' => __( 'Revoke the key? Any copy in progress on the other site will stop.', 'iworksie-migrate' ),
					'confirmCancel' => __( 'Cancel the copy? Files already copied stay; running it again skips them.', 'iworksie-migrate' ),
					'paused'        => __( 'Paused because the other site stopped answering. Press Resume to carry on where it left off.', 'iworksie-migrate' ),
					'cancelled'     => __( 'Cancelled. Files already copied stay in place.', 'iworksie-migrate' ),
					'starting'      => __( 'Starting…', 'iworksie-migrate' ),
					'viewReport'    => __( 'View full report', 'iworksie-migrate' ),
				),
			)
		);
		wp_enqueue_style(
			'iwiemg-admin',
			IWIEMG_URL . 'assets/css/admin.css',
			array( 'iwiemg-iwt-core' ),
			IWIEMG_VERSION
		);
	}
}
