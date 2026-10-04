<?php
/**
 * The shop's checkout settings for Rewloy codes (0.4.0, docs/CHECKOUT-CARDS.md §12): every choice is a per-shop
 * setting with a default, kept in Rewloy on the shop's link and editable here, in Rewloy › Ayarlar.
 *
 *  - Tax treatment per kind of value: gift card (`payment`, after tax), cashback (`discount`, before tax), a money
 *    coupon (`discount`). A percent discount card is always a discount. The plugin applies it; the quote says which.
 *  - What a refunded order takes back from the card it earned on: `code_orders` (default), `all`, `never`.
 *  - Which of the business's other cards (gift card, cashback, coupon, discount card) the shop takes, within the
 *    ceiling a person set in the Rewloy panel; the shop's own card always.
 *  - How long an unpaid order may hold a card's value: 1 to 30 days, 7 by default.
 *
 * Read with `GET /v1/shops/{id}`, written with `PATCH /v1/shops/{id}/settings` (only what changed). Only an
 * administrator changes them (`manage_options`), as the till's setting (D45).
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutSettings {

	/** Names of card programmes the shop has seen on its orders (id => {name, type}); the key may not list them. */
	public const SEEN_OPTION = 'rewloy_wc_seen_programs';

	public const TAX_MODES       = array( 'payment', 'discount' );
	public const REFUND_REVERSES = array( 'code_orders', 'all', 'never' );
	public const TAX_KINDS       = array( 'giftcard', 'cashback', 'voucher' );
	/** Rewloy's defaults (§12). */
	public const DEFAULT_TAX = array(
		'giftcard' => 'payment',
		'cashback' => 'discount',
		'voucher'  => 'discount',
	);
	public const DEFAULT_REFUND = 'code_orders';
	public const DEFAULT_DAYS   = 7;

	/** @var callable(string=): ?Client */
	private $client_factory;

	/**
	 * @param Settings                   $settings       The saved settings.
	 * @param callable(string=): ?Client $client_factory A client for the saved key.
	 */
	public function __construct( private Settings $settings, callable $client_factory ) {
		$this->client_factory = $client_factory;
	}

	/**
	 * The settings as the link carries them, each of its type, Rewloy's default where one is missing.
	 *
	 * @param array<string,mixed> $link The link (`getShop`).
	 * @return array{tax:array{giftcard:string,cashback:string,voucher:string},refundReverses:string,holdDays:int,accepted:list<string>,ceiling:?list<string>,unbacked:int}
	 */
	public static function view( array $link ): array {
		$s   = is_array( $link['settings'] ?? null ) ? $link['settings'] : array();
		$tax = is_array( $s['tax'] ?? null ) ? $s['tax'] : array();
		$out = array(
			'tax'            => self::DEFAULT_TAX,
			'refundReverses' => in_array( $s['refundReverses'] ?? '', self::REFUND_REVERSES, true ) ? (string) $s['refundReverses'] : self::DEFAULT_REFUND,
			'holdDays'       => is_int( $s['holdDays'] ?? null ) && $s['holdDays'] >= 1 && $s['holdDays'] <= 30 ? $s['holdDays'] : self::DEFAULT_DAYS,
			'accepted'       => array(),
			'ceiling'        => null,
			'unbacked'       => 0,
		);
		foreach ( self::TAX_KINDS as $kind ) {
			if ( in_array( $tax[ $kind ] ?? '', self::TAX_MODES, true ) ) {
				$out['tax'][ $kind ] = (string) $tax[ $kind ];
			}
		}
		$accepts = is_array( $link['accepts'] ?? null ) ? $link['accepts'] : array();
		$out['accepted'] = self::uuids( $accepts['programIds'] ?? array() );
		if ( is_array( $accepts['ceiling'] ?? null ) ) {
			$out['ceiling'] = self::uuids( $accepts['ceiling'] );
		}
		$unbacked        = is_array( $link['unbacked'] ?? null ) ? $link['unbacked'] : array();
		$out['unbacked'] = is_int( $unbacked['count'] ?? null ) ? max( 0, $unbacked['count'] ) : 0;
		return $out;
	}

	/**
	 * Names of card programmes, by id: what the key may list, then what the shop's orders have shown.
	 *
	 * @param list<string> $ids
	 * @return array<string,array{name:string,type:string}>
	 */
	public function names( array $ids ): array {
		$out  = array();
		$seen = get_option( self::SEEN_OPTION, array() );
		foreach ( is_array( $seen ) ? $seen : array() as $id => $p ) {
			if ( is_string( $id ) && is_array( $p ) && is_string( $p['name'] ?? null ) ) {
				$out[ $id ] = array(
					'name' => $p['name'],
					'type' => is_string( $p['type'] ?? null ) ? $p['type'] : '',
				);
			}
		}
		$client = ( $this->client_factory )();
		if ( null !== $client && array() !== array_diff( $ids, array_keys( $out ) ) ) {
			try {
				foreach ( $client->list_programs() as $p ) {
					if ( is_string( $p['id'] ?? null ) && is_string( $p['name'] ?? null ) ) {
						$out[ strtolower( $p['id'] ) ] = array(
							'name' => $p['name'],
							'type' => is_string( $p['type'] ?? null ) ? $p['type'] : '',
						);
					}
				}
			} catch ( RewloyException $e ) {
				unset( $e );
			}
		}
		return array_intersect_key( $out, array_flip( $ids ) );
	}

	/**
	 * Remembers a card programme's name from a redemption Rewloy returned (the shop's key may not list programmes other
	 * than its own, but a code's answer names its card).
	 *
	 * @param array<string,mixed> $r A redemption or a quote.
	 */
	public static function remember( array $r ): void {
		$id   = is_string( $r['programId'] ?? null ) ? strtolower( $r['programId'] ) : '';
		$name = is_string( $r['programName'] ?? null ) ? substr( $r['programName'], 0, 120 ) : '';
		$type = is_string( $r['type'] ?? null ) ? substr( $r['type'], 0, 20 ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/', $id ) || '' === $name ) {
			return;
		}
		$seen = get_option( self::SEEN_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		if ( ( $seen[ $id ]['name'] ?? null ) === $name && ( $seen[ $id ]['type'] ?? null ) === $type ) {
			return;
		}
		$seen[ $id ] = array(
			'name' => $name,
			'type' => $type,
		);
		update_option( self::SEEN_OPTION, array_slice( $seen, -50, null, true ), false );
	}

	/**
	 * Saves the posted form: only what differs from what Rewloy has now goes in the PATCH.
	 *
	 * @param array<string,mixed> $posted   Unslashed form data.
	 * @param bool                $is_admin Whether the person is an administrator.
	 */
	public function save( array $posted, bool $is_admin ): Result {
		if ( ! $is_admin ) {
			return Result::error( __( 'Only an administrator can change the checkout settings.', 'rewloy-for-woocommerce' ) );
		}
		$link   = $this->settings->get()['link_id'];
		$client = '' !== $link ? ( $this->client_factory )() : null;
		if ( null === $client ) {
			return Result::error( __( 'This shop is not connected.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$now = self::view( $client->get_shop( $link ) );
		} catch ( RewloyException $e ) {
			return Result::error( Messages::for_error( $e ) );
		}
		$patch = array();
		foreach ( self::TAX_KINDS as $kind ) {
			$v = isset( $posted[ 'tax_' . $kind ] ) && is_string( $posted[ 'tax_' . $kind ] ) ? $posted[ 'tax_' . $kind ] : '';
			if ( in_array( $v, self::TAX_MODES, true ) && $v !== $now['tax'][ $kind ] ) {
				$patch['tax'][ $kind ] = $v;
			}
		}
		$refund = isset( $posted['refund_reverses'] ) && is_string( $posted['refund_reverses'] ) ? $posted['refund_reverses'] : '';
		if ( in_array( $refund, self::REFUND_REVERSES, true ) && $refund !== $now['refundReverses'] ) {
			$patch['refundReverses'] = $refund;
		}
		$days_raw = isset( $posted['hold_days'] ) && is_string( $posted['hold_days'] ) ? trim( $posted['hold_days'] ) : '';
		if ( '' !== $days_raw ) {
			if ( 1 !== preg_match( '/^\d{1,2}$/', $days_raw ) || (int) $days_raw < 1 || (int) $days_raw > 30 ) {
				return Result::error( __( 'The hold length is a whole number of days from 1 to 30.', 'rewloy-for-woocommerce' ) );
			}
			if ( (int) $days_raw !== $now['holdDays'] ) {
				$patch['holdDays'] = (int) $days_raw;
			}
		}
		if ( ! empty( $posted['accepts_shown'] ) && null !== $now['ceiling'] ) {
			$want = array_values( array_intersect( self::uuids( $posted['accepts'] ?? array() ), $now['ceiling'] ) );
			sort( $want );
			$had = $now['accepted'];
			sort( $had );
			if ( $want !== $had ) {
				$patch['accepts'] = array( 'programIds' => $want );
			}
		}
		if ( array() === $patch ) {
			return Result::ok( __( 'Nothing changed.', 'rewloy-for-woocommerce' ) );
		}
		try {
			$client->update_shop_settings( $link, $patch );
		} catch ( RewloyException $e ) {
			if ( 'OUT_OF_SCOPE' === $e->api_code ) {
				return Result::error( __( 'That card is not among the cards this plugin may switch on. A person with the rights adds it on the shop\'s page in the Rewloy panel ("Eklentinin açabileceği kartlar").', 'rewloy-for-woocommerce' ) );
			}
			return Result::error( Messages::for_error( $e ) );
		}
		return Result::ok( __( 'The checkout settings are saved in Rewloy.', 'rewloy-for-woocommerce' ) );
	}

	/**
	 * UUIDs from a list, lowercased, each once.
	 *
	 * @param mixed $raw
	 * @return list<string>
	 */
	private static function uuids( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $v ) {
			if ( is_string( $v ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v ) ) {
				$out[ strtolower( $v ) ] = true;
			}
		}
		return array_keys( $out );
	}
}
