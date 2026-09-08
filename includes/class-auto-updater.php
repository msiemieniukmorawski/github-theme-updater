<?php
/**
 * Unattended updates.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Installs a newer release without anyone clicking a button.
 *
 * Two things can start a run: the daily schedule (a WP-Cron event at the
 * configured hour) and the GitHub webhook (a single event queued by
 * {@see Webhook}). Both go through {@see run()}, which reuses the exact
 * installation sequence of the admin screen — lock, backup, verification,
 * rollback, history entry — and then sends the e-mail report.
 */
final class Auto_Updater {

	/**
	 * Cron hook of the daily run.
	 */
	const CRON_HOOK = 'gthu_auto_update';

	/**
	 * Cron hook of a run queued by the webhook.
	 */
	const WEBHOOK_HOOK = 'gthu_webhook_update';

	/**
	 * Hour used when the setting is empty or unreadable.
	 */
	const DEFAULT_TIME = '03:00';

	/**
	 * How many times a webhook run is retried when GitHub's API has not caught
	 * up with the release yet.
	 */
	const WEBHOOK_ATTEMPTS = 3;

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
	 * Theme installer.
	 *
	 * @var Theme_Installer
	 */
	private $installer;

	/**
	 * E-mail reports.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings  Settings repository.
	 * @param Github_Client   $client    GitHub client.
	 * @param Theme_Installer $installer Theme installer.
	 * @param Notifier        $notifier  E-mail reports.
	 */
	public function __construct( Settings $settings, Github_Client $client, Theme_Installer $installer, Notifier $notifier ) {
		$this->settings  = $settings;
		$this->client    = $client;
		$this->installer = $installer;
		$this->notifier  = $notifier;
	}

	/**
	 * Registers the cron handlers and keeps the schedule in step with the settings.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::WEBHOOK_HOOK, array( $this, 'run_webhook' ), 10, 2 );

		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_saved' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings_saved' ) );

		// The twice-daily check doubles as a safety net: if the daily event
		// ever went missing (a cleared cron array, a failed reschedule), it
		// is put back without waiting for someone to open the settings.
		add_action( Update_Checker::CRON_HOOK, array( $this, 'sync_schedule' ), 5 );
	}

	/**
	 * Handler of the daily event.
	 *
	 * @return void
	 */
	public function run_scheduled() {
		$this->run( 'schedule' );

		// A daily interval is 24 hours, not "same wall-clock time tomorrow", so
		// the schedule drifts by an hour at every DST change. Put it back.
		$this->sync_schedule();
	}

	/**
	 * Handler of a run queued by the webhook.
	 *
	 * GitHub sends the event the moment the release is published, and its
	 * REST API may lag a little behind. When the release list does not show
	 * the announced tag yet, the run is retried a couple of times.
	 *
	 * @param string $tag     Tag announced by the webhook.
	 * @param int    $attempt Zero-based attempt number.
	 * @return void
	 */
	public function run_webhook( $tag = '', $attempt = 0 ) {
		$tag     = (string) $tag;
		$attempt = (int) $attempt;
		$outcome = $this->run( 'webhook' );

		$seen = $outcome['release'] instanceof Release ? $outcome['release']->tag() : '';

		if ( 'up_to_date' !== $outcome['status'] || '' === $tag || $seen === $tag ) {
			return;
		}

		if ( $attempt + 1 >= self::WEBHOOK_ATTEMPTS ) {
			return;
		}

		wp_schedule_single_event(
			time() + 3 * MINUTE_IN_SECONDS * ( $attempt + 1 ),
			self::WEBHOOK_HOOK,
			array( $tag, $attempt + 1 )
		);
	}

	/**
	 * Queues a run in response to a webhook delivery.
	 *
	 * The request must return to GitHub within seconds, and an update can take
	 * minutes, so the work is handed to WP-Cron and the cron runner is poked
	 * straight away.
	 *
	 * @param string $tag Tag announced by the webhook.
	 * @return void
	 */
	public function queue_webhook_run( $tag ) {
		$tag = (string) $tag;

		// Core refuses an identical event within ten minutes of an existing
		// one, which is exactly the de-duplication a redelivery needs.
		wp_schedule_single_event( time(), self::WEBHOOK_HOOK, array( $tag, 0 ) );

		spawn_cron();
	}

