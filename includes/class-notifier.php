<?php
/**
 * E-mail reports.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tells people what an unattended run did.
 *
 * Nobody is looking at the admin screen at three in the morning, so every
 * automatic run that changes something — or fails trying — ends with a plain
 * text e-mail to the configured addresses. Manual updates from the admin
 * screen stay silent: the person clicking the button sees the result.
 */
final class Notifier {

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
	 * Splits a free-form list of addresses into valid, unique e-mails.
	 *
	 * Commas, semicolons, whitespace and new lines all separate addresses, so
	 * a list pasted from anywhere works. Invalid entries are reported in the
	 * second element rather than silently dropped.
	 *
	 * @param string $text Raw input.
	 * @return array{0: string[], 1: string[]} Valid addresses, rejected entries.
	 */
	public static function parse_recipients( $text ) {
		$parts    = preg_split( '/[\s,;]+/', (string) $text );
		$parts    = is_array( $parts ) ? $parts : array();
		$valid    = array();
		$rejected = array();
		$seen     = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$email = is_email( $part );

			if ( ! $email ) {
				$rejected[] = $part;
				continue;
			}

			$key = strtolower( $email );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$valid[]      = $email;
		}//end foreach

		return array( $valid, $rejected );
	}

	/**
	 * Addresses that receive the reports.
	 *
	 * @param string $event What is being reported: installed, failed, check_failed or test.
	 * @return string[]
	 */
	public function recipients( $event ) {
		list( $emails ) = self::parse_recipients( (string) $this->settings->get( 'notify_emails', '' ) );

		/**
		 * Filters the addresses an automatic update report is sent to.
		 *
		 * @param string[] $emails Addresses from the settings.
		 * @param string   $event  What is being reported: installed, failed, check_failed or test.
		 */
		$emails = (array) apply_filters( 'gthu_notification_recipients', $emails, $event );

		return array_values( array_filter( array_map( 'strval', $emails ) ) );
	}

	/**
	 * Reports a successful update.
	 *
	 * @param Release              $release  Installed release.
	 * @param array<string, mixed> $summary  Installer summary.
	 * @param string               $previous Version installed before.
	 * @param string               $trigger  What started the run.
	 * @return bool Whether an e-mail went out.
	 */
	public function installed( Release $release, array $summary, $previous, $trigger ) {
		$subject = sprintf(
			/* translators: 1: site name, 2: installed version. */
			__( '[%1$s] Theme updated to %2$s', 'github-theme-updater' ),
			$this->site_name(),
			$release->tag()
		);

		$lines = array(
			sprintf(
				/* translators: 1: site URL, 2: version. */
				__( 'The theme on %1$s was updated to version %2$s.', 'github-theme-updater' ),
				home_url( '/' ),
				$release->label()
			),
			'',
		);

		$lines = array_merge( $lines, $this->facts( $release, $previous, $trigger ) );

		$backup = isset( $summary['backup'] ) && is_array( $summary['backup'] ) ? $summary['backup'] : null;

		$lines[] = sprintf(
			/* translators: %s: number of files. */
			__( 'Files copied: %s', 'github-theme-updater' ),
			number_format_i18n( isset( $summary['files'] ) ? (int) $summary['files'] : 0 )
		);

		$lines[] = $backup && ! empty( $backup['id'] )
			? sprintf(
				/* translators: %s: backup identifier. */
				__( 'Backup of the previous version: %s (Backups tab)', 'github-theme-updater' ),
				(string) $backup['id']
			)
			: __( 'Backup of the previous version: none (backups are turned off, or the theme directory did not exist yet)', 'github-theme-updater' );

		if ( ! empty( $summary['protected'] ) && is_array( $summary['protected'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: comma separated list of paths. */
				__( 'Protected paths left untouched: %s', 'github-theme-updater' ),
				implode( ', ', array_map( 'strval', $summary['protected'] ) )
			);
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: URL of the plugin screen. */
			__( 'Plugin screen: %s', 'github-theme-updater' ),
			Admin_Page::url()
		);

		$lines = array_merge( $lines, $this->notes_block( $release ) );

		return $this->send( 'installed', $subject, $lines, compact( 'release', 'summary', 'previous', 'trigger' ) );
	}

	/**
	 * Reports a failed update.
	 *
	 * @param Release  $release  Release that failed to install.
	 * @param WP_Error $error    What went wrong.
	 * @param string   $previous Version installed before the attempt.
	 * @param string   $trigger  What started the run.
	 * @return bool Whether an e-mail went out.
	 */
	public function failed( Release $release, WP_Error $error, $previous, $trigger ) {
		$subject = sprintf(
			/* translators: 1: site name, 2: version that failed to install. */
			__( '[%1$s] Theme update to %2$s FAILED', 'github-theme-updater' ),
			$this->site_name(),
			$release->tag()
		);

		$lines = array(
			sprintf(
				/* translators: 1: site URL, 2: version. */
				__( 'The automatic update of the theme on %1$s to version %2$s did not complete.', 'github-theme-updater' ),
				home_url( '/' ),
				$release->label()
			),
			'',
			sprintf(
				/* translators: %s: error message. */
				__( 'Error: %s', 'github-theme-updater' ),
				$error->get_error_message()
			),
			'',
		);

		$lines   = array_merge( $lines, $this->facts( $release, $previous, $trigger ) );
		$lines[] = '';
		$lines[] = __( 'If the failure happened while files were being replaced, the theme was restored from the backup automatically and the error message above says so. Check the site anyway, then look at the Update tab for details:', 'github-theme-updater' );
		$lines[] = Admin_Page::url();
		$lines   = array_merge( $lines, $this->notes_block( $release ) );

		return $this->send( 'failed', $subject, $lines, compact( 'release', 'error', 'previous', 'trigger' ) );
	}

	/**
	 * Reports that GitHub could not be checked.
	 *
	 * @param WP_Error $error   What went wrong.
	 * @param string   $trigger What started the run.
	 * @return bool Whether an e-mail went out.
	 */
	public function check_failed( WP_Error $error, $trigger ) {
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Theme update check failed', 'github-theme-updater' ),
			$this->site_name()
		);

		$lines = array(
			sprintf(
				/* translators: %s: site URL. */
				__( 'The automatic update on %s could not check GitHub for a new version of the theme. Nothing was changed.', 'github-theme-updater' ),
				home_url( '/' )
			),
			'',
			sprintf(
				/* translators: %s: error message. */
				__( 'Error: %s', 'github-theme-updater' ),
				$error->get_error_message()
			),
			sprintf(
				/* translators: %s: what started the run. */
				__( 'Started by: %s', 'github-theme-updater' ),
				Auto_Updater::trigger_label( $trigger )
			),
			'',
			__( 'A message like this usually means an expired token or a renamed repository. This report is sent once; it repeats only if the error changes or after a successful check.', 'github-theme-updater' ),
			Admin_Page::url( 'settings' ),
		);

		return $this->send( 'check_failed', $subject, $lines, compact( 'error', 'trigger' ) );
	}

	/**
	 * Sends a test message to confirm the addresses and the mail setup work.
	 *
	 * @return true|WP_Error
	 */
	public function test() {
		$recipients = $this->recipients( 'test' );

		if ( empty( $recipients ) ) {
			return new WP_Error(
				'gthu_no_recipients',
				__( 'No valid e-mail address is saved. Enter at least one under Automatic updates and save the settings first.', 'github-theme-updater' )
			);
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Test message from GitHub Theme Updater', 'github-theme-updater' ),
			$this->site_name()
		);

		$lines = array(
			sprintf(
				/* translators: %s: site URL. */
				__( 'This is a test. Reports about automatic theme updates on %s will be sent to this address.', 'github-theme-updater' ),
				home_url( '/' )
			),
			'',
			sprintf(
				/* translators: %s: comma separated list of addresses. */
				__( 'Recipients: %s', 'github-theme-updater' ),
				implode( ', ', $recipients )
			),
		);

		if ( ! $this->send( 'test', $subject, $lines, array() ) ) {
			return new WP_Error(
				'gthu_mail_failed',
				__( 'WordPress could not send the message. Check the mail configuration of the site (an SMTP plugin usually helps).', 'github-theme-updater' )
			);
		}

		return true;
	}

	/**
	 * Lines shared by the success and failure reports.
	 *
	 * @param Release $release  Release involved.
	 * @param string  $previous Version installed before.
	 * @param string  $trigger  What started the run.
	 * @return string[]
	 */
	private function facts( Release $release, $previous, $trigger ) {
		$repository = $this->settings->repository();

		return array(
			sprintf(
				/* translators: %s: site name. */
				__( 'Site: %s', 'github-theme-updater' ),
				$this->site_name()
			),
			sprintf(
				/* translators: %s: theme directory name. */
				__( 'Theme directory: %s', 'github-theme-updater' ),
				(string) $this->settings->get( 'theme_slug', '' )
			),
			sprintf(
				/* translators: %s: repository name. */
				__( 'Repository: %s', 'github-theme-updater' ),
				$repository ? $repository->full_name() : ''
			),
			sprintf(
				/* translators: %s: version. */
				__( 'Previous version: %s', 'github-theme-updater' ),
				'' !== (string) $previous ? (string) $previous : __( 'unknown', 'github-theme-updater' )
			),
			sprintf(
				/* translators: %s: version. */
				__( 'New version: %s', 'github-theme-updater' ),
				$release->tag()
			),
			sprintf(
				/* translators: %s: what started the run. */
				__( 'Started by: %s', 'github-theme-updater' ),
				Auto_Updater::trigger_label( $trigger )
			),
			sprintf(
				/* translators: %s: date and time. */
				__( 'When: %s', 'github-theme-updater' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
			),
		);
	}

	/**
	 * The release notes, as written on GitHub, plus a link to the release.
	 *
	 * @param Release $release Release involved.
	 * @return string[]
	 */
	private function notes_block( Release $release ) {
		$repository = $this->settings->repository();
		$lines      = array( '', str_repeat( '-', 40 ), '' );

		$lines[] = sprintf(
			/* translators: %s: version. */
			__( 'Release notes for %s:', 'github-theme-updater' ),
			$release->label()
		);
		$lines[] = '';

		$notes = trim( $release->notes() );

		if ( '' === $notes ) {
			$lines[] = __( '(no release notes were written on GitHub)', 'github-theme-updater' );
		} else {
			$lines[] = wp_strip_all_tags( $notes );
		}

		if ( $repository ) {
			$lines[] = '';
			$lines[] = $repository->html_url() . '/releases/tag/' . rawurlencode( $release->tag() );
		}

		return $lines;
	}

	/**
	 * Sends a plain text message to the configured addresses.
	 *
	 * @param string               $event   What is being reported.
	 * @param string               $subject Subject line.
	 * @param string[]             $lines   Body lines.
	 * @param array<string, mixed> $context Objects the report was built from, for the filter.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	private function send( $event, $subject, array $lines, array $context ) {
		$recipients = $this->recipients( $event );

		if ( empty( $recipients ) ) {
			return false;
		}

		$message = array(
			'to'      => $recipients,
			'subject' => $subject,
			'body'    => implode( "\n", $lines ),
			'headers' => array( 'Content-Type: text/plain; charset=UTF-8' ),
		);

		/**
		 * Filters an automatic update report before it is sent.
		 *
		 * Return an empty array (or anything without a `to` key) to suppress it.
		 *
		 * @param array<string, mixed> $message Keys: to, subject, body, headers.
		 * @param string               $event   What is being reported: installed, failed, check_failed or test.
		 * @param array<string, mixed> $context Objects the report was built from.
		 */
		$message = (array) apply_filters( 'gthu_notification_message', $message, $event, $context );

		if ( empty( $message['to'] ) ) {
			return false;
		}

		return (bool) wp_mail(
			$message['to'],
			isset( $message['subject'] ) ? (string) $message['subject'] : $subject,
			isset( $message['body'] ) ? (string) $message['body'] : '',
			isset( $message['headers'] ) ? $message['headers'] : array()
		);
	}

	/**
	 * Site name with HTML entities resolved, for subject lines.
	 *
	 * @return string
	 */
	private function site_name() {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
