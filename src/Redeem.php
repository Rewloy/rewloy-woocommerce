<?php
/**
 * A Rewloy card at the checkout, the cart's side (0.4.0, Rewloy ADR 179, docs/CHECKOUT-CARDS.md §10).
 *
 * The customer mints a one-time code on their card (`RW-7K3M-Q9TX`) and types it into WooCommerce's own coupon field,
 * which both the classic and the block checkout have. This class answers that code as a virtual coupon:
 *
 *  - `woocommerce_get_shop_coupon_data`: a code that looks like a Rewloy code is asked of Rewloy (`quote`) and becomes a
 *    coupon: a cashback card's or a money coupon's value a `fixed_cart` discount, a discount card's (or a percent
 *    coupon's) a `percent` one; a stamp, points or VIP card a coupon of nothing that ties the order to the card. A value
 *    the shop's setting treats as a PAYMENT (a gift card by default, `tax: payment`) carries no amount on the coupon:
 *    it is a negative, non-taxable fee (`woocommerce_cart_calculate_fees`), after tax, so the order's KDV stays as it is.
 *  - `woocommerce_coupon_is_valid`: Rewloy's refusal, in the shop's language; at most three codes an order, one a card.
 *  - The quote is kept in the WooCommerce session for five minutes (WooCommerce builds a coupon many times a request);
 *    only a successful one, so nothing is ever applied from a guessed or stale answer. A code held for an order stays
 *    answered for that order (Rewloy would now call it used).
 *  - A typo is caught by the check character, without a call; five refused codes in ten minutes stop a session from
 *    asking again for a while (Rewloy has its own budget per shop).
 *  - The shop's own coupon of the same name wins; with the shop not connected no Rewloy code is answered at all; in the
 *    WordPress admin (applying a coupon to an order by hand) a Rewloy code is refused without a call.
 *
 * Nothing here takes value: the hold, at the order, is Holds'. The code never goes into a note, a log, an exception or
 * an option; the session keeps the quote under the normalised code until the cart is emptied.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Redeem {

	/** The session key of the quotes: normalised code => {q: quote, at: time, order: order id once held}. */
	public const SESSION = 'rewloy_wc_codes';
	/** The session key of the refused codes' times (the session's own budget). */
	public const MISSES = 'rewloy_wc_code_misses';
	/** Seconds a quote is reused for. */
	public const CACHE_TTL = 300;
	/** Refused codes a session may type in MISS_WINDOW seconds. */
	public const MISS_LIMIT  = 5;
	public const MISS_WINDOW = 600;
	/** Rewloy codes in one order (Rewloy's own limit). */
	public const MAX_CODES = 3;
	/** Seconds one call may take inside the customer's request. */
	public const TIMEOUT = 8.0;
	/** The id of a gift card's fee line starts with this, then the code's ref. */
	public const FEE_PREFIX = 'rewloy-';
	/** The meta on an order's fee line that names its code (RedeemCode::ref, never the code). */
	public const META_FEE = '_rewloy_code_ref';

	/** Errors that are the plugin's own, besides Rewloy's codes. */
	public const E_UNREACHABLE = 'UNREACHABLE';
	public const E_TOO_MANY    = 'TOO_MANY_TRIES';
	public const E_ADMIN       = 'NOT_AT_CHECKOUT';

	/** Refusals that count against the session's budget: a code that is not (or no longer) the customer's to use. */
	private const MISS_CODES = array( 'CODE_INVALID', 'CODE_EXPIRED', 'CODE_USED' );

	/** @var callable(string=): ?Client */
	private $client_factory;
	/** @var callable(): ?object WooCommerce's session (get/set), or null outside a shopper's request. */
	private $session;
	/** @var callable(): ?object WooCommerce's cart, or null. */
	private $cart;
	/** @var callable(): int */
	private $now;

	/** @var array<string,array{quote?:array<string,mixed>,error?:string,currency?:string}> This request's answers, by normalised code (or `typo:` + the code). */
	private array $memo = array();
	/** @var array<string,bool> This request's look-ups of the shop's own coupons, by code. */
	private array $shop_coupons = array();
	/** @var array<string,float> A value coupon's amount without tax (prices entered without tax), by normalised code. */
	private array $net = array();
	/** How deep cap_discounts() has recalculated the totals in this request (at most three passes). */
	private int $passes = 0;

	/**
	 * @param Settings                    $settings       The saved settings.
	 * @param callable(string=): ?Client  $client_factory A client for the saved key; null without one.
	 * @param (callable(): ?object)|null  $session        WooCommerce's session (tests pass a double).
	 * @param (callable(): ?object)|null  $cart           WooCommerce's cart (tests pass a double).
	 * @param (callable(): int)|null      $now            The clock (tests).
	 * @param bool                        $send_shopper   Whether the quote carries `shopper`, an opaque hash of the shopper
	 *                                                    for Rewloy's per-shopper limits (a Rewloy that knows the field).
	 */
	public function __construct( private Settings $settings, callable $client_factory, ?callable $session = null, ?callable $cart = null, ?callable $now = null, private bool $send_shopper = false ) {
		$this->client_factory = $client_factory;
		$this->session        = $session ?? static function (): ?object {
			$wc = function_exists( 'WC' ) ? WC() : null;
			return $wc instanceof \WooCommerce && $wc->session instanceof \WC_Session ? $wc->session : null;
		};
		$this->cart           = $cart ?? static function (): ?object {
			$wc = function_exists( 'WC' ) ? WC() : null;
			return $wc instanceof \WooCommerce && $wc->cart instanceof \WC_Cart ? $wc->cart : null;
		};
		$this->now            = $now ?? static fn(): int => time();
	}

	public function register(): void {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'is_valid' ), 10, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_discount_amount_html', array( $this, 'amount_html' ), 10, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ) );
		add_filter( 'woocommerce_cart_totals_get_fees_from_cart_taxes', array( $this, 'fee_taxes' ), 10, 2 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'cap_discounts' ) );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_fee_item' ), 10, 3 );
		add_action( 'woocommerce_order_item_fee_after_calculate_taxes', array( $this, 'fee_item_taxes' ) );
		add_action( 'woocommerce_removed_coupon', array( $this, 'removed' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'emptied' ) );
	}

	/* ---------------------------------------------------------------- the coupon */

	/**
	 * `woocommerce_get_shop_coupon_data`: a Rewloy code becomes a virtual coupon; anything else is left as it was.
	 *
	 * @param mixed $data   What an earlier filter answered (false when none).
	 * @param mixed $code   The coupon code WooCommerce is building (lowercased).
	 * @param mixed $coupon The coupon being built.
	 * @return mixed
	 */
	public function coupon_data( $data, $code = '', $coupon = null ) {
		unset( $coupon );
		if ( false !== $data && null !== $data && '' !== $data ) {
			return $data;
		}
		if ( ! is_string( $code ) || ! RedeemCode::looks_like( $code ) || ! $this->settings->is_connected() || $this->is_shop_coupon( $code ) ) {
			return $data;
		}
		return $this->coupon_array( $this->resolve( $code ), RedeemCode::normalize( $code ) );
	}

	/**
	 * What a code gives, or why not: this request's answer, else the session's, else Rewloy's.
	 *
	 * @return array{quote?:array<string,mixed>,error?:string,currency?:string}
	 */
	public function resolve( string $code ): array {
		$norm = RedeemCode::normalize( $code );
		$key  = '' !== $norm ? $norm : 'typo:' . strtoupper( $code );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$this->memo[ $key ] = $this->answer( $norm );
		return $this->memo[ $key ];
	}

	/**
	 * @return array{quote?:array<string,mixed>,error?:string,currency?:string}
	 */
	private function answer( string $norm ): array {
		if ( is_admin() ) {
			// Applying a coupon to an order by hand: the code is the customer's, at the customer's checkout only.
			return array( 'error' => self::E_ADMIN );
		}
		if ( '' === $norm ) {
			$this->miss();
			return array( 'error' => 'CODE_INVALID' );
		}
		$cached = $this->cached( $norm );
		if ( null !== $cached ) {
			return array( 'quote' => $cached );
		}
		if ( $this->misses() >= self::MISS_LIMIT ) {
			return array( 'error' => self::E_TOO_MANY );
		}
		$client = ( $this->client_factory )();
		$link   = $this->settings->get()['link_id'];
		if ( null === $client || '' === $link ) {
			return array( 'error' => self::E_UNREACHABLE );
		}
		try {
			$q = self::clean_quote( $client->with_timeout( self::TIMEOUT )->quote_code( $link, $norm, self::currency(), $this->send_shopper ? $this->shopper() : '' ) );
		} catch ( RewloyException $e ) {
			if ( $e instanceof ApiError && in_array( $e->api_code, self::MISS_CODES, true ) ) {
				$this->miss();
			}
			$currency = is_string( $e->details['currency'] ?? null ) ? $e->details['currency'] : '';
			return array(
				'error'    => self::error_of( $e ),
				'currency' => $currency,
			);
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return array( 'error' => self::E_UNREACHABLE );
		}
		if ( null === $q ) {
			return array( 'error' => self::E_UNREACHABLE );
		}
		$this->remember( $norm, $q );
		return array( 'quote' => $q );
	}

	/** Which of the plugin's messages a failed call gets: Rewloy's code for a refusal, "unreachable" for anything unclear. */
	public static function error_of( RewloyException $e ): string {
		if ( ! $e instanceof ApiError || $e->outcome_unknown() || 429 === $e->status ) {
			return self::E_UNREACHABLE;
		}
		return '' !== $e->api_code ? $e->api_code : self::E_UNREACHABLE;
	}

	/**
	 * A quote as the plugin keeps it: the documented fields, each of its type; null when the answer is not one.
	 *
	 * @param array<string,mixed> $q
	 * @return array{kind:string,type:string,programId:string,programName:string,currency:string,maxMinor:?int,percent:?int,amountMinor:?int,tax:string,cardId:string,cardLast4:string,codeLast4:string}|null
	 */
	public static function clean_quote( array $q ): ?array {
		$kind = is_string( $q['kind'] ?? null ) ? $q['kind'] : '';
		if ( ! in_array( $kind, array( 'balance', 'percent', 'amount', 'link' ), true ) ) {
			return null;
		}
		$int = static fn( $v ): ?int => is_int( $v ) || ( is_numeric( $v ) && (string) (int) $v === (string) $v ) ? (int) $v : null;
		$str = static fn( $v, int $max ): string => is_string( $v ) ? substr( $v, 0, $max ) : '';
		$out = array(
			'kind'        => $kind,
			'type'        => $str( $q['type'] ?? null, 20 ),
			'programId'   => $str( $q['programId'] ?? null, 36 ),
			'programName' => $str( $q['programName'] ?? null, 120 ),
			'currency'    => strtoupper( $str( $q['currency'] ?? null, 3 ) ),
			'maxMinor'    => $int( $q['maxMinor'] ?? null ),
			'percent'     => $int( $q['percent'] ?? null ),
			'amountMinor' => $int( $q['amountMinor'] ?? null ),
			'tax'         => 'payment' === ( $q['tax'] ?? null ) ? 'payment' : 'discount',
			'cardId'      => $str( $q['cardId'] ?? null, 32 ),
			'cardLast4'   => (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $str( $q['cardLast4'] ?? null, 8 ) ) ),
			'codeLast4'   => (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $str( $q['codeLast4'] ?? null, 8 ) ) ),
		);
		// What the code gives must be there for its kind; a value is a positive whole number of minor units.
		if ( ( 'balance' === $kind && ( $out['maxMinor'] ?? 0 ) < 1 ) || ( 'amount' === $kind && ( $out['amountMinor'] ?? 0 ) < 1 )
			|| ( 'percent' === $kind && ( ( $out['percent'] ?? 0 ) < 1 || ( $out['percent'] ?? 0 ) > 100 ) ) ) {
			return null;
		}
		if ( 'link' === $kind || 'percent' === $kind ) {
			$out['tax'] = 'discount';
		}
		return $out;
	}

	/**
	 * How the code's value reaches the order: `coupon` (a discount before tax), `fee` (a payment after tax: a negative,
	 * non-taxable fee) or `link` (no value).
	 *
	 * @param array<string,mixed> $q A clean quote.
	 */
	public static function mode( array $q ): string {
		if ( 'link' === $q['kind'] ) {
			return 'link';
		}
		return 'payment' === $q['tax'] && in_array( $q['kind'], array( 'balance', 'amount' ), true ) ? 'fee' : 'coupon';
	}

	/**
	 * The most the code may take off this order, in minor units: a money card's available amount (the holder's cap), a
	 * coupon's online value; null for a percent and for a tie.
	 *
	 * @param array<string,mixed> $q A clean quote.
	 */
	public static function value_minor( array $q ): ?int {
		if ( 'balance' === $q['kind'] ) {
			return is_int( $q['maxMinor'] ) ? $q['maxMinor'] : null;
		}
		if ( 'amount' === $q['kind'] ) {
			return is_int( $q['amountMinor'] ) ? $q['amountMinor'] : null;
		}
		return null;
	}

	/**
	 * The coupon WooCommerce builds for an answer.
	 *
	 * @param array{quote?:array<string,mixed>,error?:string,currency?:string} $answer
	 * @param string                                                            $norm   The normalised code.
	 * @return array<string,mixed>
	 */
	private function coupon_array( array $answer, string $norm ): array {
		$coupon = array(
			'discount_type'  => 'fixed_cart',
			'amount'         => 0,
			'individual_use' => false,
			'description'    => 'Rewloy',
		);
		$q = $answer['quote'] ?? null;
		if ( ! is_array( $q ) || 'coupon' !== self::mode( $q ) ) {
			return $coupon;
		}
		if ( 'percent' === $q['kind'] ) {
			$coupon['discount_type'] = 'percent';
			$coupon['amount']        = (int) $q['percent'];
			return $coupon;
		}
		$coupon['amount'] = $this->coupon_amount( $q, $norm );
		return $coupon;
	}

	/**
	 * A value coupon's amount, in the shop's currency units. WooCommerce takes a `fixed_cart` amount as the price is
	 * entered: with tax when prices include tax, without tax otherwise. In the second case the amount is the value
	 * without the cart's tax, so that what the customer saves (discount and its tax) is never more than the code
	 * holds; cap_discounts() checks it after the totals, whatever the rounding did.
	 *
	 * @param array<string,mixed> $q    A clean quote.
	 * @param string              $norm The normalised code.
	 */
	private function coupon_amount( array $q, string $norm ): float {
		$value = (int) self::value_minor( $q );
		if ( isset( $this->net[ $norm ] ) ) {
			return $this->net[ $norm ];
		}
		$amount = $value / 100;
		if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && function_exists( 'wc_prices_include_tax' ) && ! wc_prices_include_tax() ) {
			$cart = ( $this->cart )();
			if ( null !== $cart && method_exists( $cart, 'get_subtotal' ) && method_exists( $cart, 'get_subtotal_tax' ) ) {
				$net = (float) $cart->get_subtotal();
				$tax = (float) $cart->get_subtotal_tax();
				if ( $net > 0 && $tax > 0 ) {
					$amount = floor( $value * $net / ( $net + $tax ) ) / 100;
				}
			}
		}
		return $amount;
	}

	/**
	 * `woocommerce_coupon_is_valid`: Rewloy's refusal as the message both checkouts show; the order's limits.
	 *
	 * @param mixed $valid     Valid so far.
	 * @param mixed $coupon    The coupon.
	 * @param mixed $discounts WooCommerce's discounts object (for an order or the cart).
	 * @return mixed
	 * @throws \Exception The refusal, which WooCommerce shows.
	 */
	public function is_valid( $valid, $coupon = null, $discounts = null ) {
		unset( $discounts );
		if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) ) {
			return $valid;
		}
		$code = (string) $coupon->get_code();
		if ( ! RedeemCode::looks_like( $code ) || ! $this->settings->is_connected() || $this->is_shop_coupon( $code ) ) {
			return $valid;
		}
		$answer = $this->resolve( $code );
		if ( isset( $answer['error'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce escapes a coupon's error where it shows it.
			throw new \Exception( RedeemWords::refusal( $answer['error'], $answer['currency'] ?? '' ) );
		}
		$q    = $answer['quote'] ?? array();
		$norm = RedeemCode::normalize( $code );
		$n    = 1;
		foreach ( $this->applied() as $other ) {
			$other_norm = RedeemCode::normalize( $other );
			if ( '' === $other_norm || $other_norm === $norm ) {
				continue;
			}
			++$n;
			$oq = $this->cached( $other_norm );
			if ( null !== $oq && '' !== $q['cardId'] && $oq['cardId'] === $q['cardId'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce escapes a coupon's error where it shows it.
				throw new \Exception( RedeemWords::refusal( 'CARD_IN_ORDER' ) );
			}
		}
		if ( $n > self::MAX_CODES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce escapes a coupon's error where it shows it.
			throw new \Exception( RedeemWords::refusal( 'ORDER_CODES_LIMIT' ) );
		}
		return $valid;
	}

	/**
	 * The Rewloy codes in the cart now, as WooCommerce keeps them (lowercased).
	 *
	 * @return list<string>
	 */
	public function applied(): array {
		$cart = ( $this->cart )();
		if ( null === $cart || ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $cart->get_applied_coupons() as $code ) {
			if ( is_string( $code ) && RedeemCode::looks_like( $code ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Classic checkout: the coupon line reads "Rewloy: <card>" rather than "Coupon: rw-….".
	 *
	 * @param mixed $label  WooCommerce's label.
	 * @param mixed $coupon The coupon.
	 * @return mixed
	 */
	public function label( $label, $coupon = null ) {
		$q = $this->quote_of_coupon( $coupon );
		return null === $q ? $label : RedeemWords::line_label( $q['programName'] );
	}

	/**
	 * The coupon line's amount: a payment is on its own line below, a tie gives no discount, both say so.
	 *
	 * @param mixed $html   WooCommerce's amount.
	 * @param mixed $coupon The coupon.
	 * @return mixed
	 */
	public function amount_html( $html, $coupon = null ) {
		$q = $this->quote_of_coupon( $coupon );
		if ( null === $q ) {
			return $html;
		}
		$mode = self::mode( $q );
		if ( 'fee' === $mode ) {
			return esc_html( RedeemWords::paid_below() );
		}
		if ( 'link' === $mode ) {
			return esc_html( RedeemWords::tie_line() );
		}
		return $html;
	}

	/**
	 * The quote of a Rewloy coupon in the cart, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function quote_of_coupon( mixed $coupon ): ?array {
		if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) ) {
			return null;
		}
		$code = (string) $coupon->get_code();
		if ( ! RedeemCode::looks_like( $code ) || ! $this->settings->is_connected() ) {
			return null;
		}
		$norm = RedeemCode::normalize( $code );
		if ( '' === $norm ) {
			return null;
		}
		$answer = $this->memo[ $norm ] ?? null;
		return is_array( $answer['quote'] ?? null ) ? $answer['quote'] : $this->cached( $norm );
	}

	/* ---------------------------------------------------------------- the payment */

	/**
	 * `woocommerce_cart_calculate_fees`: a code whose value is a payment (a gift card by default) is a negative,
	 * non-taxable fee of what the code may take. WooCommerce caps a negative fee so the total does not go below zero;
	 * the hold takes the fee's final amount from the order.
	 *
	 * @param mixed $cart The cart.
	 */
	public function add_fees( $cart = null ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_applied_coupons' ) || ! $this->settings->is_connected() ) {
			return;
		}
		foreach ( (array) $cart->get_applied_coupons() as $code ) {
			if ( ! is_string( $code ) || ! RedeemCode::looks_like( $code ) || $this->is_shop_coupon( $code ) ) {
				continue;
			}
			$norm = RedeemCode::normalize( $code );
			$q    = '' === $norm ? null : ( $this->resolve( $code )['quote'] ?? null );
			if ( ! is_array( $q ) || 'fee' !== self::mode( $q ) ) {
				continue;
			}
			$value = (int) self::value_minor( $q );
			if ( $value < 1 ) {
				continue;
			}
			$fee = array(
				'id'        => self::FEE_PREFIX . RedeemCode::ref( $norm ),
				'name'      => RedeemWords::line_label( $q['programName'] ),
				'amount'    => -1 * $value / 100,
				'taxable'   => false,
				'tax_class' => '',
			);
			if ( method_exists( $cart, 'fees_api' ) ) {
				$cart->fees_api()->add_fee( $fee );
			} elseif ( method_exists( $cart, 'add_fee' ) ) {
				$cart->add_fee( $fee['name'], $fee['amount'], false );
			}
		}
	}

	/**
	 * `woocommerce_cart_totals_get_fees_from_cart_taxes`: WooCommerce splits a negative fee's tax over the cart, which
	 * makes it a discount (the KDV goes down). A Rewloy payment carries no tax: the goods' KDV stays as it is.
	 *
	 * @param mixed $taxes The fee's taxes.
	 * @param mixed $fee   The fee as WooCommerce's totals hold it (`$fee->object->id`).
	 * @return mixed
	 */
	public function fee_taxes( $taxes, $fee = null ) {
		$id = is_object( $fee ) && isset( $fee->object ) && is_object( $fee->object ) && isset( $fee->object->id ) ? (string) $fee->object->id : '';
		return str_starts_with( $id, self::FEE_PREFIX ) ? array() : $taxes;
	}

	/**
	 * `woocommerce_checkout_create_order_fee_item`: the fee line on the order is named for its code (its ref, never the
	 * code), so the hold reads the right amount.
	 *
	 * @param mixed $item    The order's fee line.
	 * @param mixed $fee_key The cart fee's id.
	 * @param mixed $fee     The cart fee.
	 */
	public function tag_fee_item( $item, $fee_key = '', $fee = null ): void {
		unset( $fee );
		if ( is_object( $item ) && method_exists( $item, 'add_meta_data' ) && is_string( $fee_key ) && str_starts_with( $fee_key, self::FEE_PREFIX ) ) {
			$item->add_meta_data( self::META_FEE, substr( $fee_key, strlen( self::FEE_PREFIX ) ), true );
		}
	}

	/**
	 * `woocommerce_order_item_fee_after_calculate_taxes`: an order works its taxes out again (the block checkout does it
	 * as it makes the order, the admin's "Recalculate" too) and splits a negative fee's tax over the items, as the cart
	 * does. A Rewloy payment line carries none there either, so the order is what the customer saw: same total, same KDV.
	 *
	 * @param mixed $item The order's fee line.
	 */
	public function fee_item_taxes( $item = null ): void {
		if ( $item instanceof \WC_Order_Item_Fee && '' !== (string) $item->get_meta( self::META_FEE ) ) {
			$item->set_taxes( array() );
		}
	}

	/**
	 * `woocommerce_after_calculate_totals`: what a value coupon saves the customer (its discount and that discount's
	 * tax) is never more than the code may take. Prices entered without tax and the cart's rounding can push it over by
	 * a little; then the coupon's amount is lowered by that much and the totals are worked out once more.
	 *
	 * @param mixed $cart The cart.
	 */
	public function cap_discounts( $cart = null ): void {
		if ( $this->passes >= 3 || ! is_object( $cart ) || ! method_exists( $cart, 'get_coupon_discount_amount' ) ) {
			return;
		}
		$again = false;
		foreach ( $this->applied() as $code ) {
			$norm = RedeemCode::normalize( $code );
			$q    = '' === $norm ? null : ( $this->memo[ $norm ]['quote'] ?? $this->cached( $norm ) );
			if ( ! is_array( $q ) || 'coupon' !== self::mode( $q ) || 'percent' === $q['kind'] ) {
				continue;
			}
			$value = (int) self::value_minor( $q );
			$saved = (int) round( 100 * (float) $cart->get_coupon_discount_amount( $code, false ) );
			if ( $saved <= $value ) {
				continue;
			}
			$amount = $this->net[ $norm ] ?? $this->coupon_amount( $q, $norm );
			$lower  = floor( 100 * $amount * $value / $saved ) / 100;
			// At least a kuruş less each time, so the rounding cannot keep it where it was.
			$this->net[ $norm ] = max( 0.0, min( $lower, $amount - 0.01 ) );
			$again              = true;
		}
		if ( $again && method_exists( $cart, 'calculate_totals' ) ) {
			++$this->passes;
			try {
				$cart->calculate_totals();
			} finally {
				--$this->passes;
			}
		}
	}

	/* ---------------------------------------------------------------- the session */

	/**
	 * A successful quote of this session that is still good: five minutes, or as long as an order holds the code.
	 *
	 * @return array<string,mixed>|null
	 */
	public function cached( string $norm ): ?array {
		$all = $this->stored();
		$row = $all[ $norm ] ?? null;
		if ( ! is_array( $row ) || ! is_array( $row['q'] ?? null ) ) {
			return null;
		}
		$q = self::clean_quote( $row['q'] );
		if ( null === $q ) {
			return null;
		}
		$held = (int) ( $row['order'] ?? 0 ) > 0;
		return $held || ( (int) ( $row['at'] ?? 0 ) + self::CACHE_TTL ) >= ( $this->now )() ? $q : null;
	}

	/**
	 * The quote kept for an order's code, or asked again (a code bound to this shop and not yet on an order is still
	 * answered by Rewloy inside its 45 minutes). Null with the error when there is none.
	 *
	 * @return array{quote?:array<string,mixed>,error?:string,currency?:string}
	 */
	public function for_order( string $norm ): array {
		$q = $this->memo[ $norm ]['quote'] ?? $this->cached( $norm );
		if ( is_array( $q ) ) {
			return array( 'quote' => $q );
		}
		unset( $this->memo[ $norm ] );
		return $this->resolve( $norm );
	}

	/** The code is held for this order now: its quote stays good for the order (Rewloy would call it used). */
	public function mark_held( string $norm, int $order_id ): void {
		$all = $this->stored();
		if ( isset( $all[ $norm ] ) && is_array( $all[ $norm ] ) ) {
			$all[ $norm ]['order'] = $order_id;
			$this->store( $all );
		}
	}

	/** Forgets a code (refused at the order, or removed from the cart): typed again, it is asked afresh. */
	public function forget( string $norm ): void {
		unset( $this->memo[ $norm ] );
		$all = $this->stored();
		if ( isset( $all[ $norm ] ) ) {
			unset( $all[ $norm ] );
			$this->store( $all );
		}
	}

	/**
	 * `woocommerce_removed_coupon`: a removed code is asked afresh when typed again, unless an order of this session
	 * already holds it (then its quote is the order's).
	 *
	 * @param mixed $code The removed code.
	 */
	public function removed( $code = '' ): void {
		$norm = is_string( $code ) ? RedeemCode::normalize( $code ) : '';
		if ( '' === $norm ) {
			return;
		}
		$row = $this->stored()[ $norm ] ?? null;
		if ( is_array( $row ) && (int) ( $row['order'] ?? 0 ) > 0 ) {
			return;
		}
		$this->forget( $norm );
	}

	/** `woocommerce_cart_emptied` (after an order is placed, or by hand): the session keeps no code. */
	public function emptied(): void {
		$this->memo = array();
		$this->store( array() );
	}

	/**
	 * @param array<string,mixed> $q A clean quote.
	 */
	private function remember( string $norm, array $q ): void {
		$all          = $this->stored();
		$all[ $norm ] = array(
			'q'  => $q,
			'at' => ( $this->now )(),
		);
		$this->store( $all );
	}

	/** @return array<string,mixed> */
	private function stored(): array {
		$session = ( $this->session )();
		$all     = null !== $session && method_exists( $session, 'get' ) ? $session->get( self::SESSION ) : null;
		return is_array( $all ) ? $all : array();
	}

	/** @param array<string,mixed> $all */
	private function store( array $all ): void {
		$session = ( $this->session )();
		if ( null !== $session && method_exists( $session, 'set' ) ) {
			$session->set( self::SESSION, array() === $all ? null : $all );
		}
	}

	/** One more refused code in this session's window. */
	private function miss(): void {
		$session = ( $this->session )();
		if ( null === $session || ! method_exists( $session, 'set' ) ) {
			return;
		}
		$times   = $this->miss_times();
		$times[] = ( $this->now )();
		$session->set( self::MISSES, array_slice( $times, -20 ) );
	}

	/** How many codes this session had refused in the window. */
	private function misses(): int {
		return count( $this->miss_times() );
	}

	/** @return list<int> */
	private function miss_times(): array {
		$session = ( $this->session )();
		$raw     = null !== $session && method_exists( $session, 'get' ) ? $session->get( self::MISSES ) : null;
		$since   = ( $this->now )() - self::MISS_WINDOW;
		$out     = array();
		foreach ( is_array( $raw ) ? $raw : array() as $t ) {
			if ( is_int( $t ) && $t > $since ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * An opaque, stable name of the shopper for Rewloy's per-shopper limits (30 quotes in 10 minutes, 10 invalid codes an
	 * hour): HMAC-SHA256 of the WooCommerce customer id (a logged-in user's id, else the session's own key) under the
	 * site's secret, never the id itself. '' without a session.
	 */
	public function shopper(): string {
		$session = ( $this->session )();
		$id      = null !== $session && method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';
		if ( '' === $id ) {
			return '';
		}
		// HMAC-SHA256 under the site's own secret (never sent anywhere), hex: Rewloy takes 8 to 64 of [A-Za-z0-9_-].
		return substr( hash_hmac( 'sha256', 'rewloy-shopper:' . $id, (string) wp_salt( 'auth' ) ), 0, 32 );
	}

	/** The shop's currency (the cart's). */
	public static function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'TRY';
	}

	/** Is there a coupon of the shop's own with this code? It wins over a Rewloy code of the same letters. */
	private function is_shop_coupon( string $code ): bool {
		$key = strtolower( $code );
		if ( ! isset( $this->shop_coupons[ $key ] ) ) {
			$this->shop_coupons[ $key ] = function_exists( 'wc_get_coupon_id_by_code' ) && (int) wc_get_coupon_id_by_code( $code ) > 0;
		}
		return $this->shop_coupons[ $key ];
	}
}
