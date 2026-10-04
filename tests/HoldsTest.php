<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\CheckoutSettings;
use Rewloy\WooCommerce\Holds;
use Rewloy\WooCommerce\Redeem;
use Rewloy\WooCommerce\RedeemCode;
use Rewloy\WooCommerce\Settings;

/** The order's side of a Rewloy code (0.4.0): hold, capture, release, refund, the record and the notes. */
final class HoldsTest extends TestCase {

	private \FakeSession $session;
	private \FakeCart $cart;
	/** @var list<array{0:int,1:int,2:string,3:int}> */
	private array $scheduled = array();
	/** @var list<array{0:int,1:string}> */
	private array $unscheduled = array();
	private bool $canSchedule = true;
	private Settings $settings;

	private const RW   = 'RW-XV5C-RHBE';
	private const NORM = 'RWXV5CRHBE';
	private const R1   = '0192dddd-5c6d-7e8f-9a0b-1c2d3e4f5a01';

	protected function setUp(): void {
		parent::setUp();
		$this->session     = new \FakeSession();
		$this->cart        = new \FakeCart();
		$this->scheduled   = $this->unscheduled = array();
		$this->canSchedule = true;
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'TRY' );
		Functions\when( 'wc_get_coupon_id_by_code' )->justReturn( 0 );
		Functions\when( 'wc_price' )->alias( static fn( $n ) => '<span>' . number_format( (float) $n, 2, ',', '.' ) . '&nbsp;&#8378;</span>' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		$this->settings = $this->connected();
		$this->settings->save_api_key( self::PLUGIN_KEY );
	}

	private function redeem(): Redeem {
		return new Redeem( $this->settings, $this->factory(), fn() => $this->session, fn() => $this->cart );
	}

	private function holds( ?Redeem $redeem = null ): Holds {
		return new Holds(
			$this->settings,
			$this->factory(),
			$redeem ?? $this->redeem(),
			function ( int $when, int $id, string $step, int $attempt ): bool {
				$this->scheduled[] = array( $when, $id, $step, $attempt );
				return $this->canSchedule;
			},
			function ( int $id, string $step ): void {
				$this->unscheduled[] = array( $id, $step );
			}
		);
	}

	private function quoteData( array $over = array() ): array {
		return array_merge(
			array(
				'kind' => 'balance', 'type' => 'cashback', 'programId' => self::PROGRAM, 'programName' => 'Cashback kartı', 'currency' => 'TRY',
				'maxMinor' => 4000, 'percent' => null, 'amountMinor' => null, 'tax' => 'discount', 'cardId' => 'card-a', 'cardLast4' => '6L4R', 'codeLast4' => 'RHBE',
			),
			$over
		);
	}

	/** The session's quote, as the cart left it. */
	private function quoted( array $over = array() ): void {
		$this->session->data[ Redeem::SESSION ][ self::NORM ] = array(
			'q'  => $this->quoteData( $over ),
			'at' => time(),
		);
	}

	private function redemption( array $over = array() ): array {
		return array_merge(
			array(
				'id' => self::R1, 'orderId' => '17', 'codeLast4' => 'RHBE', 'cardId' => 'card-a', 'cardLast4' => '6L4R', 'kind' => 'balance', 'type' => 'cashback',
				'programId' => self::PROGRAM, 'programName' => 'Cashback kartı', 'amountMinor' => 4000, 'percent' => null, 'currency' => 'TRY', 'state' => 'held',
				'generation' => 1, 'heldUntil' => '2026-10-11T12:00:00Z', 'capturedMinor' => 0, 'refundedMinor' => 0, 'late' => false, 'releaseReason' => null,
			),
			$over
		);
	}

	private function order( string $status = 'pending' ): \WC_Order {
		$o                  = new \WC_Order( 17, $status, 'alici@example.test' );
		$o->total           = '360.00';
		$o->discount_total  = '33.33';
		$o->discount_tax    = '6.67';
		$o->items['coupon'] = array( new \WC_Order_Item_Coupon( strtolower( self::RW ), '33.33', '6.67' ) );
		return $o;
	}

	private function notes( \WC_Order $o ): string {
		return implode( "\n", array_column( $o->notes, 'note' ) );
	}

