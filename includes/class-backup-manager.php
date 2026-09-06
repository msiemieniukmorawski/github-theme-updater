<?php
/**
 * Theme backup handling.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a rotating set of theme snapshots taken right before each update.
 *
 * A snapshot is a plain directory copy, which makes restoring it a copy in the
 * other direction — no archive format to get wrong while the site is broken.
 */
final class Backup_Manager {

	/**
	 * Metadata file stored inside every backup.
	 */
	const META_FILE = 'gthu-backup.json';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Directory used by versions up to 2.0.0.
	 */
	const LEGACY_DIR = 'upgrade/gthu-backups';

	/**
	 * Absolute path of the backup directory.
	 *
	 * Deliberately NOT under `wp-content/upgrade/`: `WP_Upgrader::unpack_package()`
	 * wipes that directory before every core, plugin and theme update, so backups
	 * stored there would disappear the next time anything else is updated.
	 *
	 * @return string
	 */
	public function dir() {
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'gthu-backups';

		return untrailingslashit( (string) apply_filters( 'gthu_backup_dir', $dir ) );
	}

	/**
	 * Absolute path of the pre-2.0.1 backup directory.
	 *
	 * @return string
	 */
	public static function legacy_dir() {
		return untrailingslashit( trailingslashit( WP_CONTENT_DIR ) . self::LEGACY_DIR );
	}

	/**
	 * Creates the backup directory and blocks direct web access to it.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @return true|WP_Error
	 */
	private function ensure_dir( Filesystem $fs ) {
		$dir = $this->dir();

		if ( ! $fs->mkdir( $dir ) ) {
			return new WP_Error(
				'gthu_backup_dir',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the backup directory: %s', 'github-theme-updater' ),
					$dir
				)
			);
		}

