<?php
/**
 * The WooCommerce webhook that carries paid orders to Rewloy, created and kept
 * by the plugin, and the filter that cuts what it carries down to what Rewloy reads.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Webhooks {

	public const TOPIC       = 'order.updated';
	public const API_VERSION = 'wp_api_v3';

	public function __construct( private Settings $settings ) {
	}

	public function register(): void {
		add_filter( 'woocommerce_webhook_payload', array( $this, 'trim_payload' ), 10, 4 );
	}

	/**
	 * Creates the webhook: topic `order.updated`, API version `wp_api_v3`, active,
	 * with the delivery address and secret Rewloy gave. Returns its id.
	 *
	 * @throws \RuntimeException When WooCommerce does not save it.
	 */
	public function create( string $delivery_url, #[\SensitiveParameter] string $secret ): int {
		$hook = new \WC_Webhook();
		$hook->set_name( 'Rewloy' );
		$hook->set_user_id( get_current_user_id() );
		$hook->set_topic( self::TOPIC );
		$hook->set_api_version( self::API_VERSION );
		$hook->set_delivery_url( $delivery_url );
		$hook->set_secret( $secret );
		$hook->set_status( 'active' );
		$id = (int) $hook->save();
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'WooCommerce did not save the webhook.' );
		}
		return $id;
	}

	/**
	 * The webhook with this id, if it is ours: its delivery address names this shop's link
	 * (`/hooks/store/<link id>`). An id that was reused, or edited by hand, never reaches
	 * another webhook.
	 */
	private function owned( int $id ): ?\WC_Webhook {
		$hook = $id > 0 ? wc_get_webhook( $id ) : null;
		if ( ! $hook instanceof \WC_Webhook ) {
			return null;
		}
		$link = strtolower( $this->settings->get()['link_id'] );
		$path = wp_parse_url( (string) $hook->get_delivery_url(), PHP_URL_PATH );
		return '' !== $link && is_string( $path ) && '/hooks/store/' . $link === strtolower( $path ) ? $hook : null;
	}

	/**
	 * What the screen shows of the webhook.
	 *
	 * @return array{exists:bool,status:string,failures:int,edit_url:string}
	 */
	public function health( int $id ): array {
		$hook = $this->owned( $id );
		if ( null === $hook ) {
			return array(
				'exists'   => false,
				'status'   => '',
				'failures' => 0,
				'edit_url' => '',
			);
		}
		return array(
			'exists'   => true,
			'status'   => (string) $hook->get_status(),
			'failures' => (int) $hook->get_failure_count(),
			'edit_url' => admin_url( 'admin.php?page=wc-settings&tab=advanced&section=webhooks&edit-webhook=' . $id ),
		);
	}

	/** Back to active with a clean failure count (WooCommerce disables a webhook after repeated failures). */
	public function reactivate( int $id ): bool {
		$hook = $this->owned( $id );
		if ( null === $hook ) {
			return false;
		}
		$hook->set_failure_count( 0 ); // @phpstan-ignore argument.type (the stub's docblock says bool; WooCommerce stores an integer)
		$hook->set_status( 'active' );
		$hook->save();
		return true;
	}

	/**
	 * Delivers one order through the webhook again: what Rewloy asks for (`order.result: resend`) when the order's
	 * notification came before the card did. WooCommerce's own entry point is used (`process`), the one a real order
	 * update goes through, so the delivery is queued the same way, signed the same way and cut down by trim_payload()
	 * the same way. It is not delivered from here: WooCommerce sends it from its own queue.
	 *
	 * @return bool True when the webhook is ours and active and the order was handed to it; false otherwise. WooCommerce
	 *              does not say whether it queued the delivery (it may refuse an order it finds invalid), so true is
	 *              "handed over", not "will arrive".
	 */
	public function redeliver( int $id, int $order_id ): bool {
		$hook = $this->owned( $id );
		if ( null === $hook || 'active' !== (string) $hook->get_status() || $order_id <= 0 ) {
			return false;
		}
		$hook->process( $order_id );
		return true;
	}

	public function delete( int $id ): void {
		$hook = $this->owned( $id );
		if ( null !== $hook ) {
			$hook->delete( true );
		}
	}

	/**
	 * Only for the plugin's own webhook: send Rewloy the order's number, status,
	 * currency and total, and the billing e-mail only once the order is paid
	 * (processing or completed: Rewloy ignores an unpaid order without reading its
	 * e-mail), and nothing else. WooCommerce's order payload also holds names,
	 * addresses, phone numbers and line items; Rewloy reads none of them. The
	 * webhook's signature is computed over what is sent. The values are read from
	 * the order itself, so they do not depend on the webhook user's REST rights.
	 *
	 * @param mixed $payload     The payload WooCommerce built.
	 * @param mixed $resource    The resource ("order").
	 * @param mixed $resource_id The resource's id.
	 * @param mixed $webhook_id  The webhook delivering it.
	 * @return mixed
	 */
	public function trim_payload( $payload, $resource = '', $resource_id = 0, $webhook_id = 0 ) {
		$mine = $this->settings->get()['webhook_id'];
		if ( $mine <= 0 || (int) $webhook_id !== $mine || 'order' !== $resource || ! is_array( $payload ) ) {
			return $payload;
		}
		// The order itself is the source, not WooCommerce's REST payload: that payload is built as the webhook's
		// user, and when that user was deleted or lost the right to read orders it is an error array, which would
		// reach Rewloy as an empty order (answered 200 "ignored": no failure anywhere, and no order ever credited).
		$order = is_numeric( $resource_id ) && (int) $resource_id > 0 ? wc_get_order( (int) $resource_id ) : false;
		if ( $order instanceof \WC_Order ) {
			$status = (string) $order->get_status();
			return array(
				'id'       => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'status'   => $status,
				'currency' => (string) $order->get_currency(),
				'total'    => (string) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'billing'  => array( 'email' => in_array( $status, array( 'processing', 'completed' ), true ) ? (string) $order->get_billing_email() : '' ),
			);
		}
		$billing = is_array( $payload['billing'] ?? null ) ? $payload['billing'] : array();
		$paid    = in_array( $payload['status'] ?? '', array( 'processing', 'completed' ), true );
		return array(
			'id'       => $payload['id'] ?? $resource_id,
			'number'   => $payload['number'] ?? '',
			'status'   => $payload['status'] ?? '',
			'currency' => $payload['currency'] ?? '',
			'total'    => $payload['total'] ?? '',
			'billing'  => array( 'email' => $paid && is_string( $billing['email'] ?? null ) ? $billing['email'] : '' ),
		);
	}
}
