<?php
/**
 * Schedule helper tests.
 *
 * @package MSM\GitHubThemeUpdater
 */

declare(strict_types=1);

namespace MSM\GitHubThemeUpdater\Tests;

use DateTimeImmutable;
use DateTimeZone;
use MSM\GitHubThemeUpdater\Auto_Updater;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MSM\GitHubThemeUpdater\Auto_Updater
 */
final class AutoUpdaterTest extends TestCase {

	/**
	 * @dataProvider timeProvider
	 */
	public function testNormalisesTheTimeOfDay( string $input, string $expected ): void {
		$this->assertSame( $expected, Auto_Updater::sanitize_time( $input ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function timeProvider(): array {
		return array(
			'padded'          => array( '03:00', '03:00' ),
			'unpadded hour'   => array( '3:05', '03:05' ),
			'with seconds'    => array( '23:59:30', '23:59' ),
			'surrounding ws'  => array( ' 12:30 ', '12:30' ),
			'hour too large'  => array( '24:00', Auto_Updater::DEFAULT_TIME ),
			'minute too big'  => array( '10:60', Auto_Updater::DEFAULT_TIME ),
			'garbage'         => array( 'three am', Auto_Updater::DEFAULT_TIME ),
			'empty'           => array( '', Auto_Updater::DEFAULT_TIME ),
			'injection shape' => array( "03:00\n<script>", Auto_Updater::DEFAULT_TIME ),
		);
	}

	private function local( int $timestamp, DateTimeZone $tz ): string {
		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz )->format( 'Y-m-d H:i' );
	}

	public function testNextRunIsLaterTodayWhenTheHourHasNotPassed(): void {
		$tz  = new DateTimeZone( 'Europe/Warsaw' );
		$now = ( new DateTimeImmutable( '2026-06-10 01:30:00', $tz ) )->getTimestamp();

		$this->assertSame( '2026-06-10 03:00', $this->local( Auto_Updater::next_run_timestamp( '03:00', $tz, $now ), $tz ) );
	}

	public function testNextRunIsTomorrowWhenTheHourHasPassed(): void {
		$tz  = new DateTimeZone( 'Europe/Warsaw' );
		$now = ( new DateTimeImmutable( '2026-06-10 03:00:00', $tz ) )->getTimestamp();

		$this->assertSame( '2026-06-11 03:00', $this->local( Auto_Updater::next_run_timestamp( '03:00', $tz, $now ), $tz ) );
	}

	public function testNextRunKeepsTheWallClockTimeAcrossADstChange(): void {
		$tz = new DateTimeZone( 'Europe/Warsaw' );

		// Clocks go forward at 02:00 on 2026-03-29, so that day is 23 hours long.
		$now  = ( new DateTimeImmutable( '2026-03-28 04:00:00', $tz ) )->getTimestamp();
		$next = Auto_Updater::next_run_timestamp( '03:00', $tz, $now );

		$this->assertSame( '2026-03-29 03:00', $this->local( $next, $tz ) );
		$this->assertSame( 22 * 3600, $next - $now );
	}

	public function testNextRunUsesTheGivenTimezone(): void {
		$warsaw = new DateTimeZone( 'Europe/Warsaw' );
		$tokyo  = new DateTimeZone( 'Asia/Tokyo' );
		$now    = ( new DateTimeImmutable( '2026-06-10 12:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$this->assertNotSame(
			Auto_Updater::next_run_timestamp( '03:00', $warsaw, $now ),
			Auto_Updater::next_run_timestamp( '03:00', $tokyo, $now )
		);
		$this->assertSame( '2026-06-11 03:00', $this->local( Auto_Updater::next_run_timestamp( '03:00', $tokyo, $now ), $tokyo ) );
	}
}