		// Backups contain theme source, so they must never be served over HTTP.
		if ( ! $fs->exists( $dir . '/index.php' ) ) {
			$fs->put( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		if ( ! $fs->exists( $dir . '/.htaccess' ) ) {
			$fs->put( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		if ( ! $fs->exists( $dir . '/web.config' ) ) {
			$fs->put(
				$dir . '/web.config',
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n"
			);
		}

		return true;
	}

	/**
	 * Moves backups out of the pre-2.0.1 location.
	 *
	 * Anything left under `wp-content/upgrade/` is on borrowed time, because
	 * WordPress empties that directory on its next update of anything.
	 *
	 * @return bool Whether backups were moved.
	 */
	public static function migrate_legacy_dir() {
		$legacy = self::legacy_dir();

		// Cheap check first: on almost every request there is nothing to do.
		if ( ! is_dir( $legacy ) ) {
			return false;
		}

		$fs = Filesystem::get();

		if ( is_wp_error( $fs ) ) {
			return false;
		}

		$target = untrailingslashit(
			(string) apply_filters( 'gthu_backup_dir', trailingslashit( WP_CONTENT_DIR ) . 'gthu-backups' )
		);

		if ( $fs->exists( $target ) ) {
			// Both exist: keep the new one and drop the doomed copy.
			return $fs->delete( $legacy );
		}

		return $fs->move( $legacy, $target );
	}

	/**
	 * Takes a snapshot of the current theme directory.
	 *
	 * @param Filesystem      $fs      Filesystem.
	 * @param string          $version Version being replaced.
	 * @param callable|null   $on_file Called after every copied file.
	 * @param Path_Rules|null $exclude Paths left out of the backup.
	 * @return array<string, mixed>|WP_Error Backup metadata.
	 */
	public function create( Filesystem $fs, $version = '', ?callable $on_file = null, ?Path_Rules $exclude = null ) {
		$theme_dir = $this->settings->theme_dir();

		if ( '' === $theme_dir || ! $fs->is_dir( $theme_dir ) ) {
			return new WP_Error(
				'gthu_backup_source',
				__( 'There is nothing to back up — the theme directory does not exist.', 'github-theme-updater' )
			);
		}

		$prepared = $this->ensure_dir( $fs );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$slug        = (string) $this->settings->get( 'theme_slug', '' );
		$id          = $this->build_id( $slug, $version );
		$destination = $this->dir() . '/' . $id;

		$copied = $fs->copy_tree( $theme_dir, $destination, $exclude, '', $on_file );

		if ( is_wp_error( $copied ) ) {
			$fs->delete( $destination );

			return $copied;
		}

		$meta = array(
			'id'         => $id,
			'theme_slug' => $slug,
			'version'    => (string) $version,
			'created'    => time(),
			'created_by' => get_current_user_id(),
			'size'       => $fs->size( $destination ),
		);

		$fs->put( $destination . '/' . self::META_FILE, wp_json_encode( $meta ) );

		$this->prune( $fs );

		return $meta;
	}

	/**
	 * Restores a snapshot over the theme directory.
	 *
	 * The theme directory is emptied first, so files added after the backup do
	 * not survive the rollback. Protected paths are left alone.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @param string     $id Backup identifier.
	 * @return array<string, mixed>|WP_Error Restored backup metadata.
	 */
	public function restore( Filesystem $fs, $id ) {
		$backup = $this->get( $fs, $id );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$theme_dir = $this->settings->theme_dir();

		if ( '' === $theme_dir ) {
			return new WP_Error(
				'gthu_no_theme',
				__( 'No theme directory is set.', 'github-theme-updater' )
			);
		}

		if ( ! $fs->mkdir( $theme_dir ) ) {
			return new WP_Error(
				'gthu_theme_dir',
				__( 'Could not create the theme directory.', 'github-theme-updater' )
			);
		}

		$protected = $this->settings->skipped_paths();

		$deletable = $fs->ensure_deletable( $theme_dir, $protected );

		if ( is_wp_error( $deletable ) ) {
			return $deletable;
		}

		$emptied = $fs->empty_dir( $theme_dir, $protected );

		if ( is_wp_error( $emptied ) ) {
			return $emptied;
		}

		// The metadata file belongs to the backup, not to the theme.
		$skip = Path_Rules::from_array( array_merge( $protected->all(), array( self::META_FILE ) ) );

		$copied = $fs->copy_tree( $this->path( $id ), $theme_dir, $skip );

		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		return $backup;
	}

	/**
	 * Returns one backup.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @param string     $id Backup identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( Filesystem $fs, $id ) {
		$id = self::sanitize_id( $id );

		if ( '' === $id || ! $fs->is_dir( $this->path( $id ) ) ) {
			return new WP_Error(
				'gthu_backup_missing',
				__( 'The requested backup was not found.', 'github-theme-updater' )
			);
		}

		return $this->read_meta( $fs, $id );
	}

	/**
	 * Returns every backup, newest first.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( Filesystem $fs ) {
		$dir = $this->dir();

		if ( ! $fs->is_dir( $dir ) ) {
			return array();
		}

		$list = $fs->raw()->dirlist( $dir, false, false );

		if ( ! is_array( $list ) ) {
			return array();
		}

		$backups = array();

		foreach ( $list as $name => $info ) {
			if ( 'd' !== $info['type'] ) {
				continue;
			}

			$backups[] = $this->read_meta( $fs, $name );
		}

		usort(
			$backups,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);

		return $backups;
	}

	/**
	 * Deletes one backup.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @param string     $id Backup identifier.
	 * @return true|WP_Error
	 */
	public function delete( Filesystem $fs, $id ) {
		$id = self::sanitize_id( $id );

		if ( '' === $id || ! $fs->is_dir( $this->path( $id ) ) ) {
			return new WP_Error(
				'gthu_backup_missing',
				__( 'The requested backup was not found.', 'github-theme-updater' )
			);
		}

		if ( ! $fs->delete( $this->path( $id ) ) ) {
			return new WP_Error(
				'gthu_backup_delete',
				__( 'Could not delete the backup.', 'github-theme-updater' )
			);
		}

		return true;
	}

	/**
	 * Removes the oldest backups above the configured limit.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @return int Number of backups removed.
	 */
	public function prune( Filesystem $fs ) {
		$limit   = (int) $this->settings->get( 'backup_limit', 3 );
		$backups = $this->all( $fs );
		$removed = 0;

		if ( count( $backups ) <= $limit ) {
			return 0;
		}

		foreach ( array_slice( $backups, $limit ) as $backup ) {
			if ( ! is_wp_error( $this->delete( $fs, $backup['id'] ) ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Deletes every backup and the directory itself.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @return void
	 */
	public function delete_all( Filesystem $fs ) {
		$fs->delete( $this->dir() );
	}

	/**
	 * Absolute path of a backup.
	 *
	 * @param string $id Backup identifier.
	 * @return string
	 */
	public function path( $id ) {
		return $this->dir() . '/' . self::sanitize_id( $id );
	}

	/**
	 * Reads the metadata of a backup, falling back to what the name encodes.
	 *
	 * @param Filesystem $fs Filesystem.
	 * @param string     $id Backup identifier.
	 * @return array<string, mixed>
	 */
	private function read_meta( Filesystem $fs, $id ) {
		$defaults = array(
			'id'         => $id,
			'theme_slug' => '',
			'version'    => '',
			'created'    => 0,
			'created_by' => 0,
			'size'       => 0,
		);

		$raw = $fs->read( $this->path( $id ) . '/' . self::META_FILE );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				$meta       = array_merge( $defaults, $decoded );
				$meta['id'] = $id;

				return $meta;
			}
		}

		// No metadata: recover what we can from the directory name.
		$parts = explode( '__', $id );

		if ( count( $parts ) >= 3 ) {
			$defaults['theme_slug'] = $parts[0];
			$defaults['version']    = $parts[1];
			$defaults['created']    = (int) $parts[2];
		}

		return $defaults;
	}

	/**
	 * Builds a backup identifier.
	 *
	 * @param string $slug    Theme slug.
	 * @param string $version Version being archived.
	 * @return string
	 */
	private function build_id( $slug, $version ) {
		$version = '' === (string) $version ? 'no-version' : (string) $version;

		return self::sanitize_id( $slug . '__' . $version . '__' . time() );
	}

	/**
	 * Keeps identifiers to a safe character set so they can never escape the
	 * backup directory.
	 *
	 * @param string $id Raw identifier.
	 * @return string
	 */
	public static function sanitize_id( $id ) {
		$id = (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $id );

		// Dots alone, or `..` anywhere, would resolve to a parent directory and
		// turn "delete this backup" into "delete wp-content". Not a backup ID.
		if ( '' === trim( $id, '.' ) || false !== strpos( $id, '..' ) ) {
			return '';
		}

		return $id;
	}
}
