<?php
/**
 * Admin screens.
 *
 * @package IWorksIE\Migrate
 */

namespace IWorksIE\Migrate\Admin;

use IWorksIE\Migrate\Giver\KeyStore;
use IWorksIE\Migrate\Receiver\PullJob;
use IWorksIE\Migrate\Receiver\ReportBuilder;
use IWorksIE\Migrate\Support\MigrationKey;
use IWorksIE\Migrate\Vendor\IWorksIE\Core\Core;
use IWorksIE\Migrate\Vendor\IWorksIE\Core\UI;

/**
 * Renders the Copy, Give and Report tabs.
 */
final class Screens {

	/**
	 * @var Core
	 */
	private $core;

	/**
	 * @param Core $core Core.
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * Menu callback.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'iworksie-migrate' ) );
		}

		$this->core->page()->render(
			array(
				'title'   => (string) $this->core->config( 'name' ),
				'version' => (string) $this->core->config( 'version' ),
				'tagline' => (string) $this->core->config( 'tagline' ),
				'tabs'    => array(
					'receive' => __( 'Copy from another site', 'iworksie-migrate' ),
					'give'    => __( 'Let another site copy this one', 'iworksie-migrate' ),
					'report'  => __( 'Report', 'iworksie-migrate' ),
				),
			),
			function ( $active ) {
				if ( 'give' === $active ) {
					$this->render_give();
				} elseif ( 'report' === $active ) {
					$this->render_report();
				} else {
					$this->render_receive();
				}
			}
		);
	}

	/**
	 * "Expires in 23 hours" / "Expired".
	 *
	 * @param int $expires Unix time.
	 * @return string
	 */
	public static function expires_label( $expires ) {
		if ( $expires <= time() ) {
			return __( 'Expired', 'iworksie-migrate' );
		}
		/* translators: %s: human time difference, e.g. "23 hours" */
		return sprintf( __( 'Expires in %s', 'iworksie-migrate' ), human_time_diff( time(), $expires ) );
	}

