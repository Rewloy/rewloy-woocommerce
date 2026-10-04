<?php
/**
 * The till on the "Rewloy › Kasa" screen (0.3.0, D40–D42): a card read by number or by its QR link, a sale written to
 * it by its type (`recordSale`), and the card's own operations (`passAction`), at the ONE branch the Rewloy panel
 * opened this site's till at. Only while Rewloy says the key has `till`; Rewloy checks it again on every call.
 *
 * - The calls go from WordPress, never from the browser: the key stays on the server (Ajax.php is the door).
 * - Every write carries the Idempotency-Key the browser made for that button press (a UUID), the same on a retry of
 *   the same press, so a repeat never writes twice; the receipt number goes in `reference`, never in the key.
 * - A scanned card link's viewing key (`?k=`) is dropped as the link is read (Serial::from_input) and goes nowhere.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Till {

	/** The shape of the key the browser makes per press: a version 4 UUID. */
	private const KEY_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	public function __construct( private Panel $panel ) {
	}

	/**
	 * Reads a card at this site's branch: its state, what it accepts, and the till's notices there (wrong branch,
	 * a running promotion). The full number is shown: the person at the till holds the card.
	 *
	 * @return array{ok:bool,message:string,card:?array<string,mixed>}
	 */
	public function lookup( #[\SensitiveParameter] string $raw ): array {
		$ready = $this->ready();
		if ( null !== $ready ) {
			return $ready;
		}
		$serial = Serial::from_input( $raw );
		if ( '' === $serial ) {
			return self::no( __( 'That is not a Rewloy card number. Type the 12 letters and digits under the QR code, or scan the code.', 'rewloy-for-woocommerce' ) );
		}
		return $this->state( $serial );
	}

	/**
	 * A paid total, written to the card by its type: a stamp, points, a visit or cashback; nothing for a gift card,
	 * coupon or discount card (Rewloy says why).
	 *
	 * @param string $amount    The paid total as typed ("125", "125,50").
	 * @param string $reference The receipt number ('' for none).
	 * @param string $key       The press's Idempotency-Key.
	 * @return array{ok:bool,message:string,card:?array<string,mixed>}
	 */
	public function sale( string $serial, string $amount, string $reference, string $key ): array {
		$ready = $this->ready();
		if ( null !== $ready ) {
			return $ready;
		}
		$serial = Serial::normalize( $serial );
		$minor  = self::minor( $amount );
		if ( '' === $serial ) {
			return self::no( __( 'Read the card first.', 'rewloy-for-woocommerce' ) );
		}
		if ( null === $minor ) {
			return self::no( __( 'Enter the paid total, between 0 and 100,000.', 'rewloy-for-woocommerce' ) );
		}
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return self::no( __( 'This screen is out of date. Reload the page and try again.', 'rewloy-for-woocommerce' ) );
		}
		$reference = self::reference( $reference );
		$client    = $this->panel->client();
		if ( null === $client ) {
			return self::no( __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$r = $client->record_sale( $serial, $this->location(), $minor, $reference, $key );
		} catch ( RewloyException $e ) {
			return self::failed( $e );
		}
		return $this->written( $serial, Messages::sale_result( $r, $this->panel->settings()->get()['currency'] ) );
	}

	/**
	 * One of the card's own operations (redeem a reward, spend a balance, use a coupon…), with what it needs.
	 *
	 * @param array<string,string> $fields `amount`, `points`, `reward` as typed.
	 * @return array{ok:bool,message:string,card:?array<string,mixed>}
	 */
	public function action( string $serial, string $action, array $fields, string $key ): array {
		$ready = $this->ready();
		if ( null !== $ready ) {
			return $ready;
		}
		$serial = Serial::normalize( $serial );
		if ( '' === $serial ) {
			return self::no( __( 'Read the card first.', 'rewloy-for-woocommerce' ) );
		}
		// `load` needs a permission this plugin's key never has; anything unknown is not sent.
		if ( 'load' === $action || '' === Messages::action_label( $action ) ) {
			return self::no( __( 'That operation is not available here.', 'rewloy-for-woocommerce' ) );
		}
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return self::no( __( 'This screen is out of date. Reload the page and try again.', 'rewloy-for-woocommerce' ) );
		}
		$body = array(
			'action'     => $action,
			'locationId' => $this->location(),
		);
		if ( in_array( $action, array( 'earn-points', 'spend', 'accrue' ), true ) ) {
			$minor = self::minor( $fields['amount'] ?? '' );
			if ( null === $minor || $minor < 1 ) {
				return self::no( __( 'Enter the amount, more than 0 and at most 100,000.', 'rewloy-for-woocommerce' ) );
			}
			$body['amountMinor'] = $minor;
		}
		if ( 'spend-points' === $action ) {
			$points = trim( $fields['points'] ?? '' );
			if ( 1 !== preg_match( '/^\d{1,7}$/', $points ) || (int) $points < 1 || (int) $points > 1_000_000 ) {
				return self::no( __( 'Enter how many points to spend.', 'rewloy-for-woocommerce' ) );
			}
			$body['points'] = (int) $points;
		}
		if ( 'redeem-reward' === $action ) {
			$reward = trim( $fields['reward'] ?? '0' );
			$body['rewardIndex'] = 1 === preg_match( '/^[0-4]$/', $reward ) ? (int) $reward : 0;
		}
		$client = $this->panel->client();
		if ( null === $client ) {
			return self::no( __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$r = $client->pass_action( $serial, $body, $key );
		} catch ( RewloyException $e ) {
			return self::failed( $e );
		}
		return $this->written( $serial, Messages::action_result( $action, true === ( $r['duplicate'] ?? false ) ) );
	}

	/**
	 * The answer to a write Rewloy confirmed: what was written, and the card as it is now. If only that last read
	 * fails, the write still stands: success, never a `retry` (a retry of a written press is what the review's M1
	 * found could be pressed as a new one), and the screen says the card's state could not be refreshed.
	 *
	 * @return array{ok:bool,message:string,card:?array<string,mixed>}
	 */
	private function written( string $serial, string $message ): array {
		$state = $this->state( $serial );
		if ( ! $state['ok'] ) {
			return array(
				'ok'      => true,
				'message' => $message . ' ' . __( 'The card\'s latest state could not be refreshed; read the card again to see it.', 'rewloy-for-woocommerce' ),
				'card'    => null,
			);
		}
		return array(
			'ok'      => true,
			'message' => $message,
			'card'    => $state['card'],
		);
	}

	/**
	 * Null when the till may be used; otherwise the answer that says why not.
	 *
	 * @return array{ok:bool,message:string,card:null}|null
	 */
	private function ready(): ?array {
		$a = $this->panel->abilities();
		if ( ! $a['ok'] ) {
			return self::no( '' !== $a['error'] ? $a['error'] : __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		if ( ! $a['till'] ) {
			return self::no( __( 'The till is not turned on for this shop. It is turned on in the Rewloy panel, on this shop link\'s page.', 'rewloy-for-woocommerce' ) );
		}
		return null;
	}

	private function location(): string {
		return $this->panel->abilities()['till_location_id'];
	}

	/**
	 * The card as the till shows it: its state (getPass) and the branch's rules for it (getPassTill).
	 *
	 * @return array{ok:bool,message:string,card:?array<string,mixed>}
	 */
	private function state( string $serial ): array {
		$client = $this->panel->client();
		if ( null === $client ) {
			return self::no( __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$till = $client->get_pass_till( $serial, $this->location() );
		} catch ( RewloyException $e ) {
			return self::failed( $e );
		}
		$card = array(
			'serial'  => $serial,
			'shown'   => $serial,
			'allowed' => true === ( $till['allowed'] ?? false ),
			'notices' => self::notices( $till ),
			'actions' => array(),
			'sale'    => '',
		);
		// The card's state needs "Görüntüleme" (passes.read); without it the till still sells and shows the branch rules.
		if ( $this->panel->abilities()['view'] ) {
			try {
				$card = array_merge( $card, Panel::card_view( $client->get_pass( $serial ), $serial, true ) );
			} catch ( RewloyException $e ) {
				unset( $e );
			}
		}
		return array(
			'ok'      => true,
			'message' => '',
			'card'    => $card + array( 'customer_url' => $this->panel->links()->customer_of( $serial ) ),
		);
	}

	/**
	 * The till's notices, as Rewloy words them for the cashier (wrong branch, a running promotion), with their tone.
	 *
	 * @param array<string,mixed> $till The answer of getPassTill.
	 * @return list<array{tone:string,text:string}>
	 */
	private static function notices( array $till ): array {
		$out = array();
		foreach ( is_array( $till['notices'] ?? null ) ? $till['notices'] : array() as $n ) {
			if ( ! is_array( $n ) || ! is_string( $n['text'] ?? null ) ) {
				continue;
			}
			$tone  = in_array( $n['tone'] ?? '', array( 'block', 'promo', 'info' ), true ) ? (string) $n['tone'] : 'info';
			$text  = $n['text'] . ( is_string( $n['note'] ?? null ) && '' !== $n['note'] ? ' · ' . $n['note'] : '' );
			$out[] = array(
				'tone' => $tone,
				'text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 400 ) : substr( $text, 0, 400 ),
			);
		}
		return $out;
	}

	/** A total as typed, "125", "125,50" or "1250.5", to minor units; null unless it is between 0 and 100,000. */
	public static function minor( string $raw ): ?int {
		$raw = trim( str_replace( array( ' ', "\u{00a0}" ), '', $raw ) );
		if ( 1 !== preg_match( '/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $raw, $m ) ) {
			return null;
		}
		$minor = (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '0', 2, '0' );
		return $minor <= 10_000_000 ? $minor : null;
	}

	/** A receipt number as one plain line: no tags, no control characters, at most 80 characters. */
	private static function reference( string $raw ): string {
		$clean = trim( (string) preg_replace( '/[\p{Cc}\p{Cf}]+/u', ' ', sanitize_text_field( $raw ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, 80 ) : substr( $clean, 0, 80 );
	}

	/** @return array{ok:bool,message:string,card:null} */
	private static function no( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'card'    => null,
		);
	}

	/**
	 * A failed call in the panel's words. `retry` is set when the outcome is unknown (no answer): the same press may be
	 * sent again with the same key, and Rewloy will not write twice.
	 *
	 * @return array{ok:bool,message:string,card:null,retry?:bool}
	 */
	private static function failed( RewloyException $e ): array {
		$out = self::no( Messages::for_till_error( $e ) );
		if ( $e->outcome_unknown() ) {
			$out['retry'] = true;
		}
		return $out;
	}
}
