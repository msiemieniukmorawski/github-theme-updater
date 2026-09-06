<?php
/**
 * GitHub REST API client.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that talks HTTP to github.com lives here.
 *
 * The rest of the plugin only ever sees `Release` objects and `WP_Error`s, so
 * the API shape stays in one file.
 */
final class Github_Client {

	/**
	 * Transient caching the release list.
	 */
	const CACHE_KEY = 'gthu_releases_cache';

	/**
	 * API version header value.
	 */
	const API_VERSION = '2022-11-28';

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
	 * Returns the releases of the configured repository, newest first.
	 *
	 * @param bool $force Bypass the cache.
	 * @return Release[]|WP_Error
	 */
	public function get_releases( $force = false ) {
		$repository = $this->settings->repository();

		if ( ! $repository ) {
			return $this->not_configured();
		}

		if ( ! $force ) {
			$cached = $this->read_cache( $repository );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$response = $this->request( $repository->api_url( 'releases?per_page=30' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$pattern             = (string) $this->settings->get( 'asset_pattern', '' );
		$include_prereleases = (bool) $this->settings->get( 'include_prereleases', false );
		$releases            = array();

		foreach ( $response as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['draft'] ) ) {
				continue;
			}

			if ( ! $include_prereleases && ! empty( $item['prerelease'] ) ) {
				continue;
			}

			$release = Release::from_api( $item, $pattern );

			if ( $release ) {
				$releases[] = $release;
			}
		}

		$this->write_cache( $repository, $releases );

		return $releases;
	}

	/**
	 * Returns the release the plugin would install right now.
	 *
	 * In branch mode this is a snapshot of the configured branch; otherwise it
	 * is the newest release.
	 *
	 * @param bool $force Bypass the cache.
	 * @return Release|WP_Error
	 */
	public function get_latest_release( $force = false ) {
		$repository = $this->settings->repository();

		if ( ! $repository ) {
			return $this->not_configured();
		}

		if ( 'branch' === $this->settings->get( 'source' ) ) {
			return Release::from_branch( $repository, (string) $this->settings->get( 'branch', 'main' ) );
		}

		$releases = $this->get_releases( $force );

		if ( is_wp_error( $releases ) ) {
			return $releases;
		}

		if ( empty( $releases ) ) {
			return new WP_Error(
				'gthu_no_releases',
				sprintf(
					/* translators: %s: repository URL. */
					__( 'The repository %s has no releases yet. Create one on GitHub, or switch the plugin to branch mode under Settings.', 'github-theme-updater' ),
					$repository->releases_url()
				)
			);
		}

		return $releases[0];
	}

	/**
	 * Finds a release by its tag.
	 *
	 * @param string $tag Tag name.
	 * @return Release|WP_Error
	 */
	public function get_release_by_tag( $tag ) {
		$tag      = (string) $tag;
		$releases = $this->get_releases();

		if ( is_wp_error( $releases ) ) {
			return $releases;
		}

		foreach ( $releases as $release ) {
			if ( $release->tag() === $tag ) {
				return $release;
			}
		}

		return new WP_Error(
			'gthu_release_not_found',
			sprintf(
				/* translators: %s: git tag name. */
				__( 'No release tagged %s was found.', 'github-theme-updater' ),
				$tag
			)
		);
	}

	/**
	 * Checks that the repository is reachable with the current credentials.
	 *
	 * @return array{full_name: string, private: bool, default_branch: string}|WP_Error Repository metadata on success.
	 */
	public function test_connection() {
		$repository = $this->settings->repository();

		if ( ! $repository ) {
			return $this->not_configured();
		}

		$response = $this->request( $repository->api_url() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'full_name'      => isset( $response['full_name'] ) ? (string) $response['full_name'] : $repository->full_name(),
			'private'        => ! empty( $response['private'] ),
			'default_branch' => isset( $response['default_branch'] ) ? (string) $response['default_branch'] : 'main',
		);
	}

	/**
	 * Downloads a release archive to a temporary file.
	 *
	 * GitHub answers archive requests with a redirect to a signed storage URL.
	 * The redirect is followed manually and without the `Authorization` header,
	 * because storage backends reject requests carrying foreign credentials.
	 *
	 * @param Release $release Release to download.
	 * @return string|WP_Error Absolute path to the downloaded file.
	 */
	public function download( Release $release ) {
		$url = $release->zip_url();

		if ( '' === $url ) {
			return new WP_Error(
				'gthu_missing_zip',
				__( 'This release has no ZIP file to download.', 'github-theme-updater' )
			);
		}

		$headers = $this->headers();

		if ( $release->is_asset() ) {
			$headers['Accept'] = 'application/octet-stream';
		}

		$probe = wp_remote_get(
			$url,
			array(
				'headers'     => $headers,
				'redirection' => 0,
				'timeout'     => 30,
			)
		);

		if ( is_wp_error( $probe ) ) {
			return $this->transport_error( $probe );
		}

		$status   = (int) wp_remote_retrieve_response_code( $probe );
		$location = wp_remote_retrieve_header( $probe, 'location' );

		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) && $location ) {
			$url     = is_array( $location ) ? end( $location ) : $location;
			$headers = array( 'User-Agent' => $this->user_agent() );
		} elseif ( $status >= 400 ) {
			return $this->http_error( $status, wp_remote_retrieve_body( $probe ) );
		}