	/**
	 * Receiving side.
	 *
	 * @return void
	 */
	private function render_receive() {
		$status     = Ajax::status_payload();
		$stored     = MigrationKey::decode( (string) get_option( PullJob::KEY_OPTION, '' ) );
		$running    = ! empty( $status['exists'] ) && empty( $status['done'] ) && empty( $status['cancelled'] );
		$show_step2 = null !== $stored || $running;

		echo '<div class="iwiemg-intro">';
		echo '<p>' . esc_html__( 'Copies the Media Library files (everything in wp-content/uploads) from another WordPress site straight to this one. Nothing is zipped up first and nothing goes through a third-party server, so it works even when the other site is nearly out of disk space or has a small upload limit.', 'iworksie-migrate' ) . '</p>';
		echo '<ol class="iwiemg-steps">';
		echo '<li>' . esc_html__( 'Install iWorks.ie Migrate on both sites.', 'iworksie-migrate' ) . '</li>';
		echo '<li>' . esc_html__( 'On the site you are copying from, open "Let another site copy this one" and create a key.', 'iworksie-migrate' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the key below, check the connection, then start copying. You can close the tab and resume later.', 'iworksie-migrate' ) . '</li>';
		echo '</ol>';
		echo '</div>';

		// Step 1.
		echo '<section class="iwiemg-card" aria-labelledby="iwiemg-step1-title">';
		echo '<h2 id="iwiemg-step1-title">' . esc_html__( '1. Connect to the other site', 'iworksie-migrate' ) . '</h2>';
		if ( null !== $stored ) {
			echo '<p class="iwiemg-connected" data-iwiemg-connected>';
			/* translators: %s: other site's address */
			echo esc_html( sprintf( __( 'Using the key for %s.', 'iworksie-migrate' ), $stored['h'] ) ) . ' ';
			echo '<button type="button" class="button-link" data-iwiemg-forget>' . esc_html__( 'Use a different key', 'iworksie-migrate' ) . '</button>';
			echo '</p>';
		} elseif (
			! empty( $status['done'] )
			&& empty( $status['cancelled'] )
			&& ! empty( $status['exists'] )
			&& isset( $status['status'] )
			&& 'critical' !== $status['status']
		) {
			// Successful run cleared the key: prompt for a fresh paste to copy again.
			echo '<p class="iwiemg-done-hint" data-iwiemg-done-hint>' . esc_html__( 'Done. To copy again (for example, to pick up new uploads), paste a fresh key.', 'iworksie-migrate' ) . '</p>';
		}
		echo '<div class="iwiemg-key-form"' . ( null !== $stored ? ' hidden' : '' ) . ' data-iwiemg-key-form>';
		echo '<label for="iwiemg-key" class="iwiemg-label">' . esc_html__( 'Migration key from the other site', 'iworksie-migrate' ) . '</label>';
		echo '<textarea id="iwiemg-key" class="large-text code" rows="3" spellcheck="false" autocomplete="off" placeholder="iwiemg1.…"></textarea>';
		echo '<p><button type="button" class="button button-primary" data-iwiemg-preflight>' . esc_html__( 'Check connection', 'iworksie-migrate' ) . '</button></p>';
		echo '</div>';
		echo '<div class="iwiemg-preflight" data-iwiemg-preflight-results aria-live="polite"></div>';
		echo '</section>';

		// Step 2.
		echo '<section class="iwiemg-card" aria-labelledby="iwiemg-step2-title" data-iwiemg-step2' . ( $show_step2 ? '' : ' hidden' ) . '>';
		echo '<h2 id="iwiemg-step2-title">' . esc_html__( '2. Copy the files', 'iworksie-migrate' ) . '</h2>';
		echo '<fieldset class="iwiemg-options" data-iwiemg-options' . ( $running ? ' disabled' : '' ) . '>';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Copy options', 'iworksie-migrate' ) . '</legend>';
		echo '<p><label><input type="checkbox" name="skip_dead_weight" value="1" checked> ' . esc_html__( 'Skip backups, caches, logs and database dumps (recommended)', 'iworksie-migrate' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="replace" value="1"> ' . esc_html__( 'Replace files that already exist here but are different', 'iworksie-migrate' ) . '</label><br><span class="description">' . esc_html__( 'Off by default, so nothing on this site is overwritten. Files that are already identical are always skipped, so running the copy again only fetches what changed.', 'iworksie-migrate' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="verify" value="1" checked> ' . esc_html__( 'Verify every file against the other site when finished (SHA-1)', 'iworksie-migrate' ) . '</label></p>';
		echo '</fieldset>';

		$mode = 'idle';
		if ( $running ) {
			$mode = 'resume';
		}
		echo '<div class="iwt-toolbar" data-iwiemg-toolbar data-mode="' . esc_attr( $mode ) . '">';
		echo '<button type="button" class="button button-primary" data-iwiemg-start' . ( 'idle' === $mode ? '' : ' hidden' ) . '>' . esc_html__( 'Start copying', 'iworksie-migrate' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-iwiemg-resume' . ( 'resume' === $mode ? '' : ' hidden' ) . '>' . esc_html__( 'Resume', 'iworksie-migrate' ) . '</button>';
		echo '<button type="button" class="button" data-iwiemg-cancel' . ( 'resume' === $mode ? '' : ' hidden' ) . '>' . esc_html__( 'Cancel', 'iworksie-migrate' ) . '</button>';
		echo '</div>';

		$progress = UI::progress( 'iwiemg-pull' );
		if ( ! $running ) {
			$progress = str_replace( 'class="iwt-progress"', 'class="iwt-progress is-idle"', $progress );
		}
		echo $progress; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core UI escapes.

		$stats = isset( $status['stats'] ) ? (array) $status['stats'] : array();
		echo '<dl class="iwiemg-stats" data-iwiemg-stats' . ( ! empty( $status['exists'] ) && empty( $status['cancelled'] ) ? '' : ' hidden' ) . '>';
		$labels = array(
			'copied'  => __( 'Copied', 'iworksie-migrate' ),
			'bytes'   => __( 'Size copied', 'iworksie-migrate' ),
			'same'    => __( 'Already identical', 'iworksie-migrate' ),
			'kept'    => __( 'Kept (different here)', 'iworksie-migrate' ),
			'skipped' => __( 'Skipped', 'iworksie-migrate' ),
			'errors'  => __( 'Errors', 'iworksie-migrate' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd data-stat="' . esc_attr( $key ) . '">' . esc_html( isset( $stats[ $key ] ) ? (string) $stats[ $key ] : '0' ) . '</dd></div>';
		}
		echo '</dl>';

		echo '<div class="iwiemg-outcome" data-iwiemg-outcome aria-live="polite">';
		if ( ! empty( $status['done'] ) && empty( $status['cancelled'] ) && isset( $status['headline'] ) ) {
			$this->echo_outcome( (string) $status['status'], (string) $status['headline'], isset( $status['failed'] ) ? (string) $status['failed'] : '' );
		}
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Outcome box after a run.
	 *
	 * @param string $status   good|recommended|critical.
	 * @param string $headline Text.
	 * @param string $fix      What to do (failures).
	 * @return void
	 */
	private function echo_outcome( $status, $headline, $fix ) {
		echo '<div class="iwiemg-outcome__box iwiemg-outcome__box--' . esc_attr( $status ) . '">';
		echo UI::status_badge( $status ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core UI escapes.
		echo '<strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $fix ) {
			echo '<p>' . esc_html( $fix ) . '</p>';
		}
		$url = add_query_arg( 'tab', 'report' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'View full report', 'iworksie-migrate' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Giving side.
	 *
	 * @return void
	 */
	private function render_give() {
		$stored = KeyStore::stored();
		$active = KeyStore::active();

		echo '<p>' . esc_html__( 'Create a key to let another site copy this site\'s Media Library files. The other site can only read files in wp-content/uploads; it cannot change anything here, see your database or reach any other folder. Script files and server config files (.php, .htaccess and similar) are never sent.', 'iworksie-migrate' ) . '</p>';

		echo '<section class="iwiemg-card" aria-labelledby="iwiemg-give-title">';
		echo '<h2 id="iwiemg-give-title">' . esc_html__( 'Migration key', 'iworksie-migrate' ) . '</h2>';

		echo '<p class="iwiemg-key-status" data-iwiemg-key-status>';
		if ( null !== $active ) {
			echo UI::status_badge( 'good' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo esc_html( __( 'A key is active.', 'iworksie-migrate' ) . ' ' . self::expires_label( (int) $active['expires'] ) . '.' );
			if ( ! empty( $active['last_used'] ) ) {
				/* translators: %s: human time difference */
				echo ' ' . esc_html( sprintf( __( 'Last used %s ago.', 'iworksie-migrate' ), human_time_diff( (int) $active['last_used'], time() ) ) );
			}
		} elseif ( null !== $stored ) {
			echo esc_html__( 'The last key has expired. Nobody can copy this site until you create a new one.', 'iworksie-migrate' );
		} else {
			echo esc_html__( 'No key yet. Nobody can copy this site until you create one.', 'iworksie-migrate' );
		}
		echo '</p>';

		echo '<div class="iwiemg-key-create">';
		echo '<label for="iwiemg-lifetime">' . esc_html__( 'Key works for', 'iworksie-migrate' ) . '</label> ';
		echo '<select id="iwiemg-lifetime">';
		echo '<option value="1h">' . esc_html__( '1 hour', 'iworksie-migrate' ) . '</option>';
		echo '<option value="24h" selected>' . esc_html__( '24 hours', 'iworksie-migrate' ) . '</option>';
		echo '<option value="7d">' . esc_html__( '7 days', 'iworksie-migrate' ) . '</option>';
		echo '</select> ';
		echo '<button type="button" class="button button-primary" data-iwiemg-key-create>' . esc_html( null === $active ? __( 'Create key', 'iworksie-migrate' ) : __( 'Replace key', 'iworksie-migrate' ) ) . '</button> ';
		echo '<button type="button" class="button" data-iwiemg-key-revoke' . ( null === $stored ? ' hidden' : '' ) . '>' . esc_html__( 'Revoke key', 'iworksie-migrate' ) . '</button>';
		echo '</div>';

		echo '<div class="iwiemg-key-output" data-iwiemg-key-output hidden>';
		echo '<p><strong>' . esc_html__( 'Copy this key now and paste it into the site you are copying to. For security it is only shown once.', 'iworksie-migrate' ) . '</strong></p>';
		echo '<pre id="iwiemg-key-value" class="iwiemg-key-value" tabindex="0"></pre>';
		echo UI::copy_button( 'iwiemg-key-value' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Last report.
	 *
	 * @return void
	 */
	private function render_report() {
		$summary = get_option( 'iwiemg_last_report', null );
		if ( ! is_array( $summary ) ) {
			echo '<div class="iwt-empty">';
			echo '<h2 class="iwt-empty__title">' . esc_html__( 'No copy has run on this site yet', 'iworksie-migrate' ) . '</h2>';
			echo '<p class="iwt-empty__body">' . esc_html__( 'After a copy finishes, its full report (what was copied, skipped, kept and verified) appears here.', 'iworksie-migrate' ) . '</p>';
			echo '</div>';
			return;
		}
		$markdown = ReportBuilder::markdown( $summary );
		$this->echo_outcome_simple( ReportBuilder::status( $summary ), ReportBuilder::headline( $summary ) );
		echo '<div class="iwt-toolbar">';
		echo UI::copy_button( 'iwiemg-report' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ' <a class="button" href="' . esc_url( $this->core->report()->store_for_download( $markdown ) ) . '">' . esc_html__( 'Download report', 'iworksie-migrate' ) . '</a>';
		echo '</div>';
		echo '<pre id="iwiemg-report" class="iwiemg-report">' . esc_html( $markdown ) . '</pre>';
	}

	/**
	 * Outcome line without the report button.
	 *
	 * @param string $status   Status.
	 * @param string $headline Text.
	 * @return void
	 */
	private function echo_outcome_simple( $status, $headline ) {
		echo '<div class="iwiemg-outcome__box iwiemg-outcome__box--' . esc_attr( $status ) . '">';
		echo UI::status_badge( $status ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<strong>' . esc_html( $headline ) . '</strong>';
		echo '</div>';
	}
}
