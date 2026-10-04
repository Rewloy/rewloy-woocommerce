<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\ApiError;
use Rewloy\WooCommerce\Client;
use Rewloy\WooCommerce\ConnectionError;

final class ClientTest extends TestCase {

	public function test_a_get_carries_the_bearer_key_and_never_follows_redirects(): void {
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$this->client()->list_programs();
		$r = $this->requests[0];
		$this->assertSame( 'https://app.rewloy.com/v1/programs?status=active', $r['url'] );
		$this->assertSame( 'GET', $r['args']['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $r['args']['headers']['Authorization'] );
		$this->assertSame( 0, $r['args']['redirection'] );
		$this->assertStringStartsWith( 'rewloy-for-woocommerce/', $r['args']['headers']['User-Agent'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $r['args']['headers'] );
		$this->assertArrayNotHasKey( 'body', $r['args'] );
	}

	public function test_create_shop_posts_json_and_returns_the_link(): void {
		$this->script( $this->answer( 201, array( 'data' => array( 'id' => self::LINK, 'secret' => 'wc_x' ) ) ) );
		$shop = $this->client()->create_shop( array( 'platform' => 'woocommerce', 'programId' => self::PROGRAM, 'rule' => 'order' ) );
		$this->assertSame( self::LINK, $shop['id'] );
		$r = $this->requests[0];
		$this->assertSame( 'POST', $r['args']['method'] );
		$this->assertSame( 'https://app.rewloy.com/v1/shops', $r['url'] );
		$this->assertSame( 'application/json', $r['args']['headers']['Content-Type'] );
		$this->assertSame( array( 'platform' => 'woocommerce', 'programId' => self::PROGRAM, 'rule' => 'order' ), json_decode( $r['args']['body'], true ) );
	}

	public function test_issue_pass_sends_the_idempotency_key(): void {
		$this->script( $this->answer( 201, array( 'data' => array( 'serial' => 'ABCD-EFGH-JKLM', 'cardUrl' => 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=k' ) ) ) );
		$card = $this->client()->issue_pass( array( 'programId' => self::PROGRAM, 'email' => 'a@b.co', 'kvkkConsent' => true ), 'woo-0123456789-5' );
		$this->assertSame( 'ABCD-EFGH-JKLM', $card['serial'] );
		$this->assertSame( 'woo-0123456789-5', $this->requests[0]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( 'https://app.rewloy.com/v1/passes', $this->requests[0]['url'] );
	}

	public function test_api_errors_carry_the_stable_code_and_the_request_id(): void {
		$this->script( $this->failure( 403, 'PLAN_FEATURE_MISSING' ) );
		try {
			$this->client()->get_shop( self::LINK );
			$this->fail( 'expected an ApiError' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'PLAN_FEATURE_MISSING', $e->api_code );
			$this->assertSame( 403, $e->status );
			$this->assertSame( 'req-123', $e->request_id );
			$this->assertFalse( $e->outcome_unknown() );
		}
	}

	public function test_a_get_is_retried_on_a_gateway_error_then_succeeds(): void {
		$this->script( $this->failure( 503, 'INTERNAL' ), $this->failure( 502, 'INTERNAL' ), $this->answer( 200, array( 'data' => array( 'id' => self::LINK ) ) ) );
		$this->assertSame( self::LINK, $this->client()->get_shop( self::LINK )['id'] );
		$this->assertCount( 3, $this->requests );
	}

	public function test_a_get_gives_up_after_two_retries(): void {
		$this->script( $this->failure( 503, 'X' ), $this->failure( 503, 'X' ), $this->failure( 503, 'X' ) );
		$this->expectException( ApiError::class );
		try {
			$this->client()->get_shop( self::LINK );
		} finally {
			$this->assertCount( 3, $this->requests );
		}
	}

	public function test_a_get_is_retried_after_a_network_error(): void {
		$this->script( new \WP_Error( 'http_request_failed', 'cURL error 28' ), $this->answer( 200, array( 'data' => array() ) ) );
		$this->assertSame( array(), $this->client()->list_programs() );
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_post_is_never_retried_not_even_with_a_key(): void {
		foreach ( array( $this->failure( 503, 'INTERNAL' ), $this->failure( 429, 'RATE_LIMITED', array( 'retry-after' => '1' ) ), new \WP_Error( 'http_request_failed', 'timeout' ) ) as $failure ) {
			$this->requests = array();
			$this->script( $failure, $this->answer( 201, array( 'data' => array( 'serial' => 'A', 'cardUrl' => 'u' ) ) ) );
			try {
				$this->client()->issue_pass( array( 'programId' => self::PROGRAM ), 'woo-0123456789-5' );
				$this->fail( 'expected an error' );
			} catch ( \Rewloy\WooCommerce\RewloyException $e ) {
				$this->assertCount( 1, $this->requests, 'issuePass was sent once' );
			}
		}
	}

	public function test_create_shop_is_never_retried(): void {
		$this->script( $this->failure( 502, 'INTERNAL' ), $this->answer( 201, array( 'data' => array( 'id' => self::LINK ) ) ) );
		try {
			$this->client()->create_shop( array( 'platform' => 'woocommerce' ) );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertCount( 1, $this->requests );
		}
	}

	public function test_patch_and_delete_are_retried(): void {
		$this->script( $this->failure( 503, 'X' ), $this->answer( 200, array( 'data' => array( 'enabled' => false ) ) ) );
		$this->assertFalse( $this->client()->set_shop_enabled( self::LINK, false )['enabled'] );
		$this->assertSame( 'PATCH', $this->requests[1]['args']['method'] );
		$this->assertSame( array( 'enabled' => false ), json_decode( $this->requests[1]['args']['body'], true ) );

		$this->requests = array();
		$this->script( new \WP_Error( 'x', 'y' ), $this->answer( 204 ) );
		$this->client()->delete_shop( self::LINK );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'DELETE', $this->requests[1]['args']['method'] );
	}

	public function test_retry_after_longer_than_the_limit_is_not_waited_for(): void {
		$this->script( $this->failure( 429, 'RATE_LIMITED', array( 'retry-after' => '120' ) ) );
		try {
			$this->client()->get_shop( self::LINK );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertSame( 120.0, $e->retry_after );
			$this->assertCount( 1, $this->requests );
		}
	}

	public function test_a_short_retry_after_is_waited_for(): void {
		$waited = array();
		$client = new Client( self::KEY, 'https://app.rewloy.com', $this->transport(), static function ( float $s ) use ( &$waited ): void {
			$waited[] = $s;
		} );
		$this->script( $this->failure( 429, 'RATE_LIMITED', array( 'retry-after' => '2' ) ), $this->answer( 200, array( 'data' => array( 'id' => self::LINK ) ) ) );
		$client->get_shop( self::LINK );
		$this->assertSame( array( 2.0 ), $waited );
	}

	public function test_a_2xx_that_is_not_json_is_a_connection_error_with_unknown_outcome(): void {
		$this->script( array( 'response' => array( 'code' => 201 ), 'body' => '<html>', 'headers' => array() ) );
		try {
			$this->client()->issue_pass( array(), 'woo-0123456789-5' );
			$this->fail( 'expected an error' );
		} catch ( ConnectionError $e ) {
			$this->assertTrue( $e->outcome_unknown() );
		}
	}

	public function test_a_2xx_without_the_card_is_an_unknown_outcome(): void {
		$this->script( $this->answer( 201, array( 'data' => array( 'serial' => 'A' ) ) ) );
		try {
			$this->client()->issue_pass( array(), 'woo-0123456789-5' );
			$this->fail( 'expected an error' );
		} catch ( ConnectionError $e ) {
			$this->assertTrue( $e->outcome_unknown() );
		}
	}

	public function test_server_errors_leave_the_outcome_open(): void {
		$this->script( $this->failure( 500, 'INTERNAL' ) );
		try {
			$this->client()->issue_pass( array(), 'woo-0123456789-5' );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertTrue( $e->outcome_unknown() );
		}
	}

	public function test_the_key_is_in_no_message_and_not_in_debug_output(): void {
		$client = $this->client();
		$this->assertStringNotContainsString( 'SECRET', print_r( $client, true ) );
		$this->assertStringNotContainsString( 'SECRET', var_export( $client->__debugInfo(), true ) );
		$this->script( new \WP_Error( 'http_request_failed', 'boom' ) );
		try {
			$client->issue_pass( array(), 'woo-0123456789-5' );
		} catch ( \Rewloy\WooCommerce\RewloyException $e ) {
			$this->assertStringNotContainsString( 'SECRET', $e->getMessage() );
			$this->assertStringNotContainsString( 'SECRET', (string) $e );
		}
	}

	public function test_ids_in_paths_must_be_uuids(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->client()->get_shop( '../passes' );
	}

	public function test_only_an_api_key_and_an_https_origin_are_accepted(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Client( 'rws_notakey', 'https://app.rewloy.com' );
	}

	public function test_http_origin_is_refused_except_for_localhost(): void {
		new Client( self::KEY, 'http://localhost:4000' );
		$this->expectException( \InvalidArgumentException::class );
		new Client( self::KEY, 'http://app.rewloy.com' );
	}

	public function test_orders_list_asks_for_a_limit(): void {
		$this->script( $this->answer( 200, array( 'data' => array( array( 'orderId' => '9', 'outcome' => 'credited', 'at' => '2026-10-01T10:00:00Z' ) ) ) ) );
		$rows = $this->client()->list_shop_orders( self::LINK, 500 );
		$this->assertSame( '9', $rows[0]['orderId'] );
		$this->assertStringEndsWith( '/orders?limit=100', $this->requests[0]['url'] );
	}
}
