<?php
/**
 * A Rewloy checkout code as the customer types it into WooCommerce's coupon field (0.4.0, Rewloy ADR 179):
 * `RW-7K3M-Q9TX`, seven random characters of the serial alphabet and a check character.
 *
 * WooCommerce lowercases coupon codes; everything here works on the normalised form `RW7K3MQ9TX` (uppercase, spaces
 * and dashes out). The check character (weights 1, 3, …, 13, mod 32, as Rewloy computes it) catches a typo without a
 * call to Rewloy, so a typo never counts against the shop's failure budget there.
 *
 * The code is a bearer secret for 45 minutes: it never goes into a note, a log line, an exception or an option. What
 * the plugin keeps of it is its last four characters and `ref()`, a keyed hash that names it on the order's fee line.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class RedeemCode {

	/** The serial alphabet: no 0/O/1/I. */
	public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	/** Odd weights are units mod 32: any one wrong character changes the check character. */
	private const WEIGHTS = array( 1, 3, 5, 7, 9, 11, 13 );

	/** Uppercase, whitespace and dashes out. */
	private static function squeeze( string $raw ): string {
		return strtoupper( (string) preg_replace( '/[\s\-]+/u', '', $raw ) );
	}

	/**
	 * Does this look like a Rewloy code at all (`RW` and eight letters or digits), right or wrong? Anything else is the
	 * shop's own coupon and is left alone.
	 */
	public static function looks_like( string $raw ): bool {
		return 1 === preg_match( '/^RW[A-Z0-9]{8}$/', self::squeeze( $raw ) );
	}

	/** The normalised code (`RWXXXXXXXX`) when it is well formed and its check character is right; '' otherwise. */
	public static function normalize( string $raw ): string {
		$s = self::squeeze( $raw );
		if ( 1 !== preg_match( '/^RW[A-HJ-NP-Z2-9]{8}$/', $s ) ) {
			return '';
		}
		return self::check_char( substr( $s, 2, 7 ) ) === $s[9] ? $s : '';
	}

	/** The check character of seven characters of the alphabet. */
	public static function check_char( string $seven ): string {
		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += self::WEIGHTS[ $i ] * (int) strpos( self::ALPHABET, $seven[ $i ] ?? 'A' );
		}
		return self::ALPHABET[ $sum % 32 ];
	}

	/** How the customer sees it: `RW-XXXX-XXXX`. */
	public static function display( string $norm ): string {
		return 'RW-' . substr( $norm, 2, 4 ) . '-' . substr( $norm, 6, 4 );
	}

	/** The last four characters, for lists and notes. */
	public static function last4( string $norm ): string {
		return substr( $norm, -4 );
	}

	/**
	 * A name for the code that is not the code: 16 hex characters of a keyed hash (wp_hash, the site's salt). It ties
	 * the fee line of a gift card to its coupon line on the order without keeping the code.
	 */
	public static function ref( string $norm ): string {
		return substr( (string) wp_hash( 'rewloy-code:' . $norm ), 0, 16 );
	}
}
