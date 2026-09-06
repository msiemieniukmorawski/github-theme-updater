<?php
/**
 * Background update checks.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Polls GitHub on a schedule and points out newer versions in the admin.
 */
final class Update_Checker {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'gthu_check_for_updates';

	/**
	 * Option storing the version whose notice was dismissed.
	 */
	const DISMISSED_OPTION = 'gthu_dismissed_update';

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
	 * Constructor.
	 *
	 * @param Settings      $settings Settings repository.
	 * @param Github_Client $client   GitHub client.
	 */
	public function __construct( Settings $settings, Github_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Registers the cron handler and the notice.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_filter( 'site_transient_update_themes', array( $this, 'shield_managed_theme' ) );
	}

	/**
	 * Keeps WordPress from offering a wordpress.org update for the managed theme.
	 *
	 * If a theme in the directory shares the slug, core would list it under
	 * Dashboard → Updates and overwrite the theme with a stranger's code.
	 *
	 * @param mixed $transient Value of the update_themes site transient.
	 * @return mixed
	 */
	public function shield_managed_theme( $transient ) {
		$slug = (string) $this->settings->get( 'theme_slug', '' );

		if ( '' === $slug || ! is_object( $transient ) ) {
			return $transient;
		}

		if ( isset( $transient->response ) && is_array( $transient->response ) ) {
			unset( $transient->response[ $slug ] );
		}

		return $transient;
	}

	/**
	 * Performs a check and stores the result.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! $this->settings->get( 'check_updates', true ) || ! $this->settings->is_configured() ) {
			return;
		}

		$release = $this->client->get_latest_release( true );

		if ( is_wp_error( $release ) ) {
			$this->settings->update_state(
				array(
					'last_check' => time(),
					'last_error' => $release->get_error_message(),
				)
			);

			return;
		}

		$this->settings->update_state(
			array(
				'last_check'     => time(),
				'latest_version' => $release->tag(),
				'last_error'     => '',
			)
		);
	}

	/**
	 * Shows a notice when the installed version is behind GitHub.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( ! current_user_can( Admin_Page::capability() ) ) {
			return;
		}

		if ( ! $this->settings->get( 'check_updates', true ) || ! $this->settings->is_configured() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// The updater screen already shows this information in context.
		if ( $screen && false !== strpos( (string) $screen->id, Admin_Page::MENU_SLUG ) ) {
			return;
		}

		$state  = $this->settings->state();
		$latest = (string) $state['latest_version'];

		if ( '' === $latest || get_option( self::DISMISSED_OPTION ) === $latest ) {
			return;
		}

		$installed = (string) $state['installed_version'];

		if ( '' !== $installed && ! version_compare( ltrim( $latest, 'vV' ), ltrim( $installed, 'vV' ), '>' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-small">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'GitHub Theme Updater:', 'github-theme-updater' ),
			esc_html(
				sprintf(
					/* translators: %s: version number available on GitHub. */
					__( 'a newer version of the theme is available (%s).', 'github-theme-updater' ),
					$latest
				)
			),
			esc_url( Admin_Page::url() ),
			esc_html__( 'Go to updates', 'github-theme-updater' ),
			esc_url( self::dismiss_url( $latest ) ),
			esc_html__( 'Hide this notice', 'github-theme-updater' )
		);
	}

	/**
	 * URL that hides the notice until the next version shows up.
	 *
	 * @param string $version Version being dismissed.
	 * @return string
	 */
	public static function dismiss_url( $version ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'gthu_dismiss_update',
					'version' => rawurlencode( $version ),
				),
				admin_url( 'admin-post.php' )
			),
			'gthu_dismiss_update'
		);
	}

	/**
	 * Clears the dismissal so the next release is announced again.
	 *
	 * @return void
	 */
	public static function forget_dismissals() {
		delete_option( self::DISMISSED_OPTION );
	}

	/**
	 * Schedules the recurring check.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Cancels the recurring check.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
