<?php
/**
 * Protected path matching tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Path_Rules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Path_Rules
 */
final class PathRulesTest extends TestCase {

	/**
	 * A representative rule set.
	 *
	 * @return Path_Rules
	 */
	private function rules(): Path_Rules {
		return Path_Rules::from_text(
			"languages\n.env\nassets/css/client.css\n*.log\n\n# a comment\n/leading-slash/\n../escape\n"
		);
	}

	public function testNormalisesRulesOnParse(): void {
		$this->assertSame(
			array( 'languages', '.env', 'assets/css/client.css', '*.log', 'leading-slash', 'escape' ),
			$this->rules()->all()
		);
	}

	public function testDropsDuplicatesAndBlankLines(): void {
		$rules = Path_Rules::from_text( "a\n\n  \nb\na\n" );

		$this->assertSame( array( 'a', 'b' ), $rules->all() );
		$this->assertSame( "a\nb", $rules->to_text() );
	}

	public function testRecognisesAnEmptyRuleSet(): void {
		$this->assertTrue( Path_Rules::from_text( "\n  \n# only a comment\n" )->is_empty() );
		$this->assertFalse( Path_Rules::from_text( 'languages' )->is_empty() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provideProtectedPaths(): array {
		return array(
			'directory rule itself'  => array( 'languages' ),
			'file inside directory'  => array( 'languages/pl_PL.po' ),
			'deep file in directory' => array( 'languages/sub/deep.mo' ),
			'exact file'             => array( '.env' ),
			'nested exact file'      => array( 'assets/css/client.css' ),
			'wildcard at root'       => array( 'debug.log' ),
			'wildcard when nested'   => array( 'inc/nested/error.log' ),
			'normalised rule'        => array( 'leading-slash/file.php' ),
		);
	}

	/**
	 * @dataProvider provideProtectedPaths
	 *
	 * @param string $path Theme relative path.
	 */
	public function testProtectsMatchingPaths( string $path ): void {
		$this->assertTrue( $this->rules()->matches( $path ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provideUnprotectedPaths(): array {
		return array(
			'plain theme file'      => array( 'style.css' ),
			'functions'             => array( 'functions.php' ),
			'similar directory'     => array( 'languages-other/file.po' ),
			'sibling of exact file' => array( 'assets/css/style.css' ),
			'extension as name'     => array( 'inc/log' ),
			'prefix of a rule'      => array( '.environment' ),
			'empty path'            => array( '' ),
		);
	}

	/**
	 * @dataProvider provideUnprotectedPaths
	 *
	 * @param string $path Theme relative path.
	 */
	public function testLeavesEverythingElseAlone( string $path ): void {
		$this->assertFalse( $this->rules()->matches( $path ) );
	}

	public function testStripsTraversalSoRulesCannotEscapeTheTheme(): void {
		$this->assertFalse( $this->rules()->matches( '../../wp-config.php' ) );

		// `../escape` normalises to `escape`, which only ever matches inside the theme.
		$this->assertTrue( $this->rules()->matches( 'escape' ) );
	}

	public function testMatchingIsCaseInsensitive(): void {
		$rules = Path_Rules::from_text( 'Languages' );

		$this->assertTrue( $rules->matches( 'languages/pl_PL.po' ) );
	}

	public function testAcceptsWindowsSeparators(): void {
		$rules = Path_Rules::from_text( 'assets\\css\\client.css' );

		$this->assertTrue( $rules->matches( 'assets/css/client.css' ) );
	}

	public function testHasMatchInsideDetectsNestedRules(): void {
		$rules = Path_Rules::from_text( 'assets/css/client.css' );

		$this->assertTrue( $rules->has_match_inside( 'assets' ) );
		$this->assertTrue( $rules->has_match_inside( 'assets/css' ) );
		$this->assertFalse( $rules->has_match_inside( 'inc' ) );
	}

	public function testWildcardRuleForcesARecursiveWalk(): void {
		// A wildcard can match at any depth, so no directory may be deleted
		// wholesale without looking inside it first.
		$this->assertTrue( Path_Rules::from_text( '*.log' )->has_match_inside( 'inc' ) );
	}

	public function testGlobMatching(): void {
		$this->assertTrue( Path_Rules::glob_match( 'theme-*.zip', 'theme-1.2.0.zip' ) );
		$this->assertTrue( Path_Rules::glob_match( 'theme-?.zip', 'theme-1.zip' ) );
		$this->assertTrue( Path_Rules::glob_match( 'THEME-*.ZIP', 'theme-1.2.0.zip' ) );
		$this->assertFalse( Path_Rules::glob_match( 'theme-*.zip', 'source-map.txt' ) );
		$this->assertFalse( Path_Rules::glob_match( '', 'anything' ) );
		$this->assertTrue( Path_Rules::glob_match( 'exact.zip', 'exact.zip' ) );
	}

	public function testFromArrayMatchesFromText(): void {
		$this->assertSame(
			Path_Rules::from_text( "languages\n.env" )->all(),
			Path_Rules::from_array( array( 'languages', '.env' ) )->all()
		);
	}
}
