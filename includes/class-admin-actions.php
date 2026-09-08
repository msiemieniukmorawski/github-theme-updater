<?php
/**
 * Form handlers.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Handles every state changing request.
 *
 * All actions follow post/redirect/get: they verify a nonce and a capability,
 * do the work, queue a notice and send the browser back to the tab it came
 * from. Nothing mutates state on a plain page view.
 */
final class Admin_Actions {

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * GitHub client.
	 *
	 * @var Github_Client
	 */
	private $client;

	/**
	 * Backup manager.
	 *
	 * @var Backup_Manager
	 */
	private $backups;

	/**
	 * Theme installer.
	 *
	 * @var Theme_Installer
	 */
	private $installer;

	/**
	 * Automatic updater.
	 *
	 * @var Auto_Updater
	 */
	private $auto_updater;

	/**
	 * E-mail reports.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings     Settings repository.
	 * @param Github_Client   $client       GitHub client.
	 * @param Backup_Manager  $backups      Backup manager.
	 * @param Theme_Installer $installer    Theme installer.
	 * @param Auto_Updater    $auto_updater Automatic updater.
	 * @param Notifier        $notifier     E-mail reports.
	 */
	public function __construct( Settings $settings, Github_Client $client, Backup_Manager $backups, Theme_Installer $installer, Auto_Updater $auto_updater, Notifier $notifier ) {
		$this->settings     = $settings;
		$this->client       = $client;
		$this->backups      = $backups;
		$this->installer    = $installer;
		$this->auto_updater = $auto_updater;
		$this->notifier     = $notifier;
	}

	/**
	 * Registers the handlers.
	 *
	 * @return void
	 */
	public function register() {
		$actions = array(
			'gthu_install'        => 'handle_install',
			'gthu_restore_backup' => 'handle_restore_backup',
			'gthu_delete_backup'  => 'handle_delete_backup',
			'gthu_create_backup'  => 'handle_create_backup',
			'gthu_release_lock'   => 'handle_release_lock',
			'gthu_refresh'        => 'handle_refresh',
			'gthu_test'           => 'handle_test',
			'gthu_dismiss_update' => 'handle_dismiss_update',
			'gthu_run_auto'       => 'handle_run_auto',
			'gthu_test_email'     => 'handle_test_email',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}

		add_action( 'wp_ajax_gthu_progress', array( $this, 'handle_progress' ) );
	}

