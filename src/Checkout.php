<?php
/**
 * The invitation at checkout (optional, off by default): a box, unticked, with
 * the privacy notice beside it, on the classic checkout and (WooCommerce 8.9+)
 * the block checkout.
 *
 * The notice is information, not a consent box: what ticking the box asks for
 * is the card. That the notice was shown is what the API's `kvkkConsent` states.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Checkout {

	public const FIELD = 'rewloy_invite';
	public const PRIVACY_URL = 'https://rewloy.com/gizlilik#kart-sahipleri';

	public function __construct( private Settings $settings, private Issuer $issuer ) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_box' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_choice' ), 10, 2 );
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_order_status_processing', array( $this->issuer, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this->issuer, 'on_paid' ), 10, 2 );
		add_action( Issuer::HOOK, array( $this->issuer, 'run_queued' ) );
		add_filter( 'woocommerce_order_actions', array( $this->issuer, 'order_actions' ) );
		add_action( 'woocommerce_order_action_rewloy_retry_card', array( $this->issuer, 'retry' ) );
	}

	/** The box's words: the card's name in the sentence. */
	public function label(): string {
		$name = $this->settings->get()['program_name'];
		return '' !== $name
			/* translators: %s: the loyalty card's name. */
			? sprintf( __( 'Open the "%s" loyalty card for me, with my billing e-mail address.', 'rewloy-for-woocommerce' ), $name )
			: __( 'Open a loyalty card for me, with my billing e-mail address.', 'rewloy-for-woocommerce' );
	}

	/** Who answers for the data, and what is done with it: the join form's own note, in the shop's name. */
	public function notice(): string {
		$s    = $this->settings->get();
		$name = '' !== $s['controller_name'] ? $s['controller_name'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$who  = '' !== $s['controller_email'] ? $name . ' (' . $s['controller_email'] . ')' : $name;
		/* translators: %s: the shop's (data controller's) name, with its e-mail address when set. */
		return sprintf( __( 'Data controller: %s. Your details are processed so the card works and for the messages the business sends through it; you can turn messages off in Rewloy Cüzdan. Rewloy runs the card on this business\'s behalf. Details are in the privacy notice.', 'rewloy-for-woocommerce' ), $who );
	}

	/** The classic checkout's box. Never pre-ticked, never required. */
	public function render_box(): void {
		if ( ! $this->issuer->is_active() ) {
			return;
		}
		echo '<p class="form-row rewloy-invite">';
		echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
		echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" value="1" />';
		echo '<span>' . esc_html( $this->label() ) . '</span></label>';
		echo '<small class="rewloy-invite__notice" style="display:block;margin-top:.25em">' . esc_html( $this->notice() ) . ' ';
		echo '<a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy notice', 'rewloy-for-woocommerce' ) . '</a></small>';
		echo '</p>';
	}

	/**
	 * Keeps the choice on the order. WooCommerce has already checked its own nonce for the checkout request.
	 *
	 * @param \WC_Order           $order The order being created.
	 * @param array<string,mixed> $data  The posted checkout data.
	 */
	public function save_choice( $order, $data = array() ): void {
		unset( $data );
		if ( ! $order instanceof \WC_Order || ! $this->issuer->is_active() ) {
			return;
		}
		// WooCommerce verifies the checkout nonce before this runs.
		$raw    = $_POST[ self::FIELD ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked and sanitised below.
		$posted = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		if ( '1' === $posted ) {
			$order->update_meta_data( Issuer::META_INVITE, 'yes' );
		}
	}

	/** The block checkout's box (WooCommerce 8.9+ has the additional checkout fields API). */
	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! $this->issuer->is_active() ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'rewloy-for-woocommerce/invite',
				'label'    => $this->label() . ' ' . $this->notice() . ' ' . self::PRIVACY_URL,
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
	}
}