		$destination = wp_tempnam( 'gthu-theme.zip' );

		if ( ! $destination ) {
			return new WP_Error(
				'gthu_tempfile',
				__( 'Could not create a temporary file. Check that the temporary directory is writable.', 'github-theme-updater' )
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers'  => $headers,
				'timeout'  => (int) apply_filters( 'gthu_download_timeout', 300 ),
				'stream'   => true,
				'filename' => $destination,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $destination );

			return $this->transport_error( $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 400 ) {
			$body = file_exists( $destination ) ? (string) file_get_contents( $destination ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $destination );

			return $this->http_error( $status, $body );
		}

		if ( ! file_exists( $destination ) || filesize( $destination ) < 1024 ) {
			wp_delete_file( $destination );

			return new WP_Error(
				'gthu_empty_download',
				__( 'The downloaded file is empty or corrupt. Try again in a moment.', 'github-theme-updater' )
			);
		}

		// An HTML error page saved under a .zip name is the classic failure
		// mode of a bad token or a moved repository; the ZIP signature catches it.
		$magic = (string) file_get_contents( $destination, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( "PK\x03\x04" !== $magic ) {
			wp_delete_file( $destination );

			return new WP_Error(
				'gthu_not_zip',
				__( 'The downloaded file is not a ZIP archive. GitHub may have answered with an error page — check the token and the repository address.', 'github-theme-updater' )
			);
		}

		return $destination;
	}

	/**
	 * Performs a GET request against the API and decodes the payload.
	 *
	 * @param string $url Absolute API URL.
	 * @return array<mixed>|WP_Error
	 */
	private function request( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->headers(),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status >= 400 ) {
			return $this->http_error( $status, $body, $response );
		}

		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'gthu_invalid_json',
				__( 'The GitHub response is not valid JSON.', 'github-theme-updater' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'gthu_bad_response',
				__( 'GitHub returned an unexpected response while fetching the release list.', 'github-theme-updater' )
			);
		}

