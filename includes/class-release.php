<?php
/**
 * Release value object.
 *
 * @package MSM\GitHubThemeUpdater
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * A downloadable version of the theme: a GitHub release, or a branch snapshot.
 */
final class Release {

	/**
	 * Tag name, or branch name for branch snapshots.
	 *
	 * @var string
	 */
	private $tag;

	/**
	 * Human readable release name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Archive URL to download.
	 *
	 * @var string
	 */
	private $zip_url;

	/**
	 * Publication timestamp.
	 *
	 * @var int
	 */
	private $published_at;

	/**
	 * Whether GitHub flags this release as a pre-release.
	 *
	 * @var bool
	 */
	private $prerelease;

	/**
	 * Release notes (raw markdown).
	 *
	 * @var string
	 */
	private $notes;

	/**
	 * Whether the archive is a release asset rather than a source zipball.
	 *
	 * @var bool
	 */
	private $is_asset;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $args Release properties.
	 */
	public function __construct( array $args ) {
		$args = array_merge(
			array(
				'tag'          => '',
				'name'         => '',
				'zip_url'      => '',
				'published_at' => 0,
				'prerelease'   => false,
				'notes'        => '',
				'is_asset'     => false,
			),
			$args
		);

		$this->tag          = (string) $args['tag'];
		$this->name         = (string) $args['name'];
		$this->zip_url      = (string) $args['zip_url'];
		$this->published_at = (int) $args['published_at'];
		$this->prerelease   = (bool) $args['prerelease'];
		$this->notes        = (string) $args['notes'];
		$this->is_asset     = (bool) $args['is_asset'];
	}

	/**
	 * Builds a release from a GitHub API payload.
	 *
	 * @param array<string, mixed> $data          Decoded release object.
	 * @param string               $asset_pattern Optional glob matched against asset names.
	 * @return Release|null Null when the payload carries no downloadable archive.
	 */
	public static function from_api( array $data, $asset_pattern = '' ) {
		$zip_url  = isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';
		$is_asset = false;

		$asset_pattern = trim( (string) $asset_pattern );

		if ( '' !== $asset_pattern && ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				$asset_name = isset( $asset['name'] ) ? (string) $asset['name'] : '';

				if ( '' === $asset_name || empty( $asset['url'] ) ) {
					continue;
				}

				if ( Path_Rules::glob_match( $asset_pattern, $asset_name ) ) {
					$zip_url  = (string) $asset['url'];
					$is_asset = true;
					break;
				}
			}
		}

		if ( '' === $zip_url ) {
			return null;
		}

		$tag = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';

		return new self(
			array(
				'tag'          => $tag,
				'name'         => isset( $data['name'] ) && '' !== $data['name'] ? (string) $data['name'] : $tag,
				'zip_url'      => $zip_url,
				'published_at' => isset( $data['published_at'] ) ? (int) strtotime( (string) $data['published_at'] ) : 0,
				'prerelease'   => ! empty( $data['prerelease'] ),
				'notes'        => isset( $data['body'] ) ? (string) $data['body'] : '',
				'is_asset'     => $is_asset,
			)
		);
	}

	/**
	 * Builds a pseudo release pointing at a branch snapshot.
	 *
	 * @param Repository $repository Repository.
	 * @param string     $branch     Branch name.
	 * @return Release
	 */
	public static function from_branch( Repository $repository, $branch ) {
		$branch = (string) $branch;

		return new self(
			array(
				'tag'          => $branch,
				/* translators: %s: git branch name. */
				'name'         => sprintf( __( 'Branch %s', 'github-theme-updater' ), $branch ),
				'zip_url'      => $repository->api_url( 'zipball/' . rawurlencode( $branch ) ),
				'published_at' => time(),
			)
		);
	}

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function tag() {
		return $this->tag;
	}

	/**
	 * Release name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Archive URL.
	 *
	 * @return string
	 */
	public function zip_url() {
		return $this->zip_url;
	}

	/**
	 * Publication timestamp.
	 *
	 * @return int
	 */
	public function published_at() {
		return $this->published_at;
	}

	/**
	 * Whether this is a pre-release.
	 *
	 * @return bool
	 */
	public function is_prerelease() {
		return $this->prerelease;
	}

	/**
	 * Whether the archive is a release asset.
	 *
	 * @return bool
	 */
	public function is_asset() {
		return $this->is_asset;
	}

	/**
	 * Release notes.
	 *
	 * @return string
	 */
	public function notes() {
		return $this->notes;
	}

	/**
	 * Version number with the conventional `v` prefix removed.
	 *
	 * @return string
	 */
	public function version() {
		return ltrim( $this->tag, 'vV' );
	}

	/**
	 * Label for select boxes and summaries.
	 *
	 * @return string
	 */
	public function label() {
		if ( '' === $this->name || $this->name === $this->tag ) {
			return $this->tag;
		}

		return sprintf( '%1$s (%2$s)', $this->name, $this->tag );
	}

	/**
	 * Whether this release is newer than the given version.
	 *
	 * @param string $version Version currently installed.
	 * @return bool
	 */
	public function is_newer_than( $version ) {
		$version = ltrim( (string) $version, 'vV' );

		if ( '' === $version ) {
			return true;
		}

		return version_compare( $this->version(), $version, '>' );
	}

	/**
	 * Serialises the release for transient storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'tag'          => $this->tag,
			'name'         => $this->name,
			'zip_url'      => $this->zip_url,
			'published_at' => $this->published_at,
			'prerelease'   => $this->prerelease,
			'notes'        => $this->notes,
			'is_asset'     => $this->is_asset,
		);
	}
}
