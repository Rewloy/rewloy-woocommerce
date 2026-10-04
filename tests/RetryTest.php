<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\WooCommerce\Retry;

final class RetryTest extends TestCase {

	public function test_only_safe_methods_are_repeatable(): void {
		foreach ( array( 'GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'get' ) as $m ) {
			$this->assertTrue( Retry::safe_method( $m ), $m );
		}
		$this->assertFalse( Retry::safe_method( 'POST' ) );
	}

	public function test_backoff_grows_and_stays_in_bounds(): void {
		$this->assertSame( 0.25, Retry::backoff( 0, 0.0 ) );
		$this->assertSame( 0.5, Retry::backoff( 0, 1.0 ) );
		$this->assertSame( 1.0, Retry::backoff( 1, 1.0 ) );
		$this->assertSame( 4.0, Retry::backoff( 10, 1.0 ) );
		$this->assertSame( 2.0, Retry::backoff( 10, 0.0 ) );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertLessThanOrEqual( Retry::MAX, Retry::backoff( $i ) );
		}
	}

	public function test_retry_after(): void {
		$this->assertSame( 3.0, Retry::parse_retry_after( '3' ) );
		$this->assertSame( 0.0, Retry::parse_retry_after( 'Wed, 21 Oct 2015 07:28:00 GMT', 2_000_000_000 ) );
		$this->assertSame( 10.0, Retry::parse_retry_after( 'Wed, 21 Oct 2015 07:28:10 GMT', strtotime( 'Wed, 21 Oct 2015 07:28:00 GMT' ) ) );
		$this->assertNull( Retry::parse_retry_after( '' ) );
		$this->assertNull( Retry::parse_retry_after( 'soon' ) );
	}

	public function test_which_statuses_are_retried(): void {
		foreach ( array( 429, 502, 503, 504, 520, 524 ) as $s ) {
			$this->assertTrue( Retry::retryable_status( $s, '' ), (string) $s );
		}
		$this->assertTrue( Retry::retryable_status( 409, 'IDEMPOTENCY_IN_PROGRESS' ) );
		foreach ( array( 400, 401, 403, 404, 409, 422, 500 ) as $s ) {
			$this->assertFalse( Retry::retryable_status( $s, 'X' ), (string) $s );
		}
	}
}
