<?php
/**
 * Settings repository and schema.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the plugin configuration.
 *
 * Everything lives in one serialised option so that a settings form submit is
 * atomic, and so that adding an option later never means another `add_option()`.
 */
final class Settings {

	/**
	 * Option holding the user configuration.
	 */
	const OPTION = 'gthu_settings';

	/**
	 * Option holding runtime state (installed version, last check, ...).
	 */
	const STATE_OPTION = 'gthu_state';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private $cache = null;

	/**
	 * Registers the settings API integration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );

		// options.php gates saving on `manage_options` by default, which would
		// undercut the stricter capability the rest of the plugin uses.
		add_filter(
			'option_page_capability_gthu_settings_group',
			static function () {
				return Admin_Page::capability();
			}
		);
	}

	/**
	 * Declares the option to the Settings API.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			'gthu_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'repository'          => '',
			'token'               => '',
			'theme_slug'          => '',
			'source'              => 'release',
			'branch'              => 'main',
			'asset_pattern'       => '',
			'include_prereleases' => false,
			'protected_paths'     => "languages\n.env\nacf-json\n",
			'ignored_paths'       => ".git\nnode_modules\n",
			'create_backup'       => true,
			'backup_limit'        => 3,
			'check_updates'       => true,
			'delete_data'         => false,
		);
	}

	/**
	 * Returns the whole configuration, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			$this->cache = array_merge( self::defaults(), $stored );
		}

		return $this->cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persists a subset of settings.
	 *
	 * @param array<string, mixed> $values Values to merge into the stored option.
	 * @return void
	 */
	public function update( array $values ) {
		$updated = array_merge( $this->all(), $values );

		update_option( self::OPTION, $updated );
		$this->cache = $updated;
	}

