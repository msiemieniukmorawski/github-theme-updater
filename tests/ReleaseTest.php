<?php
/**
 * Release value object tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Release;
use MSM\GitHubThemeUpdater\Repository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Release
 */
final class ReleaseTest extends TestCase {

	/**
	 * A GitHub release payload, trimmed to the fields the plugin reads.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'tag_name'     => 'v1.2.0',
				'name'         => 'Cart fixes',
				'zipball_url'  => 'https://api.github.com/repos/a/b/zipball/v1.2.0',
				'published_at' => '2026-01-15T10:00:00Z',
				'prerelease'   => false,
				'body'         => 'Change log',
				'assets'       => array(
					array(
						'name' => 'theme-1.2.0.zip',
						'url'  => 'https://api.github.com/repos/a/b/releases/assets/9',
					),
					array(
						'name' => 'source-map.txt',
						'url'  => 'https://api.github.com/repos/a/b/releases/assets/10',
					),
				),
			),
			$overrides
		);
	}

	public function testReadsTheApiPayload(): void {
		$release = Release::from_api( $this->payload() );

		$this->assertSame( 'v1.2.0', $release->tag() );
		$this->assertSame( '1.2.0', $release->version() );
		$this->assertSame( 'Cart fixes (v1.2.0)', $release->label() );
		$this->assertSame( 'Change log', $release->notes() );
		$this->assertFalse( $release->is_prerelease() );
		$this->assertSame( strtotime( '2026-01-15T10:00:00Z' ), $release->published_at() );
	}

	public function testDefaultsToTheSourceZipball(): void {
		$release = Release::from_api( $this->payload() );

		$this->assertSame( 'https://api.github.com/repos/a/b/zipball/v1.2.0', $release->zip_url() );
		$this->assertFalse( $release->is_asset() );
	}

	public function testPicksTheAssetMatchingThePattern(): void {
		$release = Release::from_api( $this->payload(), 'theme-*.zip' );

		$this->assertSame( 'https://api.github.com/repos/a/b/releases/assets/9', $release->zip_url() );
		$this->assertTrue(
			$release->is_asset(),
			'Assets need a different Accept header, so the flag has to be set.'
		);
	}

	public function testFallsBackToTheZipballWhenNoAssetMatches(): void {
		$release = Release::from_api( $this->payload(), 'nothing-*.zip' );

		$this->assertSame( 'https://api.github.com/repos/a/b/zipball/v1.2.0', $release->zip_url() );
		$this->assertFalse( $release->is_asset() );
	}

	public function testReturnsNullWithoutAnyArchive(): void {
		$this->assertNull( Release::from_api( array( 'tag_name' => 'v1.0.0' ) ) );
	}

	public function testLabelFallsBackToTheTag(): void {
		$this->assertSame( 'v1.2.0', Release::from_api( $this->payload( array( 'name' => '' ) ) )->label() );
		$this->assertSame( 'v1.2.0', Release::from_api( $this->payload( array( 'name' => 'v1.2.0' ) ) )->label() );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function provideInstalledVersions(): array {
		return array(
			'older'                 => array( 'v1.1.9', true ),
			'older without prefix'  => array( '1.1.9', true ),
			'identical'             => array( 'v1.2.0', false ),
			'identical no prefix'   => array( '1.2.0', false ),
			'newer'                 => array( '1.3.0', false ),
			'nothing installed yet' => array( '', true ),
			'shorter version'       => array( '1.2', true ),
		);
	}

	/**
	 * @dataProvider provideInstalledVersions
	 *
	 * @param string $installed Version currently on the site.
	 * @param bool   $expected  Whether the release counts as newer.
	 */
	public function testComparesVersionsIgnoringTheVPrefix( string $installed, bool $expected ): void {
		$this->assertSame( $expected, Release::from_api( $this->payload() )->is_newer_than( $installed ) );
	}

	public function testSurvivesTheTransientRoundTrip(): void {
		$release  = Release::from_api( $this->payload(), 'theme-*.zip' );
		$restored = new Release( $release->to_array() );

		$this->assertSame( $release->tag(), $restored->tag() );
		$this->assertSame( $release->label(), $restored->label() );
		$this->assertSame( $release->zip_url(), $restored->zip_url() );
		$this->assertSame( $release->is_asset(), $restored->is_asset() );
		$this->assertSame( $release->published_at(), $restored->published_at() );
	}

	public function testBuildsABranchSnapshot(): void {
		$release = Release::from_branch( Repository::from_string( 'owner/repo' ), 'develop' );

		$this->assertSame( 'develop', $release->tag() );
		$this->assertSame( 'https://api.github.com/repos/owner/repo/zipball/develop', $release->zip_url() );
		$this->assertFalse( $release->is_asset() );
	}

	public function testEncodesBranchNamesWithSlashes(): void {
		$release = Release::from_branch( Repository::from_string( 'owner/repo' ), 'feature/new-header' );

		$this->assertSame(
			'https://api.github.com/repos/owner/repo/zipball/feature%2Fnew-header',
			$release->zip_url()
		);
	}
}
