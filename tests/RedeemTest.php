<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Redeem;
use Rewloy\WooCommerce\RedeemCode;
use Rewloy\WooCommerce\Settings;

/** The cart's side of a Rewloy code (0.4.0): the virtual coupon, its validity, the payment line, the session. */
final class RedeemTest extends TestCase {

	private \FakeSession $session;
	private \FakeCart $cart;
	private int $now = 1_800_000_000;
	private bool $admin = false;
	private bool $taxIncl = true;
	/** @var array<string,int> The shop's own coupons. */
	private array $shopCoupons = array();

	private const RW = 'RW-XV5C-RHBE';
	private const NORM = 'RWXV5CRHBE';

	protected function setUp(): void {
		parent::setUp();
		$this->session     = new \FakeSession();
		$this->cart        = new \FakeCart();
		$this->admin       = false;
		$this->taxIncl     = true;
		$this->shopCoupons = array();
		Functions\when( 'is_admin' )->alias( fn() => $this->admin );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'TRY' );
		Functions\when( 'wc_get_coupon_id_by_code' )->alias( fn( $c ) => $this->shopCoupons[ strtolower( (string) $c ) ] ?? 0 );
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'wc_prices_include_tax' )->alias( fn() => $this->taxIncl );
		Functions\when( 'esc_html' )->returnArg();
	}

	private function redeem( ?Settings $settings = null ): Redeem {
		$settings ??= $this->connected();
		$settings->save_api_key( self::PLUGIN_KEY );
		return new Redeem( $settings, $this->factory(), fn() => $this->session, fn() => $this->cart, fn() => $this->now );
	}

	/** Rewloy's quote of a code. */
	private function quote( array $over = array() ): array {
		return $this->answer(
			200,
			array(
				'data' => array_merge(
					array(
						'kind'        => 'balance',
						'type'        => 'cashback',
						'programId'   => self::PROGRAM,
						'programName' => 'Cashback kartı',
						'currency'    => 'TRY',
						'maxMinor'    => 4000,
						'percent'     => null,
						'amountMinor' => null,
						'tax'         => 'discount',
						'cardId'      => 'card-a',
						'cardLast4'   => '6L4R',
						'codeLast4'   => 'RHBE',
						'firstUseBy'  => '2026-10-04T12:15:00Z',
						'attachBy'    => '2026-10-04T12:45:00Z',
					),
					$over
				),
			)
		);
	}

	private function thrown( Redeem $r, string $code = self::RW ): string {
		try {
			$r->is_valid( true, new \WC_Coupon( strtolower( $code ) ) );
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_a_code_that_is_not_rewloys_is_left_to_the_shop(): void {
		$r = $this->redeem();
		$this->assertFalse( $r->coupon_data( false, 'indirim10' ) );
		$this->assertTrue( $r->is_valid( true, new \WC_Coupon( 'indirim10' ) ) );
		$this->assertSame( array( 'already' ), $r->coupon_data( array( 'already' ), strtolower( self::RW ) ), 'an earlier answer stands' );
		$this->assertSame( array(), $this->requests );
	}

	public function test_without_a_connection_no_rewloy_code_is_answered(): void {
		$r = new Redeem( new Settings(), $this->factory(), fn() => $this->session, fn() => $this->cart );
		$this->assertFalse( $r->coupon_data( false, strtolower( self::RW ) ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_shops_own_coupon_of_the_same_letters_wins(): void {
		$this->shopCoupons[ strtolower( self::RW ) ] = 12;
		$this->assertFalse( $this->redeem()->coupon_data( false, strtolower( self::RW ) ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_cashback_code_is_a_fixed_cart_coupon_of_what_it_may_take(): void {
		$this->script( $this->quote() );
		$c = $this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertSame( 'fixed_cart', $c['discount_type'] );
		$this->assertSame( 40.0, $c['amount'] );
		$this->assertFalse( $c['individual_use'] );
		$this->assertArrayNotHasKey( 'usage_limit', $c, 'single use is Rewloy\'s to keep' );
		$this->assertSame( array( 'code' => self::NORM, 'currency' => 'TRY' ), json_decode( $this->requests[0]['args']['body'], true ) );
		$this->assertSame( Redeem::TIMEOUT, $this->requests[0]['args']['timeout'] );
	}

	public function test_a_discount_card_is_a_percent_coupon(): void {
		$this->script( $this->quote( array( 'kind' => 'percent', 'type' => 'discount', 'maxMinor' => null, 'percent' => 15, 'tax' => null ) ) );
		$c = $this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertSame( array( 'percent', 15 ), array( $c['discount_type'], $c['amount'] ) );
	}

	public function test_a_gift_card_with_the_payment_setting_is_a_coupon_of_nothing_and_a_negative_untaxed_fee(): void {
		$this->script( $this->quote( array( 'type' => 'giftcard', 'programName' => 'Hediye kartı', 'tax' => 'payment' ) ) );
		$r = $this->redeem();
		$c = $r->coupon_data( false, strtolower( self::RW ) );
		$this->assertSame( 0, $c['amount'] );
		$this->cart->applied = array( strtolower( self::RW ) );
		$r->add_fees( $this->cart );
		$this->assertCount( 1, $this->cart->fees );
		$fee = $this->cart->fees[0];
		$this->assertSame( Redeem::FEE_PREFIX . RedeemCode::ref( self::NORM ), $fee['id'] );
		$this->assertEquals( -40.0, $fee['amount'] );
		$this->assertFalse( $fee['taxable'] );
		$this->assertSame( 'Rewloy: Hediye kartı', $fee['name'] );
		$this->assertStringNotContainsString( 'XV5C', json_encode( $fee ) );
		$this->assertCount( 1, $this->requests, 'the fee reads the quote, it does not ask again' );
	}

	public function test_a_money_coupon_with_the_payment_setting_is_a_fee_too_and_a_percent_never_is(): void {
		$this->assertSame( 'fee', Redeem::mode( Redeem::clean_quote( array( 'kind' => 'amount', 'amountMinor' => 5000, 'tax' => 'payment' ) ) ) );
		$this->assertSame( 'coupon', Redeem::mode( Redeem::clean_quote( array( 'kind' => 'percent', 'percent' => 10, 'tax' => 'payment' ) ) ) );
		$this->assertSame( 'link', Redeem::mode( Redeem::clean_quote( array( 'kind' => 'link', 'tax' => null ) ) ) );
		$this->assertNull( Redeem::clean_quote( array( 'kind' => 'balance', 'maxMinor' => 0 ) ), 'no value, no coupon' );
		$this->assertNull( Redeem::clean_quote( array( 'kind' => 'refund' ) ) );
	}

	public function test_the_payment_lines_tax_is_removed_in_the_cart_and_on_the_order_and_no_one_elses(): void {
		$r      = $this->redeem();
		$ours   = (object) array( 'object' => (object) array( 'id' => 'rewloy-abc' ) );
		$theirs = (object) array( 'object' => (object) array( 'id' => 'shipping-fee' ) );
		$this->assertSame( array(), $r->fee_taxes( array( 1 => -6.67 ), $ours ) );
		$this->assertSame( array( 1 => 5.0 ), $r->fee_taxes( array( 1 => 5.0 ), $theirs ) );

		$item = new \WC_Order_Item_Fee( '-40' );
		$r->tag_fee_item( $item, 'rewloy-abc' );
		$this->assertSame( 'abc', $item->get_meta( Redeem::META_FEE ) );
		$r->fee_item_taxes( $item );
		$this->assertSame( array(), $item->taxes );
		$other = new \WC_Order_Item_Fee( '-5' );
		$r->tag_fee_item( $other, 'handling' );
		$r->fee_item_taxes( $other );
		$this->assertSame( 'untouched', $other->taxes );
	}

	public function test_a_stamp_card_is_a_tie_with_its_own_words(): void {
		$this->script( $this->quote( array( 'kind' => 'link', 'type' => 'stamp', 'programName' => 'Damga kartı', 'maxMinor' => null, 'tax' => null ) ) );
		$r = $this->redeem();
		$c = $r->coupon_data( false, strtolower( self::RW ) );
		$this->assertSame( 0, $c['amount'] );
		$coupon = new \WC_Coupon( strtolower( self::RW ) );
		$this->assertSame( 'Rewloy: Damga kartı', $r->label( 'Coupon: x', $coupon ) );
		$this->assertSame( 'no discount: the order counts on this card', $r->amount_html( '-0', $coupon ) );
		$this->assertSame( '-₺1', $r->amount_html( '-₺1', new \WC_Coupon( 'indirim10' ) ) );
	}

	public function test_the_quote_is_kept_for_five_minutes_then_asked_again(): void {
		$this->script( $this->quote(), $this->quote( array( 'maxMinor' => 3000 ) ) );
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertSame( 40.0, $this->redeem()->coupon_data( false, strtolower( self::RW ) )['amount'], 'another request, same session' );
		$this->assertCount( 1, $this->requests );
		$this->now += Redeem::CACHE_TTL + 1;
		$this->assertSame( 30.0, $this->redeem()->coupon_data( false, strtolower( self::RW ) )['amount'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_code_held_for_an_order_stays_answered_past_five_minutes(): void {
		$this->script( $this->quote() );
		$r = $this->redeem();
		$r->coupon_data( false, strtolower( self::RW ) );
		$r->mark_held( self::NORM, 17 );
		$this->now += 3600;
		$this->assertSame( 40.0, $this->redeem()->coupon_data( false, strtolower( self::RW ) )['amount'] );
		$this->assertCount( 1, $this->requests );
		// Removed from the cart while an order holds it: kept; the cart emptied after the order: gone.
		$this->redeem()->removed( strtolower( self::RW ) );
		$this->assertNotNull( $this->redeem()->cached( self::NORM ) );
		$this->redeem()->emptied();
		$this->assertArrayNotHasKey( Redeem::SESSION, $this->session->data );
	}

	public function test_a_removed_code_is_asked_afresh(): void {
		$this->script( $this->quote(), $this->quote() );
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->redeem()->removed( strtolower( self::RW ) );
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertCount( 2, $this->requests );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function refusals(): array {
		return array(
			'invalid'   => array( 'CODE_INVALID', 'This Rewloy code is not valid. Copy it again from your card\'s page or from Rewloy Cüzdan.' ),
			'expired'   => array( 'CODE_EXPIRED', 'This code has expired. Make a new code on your card.' ),
			'used'      => array( 'CODE_USED', 'This code was used on another order. Make a new code on your card.' ),
			'balance'   => array( 'INSUFFICIENT_BALANCE', 'Your card\'s balance is not enough for this code. Remove the code and make a new one on your card.' ),
			'inactive'  => array( 'PASS_INACTIVE', 'This card can no longer be used.' ),
			'expiredp'  => array( 'PASS_EXPIRED', 'This card can no longer be used.' ),
			'usedup'    => array( 'PASS_USED_UP', 'This card has no use left.' ),
			'nothere'   => array( 'CODE_NOT_ACCEPTED_HERE', 'This card cannot be used in this shop.' ),
			'forbidden' => array( 'FORBIDDEN', 'This card cannot be used in this shop.' ),
			'voucher'   => array( 'VOUCHER_NOT_ONLINE', 'This coupon can only be used in the shop.' ),
			'paused'    => array( 'SHOP_PAUSED', 'Rewloy cards cannot be used in this shop right now.' ),
			'other'     => array( 'SOMETHING_NEW', 'This Rewloy code cannot be used on this order. Remove it and make a new code on your card.' ),
		);
	}

	/** @dataProvider refusals */
	public function test_each_refusal_is_said_in_the_customers_words_and_nothing_is_applied( string $code, string $words ): void {
		$this->script( $this->failure( 409, $code ) );
		$r = $this->redeem();
		$c = $r->coupon_data( false, strtolower( self::RW ) );
		$this->assertSame( 0, $c['amount'] );
		$this->assertSame( $words, $this->thrown( $r ) );
		$this->assertNull( $r->cached( self::NORM ), 'a refusal is never kept' );
		$this->assertStringNotContainsString( 'XV5C', $words );
	}

	public function test_a_currency_mismatch_names_the_cards_currency(): void {
		$this->script( $this->answer( 422, array( 'error' => array( 'code' => 'CURRENCY_MISMATCH', 'message' => 'x', 'details' => array( 'currency' => 'EUR' ) ) ) ) );
		$this->assertSame( 'This card works in EUR; your basket is in another currency.', $this->thrown( $this->redeem() ) );
	}

	public function test_rewloy_unreachable_or_busy_is_said_so_and_nothing_is_applied_or_kept(): void {
		foreach ( array( array( new \WP_Error( 'x', 'down' ), new \WP_Error( 'x', 'down' ) ), array( $this->failure( 503, 'X' ), $this->failure( 503, 'X' ) ), array( $this->failure( 429, 'RATE_LIMITED' ), $this->failure( 429, 'RATE_LIMITED' ) ), array( $this->failure( 500, 'INTERNAL' ) ) ) as $answers ) {
			$this->script( ...$answers );
			$this->session->data = array();
			$this->assertSame( 'Rewloy cannot be reached right now. Try the code again in a few minutes, or remove it and go on with the order.', $this->thrown( $this->redeem() ) );
			$this->assertSame( array(), $this->session->data, 'not kept, and not counted as a miss' );
		}
	}

	public function test_a_typo_is_refused_without_a_call_and_counts_as_a_miss(): void {
		$typo = substr_replace( self::RW, 'A' === substr( self::RW, -1 ) ? 'B' : 'A', -1 );
		$this->assertSame( 'This Rewloy code is not valid. Copy it again from your card\'s page or from Rewloy Cüzdan.', $this->thrown( $this->redeem(), $typo ) );
		$this->assertSame( array(), $this->requests );
		$this->assertCount( 1, $this->session->data[ Redeem::MISSES ] );
	}

	public function test_five_refused_codes_in_ten_minutes_stop_the_session_asking(): void {
		for ( $i = 0; $i < Redeem::MISS_LIMIT; $i++ ) {
			$this->script( $this->failure( 404, 'CODE_INVALID' ) );
			$this->thrown( $this->redeem(), RedeemCodeTest::code( 'AAAAAA' . RedeemCode::ALPHABET[ $i ] ) );
		}
		$this->assertCount( Redeem::MISS_LIMIT, $this->requests );
		$this->assertSame( 'Too many codes were tried. Try again in a few minutes.', $this->thrown( $this->redeem() ) );
		$this->assertCount( Redeem::MISS_LIMIT, $this->requests, 'no call' );
		$this->now += Redeem::MISS_WINDOW + 1;
		$this->script( $this->quote() );
		$this->assertSame( '', $this->thrown( $this->redeem() ) );
	}

	public function test_a_refusal_that_is_not_the_customers_code_does_not_count_as_a_miss(): void {
		$this->script( $this->failure( 409, 'INSUFFICIENT_BALANCE' ) );
		$this->thrown( $this->redeem() );
		$this->assertArrayNotHasKey( Redeem::MISSES, $this->session->data );
	}

	public function test_in_the_admin_a_rewloy_code_is_refused_without_a_call(): void {
		$this->admin = true;
		$this->assertSame( 'Rewloy codes are used at the checkout only.', $this->thrown( $this->redeem() ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_one_code_a_card_and_three_codes_an_order(): void {
		$this->script( $this->quote(), $this->quote( array( 'cardId' => 'card-a', 'codeLast4' => 'LAEX' ) ) );
		$first  = strtolower( self::RW );
		$second = 'rw-tqyu-laex';
		$r      = $this->redeem();
		$r->coupon_data( false, $first );
		$this->cart->applied = array( $first );
		$this->assertSame( 'This card is already used on this order.', $this->thrown( $r, $second ) );

		$codes = array( RedeemCodeTest::code( 'BBBBBBB' ), RedeemCodeTest::code( 'CCCCCCC' ), RedeemCodeTest::code( 'DDDDDDD' ), RedeemCodeTest::code( 'EEEEEEE' ) );
		$this->session->data = array();
		$this->script( $this->quote( array( 'cardId' => 'b' ) ), $this->quote( array( 'cardId' => 'c' ) ), $this->quote( array( 'cardId' => 'd' ) ), $this->quote( array( 'cardId' => 'e' ) ) );
		$r = $this->redeem();
		foreach ( $codes as $c ) {
			$r->coupon_data( false, strtolower( $c ) );
		}
		$this->cart->applied = array_map( 'strtolower', array_slice( $codes, 0, 3 ) );
		$this->assertSame( 'An order can use at most 3 Rewloy codes.', $this->thrown( $r, $codes[3] ) );
		$this->assertSame( '', $this->thrown( $r, $codes[2] ), 'the third itself is fine' );
	}

	public function test_prices_without_tax_get_a_coupon_of_the_value_without_the_carts_tax(): void {
		$this->taxIncl            = false;
		$this->cart->subtotal     = 400.0;
		$this->cart->subtotal_tax = 80.0;
		$this->script( $this->quote( array( 'maxMinor' => 4800 ) ) );
		$this->assertSame( 40.0, $this->redeem()->coupon_data( false, strtolower( self::RW ) )['amount'] );
	}

	public function test_what_a_value_coupon_saves_is_never_more_than_the_code_holds(): void {
		$this->script( $this->quote() );
		$r                   = $this->redeem();
		$code                = strtolower( self::RW );
		$this->cart->applied = array( $code );
		$r->coupon_data( false, $code );
		$this->cart->saved[ $code ] = 40.07;
		$this->cart->on_calculate   = function ( \FakeCart $cart ) use ( $r, $code ): void {
			// WooCommerce builds the coupon again on every calculation: the lowered amount is what it gets.
			$cart->saved[ $code ] = $r->coupon_data( false, $code )['amount'];
			$r->cap_discounts( $cart );
		};
		$r->cap_discounts( $this->cart );
		$this->assertSame( 1, $this->cart->recalculated );
		$this->assertLessThanOrEqual( 40.0, $this->cart->saved[ $code ] );
		$this->cart->saved[ $code ] = 40.0;
		$r->cap_discounts( $this->cart );
		$this->assertSame( 1, $this->cart->recalculated, 'within the value: nothing to do' );
	}

	public function test_the_shopper_is_an_opaque_hash_sent_only_when_asked(): void {
		$this->script( $this->quote(), $this->quote() );
		$this->session->customer = 'cust-xyz';
		$settings = $this->connected();
		$settings->save_api_key( self::PLUGIN_KEY );
		( new Redeem( $settings, $this->factory(), fn() => $this->session, fn() => $this->cart, fn() => $this->now, true ) )->coupon_data( false, strtolower( self::RW ) );
		$sent = json_decode( $this->requests[0]['args']['body'], true )['shopper'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $sent );
		$this->assertStringNotContainsString( 'cust-xyz', $sent );
		$this->session->data = array();
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		$this->assertArrayNotHasKey( 'shopper', json_decode( $this->requests[1]['args']['body'], true ) );
	}

	public function test_the_code_is_never_written_whole_in_the_session_values(): void {
		$this->script( $this->quote() );
		$this->redeem()->coupon_data( false, strtolower( self::RW ) );
		// The session keys the quote by the code (WooCommerce keeps the code in the cart anyway); no value repeats it.
		$this->assertStringNotContainsString( 'XV5C', json_encode( array_values( $this->session->data[ Redeem::SESSION ] ) ) );
	}
}