	/**
	 * Drops the request-level cache.
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Validates and normalises the settings form payload.
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$current = $this->all();
		$input   = is_array( $input ) ? $input : array();
		$clean   = $current;

		// Repository: accept any GitHub URL flavour, store the canonical owner/repo.
		$repository_raw = isset( $input['repository'] ) ? trim( (string) $input['repository'] ) : '';

		if ( '' === $repository_raw ) {
			$clean['repository'] = '';
		} else {
			$repository = Repository::from_string( $repository_raw );

			if ( $repository ) {
				$clean['repository'] = $repository->full_name();
			} else {
				$clean['repository'] = $current['repository'];
				add_settings_error(
					self::OPTION,
					'gthu_repository',
					sprintf(
						/* translators: %s: value entered by the user. */
						__( 'Repository not recognised: %s. Enter it as owner/repository, or paste an address such as https://github.com/owner/repository.', 'github-theme-updater' ),
						esc_html( $repository_raw )
					),
					'error'
				);
			}
		}//end if

		// Token: an empty field keeps the stored value, so it never has to be re-typed.
		$token_raw = isset( $input['token'] ) ? trim( (string) $input['token'] ) : '';

		if ( ! empty( $input['clear_token'] ) ) {
			$clean['token'] = '';
		} elseif ( '' !== $token_raw ) {
			// A value that is already in storage form (an update_option() call
			// from a migration or WP-CLI) must not be wrapped a second time.
			$clean['token'] = Token_Storage::is_stored( $token_raw ) ? $token_raw : Token_Storage::encrypt( $token_raw );
		}

		$clean['theme_slug'] = isset( $input['theme_slug'] )
			? self::sanitize_theme_slug( (string) $input['theme_slug'] )
			: '';

		$clean['source'] = ( isset( $input['source'] ) && 'branch' === $input['source'] ) ? 'branch' : 'release';

		$branch          = isset( $input['branch'] ) ? trim( (string) $input['branch'] ) : '';
		$clean['branch'] = '' !== $branch ? sanitize_text_field( $branch ) : 'main';

		$clean['asset_pattern'] = isset( $input['asset_pattern'] )
			? sanitize_text_field( trim( (string) $input['asset_pattern'] ) )
			: '';

		$clean['include_prereleases'] = ! empty( $input['include_prereleases'] );
		$clean['create_backup']       = ! empty( $input['create_backup'] );
		$clean['check_updates']       = ! empty( $input['check_updates'] );
		$clean['delete_data']         = ! empty( $input['delete_data'] );

		$limit                 = isset( $input['backup_limit'] ) ? (int) $input['backup_limit'] : 3;
		$clean['backup_limit'] = max( 1, min( 20, $limit ) );

		$paths                    = isset( $input['protected_paths'] ) ? (string) $input['protected_paths'] : '';
		$clean['protected_paths'] = Path_Rules::from_text( $paths )->to_text();

		$ignored                = isset( $input['ignored_paths'] ) ? (string) $input['ignored_paths'] : '';
		$clean['ignored_paths'] = Path_Rules::from_text( $ignored )->to_text();

		// The remote configuration changed, so any cached API response is stale.
		if ( $clean['repository'] !== $current['repository'] || $clean['token'] !== $current['token'] ) {
			Github_Client::flush_cache();
		}

		return $clean;
	}

	/**
	 * Cleans a theme directory name.
	 *
	 * Case is preserved on purpose: theme directories such as `Divi` or
	 * `Avada-Child` are real, and `sanitize_key()` would lowercase them into a
	 * path that does not exist. Only the characters that could escape the themes
	 * directory are removed.
	 *
	 * @param string $slug Raw input.
	 * @return string Empty string when nothing usable is left.
	 */
	public static function sanitize_theme_slug( $slug ) {
		$slug = trim( (string) $slug );
		$slug = str_replace( '\\', '/', $slug );
		$slug = wp_basename( untrailingslashit( $slug ) );
		$slug = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $slug );

		// `.` and `..` survive the filter above but are not directory names.
		if ( '' === trim( $slug, '.' ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Returns the GitHub token in clear text.
	 *
	 * A `GTHU_GITHUB_TOKEN` constant always wins, which keeps the token out of
	 * the database on properly configured environments.
	 *
	 * @return string
	 */
	public function token() {
		if ( defined( 'GTHU_GITHUB_TOKEN' ) && GTHU_GITHUB_TOKEN ) {
			return (string) GTHU_GITHUB_TOKEN;
		}

		return Token_Storage::decrypt( (string) $this->get( 'token', '' ) );
	}

	/**
	 * Whether the token is defined in wp-config.php rather than in the database.
	 *
	 * @return bool
	 */
	public function token_is_constant() {
		return defined( 'GTHU_GITHUB_TOKEN' ) && GTHU_GITHUB_TOKEN;
	}

	/**
	 * Whether a token is available at all.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->token();
	}

	/**
	 * Configured repository, if any.
	 *
	 * @return Repository|null
	 */
	public function repository() {
		return Repository::from_string( (string) $this->get( 'repository', '' ) );
	}

	/**
	 * Protected path rules.
	 *
	 * @return Path_Rules
	 */
	public function protected_paths() {
		return Path_Rules::from_text( (string) $this->get( 'protected_paths', '' ) );
	}

	/**
	 * Paths that are never copied anywhere and left alone on disk.
	 *
	 * Development leftovers such as `.git` or `node_modules`: they are not
	 * part of the theme, but they can hold tens of thousands of files and turn
	 * a backup into a multi-minute job.
	 *
	 * @return Path_Rules
	 */
	public function ignored_paths() {
		return Path_Rules::from_text( (string) $this->get( 'ignored_paths', '' ) );
	}

	/**
	 * Everything an update or a restore must not delete or overwrite.
	 *
	 * Protected paths are kept because the site owner wants them; ignored
	 * paths are kept because they are none of the plugin's business.
	 *
	 * @return Path_Rules
	 */
	public function skipped_paths() {
		return Path_Rules::from_array(
			array_merge( $this->protected_paths()->all(), $this->ignored_paths()->all() )
		);
	}

	/**
	 * Absolute path of the managed theme directory.
	 *
	 * @return string
	 */
	public function theme_dir() {
		$slug = (string) $this->get( 'theme_slug', '' );

		return '' === $slug ? '' : trailingslashit( get_theme_root() ) . $slug;
	}

	/**
	 * Whether the plugin has everything it needs to talk to GitHub.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->repository() && '' !== (string) $this->get( 'theme_slug', '' );
	}

	/**
	 * Returns the runtime state.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );

		return array_merge(
			array(
				'installed_version' => '',
				'installed_at'      => 0,
				'installed_by'      => 0,
				'last_check'        => 0,
				'latest_version'    => '',
				'last_error'        => '',
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Merges values into the runtime state.
	 *
	 * @param array<string, mixed> $values State values.
	 * @return void
	 */
	public function update_state( array $values ) {
		update_option( self::STATE_OPTION, array_merge( $this->state(), $values ), false );
	}
}
