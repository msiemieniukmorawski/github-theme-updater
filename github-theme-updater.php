<?php
/**
 * Plugin Name:       GitHub Theme Updater
 * Plugin URI:        https://github.com/msiemieniukmorawski/github-theme-updater
 * Description:       Updates a WordPress theme straight from a GitHub repository, private ones included, with backups, version rollback and per-path protection against overwriting.
 * Version:           2.3.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ms-m.pl
 * Author URI:        https://ms-m.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       github-theme-updater
 * Domain Path:       /languages
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

const VERSION     = '2.3.1';
const PLUGIN_FILE = __FILE__;

define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader.
 *
 * Maps `MSM\GitHubThemeUpdater\Theme_Installer` to `includes/class-theme-installer.php`,
 * following the WordPress file naming convention.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Shared plugin instance.
 *
 * @return Plugin
 */
function plugin() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin();
	}

	return $instance;
}

plugin()->register();

register_activation_hook( __FILE__, array( Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle::class, 'deactivate' ) );
