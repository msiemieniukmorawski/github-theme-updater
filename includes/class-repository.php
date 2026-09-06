<?php
/**
 * GitHub repository value object.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable `owner/name` pair, plus the URL builders that go with it.
 */
final class Repository {

	/**
	 * Repository owner (user or organisation).
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Repository name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Constructor.
	 *
	 * @param string $owner Repository owner.
	 * @param string $name  Repository name.
	 */
	public function __construct( $owner, $name ) {
		$this->owner = (string) $owner;
		$this->name  = (string) $name;
	}

	/**
	 * Parses any reasonable way a user might paste a repository reference.
	 *
	 * Accepts `owner/repo`, browser URLs, SSH remotes, `.git` suffixes and the
	 * REST endpoints that version 1.x of this plugin asked people to type in.
	 *
	 * @param string $value Raw user input.
	 * @return Repository|null Null when nothing usable was found.
	 */
	public static function from_string( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		// Matches api.github.com/repos/owner/repo, github.com/owner/repo and git@github.com:owner/repo.git.
		if ( preg_match( '~github\.com[/:]+(?:repos/)?([^/\s]+)/([^/\s?\#]+)~i', $value, $matches ) ) {
			return self::build( $matches[1], $matches[2] );
		}

		// Bare owner/repo.
		if ( preg_match( '~^([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)$~', $value, $matches ) ) {
			return self::build( $matches[1], $matches[2] );
		}

		return null;
	}

	/**
	 * Creates an instance after cleaning up the captured segments.
	 *
	 * @param string $owner Raw owner segment.
	 * @param string $name  Raw name segment.
	 * @return Repository|null
	 */
	private static function build( $owner, $name ) {
		$owner = trim( $owner );
		$name  = preg_replace( '/\.git$/i', '', trim( $name ) );

		if ( '' === $owner || '' === $name ) {
			return null;
		}

		return new self( $owner, $name );
	}

	/**
	 * Repository owner.
	 *
	 * @return string
	 */
	public function owner() {
		return $this->owner;
	}

	/**
	 * Repository name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Canonical `owner/name` identifier.
	 *
	 * @return string
	 */
	public function full_name() {
		return $this->owner . '/' . $this->name;
	}

	/**
	 * Builds an API endpoint for this repository.
	 *
	 * @param string $path Endpoint path relative to the repository root.
	 * @return string
	 */
	public function api_url( $path = '' ) {
		$base = 'https://api.github.com/repos/' . rawurlencode( $this->owner ) . '/' . rawurlencode( $this->name );

		return '' === $path ? $base : $base . '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Human facing repository URL.
	 *
	 * @return string
	 */
	public function html_url() {
		return 'https://github.com/' . rawurlencode( $this->owner ) . '/' . rawurlencode( $this->name );
	}

	/**
	 * URL of the releases page.
	 *
	 * @return string
	 */
	public function releases_url() {
		return $this->html_url() . '/releases';
	}
}