	private function refused( callable $fn ): string {
		try {
			$fn();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		$this->fail( 'expected the checkout to be refused' );
	}

	public function test_an_order_without_a_rewloy_code_calls_nothing(): void {
		$o = new \WC_Order( 17, 'pending' );
		$o->items['coupon'] = array( new \WC_Order_Item_Coupon( 'indirim10', '10', '0' ) );
		$this->holds()->hold( $o );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $o->meta );
	}

	public function test_a_coupon_code_is_held_for_what_the_customer_saved_and_written_down(): void {
		$this->quoted();
		$this->script( $this->answer( 201, array( 'data' => $this->redemption() ) ) );
		$o = $this->order();
		$this->holds()->on_classic( 17, array(), $o );
		$this->assertSame( 'https://app.rewloy.com/v1/shops/' . self::LINK . '/orders/17/redemptions', $this->requests[0]['url'] );
		$this->assertSame( array( 'code' => self::NORM, 'currency' => 'TRY', 'amountMinor' => 4000, 'orderTotalMinor' => 40000 ), json_decode( $this->requests[0]['args']['body'], true ) );
		$this->assertSame( Redeem::TIMEOUT, $this->requests[0]['args']['timeout'] );
		$rows = Holds::read( $o );
		$this->assertSame( 'held', $rows[0]['state'] );
		$this->assertSame( RedeemCode::ref( self::NORM ), $rows[0]['ref'] );
		$this->assertStringNotContainsString( 'XV5C', (string) $o->meta[ Holds::META ], 'the code is not kept' );
		$this->assertStringContainsString( 'Rewloy: 40,00 ₺ held for this order on the Cashback kartı (card …6L4R).', $this->notes( $o ) );
		$this->assertSame( array( array( 17, 'release' ) ), $this->unscheduled, 'a release waiting from before is called off' );
		$this->assertSame( 17, $this->session->data[ Redeem::SESSION ][ self::NORM ]['order'] );
		$this->assertSame( 'Cashback kartı', get_option( CheckoutSettings::SEEN_OPTION )[ self::PROGRAM ]['name'] );
	}

	public function test_a_payment_code_is_held_for_the_payment_lines_final_amount(): void {
		$this->quoted( array( 'type' => 'giftcard', 'tax' => 'payment', 'maxMinor' => 50000 ) );
		$this->script( $this->answer( 201, array( 'data' => $this->redemption( array( 'amountMinor' => 10000 ) ) ) ) );
		$o = $this->order();
		$o->items['coupon'] = array( new \WC_Order_Item_Coupon( strtolower( self::RW ), '0', '0' ) );
		$fee                = new \WC_Order_Item_Fee( '-100.00' );
		$fee->meta[ Redeem::META_FEE ] = RedeemCode::ref( self::NORM );
		$o->items['fee']    = array( new \WC_Order_Item_Fee( '-5' ), $fee );
		$this->holds()->hold( $o );
		$this->assertSame( 10000, json_decode( $this->requests[0]['args']['body'], true )['amountMinor'], 'what WooCommerce let the line take, not the card\'s 500' );
	}

	public function test_a_tie_holds_nothing_and_says_so(): void {
		$this->quoted( array( 'kind' => 'link', 'type' => 'stamp', 'programName' => 'Damga kartı', 'maxMinor' => null, 'tax' => null ) );
		$this->script( $this->answer( 201, array( 'data' => $this->redemption( array( 'kind' => 'link', 'type' => 'stamp', 'programName' => 'Damga kartı', 'amountMinor' => 0 ) ) ) ) );
		$o = $this->order();
		$o->items['coupon'] = array( new \WC_Order_Item_Coupon( strtolower( self::RW ), '0', '0' ) );
		$this->holds()->hold( $o );
		$this->assertSame( 0, json_decode( $this->requests[0]['args']['body'], true )['amountMinor'] );
		$this->assertStringContainsString( 'the order is tied to the Damga kartı (card …6L4R); once paid, it counts on this card.', $this->notes( $o ) );
	}

