<?php
/**
 * Webhook verification tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use MSM\GitHubThemeUpdater\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Webhook
 */
final class WebhookTest extends TestCase {

	private const SECRET = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

	/**
	 * A release payload, trimmed to the fields the plugin reads.
	 *
	 * @param array<string, mixed> $release    Release fields to replace.
	 * @param string               $action     Event action.
	 * @param string               $repository Repository full name.
	 * @return array<string, mixed>
	 */
	private function payload( array $release = array(), string $action = 'published', string $repository = 'Acme/Theme' ): array {
		return array(
			'action'     => $action,
			'release'    => array_merge(
				array(
					'tag_name'   => 'v2.0.0',
					'draft'      => false,
					'prerelease' => false,
				),
				$release
			),
			'repository' => array( 'full_name' => $repository ),
		);
	}

	private function sign( string $body, string $secret = self::SECRET ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	public function testAcceptsGithubsSignature(): void {
		$body = '{"action":"published"}';

		$this->assertTrue( Webhook::signature_matches( $body, $this->sign( $body ), self::SECRET ) );
	}

	public function testRejectsAWrongSecret(): void {
		$body = '{"action":"published"}';

		$this->assertFalse( Webhook::signature_matches( $body, $this->sign( $body, 'other' ), self::SECRET ) );
	}

	public function testRejectsATamperedBody(): void {
		$signature = $this->sign( '{"action":"published"}' );

		$this->assertFalse( Webhook::signature_matches( '{"action":"published","x":1}', $signature, self::SECRET ) );
	}

	public function testRejectsMissingOrMalformedSignatures(): void {
		$body = '{}';

		$this->assertFalse( Webhook::signature_matches( $body, '', self::SECRET ) );
		$this->assertFalse( Webhook::signature_matches( $body, hash_hmac( 'sha256', $body, self::SECRET ), self::SECRET ), 'Without the sha256= prefix.' );
		$this->assertFalse( Webhook::signature_matches( $body, 'sha1=' . sha1( $body ), self::SECRET ), 'The legacy SHA-1 header is not accepted.' );
		$this->assertFalse( Webhook::signature_matches( $body, 'sha256=', self::SECRET ) );
	}

	public function testRefusesToVerifyWithoutASecret(): void {
		$body = '{}';

		$this->assertFalse( Webhook::signature_matches( $body, 'sha256=' . hash_hmac( 'sha256', $body, '' ), '' ) );
	}

	public function testGeneratedSecretsAreLongRandomHex(): void {
		$one = Webhook::generate_secret();
		$two = Webhook::generate_secret();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $one );
		$this->assertNotSame( $one, $two );
	}

	public function testAcceptsAPublishedReleaseOfTheConfiguredRepository(): void {
		$verdict = Webhook::interpret( $this->payload(), 'acme/theme', false );

		$this->assertSame( 'accepted', $verdict['status'] );
		$this->assertSame( 'v2.0.0', $verdict['tag'] );
	}

	public function testIgnoresEveryActionButPublished(): void {
		foreach ( array( 'created', 'edited', 'released', 'prereleased', 'deleted', 'unpublished', '' ) as $action ) {
			$verdict = Webhook::interpret( $this->payload( array(), $action ), 'acme/theme', false );

			$this->assertSame( 'ignored', $verdict['status'], 'Action: ' . $action );
			$this->assertSame( 'action', $verdict['reason'] );
		}
	}

	public function testRejectsAnotherRepository(): void {
		$verdict = Webhook::interpret( $this->payload( array(), 'published', 'someone/else' ), 'acme/theme', false );

		$this->assertSame( 'rejected', $verdict['status'] );
		$this->assertSame( 'repository', $verdict['reason'] );
		$this->assertSame( '', $verdict['tag'] );
	}

	public function testRejectsAPayloadWithoutARepository(): void {
		$payload = $this->payload();
		unset( $payload['repository'] );

		$this->assertSame( 'rejected', Webhook::interpret( $payload, 'acme/theme', false )['status'] );
	}

	public function testIgnoresDrafts(): void {
		$verdict = Webhook::interpret( $this->payload( array( 'draft' => true ) ), 'acme/theme', false );

		$this->assertSame( 'ignored', $verdict['status'] );
		$this->assertSame( 'draft', $verdict['reason'] );
	}

	public function testPreReleasesFollowTheSetting(): void {
		$payload = $this->payload( array( 'prerelease' => true ) );

		$this->assertSame( 'ignored', Webhook::interpret( $payload, 'acme/theme', false )['status'] );
		$this->assertSame( 'accepted', Webhook::interpret( $payload, 'acme/theme', true )['status'] );
	}

	public function testStripsAnythingButTagCharactersFromTheTag(): void {
		$verdict = Webhook::interpret( $this->payload( array( 'tag_name' => "v2.0.0<script>alert(1)</script>\n" ) ), 'acme/theme', false );

		$this->assertSame( 'v2.0.0scriptalert1/script', $verdict['tag'] );
	}

	public function testIgnoresAReleaseWithoutATag(): void {
		$verdict = Webhook::interpret( $this->payload( array( 'tag_name' => '' ) ), 'acme/theme', false );

		$this->assertSame( 'ignored', $verdict['status'] );
		$this->assertSame( 'tag', $verdict['reason'] );
	}
}
