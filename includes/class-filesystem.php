<?php
/**
 * WP_Filesystem wrapper.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use WP_Error;
use WP_Filesystem_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Recursive file operations that understand protected paths.
 *
 * Every write goes through WP_Filesystem instead of raw PHP calls, so file
 * ownership and permissions stay consistent with the rest of WordPress. The
 * one exception is mkdir(), which uses wp_mkdir_p() because it creates missing
 * parents and applies the WordPress directory permissions on the way.
 */
final class Filesystem {

	/**
	 * Underlying WordPress filesystem abstraction.
	 *
	 * @var WP_Filesystem_Base
	 */
	private $fs;

	/**
	 * Constructor.
	 *
	 * @param WP_Filesystem_Base $fs Initialised filesystem.
	 */
	private function __construct( WP_Filesystem_Base $fs ) {
		$this->fs = $fs;
	}

	/**
	 * Wraps an already initialised filesystem.
	 *
	 * Lets tests run the real WP_Filesystem_Direct against a temporary
	 * directory without booting WordPress.
	 *
	 * @param WP_Filesystem_Base $fs Initialised filesystem.
	 * @return Filesystem
	 */
	public static function wrap( WP_Filesystem_Base $fs ) {
		return new self( $fs );
	}

	/**
	 * Boots WP_Filesystem and wraps it.
	 *
	 * Only the `direct` transport is supported: an unattended update cannot
	 * prompt for FTP credentials halfway through deleting a theme.
	 *
	 * @return Filesystem|WP_Error
	 */
	public static function get() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$method = get_filesystem_method();

