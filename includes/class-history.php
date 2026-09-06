<?php
/**
 * Operation log.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a short log of the most recent updates and restores.
 *
 * Every attempt is recorded, successful or not, so the Update tab can answer
 * "what happened last time, who did it and how long did it take" without
 * anyone digging through server logs.
 */
final class History {

	/**
	 * Option holding the log.
	 */
	const OPTION = 'gthu_history';

	/**
	 * How many entries are kept by default.
	 */
	const DEFAULT_LIMIT = 5;

	/**
	 * Number of entries kept.
	 *
	 * @return int
	 */
	public static function limit() {
		/**
		 * Filters how many operations the log keeps.
		 *
		 * @param int $limit Number of entries.
		 */
		return max( 1, (int) apply_filters( 'gthu_history_limit', self::DEFAULT_LIMIT ) );
	}

	/**
	 * Adds an entry at the top of the log, dropping the oldest beyond the limit.
	 *
	 * @param array<string, mixed> $entry Entry fields; missing ones get defaults.
	 * @return void
	 */
	public static function record( array $entry ) {
		$entry = wp_parse_args( $entry, self::defaults() );

		$entry['duration'] = round( (float) $entry['duration'], 1 );
		$entry['files']    = (int) $entry['files'];
		$entry['time']     = (int) $entry['time'];
		$entry['user']     = (int) $entry['user'];

		$entries = self::all();

		array_unshift( $entries, $entry );

		update_option( self::OPTION, array_slice( $entries, 0, self::limit() ), false );
	}

	/**
	 * Entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		$entries  = array_values( array_filter( $entries, 'is_array' ) );
		$defaults = self::defaults();

		foreach ( $entries as &$entry ) {
			$entry = wp_parse_args( $entry, $defaults );
		}

		unset( $entry );

		return $entries;
	}

	/**
	 * Fields of an entry and their defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults() {
		return array(
			'action'   => 'install',
			'status'   => 'success',
			'version'  => '',
			'previous' => '',
			'message'  => '',
			'duration' => 0,
			'files'    => 0,
			'backup'   => '',
			'time'     => time(),
			'user'     => get_current_user_id(),
		);
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Formats a duration for display.
	 *
	 * @param float $seconds Duration in seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (float) $seconds );

		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %s: number of seconds. */
				__( '%s s', 'github-theme-updater' ),
				number_format_i18n( $seconds, $seconds < 10 ? 1 : 0 )
			);
		}

		return sprintf(
			/* translators: 1: number of minutes, 2: number of seconds. */
			__( '%1$s min %2$s s', 'github-theme-updater' ),
			number_format_i18n( floor( $seconds / 60 ) ),
			number_format_i18n( floor( $seconds ) % 60 )
		);
	}
}
