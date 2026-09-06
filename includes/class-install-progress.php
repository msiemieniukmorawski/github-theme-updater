<?php
/**
 * Installation progress reporting.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Records which step of an installation is running so the admin screen can
 * show it while the request is still in flight.
 *
 * The state lives in a transient with the same lifetime as the install lock.
 * The installer writes to it, the progress endpoint reads it, and the browser
 * polls that endpoint while the update request is pending.
 */
final class Install_Progress {

	/**
	 * Transient holding the progress record.
	 */
	const TRANSIENT = 'gthu_install_progress';

	/**
	 * How long a record survives, matching the install lock.
	 */
	const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Minimum interval between throttled writes, in seconds.
	 */
	const THROTTLE = 0.5;

	/**
	 * When the record was last written, as a float timestamp.
	 *
	 * @var float
	 */
	private static $last_write = 0.0;

	/**
	 * Steps of an installation in the order they run.
	 *
	 * @return array<string, string> Step key mapped to its label.
	 */
	public static function steps() {
		return array(
			'resolve'  => __( 'Checking the release on GitHub', 'github-theme-updater' ),
			'download' => __( 'Downloading the archive', 'github-theme-updater' ),
			'unpack'   => __( 'Unpacking the archive', 'github-theme-updater' ),
			'verify'   => __( 'Checking the theme files can be replaced', 'github-theme-updater' ),
			'backup'   => __( 'Backing up the current theme', 'github-theme-updater' ),
			'clean'    => __( 'Removing the old theme files', 'github-theme-updater' ),
			'copy'     => __( 'Copying the new files', 'github-theme-updater' ),
			'finish'   => __( 'Finishing up', 'github-theme-updater' ),
		);
	}

	/**
	 * Steps that only appear when something went wrong.
	 *
	 * @return array<string, string> Step key mapped to its label.
	 */
	public static function recovery_steps() {
		return array(
			'rollback' => __( 'Restoring the theme from the backup', 'github-theme-updater' ),
		);
	}

	/**
	 * Starts a fresh record.
	 *
	 * @param string $version Version being installed, for display.
	 * @return void
	 */
	public static function start( $version = '' ) {
		self::write(
			array(
				'started' => time(),
				'version' => (string) $version,
				'step'    => '',
				'detail'  => '',
				'done'    => array(),
				'skipped' => array(),
			)
		);
	}

	/**
	 * Marks a step as the one running now.
	 *
	 * The previously running step is recorded as finished.
	 *
	 * @param string $step   Step key.
	 * @param string $detail Optional detail shown next to the step.
	 * @return void
	 */
	public static function step( $step, $detail = '' ) {
		$data = self::read();

		if ( null === $data ) {
			self::start();
			$data = self::read();
		}

		if ( '' !== $data['step'] && $data['step'] !== $step && ! in_array( $data['step'], $data['done'], true ) ) {
			$data['done'][] = $data['step'];
		}

		$data['step']   = (string) $step;
		$data['detail'] = (string) $detail;

		self::write( $data );
	}

	/**
	 * Marks a step as skipped.
	 *
	 * @param string $step Step key.
	 * @return void
	 */
	public static function skip( $step ) {
		$data = self::read();

		if ( null === $data ) {
			return;
		}

		if ( ! in_array( $step, $data['skipped'], true ) ) {
			$data['skipped'][] = (string) $step;
		}

		self::write( $data );
	}

	/**
	 * Updates the detail of the running step.
	 *
	 * Called for every copied file, so writes are throttled to keep the
	 * database out of the hot loop. Pass `$force` for the final value.
	 *
	 * @param string $detail Detail text.
	 * @param bool   $force  Write even if the last write was a moment ago.
	 * @return void
	 */
	public static function detail( $detail, $force = false ) {
		if ( ! $force && ( microtime( true ) - self::$last_write ) < self::THROTTLE ) {
			return;
		}

		$data = self::read();

		if ( null === $data ) {
			return;
		}

		$data['detail'] = (string) $detail;

		self::write( $data );
	}

	/**
	 * Removes the record.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Builds a per-file callback that keeps a running count in the detail.
	 *
	 * @return callable
	 */
	public static function file_counter() {
		$count = 0;

		return static function () use ( &$count ) {
			++$count;

			Install_Progress::detail(
				sprintf(
					/* translators: %s: number of files copied so far. */
					_n( '%s file', '%s files', $count, 'github-theme-updater' ),
					number_format_i18n( $count )
				)
			);
		};
	}

	/**
	 * Whether a record exists and an operation still holds the lock.
	 *
	 * @return bool
	 */
	public static function is_running() {
		return null !== self::read() && Theme_Installer::is_locked();
	}

	/**
	 * State of every step, ready to be sent to the browser.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot() {
		$data  = self::read();
		$steps = array();

		if ( null === $data ) {
			return array(
				'running' => false,
				'version' => '',
				'step'    => '',
				'detail'  => '',
				'elapsed' => 0,
				'steps'   => $steps,
			);
		}

		$labels = self::steps();

		// Recovery steps are only listed once they actually happened.
		foreach ( self::recovery_steps() as $key => $label ) {
			if ( $key === $data['step'] || in_array( $key, $data['done'], true ) ) {
				$labels[ $key ] = $label;
			}
		}

		foreach ( $labels as $key => $label ) {
			if ( $key === $data['step'] ) {
				$status = 'active';
			} elseif ( in_array( $key, $data['done'], true ) ) {
				$status = 'done';
			} elseif ( in_array( $key, $data['skipped'], true ) ) {
				$status = 'skipped';
			} else {
				$status = 'pending';
			}

			$steps[] = array(
				'key'    => $key,
				'label'  => $label,
				'status' => $status,
			);
		}

		return array(
			'running' => Theme_Installer::is_locked(),
			'version' => (string) $data['version'],
			'step'    => (string) $data['step'],
			'detail'  => (string) $data['detail'],
			'elapsed' => max( 0, time() - (int) $data['started'] ),
			'steps'   => $steps,
		);
	}

	/**
	 * Reads the record.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function read() {
		$data = get_transient( self::TRANSIENT );

		if ( ! is_array( $data ) || ! isset( $data['started'] ) ) {
			return null;
		}

		return wp_parse_args(
			$data,
			array(
				'started' => time(),
				'version' => '',
				'step'    => '',
				'detail'  => '',
				'done'    => array(),
				'skipped' => array(),
			)
		);
	}

	/**
	 * Writes the record.
	 *
	 * @param array<string, mixed> $data Record.
	 * @return void
	 */
	private static function write( array $data ) {
		$data['updated'] = time();

		set_transient( self::TRANSIENT, $data, self::TTL );

		self::$last_write = microtime( true );
	}
}
