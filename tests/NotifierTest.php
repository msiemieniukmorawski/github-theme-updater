<?php
/**
 * Recipient parsing tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Notifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Notifier
 */
final class NotifierTest extends TestCase {

	public function testSplitsOnAnySeparator(): void {
		list( $valid, $rejected ) = Notifier::parse_recipients( "a@example.com, b@example.com;c@example.com\nd@example.com e@example.com" );

		$this->assertSame(
			array( 'a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com' ),
			$valid
		);
		$this->assertSame( array(), $rejected );
	}

	public function testReportsInvalidEntriesInsteadOfDroppingThemSilently(): void {
		list( $valid, $rejected ) = Notifier::parse_recipients( 'ok@example.com, not-an-address, also@example.com, @nope' );

		$this->assertSame( array( 'ok@example.com', 'also@example.com' ), $valid );
		$this->assertSame( array( 'not-an-address', '@nope' ), $rejected );
	}

	public function testDeduplicatesCaseInsensitively(): void {
		list( $valid ) = Notifier::parse_recipients( 'Me@Example.com, me@example.com, ME@EXAMPLE.COM' );

		$this->assertSame( array( 'Me@Example.com' ), $valid );
	}

	public function testEmptyInputYieldsNothing(): void {
		$this->assertSame( array( array(), array() ), Notifier::parse_recipients( '' ) );
		$this->assertSame( array( array(), array() ), Notifier::parse_recipients( " , ;\n" ) );
	}
}
