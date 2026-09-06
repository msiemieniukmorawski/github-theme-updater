<?php
/**
 * Repository parsing tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Repository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Repository
 */
final class RepositoryTest extends TestCase {

	/**
	 * Every shape a user might paste into the repository field.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provideAcceptedInput(): array {
		return array(
			'bare owner/repo'      => array( 'studiozaiste/saketos', 'studiozaiste/saketos' ),
			'browser url'          => array( 'https://github.com/studiozaiste/saketos', 'studiozaiste/saketos' ),
			'trailing slash'       => array( 'https://github.com/studiozaiste/saketos/', 'studiozaiste/saketos' ),
			'git suffix'           => array( 'https://github.com/studiozaiste/saketos.git', 'studiozaiste/saketos' ),
			'ssh remote'           => array( 'git@github.com:studiozaiste/saketos.git', 'studiozaiste/saketos' ),
			'deep url'             => array( 'https://github.com/studiozaiste/saketos/tree/main/assets', 'studiozaiste/saketos' ),
			'v1 api endpoint'      => array( 'https://api.github.com/repos/studiozaiste/saketos/releases/latest', 'studiozaiste/saketos' ),
			'dots and underscores' => array( 'ms-m.pl/my_theme-2', 'ms-m.pl/my_theme-2' ),
			'surrounding spaces'   => array( '  studiozaiste/saketos  ', 'studiozaiste/saketos' ),
			'uppercase host'       => array( 'HTTPS://GitHub.com/Studio/Theme', 'Studio/Theme' ),
		);
	}

	/**
	 * @dataProvider provideAcceptedInput
	 *
	 * @param string $input    Raw user input.
	 * @param string $expected Canonical owner/repo.
	 */
	public function testParsesEveryAcceptedForm( string $input, string $expected ): void {
		$repository = Repository::from_string( $input );

		$this->assertNotNull( $repository, 'Expected the input to be recognised.' );
		$this->assertSame( $expected, $repository->full_name() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provideRejectedInput(): array {
		return array(
			'empty'          => array( '' ),
			'whitespace'     => array( '   ' ),
			'single segment' => array( 'saketos' ),
			'prose'          => array( 'jakis tekst bez sensu' ),
			'other host'     => array( 'https://gitlab.com/owner/repo' ),
			'no owner'       => array( '/saketos' ),
		);
	}

	/**
	 * @dataProvider provideRejectedInput
	 *
	 * @param string $input Raw user input.
	 */
	public function testRejectsUnusableInput( string $input ): void {
		$this->assertNull( Repository::from_string( $input ) );
	}

	public function testSplitsOwnerAndName(): void {
		$repository = Repository::from_string( 'studiozaiste/saketos' );

		$this->assertSame( 'studiozaiste', $repository->owner() );
		$this->assertSame( 'saketos', $repository->name() );
	}

	public function testBuildsApiUrls(): void {
		$repository = Repository::from_string( 'owner/repo' );

		$this->assertSame( 'https://api.github.com/repos/owner/repo', $repository->api_url() );
		$this->assertSame( 'https://api.github.com/repos/owner/repo/releases', $repository->api_url( 'releases' ) );
		$this->assertSame(
			'https://api.github.com/repos/owner/repo/releases',
			$repository->api_url( '/releases' ),
			'A leading slash in the path must not double up.'
		);
	}

	public function testBuildsHumanUrls(): void {
		$repository = Repository::from_string( 'owner/repo' );

		$this->assertSame( 'https://github.com/owner/repo', $repository->html_url() );
		$this->assertSame( 'https://github.com/owner/repo/releases', $repository->releases_url() );
	}
}
