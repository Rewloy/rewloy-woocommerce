<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\ApiError;
use Rewloy\WooCommerce\ConnectionError;
use Rewloy\WooCommerce\Messages;

final class MessagesTest extends TestCase {

	public function test_the_five_outcomes_are_the_delivery_results_too_and_four_more_are_added(): void {
		$this->assertSame( Messages::OUTCOMES, array_slice( Messages::DELIVERY_RESULTS, 0, 5 ) );
		foreach ( Messages::OUTCOMES as $o ) {
			$this->assertSame( Messages::outcome_label( $o ), Messages::delivery_label( $o ) );
		}
		$this->assertSame( 'Repeat of a recorded order', Messages::delivery_label( 'duplicate' ) );
		$this->assertSame( 'Unpaid order (not recorded)', Messages::delivery_label( 'ignored' ) );
		$this->assertSame( 'No order number', Messages::delivery_label( 'no_id' ) );
		$this->assertSame( 'Could not be read', Messages::delivery_label( 'bad_body' ) );
		$this->assertSame( 'something_new', Messages::delivery_label( 'something_new' ), 'a result Rewloy adds later is shown as it is, not hidden' );
	}

	public function test_the_refusal_reason_is_named(): void {
		$this->assertSame( 'Signature did not match', Messages::refusal_label( 'bad_signature' ) );
		$this->assertSame( 'other', Messages::refusal_label( 'other' ) );
	}

	public function test_the_codes_of_the_connect_flow_and_of_an_order_s_card_read_in_our_words(): void {
		foreach ( array(
			'CONNECT_TOKEN_INVALID'  => '15 minutes',
			'SHOP_PROGRAM_MISMATCH'  => 'another card',
			'IDEMPOTENCY_KEY_REUSED' => 'different request',
			'TEST_LIMIT_REACHED'     => 'test environment is full',
			'FORBIDDEN'              => 'E-ticaret role',
		) as $code => $text ) {
			$message = Messages::for_error( new ApiError( 'Türkçe API iletisi', 409, $code, 'req-1' ) );
			$this->assertStringContainsString( $text, $message, $code );
			$this->assertStringNotContainsString( 'Türkçe API iletisi', $message, $code );
		}
	}

	public function test_a_lost_connection_is_not_an_api_code(): void {
		$this->assertStringContainsString( 'could not be reached', Messages::for_error( new ConnectionError( 'boom' ) ) );
	}
}
