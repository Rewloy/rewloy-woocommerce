<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\ApiError;
use Rewloy\WooCommerce\ConnectionError;
use Rewloy\WooCommerce\Retry;

/** The checkout codes' calls (0.4.0): paths, bodies, retries, errors. */
final class ClientCheckoutTest extends TestCase {

	private function body( int $i = 0 ): array {
		return json_decode( (string) $this->requests[ $i ]['args']['body'], true );
	}

	public function test_quote_posts_the_code_and_the_currency_and_returns_the_data(): void {
		$this->script( $this->answer( 200, array( 'data' => array( 'kind' => 'balance', 'maxMinor' => 4000 ) ) ) );
		$q = $this->client()->quote_code( self::LINK, 'RWXV5CRHBE', 'try' );
		$this->assertSame( 4000, $q['maxMinor'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'https://app.rewloy.com/v1/shops/' . self::LINK . '/checkout-codes/quote', $this->requests[0]['url'] );
		$this->assertSame( array( 'code' => 'RWXV5CRHBE', 'currency' => 'TRY' ), $this->body() );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $this->requests[0]['args']['headers'], 'the step is keyed on its own natural key' );
	}

	public function test_quote_sends_the_shopper_only_when_given(): void {
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$this->client()->quote_code( self::LINK, 'RWXV5CRHBE', 'TRY', 'abc123' );
		$this->assertSame( 'abc123', $this->body()['shopper'] );
	}

	public function test_hold_capture_release_refund_and_settings_go_where_the_api_says(): void {
		$this->script(
			$this->answer( 201, array( 'data' => array( 'id' => 'r1' ) ) ),
			$this->answer( 200, array( 'data' => array() ) ),
			$this->answer( 200, array( 'data' => array() ) ),
			$this->answer( 200, array( 'data' => array( 'redemptions' => array(), 'unearned' => null ) ) ),
			$this->answer( 200, array( 'data' => array() ) ),
			$this->answer( 200, array( 'data' => array( 'id' => self::LINK ) ) )
		);
		$c = $this->client();
		$c->hold_code( self::LINK, '1042', 'RWXV5CRHBE', 'TRY', 4000 );
		$c->capture_order( self::LINK, '1042' );
		$c->release_order( self::LINK, '1042', 'cancelled' );
		$c->refund_order( self::LINK, '1042' );
		$c->order_redemptions( self::LINK, '1042' );
		$c->update_shop_settings( self::LINK, array( 'holdDays' => 3 ) );
		$base = 'https://app.rewloy.com/v1/shops/' . self::LINK;
		$this->assertSame( $base . '/orders/1042/redemptions', $this->requests[0]['url'] );
		$this->assertSame( array( 'code' => 'RWXV5CRHBE', 'currency' => 'TRY', 'amountMinor' => 4000 ), $this->body( 0 ) );
		$this->assertSame( $base . '/orders/1042/capture', $this->requests[1]['url'] );
		$this->assertSame( '{}', $this->requests[1]['args']['body'], 'an empty body is an object' );
		$this->assertSame( array( 'reason' => 'cancelled' ), $this->body( 2 ) );
		$this->assertSame( $base . '/orders/1042/refund', $this->requests[3]['url'] );
		$this->assertSame( '{}', $this->requests[3]['args']['body'], 'a full refund names no amount' );
		$this->assertSame( 'GET', $this->requests[4]['args']['method'] );
		$this->assertSame( 'PATCH', $this->requests[5]['args']['method'] );
		$this->assertSame( $base . '/settings', $this->requests[5]['url'] );
	}

	public function test_an_unknown_release_reason_is_the_shops_own(): void {
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$this->client()->release_order( self::LINK, '7', 'whatever' );
		$this->assertSame( array( 'reason' => 'shop' ), $this->body() );
	}

	public function test_the_natural_key_steps_are_retried_once_on_a_gateway_error(): void {
		$this->script( $this->failure( 502, 'BAD_GATEWAY' ), $this->answer( 200, array( 'data' => array() ) ) );
		$this->client()->capture_order( self::LINK, '7' );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( Retry::MAX_RETRIES, Retry::retries_for( 'POST', '', true ) );
		$this->assertSame( 0, Retry::retries_for( 'POST', '' ), 'any other POST is still sent once' );
	}

	public function test_a_refusal_is_not_retried_and_carries_its_details(): void {
		$this->script(
			$this->answer( 409, array( 'error' => array( 'code' => 'HOLD_UNBACKED', 'message' => 'x', 'requestId' => 'req-9', 'details' => array( 'redemptions' => array( array( 'id' => 'r' ) ) ) ) ) )
		);
		try {
			$this->client()->capture_order( self::LINK, '7' );
			$this->fail( 'expected a refusal' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'HOLD_UNBACKED', $e->api_code );
			$this->assertSame( array( array( 'id' => 'r' ) ), $e->details['redemptions'] );
		}
		$this->assertCount( 1, $this->requests );
	}

	public function test_no_answer_after_the_retry_is_a_connection_error(): void {
		$this->script( new \WP_Error( 'http', 'down' ), new \WP_Error( 'http', 'down' ) );
		$this->expectException( ConnectionError::class );
		$this->client()->hold_code( self::LINK, '7', 'RWXV5CRHBE', 'TRY', 1 );
	}

	public function test_an_order_number_rewloy_would_not_take_never_goes_into_a_path(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->client()->capture_order( self::LINK, '../me' );
	}

	public function test_with_timeout_is_a_copy_with_its_own_limit(): void {
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$c = $this->client();
		$c->with_timeout( 8.0 )->capture_order( self::LINK, '7' );
		$this->assertSame( 8.0, $this->requests[0]['args']['timeout'] );
		$this->assertSame( 10.0, $c->__debugInfo()['timeout'], 'the original keeps its own' );
	}

	public function test_the_code_is_never_in_an_exception_or_the_debug_view(): void {
		$this->script( $this->failure( 404, 'CODE_INVALID' ) );
		try {
			$this->client()->quote_code( self::LINK, 'RWXV5CRHBE', 'TRY' );
		} catch ( ApiError $e ) {
			$this->assertStringNotContainsString( 'XV5C', $e->getMessage() . print_r( $e->details, true ) );
		}
		$this->assertStringNotContainsString( 'XV5C', print_r( $this->client(), true ) );
	}
}
