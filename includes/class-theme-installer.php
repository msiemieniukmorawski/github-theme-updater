<?php
/**
 * Theme installation orchestration.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Installs a release over the managed theme.
 *
 * The order of operations is what matters here: nothing is deleted before the
 * new version has been downloaded, unpacked and validated, and a failed copy
 * rolls the theme back to the snapshot taken moments earlier.
 */
final class Theme_Installer {

	/**
	 * Option row claimed for the duration of an installation.
	 *
	 * An option rather than a transient: the claim is a single INSERT IGNORE,
	 * so two requests arriving in the same second cannot both succeed. The
	 * transient API reads first and writes second, which leaves that gap open.
	 */
	const LOCK_OPTION = 'gthu_install_lock';

	/**
	 * How long a lock is honoured before it counts as abandoned.
	 */
	const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

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
	 * Downloads and installs a release.
	 *
	 * Only one installation may run at a time: two of them interleaving inside
	 * the same theme directory would leave a mix of both versions on disk.
	 *
	 * @param Release $release Release to install.
	 * @param bool    $locked  Whether the caller already holds the lock from lock().
	 * @return array<string, mixed>|WP_Error Installation summary.
	 */
	public function install( Release $release, $locked = false ) {
		if ( ! $locked && ! self::lock() ) {
			return self::locked_error();
		}

		$state    = $this->settings->state();
		$previous = (string) $state['installed_version'];
		$started  = microtime( true );

		$result = $this->run( $release );

		Install_Progress::clear();
		self::unlock();

		History::record(
			array(
				'action'   => 'install',
				'status'   => is_wp_error( $result ) ? 'error' : 'success',
				'version'  => $release->tag(),
				'previous' => $previous,
				'message'  => is_wp_error( $result ) ? $result->get_error_message() : '',
				'duration' => microtime( true ) - $started,
				'files'    => is_array( $result ) ? (int) $result['files'] : 0,
				'backup'   => is_array( $result ) && ! empty( $result['backup']['id'] ) ? (string) $result['backup']['id'] : '',
			)
		);

		return $result;
	}

	/**
	 * Whether an installation or a rollback is running right now.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		$since = self::locked_since();

		return $since > 0 && ( time() - $since ) < self::LOCK_TTL;
	}

	/**
	 * When the running operation claimed the lock.
	 *
	 * Read straight from the database: the option cache of this request may
	 * predate a claim made by another one.
	 *
	 * @return int Unix timestamp, or 0 when nothing holds the lock.
	 */
	public static function locked_since() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached, see above.
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

