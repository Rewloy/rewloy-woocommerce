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
		$this->script( $this->failure( 503, 'INTERNAL' ), $this->answer( 200, array( 'data' => array( 'id' => self::LINK ) ) ) );
		$this->assertSame( self::LINK, $this->client()->get_shop( self::LINK )['id'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_get_gives_up_after_its_one_retry(): void {
		$this->script( $this->failure( 503, 'X' ), $this->failure( 502, 'X' ), $this->answer( 200, array( 'data' => array() ) ) );
		try {
			$this->client()->get_shop( self::LINK );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertSame( 502, $e->status );
			$this->assertCount( 2, $this->requests, 'an admin screen waits for these calls: one retry, not more' );
		}
	}

	public function test_requests_cap_the_size_of_what_they_read(): void {
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$this->client()->list_programs();
		$this->assertSame( 1_048_576, $this->requests[0]['args']['limit_response_size'] );
		$this->assertSame( 10.0, $this->requests[0]['args']['timeout'] );
	}

	public function test_a_card_serial_the_api_sends_must_be_plain(): void {
		foreach ( array( '<script>', 'AB', 'ABCD EFGH', '' ) as $serial ) {
			$this->script( $this->answer( 201, array( 'data' => array( 'serial' => $serial, 'cardUrl' => 'https://rewloy.com/p/x' ) ) ) );
			try {
				$this->client()->issue_pass( array(), 'woo-0123456789-5' );
				$this->fail( 'expected an error for ' . $serial );
			} catch ( ConnectionError $e ) {
				$this->assertTrue( $e->outcome_unknown(), 'accepted, so possibly issued' );
			}
		}
	}

	public function test_a_get_is_retried_after_a_network_error(): void {
		$this->script( new \WP_Error( 'http_request_failed', 'cURL error 28' ), $this->answer( 200, array( 'data' => array() ) ) );
		$this->assertSame( array(), $this->client()->list_programs() );
		$this->assertCount( 2, $this->requests );
	}

	private function card( string $serial = 'ABCD-EFGH-JKLM' ): array {
		return $this->answer( 201, array( 'data' => array( 'serial' => $serial, 'cardUrl' => 'https://rewloy.com/p/' . $serial . '?k=k' ) ) );
	}

	/** issuePass carries an Idempotency-Key and Rewloy replays the first answer, so repeating it is safe: it is retried. */
	public function test_issue_pass_is_retried_after_a_failure_that_another_attempt_can_get_past(): void {
		foreach ( array(
			'a gateway error'   => $this->failure( 503, 'INTERNAL' ),
			'a 502'             => $this->failure( 502, 'INTERNAL' ),
			'rate limited'      => $this->failure( 429, 'RATE_LIMITED', array( 'retry-after' => '1' ) ),
			'still running'     => $this->failure( 409, 'IDEMPOTENCY_IN_PROGRESS' ),
			'a network error'   => new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
		) as $name => $failure ) {
			$this->requests = array();
			$this->script( $failure, $this->card() );
			$card = $this->client()->issue_pass( array( 'programId' => self::PROGRAM, 'email' => 'a@b.co' ), 'woo-0123456789-5' );
			$this->assertSame( 'ABCD-EFGH-JKLM', $card['serial'], $name );
			$this->assertCount( 2, $this->requests, $name );
			$this->assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'], $name . ': the same body' );
			$this->assertSame( 'woo-0123456789-5', $this->requests[1]['args']['headers']['Idempotency-Key'], $name . ': the same key' );
		}
	}

	public function test_issue_pass_gives_up_after_two_retries(): void {
		$this->script( $this->failure( 503, 'X' ), $this->failure( 502, 'X' ), $this->failure( 504, 'X' ), $this->card() );
		try {
			$this->client()->issue_pass( array( 'programId' => self::PROGRAM ), 'woo-0123456789-5' );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertSame( 504, $e->status );
			$this->assertCount( 3, $this->requests, 'one attempt and two retries: it runs in a background action, not under an admin page' );
			$this->assertTrue( $e->outcome_unknown() );
		}
	}

	public function test_issue_pass_does_not_retry_what_another_attempt_cannot_change(): void {
		foreach ( array(
			'a refusal'        => $this->failure( 422, 'EMAIL_BLOCKED' ),
			'the key reused'   => $this->failure( 422, 'IDEMPOTENCY_KEY_REUSED' ),
			'not authorised'   => $this->failure( 403, 'FORBIDDEN' ),
			'a server error'   => $this->failure( 500, 'INTERNAL' ),
			'a long wait'      => $this->failure( 429, 'RATE_LIMITED', array( 'retry-after' => '120' ) ),
		) as $name => $failure ) {
			$this->requests = array();
			$this->script( $failure, $this->card() );
			try {
				$this->client()->issue_pass( array( 'programId' => self::PROGRAM ), 'woo-0123456789-5' );
				$this->fail( 'expected an error: ' . $name );
			} catch ( \Rewloy\WooCommerce\RewloyException $e ) {
				$this->assertCount( 1, $this->requests, $name );
			}
		}
	}

	public function test_a_still_running_first_request_is_an_unclear_outcome_not_a_refusal(): void {
		$this->script( $this->failure( 409, 'IDEMPOTENCY_IN_PROGRESS' ), $this->failure( 409, 'IDEMPOTENCY_IN_PROGRESS' ), $this->failure( 409, 'IDEMPOTENCY_IN_PROGRESS' ) );
		try {
			$this->client()->issue_pass( array( 'programId' => self::PROGRAM ), 'woo-0123456789-5' );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertSame( 409, $e->status );
			$this->assertTrue( $e->outcome_unknown(), 'the first request may yet open a card' );
		}
	}

	public function test_a_post_without_a_key_is_never_retried(): void {
		foreach ( array( $this->failure( 503, 'INTERNAL' ), new \WP_Error( 'http_request_failed', 'timeout' ) ) as $failure ) {
			$this->requests = array();
			$this->script( $failure, $this->answer( 201, array( 'data' => array( 'id' => self::LINK ) ) ) );
			try {
				$this->client()->create_shop( array( 'platform' => 'woocommerce' ) );
				$this->fail( 'expected an error' );
			} catch ( \Rewloy\WooCommerce\RewloyException $e ) {
				$this->assertCount( 1, $this->requests, 'creating a link is sent once' );
			}
		}
	}

	public function test_a_replay_in_the_middle_of_retries_is_reported(): void {
		$this->script( new \WP_Error( 'x', 'y' ), $this->answer( 201, array( 'data' => array( 'serial' => 'ABCD-EFGH-JKLM', 'cardUrl' => 'https://rewloy.com/p/x?k=k' ) ), array( 'idempotent-replayed' => 'true' ) ) );
		$card = $this->client()->issue_pass( array( 'programId' => self::PROGRAM ), 'woo-0123456789-5' );
		$this->assertTrue( $card['replayed'] );
		$this->assertCount( 2, $this->requests );
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
		$this->script( new \WP_Error( 'http_request_failed', 'boom' ), new \WP_Error( 'http_request_failed', 'boom' ), new \WP_Error( 'http_request_failed', 'boom' ) );
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

	public function test_me_asks_who_the_key_is(): void {
		$this->script( $this->meAnswer() );
		$me = $this->client()->me();
		$this->assertSame( 'https://app.rewloy.com/v1/me', $this->requests[0]['url'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'key', $me['kind'] );
		$this->assertContains( 'shops.manage', $me['permissions'] );
	}

	public function test_connect_shop_sends_the_code_and_the_name_and_no_key(): void {
		$this->script( $this->connectAnswer() );
		$answer = Client::anonymous( 'https://app.rewloy.com', $this->transport(), static function ( float $s ): void {} )->connect_shop( self::CODE, 'Örnek Mağaza' );
		$r      = $this->requests[0];
		$this->assertSame( 'POST', $r['args']['method'] );
		$this->assertSame( 'https://app.rewloy.com/v1/shops/connect', $r['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $r['args']['headers'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $r['args']['headers'] );
		$this->assertSame( 'application/json', $r['args']['headers']['Content-Type'] );
		$this->assertSame( array( 'token' => self::CODE, 'shopName' => 'Örnek Mağaza' ), json_decode( $r['args']['body'], true ) );
		$this->assertSame( self::LINK, $answer['shop']['id'] );
		$this->assertSame( self::PLUGIN_KEY, $answer['apiKey']['token'] );
		$this->assertStringStartsWith( 'wc_', $answer['secret'] );
	}

	public function test_connect_shop_omits_an_empty_name_and_is_not_retried_even_though_the_code_is_the_credential(): void {
		$this->script( $this->failure( 503, 'INTERNAL' ), $this->connectAnswer() );
		try {
			Client::anonymous( 'https://app.rewloy.com', $this->transport(), static function ( float $s ): void {} )->connect_shop( self::CODE );
			$this->fail( 'expected an error' );
		} catch ( ApiError $e ) {
			$this->assertCount( 1, $this->requests, 'the code is spent by the first answer, which is shown once' );
			$this->assertSame( array( 'token' => self::CODE ), json_decode( $this->requests[0]['args']['body'], true ) );
		}
	}

	public function test_a_client_without_a_key_refuses_every_call_that_needs_one(): void {
		$client = Client::anonymous( 'https://app.rewloy.com', $this->transport() );
		foreach ( array(
			fn() => $client->me(),
			fn() => $client->list_programs(),
			fn() => $client->get_shop( self::LINK ),
			fn() => $client->create_shop( array() ),
			fn() => $client->delete_shop( self::LINK ),
			fn() => $client->issue_pass( array(), 'woo-0123456789-5' ),
		) as $call ) {
			try {
				$call();
				$this->fail( 'expected a LogicException' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'no API key', $e->getMessage() );
			}
		}
		$this->assertSame( array(), $this->requests, 'nothing went out' );
	}

	public function test_an_anonymous_client_is_made_only_through_the_named_constructor(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Client( self::KEY, 'https://app.rewloy.com', null, null, 10.0, true );
	}

	public function test_an_anonymous_client_still_needs_an_https_origin(): void {
		$this->expectException( \InvalidArgumentException::class );
		Client::anonymous( 'http://app.rewloy.com' );
	}

	public function test_the_code_is_in_no_message_and_not_in_debug_output(): void {
		$client = Client::anonymous( 'https://app.rewloy.com', $this->transport(), static function ( float $s ): void {} );
		$this->script( new \WP_Error( 'http_request_failed', 'boom' ) );
		try {
			$client->connect_shop( self::CODE );
		} catch ( \Rewloy\WooCommerce\RewloyException $e ) {
			$this->assertStringNotContainsString( 'ABCDEFGH', $e->getMessage() );
			$this->assertStringNotContainsString( 'ABCDEFGH', (string) $e );
		}
		$this->assertStringNotContainsString( 'ABCDEFGH', print_r( $client->__debugInfo(), true ) );
	}

	public function test_issue_pass_reports_a_replay_and_the_orders_result(): void {
		$card = array( 'serial' => 'ABCD-EFGH-JKLM', 'cardUrl' => 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=k' );
		$this->script(
			$this->answer( 201, array( 'data' => $card + array( 'order' => array( 'shopId' => self::LINK, 'orderId' => '5', 'result' => 'resend', 'outcome' => null ) ) ), array( 'idempotent-replayed' => 'true' ) ),
			$this->answer( 201, array( 'data' => $card ) ),
			$this->answer( 201, array( 'data' => $card + array( 'order' => array( 'result' => 'something-new' ) ) ) ),
			$this->answer( 201, array( 'data' => $card + array( 'order' => array( 'result' => 'waiting' ) ) ), array( 'idempotent-replayed' => 'false' ) )
		);
		$client = $this->client();
		$a      = $client->issue_pass( array(), 'woo-0123456789-5' );
		$this->assertTrue( $a['replayed'] );
		$this->assertSame( 'resend', $a['order_result'] );
		$b = $client->issue_pass( array(), 'woo-0123456789-5' );
		$this->assertFalse( $b['replayed'] );
		$this->assertSame( '', $b['order_result'], 'no order named, no result' );
		$this->assertSame( '', $client->issue_pass( array(), 'woo-0123456789-5' )['order_result'], 'a result this plugin does not know is not acted on' );
		$d = $client->issue_pass( array(), 'woo-0123456789-5' );
		$this->assertFalse( $d['replayed'] );
		$this->assertSame( 'waiting', $d['order_result'] );
	}
}
