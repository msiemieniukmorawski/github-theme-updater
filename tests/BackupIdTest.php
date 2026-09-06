<?php
/**
 * Backup identifier tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Backup_Manager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Backup_Manager::sanitize_id
 */
final class BackupIdTest extends TestCase {

	public function testKeepsAWellFormedId(): void {
		$this->assertSame(
			'fizjoSmart__1.2.0__1788724619',
			Backup_Manager::sanitize_id( 'fizjoSmart__1.2.0__1788724619' )
		);
	}

	public function testReplacesForeignCharacters(): void {
		$this->assertSame( 'my-theme--v1-0-', Backup_Manager::sanitize_id( 'my theme/ v1:0!' ) );
	}

	/**
	 * @dataProvider traversalIds
	 */
	public function testRejectsAnythingThatCouldLeaveTheBackupDirectory( string $id ): void {
		$this->assertSame( '', Backup_Manager::sanitize_id( $id ), "'$id' must not become a backup path" );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function traversalIds(): array {
		return array(
			'parent'              => array( '..' ),
			'current'             => array( '.' ),
			'many dots'           => array( '....' ),
			'empty'               => array( '' ),
			'parent with slash'   => array( '../' ),
			'parent inside'       => array( 'a/../b' ),
			'parent after clean'  => array( 'a..b' ),
			'backslash traversal' => array( '..\\..\\wp-config.php' ),
		);
	}
}
