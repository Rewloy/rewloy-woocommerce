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

	public function test_a_card_is_never_called_a_stamp_card_unless_it_is_one(): void {
		$this->assertSame( 'Stamp card', Messages::type_label( 'stamp' ) );
		$this->assertSame( 'Points card', Messages::type_label( 'points' ) );
		$this->assertSame( 'VIP card', Messages::type_label( 'vip' ) );
		$this->assertSame( 'Cashback card', Messages::type_label( 'cashback' ) );
		$this->assertSame( 'The amount did not reach the rule\'s threshold; nothing was added.', Messages::outcome_why( 'below' ) );
		$this->assertSame( 'The amount did not reach the rule\'s threshold; nothing was added.', Messages::outcome_why( 'below', 'points' ) );
		$this->assertSame( 'The cashback on the order came to nothing; nothing was added.', Messages::outcome_why( 'below', 'cashback' ) );
		$this->assertSame( 'Added to the buyer\'s card.', Messages::outcome_why( 'credited', 'cashback' ) );
	}

	public function test_the_cashback_note_gives_the_rate_with_an_example_or_no_number(): void {
		\Brain\Monkey\Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d, ',', '.' ) );
		$this->assertSame(
			'Cashback card "A": 5% of every paid order\'s total is added to the card\'s balance (the card\'s own rate, set in the card\'s settings in the Rewloy panel). For example, an order of 400 TRY adds 20 TRY. No rule is needed.',
			Messages::cashback_note( 'A', 5.0, 'TRY' )
		);
		$this->assertStringContainsString( '7,50% of every paid order\'s total', Messages::cashback_note( 'A', 7.5, '' ) );
		$this->assertStringContainsString( 'an order of 400 adds 30', Messages::cashback_note( 'A', 7.5, '' ) );
		$none = Messages::cashback_note( 'A', null, 'TRY' );
		$this->assertStringContainsString( 'the card\'s own rate is applied', $none );
		$this->assertDoesNotMatchRegularExpression( '/\d/', $none );
	}
}
