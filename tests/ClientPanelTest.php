<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\ApiError;
use Rewloy\WooCommerce\ConnectionError;

/** The calls the "Rewloy" screens make (0.3.0): what goes out, and that a repeated till press keeps its key. */
final class ClientPanelTest extends TestCase {

	private const PRESS = '6f1c2e8a-3b4d-4c5e-8f60-718293a4b5c6';

	/** @return array<string,string> */
	private function headers( int $i ): array {
		return $this->requests[ $i ]['args']['headers'];
	}

	public function test_reads_go_to_their_endpoints_with_the_card_number_normalised(): void {
		$this->script( $this->passAnswer(), $this->tillAnswer(), $this->answer( 200, array( 'data' => array(), 'meta' => array( 'page' => 1, 'pageSize' => 20, 'total' => 0 ) ) ), $this->answer( 200, array( 'data' => array( 'kpis' => array() ) ) ), $this->answer( 200, array( 'data' => array( 'id' => self::PROGRAM ) ) ) );
		$c = $this->client();
		$c->get_pass( 'abcd efgh jklm' );
		$c->get_pass_till( self::SERIAL, strtoupper( self::BRANCH ) );
		$c->list_activity( self::PROGRAM, 500 );
		$c->analytics( self::PROGRAM, 12 );
		$c->get_program( self::PROGRAM );
		$this->assertSame( 'https://app.rewloy.com/v1/passes/ABCD-EFGH-JKLM', $this->requests[0]['url'] );
		$this->assertSame( 'https://app.rewloy.com/v1/passes/ABCD-EFGH-JKLM/till?locationId=' . self::BRANCH, $this->requests[1]['url'] );
		$this->assertSame( 'https://app.rewloy.com/v1/activity?programId=' . self::PROGRAM . '&limit=100', $this->requests[2]['url'] );
		$this->assertSame( 'https://app.rewloy.com/v1/analytics?programId=' . self::PROGRAM . '&days=30', $this->requests[3]['url'] );
		$this->assertSame( 'https://app.rewloy.com/v1/programs/' . self::PROGRAM, $this->requests[4]['url'] );
		foreach ( $this->requests as $r ) {
			$this->assertSame( 'GET', $r['args']['method'] );
			$this->assertSame( 'Bearer ' . self::KEY, $r['args']['headers']['Authorization'] );
		}
	}

	public function test_a_card_number_that_is_not_one_never_goes_into_a_path(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->client()->get_pass( '../shops' );
	}

	public function test_a_sale_sends_the_total_the_branch_and_the_receipt_number_and_the_press_key_as_the_idempotency_key(): void {
		$this->script( $this->answer( 200, array( 'data' => array( 'type' => 'stamp', 'applied' => 'stamps', 'credited' => 1, 'balance' => 3, 'duplicate' => false, 'rewardReady' => false, 'rewardsReady' => 0 ) ) ) );
		$this->client()->record_sale( self::SERIAL, self::BRANCH, 12550, 'FIS-1042', self::PRESS );
		$this->assertSame( 'https://app.rewloy.com/v1/passes/ABCD-EFGH-JKLM/sale', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( self::PRESS, $this->headers( 0 )['Idempotency-Key'], 'the press key, never the receipt number' );
		$this->assertSame( array( 'locationId' => self::BRANCH, 'amountMinor' => 12550, 'reference' => 'FIS-1042' ), json_decode( $this->requests[0]['args']['body'], true ) );
	}

	public function test_a_sale_without_a_receipt_number_sends_none(): void {
		$this->script( $this->answer( 200, array( 'data' => array( 'applied' => 'none' ) ) ) );
		$this->client()->record_sale( self::SERIAL, self::BRANCH, 0, '', self::PRESS );
		$this->assertArrayNotHasKey( 'reference', json_decode( $this->requests[0]['args']['body'], true ) );
	}

	public function test_a_press_with_no_clear_answer_is_sent_again_with_the_same_key_and_the_same_body(): void {
		$this->script( new \WP_Error( 'http_request_failed', 'timed out' ), $this->answer( 502 ), $this->answer( 200, array( 'data' => array( 'applied' => 'stamps', 'credited' => 1, 'duplicate' => true ) ) ) );
		$this->client()->record_sale( self::SERIAL, self::BRANCH, 1000, '', self::PRESS );
		$this->assertCount( 3, $this->requests );
		foreach ( array( 1, 2 ) as $i ) {
			$this->assertSame( self::PRESS, $this->headers( $i )['Idempotency-Key'] );
			$this->assertSame( $this->requests[0]['args']['body'], $this->requests[ $i ]['args']['body'] );
		}
	}

	public function test_an_action_carries_its_own_body_and_the_press_key(): void {
		$this->script( $this->answer( 200, array( 'data' => array( 'balance' => 0, 'duplicate' => false ) ) ) );
		$this->client()->pass_action( self::SERIAL, array( 'action' => 'redeem-stamps', 'locationId' => self::BRANCH ), self::PRESS );
		$this->assertSame( 'https://app.rewloy.com/v1/passes/ABCD-EFGH-JKLM/actions', $this->requests[0]['url'] );
		$this->assertSame( self::PRESS, $this->headers( 0 )['Idempotency-Key'] );
		$this->assertSame( 'redeem-stamps', json_decode( $this->requests[0]['args']['body'], true )['action'] );
	}

	public function test_a_till_write_without_a_usable_key_is_not_sent(): void {
		$this->expectException( \InvalidArgumentException::class );
		try {
			$this->client()->record_sale( self::SERIAL, self::BRANCH, 1000, '', 'short' );
		} finally {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function test_a_refusal_keeps_its_code_and_an_answer_that_never_came_is_a_connection_error(): void {
		$this->script( $this->failure( 422, 'IDEMPOTENCY_KEY_REUSED' ) );
		try {
			$this->client()->record_sale( self::SERIAL, self::BRANCH, 1000, '', self::PRESS );
			$this->fail( 'expected ApiError' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'IDEMPOTENCY_KEY_REUSED', $e->api_code );
			$this->assertFalse( $e->outcome_unknown() );
		}
		$this->script( new \WP_Error( 'x', 'down' ), new \WP_Error( 'x', 'down' ), new \WP_Error( 'x', 'down' ) );
		try {
			$this->client()->record_sale( self::SERIAL, self::BRANCH, 1000, '', self::PRESS );
			$this->fail( 'expected ConnectionError' );
		} catch ( ConnectionError $e ) {
			$this->assertTrue( $e->outcome_unknown() );
		}
	}
}
