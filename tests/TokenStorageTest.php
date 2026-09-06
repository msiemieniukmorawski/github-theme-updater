<?php
/**
 * Token storage tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Token_Storage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Token_Storage
 */
final class TokenStorageTest extends TestCase {

	private const TOKEN = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	public function testRoundTrips(): void {
		$stored = Token_Storage::encrypt( self::TOKEN );

		$this->assertSame( self::TOKEN, Token_Storage::decrypt( $stored ) );
	}

	public function testDoesNotStoreTheTokenInClearText(): void {
		$stored = Token_Storage::encrypt( self::TOKEN );

		$this->assertStringNotContainsString( self::TOKEN, $stored );
		$this->assertNotSame( self::TOKEN, $stored );
	}

	public function testUsesAFreshInitialisationVectorEachTime(): void {
		$this->assertNotSame(
			Token_Storage::encrypt( self::TOKEN ),
			Token_Storage::encrypt( self::TOKEN ),
			'Identical ciphertext would leak that two sites share a token.'
		);
	}

	public function testHandlesAnEmptyToken(): void {
		$this->assertSame( '', Token_Storage::encrypt( '' ) );
		$this->assertSame( '', Token_Storage::decrypt( '' ) );
	}

	public function testReadsPlainValuesWrittenByVersionOne(): void {
		// 1.x stored the token as-is; those installs must keep working.
		$this->assertSame( 'ghp_legacy_value', Token_Storage::decrypt( 'ghp_legacy_value' ) );
	}

	public function testReturnsNothingForCorruptedPayloads(): void {
		$this->assertSame( '', Token_Storage::decrypt( 'gthu:enc:not-base64-!!' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a deliberately truncated payload.
		$this->assertSame( '', Token_Storage::decrypt( 'gthu:enc:' . base64_encode( 'short' ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provideMaskedTokens(): array {
		return array(
			'long token'  => array( self::TOKEN, 'ghp_********6789' ),
			'exactly 8'   => array( '12345678', '1234********5678' ),
			'short token' => array( 'abc', '***' ),
			'empty'       => array( '', '' ),
		);
	}

	/**
	 * @dataProvider provideMaskedTokens
	 *
	 * @param string $token    Clear text token.
	 * @param string $expected Masked preview.
	 */
	public function testMasksTokensForDisplay( string $token, string $expected ): void {
		$this->assertSame( $expected, Token_Storage::mask( $token ) );
	}

	public function testMaskNeverRevealsTheMiddleOfTheToken(): void {
		$masked = Token_Storage::mask( self::TOKEN );

		$this->assertStringNotContainsString( 'MNOPQRSTUVWXYZ', $masked );
	}
}