	/**
	 * Checks GitHub and installs the newest release if it is newer.
	 *
	 * @param string $trigger What started the run: `schedule`, `webhook` or `manual`.
	 * @return array{status: string, message: string, release: Release|null, result: array<string, mixed>|WP_Error|null}
	 */
	public function run( $trigger = 'schedule' ) {
		$trigger = in_array( $trigger, array( 'schedule', 'webhook', 'manual' ), true ) ? $trigger : 'manual';

		if ( 'schedule' === $trigger && ! $this->settings->get( 'auto_update', false ) ) {
			return $this->finish( $trigger, 'disabled', __( 'Scheduled updates are turned off.', 'github-theme-updater' ) );
		}

		if ( 'webhook' === $trigger && ! $this->settings->get( 'webhook_enabled', false ) ) {
			return $this->finish( $trigger, 'disabled', __( 'The GitHub webhook is turned off.', 'github-theme-updater' ) );
		}

		if ( ! $this->settings->is_configured() ) {
			return $this->finish( $trigger, 'not_configured', __( 'The plugin is not configured: enter a repository and a theme directory first.', 'github-theme-updater' ) );
		}

		if ( 'branch' === $this->settings->get( 'source' ) ) {
			return $this->finish( $trigger, 'branch_mode', __( 'Automatic updates work in release mode only. A branch has no version number to compare, so the plugin cannot tell whether it is newer.', 'github-theme-updater' ) );
		}

		Github_Client::flush_cache();

		$release = $this->client->get_latest_release( true );

		if ( is_wp_error( $release ) ) {
			$this->settings->update_state(
				array(
					'last_check' => time(),
					'last_error' => $release->get_error_message(),
				)
			);

			$this->notify_check_failure( $release, $trigger );

			return $this->finish( $trigger, 'check_failed', $release->get_error_message() );
		}

		$state     = $this->settings->state();
		$installed = (string) $state['installed_version'];

		$this->settings->update_state(
			array(
				'last_check'     => time(),
				'latest_version' => $release->tag(),
				'last_error'     => '',
			)
		);

		// A working check clears the memory of the last reported failure, so
		// the same problem is announced again if it comes back later.
		if ( '' !== (string) $state['auto_notified_error'] ) {
			$this->settings->update_state( array( 'auto_notified_error' => '' ) );
		}

		if ( ! $release->is_newer_than( $installed ) ) {
			return $this->finish(
				$trigger,
				'up_to_date',
				sprintf(
					/* translators: 1: installed version, 2: newest version on GitHub. */
					__( 'Nothing to do: installed %1$s, newest on GitHub %2$s.', 'github-theme-updater' ),
					'' !== $installed ? $installed : __( 'unknown', 'github-theme-updater' ),
					$release->tag()
				),
				$release
			);
		}

		if ( ! Theme_Installer::lock() ) {
			return $this->finish( $trigger, 'locked', Theme_Installer::locked_error()->get_error_message(), $release );
		}

		Install_Progress::start( $release->tag() );
		Install_Progress::step( 'resolve' );

		$result = $this->installer->install( $release, true, $trigger );

		if ( is_wp_error( $result ) ) {
			$this->notifier->failed( $release, $result, $installed, $trigger );

			return $this->finish(
				$trigger,
				'failed',
				sprintf(
					/* translators: 1: version that failed to install, 2: error message. */
					__( 'Updating to %1$s failed: %2$s', 'github-theme-updater' ),
					$release->tag(),
					$result->get_error_message()
				),
				$release,
				$result
			);
		}

		$this->notifier->installed( $release, $result, $installed, $trigger );

		return $this->finish(
			$trigger,
			'updated',
			sprintf(
				/* translators: 1: previously installed version, 2: newly installed version. */
				__( 'Updated from %1$s to %2$s.', 'github-theme-updater' ),
				'' !== $installed ? $installed : __( 'unknown', 'github-theme-updater' ),
				$release->tag()
			),
			$release,
			$result
		);
	}

	/**
	 * Records the outcome of a run and shapes the return value.
	 *
	 * @param string                             $trigger What started the run.
	 * @param string                             $status  Outcome keyword.
	 * @param string                             $message Human readable outcome.
	 * @param Release|null                       $release Release considered, if the check succeeded.
	 * @param array<string, mixed>|WP_Error|null $result  Installer result, if an installation ran.
	 * @return array{status: string, message: string, release: Release|null, result: array<string, mixed>|WP_Error|null}
	 */
	private function finish( $trigger, $status, $message, $release = null, $result = null ) {
		// A disabled feature poked by a stale cron event is not worth a log line.
		if ( 'disabled' !== $status ) {
			$this->settings->update_state(
				array(
					'auto_last_run'     => time(),
					'auto_last_trigger' => $trigger,
					'auto_last_status'  => $status,
					'auto_last_message' => $message,
				)
			);
		}

		/**
		 * Fires after an automatic run, whatever its outcome.
		 *
		 * @param string       $status  Outcome keyword: updated, failed, up_to_date, locked, check_failed, branch_mode, not_configured, disabled.
		 * @param string       $trigger What started the run: schedule, webhook or manual.
		 * @param string       $message Human readable outcome.
		 * @param Release|null $release Release considered, if the check succeeded.
		 */
		do_action( 'gthu_auto_update_finished', $status, $trigger, $message, $release );

		return array(
			'status'  => $status,
			'message' => $message,
			'release' => $release,
			'result'  => $result,
		);
	}

