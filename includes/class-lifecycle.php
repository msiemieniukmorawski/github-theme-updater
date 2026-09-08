<?php
/**
 * Activation, deactivation and data migrations.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that runs once rather than on every request.
 */
final class Lifecycle {

	/**
	 * Option tracking the schema version the database is on.
	 */
	const VERSION_OPTION = 'gthu_version';

	/**
	 * Option names used by version 1.x.
	 */
	const LEGACY_OPTIONS = array(
		'repository' => 'gthu_github_repo',
		'token'      => 'gthu_github_token',
		'theme_slug' => 'gthu_theme_slug',
		'version'    => 'gthu_last_installed_version',
	);

	/**
	 * Runs on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::migrate();
		Backup_Manager::migrate_legacy_dir();

		if ( ! get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		Update_Checker::schedule();
		plugin()->auto_updater()->sync_schedule();
		update_option( self::VERSION_OPTION, VERSION );
	}

	/**
	 * Runs on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Update_Checker::unschedule();
		Auto_Updater::unschedule_all();
		Github_Client::flush_cache();
	}

	/**
	 * Runs migrations after a plugin update that skipped activation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) === VERSION ) {
			return;
		}

		self::migrate();

		if ( is_admin() ) {
			Backup_Manager::migrate_legacy_dir();
		}

		Update_Checker::schedule();
		plugin()->auto_updater()->sync_schedule();
		update_option( self::VERSION_OPTION, VERSION );
	}

	/**
	 * Moves the version 1.x options into the new settings array.
	 *
	 * The old options are left untouched: if the update has to be rolled back,
	 * the previous version still finds its configuration.
	 *
	 * @return void
	 */
	private static function migrate() {
		$stored = get_option( Settings::OPTION );

		// Already migrated, or configured on the new schema.
		if ( is_array( $stored ) && ! empty( $stored['repository'] ) ) {
			return;
		}

		$legacy_repo = (string) get_option( self::LEGACY_OPTIONS['repository'], '' );
		$legacy_slug = (string) get_option( self::LEGACY_OPTIONS['theme_slug'], '' );

		if ( '' === $legacy_repo && '' === $legacy_slug ) {
			return;
		}

		$settings   = is_array( $stored ) ? array_merge( Settings::defaults(), $stored ) : Settings::defaults();
		$repository = Repository::from_string( $legacy_repo );

		if ( $repository ) {
			$settings['repository'] = $repository->full_name();
		}

		if ( '' !== $legacy_slug ) {
			$settings['theme_slug'] = Settings::sanitize_theme_slug( $legacy_slug );
		}

		$legacy_token = (string) get_option( self::LEGACY_OPTIONS['token'], '' );

		if ( '' !== $legacy_token ) {
			$settings['token'] = Token_Storage::encrypt( $legacy_token );
		}

		update_option( Settings::OPTION, $settings );

		$legacy_version = (string) get_option( self::LEGACY_OPTIONS['version'], '' );

		if ( '' !== $legacy_version ) {
			$state = get_option( Settings::STATE_OPTION, array() );
			$state = is_array( $state ) ? $state : array();

			// 1.x stored the archive file name; keep it only when it looks like a tag.
			if ( preg_match( '/^v?\d+(\.\d+)*/', $legacy_version ) ) {
				$state['installed_version'] = $legacy_version;
				update_option( Settings::STATE_OPTION, $state, false );
			}
		}
	}
}
