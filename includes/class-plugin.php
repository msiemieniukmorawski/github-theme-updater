<?php
/**
 * Plugin bootstrapper.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin services together and registers WordPress hooks.
 *
 * The class is intentionally the only place that knows about the full object
 * graph, so every other class can stay dependency-injected and testable.
 */
final class Plugin {

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * GitHub API client.
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
	 * Admin screen controller.
	 *
	 * @var Admin_Page
	 */
	private $admin_page;

	/**
	 * Form/POST controller.
	 *
	 * @var Admin_Actions
	 */
	private $actions;

	/**
	 * Background update checker.
	 *
	 * @var Update_Checker
	 */
	private $checker;

	/**
	 * Builds the object graph.
	 */
	public function __construct() {
		$this->settings   = new Settings();
		$this->client     = new Github_Client( $this->settings );
		$this->backups    = new Backup_Manager( $this->settings );
		$this->installer  = new Theme_Installer( $this->settings, $this->client, $this->backups );
		$this->admin_page = new Admin_Page( $this->settings, $this->client, $this->backups );
		$this->actions    = new Admin_Actions( $this->settings, $this->client, $this->backups, $this->installer );
		$this->checker    = new Update_Checker( $this->settings, $this->client );
	}

	/**
	 * Registers every hook the plugin needs.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( Lifecycle::class, 'maybe_upgrade' ) );

		$this->settings->register();
		$this->admin_page->register();
		$this->actions->register();
		$this->checker->register();

		Notices::register();
	}

	/**
	 * Loads the plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'github-theme-updater',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Settings repository accessor.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * GitHub client accessor.
	 *
	 * @return Github_Client
	 */
	public function client() {
		return $this->client;
	}

	/**
	 * Backup manager accessor.
	 *
	 * @return Backup_Manager
	 */
	public function backups() {
		return $this->backups;
	}

	/**
	 * Theme installer accessor.
	 *
	 * @return Theme_Installer
	 */
	public function installer() {
		return $this->installer;
	}
}
