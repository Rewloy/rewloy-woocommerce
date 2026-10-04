<?php
/**
 * The exact pages of the Rewloy panel the plugin sends people to (Rewloy's docs/API.md "Panelin adresleri", ADR 178).
 *
 * Everything the plugin does not do itself (creating and designing cards, campaigns, the team, billing, a customer's
 * details) is done there: the plugin says so and links to the page, never to a look-alike form. The panel lives on the
 * API's own origin (https://app.rewloy.com, or REWLOY_API_URL for a staging server).
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Links {

	private string $base;

	public function __construct( string $base = Client::DEFAULT_BASE_URL ) {
		$this->base = rtrim( $base, '/' );
	}

	/** The panel of the API the plugin talks to. */
	public static function for_site(): self {
		return new self( Plugin::api_base() );
	}

	public function programs(): string {
		return $this->base . '/panel/programs';
	}

	public function new_program(): string {
		return $this->base . '/panel/programs/new';
	}

	/** A card programme's page: its design and its settings are one page in the panel (no address per tab). */
	public function program( string $program_id ): string {
		return '' === self::id( $program_id ) ? $this->programs() : $this->base . '/panel/programs/' . self::id( $program_id );
	}

	/** The shop link's page; `$abilities` opens it at "WordPress yetkileri", where Görüntüleme and Kasa are changed. */
	public function shop( string $shop_id, bool $abilities = false ): string {
		if ( '' === self::id( $shop_id ) ) {
			return $this->base . '/panel/settings/shops';
		}
		return $this->base . '/panel/settings/shops/' . self::id( $shop_id ) . ( $abilities ? '#wordpress-yetkileri' : '' );
	}

	/** The customers, those of one programme when given. */
	public function customers( string $program_id = '' ): string {
		return $this->base . '/panel/customers' . ( '' !== self::id( $program_id ) ? '?program=' . self::id( $program_id ) : '' );
	}

	/**
	 * A card's holder: a card has no panel page of its own, so the customer list searched by the card number (its owner
	 * is the result, and the customer's page is one click away).
	 */
	public function customer_of( string $serial ): string {
		$s = Serial::normalize( $serial );
		return '' === $s ? $this->customers() : $this->base . '/panel/customers?q=' . rawurlencode( $s );
	}

	/** The activity record, of one card or one programme when given. */
	public function activity( string $program_id = '', string $serial = '' ): string {
		$s = Serial::normalize( $serial );
		if ( '' !== $s ) {
			return $this->base . '/panel/activity?serial=' . rawurlencode( $s );
		}
		return $this->base . '/panel/activity' . ( '' !== self::id( $program_id ) ? '?programId=' . self::id( $program_id ) : '' );
	}

	public function campaigns(): string {
		return $this->base . '/panel/campaigns';
	}

	public function analytics(): string {
		return $this->base . '/panel/analytics';
	}

	/** The panel's own till (the browser scanner). */
	public function scan(): string {
		return $this->base . '/scan';
	}

	public function team(): string {
		return $this->base . '/panel/team';
	}

	/** API keys and webhooks. */
	public function developers(): string {
		return $this->base . '/panel/developers';
	}

	public function locations(): string {
		return $this->base . '/panel/settings/locations';
	}

	/** The plan and billing. */
	public function plan(): string {
		return $this->base . '/panel/plan';
	}

	/** A Rewloy id that goes into a link: a UUID in lower case, or ''. */
	private static function id( string $id ): string {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ? strtolower( $id ) : '';
	}
}
