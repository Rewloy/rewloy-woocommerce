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

	/** What an outcome means, in the panel's words. */
	public static function outcome_why( string $outcome ): string {
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
				return __( 'This API key is not allowed to do that. It needs the permissions to see cards and settings, to manage API keys and shop links, and to issue cards.', 'rewloy-for-woocommerce' );
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
				return __( 'Rewloy is receiving too many requests from this key. Try again in a minute.', 'rewloy-for-woocommerce' );
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