		if ( 'direct' !== $method ) {
			return new WP_Error(
				'gthu_filesystem_method',
				sprintf(
					/* translators: %s: filesystem method reported by WordPress, e.g. ftpext. */
					__( 'WordPress has no direct file access (method: %s). Add the FS_METHOD constant set to “direct” to wp-config.php, or fix the permissions on the themes directory.', 'github-theme-updater' ),
					$method
				)
			);
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'gthu_filesystem_init',
				__( 'Could not initialise the WordPress filesystem.', 'github-theme-updater' )
			);
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return new WP_Error(
				'gthu_filesystem_missing',
				__( 'The WordPress filesystem is unavailable.', 'github-theme-updater' )
			);
		}

		return new self( $wp_filesystem );
	}

	/**
	 * Raw filesystem accessor.
	 *
	 * @return WP_Filesystem_Base
	 */
	public function raw() {
		return $this->fs;
	}

	/**
	 * Whether a path exists.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function exists( $path ) {
		return $this->fs->exists( $path );
	}

	/**
	 * Whether a path is a directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function is_dir( $path ) {
		return $this->fs->is_dir( $path );
	}

	/**
	 * Whether a path is writable.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function is_writable( $path ) {
		return $this->fs->is_writable( $path );
	}

	/**
	 * Creates a directory, including missing parents.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function mkdir( $path ) {
		if ( $this->fs->is_dir( $path ) ) {
			return true;
		}

		return wp_mkdir_p( $path );
	}

	/**
	 * Deletes a file or a whole directory tree.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete( $path ) {
		if ( ! $this->fs->exists( $path ) ) {
			return true;
		}

		if ( $this->fs->delete( $path, true ) ) {
			return true;
		}

		// Second attempt with the read-only flags cleared. Git pack files and
		// files copied from Windows carry them routinely, and unlink() refuses
		// such files on Windows. Warnings from chmod() on files we do not own
		// are noise here: the retry below is what decides.
		@$this->fs->chmod( $path, false, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		clearstatcache();

		return $this->fs->delete( $path, true );
	}

	/**
	 * Makes sure everything an update would delete can actually be deleted.
	 *
	 * Runs before the theme directory is touched: an undeletable file found
	 * halfway through emptying the directory leaves the site without a theme.
	 * Read-only files are made writable on the spot; a directory the PHP user
	 * cannot write to is reported, because only its owner can fix that.
	 *
	 * @param string          $directory Absolute directory path.
	 * @param Path_Rules|null $rules     Paths that will be left alone.
	 * @param string          $relative  Internal: path relative to the root.
	 * @return true|WP_Error
	 */
	public function ensure_deletable( $directory, ?Path_Rules $rules = null, $relative = '' ) {
		if ( ! $this->fs->is_writable( $directory ) ) {
			return $this->not_deletable( '' === $relative ? wp_basename( $directory ) : $relative );
		}

		$list = $this->fs->dirlist( $directory, true, false );

		if ( false === $list ) {
			return new WP_Error(
				'gthu_dirlist',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not read the contents of the directory %s.', 'github-theme-updater' ),
					$directory
				)
			);
		}

		// On Windows unlink() refuses read-only files; elsewhere deleting a
		// file only needs a writable parent directory, checked above.
		$check_files = $this->is_windows();

		foreach ( $list as $name => $info ) {
			$child_relative = '' === $relative ? $name : $relative . '/' . $name;
			$path           = trailingslashit( $directory ) . $name;

			if ( $rules && $rules->matches( $child_relative ) ) {
				continue;
			}

			if ( 'd' === $info['type'] ) {
				$result = $this->ensure_deletable( $path, $rules, $child_relative );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				continue;
			}

			if ( $check_files && ! $this->fs->is_writable( $path ) ) {
				if ( ! $this->make_writable( $path ) ) {
					return $this->not_deletable( $child_relative );
				}
			}
		}//end foreach

		return true;
	}

	/**
	 * Clears the read-only flag of a file and reports whether that worked.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	private function make_writable( $path ) {
		$this->fs->chmod( $path, FS_CHMOD_FILE );
		clearstatcache( true, $path );

		return $this->fs->is_writable( $path );
	}

	/**
	 * Whether PHP runs on Windows, where unlink() refuses read-only files.
	 *
	 * @return bool
	 */
	private function is_windows() {
		return '\\' === DIRECTORY_SEPARATOR;
	}

	/**
	 * Error for a path the web server user cannot remove.
	 *
	 * @param string $relative Path relative to the theme root.
	 * @return WP_Error
	 */
	private function not_deletable( $relative ) {
		return new WP_Error(
			'gthu_not_deletable',
			sprintf(
				/* translators: %s: file or directory path relative to the theme root. */
				__( '%s cannot be deleted by the web server user. Check the ownership and permissions of the theme files. Nothing has been changed yet.', 'github-theme-updater' ),
				$relative
			)
		);
	}

	/**
	 * Moves a file or directory.
	 *
	 * @param string $source      Absolute source path.
	 * @param string $destination Absolute destination path.
	 * @return bool
	 */
	public function move( $source, $destination ) {
		return $this->fs->move( $source, $destination, false );
	}

	/**
	 * Writes a string to a file.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 * @return bool
	 */
	public function put( $path, $contents ) {
		return $this->fs->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	/**
	 * Reads a file.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public function read( $path ) {
		return $this->fs->get_contents( $path );
	}

	/**
	 * Copies a directory tree, skipping protected paths.
	 *
	 * @param string          $source      Absolute source directory.
	 * @param string          $destination Absolute destination directory.
	 * @param Path_Rules|null $rules       Paths that must not be written.
	 * @param string          $relative    Internal: path relative to the copy root.
	 * @param callable|null   $on_file     Called with the relative path after every copied file.
	 * @return true|WP_Error
	 */
	public function copy_tree( $source, $destination, ?Path_Rules $rules = null, $relative = '', ?callable $on_file = null ) {
		$list = $this->fs->dirlist( $source, true, false );

		if ( false === $list ) {
			return new WP_Error(
				'gthu_dirlist',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not read the contents of the directory %s.', 'github-theme-updater' ),
					$source
				)
			);
		}

		if ( ! $this->fs->is_dir( $destination ) && ! $this->mkdir( $destination ) ) {
			return new WP_Error(
				'gthu_mkdir',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the directory %s.', 'github-theme-updater' ),
					$destination
				)
			);
		}

		foreach ( $list as $name => $info ) {
			$child_relative = '' === $relative ? $name : $relative . '/' . $name;

			if ( $rules && $rules->matches( $child_relative ) ) {
				continue;
			}

			$source_path      = trailingslashit( $source ) . $name;
			$destination_path = trailingslashit( $destination ) . $name;

			if ( 'd' === $info['type'] ) {
				$result = $this->copy_tree( $source_path, $destination_path, $rules, $child_relative, $on_file );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				continue;
			}

			if ( ! $this->fs->copy( $source_path, $destination_path, true, FS_CHMOD_FILE ) ) {
				return new WP_Error(
					'gthu_copy',
					sprintf(
						/* translators: %s: file path. */
						__( 'Could not copy the file %s.', 'github-theme-updater' ),
						$child_relative
					)
				);
			}

			if ( $on_file ) {
				$on_file( $child_relative );
			}
		}//end foreach

		return true;
	}

	/**
	 * Empties a directory while keeping protected paths in place.
	 *
	 * @param string          $directory Absolute directory to empty.
	 * @param Path_Rules|null $rules     Paths that must survive.
	 * @param string          $relative  Internal: path relative to the root being emptied.
	 * @return true|WP_Error
	 */
	public function empty_dir( $directory, ?Path_Rules $rules = null, $relative = '' ) {
		$list = $this->fs->dirlist( $directory, true, false );

		if ( false === $list ) {
			return new WP_Error(
				'gthu_dirlist',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not read the contents of the directory %s.', 'github-theme-updater' ),
					$directory
				)
			);
		}

		foreach ( $list as $name => $info ) {
			$child_relative = '' === $relative ? $name : $relative . '/' . $name;
			$path           = trailingslashit( $directory ) . $name;

			if ( $rules && $rules->matches( $child_relative ) ) {
				continue;
			}

			$is_dir = 'd' === $info['type'];

			// A directory that may hold protected files is emptied entry by entry.
			if ( $is_dir && $rules && $rules->has_match_inside( $child_relative ) ) {
				$result = $this->empty_dir( $path, $rules, $child_relative );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Nothing protected turned up here after all, so the now empty
				// directory goes too — leftovers would look like theme files.
				$remaining = $this->fs->dirlist( $path, true, false );

				if ( is_array( $remaining ) && empty( $remaining ) ) {
					$this->delete( $path );
				}

				continue;
			}

			if ( ! $this->delete( $path ) ) {
				return new WP_Error(
					'gthu_delete',
					sprintf(
						/* translators: %s: file or directory path. */
						__( 'Could not delete %s. A file inside is read-only, locked by another program, or owned by a different user than PHP runs as.', 'github-theme-updater' ),
						$child_relative
					)
				);
			}
		}//end foreach

		return true;
	}

	/**
	 * Finds the directory holding the theme inside an extracted archive.
	 *
	 * GitHub names source archives `owner-repo-sha`, so the theme is never at a
	 * predictable path: it is located by looking for `style.css` instead.
	 *
	 * @param string $directory Absolute path of the extracted archive.
	 * @param int    $max_depth How deep to look.
	 * @return string|WP_Error Absolute path of the theme root.
	 */
	public function locate_theme_root( $directory, $max_depth = 3 ) {
		$directory = untrailingslashit( $directory );

		if ( $this->fs->exists( trailingslashit( $directory ) . 'style.css' ) ) {
			return $directory;
		}

		if ( $max_depth <= 0 ) {
			return new WP_Error(
				'gthu_theme_root_missing',
				__( 'No style.css was found in the downloaded archive. Make sure the repository holds a theme, rather than, say, an entire wp-content directory.', 'github-theme-updater' )
			);
		}

		$list = $this->fs->dirlist( $directory, true, false );

		if ( ! is_array( $list ) ) {
			return new WP_Error(
				'gthu_dirlist',
				__( 'Could not read the contents of the downloaded archive.', 'github-theme-updater' )
			);
		}

		foreach ( $list as $name => $info ) {
			if ( 'd' !== $info['type'] ) {
				continue;
			}

			$found = $this->locate_theme_root( trailingslashit( $directory ) . $name, $max_depth - 1 );

			if ( ! is_wp_error( $found ) ) {
				return $found;
			}
		}

		return new WP_Error(
			'gthu_theme_root_missing',
			__( 'No style.css was found in the downloaded archive. Make sure the repository holds a theme, rather than, say, an entire wp-content directory.', 'github-theme-updater' )
		);
	}

	/**
	 * Measures a directory tree.
	 *
	 * @param string $directory Absolute path.
	 * @return int Size in bytes.
	 */
	public function size( $directory ) {
		$list = $this->fs->dirlist( $directory, true, true );

		return is_array( $list ) ? $this->sum_dirlist( $list ) : 0;
	}

	/**
	 * Recursively sums the sizes reported by `dirlist()`.
	 *
	 * @param array<string, mixed> $entries Directory listing.
	 * @return int
	 */
	private function sum_dirlist( array $entries ) {
		$total = 0;

		foreach ( $entries as $info ) {
			if ( isset( $info['type'] ) && 'd' === $info['type'] ) {
				if ( ! empty( $info['files'] ) && is_array( $info['files'] ) ) {
					$total += $this->sum_dirlist( $info['files'] );
				}

				continue;
			}

			$total += isset( $info['size'] ) ? (int) $info['size'] : 0;
		}

		return $total;
	}
}
