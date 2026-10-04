<?php
/**
 * The plugin's words about what Rewloy answered. English in the code; the Turkish
 * ones (the house voice of the Rewloy panel: plain, exact, claiming only what is
 * recorded) are in languages/. The API's own message is never shown: these are.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Messages {

	/** The outcomes Rewloy records for an order, in the panel's order. */
	public const OUTCOMES = array( 'credited', 'unmatched', 'below', 'paused', 'currency' );

	/** What Rewloy can say about the last signed request of a shop (`lastDelivery.result`): the five outcomes and four more. */
	public const DELIVERY_RESULTS = array( 'credited', 'unmatched', 'below', 'paused', 'currency', 'duplicate', 'ignored', 'no_id', 'bad_body' );

	/** The panel's label for a recorded outcome. */
	public static function outcome_label( string $outcome ): string {
		switch ( $outcome ) {
			case 'credited':
				return __( 'Added to the card', 'rewloy-for-woocommerce' );
			case 'unmatched':
				return __( 'No card', 'rewloy-for-woocommerce' );
			case 'below':
				return __( 'Below the threshold', 'rewloy-for-woocommerce' );
			case 'paused':
				return __( 'While the link was off', 'rewloy-for-woocommerce' );
			case 'currency':
				return __( 'Other currency', 'rewloy-for-woocommerce' );
			default:
				return $outcome;
		}
	}

	/**
	 * The panel's label for what became of the last signed request (`lastDelivery.result`): the outcomes of an order
	 * and four things that are not one (a repeat, an unpaid order, no order number, an unreadable body).
	 */
	public static function delivery_label( string $result ): string {
		switch ( $result ) {
			case 'duplicate':
				return __( 'Repeat of a recorded order', 'rewloy-for-woocommerce' );
			case 'ignored':
				return __( 'Unpaid order (not recorded)', 'rewloy-for-woocommerce' );
			case 'no_id':
				return __( 'No order number', 'rewloy-for-woocommerce' );
			case 'bad_body':
				return __( 'Could not be read', 'rewloy-for-woocommerce' );
			default:
				return self::outcome_label( $result );
		}
	}

	/** The panel's label for why Rewloy refused a request to the shop's address (`lastRefusal.reason`). */
	public static function refusal_label( string $reason ): string {
		return 'bad_signature' === $reason ? __( 'Signature did not match', 'rewloy-for-woocommerce' ) : $reason;
	}

	/**
	 * What an outcome means, in the panel's words. `below` depends on the card: a stamp or points card has a rule with a
	 * threshold, a cashback card has none (its own rate applied to the total came to nothing).
	 *
	 * @param string $type The connected card's type ('' when not known).
	 */
	public static function outcome_why( string $outcome, string $type = '' ): string {
		if ( 'below' === $outcome && 'cashback' === $type ) {
			return __( 'The cashback on the order came to nothing; nothing was added.', 'rewloy-for-woocommerce' );
		}
		switch ( $outcome ) {
			case 'credited':
				return __( 'Added to the buyer\'s card.', 'rewloy-for-woocommerce' );
			case 'unmatched':
				return __( 'No customer of this card has the order\'s e-mail; no card was opened.', 'rewloy-for-woocommerce' );
			case 'below':
				return __( 'The amount did not reach the rule\'s threshold; nothing was added.', 'rewloy-for-woocommerce' );
			case 'paused':
				return __( 'Arrived while the link was off; it was not processed and will not be later.', 'rewloy-for-woocommerce' );
			case 'currency':
				return __( 'The order is not in the card\'s currency; the amount is not converted.', 'rewloy-for-woocommerce' );
			default:
				return '';
		}
	}

	/** The rule in the programme's own unit, as Rewloy applies it. */
	public static function rule_text( string $type, string $rule, int $per_amount_minor, int $step, string $currency ): string {
		if ( 'vip' === $type ) {
			return __( 'Every paid order counts as one visit.', 'rewloy-for-woocommerce' );
		}
		if ( 'cashback' === $type ) {
			return __( 'The card\'s own cashback rate is applied to the order total.', 'rewloy-for-woocommerce' );
		}
		$stamps = 'stamp' === $type;
		if ( 'amount' === $rule ) {
			$amount = number_format_i18n( $per_amount_minor / 100, 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
			return $stamps
				/* translators: 1: amount with currency, 2: number of stamps. */
				? sprintf( _n( 'For every %1$s of the order total, %2$d stamp (at most 50 per order).', 'For every %1$s of the order total, %2$d stamps (at most 50 per order).', $step, 'rewloy-for-woocommerce' ), $amount, $step )
				/* translators: 1: amount with currency, 2: number of points. */
				: sprintf( _n( 'For every %1$s of the order total, %2$d point (at most 500 per order).', 'For every %1$s of the order total, %2$d points (at most 500 per order).', $step, 'rewloy-for-woocommerce' ), $amount, $step );
		}
		return $stamps
			/* translators: %d: number of stamps. */
			? sprintf( _n( '%d stamp for every paid order.', '%d stamps for every paid order.', $step, 'rewloy-for-woocommerce' ), $step )
			/* translators: %d: number of points. */
			: sprintf( _n( '%d point for every paid order.', '%d points for every paid order.', $step, 'rewloy-for-woocommerce' ), $step );
	}

	/** What a VIP card does with an order; it needs no rule. */
	public static function vip_note(): string {
		return __( 'VIP cards: every paid order counts as one visit and moves the card up its levels. No rule is needed.', 'rewloy-for-woocommerce' );
	}

	/** What stamp and points cards do with an order: the rule on the screen. */
	public static function stamp_points_note(): string {
		return __( 'Stamp and points cards: the rule below decides what an order adds.', 'rewloy-for-woocommerce' );
	}

	/**
	 * What a cashback card does with an order: its own rate, no rule. With the rate when Rewloy lists it (and an example
	 * on a 400 order); without a number when it does not.
	 *
	 * @param string     $name     The card's name.
	 * @param float|null $rate     The card's rate in percent, or null when not listed.
	 * @param string     $currency The card's currency ('' when not listed).
	 */
	public static function cashback_note( string $name, ?float $rate, string $currency ): string {
		if ( null === $rate ) {
			/* translators: %s: the card's name. */
			return sprintf( __( 'Cashback card "%s": the card\'s own rate is applied to the total of every paid order and added to the card\'s balance; you change the rate in the card\'s settings in the Rewloy panel. No rule is needed.', 'rewloy-for-woocommerce' ), $name );
		}
		$fmt = static fn( float $n ): string => number_format_i18n( $n, floor( $n ) === $n ? 0 : 2 );
		$cur = '' !== $currency ? ' ' . $currency : '';
		/* translators: 1: the card's name, 2: the rate in percent, 3: an example order total with currency, 4: what that order adds, with currency. */
		return sprintf( __( 'Cashback card "%1$s": %2$s%% of every paid order\'s total is added to the card\'s balance (the card\'s own rate, set in the card\'s settings in the Rewloy panel). For example, an order of %3$s adds %4$s. No rule is needed.', 'rewloy-for-woocommerce' ), $name, $fmt( $rate ), $fmt( 400.0 ) . $cur, $fmt( 4 * $rate ) . $cur );
	}

	/** A programme type in words. */
	public static function type_label( string $type ): string {
		switch ( $type ) {
			case 'stamp':
				return __( 'Stamp card', 'rewloy-for-woocommerce' );
			case 'points':
				return __( 'Points card', 'rewloy-for-woocommerce' );
			case 'vip':
				return __( 'VIP card', 'rewloy-for-woocommerce' );
			case 'cashback':
				return __( 'Cashback card', 'rewloy-for-woocommerce' );
			default:
				return $type;
		}
	}

	/** Any card type in words, the gift card, coupon and discount card included (the till reads every type). */
	public static function any_type_label( string $type ): string {
		switch ( $type ) {
			case 'giftcard':
				return __( 'Gift card', 'rewloy-for-woocommerce' );
			case 'voucher':
				return __( 'Coupon', 'rewloy-for-woocommerce' );
			case 'discount':
				return __( 'Discount card', 'rewloy-for-woocommerce' );
			default:
				return self::type_label( $type );
		}
	}

	/** A card's status in words. */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'active':
				return __( 'Open', 'rewloy-for-woocommerce' );
			case 'redeemed':
				return __( 'Used', 'rewloy-for-woocommerce' );
			case 'voided':
			case 'revoked':
			case 'ended':
				return __( 'Closed', 'rewloy-for-woocommerce' );
			case 'expired':
				return __( 'Expired', 'rewloy-for-woocommerce' );
			default:
				return $status;
		}
	}

	/** What a card's progress figure is (`progressLabel` of getPass). */
	public static function progress_label( string $key ): string {
		switch ( $key ) {
			case 'stamps':
				return __( 'Stamps', 'rewloy-for-woocommerce' );
			case 'points':
				return __( 'Points', 'rewloy-for-woocommerce' );
			case 'rewardReady':
				return __( 'Reward', 'rewloy-for-woocommerce' );
			case 'balance':
				return __( 'Balance', 'rewloy-for-woocommerce' );
			case 'discount':
				return __( 'Discount', 'rewloy-for-woocommerce' );
			case 'tier':
				return __( 'Level', 'rewloy-for-woocommerce' );
			case 'offer':
				return __( 'Offer', 'rewloy-for-woocommerce' );
			default:
				return __( 'State', 'rewloy-for-woocommerce' );
		}
	}

	/** A card's own till operation as a button says it; '' for one this plugin does not offer. */
	public static function action_label( string $action ): string {
		switch ( $action ) {
			case 'earn-stamps':
				return __( 'Add a stamp', 'rewloy-for-woocommerce' );
			case 'redeem-stamps':
				return __( 'Give the stamp reward', 'rewloy-for-woocommerce' );
			case 'earn-points':
				return __( 'Add points for an amount', 'rewloy-for-woocommerce' );
			case 'redeem-reward':
				return __( 'Give a points reward', 'rewloy-for-woocommerce' );
			case 'spend-points':
				return __( 'Spend points', 'rewloy-for-woocommerce' );
			case 'visit':
				return __( 'Count a visit', 'rewloy-for-woocommerce' );
			case 'spend':
				return __( 'Spend from the balance', 'rewloy-for-woocommerce' );
			case 'accrue':
				return __( 'Add cashback for an amount', 'rewloy-for-woocommerce' );
			case 'use':
				return __( 'Use the card', 'rewloy-for-woocommerce' );
			default:
				return '';
		}
	}

	/** Does the operation spend something of the customer's (a reward, a balance, a coupon)? Those ask to confirm. */
	public static function action_spends( string $action ): bool {
		return in_array( $action, array( 'redeem-stamps', 'redeem-reward', 'spend-points', 'spend', 'use' ), true );
	}

	/** What a sale writes on a card of this type (`sale.writes`). */
	public static function sale_writes( string $writes ): string {
		switch ( $writes ) {
			case 'stamps':
				return __( 'A sale adds a stamp.', 'rewloy-for-woocommerce' );
			case 'points':
				return __( 'A sale adds points by the card\'s own rate.', 'rewloy-for-woocommerce' );
			case 'visit':
				return __( 'A sale counts a visit (once per visit window).', 'rewloy-for-woocommerce' );
			case 'cashback':
				return __( 'A sale adds cashback by the card\'s own rate.', 'rewloy-for-woocommerce' );
			default:
				return __( 'A sale adds nothing to this card; use its own operations below.', 'rewloy-for-woocommerce' );
		}
	}

	/**
	 * What a sale did, from Rewloy's answer (`recordSale`): what was written and how much, or why nothing was.
	 *
	 * @param array<string,mixed> $r        The answer.
	 * @param string              $currency The card's currency ('' when not known).
	 */
	public static function sale_result( array $r, string $currency ): string {
		$applied  = is_string( $r['applied'] ?? null ) ? $r['applied'] : 'none';
		$credited = is_numeric( $r['credited'] ?? null ) ? (int) $r['credited'] : 0;
		$repeat   = true === ( $r['duplicate'] ?? false );
		switch ( $applied ) {
			case 'stamps':
				/* translators: %d: number of stamps. */
				$text = sprintf( _n( 'Sale recorded: %d stamp added.', 'Sale recorded: %d stamps added.', $credited, 'rewloy-for-woocommerce' ), $credited );
				break;
			case 'points':
				/* translators: %d: number of points. */
				$text = sprintf( _n( 'Sale recorded: %d point added.', 'Sale recorded: %d points added.', $credited, 'rewloy-for-woocommerce' ), $credited );
				break;
			case 'visit':
				$text = __( 'Sale recorded: a visit counted.', 'rewloy-for-woocommerce' );
				break;
			case 'cashback':
				/* translators: %s: the cashback with currency. */
				$text = sprintf( __( 'Sale recorded: %s cashback added.', 'rewloy-for-woocommerce' ), number_format_i18n( $credited / 100, 2 ) . ( '' !== $currency ? ' ' . $currency : '' ) );
				break;
			default:
				$text = self::sale_reason( is_string( $r['reason'] ?? null ) ? $r['reason'] : '' );
		}
		if ( is_array( $r['promotion'] ?? null ) && is_string( $r['promotion']['name'] ?? null ) ) {
			/* translators: %s: the till promotion's name. */
			$text .= ' ' . sprintf( __( 'Promotion: %s.', 'rewloy-for-woocommerce' ), $r['promotion']['name'] );
		}
		if ( true === ( $r['rewardReady'] ?? false ) ) {
			$text .= ' ' . __( 'A reward is ready on this card.', 'rewloy-for-woocommerce' );
		}
		return $repeat ? __( 'This press was already recorded; nothing was written twice.', 'rewloy-for-woocommerce' ) . ' ' . $text : $text;
	}

	/** Why a sale wrote nothing (`reason` of recordSale). */
	public static function sale_reason( string $reason ): string {
		switch ( $reason ) {
			case 'below_minimum':
				return __( 'Nothing written: the total earns less than one point or one cent of cashback.', 'rewloy-for-woocommerce' );
			case 'visit_already_counted':
				return __( 'Nothing written: a visit is already counted on this card in this visit window.', 'rewloy-for-woocommerce' );
			case 'card_full':
				return __( 'Nothing written: the stamp card is full. Give the reward first.', 'rewloy-for-woocommerce' );
			case 'type_does_not_earn':
				return __( 'Nothing written: gift cards, coupons and discount cards earn nothing from a sale.', 'rewloy-for-woocommerce' );
			default:
				return __( 'Nothing was written.', 'rewloy-for-woocommerce' );
		}
	}

	/** What an operation did. */
	public static function action_result( string $action, bool $repeat ): string {
		$label = self::action_label( $action );
		/* translators: %s: the operation, such as "Add a stamp". */
		$text = sprintf( __( 'Done: %s.', 'rewloy-for-woocommerce' ), $label );
		return $repeat ? __( 'This press was already recorded; nothing was written twice.', 'rewloy-for-woocommerce' ) . ' ' . $text : $text;
	}

	/** One line of a card's activity: what happened and how much. */
	public static function activity_text( string $kind, ?float $delta, string $unit, string $currency ): string {
		$amount = '';
		if ( null !== $delta && 0.0 !== $delta ) {
			$abs = abs( $delta );
			// Money is kept in minor units (Rewloy's `try_minor`, whatever the card's currency).
			if ( str_ends_with( $unit, '_minor' ) ) {
				$amount = number_format_i18n( $abs / 100, 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
			} else {
				$amount = number_format_i18n( $abs, floor( $abs ) === $abs ? 0 : 2 ) . ( '' !== $unit ? ' ' . self::unit_label( $unit ) : '' );
			}
		}
		switch ( $kind ) {
			case 'earn':
				$what = __( 'Added', 'rewloy-for-woocommerce' );
				break;
			case 'redeem':
				$what = __( 'Reward used', 'rewloy-for-woocommerce' );
				break;
			case 'spend':
				$what = __( 'Spent', 'rewloy-for-woocommerce' );
				break;
			case 'load':
				$what = __( 'Loaded', 'rewloy-for-woocommerce' );
				break;
			case 'accrue':
				$what = __( 'Cashback added', 'rewloy-for-woocommerce' );
				break;
			case 'visit':
				$what = __( 'Visit', 'rewloy-for-woocommerce' );
				break;
			case 'use':
				$what = __( 'Card used', 'rewloy-for-woocommerce' );
				break;
			case 'issue':
				$what = __( 'Card given', 'rewloy-for-woocommerce' );
				break;
			case 'adjust':
				$what = __( 'Corrected by hand', 'rewloy-for-woocommerce' );
				break;
			case 'expire':
				$what = __( 'Expired', 'rewloy-for-woocommerce' );
				break;
			default:
				$what = $kind;
		}
		return '' !== $amount ? $what . ': ' . $amount : $what;
	}

	/** A ledger unit in words. */
	private static function unit_label( string $unit ): string {
		switch ( $unit ) {
			case 'stamp':
			case 'stamps':
				return __( 'stamp(s)', 'rewloy-for-woocommerce' );
			case 'point':
			case 'points':
				return __( 'point(s)', 'rewloy-for-woocommerce' );
			case 'visit':
			case 'visits':
				return __( 'visit(s)', 'rewloy-for-woocommerce' );
			default:
				return $unit;
		}
	}

	/** Who did something, in one word: never a name or an address. */
	public static function actor_kind( string $kind ): string {
		switch ( $kind ) {
			case 'seat':
				return __( 'Team member', 'rewloy-for-woocommerce' );
			case 'api_key':
				return __( 'Integration (API key)', 'rewloy-for-woocommerce' );
			default:
				return __( 'Rewloy', 'rewloy-for-woocommerce' );
		}
	}

	/** A failed card lookup in the panel's words. */
	public static function for_card_error( RewloyException $e ): string {
		switch ( $e->api_code ) {
			case 'PASS_NOT_FOUND':
				return __( 'No card with this number in this business.', 'rewloy-for-woocommerce' );
			case 'FORBIDDEN':
			case 'OUT_OF_SCOPE':
				return __( 'This shop\'s key may not read that card: it belongs to another Rewloy card, or "Görüntüleme" is off for this shop in the Rewloy panel.', 'rewloy-for-woocommerce' );
		}
		return self::for_error( $e );
	}

	/** A failed till call in the panel's words. */
	public static function for_till_error( RewloyException $e ): string {
		switch ( $e->api_code ) {
			case 'PASS_NOT_FOUND':
				return __( 'No card with this number in this business.', 'rewloy-for-woocommerce' );
			case 'WRONG_LOCATION':
				return __( 'This card is not valid at this branch.', 'rewloy-for-woocommerce' );
			case 'LOCATION_NOT_FOUND':
				return __( 'The till\'s branch no longer exists in Rewloy (it may have been archived). Choose another branch for this shop in the Rewloy panel.', 'rewloy-for-woocommerce' );
			case 'PASS_INACTIVE':
				return __( 'This card is closed.', 'rewloy-for-woocommerce' );
			case 'PASS_EXPIRED':
				return __( 'This card has expired.', 'rewloy-for-woocommerce' );
			case 'PASS_USED_UP':
				return __( 'This card has already been used.', 'rewloy-for-woocommerce' );
			case 'INSUFFICIENT_BALANCE':
				return __( 'The card\'s balance is not enough for that.', 'rewloy-for-woocommerce' );
			case 'REWARD_NOT_READY':
				return __( 'The reward is not ready on this card yet.', 'rewloy-for-woocommerce' );
			case 'VISIT_ALREADY_COUNTED':
				return __( 'A visit is already counted on this card in this visit window.', 'rewloy-for-woocommerce' );
			case 'WRONG_CARD_TYPE':
				return __( 'This card does not take that operation.', 'rewloy-for-woocommerce' );
			case 'IDEMPOTENCY_KEY_REUSED':
				return __( 'Rewloy already recorded a different operation for this press. Nothing was written; press the button again for a new operation.', 'rewloy-for-woocommerce' );
			case 'IDEMPOTENCY_IN_PROGRESS':
				return __( 'This press is still being recorded. Wait a moment and try again; nothing will be written twice.', 'rewloy-for-woocommerce' );
			case 'FORBIDDEN':
			case 'OUT_OF_SCOPE':
				return __( 'This shop\'s till may not do that: the card belongs to another Rewloy card, the operation needs a permission the till does not have, or the till was turned off in the Rewloy panel.', 'rewloy-for-woocommerce' );
		}
		if ( $e->outcome_unknown() ) {
			return __( 'Rewloy gave no clear answer, so it may or may not have been recorded. Press "Try again": the same press is sent again and Rewloy never writes it twice.', 'rewloy-for-woocommerce' );
		}
		return self::for_error( $e );
	}

	/** What a person should read for a failed call. Never the API's own text, never the key. */
	public static function for_error( RewloyException $e ): string {
		$text = self::error_text( $e );
		$id   = preg_replace( '/[^A-Za-z0-9-]/', '', $e->request_id ) ?? '';
		if ( '' !== $id ) {
			/* translators: 1: the sentence, 2: the request id Rewloy support asks for. */
			$text = sprintf( __( '%1$s (request: %2$s)', 'rewloy-for-woocommerce' ), $text, substr( $id, 0, 64 ) );
		}
		return $text;
	}

	private static function error_text( RewloyException $e ): string {
		if ( $e instanceof ConnectionError ) {
			return __( 'Rewloy could not be reached or its answer could not be read. Try again in a moment.', 'rewloy-for-woocommerce' );
		}
		switch ( $e->api_code ) {
			case 'INVALID_API_KEY':
			case 'UNAUTHENTICATED':
				return __( 'Rewloy did not accept this API key. Check that it is complete and has not been revoked.', 'rewloy-for-woocommerce' );
			case 'FORBIDDEN':
			case 'OUT_OF_SCOPE':
				return __( 'This API key is not allowed to do that. It needs the permissions to see cards, to see and manage shop links, and to issue cards; the E-ticaret role has exactly those.', 'rewloy-for-woocommerce' );
			case 'PLAN_FEATURE_MISSING':
				return __( 'Shop links are not in this business\'s Rewloy plan.', 'rewloy-for-woocommerce' );
			case 'LIMIT':
				return __( 'At most 5 shops can be linked. Delete one in the Rewloy panel first.', 'rewloy-for-woocommerce' );
			case 'PROGRAM_NOT_FOUND':
				return __( 'That card was not found, or it is archived.', 'rewloy-for-woocommerce' );
			case 'NOT_AN_INSTRUMENT':
				return __( 'Gift cards, coupons and discount cards are not filled by orders; they are given by code.', 'rewloy-for-woocommerce' );
			case 'SHOP_NOT_FOUND':
				return __( 'This link no longer exists in Rewloy.', 'rewloy-for-woocommerce' );
			case 'RATE_LIMITED':
				return __( 'Rewloy is receiving too many requests from this key or address. Try again in a few minutes.', 'rewloy-for-woocommerce' );
			case 'CONNECT_TOKEN_INVALID':
				return __( 'Rewloy did not accept this code: it may have been used already, have lapsed (a code lasts 15 minutes) or have been withdrawn. Make a new one in the Rewloy panel: E-ticaret › Mağaza bağla › WooCommerce › "Rewloy eklentisiyle".', 'rewloy-for-woocommerce' );
			case 'SHOP_PROGRAM_MISMATCH':
				return __( 'This shop link belongs to another card than the one the invitation opens.', 'rewloy-for-woocommerce' );
			case 'IDEMPOTENCY_KEY_REUSED':
				return __( 'Rewloy already has a different request under this order\'s key.', 'rewloy-for-woocommerce' );
			case 'TEST_LIMIT_REACHED':
				return __( 'The Rewloy test environment is full. Reset it in the Rewloy panel.', 'rewloy-for-woocommerce' );
			case 'READ_ONLY':
			case 'VIEW_AS_READ_ONLY':
				return __( 'This business\'s Rewloy account is read-only right now.', 'rewloy-for-woocommerce' );
			case 'VALIDATION':
				return __( 'Rewloy did not accept the values sent.', 'rewloy-for-woocommerce' );
			case 'CONSENT_REQUIRED':
				return __( 'Rewloy needs the statement that the customer was shown the privacy notice.', 'rewloy-for-woocommerce' );
			case 'EMAIL_BLOCKED':
			case 'CUSTOMER_BLOCKED':
				return __( 'This e-mail address cannot be given a card by this business.', 'rewloy-for-woocommerce' );
			case 'INVALID_CUSTOMER':
				return __( 'Rewloy did not accept the customer\'s details.', 'rewloy-for-woocommerce' );
			case 'PASS_REFUSED':
				return __( 'Rewloy refused to open the card.', 'rewloy-for-woocommerce' );
			case 'INTERNAL':
				return __( 'Rewloy had an error of its own. Try again in a moment.', 'rewloy-for-woocommerce' );
		}
		if ( $e->status >= 500 ) {
			return __( 'Rewloy had an error of its own. Try again in a moment.', 'rewloy-for-woocommerce' );
		}
		$code = preg_replace( '/[^A-Z_]/', '', $e->api_code ) ?? '';
		return '' !== $code
			/* translators: %s: the API's error code. */
			? sprintf( __( 'Rewloy refused the request (code %s).', 'rewloy-for-woocommerce' ), $code )
			: __( 'Rewloy refused the request.', 'rewloy-for-woocommerce' );
	}
}
