<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted, and only removes data when the site
 * owner opted in to it in the settings. Theme files are never touched.
 *
 * @package MSM\GitHubThemeUpdater
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$gthu_settings = get_option( 'gthu_settings' );

// Always drop the transient caches and the scheduled check.
delete_transient( 'gthu_releases_cache' );
delete_transient( 'gthu_install_lock' );
delete_option( 'gthu_install_lock' );
delete_transient( 'gthu_install_progress' );
delete_transient( 'gthu_webhook_deliveries' );

wp_clear_scheduled_hook( 'gthu_check_for_updates' );
wp_clear_scheduled_hook( 'gthu_auto_update' );
wp_unschedule_hook( 'gthu_webhook_update' );

if ( ! is_array( $gthu_settings ) || empty( $gthu_settings['delete_data'] ) ) {
	return;
}

delete_option( 'gthu_settings' );
delete_option( 'gthu_state' );
delete_option( 'gthu_version' );
delete_option( 'gthu_dismissed_update' );
delete_option( 'gthu_history' );

// Options written by version 1.x.
delete_option( 'gthu_github_repo' );
delete_option( 'gthu_github_token' );
delete_option( 'gthu_theme_slug' );
delete_option( 'gthu_last_installed_version' );

/**
 * Backups live outside the database, so they need an explicit cleanup.
 *
 * Two directories are covered: the current one, matching the default in
 * Backup_Manager, and the `upgrade/` location used by releases before 2.1.
 * A site that upgraded from one of those can still hold files there.
 */
require_once ABSPATH . 'wp-admin/includes/file.php';

$gthu_backup_dirs = array(
	(string) apply_filters( 'gthu_backup_dir', trailingslashit( WP_CONTENT_DIR ) . 'gthu-backups' ),
	trailingslashit( WP_CONTENT_DIR ) . 'upgrade/gthu-backups',
);

if ( WP_Filesystem() ) {
	global $wp_filesystem;

	if ( $wp_filesystem ) {
		foreach ( array_unique( $gthu_backup_dirs ) as $gthu_backup_dir ) {
			if ( is_dir( $gthu_backup_dir ) ) {
				$wp_filesystem->delete( untrailingslashit( $gthu_backup_dir ), true );
			}
		}
	}
}
