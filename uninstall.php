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

$gthu_timestamp = wp_next_scheduled( 'gthu_check_for_updates' );

if ( $gthu_timestamp ) {
	wp_unschedule_event( $gthu_timestamp, 'gthu_check_for_updates' );
}

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
 */
$gthu_backup_dir = apply_filters( 'gthu_backup_dir', trailingslashit( WP_CONTENT_DIR ) . 'upgrade/gthu-backups' );

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( is_dir( $gthu_backup_dir ) && WP_Filesystem() ) {
	global $wp_filesystem;

	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $gthu_backup_dir, true );
	}
}
