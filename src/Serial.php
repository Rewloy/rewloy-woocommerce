<?php
/**
 * A card number as a person types it or a scanner reads it, and the card number as the screens may show it.
 *
 * A Rewloy card's QR code is a link to the card, `https://rewloy.com/p/<serial>?k=…`; the `k` is the card's private
 * viewing key, which shows the holder's own data. The plugin takes the serial from such a link and drops everything
 * else at once: the key is never stored, logged, sent anywhere or shown.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Serial {

	/**
	 * The card number in Rewloy's form, `XXXX-XXXX-XXXX` in capitals, from a typed number (any case, with or without
	 * dashes or spaces) or a scanned card link on rewloy.com; '' for anything else.
	 */
	public static function from_input( #[\SensitiveParameter] string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 2048 ) {
			return '';
		}
		if ( 1 === preg_match( '#^https?://#i', $raw ) ) {
			return self::from_link( $raw );
		}
		if ( false !== stripos( $raw, 'rewloy' ) ) {
			return self::from_garbled_link( $raw );
		}
		return self::normalize( $raw );
	}

	/**
	 * A card link as a USB scanner types it when the computer's keyboard layout is not the scanner's (a Turkish Q
	 * layout turns ':' '/' '-' '?' into other characters): the number is still three groups of four. Only what comes
	 * before the query is read, and nothing of the rest is kept.
	 */
	private static function from_garbled_link( #[\SensitiveParameter] string $raw ): string {
		$parts = preg_split( '/[?,]/', $raw );
		$head  = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : $raw;
		if ( 1 !== preg_match( '/(?:^|[^A-Za-z0-9])([A-Za-z0-9]{4})[^A-Za-z0-9]([A-Za-z0-9]{4})[^A-Za-z0-9]([A-Za-z0-9]{4})(?:[^A-Za-z0-9]|$)/', $head, $m ) ) {
			return '';
		}
		return self::normalize( $m[1] . $m[2] . $m[3] );
	}

	/** `ABCD-EFGH-JKLM` from 12 letters and digits in any case, with or without dashes or spaces; '' otherwise. */
	public static function normalize( string $raw ): string {
		$plain = strtoupper( (string) preg_replace( '/[\s-]+/', '', $raw ) );
		if ( 1 !== preg_match( '/^[A-Z0-9]{12}$/', $plain ) ) {
			return '';
		}
		return substr( $plain, 0, 4 ) . '-' . substr( $plain, 4, 4 ) . '-' . substr( $plain, 8, 4 );
	}

	/**
	 * The serial of a card link: https on rewloy.com or one of its subdomains, path `/p/<serial>`. The query (the
	 * viewing key) and anything after the serial are ignored and not kept.
	 */
	private static function from_link( #[\SensitiveParameter] string $url ): string {
		// Browsers read a backslash as a slash, PHP's parser does not: refuse anything that is not plain.
		if ( 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/^(?:[a-z0-9-]+\.)*rewloy\.com$/', strtolower( $parts['host'] ?? '' ) ) ) {
			return '';
		}
		if ( 1 !== preg_match( '#^/p/([A-Za-z0-9-]{12,14})/?$#', $parts['path'] ?? '', $m ) ) {
			return '';
		}
		return self::normalize( $m[1] );
	}

	/** What the watching screens show of a card: its last four characters, `••••-••••-JKLM`. '' when it is not a serial. */
	public static function mask( string $serial ): string {
		$norm = self::normalize( $serial );
		return '' === $norm ? '' : '••••-••••-' . substr( $norm, -4 );
	}
}
