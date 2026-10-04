<?php
/**
 * What the checkout codes say (0.4.0): the customer's messages at the checkout, the lines in the cart, the order notes.
 * English here, Turkish in languages/ (docs/CHECKOUT-CARDS.md §10.4 and §10.5, word for word where a Turkish suffix
 * after a name allows it).
 *
 * No message carries the code. A card is named by its last four characters only.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class RedeemWords {

	/**
	 * Why a code cannot be used, as both checkouts show it.
	 *
	 * @param string $code     Rewloy's error code, or one of Redeem::E_*.
	 * @param string $currency The card's currency, for CURRENCY_MISMATCH.
	 * @param string $reason   Rewloy's `details.reason`, for CODE_RELEASED (`expired` or `merchant`).
	 */
	public static function refusal( string $code, string $currency = '', string $reason = '' ): string {
		switch ( $code ) {
			case 'CODE_INVALID':
				return __( 'This Rewloy code is not valid. Copy it again from your card\'s page or from Rewloy Cüzdan.', 'rewloy-for-woocommerce' );
			case 'CODE_EXPIRED':
				return __( 'This code has expired. Make a new code on your card.', 'rewloy-for-woocommerce' );
			case 'CODE_USED':
				return __( 'This code was used on another order. Make a new code on your card.', 'rewloy-for-woocommerce' );
			case 'CODE_RELEASED':
				// A re-hold after the hold ended (Rewloy 1.0, ADR 180): never "used on another order".
				if ( 'expired' === $reason ) {
					return __( 'The hold on this code has run out. Get a new code from Rewloy.', 'rewloy-for-woocommerce' );
				}
				if ( 'merchant' === $reason ) {
					return __( 'This code was released by the business. Get a new code from Rewloy.', 'rewloy-for-woocommerce' );
				}
				return __( 'The hold on this code has ended. Make a new code on your card.', 'rewloy-for-woocommerce' );
			case 'INSUFFICIENT_BALANCE':
				return __( 'Your card\'s balance is not enough for this code. Remove the code and make a new one on your card.', 'rewloy-for-woocommerce' );
			case 'PASS_INACTIVE':
			case 'PASS_EXPIRED':
				return __( 'This card can no longer be used.', 'rewloy-for-woocommerce' );
			case 'PASS_USED_UP':
				return __( 'This card has no use left.', 'rewloy-for-woocommerce' );
			case 'CODE_NOT_ACCEPTED_HERE':
			case 'FORBIDDEN':
			case 'OUT_OF_SCOPE':
			case 'PLAN_FEATURE_MISSING':
			case 'SHOP_NOT_FOUND':
				return __( 'This card cannot be used in this shop.', 'rewloy-for-woocommerce' );
			case 'VOUCHER_NOT_ONLINE':
				return __( 'This coupon can only be used in the shop.', 'rewloy-for-woocommerce' );
			case 'CURRENCY_MISMATCH':
				$cur = (string) preg_replace( '/[^A-Z]/', '', strtoupper( $currency ) );
				return '' !== $cur
					/* translators: %s: a currency code, such as TRY. */
					? sprintf( __( 'This card works in %s; your basket is in another currency.', 'rewloy-for-woocommerce' ), $cur )
					: __( 'This card works in another currency than your basket.', 'rewloy-for-woocommerce' );
			case 'SHOP_PAUSED':
				return __( 'Rewloy cards cannot be used in this shop right now.', 'rewloy-for-woocommerce' );
			case 'CARD_IN_ORDER':
				return __( 'This card is already used on this order.', 'rewloy-for-woocommerce' );
			case 'ORDER_CODES_LIMIT':
				return __( 'An order can use at most 3 Rewloy codes.', 'rewloy-for-woocommerce' );
			case Redeem::E_TOO_MANY:
				return __( 'Too many codes were tried. Try again in a few minutes.', 'rewloy-for-woocommerce' );
			case Redeem::E_ADMIN:
				return __( 'Rewloy codes are used at the checkout only.', 'rewloy-for-woocommerce' );
			case Redeem::E_UNREACHABLE:
			case 'RATE_LIMITED':
			case 'INTERNAL':
			case 'INVALID_API_KEY':
			case 'UNAUTHENTICATED':
				return self::unreachable();
		}
		return __( 'This Rewloy code cannot be used on this order. Remove it and make a new code on your card.', 'rewloy-for-woocommerce' );
	}

	/** Rewloy did not answer clearly: nothing was applied, nothing taken. */
	public static function unreachable(): string {
		return __( 'Rewloy cannot be reached right now. Try the code again in a few minutes, or remove it and go on with the order.', 'rewloy-for-woocommerce' );
	}

	/** The cart's line for a code: "Rewloy: <card>". */
	public static function line_label( string $programme ): string {
		$programme = trim( $programme );
		/* translators: %s: the Rewloy card programme's name, such as "Hediye kartı". */
		return '' !== $programme ? sprintf( __( 'Rewloy: %s', 'rewloy-for-woocommerce' ), $programme ) : __( 'Rewloy card', 'rewloy-for-woocommerce' );
	}

	/** The coupon line of a code whose value is a payment: the value is on its own line. */
	public static function paid_below(): string {
		return __( 'taken as a payment, on its own line', 'rewloy-for-woocommerce' );
	}

	/** The coupon line of a stamp, points or VIP card: no discount, the order counts on the card. */
	public static function tie_line(): string {
		return __( 'no discount: the order counts on this card', 'rewloy-for-woocommerce' );
	}

	/**
	 * An amount in the shop's own money format, as text (for notes).
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217.
	 */
	public static function money( int $minor, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			$html = (string) wc_price( $minor / 100, array( 'currency' => $currency ) );
			// A note is plain text: the non-breaking space of the price format becomes a space.
			return trim( str_replace( "\u{00A0}", ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
		}
		return number_format( $minor / 100, 2, ',', '.' ) . ' ' . $currency;
	}

	/**
	 * The note when a code is held for the order.
	 *
	 * @param array<string,mixed> $r A redemption.
	 */
	public static function held_note( array $r ): string {
		$name = self::name( $r );
		$card = self::card( $r );
		switch ( $r['kind'] ?? '' ) {
			case 'balance':
				/* translators: 1: an amount, 2: the card programme's name, 3: the card number's last four characters. */
				return sprintf( __( 'Rewloy: %1$s held for this order on the %2$s (card …%3$s). It is taken from the card when the order is paid.', 'rewloy-for-woocommerce' ), self::amount( $r ), $name, $card );
			case 'link':
				/* translators: 1: the card programme's name, 2: the card number's last four characters. */
				return sprintf( __( 'Rewloy: the order is tied to the %1$s (card …%2$s); once paid, it counts on this card.', 'rewloy-for-woocommerce' ), $name, $card );
			default:
				/* translators: 1: the card programme's name, 2: the card number's last four characters. */
				return sprintf( __( 'Rewloy: the %1$s (card …%2$s) is used on this order; its use is held until the order is paid.', 'rewloy-for-woocommerce' ), $name, $card );
		}
	}

	/**
	 * The note when a held code is taken (the order was paid).
	 *
	 * @param array<string,mixed> $r A redemption.
	 */
	public static function captured_note( array $r ): string {
		$late = ! empty( $r['late'] );
		if ( 'balance' === ( $r['kind'] ?? '' ) ) {
			$amount = self::money( (int) ( $r['capturedMinor'] ?? 0 ), (string) ( $r['currency'] ?? '' ) );
			return $late
				/* translators: %s: an amount. */
				? sprintf( __( 'Rewloy: the hold had ended; %s was still on the card and was taken.', 'rewloy-for-woocommerce' ), $amount )
				/* translators: %s: an amount. */
				: sprintf( __( 'Rewloy: %s taken from the card.', 'rewloy-for-woocommerce' ), $amount );
		}
		if ( 'link' === ( $r['kind'] ?? '' ) ) {
			/* translators: 1: the card programme's name, 2: the card number's last four characters. */
			return sprintf( __( 'Rewloy: the paid order counts on the %1$s (card …%2$s).', 'rewloy-for-woocommerce' ), self::name( $r ), self::card( $r ) );
		}
		return $late
			? __( 'Rewloy: the hold had ended; the card still had its use, and it was recorded.', 'rewloy-for-woocommerce' )
			: __( 'Rewloy: the card\'s use was recorded.', 'rewloy-for-woocommerce' );
	}

	/**
	 * The note when a payment came after the hold ran out and the card no longer had the value.
	 *
	 * @param array<string,mixed> $r A redemption.
	 */
	public static function unbacked_note( array $r ): string {
		if ( 'balance' === ( $r['kind'] ?? '' ) ) {
			/* translators: %s: an amount. */
			return sprintf( __( 'Rewloy: the hold had ended, and when the order was paid the card no longer had the balance (it had been spent, or the card was closed). The %s on this order could not be taken from the card. It shows in the Rewloy panel under E-ticaret › the shop.', 'rewloy-for-woocommerce' ), self::amount( $r ) );
		}
		return __( 'Rewloy: the hold had ended, and when the order was paid the card had no use left (it had been used, or the card was closed). The discount on this order could not be recorded on the card. It shows in the Rewloy panel under E-ticaret › the shop.', 'rewloy-for-woocommerce' );
	}

	/**
	 * The note when a hold is let go.
	 *
	 * @param array<string,mixed> $r      A redemption.
	 * @param string              $reason `cancelled`, `failed` or `shop`.
	 */
	public static function released_note( array $r, string $reason ): string {
		switch ( $reason ) {
			case 'cancelled':
				$why = __( 'the order was cancelled', 'rewloy-for-woocommerce' );
				break;
			case 'failed':
				$why = __( 'the payment failed', 'rewloy-for-woocommerce' );
				break;
			default:
				$why = __( 'the checkout did not go through', 'rewloy-for-woocommerce' );
		}
		if ( 'balance' === ( $r['kind'] ?? '' ) ) {
			/* translators: 1: an amount, 2: why, such as "the order was cancelled". */
			return sprintf( __( 'Rewloy: %1$s released back to the card (%2$s).', 'rewloy-for-woocommerce' ), self::amount( $r ), $why );
		}
		if ( 'link' === ( $r['kind'] ?? '' ) ) {
			/* translators: %s: why, such as "the order was cancelled". */
			return sprintf( __( 'Rewloy: the order is no longer tied to the card (%s).', 'rewloy-for-woocommerce' ), $why );
		}
		/* translators: %s: why, such as "the order was cancelled". */
		return sprintf( __( 'Rewloy: the card\'s held use was released (%s).', 'rewloy-for-woocommerce' ), $why );
	}

	/**
	 * The note when the order was refunded in full.
	 *
	 * @param list<array<string,mixed>> $refunded  The redemptions that became refunded now.
	 * @param array<string,mixed>|null  $unearned  What the order's earn taken back was (Rewloy's `unearned`).
	 * @param string                    $currency  The order's currency.
	 */
	public static function refunded_note( array $refunded, ?array $unearned, string $currency ): string {
		$back = 0;
		$used = false;
		foreach ( $refunded as $r ) {
			if ( 'balance' === ( $r['kind'] ?? '' ) ) {
				$back += (int) ( $r['refundedMinor'] ?? 0 );
			} elseif ( in_array( $r['kind'] ?? '', array( 'percent', 'amount' ), true ) ) {
				$used = true;
			}
		}
		$parts = array();
		if ( $back > 0 ) {
			/* translators: %s: an amount. */
			$parts[] = sprintf( __( 'Rewloy: refunded; %s put back on the card.', 'rewloy-for-woocommerce' ), self::money( $back, $currency ) );
		} else {
			$parts[] = __( 'Rewloy: the order was refunded.', 'rewloy-for-woocommerce' );
		}
		if ( $used ) {
			$parts[] = __( 'A coupon or discount card used on the order is not reopened.', 'rewloy-for-woocommerce' );
		}
		if ( is_array( $unearned ) && (int) ( $unearned['reversed'] ?? 0 ) > 0 ) {
			/* translators: %s: what was taken back, such as "2 stamps" or an amount. */
			$parts[] = sprintf( __( 'What this order earned on the card (%s) was taken back.', 'rewloy-for-woocommerce' ), self::units( (int) $unearned['reversed'], (string) ( $unearned['unit'] ?? '' ), $currency ) );
		}
		if ( is_array( $unearned ) && (int) ( $unearned['short'] ?? 0 ) > 0 ) {
			/* translators: %s: what could not be taken back, such as "2 stamps" or an amount. */
			$parts[] = sprintf( __( '%s of it had already been used and could not be taken back.', 'rewloy-for-woocommerce' ), self::units( (int) $unearned['short'], (string) ( $unearned['unit'] ?? '' ), $currency ) );
		}
		return implode( ' ', $parts );
	}

	/** A partial refund changes nothing on the card. */
	public static function partial_refund_note(): string {
		return __( 'Rewloy: a partial refund does not change the card. If needed, refund to the card in the Rewloy panel.', 'rewloy-for-woocommerce' );
	}

	/** The hold got no clear answer: the checkout was refused and a release was asked for. */
	public static function unclear_hold_note(): string {
		return __( 'Rewloy: no clear answer came; a release of any hold was asked for.', 'rewloy-for-woocommerce' );
	}

	/** A step after the order (capture, release, refund) got no clear answer and will be tried again. */
	public static function retry_note(): string {
		return __( 'Rewloy: no clear answer came. It is tried again automatically, and Rewloy does the same when the order\'s status reaches it.', 'rewloy-for-woocommerce' );
	}

	/** Still no clear answer after the last automatic try. */
	public static function gave_up_note(): string {
		return __( 'Rewloy: still no clear answer after several tries. Look at the order\'s codes in the Rewloy panel (E-ticaret › the shop); the hold runs out by itself if the order is never taken.', 'rewloy-for-woocommerce' );
	}

	/** The card was closed after the order was placed: nothing was taken. */
	public static function closed_card_note(): string {
		return __( 'Rewloy: the card was closed after the order was placed, so nothing was taken; its hold runs out by itself.', 'rewloy-for-woocommerce' );
	}

	/** Rewloy refused a step after the order (with its code). */
	public static function refused_note( RewloyException $e ): string {
		/* translators: %s: the reason, as the plugin words it. */
		return sprintf( __( 'Rewloy: the step was refused: %s', 'rewloy-for-woocommerce' ), Messages::for_error( $e ) );
	}

	/** @param array<string,mixed> $r */
	private static function amount( array $r ): string {
		return self::money( (int) ( $r['amountMinor'] ?? 0 ), (string) ( $r['currency'] ?? '' ) );
	}

	/** @param array<string,mixed> $r */
	private static function name( array $r ): string {
		$n = is_string( $r['programName'] ?? null ) ? trim( $r['programName'] ) : '';
		return '' !== $n ? $n : __( 'Rewloy card', 'rewloy-for-woocommerce' );
	}

	/** @param array<string,mixed> $r */
	private static function card( array $r ): string {
		return (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( is_string( $r['cardLast4'] ?? null ) ? $r['cardLast4'] : '' ) );
	}

	/** An amount of a card's unit: stamps, points, visits, or money. */
	private static function units( int $n, string $unit, string $currency ): string {
		$num = number_format_i18n( $n );
		switch ( $unit ) {
			case 'stamp':
				/* translators: %s: a number of stamps. */
				return sprintf( _n( '%s stamp', '%s stamps', $n, 'rewloy-for-woocommerce' ), $num );
			case 'point':
				/* translators: %s: a number of points. */
				return sprintf( _n( '%s point', '%s points', $n, 'rewloy-for-woocommerce' ), $num );
			case 'visit':
				/* translators: %s: a number of visits. */
				return sprintf( _n( '%s visit', '%s visits', $n, 'rewloy-for-woocommerce' ), $num );
		}
		return self::money( $n, $currency );
	}
}
