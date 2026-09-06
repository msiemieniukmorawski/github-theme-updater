<?php
/**
 * PHPStan bootstrap.
 *
 * Declares the constants the plugin defines at runtime, so static analysis can
 * resolve them without executing the bootstrap file.
 *
 * @package MSM\GitHubThemeUpdater
 */

define( 'MSM\GitHubThemeUpdater\PLUGIN_DIR', __DIR__ . '/../' );
define( 'MSM\GitHubThemeUpdater\PLUGIN_URL', 'https://example.com/wp-content/plugins/github-theme-updater/' );