		return $data;
	}

	/**
	 * Request headers, including authentication when a token is configured.
	 *
	 * @return array<string, string>
	 */
	private function headers() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => $this->user_agent(),
			'X-GitHub-Api-Version' => self::API_VERSION,
		);

		$token = $this->settings->token();

		if ( '' !== $token ) {
			// Bearer works for both classic and fine-grained tokens.
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * User agent sent to GitHub.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'GitHub-Theme-Updater/' . VERSION . '; ' . home_url( '/' );
	}

	/**
	 * Turns a transport failure into a readable error.
	 *
	 * @param WP_Error $error Original error.
	 * @return WP_Error
	 */
	private function transport_error( WP_Error $error ) {
		return new WP_Error(
			'gthu_transport',
			sprintf(
				/* translators: %s: underlying HTTP error message. */
				__( 'Could not connect to GitHub: %s', 'github-theme-updater' ),
				$error->get_error_message()
			)
		);
	}

	/**
	 * Turns an HTTP error status into an actionable message.
	 *
	 * @param int                       $status   HTTP status code.
	 * @param string                    $body     Response body.
	 * @param array<string, mixed>|null $response Full response, used to read rate limit headers.
	 * @return WP_Error
	 */
	private function http_error( $status, $body, $response = null ) {
		$decoded = json_decode( (string) $body, true );
		$detail  = is_array( $decoded ) && ! empty( $decoded['message'] ) ? (string) $decoded['message'] : '';

		switch ( $status ) {
			case 401:
				$message = __( 'GitHub rejected the token (401). The token is invalid or has expired — generate a new one and save it under Settings.', 'github-theme-updater' );
				break;

			case 403:
				$remaining = $response ? wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) : '';

				if ( '0' === (string) $remaining ) {
					$message = __( 'The GitHub request limit was exceeded (403). Try again in a quarter of an hour or so.', 'github-theme-updater' );
				} else {
					$message = __( 'No access to this repository (403). Make sure the token has the “repo” scope (classic) or “Contents: Read” access to this repository (fine-grained).', 'github-theme-updater' );
				}
				break;

			case 404:
				$message = __( 'Repository not found (404). Check the owner/repository name, and for a private repository, whether the token has access to it.', 'github-theme-updater' );
				break;

			case 429:
				$message = __( 'Too many requests to GitHub (429). Wait a moment and try again.', 'github-theme-updater' );
				break;

			default:
				$message = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GitHub responded with HTTP error %d.', 'github-theme-updater' ),
					$status
				);
		}//end switch

		if ( '' !== $detail ) {
			$message .= ' ' . sprintf(
				/* translators: %s: error message returned by the GitHub API. */
				__( 'GitHub says: %s', 'github-theme-updater' ),
				$detail
			);
		}

		return new WP_Error( 'gthu_http_' . $status, $message, array( 'status' => $status ) );
	}

	/**
	 * Error used when the repository is not configured yet.
	 *
	 * @return WP_Error
	 */
	private function not_configured() {
		return new WP_Error(
			'gthu_not_configured',
			__( 'No GitHub repository is set. Fill it in on the Settings tab.', 'github-theme-updater' )
		);
	}

	/**
	 * Reads the cached release list.
	 *
	 * @param Repository $repository Repository the cache must belong to.
	 * @return Release[]|null Null on a cache miss.
	 */
	private function read_cache( Repository $repository ) {
		$cached = get_transient( self::CACHE_KEY );

		if ( ! is_array( $cached ) || empty( $cached['repository'] ) || $cached['repository'] !== $repository->full_name() ) {
			return null;
		}

		if ( ! isset( $cached['releases'] ) || ! is_array( $cached['releases'] ) ) {
			return null;
		}

		return array_map(
			static function ( $data ) {
				return new Release( (array) $data );
			},
			$cached['releases']
		);
	}

	/**
	 * Caches the release list.
	 *
	 * @param Repository $repository Repository.
	 * @param Release[]  $releases   Releases.
	 * @return void
	 */
	private function write_cache( Repository $repository, array $releases ) {
		set_transient(
			self::CACHE_KEY,
			array(
				'repository' => $repository->full_name(),
				'releases'   => array_map(
					static function ( Release $release ) {
						return $release->to_array();
					},
					$releases
				),
			),
			(int) apply_filters( 'gthu_cache_lifetime', 15 * MINUTE_IN_SECONDS )
		);
	}

	/**
	 * Drops the cached release list.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