		return (int) $since;
	}

	/**
	 * Claims the lock.
	 *
	 * @return bool Whether this request now holds the lock.
	 */
	public static function lock() {
		global $wpdb;

		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A single statement is what makes the claim atomic.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				(string) $now
			)
		);

		if ( ! $claimed ) {
			$since = self::locked_since();

			if ( $since > 0 && ( $now - $since ) < self::LOCK_TTL ) {
				return false;
			}

			// The holder never finished. Take over, unless someone else did
			// between the read above and this write.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					(string) $now,
					self::LOCK_OPTION,
					(string) $since
				)
			);
		}
		// phpcs:enable

		self::forget_option_cache();

		return (bool) $claimed;
	}

	/**
	 * Releases the lock.
	 *
	 * @return void
	 */
	public static function unlock() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirrors the direct claim in lock().
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );

		self::forget_option_cache();
	}

	/**
	 * Drops the option caches that the direct queries above bypassed.
	 *
	 * @return void
	 */
	private static function forget_option_cache() {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Error returned to whoever arrives second.
	 *
	 * @return WP_Error
	 */
	public static function locked_error() {
		return new WP_Error(
			'gthu_locked',
			__( 'Another operation on the theme files is already running. Wait a moment and reload this page.', 'github-theme-updater' )
		);
	}

	/**
	 * Performs the installation itself.
	 *
	 * @param Release $release Release to install.
	 * @return array<string, mixed>|WP_Error Installation summary.
	 */
	private function run( Release $release ) {
		// Losing the browser mid-copy would leave the theme half written, so the
		// request is allowed to finish on its own.
		ignore_user_abort( true );
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$theme_dir = $this->settings->theme_dir();

		if ( '' === $theme_dir ) {
			return new WP_Error(
				'gthu_no_theme_slug',
				__( 'No theme directory is set. Fill in the “Theme directory” field under Settings.', 'github-theme-updater' )
			);
		}

		$fs = Filesystem::get();

		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$themes_root = untrailingslashit( get_theme_root() );

		if ( ! $fs->is_writable( $themes_root ) ) {
			return new WP_Error(
				'gthu_themes_not_writable',
				sprintf(
					/* translators: %s: themes directory path. */
					__( 'The themes directory is not writable: %s', 'github-theme-updater' ),
					$themes_root
				)
			);
		}

		/**
		 * Fires before an update starts.
		 *
		 * @param Release $release Release being installed.
		 */
		do_action( 'gthu_before_install', $release );

		Install_Progress::step( 'download' );

		$archive = $this->client->download( $release );

		if ( is_wp_error( $archive ) ) {
			return $this->fail( $archive );
		}

		$workspace = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/gthu-' . wp_generate_password( 8, false, false );

		if ( ! $fs->mkdir( $workspace ) ) {
			$this->cleanup( $fs, $archive, null );

			return $this->fail(
				new WP_Error(
					'gthu_workspace',
					sprintf(
						/* translators: %s: directory path. */
						__( 'Could not create the working directory %s.', 'github-theme-updater' ),
						$workspace
					)
				)
			);
		}

		Install_Progress::step( 'unpack' );

		$unzipped = unzip_file( $archive, $workspace );

		if ( is_wp_error( $unzipped ) ) {
			$this->cleanup( $fs, $archive, $workspace );

			return $this->fail(
				new WP_Error(
					'gthu_unzip',
					sprintf(
						/* translators: %s: underlying error message. */
						__( 'Could not unpack the archive: %s', 'github-theme-updater' ),
						$unzipped->get_error_message()
					)
				)
			);
		}

		$source = $fs->locate_theme_root( $workspace );

		if ( is_wp_error( $source ) ) {
			$this->cleanup( $fs, $archive, $workspace );

			return $this->fail( $source );
		}

		$identity = $this->verify_identity( $fs, $source, $theme_dir );

		if ( is_wp_error( $identity ) ) {
			$this->cleanup( $fs, $archive, $workspace );

			return $this->fail( $identity );
		}

		$protected = $this->settings->protected_paths();
		$skip      = $this->settings->skipped_paths();

		// Everything the update will delete has to be deletable before the
		// first file goes: an undeletable file discovered halfway through
		// emptying the directory would leave the site without a theme.
		if ( $fs->is_dir( $theme_dir ) ) {
			Install_Progress::step( 'verify' );

			$deletable = $fs->ensure_deletable( $theme_dir, $skip );

			if ( is_wp_error( $deletable ) ) {
				$this->cleanup( $fs, $archive, $workspace );

				return $this->fail( $deletable );
			}
		}

		// Snapshot before anything in the live theme is touched.
		$state  = $this->settings->state();
		$backup = null;

		if ( $this->settings->get( 'create_backup', true ) && $fs->is_dir( $theme_dir ) ) {
			Install_Progress::step( 'backup' );

			$backup = $this->backups->create(
				$fs,
				(string) $state['installed_version'],
				Install_Progress::file_counter(),
				$this->settings->ignored_paths()
			);

			if ( is_wp_error( $backup ) ) {
				$this->cleanup( $fs, $archive, $workspace );

				return $this->fail(
					new WP_Error(
						'gthu_backup_failed',
						sprintf(
							/* translators: %s: underlying error message. */
							__( 'The update was stopped because the backup failed: %s', 'github-theme-updater' ),
							$backup->get_error_message()
						)
					)
				);
			}
		} else {
			Install_Progress::skip( 'backup' );
		}//end if

		if ( ! $fs->mkdir( $theme_dir ) ) {
			$this->cleanup( $fs, $archive, $workspace );

			return $this->fail(
				new WP_Error(
					'gthu_theme_dir',
					sprintf(
						/* translators: %s: directory path. */
						__( 'Could not create the theme directory %s.', 'github-theme-updater' ),
						$theme_dir
					)
				)
			);
		}

		Install_Progress::step( 'clean' );

		$emptied = $fs->empty_dir( $theme_dir, $skip );

		if ( is_wp_error( $emptied ) ) {
			$restored = $this->rollback( $fs, $backup );

			$this->cleanup( $fs, $archive, $workspace );

			return $this->fail( new WP_Error( 'gthu_delete_failed', $this->rollback_note( $emptied->get_error_message(), $restored, $backup ) ) );
		}

		Install_Progress::step( 'copy' );

		$copied_files = 0;
		$count_files  = Install_Progress::file_counter();

		$copied = $fs->copy_tree(
			$source,
			$theme_dir,
			$skip,
			'',
			static function ( $path ) use ( $count_files, &$copied_files ) {
				++$copied_files;
				$count_files( $path );
			}
		);

		if ( is_wp_error( $copied ) ) {
			$restored = $this->rollback( $fs, $backup );

			$this->cleanup( $fs, $archive, $workspace );

			return $this->fail( new WP_Error( 'gthu_copy_failed', $this->rollback_note( $copied->get_error_message(), $restored, $backup ) ) );
		}

		Install_Progress::step( 'finish' );

		$this->cleanup( $fs, $archive, $workspace );

		$this->settings->update_state(
			array(
				'installed_version' => $release->tag(),
				'installed_at'      => time(),
				'installed_by'      => get_current_user_id(),
				'last_error'        => '',
				'latest_version'    => $release->tag(),
			)
		);

		wp_clean_themes_cache();
		delete_site_transient( 'update_themes' );
		Update_Checker::forget_dismissals();

		$summary = array(
			'version'   => $release->tag(),
			'label'     => $release->label(),
			'backup'    => is_array( $backup ) ? $backup : null,
			'protected' => $protected->all(),
			'files'     => $copied_files,
		);

		/**
		 * Fires after a successful update.
		 *
		 * @param Release $release Installed release.
		 * @param array   $summary Installation summary.
		 */
		do_action( 'gthu_after_install', $release, $summary );

		return $summary;
	}

	/**
	 * Makes sure the archive is a theme, and the theme this directory holds.
	 *
	 * A wrong repository or a wrong directory name would otherwise wipe theme
	 * X and install theme Y without a word. The first install into an empty
	 * directory has nothing to compare against and is let through.
	 *
	 * @param Filesystem $fs        Filesystem.
	 * @param string     $source    Theme root inside the unpacked archive.
	 * @param string     $theme_dir Live theme directory.
	 * @return true|WP_Error
	 */
	private function verify_identity( Filesystem $fs, $source, $theme_dir ) {
		$incoming = self::theme_headers( $source );

		if ( '' === $incoming['name'] ) {
			return new WP_Error(
				'gthu_not_a_theme',
				__( 'The archive’s style.css has no “Theme Name” header, so it does not look like a WordPress theme.', 'github-theme-updater' )
			);
		}

		if ( ! $fs->exists( trailingslashit( $theme_dir ) . 'style.css' ) ) {
			return true;
		}

		$current = self::theme_headers( $theme_dir );

		if ( '' === $current['name'] ) {
			return true;
		}

		$matches = 0 === strcasecmp( $incoming['name'], $current['name'] )
			|| ( '' !== $incoming['text_domain'] && $incoming['text_domain'] === $current['text_domain'] );

		/**
		 * Filters whether the downloaded theme is accepted as the installed one.
		 *
		 * @param bool                  $matches  Whether the names or text domains agree.
		 * @param array<string, string> $incoming Headers of the theme in the archive.
		 * @param array<string, string> $current  Headers of the theme on disk.
		 */
		if ( apply_filters( 'gthu_theme_identity_matches', $matches, $incoming, $current ) ) {
			return true;
		}

		return new WP_Error(
			'gthu_theme_mismatch',
			sprintf(
				/* translators: 1: theme name in the archive, 2: theme directory name, 3: theme name on disk. */
				__( 'The archive holds the theme “%1$s”, but the directory %2$s contains “%3$s”. Check the repository and the theme directory under Settings. Nothing has been changed.', 'github-theme-updater' ),
				$incoming['name'],
				wp_basename( $theme_dir ),
				$current['name']
			)
		);
	}

	/**
	 * Reads the identifying headers of a theme's style.css.
	 *
	 * @param string $directory Theme directory.
	 * @return array{name: string, text_domain: string}
	 */
	private static function theme_headers( $directory ) {
		$headers = get_file_data(
			trailingslashit( $directory ) . 'style.css',
			array(
				'name'        => 'Theme Name',
				'text_domain' => 'Text Domain',
			)
		);

		return array(
			'name'        => trim( (string) $headers['name'] ),
			'text_domain' => trim( (string) $headers['text_domain'] ),
		);
	}

	/**
	 * Restores the theme from a snapshot taken during this run.
	 *
	 * @param Filesystem                $fs     Filesystem.
	 * @param array<string, mixed>|null $backup Backup metadata, if one was taken.
	 * @return bool Whether the theme was restored.
	 */
	private function rollback( Filesystem $fs, $backup ) {
		if ( ! is_array( $backup ) || empty( $backup['id'] ) ) {
			return false;
		}

		Install_Progress::step( 'rollback' );

		return ! is_wp_error( $this->backups->restore( $fs, $backup['id'] ) );
	}

	/**
	 * Appends what happened to the theme after a failure.
	 *
	 * @param string                    $message  Failure message.
	 * @param bool                      $restored Whether the rollback succeeded.
	 * @param array<string, mixed>|null $backup   Backup taken during this run, if any.
	 * @return string
	 */
	private function rollback_note( $message, $restored, $backup ) {
		if ( $restored ) {
			return $message . ' ' . __( 'The theme was restored from the backup.', 'github-theme-updater' );
		}

		if ( is_array( $backup ) ) {
			return $message . ' ' . __( 'WARNING: the theme could not be restored automatically — use the Backups tab.', 'github-theme-updater' );
		}

		return $message . ' ' . __( 'WARNING: backups are turned off, so the theme could not be restored — the theme directory may be incomplete.', 'github-theme-updater' );
	}

	/**
	 * Removes the downloaded archive and the working directory.
	 *
	 * @param Filesystem  $fs        Filesystem.
	 * @param string|null $archive   Downloaded archive path.
	 * @param string|null $workspace Working directory path.
	 * @return void
	 */
	private function cleanup( Filesystem $fs, $archive, $workspace ) {
		if ( $archive && file_exists( $archive ) ) {
			wp_delete_file( $archive );
		}

		if ( $workspace ) {
			$fs->delete( $workspace );
		}
	}

	/**
	 * Records the failure so the admin screen can show it later.
	 *
	 * @param WP_Error $error Failure.
	 * @return WP_Error
	 */
	private function fail( WP_Error $error ) {
		$this->settings->update_state( array( 'last_error' => $error->get_error_message() ) );

		/**
		 * Fires when an update fails.
		 *
		 * @param WP_Error $error Failure.
		 */
		do_action( 'gthu_install_failed', $error );

		return $error;
	}
}
