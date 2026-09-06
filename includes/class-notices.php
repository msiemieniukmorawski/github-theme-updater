<?php
/**
 * Admin notices queue.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a message across the redirect that follows every form submission.
 *
 * Messages are stored per user, so two administrators working at the same time
 * never see each other's results.
 */
final class Notices {

	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'gthu_notices_';

	/**
	 * Registers the renderer.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Queues a message for the current user.
	 *
	 * @param string $message Message, may contain inline HTML.
	 * @param string $type    One of success, error, warning, info.
	 * @return void
	 */
	public static function add( $message, $type = 'success' ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$queue = self::queue( $user_id );

		$queue[] = array(
			'message' => (string) $message,
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
		);

		set_transient( self::PREFIX . $user_id, $queue, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Queues an error, accepting either a string or a WP_Error.
	 *
	 * @param mixed $error Error to report.
	 * @return void
	 */
	public static function error( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

		self::add( $message, 'error' );
	}

	/**
	 * Prints and clears the queue.
	 *
	 * @return void
	 */
	public static function render() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$queue = self::queue( $user_id );

		if ( empty( $queue ) ) {
			return;
		}

		delete_transient( self::PREFIX . $user_id );

		foreach ( $queue as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				wp_kses_post( $notice['message'] )
			);
		}
	}

	/**
	 * Reads the queue of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{message: string, type: string}>
	 */
	private static function queue( $user_id ) {
		$queue = get_transient( self::PREFIX . $user_id );

		return is_array( $queue ) ? $queue : array();
	}
}
