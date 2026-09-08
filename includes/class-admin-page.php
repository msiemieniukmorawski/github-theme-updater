<?php
/**
 * Admin screen.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu and renders the tabbed interface.
 */
final class Admin_Page {

	/**
	 * Menu and page slug.
	 */
	const MENU_SLUG = 'gthu-updater';

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
	 * Constructor.
	 *
	 * @param Settings       $settings Settings repository.
	 * @param Github_Client  $client   GitHub client.
	 * @param Backup_Manager $backups  Backup manager.
	 */
	public function __construct( Settings $settings, Github_Client $client, Backup_Manager $backups ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->backups  = $backups;
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capability required to use the plugin.
	 *
	 * On multisite the themes directory is shared by every site in the network,
	 * so overwriting a theme is a network-wide action. Requiring a network
	 * capability keeps a single site administrator from replacing a theme that
	 * other sites are running.
	 *
	 * @return string
	 */
	public static function capability() {
		$default = is_multisite() ? 'manage_network_themes' : 'manage_options';

		/**
		 * Filters the capability required to manage theme updates.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'gthu_capability', $default );
	}

	/**
	 * URL of the admin screen.
	 *
	 * @param string $tab Tab to open.
	 * @return string
	 */
	public static function url( $tab = 'update' ) {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Adds the menu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Theme updates from GitHub', 'github-theme-updater' ),
			__( 'Theme from GitHub', 'github-theme-updater' ),
			self::capability(),
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-update',
			61
		);
	}

	/**
	 * Adds a shortcut on the plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( 'settings' ) ),
			esc_html__( 'Settings', 'github-theme-updater' )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Loads the stylesheet on our screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'gthu-admin',
			PLUGIN_URL . 'assets/admin.css',
			array(),
			VERSION
		);

		wp_enqueue_script(
			'gthu-admin',
			PLUGIN_URL . 'assets/admin.js',
			array(),
			VERSION,
			true
		);

		wp_localize_script(
			'gthu-admin',
			'gthuAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'progressNonce' => wp_create_nonce( 'gthu_progress' ),
				'i18n'          => array(
					'skipped'     => __( 'Skipped — backups are turned off.', 'github-theme-updater' ),
					'pollFailed'  => __( 'The progress could not be read. The page will reload when the update finishes.', 'github-theme-updater' ),
					'interrupted' => __( 'The connection to the server was interrupted. Reloading the page…', 'github-theme-updater' ),
					'unsaved'     => __( 'You have unsaved changes in the settings. Leave the page anyway?', 'github-theme-updater' ),
				),
			)
		);
	}

	/**
	 * Available tabs.
	 *
	 * @return array<string,string> Tab slug mapped to its label.
	 */
	private function tabs() {
		return array(
			'update'   => __( 'Update', 'github-theme-updater' ),
			'backups'  => __( 'Backups', 'github-theme-updater' ),
			'settings' => __( 'Settings', 'github-theme-updater' ),
			'help'     => __( 'Instructions', 'github-theme-updater' ),
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage theme updates.', 'github-theme-updater' ) );
		}

		$tabs = $this->tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'update';

		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'update';
		}

		echo '<div class="wrap gthu-wrap">';
		echo '<h1>' . esc_html__( 'Theme updates from GitHub', 'github-theme-updater' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::url( $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
		echo '<div class="gthu-tab-content">';

		$this->render_tab( $current );

		echo '</div></div>';
	}

	/**
	 * Renders a single tab.
	 *
	 * @param string $tab Tab slug.
	 * @return void
	 */
	private function render_tab( $tab ) {
		switch ( $tab ) {
			case 'settings':
				$this->view(
					'tab-settings',
					array(
						'settings' => $this->settings,
						'state'    => $this->settings->state(),
						'next_run' => Auto_Updater::next_scheduled(),
					)
				);
				break;

			case 'backups':
				$this->render_backups_tab();
				break;

			case 'help':
				$this->view(
					'tab-help',
					array(
						'settings'   => $this->settings,
						'repository' => $this->settings->repository(),
					)
				);
				break;

			default:
				$this->render_update_tab();
		}//end switch
	}

	/**
	 * Renders the update tab, resolving the remote state first.
	 *
	 * @return void
	 */
	private function render_update_tab() {
		$state      = $this->settings->state();
		$configured = $this->settings->is_configured();
		$latest     = null;
		$releases   = array();
		$error      = null;

		if ( $configured ) {
			$latest = $this->client->get_latest_release();

			if ( is_wp_error( $latest ) ) {
				$error  = $latest;
				$latest = null;
			}

			if ( 'branch' !== $this->settings->get( 'source' ) ) {
				$fetched = $this->client->get_releases();

				if ( ! is_wp_error( $fetched ) ) {
					$releases = $fetched;
				}
			}
		}

		$this->view(
			'tab-update',
			array(
				'settings'         => $this->settings,
				'state'            => $state,
				'configured'       => $configured,
				'latest'           => $latest,
				'releases'         => $releases,
				'error'            => $error,
				'repository'       => $this->settings->repository(),
				'locked_since'     => Theme_Installer::locked_since(),
				'progress_running' => Install_Progress::is_running(),
				'history'          => History::all(),
			)
		);
	}

	/**
	 * Renders the backups tab.
	 *
	 * @return void
	 */
	private function render_backups_tab() {
		$fs      = Filesystem::get();
		$backups = array();
		$error   = null;

		if ( is_wp_error( $fs ) ) {
			$error = $fs;
		} else {
			$backups = $this->backups->all( $fs );
		}

		$this->view(
			'tab-backups',
			array(
				'settings' => $this->settings,
				'backups'  => $backups,
				'error'    => $error,
				'state'    => $this->settings->state(),
			)
		);
	}

	/**
	 * Includes a view file.
	 *
	 * @param string               $name View file name, without extension.
	 * @param array<string, mixed> $vars Variables exposed to the view.
	 * @return void
	 */
	private function view( $name, array $vars = array() ) {
		$file = PLUGIN_DIR . 'views/' . $name . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled, local variable set.
		extract( $vars, EXTR_SKIP );

		require $file;
	}
}
