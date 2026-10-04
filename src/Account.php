<?php
/**
 * My Account › "Sadakat kartım" (optional, off by default).
 *
 * WooCommerce does not verify that an account's e-mail address belongs to the
 * person who registered it. So this tab NEVER looks up or shows a card by
 * e-mail: anyone could register with someone else's address and read their
 * card. It shows a text, a button to Rewloy Cüzdan (where the person proves
 * the address with a code), and the business's join link. It makes no API
 * call and reads nothing about the customer.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Account {

	public const ENDPOINT   = 'loyalty-card';
	public const CUZDAN_URL = 'https://rewloy.com/cuzdan/';

	public function __construct( private Settings $settings ) {
	}

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	/** On and connected? */
	public function is_active(): bool {
		$s = $this->settings->get();
		return $s['account_tab'] && '' !== $s['link_id'];
	}

	public function add_endpoint(): void {
		if ( $this->is_active() ) {
			add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * @param mixed $vars WooCommerce's query vars.
	 * @return mixed
	 */
	public function query_vars( $vars ) {
		if ( is_array( $vars ) && $this->is_active() ) {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
		}
		return $vars;
	}

	/**
	 * The menu entry, before "Log out".
	 *
	 * @param mixed $items The menu items.
	 * @return mixed
	 */
	public function menu_item( $items ) {
		if ( ! is_array( $items ) || ! $this->is_active() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = $this->title();
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = $this->title();
		}
		return $out;
	}

	public function title(): string {
		return __( 'My loyalty card', 'rewloy-for-woocommerce' );
	}

	/** The tab's content. No API call, no customer data. */
	public function render(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$s = $this->settings->get();
		// No heading of its own: WooCommerce already puts the endpoint's title (see title()) at the top of the page.
		echo '<div class="rewloy-account">';
		echo '<p>' . esc_html__( 'Your loyalty card lives in Rewloy Cüzdan. Sign in there with your e-mail address: Rewloy sends you a code to prove the address is yours, and then shows your cards and balances. We do not show them here, because this shop cannot check that an address is really yours.', 'rewloy-for-woocommerce' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::CUZDAN_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Rewloy Cüzdan', 'rewloy-for-woocommerce' ) . '</a></p>';
		if ( '' !== $s['join_url'] && $this->settings->is_rewloy_url( $s['join_url'] ) ) {
			echo '<p>' . esc_html__( 'Do not have the card yet?', 'rewloy-for-woocommerce' ) . ' ';
			echo '<a href="' . esc_url( $s['join_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get it here', 'rewloy-for-woocommerce' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** Rewrite rules are flushed once after the tab is turned on or off (the Admin screen sets the flag). */
	public function maybe_flush(): void {
		if ( get_option( Plugin::FLUSH_OPTION ) ) {
			delete_option( Plugin::FLUSH_OPTION );
			flush_rewrite_rules( false );
		}
	}
}
