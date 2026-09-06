<?php
/**
 * Filesystem tests, run against the real WP_Filesystem_Direct.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Filesystem;
use MSM\GitHubThemeUpdater\Path_Rules;
use PHPUnit\Framework\TestCase;
use WP_Filesystem_Direct;

/**
 * @covers \MSM\GitHubThemeUpdater\Filesystem
 */
final class FilesystemTest extends TestCase {

	private string $root;

	private Filesystem $fs;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/gthu-fs-' . uniqid();
		mkdir( $this->root, 0777, true );

		$this->fs = Filesystem::wrap( new WP_Filesystem_Direct( null ) );
	}

	protected function tearDown(): void {
		$this->wipe( $this->root );
	}

	public function testEmptyDirRemovesEverythingButProtectedPaths(): void {
		$this->seed(
			array(
				'style.css',
				'.env',
				'languages/pl_PL.mo',
				'assets/css/main.css',
				'assets/css/client.css',
				'deep/er/still/file.txt',
			)
		);

		$result = $this->fs->empty_dir( $this->root, Path_Rules::from_text( "languages\n.env\nassets/css/client.css" ) );

		$this->assertTrue( $result );
		$this->assertFileDoesNotExist( $this->root . '/style.css' );
		$this->assertFileDoesNotExist( $this->root . '/assets/css/main.css' );
		$this->assertDirectoryDoesNotExist( $this->root . '/deep', 'a directory holding nothing protected goes entirely' );

		$this->assertFileExists( $this->root . '/.env' );
		$this->assertFileExists( $this->root . '/languages/pl_PL.mo' );
		$this->assertFileExists( $this->root . '/assets/css/client.css', 'a protected file keeps its parent directories' );
	}

	public function testEmptyDirWithoutRulesLeavesAnEmptyDirectory(): void {
		$this->seed( array( 'a.txt', 'b/c.txt' ) );

		$this->assertTrue( $this->fs->empty_dir( $this->root ) );
		$this->assertDirectoryExists( $this->root );
		$this->assertSame( array(), $this->entries( $this->root ) );
	}

	public function testCopyTreeSkipsRulesAndReportsEveryCopiedFile(): void {
		$this->seed( array( 'src/style.css', 'src/skip.txt', 'src/inc/a.php', 'src/node_modules/x/y.js' ) );

		$copied = array();

		$result = $this->fs->copy_tree(
			$this->root . '/src',
			$this->root . '/dst',
			Path_Rules::from_text( "skip.txt\nnode_modules" ),
			'',
			static function ( string $relative ) use ( &$copied ): void {
				$copied[] = $relative;
			}
		);

		$this->assertTrue( $result );
		$this->assertFileExists( $this->root . '/dst/style.css' );
		$this->assertFileExists( $this->root . '/dst/inc/a.php' );
		$this->assertFileDoesNotExist( $this->root . '/dst/skip.txt' );
		$this->assertDirectoryDoesNotExist( $this->root . '/dst/node_modules' );

		sort( $copied );
		$this->assertSame( array( 'inc/a.php', 'style.css' ), $copied );
	}

	public function testCopyTreeDoesNotTouchProtectedFilesInTheDestination(): void {
		$this->seed( array( 'src/.env', 'src/style.css', 'dst/.env' ) );
		file_put_contents( $this->root . '/dst/.env', 'keep me' );

		$this->assertTrue( $this->fs->copy_tree( $this->root . '/src', $this->root . '/dst', Path_Rules::from_text( '.env' ) ) );
		$this->assertSame( 'keep me', file_get_contents( $this->root . '/dst/.env' ) );
		$this->assertFileExists( $this->root . '/dst/style.css' );
	}

	public function testDeleteRemovesReadOnlyFiles(): void {
		$this->seed( array( 'vendor/pkg/.git/objects/pack/pack.idx', 'vendor/pkg/.git/objects/pack/pack.pack' ) );
		chmod( $this->root . '/vendor/pkg/.git/objects/pack/pack.idx', 0444 );
		chmod( $this->root . '/vendor/pkg/.git/objects/pack/pack.pack', 0444 );

		$this->assertTrue( $this->fs->delete( $this->root . '/vendor' ) );
		$this->assertDirectoryDoesNotExist( $this->root . '/vendor' );
	}

	public function testEmptyDirRemovesReadOnlyFilesInsideUnprotectedDirectories(): void {
		$this->seed( array( 'vendor/pkg/.git/pack.pack', 'style.css' ) );
		chmod( $this->root . '/vendor/pkg/.git/pack.pack', 0444 );

		$this->assertTrue( $this->fs->empty_dir( $this->root, Path_Rules::from_text( '.env' ) ) );
		$this->assertSame( array(), $this->entries( $this->root ) );
	}

	public function testEnsureDeletableAcceptsReadOnlyFiles(): void {
		$this->seed( array( 'a/b.txt', 'ignored/c.txt' ) );
		chmod( $this->root . '/a/b.txt', 0444 );
		chmod( $this->root . '/ignored/c.txt', 0444 );

		$this->assertTrue( $this->fs->ensure_deletable( $this->root, Path_Rules::from_text( 'ignored' ) ) );

		// Only Windows refuses to unlink a read-only file, so only there the
		// flag is cleared up front. On Linux deletion needs a writable parent
		// directory, which is what the check looks at; the file stays as it was.
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->assertTrue( is_writable( $this->root . '/a/b.txt' ), 'read-only flag cleared so unlink() will accept the file' );
			$this->assertFalse( is_writable( $this->root . '/ignored/c.txt' ), 'ignored paths are left alone' );
		}

		$this->assertTrue( $this->fs->empty_dir( $this->root, Path_Rules::from_text( 'ignored' ) ) );
		$this->assertFileDoesNotExist( $this->root . '/a/b.txt' );
		$this->assertFileExists( $this->root . '/ignored/c.txt' );
	}

	public function testEnsureDeletableReportsADirectoryThePhpUserCannotWrite(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Directory modes do not restrict deletion on Windows.' );
		}

		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root ignores directory modes.' );
		}

		$this->seed( array( 'locked/file.txt' ) );
		chmod( $this->root . '/locked', 0555 );

		$result = $this->fs->ensure_deletable( $this->root );

		chmod( $this->root . '/locked', 0755 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gthu_not_deletable', $result->get_error_code() );
		$this->assertStringContainsString( 'locked', $result->get_error_message() );
	}

	public function testLocateThemeRootFindsANestedStyleCss(): void {
		$this->seed( array( 'owner-repo-abc1234/style.css', 'owner-repo-abc1234/index.php' ) );

		$this->assertSame( $this->root . '/owner-repo-abc1234', $this->fs->locate_theme_root( $this->root ) );
	}

	public function testLocateThemeRootGivesUpBeyondThreeLevels(): void {
		$this->seed( array( 'a/b/c/d/style.css' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->fs->locate_theme_root( $this->root ) );
	}

	/**
	 * Creates empty files (and their directories) under the temporary root.
	 *
	 * @param string[] $files Relative paths.
	 */
	private function seed( array $files ): void {
		foreach ( $files as $file ) {
			$path = $this->root . '/' . $file;

			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}

			file_put_contents( $path, $file );
		}
	}

	/**
	 * @return string[]
	 */
	private function entries( string $dir ): array {
		return array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) );
	}

	private function wipe( string $path ): void {
		if ( is_dir( $path ) ) {
			chmod( $path, 0777 );

			foreach ( $this->entries( $path ) as $entry ) {
				$this->wipe( $path . '/' . $entry );
			}

			rmdir( $path );

			return;
		}

		if ( file_exists( $path ) ) {
			chmod( $path, 0666 );
			unlink( $path );
		}
	}
}