	public function test_a_refusal_at_the_order_stops_the_checkout_lets_go_and_forgets_the_code(): void {
		$this->quoted();
		$this->script( $this->failure( 409, 'INSUFFICIENT_BALANCE' ) );
		$o   = $this->order();
		$msg = $this->refused( fn() => $this->holds()->on_classic( 17, array(), $o ) );
		$this->assertSame( 'Your card\'s balance is not enough for this code. Remove the code and make a new one on your card.', $msg );
		$this->assertCount( 1, $this->requests, 'nothing held before it: no release needed' );
		$this->assertArrayNotHasKey( self::NORM, $this->session->data[ Redeem::SESSION ] ?? array() );
	}

	public function test_a_refusal_after_another_code_was_held_releases_the_order(): void {
		$second = RedeemCodeTest::code( 'BBBBBBB' );
		$this->quoted();
		$this->session->data[ Redeem::SESSION ][ RedeemCode::normalize( $second ) ] = array( 'q' => $this->quoteData( array( 'cardId' => 'b' ) ), 'at' => time() );
		$o = $this->order();
		$o->items['coupon'][] = new \WC_Order_Item_Coupon( strtolower( $second ), '10', '2' );
		$this->script(
			$this->answer( 201, array( 'data' => $this->redemption() ) ),
			$this->failure( 409, 'CODE_USED' ),
			$this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'released', 'releaseReason' => 'shop' ) ) ) ) )
		);
		$msg = $this->refused( fn() => $this->holds()->hold( $o ) );
		$this->assertSame( 'This code was used on another order. Make a new code on your card.', $msg );
		$this->assertStringEndsWith( '/orders/17/release', $this->requests[2]['url'] );
		$this->assertSame( array( 'reason' => 'shop' ), json_decode( $this->requests[2]['args']['body'], true ) );
	}

	public function test_no_clear_answer_refuses_the_checkout_and_asks_for_a_release(): void {
		$this->quoted();
		$this->script( new \WP_Error( 'x', 'timeout' ), new \WP_Error( 'x', 'timeout' ) );
		$o   = $this->order();
		$msg = $this->refused( fn() => $this->holds()->hold( $o ) );
		$this->assertSame( 'Rewloy cannot be reached right now. Try the code again in a few minutes, or remove it and go on with the order.', $msg );
		$this->assertCount( 2, $this->requests, 'one retry with the same natural key' );
		$this->assertSame( 'unclear', $o->meta[ Holds::META_STATE ] );
		$this->assertSame( 17, $this->scheduled[0][1] );
		$this->assertSame( 'release', $this->scheduled[0][2] );
		$this->assertStringContainsString( 'no clear answer came; a release of any hold was asked for.', $this->notes( $o ) );

		// The release, later: the order is still unpaid, so it is asked; harmless with nothing held.
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$this->assertSame( 'done', $this->holds()->run( 17, 'release', 0 ) );
		$this->assertStringEndsWith( '/orders/17/release', $this->requests[2]['url'] );
		$this->assertArrayNotHasKey( Holds::META_STATE, $o->meta );
	}

	public function test_a_clear_hold_after_an_unclear_one_clears_the_flag_and_the_scheduled_release_does_nothing(): void {
		$this->quoted();
		$o                         = $this->order();
		$o->meta[ Holds::META_STATE ] = 'unclear';
		$this->script( $this->answer( 201, array( 'data' => $this->redemption() ) ) );
		$this->holds()->hold( $o );
		$this->assertArrayNotHasKey( Holds::META_STATE, $o->meta );
		$o->set_status( 'processing' );
		$this->assertSame( 'skipped', $this->holds()->run( 17, 'release', 0 ) );
	}

	public function test_a_block_checkout_refusal_is_thrown_for_the_store_api(): void {
		$this->quoted();
		$this->script( $this->failure( 410, 'CODE_EXPIRED' ) );
		$this->assertSame( 'This code has expired. Make a new code on your card.', $this->refused( fn() => $this->holds()->on_block( $this->order() ) ) );
	}

	public function test_a_hold_this_order_no_longer_carries_is_released_before_the_codes_are_held_again(): void {
		$this->quoted();
		$o                    = $this->order();
		$old                  = Holds::clean( $this->redemption( array( 'id' => '0192dddd-5c6d-7e8f-9a0b-1c2d3e4f5a09', 'codeLast4' => 'OLD1' ) ) );
		$old['ref']           = 'someothercode000';
		$o->meta[ Holds::META ] = json_encode( array( $old ) );
		$this->script(
			$this->answer( 200, array( 'data' => array( $this->redemption( array( 'id' => '0192dddd-5c6d-7e8f-9a0b-1c2d3e4f5a09', 'state' => 'released' ) ) ) ) ),
			$this->answer( 201, array( 'data' => $this->redemption() ) )
		);
		$this->holds()->hold( $o );
		$this->assertStringEndsWith( '/release', $this->requests[0]['url'] );
		$this->assertStringEndsWith( '/redemptions', $this->requests[1]['url'] );
		$this->assertCount( 2, Holds::read( $o ) );
	}

	public function test_a_code_this_order_already_holds_is_held_again_from_its_record_when_rewloy_calls_it_used(): void {
		// The session's quote is gone (a new session); Rewloy says the code is used (it is: by this order).
		$o                      = $this->order();
		$row                    = Holds::clean( $this->redemption() );
		$row['ref']             = RedeemCode::ref( self::NORM );
		$o->meta[ Holds::META ] = json_encode( array( $row ) );
		$this->script( $this->failure( 409, 'CODE_USED' ), $this->answer( 200, array( 'data' => $this->redemption() ) ) );
		$this->holds()->hold( $o );
		$this->assertStringEndsWith( '/orders/17/redemptions', $this->requests[1]['url'] );
		$this->assertSame( 4000, json_decode( $this->requests[1]['args']['body'], true )['amountMinor'] );
		$this->assertStringNotContainsString( 'held for this order', $this->notes( $o ), 'nothing changed, no new note' );
	}

	/** An API refusal with its `details`. */
	private function refusal409( string $code, array $details ): array {
		return $this->answer( 409, array( 'error' => array( 'code' => $code, 'message' => 'x', 'requestId' => 'req-1', 'status' => 409, 'details' => $details ) ) );
	}

	private function recordedOrder(): \WC_Order {
		$o                      = $this->order();
		$row                    = Holds::clean( $this->redemption() );
		$row['ref']             = RedeemCode::ref( self::NORM );
		$o->meta[ Holds::META ] = json_encode( array( $row ) );
		return $o;
	}

	public function test_a_lost_quote_is_asked_again_naming_the_order_and_rewloy_1_0_answers_the_orders_own_code(): void {
		// Rewloy 1.0 (ADR 180): the quote that names the order is answered 200 with the order's `redemption`.
		$o = $this->recordedOrder();
		$this->script(
			$this->answer( 200, array( 'data' => array_merge( $this->quoteData(), array( 'firstUseBy' => '2026-10-04T12:00:00Z', 'attachBy' => '2026-10-04T12:45:00Z', 'redemption' => $this->redemption() ) ) ) ),
			$this->answer( 200, array( 'data' => $this->redemption() ) )
		);
		$this->holds()->hold( $o );
		$this->assertCount( 2, $this->requests, 'one quote, one hold: no CODE_USED round' );
		$this->assertStringEndsWith( '/checkout-codes/quote', $this->requests[0]['url'] );
		$this->assertSame( '17', json_decode( $this->requests[0]['args']['body'], true )['orderId'] );
		$this->assertStringEndsWith( '/orders/17/redemptions', $this->requests[1]['url'] );
		$this->assertSame( 4000, json_decode( $this->requests[1]['args']['body'], true )['amountMinor'] );
		$this->assertStringNotContainsString( 'held for this order', $this->notes( $o ), 'nothing changed, no new note' );
	}

	public function test_a_session_quote_is_used_without_asking_and_the_cart_never_names_an_order(): void {
		$this->quoted();
		$this->script( $this->answer( 201, array( 'data' => $this->redemption() ) ) );
		$this->holds()->hold( $this->order() );
		$this->assertCount( 1, $this->requests, 'the session\'s quote is good: only the hold is called' );
		$this->script( $this->answer( 200, array( 'data' => $this->quoteData() ) ) );
		$this->session->data = array();
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertArrayNotHasKey( 'orderId', json_decode( $this->requests[1]['args']['body'], true ), 'at the cart there is no order yet' );
	}

	public function test_a_rewloy_before_1_0_that_refuses_the_order_id_is_asked_again_without_it_and_its_code_used_answer_is_still_met(): void {
		$o = $this->recordedOrder();
		$this->script(
			$this->failure( 400, 'VALIDATION' ),
			$this->failure( 409, 'CODE_USED' ),
			$this->answer( 200, array( 'data' => $this->redemption() ) )
		);
		$this->holds()->hold( $o );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( '17', json_decode( $this->requests[0]['args']['body'], true )['orderId'] );
		$this->assertArrayNotHasKey( 'orderId', json_decode( $this->requests[1]['args']['body'], true ) );
		$this->assertStringEndsWith( '/orders/17/redemptions', $this->requests[2]['url'] );
		$this->assertSame( 4000, json_decode( $this->requests[2]['args']['body'], true )['amountMinor'] );
	}

	public function test_a_validation_error_without_an_order_id_is_not_asked_again(): void {
		$this->script( $this->failure( 400, 'VALIDATION' ) );
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_code_released_at_the_quote_is_told_by_its_reason(): void {
		foreach ( array(
			'expired'  => 'The hold on this code has run out. Get a new code from Rewloy.',
			'merchant' => 'This code was released by the business. Get a new code from Rewloy.',
			''         => 'The hold on this code has ended. Make a new code on your card.',
		) as $reason => $words ) {
			$this->requests = array();
			$this->session  = new \FakeSession();
			$this->script( $this->refusal409( 'CODE_RELEASED', '' === $reason ? array() : array( 'reason' => $reason ) ) );
			$o = $this->order();
			$this->assertSame( $words, $this->refused( fn() => $this->holds()->hold( $o ) ), $reason );
			$this->assertStringNotContainsString( 'another order', $words );
		}
	}

	public function test_a_code_released_at_the_hold_is_told_by_its_reason_and_lets_the_orders_other_holds_go(): void {
		$second = RedeemCodeTest::code( 'BBBBBBB' );
		$this->quoted();
		$this->session->data[ Redeem::SESSION ][ RedeemCode::normalize( $second ) ] = array( 'q' => $this->quoteData( array( 'cardId' => 'b' ) ), 'at' => time() );
		$o                    = $this->order();
		$o->items['coupon'][] = new \WC_Order_Item_Coupon( strtolower( $second ), '10', '2' );
		$this->script(
			$this->answer( 201, array( 'data' => $this->redemption() ) ),
			$this->refusal409( 'CODE_RELEASED', array( 'reason' => 'expired' ) ),
			$this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'released', 'releaseReason' => 'shop' ) ) ) ) )
		);
		$this->assertSame( 'The hold on this code has run out. Get a new code from Rewloy.', $this->refused( fn() => $this->holds()->hold( $o ) ) );
		$this->assertStringEndsWith( '/orders/17/release', $this->requests[2]['url'] );
	}

	private function heldOrder( string $status, array $row = array() ): \WC_Order {
		$o                      = $this->order( $status );
		$r                      = Holds::clean( $this->redemption( $row ) );
		$r['ref']               = RedeemCode::ref( self::NORM );
		$o->meta[ Holds::META ] = json_encode( array( $r ) );
		return $o;
	}

	public function test_paid_captures_at_once_and_notes_it(): void {
		$o = $this->heldOrder( 'processing' );
		$this->script( $this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'captured', 'capturedMinor' => 4000 ) ) ) ) ) );
		$this->holds()->on_paid( 17 );
		$this->assertStringEndsWith( '/orders/17/capture', $this->requests[0]['url'] );
		$this->assertSame( Holds::STEP_TIMEOUT, $this->requests[0]['args']['timeout'] );
		$this->assertStringContainsString( 'Rewloy: 40,00 ₺ taken from the card.', $this->notes( $o ) );
		$this->assertSame( 'captured', Holds::read( $o )[0]['state'] );
		// Paid again (completed): nothing left to do, no call.
		$this->holds()->on_paid( 17 );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_gateways_payment_complete_captures_before_the_order_is_marked_paid(): void {
		$o = $this->heldOrder( 'pending' );
		$this->script( $this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'captured', 'capturedMinor' => 4000 ) ) ) ) ) );
		$this->holds()->on_payment_complete( 17 );
		$this->assertStringEndsWith( '/orders/17/capture', $this->requests[0]['url'] );
		$this->assertSame( 'captured', Holds::read( $o )[0]['state'] );
		$o->set_status( 'processing' );
		$this->holds()->on_paid( 17 );
		$this->assertCount( 1, $this->requests, 'the status that follows finds nothing left' );
	}

	public function test_the_order_total_before_discounts_counts_the_payment_lines_back_in(): void {
		$o                 = $this->order();
		$o->total          = '300.00';
		$o->discount_total = '50.00';
		$o->discount_tax   = '10.00';
		$fee               = new \WC_Order_Item_Fee( '-40.00' );
		$fee->meta[ Redeem::META_FEE ] = 'abc';
		$o->items['fee']   = array( $fee, new \WC_Order_Item_Fee( '5.00' ) );
		$this->assertSame( 40000, $this->holds()->total_before_discounts( $o ) );
	}

	public function test_a_late_capture_and_an_unbacked_one_are_said_as_they_are(): void {
		$o = $this->heldOrder( 'processing', array( 'state' => 'expired' ) );
		$this->script( $this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'captured', 'capturedMinor' => 4000, 'late' => true ) ) ) ) ) );
		$this->holds()->run( 17, 'capture', 0 );
		$this->assertStringContainsString( 'the hold had ended; 40,00 ₺ was still on the card and was taken.', $this->notes( $o ) );

		$o = $this->heldOrder( 'processing', array( 'state' => 'expired' ) );
		$this->script( $this->answer( 409, array( 'error' => array( 'code' => 'HOLD_UNBACKED', 'message' => 'x', 'details' => array( 'redemptions' => array( $this->redemption( array( 'state' => 'unbacked', 'late' => true ) ) ) ) ) ) ) );
		$this->assertSame( 'done', $this->holds()->run( 17, 'capture', 0 ) );
		$this->assertStringContainsString( 'it had been spent, or the card was closed). The 40,00 ₺ on this order could not be taken from the card. It shows in the Rewloy panel under E-ticaret › the shop.', $this->notes( $o ) );
		$this->assertSame( 'unbacked', Holds::read( $o )[0]['state'] );
	}

	public function test_a_card_closed_since_the_order_is_noted_and_not_retried(): void {
		$o = $this->heldOrder( 'processing' );
		$this->script( $this->failure( 409, 'PASS_INACTIVE' ) );
		$this->assertSame( 'refused', $this->holds()->run( 17, 'capture', 0 ) );
		$this->assertStringContainsString( 'the card was closed after the order was placed', $this->notes( $o ) );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_an_unclear_capture_is_tried_again_later_then_given_up_with_a_note(): void {
		$o = $this->heldOrder( 'processing' );
		$this->script( $this->failure( 503, 'X' ), $this->failure( 503, 'X' ) );
		$this->assertSame( 'retry', $this->holds()->run( 17, 'capture', 0 ) );
		$this->assertSame( array( 17, 'capture', 1 ), array_slice( $this->scheduled[0], 1 ) );
		$this->assertGreaterThanOrEqual( time() + Holds::RETRY_DELAYS[0] - 1, $this->scheduled[0][0] );
		$this->assertStringContainsString( 'It is tried again automatically, and Rewloy does the same', $this->notes( $o ) );
		$this->script( $this->failure( 503, 'X' ), $this->failure( 503, 'X' ) );
		$this->assertSame( 'gave-up', $this->holds()->run( 17, 'capture', count( Holds::RETRY_DELAYS ) ) );
		$this->assertStringContainsString( 'still no clear answer after several tries', $this->notes( $o ) );
	}

	public function test_a_cancelled_or_failed_order_releases_with_its_reason(): void {
		foreach ( array( 'cancelled' => 'the order was cancelled', 'failed' => 'the payment failed' ) as $status => $why ) {
			$o = $this->heldOrder( $status );
			$this->script( $this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'released', 'releaseReason' => $status ) ) ) ) ) );
			'cancelled' === $status ? $this->holds()->on_cancelled( 17 ) : $this->holds()->on_failed( 17 );
			$this->assertSame( array( 'reason' => $status ), json_decode( (string) end( $this->requests )['args']['body'], true ) );
			$this->assertStringContainsString( "Rewloy: 40,00 ₺ released back to the card ($why).", $this->notes( $o ) );
		}
	}

	public function test_a_step_the_order_has_moved_past_is_not_made(): void {
		$o = $this->heldOrder( 'processing' );
		$this->assertSame( 'skipped', $this->holds()->run( 17, 'release', 0 ), 'a paid order is not released' );
		$o->set_status( 'pending' );
		$this->assertSame( 'skipped', $this->holds()->run( 17, 'capture', 0 ), 'an unpaid one is not captured' );
		$this->assertSame( 'skipped', $this->holds()->run( 17, 'refund', 0 ) );
		$this->assertSame( 'no-order', $this->holds()->run( 99, 'capture', 0 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_full_refund_puts_the_value_back_and_says_what_the_order_earned_back(): void {
		$o = $this->heldOrder( 'refunded', array( 'state' => 'captured', 'capturedMinor' => 4000 ) );
		$this->script(
			$this->answer(
				200,
				array(
					'data' => array(
						'redemptions' => array( $this->redemption( array( 'state' => 'refunded', 'capturedMinor' => 4000, 'refundedMinor' => 4000 ) ) ),
						'unearned'    => array( 'cardLast4' => '6L4R', 'unit' => 'try_minor', 'earned' => 1800, 'reversed' => 1200, 'short' => 600 ),
					),
				)
			)
		);
		$this->holds()->on_refunded( 17 );
		$this->assertSame( '{}', $this->requests[0]['args']['body'] );
		$note = $this->notes( $o );
		$this->assertStringContainsString( 'Rewloy: refunded; 40,00 ₺ put back on the card.', $note );
		$this->assertStringContainsString( 'What this order earned on the card (12,00 ₺) was taken back.', $note );
		$this->assertStringContainsString( '6,00 ₺ of it had already been used and could not be taken back.', $note );
	}

	public function test_a_refunded_tie_says_only_what_happened(): void {
		$o = $this->heldOrder( 'refunded', array( 'kind' => 'link', 'state' => 'captured', 'amountMinor' => 0 ) );
		$this->script( $this->answer( 200, array( 'data' => array( 'redemptions' => array( $this->redemption( array( 'kind' => 'link', 'state' => 'refunded', 'amountMinor' => 0 ) ) ), 'unearned' => array( 'unit' => 'stamp', 'earned' => 2, 'reversed' => 2, 'short' => 0 ) ) ) ) );
		$this->holds()->on_refunded( 17 );
		$this->assertSame( 'Rewloy: the order was refunded. What this order earned on the card (2 stamps) was taken back.', end( $o->notes )['note'] );
	}

	public function test_a_partial_refund_writes_a_note_and_calls_nothing(): void {
		$o = $this->heldOrder( 'processing', array( 'state' => 'captured', 'capturedMinor' => 4000 ) );
		$this->holds()->on_partial_refund( 17, 3 );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'a partial refund does not change the card', $this->notes( $o ) );
	}

	public function test_no_note_and_no_record_ever_holds_the_code_or_the_cards_serial(): void {
		$this->quoted();
		$this->script(
			$this->answer( 201, array( 'data' => $this->redemption() ) ),
			$this->answer( 200, array( 'data' => array( $this->redemption( array( 'state' => 'captured', 'capturedMinor' => 4000 ) ) ) ) )
		);
		$o = $this->order();
		$this->holds()->hold( $o );
		$o->set_status( 'processing' );
		$this->holds()->on_paid( 17 );
		$all = $this->notes( $o ) . json_encode( $o->meta );
		$this->assertStringNotContainsString( 'XV5C', $all );
		$this->assertStringNotContainsString( self::SERIAL, $all );
	}

	public function test_without_a_key_a_code_on_the_order_refuses_the_checkout(): void {
		$this->settings = $this->connected();
		$this->quoted();
		$h = new Holds( $this->settings, static fn() => null, $this->redeem() );
		$this->assertSame( 'Rewloy cannot be reached right now. Try the code again in a few minutes, or remove it and go on with the order.', $this->refused( fn() => $h->hold( $this->order() ) ) );
	}
}