	/**
	 * Reports a failed version check, once per distinct error.
	 *
	 * An expired token would otherwise produce the same e-mail every night.
	 *
	 * @param WP_Error $error   Error returned by the check.
	 * @param string   $trigger What started the run.
	 * @return void
	 */
	private function notify_check_failure( WP_Error $error, $trigger ) {
		$fingerprint = md5( $error->get_error_code() . '|' . $error->get_error_message() );
		$state       = $this->settings->state();

		if ( $fingerprint === (string) $state['auto_notified_error'] ) {
			return;
		}

		if ( $this->notifier->check_failed( $error, $trigger ) ) {
			$this->settings->update_state( array( 'auto_notified_error' => $fingerprint ) );
		}
	}

	/**
	 * Reacts to a settings save.
	 *
	 * @return void
	 */
	public function on_settings_saved() {
		$this->settings->flush_cache();
		$this->sync_schedule();
	}

	/**
	 * Makes the daily event match the settings: present when the feature is
	 * on, absent when it is off, and at the configured hour.
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$enabled = (bool) $this->settings->get( 'auto_update', false );
		$next    = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $enabled ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}

			return;
		}

		$time = self::sanitize_time( (string) $this->settings->get( 'auto_update_time', self::DEFAULT_TIME ) );

		// An event that is already at the right wall-clock time is left alone;
		// an overdue one too, so a slow cron gets to run it instead of
		// watching it move a day into the future.
		if ( $next && wp_date( 'H:i', $next ) === $time ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( self::next_run_timestamp( $time, wp_timezone(), time() ), 'daily', self::CRON_HOOK );
	}

	/**
	 * Removes every event of this class, for deactivation and uninstall.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_unschedule_hook( self::WEBHOOK_HOOK );
	}

	/**
	 * When the next scheduled run is due, or 0 when none is scheduled.
	 *
	 * @return int
	 */
	public static function next_scheduled() {
		$next = wp_next_scheduled( self::CRON_HOOK );

		return $next ? (int) $next : 0;
	}

	/**
	 * Normalises a time of day to `HH:MM`.
	 *
	 * Accepts `3:00`, `03:00` and `03:00:00`; anything else falls back to the
	 * default hour.
	 *
	 * @param string $time Raw input.
	 * @return string
	 */
	public static function sanitize_time( $time ) {
		$time = trim( (string) $time );

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches ) ) {
			return self::DEFAULT_TIME;
		}

		$hours   = (int) $matches[1];
		$minutes = (int) $matches[2];

		if ( $hours > 23 || $minutes > 59 ) {
			return self::DEFAULT_TIME;
		}

		return sprintf( '%02d:%02d', $hours, $minutes );
	}

	/**
	 * First moment at or after `$now` when the clock in `$timezone` shows `$time`.
	 *
	 * @param string       $time     Time of day as `HH:MM`.
	 * @param DateTimeZone $timezone Site timezone.
	 * @param int          $now      Reference Unix timestamp.
	 * @return int Unix timestamp.
	 */
	public static function next_run_timestamp( $time, DateTimeZone $timezone, $now ) {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', self::sanitize_time( $time ) ) );

		$reference = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $timezone );
		$candidate = $reference->setTime( $hours, $minutes, 0 );

		if ( $candidate->getTimestamp() <= (int) $now ) {
			// `+1 day` keeps the wall-clock time across a DST change; setTime()
			// afterwards guards against the odd PHP build that does not.
			$candidate = $candidate->modify( '+1 day' )->setTime( $hours, $minutes, 0 );
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Human readable label of a trigger.
	 *
	 * @param string $trigger Trigger keyword.
	 * @return string
	 */
	public static function trigger_label( $trigger ) {
		switch ( (string) $trigger ) {
			case 'schedule':
				return __( 'scheduled run', 'github-theme-updater' );

			case 'webhook':
				return __( 'GitHub webhook', 'github-theme-updater' );

			case 'manual':
				return __( 'started by hand from the plugin screen', 'github-theme-updater' );
		}

		return (string) $trigger;
	}
}