	/**
	 * Installs the requested version.
	 *
	 * @return void
	 */
	public function handle_install() {
		$this->authorize( 'gthu_install' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() ran check_admin_referer() above.
		$tag = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '';

		// The lock is taken before the release lookup so that the progress
		// record written next can never clobber a running installation's.
		if ( ! Theme_Installer::lock() ) {
			Notices::error( Theme_Installer::locked_error() );
			$this->redirect( 'update' );
		}

		Install_Progress::start( $tag );
		Install_Progress::step( 'resolve' );

		if ( '' === $tag || 'latest' === $tag ) {
			$release = $this->client->get_latest_release( true );
		} else {
			$release = $this->client->get_release_by_tag( $tag );
		}

		if ( is_wp_error( $release ) ) {
			Install_Progress::clear();
			Theme_Installer::unlock();
			Notices::error( $release );
			$this->redirect( 'update' );
		}

		$state     = $this->settings->state();
		$installed = (string) $state['installed_version'];

		$result = $this->installer->install( $release, true );

		if ( is_wp_error( $result ) ) {
			Notices::error( $result );
			$this->redirect( 'update' );
		}

		$message = sprintf(
			/* translators: %s: installed version label. */
			__( 'The theme was updated to version %s.', 'github-theme-updater' ),
			'<strong>' . esc_html( $result['label'] ) . '</strong>'
		);

		if ( '' !== $installed && $installed !== $release->tag() ) {
			$message .= ' ' . sprintf(
				/* translators: %s: previously installed version. */
				__( 'Previous version: %s.', 'github-theme-updater' ),
				esc_html( $installed )
			);
		}

		if ( ! empty( $result['protected'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma separated list of protected paths. */
				__( 'Protected paths were skipped: %s.', 'github-theme-updater' ),
				'<code>' . esc_html( implode( '</code>, <code>', $result['protected'] ) ) . '</code>'
			);
		}

		if ( ! empty( $result['backup'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: URL of the backups tab. */
				__( 'A backup of the previous version is on the <a href="%s">Backups</a> tab.', 'github-theme-updater' ),
				esc_url( Admin_Page::url( 'backups' ) )
			);
		}

		Notices::add( $message, 'success' );
		$this->redirect( 'update' );
	}

	/**
	 * Restores a backup over the theme.
	 *
	 * @return void
	 */
	public function handle_restore_backup() {
		$this->authorize( 'gthu_restore_backup' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() ran check_admin_referer() above.
		$id = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
		$fs = Filesystem::get();

		if ( is_wp_error( $fs ) ) {
			Notices::error( $fs );
			$this->redirect( 'backups' );
		}

		// A rollback rewrites the same files an update would, so it takes the
		// same lock rather than racing against one.
		if ( ! Theme_Installer::lock() ) {
			Notices::error( Theme_Installer::locked_error() );
			$this->redirect( 'backups' );
		}

		ignore_user_abort( true );

		$state    = $this->settings->state();
		$previous = (string) $state['installed_version'];
		$started  = microtime( true );

		$restored = $this->backups->restore( $fs, $id );

		Theme_Installer::unlock();

		History::record(
			array(
				'action'   => 'restore',
				'status'   => is_wp_error( $restored ) ? 'error' : 'success',
				'version'  => is_wp_error( $restored ) ? '' : (string) $restored['version'],
				'previous' => $previous,
				'message'  => is_wp_error( $restored ) ? $restored->get_error_message() : '',
				'duration' => microtime( true ) - $started,
				'backup'   => $id,
			)
		);

		if ( is_wp_error( $restored ) ) {
			Notices::error( $restored );
			$this->redirect( 'backups' );
		}

		$this->settings->update_state(
			array(
				'installed_version' => (string) $restored['version'],
				'installed_at'      => time(),
				'installed_by'      => get_current_user_id(),
				'last_error'        => '',
			)
		);

		wp_clean_themes_cache();

		Notices::add(
			sprintf(
				/* translators: %s: restored version. */
				__( 'The theme was restored from a backup (version %s).', 'github-theme-updater' ),
				'<strong>' . esc_html( '' !== $restored['version'] ? $restored['version'] : __( 'unknown', 'github-theme-updater' ) ) . '</strong>'
			),
			'success'
		);

		$this->redirect( 'backups' );
	}

	/**
	 * Deletes a backup.
	 *
	 * @return void
	 */
	public function handle_delete_backup() {
		$this->authorize( 'gthu_delete_backup' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() ran check_admin_referer() above.
		$id = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
		$fs = Filesystem::get();

		if ( is_wp_error( $fs ) ) {
			Notices::error( $fs );
			$this->redirect( 'backups' );
		}

		$deleted = $this->backups->delete( $fs, $id );

		if ( is_wp_error( $deleted ) ) {
			Notices::error( $deleted );
		} else {
			Notices::add( __( 'The backup was deleted.', 'github-theme-updater' ), 'success' );
		}

		$this->redirect( 'backups' );
	}

	/**
	 * Takes a backup of the theme as it is right now.
	 *
	 * @return void
	 */
	public function handle_create_backup() {
		$this->authorize( 'gthu_create_backup' );

		$fs = Filesystem::get();

		if ( is_wp_error( $fs ) ) {
			Notices::error( $fs );
			$this->redirect( 'backups' );
		}

		// A snapshot taken while an update is rewriting the directory would
		// be a mix of two versions, so it waits for the same lock.
		if ( ! Theme_Installer::lock() ) {
			Notices::error( Theme_Installer::locked_error() );
			$this->redirect( 'backups' );
		}

		ignore_user_abort( true );

		$state  = $this->settings->state();
		$backup = $this->backups->create( $fs, (string) $state['installed_version'], null, $this->settings->ignored_paths() );

		Theme_Installer::unlock();

		if ( is_wp_error( $backup ) ) {
			Notices::error( $backup );
		} else {
			Notices::add( __( 'A backup of the current theme was created.', 'github-theme-updater' ), 'success' );
		}

		$this->redirect( 'backups' );
	}

	/**
	 * Clears a lock left behind by a process that never finished.
	 *
	 * @return void
	 */
	public function handle_release_lock() {
		$this->authorize( 'gthu_release_lock' );

		Theme_Installer::unlock();
		Install_Progress::clear();

		Notices::add(
			__( 'The lock was released. Before starting an update, check the Backups tab to confirm the previous operation finished cleanly.', 'github-theme-updater' ),
			'warning'
		);

		$this->redirect( 'update' );
	}

	/**
	 * Drops the cached release list and checks GitHub again.
	 *
	 * @return void
	 */
	public function handle_refresh() {
		$this->authorize( 'gthu_refresh' );

		Github_Client::flush_cache();

		$release = $this->client->get_latest_release( true );

		if ( is_wp_error( $release ) ) {
			Notices::error( $release );
			$this->redirect( 'update' );
		}

		$this->settings->update_state(
			array(
				'last_check'     => time(),
				'latest_version' => $release->tag(),
				'last_error'     => '',
			)
		);

		Notices::add(
			sprintf(
				/* translators: %s: newest version found on GitHub. */
				__( 'Data refreshed from GitHub. Newest available version: %s.', 'github-theme-updater' ),
				'<strong>' . esc_html( $release->label() ) . '</strong>'
			),
			'info'
		);

		$this->redirect( 'update' );
	}

	/**
	 * Verifies the repository and credentials.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->authorize( 'gthu_test' );

		$info = $this->client->test_connection();

		if ( is_wp_error( $info ) ) {
			Notices::error( $info );
			$this->redirect( 'settings' );
		}

		Notices::add(
			sprintf(
				/* translators: 1: repository name, 2: visibility, 3: default branch. */
				__( 'The connection works. Repository: %1$s (%2$s), default branch: %3$s.', 'github-theme-updater' ),
				'<strong>' . esc_html( $info['full_name'] ) . '</strong>',
				$info['private'] ? esc_html__( 'private', 'github-theme-updater' ) : esc_html__( 'public', 'github-theme-updater' ),
				'<code>' . esc_html( $info['default_branch'] ) . '</code>'
			),
			'success'
		);

		$this->redirect( 'settings' );
	}

	/**
	 * Runs the automatic update sequence right now, e-mails included.
	 *
	 * The quickest way to confirm the whole chain works before trusting it
	 * to run unattended at night.
	 *
	 * @return void
	 */
	public function handle_run_auto() {
		$this->authorize( 'gthu_run_auto' );

		ignore_user_abort( true );

		$outcome = $this->auto_updater->run( 'manual' );

		switch ( $outcome['status'] ) {
			case 'updated':
				$type = 'success';
				break;

			case 'up_to_date':
				$type = 'info';
				break;

			case 'failed':
			case 'check_failed':
				$type = 'error';
				break;

			default:
				$type = 'warning';
		}

		$message = sprintf(
			/* translators: %s: outcome of the run. */
			__( 'Automatic update run: %s', 'github-theme-updater' ),
			esc_html( $outcome['message'] )
		);

		if ( in_array( $outcome['status'], array( 'updated', 'failed' ), true ) ) {
			$message .= ' ' . ( empty( $this->notifier->recipients( 'test' ) )
				? __( 'No report was e-mailed: no address is saved.', 'github-theme-updater' )
				: __( 'A report was e-mailed to the saved addresses.', 'github-theme-updater' ) );
		}

		Notices::add( $message, $type );
		$this->redirect( 'settings' );
	}

	/**
	 * Sends a test message to the saved addresses.
	 *
	 * @return void
	 */
	public function handle_test_email() {
		$this->authorize( 'gthu_test_email' );

		$sent = $this->notifier->test();

		if ( is_wp_error( $sent ) ) {
			Notices::error( $sent );
		} else {
			Notices::add(
				sprintf(
					/* translators: %s: comma separated list of addresses. */
					__( 'A test message was sent to: %s. If it does not arrive, check the spam folder and the mail configuration of the site.', 'github-theme-updater' ),
					'<strong>' . esc_html( implode( ', ', $this->notifier->recipients( 'test' ) ) ) . '</strong>'
				),
				'success'
			);
		}

		$this->redirect( 'settings' );
	}

	/**
	 * Hides the update notice for one version.
	 *
	 * @return void
	 */
	public function handle_dismiss_update() {
		if ( ! current_user_can( Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this operation.', 'github-theme-updater' ), 403 );
		}

		check_admin_referer( 'gthu_dismiss_update' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		$version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';

		update_option( Update_Checker::DISMISSED_OPTION, $version );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Reports the state of the running installation.
	 *
	 * Polled by the Update tab while an update request is pending.
	 *
	 * @return void
	 */
	public function handle_progress() {
		if ( ! current_user_can( Admin_Page::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		check_ajax_referer( 'gthu_progress' );
		nocache_headers();

		wp_send_json_success( Install_Progress::snapshot() );
	}

	/**
	 * Rejects the request unless it is authenticated and authorised.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this operation.', 'github-theme-updater' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Sends the browser back to a tab and stops execution.
	 *
	 * @param string $tab Tab slug.
	 * @return void
	 */
	private function redirect( $tab ) {
		$url = Admin_Page::url( $tab );

		// A request sent by the admin script gets the destination as JSON and
		// navigates there itself, so the notices queued above still show up.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller verified its nonce; this only picks the response format.
		if ( ! empty( $_POST['gthu_ajax'] ) ) {
			wp_send_json_success( array( 'redirect' => $url ) );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
