<?php
/**
 * A Rewloy card at the checkout, the order's side (0.4.0, Rewloy ADR 179, docs/CHECKOUT-CARDS.md §10.2, §10.3, §10.6).
 *
 *  - **Hold**, when the order is placed and before any payment (classic `woocommerce_checkout_order_processed`, block
 *    `woocommerce_store_api_checkout_order_processed`): one call per Rewloy code on the order, with what the order
 *    actually got from it (the coupon's discount and its tax, or the payment line's final amount). Any refusal, and any
 *    unclear answer, refuses the checkout: no payment is taken without a hold. A refusal lets go of the order's other
 *    holds; an unclear answer asks for a release (safe whether or not a hold exists) and tries it again later.
 *  - **Capture**, when the order is paid (`processing`, `completed`): at once, in the same request, because Rewloy's own
 *    backstop (the signed order webhook) takes the whole hold when it arrives first. An unclear answer is tried again by
 *    Action Scheduler after 5 minutes, an hour and six hours (D29's delays); the webhook does the same work meanwhile.
 *  - **Release**, when the order is cancelled or failed; **refund**, when it is refunded in full; a partial refund only
 *    writes a note (Rewloy returns nothing automatically, §3.7).
 *  - Each answer is written to the order (`_rewloy_redemptions`: id, the code's ref and last four, the card's last
 *    four, kind, programme, amounts, state) and said in an order note. Rewloy is the record: a repeat of any step is
 *    answered with what the order's codes are now.
 *
 * Holds placed for codes the order no longer carries (a pending order paid again with another code) are let go before
 * the order's codes are held again.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Holds {

	/** The Action Scheduler hook of a step tried again later: (order id, step, attempt). */
	public const HOOK  = 'rewloy_wc_redeem';
	public const GROUP = Issuer::GROUP;

	/** The order's Rewloy codes as Rewloy last answered (a JSON list). */
	public const META = '_rewloy_redemptions';
	/** `unclear` while a hold got no clear answer and its release is pending; removed by a clear hold. */
	public const META_STATE = '_rewloy_redeem_state';

	/** Seconds before each later try of an unclear step: 5 minutes, an hour, six hours. */
	public const RETRY_DELAYS = array( 300, 3600, 21_600 );
	/** Seconds one attempt may take when a step runs inside an order's status change. */
	public const STEP_TIMEOUT = 5.0;

	/** States after which a redemption changes no more by the plugin's hand. */
	private const SETTLED = array( 'captured', 'refunded', 'unbacked' );

	/** @var callable(string=): ?Client */
	private $client_factory;
	/** @var callable(int, int, string, int): bool Schedules (when, order id, step, attempt); true when scheduled. */
	private $scheduler;
	/** @var callable(int, string): void Cancels pending tries of a step of an order. */
	private $unscheduler;

	/**
	 * @param Settings                                     $settings       The saved settings.
	 * @param callable(string=): ?Client                   $client_factory A client for the saved key.
	 * @param Redeem                                       $redeem         The cart's side (the quotes of the session).
	 * @param (callable(int, int, string, int): bool)|null $scheduler      Action Scheduler's single action (tests).
	 * @param (callable(int, string): void)|null           $unscheduler    Action Scheduler's unschedule (tests).
	 */
	public function __construct( private Settings $settings, callable $client_factory, private Redeem $redeem, ?callable $scheduler = null, ?callable $unscheduler = null ) {
		$this->client_factory = $client_factory;
		$this->scheduler      = $scheduler ?? static function ( int $when, int $order_id, string $step, int $attempt ): bool {
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return false;
			}
			return (int) as_schedule_single_action( $when, self::HOOK, array( $order_id, $step, $attempt ), self::GROUP ) > 0;
		};
		$this->unscheduler    = $unscheduler ?? static function ( int $order_id, string $step ): void {
			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				return;
			}
			for ( $attempt = 0; $attempt <= count( self::RETRY_DELAYS ); $attempt++ ) {
				as_unschedule_all_actions( self::HOOK, array( $order_id, $step, $attempt ), self::GROUP );
			}
		};
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_block' ), 10, 1 );
		add_action( 'woocommerce_pre_payment_complete', array( $this, 'on_payment_complete' ), 5, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ), 5, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_cancelled' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_failed' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'on_partial_refund' ), 10, 2 );
		add_action( self::HOOK, array( $this, 'run_queued' ), 10, 3 );
	}

	/* ---------------------------------------------------------------- the hold */

	/**
	 * The classic checkout placed the order; payment comes next. A refusal is thrown: WooCommerce shows it and takes no
	 * payment.
	 *
	 * @param mixed $order_id The order's id.
	 * @param mixed $posted   The posted checkout data.
	 * @param mixed $order    The order.
	 * @throws \Exception The refusal.
	 */
	public function on_classic( $order_id, $posted = array(), $order = null ): void {
		unset( $posted );
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->hold( $order );
		}
	}

	/**
	 * The block checkout placed the order; payment comes next. A refusal is a RouteException, which the Store API answers
	 * as an error the checkout shows; no payment is taken.
	 *
	 * @param mixed $order The order.
	 * @throws \Exception The refusal.
	 */
	public function on_block( $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		try {
			$this->hold( $order );
		} catch ( \Exception $e ) {
			$route = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
			if ( class_exists( $route ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the Store API escapes an error's message.
				throw new $route( 'rewloy_code_refused', $e->getMessage(), 400 );
			}
			throw $e;
		}
	}

	/**
	 * Holds every Rewloy code of the order, or refuses the order.
	 *
	 * @throws \Exception The customer's message, when the checkout must not go on.
	 */
	public function hold( \WC_Order $order ): void {
		$codes = $this->codes_of( $order );
		$prev  = self::read( $order );
		if ( array() === $codes && ! self::any_held( $prev ) ) {
			return;
		}
		$link   = $this->settings->get()['link_id'];
		$client = '' !== $link ? ( $this->client_factory )() : null;
		if ( null === $client ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown escaped by WooCommerce.
			throw new \Exception( RedeemWords::unreachable() );
		}
		$client   = $client->with_timeout( Redeem::TIMEOUT );
		$id       = $order->get_id();
		$currency = strtoupper( (string) $order->get_currency() );
		$refs     = array();
		foreach ( array_keys( $codes ) as $norm ) {
			$refs[ RedeemCode::ref( $norm ) ] = true;
		}
		( $this->unscheduler )( $id, 'release' );

		// A hold of a code this order no longer carries goes first; the order's own codes are held again below.
		$stale = false;
		foreach ( $prev as $r ) {
			if ( 'held' === $r['state'] && ! isset( $refs[ $r['ref'] ] ) ) {
				$stale = true;
			}
		}
		if ( $stale ) {
			$this->release_now( $order, $client, $link, 'shop' );
			$prev = self::read( $order );
		}

		$held  = array();
		$total = $this->total_before_discounts( $order );
		foreach ( $codes as $norm => $unused ) {
			$answer = $this->redeem->for_order( $norm );
			if ( 'CODE_USED' === ( $answer['error'] ?? '' ) ) {
				// Rewloy calls a code used once it is on an order, this one too: a code this order already holds (its
				// session's quote gone) is held again from what the order recorded of it.
				$answer = $this->recorded_quote( $order, $norm, $prev ) ?? $answer;
			}
			if ( isset( $answer['error'] ) ) {
				$this->refuse( $order, $client, $link, $held, $norm, (string) $answer['error'], (string) ( $answer['currency'] ?? '' ) );
			}
			$q      = (array) ( $answer['quote'] ?? array() );
			$amount = $this->applied_minor( $order, $norm, $q );
			if ( 'balance' === $q['kind'] && $amount < 1 ) {
				// The order took nothing from it (a fee WooCommerce capped to zero): there is nothing to hold.
				continue;
			}
			try {
				$r = $client->hold_code( $link, (string) $id, $norm, $currency, $amount, $total );
			} catch ( RewloyException $e ) {
				if ( $e instanceof ApiError && ! $e->outcome_unknown() && 429 !== $e->status ) {
					$this->refuse( $order, $client, $link, $held, $norm, '' !== $e->api_code ? $e->api_code : 'REFUSED', is_string( $e->details['currency'] ?? null ) ? $e->details['currency'] : '' );
				}
				$this->unclear( $order );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
				$this->unclear( $order );
			}
			$clean = self::clean( $r );
			if ( null === $clean ) {
				$this->unclear( $order );
			}
			CheckoutSettings::remember( $r );
			$clean['ref']  = RedeemCode::ref( $norm );
			$held[ $norm ] = $clean;
			$this->redeem->mark_held( $norm, $id );
		}

		$this->write( $order, array_values( $held ), $prev, 'held' );
		$order->delete_meta_data( self::META_STATE );
		$order->save_meta_data();
	}

	/**
	 * The order's Rewloy codes: normalised code => true, from its coupon lines.
	 *
	 * @return array<string,true>
	 */
	public function codes_of( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$norm = RedeemCode::normalize( (string) $item->get_code() );
			if ( '' !== $norm && ! ( function_exists( 'wc_get_coupon_id_by_code' ) && (int) wc_get_coupon_id_by_code( (string) $item->get_code() ) > 0 ) ) {
				$out[ $norm ] = true;
			}
		}
		return $out;
	}

	/**
	 * The order's total before its discounts, in minor units (Rewloy's `orderTotalMinor`: no code's amount may be
	 * above it): what is paid, plus the coupon discounts and their tax, plus the Rewloy payment lines.
	 */
	public function total_before_discounts( \WC_Order $order ): int {
		$total = (float) $order->get_total() + (float) $order->get_discount_total() + (float) $order->get_discount_tax();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Fee && '' !== (string) $item->get_meta( Redeem::META_FEE ) ) {
				$total += abs( (float) $item->get_total() );
			}
		}
		return (int) round( 100 * $total );
	}

	/**
	 * A quote rebuilt from what this order recorded of the code (kind, card), with the payment mode read from the
	 * order's own fee line; null when the order has no record of it.
	 *
	 * @param list<array<string,mixed>> $prev The order's recorded redemptions.
	 * @return array{quote:array<string,mixed>}|null
	 */
	private function recorded_quote( \WC_Order $order, string $norm, array $prev ): ?array {
		$ref = RedeemCode::ref( $norm );
		foreach ( $prev as $r ) {
			if ( $ref !== $r['ref'] ) {
				continue;
			}
			$fee = false;
			foreach ( $order->get_items( 'fee' ) as $item ) {
				if ( $item instanceof \WC_Order_Item_Fee && $ref === (string) $item->get_meta( Redeem::META_FEE ) ) {
					$fee = true;
				}
			}
			return array(
				'quote' => array(
					'kind'        => $r['kind'],
					'type'        => $r['type'],
					'programName' => $r['programName'],
					'tax'         => $fee ? 'payment' : 'discount',
					'cardId'      => '',
					'cardLast4'   => $r['cardLast4'],
				),
			);
		}
		return null;
	}

	/**
	 * What the order actually got from a code, in minor units: the payment line's final amount (WooCommerce may have
	 * capped it), or the coupon line's discount with its tax (what the customer saved); 0 for a tie.
	 *
	 * @param array<string,mixed> $q The code's quote.
	 */
	public function applied_minor( \WC_Order $order, string $norm, array $q ): int {
		$mode = Redeem::mode( $q );
		if ( 'link' === $mode ) {
			return 0;
		}
		if ( 'fee' === $mode ) {
			$ref = RedeemCode::ref( $norm );
			foreach ( $order->get_items( 'fee' ) as $item ) {
				if ( $item instanceof \WC_Order_Item_Fee && $ref === (string) $item->get_meta( Redeem::META_FEE ) ) {
					return (int) round( 100 * abs( (float) $item->get_total() ) );
				}
			}
			return 0;
		}
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Coupon && RedeemCode::normalize( (string) $item->get_code() ) === $norm ) {
				return (int) round( 100 * ( (float) $item->get_discount() + (float) $item->get_discount_tax() ) );
			}
		}
		return 0;
	}

	/**
	 * A refusal at the order: the holds this order already has are let go (the customer will remove the code, or pay
	 * with another), the code is asked afresh next time, and the checkout stops with the customer's message.
	 *
	 * @param array<string,array<string,mixed>> $held The holds made in this call.
	 * @throws \Exception Always.
	 */
	private function refuse( \WC_Order $order, Client $client, string $link, array $held, string $norm, string $code, string $currency ): never {
		$this->redeem->forget( $norm );
		if ( array() !== $held || self::any_held( self::read( $order ) ) ) {
			try {
				$this->release_now( $order, $client, $link, 'shop' );
			} catch ( RewloyException $e ) {
				unset( $e );
				$this->schedule( $order->get_id(), 'release', 0 );
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown escaped by WooCommerce.
		throw new \Exception( RedeemWords::refusal( $code, $currency ) );
	}

	/**
	 * No clear answer to a hold: the checkout is refused, and a release is asked for (now in the background, then again
	 * later), which is harmless if no hold was made. The order remembers it until a clear hold.
	 *
	 * @throws \Exception Always.
	 */
	private function unclear( \WC_Order $order ): never {
		$order->update_meta_data( self::META_STATE, 'unclear' );
		$order->save_meta_data();
		$order->add_order_note( RedeemWords::unclear_hold_note(), 0 );
		if ( ! ( $this->scheduler )( time() + 60, $order->get_id(), 'release', 0 ) ) {
			$this->run( $order->get_id(), 'release', 0 );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown escaped by WooCommerce.
		throw new \Exception( RedeemWords::unreachable() );
	}

	/* ---------------------------------------------------------------- after the order */

	/**
	 * The order is paid: take what it holds, now.
	 *
	 * @param mixed $order_id The order's id.
	 * @param mixed $order    The order (not trusted: read again).
	 */
	public function on_paid( $order_id, $order = null ): void {
		unset( $order );
		$this->step_now( (int) $order_id, 'capture' );
	}

	/**
	 * A gateway says the payment is complete (`woocommerce_pre_payment_complete`), before the order is marked paid:
	 * the hold is taken now, before the paid status (and the order webhook it sends) can take it (ADR 179's review: the
	 * webhook takes the whole hold). Orders marked paid by hand, or by a gateway that skips payment_complete(), are taken
	 * by on_paid().
	 *
	 * @param mixed $order_id The order's id.
	 */
	public function on_payment_complete( $order_id ): void {
		$order = (int) $order_id > 0 ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof \WC_Order || array() === self::read( $order ) ) {
			return;
		}
		$this->run( (int) $order_id, 'capture', 0, true );
	}

	/**
	 * @param mixed $order_id The order's id.
	 * @param mixed $order    The order (not trusted: read again).
	 */
	public function on_cancelled( $order_id, $order = null ): void {
		unset( $order );
		$this->step_now( (int) $order_id, 'release' );
	}

	/**
	 * @param mixed $order_id The order's id.
	 * @param mixed $order    The order (not trusted: read again).
	 */
	public function on_failed( $order_id, $order = null ): void {
		unset( $order );
		$this->step_now( (int) $order_id, 'release' );
	}

	/**
	 * @param mixed $order_id The order's id.
	 * @param mixed $order    The order (not trusted: read again).
	 */
	public function on_refunded( $order_id, $order = null ): void {
		unset( $order );
		$this->step_now( (int) $order_id, 'refund' );
	}

	/**
	 * A partial refund changes nothing on the card (§3.7); the note says so, once per refund, when a card paid.
	 *
	 * @param mixed $order_id  The order's id.
	 * @param mixed $refund_id The refund's id.
	 */
	public function on_partial_refund( $order_id, $refund_id = 0 ): void {
		unset( $refund_id );
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( self::read( $order ) as $r ) {
			if ( 'balance' === $r['kind'] && 'captured' === $r['state'] ) {
				$order->add_order_note( RedeemWords::partial_refund_note(), 0 );
				return;
			}
		}
	}

	/** A step right away, in the request that changed the order; an unclear answer is tried again later. */
	private function step_now( int $order_id, string $step ): void {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || array() === self::read( $order ) ) {
			return;
		}
		$this->run( $order_id, $step, 0 );
	}

	/**
	 * Action Scheduler's callback: run(), which returns what happened for tests.
	 *
	 * @param mixed $order_id The order's id.
	 * @param mixed $step     The step.
	 * @param mixed $attempt  How many tries came before.
	 */
	public function run_queued( $order_id, $step = 'capture', $attempt = 0 ): void {
		$this->run( $order_id, $step, $attempt );
	}

	/**
	 * One try of a step (also Action Scheduler's callback): `capture`, `release` or `refund`. Each first reads the order
	 * again and does nothing when the order has moved on (a cancelled order paid again is not released; a release asked
	 * for an unclear hold is not made once a clear hold came).
	 *
	 * @param mixed $order_id The order's id.
	 * @param mixed $step     The step.
	 * @param mixed $attempt  How many tries came before (0 for the first).
	 * @param bool  $paid     True when a gateway has just said the order is paid (its status is not yet).
	 * @return string What happened, for tests: done, skipped, retry, gave-up, refused, no-order.
	 */
	public function run( $order_id, $step = 'capture', $attempt = 0, bool $paid = false ): string {
		$id    = (int) $order_id;
		$order = $id > 0 ? wc_get_order( $id ) : false;
		$step  = is_string( $step ) ? $step : '';
		if ( ! $order instanceof \WC_Order ) {
			return 'no-order';
		}
		$link   = $this->settings->get()['link_id'];
		$client = '' !== $link ? ( $this->client_factory )() : null;
		if ( null === $client || ! $this->due( $order, $step, $paid ) ) {
			return 'skipped';
		}
		$client = $client->with_timeout( self::STEP_TIMEOUT );
		try {
			switch ( $step ) {
				case 'capture':
					$this->capture( $order, $client, $link );
					break;
				case 'release':
					$this->release_now( $order, $client, $link, $this->release_reason( $order ) );
					$order->delete_meta_data( self::META_STATE );
					$order->save_meta_data();
					break;
				case 'refund':
					$this->refund( $order, $client, $link );
					break;
				default:
					return 'skipped';
			}
		} catch ( RewloyException $e ) {
			if ( $e instanceof ApiError && ! $e->outcome_unknown() && 429 !== $e->status ) {
				$order->add_order_note( in_array( $e->api_code, array( 'PASS_INACTIVE', 'PASS_EXPIRED' ), true ) ? RedeemWords::closed_card_note() : RedeemWords::refused_note( $e ), 0 );
				return 'refused';
			}
			return $this->later( $order, $step, (int) $attempt );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return 'skipped';
		}
		return 'done';
	}

	/** Is the step still what the order needs? */
	private function due( \WC_Order $order, string $step, bool $paid = false ): bool {
		$rows   = self::read( $order );
		$status = (string) $order->get_status();
		switch ( $step ) {
			case 'capture':
				// Paid, and something not yet settled: held, or let go (a late payment is taken if the card still has it).
				if ( ! $paid && ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
					return false;
				}
				foreach ( $rows as $r ) {
					if ( ! in_array( $r['state'], self::SETTLED, true ) ) {
						return true;
					}
				}
				return false;
			case 'release':
				if ( 'unclear' === (string) $order->get_meta( self::META_STATE ) ) {
					return ! in_array( $status, array( 'processing', 'completed', 'refunded' ), true );
				}
				return in_array( $status, array( 'cancelled', 'failed' ), true ) && self::any_held( $rows );
			case 'refund':
				if ( 'refunded' !== $status ) {
					return false;
				}
				foreach ( $rows as $r ) {
					if ( 'captured' === $r['state'] ) {
						return true;
					}
				}
				return false;
		}
		return false;
	}

	/** Why a release is asked: the order's status, or the shop's own (an unclear hold, a refused checkout). */
	private function release_reason( \WC_Order $order ): string {
		$status = (string) $order->get_status();
		return in_array( $status, array( 'cancelled', 'failed' ), true ) ? $status : 'shop';
	}

	/** An unclear step: tried again later (a note the first time), until the last delay; then a note that says so. */
	private function later( \WC_Order $order, string $step, int $attempt ): string {
		if ( $attempt >= count( self::RETRY_DELAYS ) ) {
			$order->add_order_note( RedeemWords::gave_up_note(), 0 );
			return 'gave-up';
		}
		$scheduled = ( $this->scheduler )( time() + self::RETRY_DELAYS[ $attempt ], $order->get_id(), $step, $attempt + 1 );
		if ( 0 === $attempt ) {
			$order->add_order_note( $scheduled ? RedeemWords::retry_note() : RedeemWords::gave_up_note(), 0 );
		}
		return $scheduled ? 'retry' : 'gave-up';
	}

	/** Schedules a later try (for a release that could not be asked at once). */
	private function schedule( int $order_id, string $step, int $attempt ): void {
		( $this->scheduler )( time() + 60, $order_id, $step, $attempt );
	}

	private function capture( \WC_Order $order, Client $client, string $link ): void {
		$prev = self::read( $order );
		try {
			$rows = $client->capture_order( $link, (string) $order->get_id() );
		} catch ( ApiError $e ) {
			if ( 'HOLD_UNBACKED' !== $e->api_code ) {
				throw $e;
			}
			$rows = array();
			foreach ( (array) ( $e->details['redemptions'] ?? array() ) as $r ) {
				if ( is_array( $r ) ) {
					$rows[] = $r;
				}
			}
		}
		$this->write( $order, self::clean_all( $rows, $prev ), $prev, 'capture' );
	}

	/**
	 * Lets go of whatever the order holds.
	 *
	 * @param string $reason `cancelled`, `failed` or `shop`.
	 */
	private function release_now( \WC_Order $order, Client $client, string $link, string $reason ): void {
		$prev = self::read( $order );
		$rows = $client->release_order( $link, (string) $order->get_id(), $reason );
		$this->write( $order, self::clean_all( $rows, $prev ), $prev, 'release:' . $reason );
	}

	private function refund( \WC_Order $order, Client $client, string $link ): void {
		$prev   = self::read( $order );
		$answer = $client->refund_order( $link, (string) $order->get_id() );
		$rows   = array();
		foreach ( (array) ( $answer['redemptions'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$rows[] = $r;
			}
		}
		$clean    = self::clean_all( $rows, $prev );
		$before   = array();
		foreach ( $prev as $p ) {
			$before[ $p['id'] ] = $p;
		}
		$now = array();
		foreach ( $clean as $r ) {
			if ( 'refunded' === $r['state'] && 'refunded' !== ( $before[ $r['id'] ]['state'] ?? '' ) ) {
				$now[] = $r;
			}
		}
		$this->store( $order, $clean );
		$unearned = is_array( $answer['unearned'] ?? null ) ? $answer['unearned'] : null;
		if ( array() !== $now || null !== $unearned ) {
			$order->add_order_note( RedeemWords::refunded_note( $now, $unearned, (string) $order->get_currency() ), 0 );
		}
	}

	/* ---------------------------------------------------------------- the record */

	/**
	 * Writes Rewloy's answer to the order and says what changed in notes.
	 *
	 * @param list<array<string,mixed>> $rows The redemptions now (clean).
	 * @param list<array<string,mixed>> $prev The redemptions before.
	 * @param string                    $why  `held`, `capture`, or `release:<reason>`.
	 */
	private function write( \WC_Order $order, array $rows, array $prev, string $why ): void {
		$before = array();
		foreach ( $prev as $p ) {
			$before[ $p['id'] ] = $p;
		}
		foreach ( $rows as $r ) {
			$was = $before[ $r['id'] ] ?? null;
			if ( null !== $was && $was['state'] === $r['state'] && $was['generation'] === $r['generation'] && $was['amountMinor'] === $r['amountMinor'] ) {
				continue;
			}
			$note = '';
			if ( 'held' === $r['state'] ) {
				$note = RedeemWords::held_note( $r );
			} elseif ( 'captured' === $r['state'] ) {
				$note = RedeemWords::captured_note( $r );
			} elseif ( 'unbacked' === $r['state'] ) {
				$note = RedeemWords::unbacked_note( $r );
			} elseif ( in_array( $r['state'], array( 'released', 'expired' ), true ) && null !== $was && 'held' === $was['state'] ) {
				$note = RedeemWords::released_note( $r, str_starts_with( $why, 'release:' ) ? substr( $why, 8 ) : 'shop' );
			}
			if ( '' !== $note ) {
				$order->add_order_note( $note, 0 );
			}
		}
		// Rows Rewloy did not name this time (a hold made by another call) are kept as they were.
		$merged = array();
		foreach ( $prev as $p ) {
			$merged[ $p['id'] ] = $p;
		}
		foreach ( $rows as $r ) {
			$merged[ $r['id'] ] = $r;
		}
		$this->store( $order, array_values( $merged ) );
	}

	/** @param list<array<string,mixed>> $rows */
	private function store( \WC_Order $order, array $rows ): void {
		$order->update_meta_data( self::META, (string) wp_json_encode( array_values( $rows ) ) );
		$order->save_meta_data();
	}

	/**
	 * The order's Rewloy codes as last answered.
	 *
	 * @return list<array{id:string,ref:string,last4:string,cardLast4:string,kind:string,type:string,programName:string,amountMinor:int,capturedMinor:int,refundedMinor:int,currency:string,state:string,generation:int,late:bool}>
	 */
	public static function read( \WC_Order $order ): array {
		$raw  = $order->get_meta( self::META );
		$list = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $r ) {
			$c = is_array( $r ) ? self::clean( $r ) : null;
			if ( null !== $c ) {
				$c['ref'] = is_string( $r['ref'] ?? null ) ? substr( $r['ref'], 0, 32 ) : '';
				$out[]    = $c;
			}
		}
		return $out;
	}

	/**
	 * Rewloy's redemptions, cleaned, each keeping the code's ref the order already had for it.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @param list<array<string,mixed>> $prev
	 * @return list<array<string,mixed>>
	 */
	private static function clean_all( array $rows, array $prev ): array {
		$refs = array();
		foreach ( $prev as $p ) {
			$refs[ $p['id'] ] = $p['ref'];
		}
		$out = array();
		foreach ( $rows as $r ) {
			$c = self::clean( $r );
			if ( null !== $c ) {
				$c['ref'] = $refs[ $c['id'] ] ?? '';
				$out[]    = $c;
			}
		}
		return $out;
	}

	/**
	 * One redemption as the order keeps it: no code, no serial, the card's last four only. Null when it is not one.
	 *
	 * @param array<string,mixed> $r
	 * @return array{id:string,ref:string,last4:string,cardLast4:string,kind:string,type:string,programName:string,amountMinor:int,capturedMinor:int,refundedMinor:int,currency:string,state:string,generation:int,late:bool}|null
	 */
	public static function clean( array $r ): ?array {
		$id    = is_string( $r['id'] ?? null ) ? strtolower( $r['id'] ) : '';
		$state = is_string( $r['state'] ?? null ) ? $r['state'] : '';
		$kind  = is_string( $r['kind'] ?? null ) ? $r['kind'] : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id )
			|| ! in_array( $state, array( 'held', 'captured', 'released', 'expired', 'refunded', 'unbacked' ), true )
			|| ! in_array( $kind, array( 'balance', 'percent', 'amount', 'link' ), true ) ) {
			return null;
		}
		$alnum = static fn( $v ): string => substr( (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( is_string( $v ) ? $v : '' ) ), 0, 8 );
		$int   = static fn( $v ): int => is_numeric( $v ) ? max( 0, (int) $v ) : 0;
		return array(
			'id'            => $id,
			'ref'           => '',
			'last4'         => $alnum( $r['codeLast4'] ?? ( $r['last4'] ?? '' ) ),
			'cardLast4'     => $alnum( $r['cardLast4'] ?? '' ),
			'kind'          => $kind,
			'type'          => is_string( $r['type'] ?? null ) ? substr( $r['type'], 0, 20 ) : '',
			'programName'   => is_string( $r['programName'] ?? null ) ? substr( $r['programName'], 0, 120 ) : '',
			'amountMinor'   => $int( $r['amountMinor'] ?? 0 ),
			'capturedMinor' => $int( $r['capturedMinor'] ?? 0 ),
			'refundedMinor' => $int( $r['refundedMinor'] ?? 0 ),
			'currency'      => is_string( $r['currency'] ?? null ) ? strtoupper( substr( $r['currency'], 0, 3 ) ) : '',
			'state'         => $state,
			'generation'    => max( 1, $int( $r['generation'] ?? 1 ) ),
			'late'          => ! empty( $r['late'] ),
		);
	}

	/** @param list<array<string,mixed>> $rows */
	private static function any_held( array $rows ): bool {
		foreach ( $rows as $r ) {
			if ( 'held' === $r['state'] ) {
				return true;
			}
		}
		return false;
	}
}
