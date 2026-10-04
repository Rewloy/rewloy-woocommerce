<?php
/**
 * The door between the "Rewloy" screens' small scripts and Rewloy (0.3.0, D41): the browser never holds the API key
 * and never talks to Rewloy; it asks WordPress, and WordPress asks Rewloy with the key it keeps.
 *
 * Every action: `manage_woocommerce` first, then a POST, then the screens' nonce, then sanitised input; the answer is
 * JSON the script writes into the page as text (never as HTML).
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	/** The nonce action every screen script carries. */
	public const NONCE = 'rewloy_wc_panel';
	/** The admin-ajax actions, each `wp_ajax_<name>`. */
	public const ACTIONS = array( 'rewloy_wc_activity', 'rewloy_wc_till_lookup', 'rewloy_wc_till_sale', 'rewloy_wc_till_action' );

	/** @var callable(array<string,mixed>, int): void */
	private $respond;

	/**
	 * @param Panel                                           $panel   The reads.
	 * @param Till                                            $till    The till.
	 * @param (callable(array<string,mixed>, int): void)|null $respond Replaces wp_send_json (tests).
	 */
	public function __construct( private Panel $panel, private Till $till, ?callable $respond = null ) {
		$this->respond = $respond ?? static function ( array $body, int $status ): void {
			wp_send_json( $body, $status );
		};
	}

	public function register(): void {
		add_action( 'wp_ajax_rewloy_wc_activity', array( $this, 'activity' ) );
		add_action( 'wp_ajax_rewloy_wc_till_lookup', array( $this, 'till_lookup' ) );
		add_action( 'wp_ajax_rewloy_wc_till_sale', array( $this, 'till_sale' ) );
		add_action( 'wp_ajax_rewloy_wc_till_action', array( $this, 'till_action' ) );
	}

	/** The latest activity on the programme's cards, for the watching screen's refresh. */
	public function activity(): void {
		if ( ! $this->allowed() ) {
			return;
		}
		if ( ! $this->panel->abilities()['view'] ) {
			$this->send( array( 'ok' => false, 'message' => __( '"Görüntüleme" is off for this shop.', 'rewloy-for-woocommerce' ) ), 403 );
			return;
		}
		$a = $this->panel->activity( 20 );
		$this->send(
			array(
				'ok'      => $a['ok'],
				'message' => $a['error'],
				'rows'    => $a['rows'],
			),
			200
		);
	}

	public function till_lookup(): void {
		if ( ! $this->allowed( Capability::TILL ) ) {
			return;
		}
		$this->send( $this->till->lookup( $this->post( 'card' ) ), 200 );
	}

	public function till_sale(): void {
		if ( ! $this->allowed( Capability::TILL ) ) {
			return;
		}
		$this->send( $this->till->sale( $this->post( 'serial' ), $this->post( 'amount' ), $this->post( 'reference' ), $this->post( 'key' ) ), 200 );
	}

	public function till_action(): void {
		if ( ! $this->allowed( Capability::TILL ) ) {
			return;
		}
		$fields = array(
			'amount' => $this->post( 'amount' ),
			'points' => $this->post( 'points' ),
			'reward' => $this->post( 'reward' ),
		);
		$this->send( $this->till->action( $this->post( 'serial' ), $this->post( 'operation' ), $fields, $this->post( 'key' ) ), 200 );
	}

	/**
	 * Capability, then a POST, then the nonce; each refusal answered once, in JSON. The till's actions need the till's
	 * own capability as well (D45).
	 */
	private function allowed( string $also = '' ): bool {
		if ( ! current_user_can( Admin::CAPABILITY ) || ( '' !== $also && ! current_user_can( $also ) ) ) {
			$this->send( array( 'ok' => false, 'message' => __( 'You are not allowed to do this.', 'rewloy-for-woocommerce' ) ), 403 );
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			$this->send( array( 'ok' => false, 'message' => __( 'You are not allowed to do this.', 'rewloy-for-woocommerce' ) ), 405 );
			return false;
		}
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			$this->send( array( 'ok' => false, 'message' => __( 'This screen is out of date. Reload the page and try again.', 'rewloy-for-woocommerce' ) ), 403 );
			return false;
		}
		return true;
	}

	/** One posted value, unslashed and cleaned of tags and line breaks. */
	private function post( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- allowed() checks the nonce first.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) : '';
	}

	/** @param array<string,mixed> $body */
	private function send( array $body, int $status ): void {
		( $this->respond )( $body, $status );
	}
}
