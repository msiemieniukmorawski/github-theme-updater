<?php
/**
 * Theme directory name sanitising tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Settings::sanitize_theme_slug
 */
final class ThemeSlugTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provideSlugs(): array {
		return array(
			'plain'                 => array( 'saketos', 'saketos' ),
			'hyphenated'            => array( 'twentytwentyfour-child', 'twentytwentyfour-child' ),
			'underscored'           => array( 'my_theme', 'my_theme' ),
			'dotted'                => array( 'theme.v2', 'theme.v2' ),
			'surrounding spaces'    => array( '  saketos  ', 'saketos' ),
			'trailing slash'        => array( 'saketos/', 'saketos' ),
			'full path'             => array( '/var/www/wp-content/themes/saketos', 'saketos' ),
			'windows path'          => array( 'C:\\www\\themes\\saketos', 'saketos' ),
			'traversal'             => array( '../../wp-config', 'wp-config' ),
			'inner spaces stripped' => array( 'my theme', 'mytheme' ),
		);
	}

	/**
	 * @dataProvider provideSlugs
	 *
	 * @param string $input    Raw input.
	 * @param string $expected Cleaned directory name.
	 */
	public function testCleansInput( string $input, string $expected ): void {
		$this->assertSame( $expected, Settings::sanitize_theme_slug( $input ) );
	}

	/**
	 * Theme directories such as `Divi` really exist, and lowercasing them would
	 * point the updater at a path that is not there.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provideMixedCaseSlugs(): array {
		return array(
			'capitalised'   => array( 'Divi' ),
			'camel case'    => array( 'AvadaChild' ),
			'mixed hyphens' => array( 'Avada-Child' ),
			'all caps'      => array( 'GENESIS' ),
		);
	}

	/**
	 * @dataProvider provideMixedCaseSlugs
	 *
	 * @param string $slug Directory name.
	 */
	public function testPreservesCase( string $slug ): void {
		$this->assertSame( $slug, Settings::sanitize_theme_slug( $slug ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provideUnusableSlugs(): array {
		return array(
			'empty'        => array( '' ),
			'spaces only'  => array( '   ' ),
			'current dir'  => array( '.' ),
			'parent dir'   => array( '..' ),
			'only dots'    => array( '...' ),
			'only slashes' => array( '///' ),
		);
	}

	/**
	 * @dataProvider provideUnusableSlugs
	 *
	 * @param string $slug Directory name.
	 */
	public function testRejectsUnusableNames( string $slug ): void {
		$this->assertSame( '', Settings::sanitize_theme_slug( $slug ) );
	}

	public function testResultNeverEscapesTheThemesDirectory(): void {
		foreach ( array( '../evil', '..\\evil', 'a/../../b', './x' ) as $input ) {
			$slug = Settings::sanitize_theme_slug( $input );

			$this->assertStringNotContainsString( '/', $slug );
			$this->assertStringNotContainsString( '\\', $slug );
			$this->assertNotSame( '..', $slug );
		}
	}
}
