<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Filters;
use Rewloy\WooCommerce\Webhooks;

final class WebhooksTest extends TestCase {

	private function fullOrder(): array {
		return array(
			'id'       => 55,
			'number'   => '55',
			'status'   => 'processing',
			'currency' => 'TRY',
			'total'    => '250.00',
			'billing'  => array(
				'first_name' => 'Ayşe',
				'last_name'  => 'Yılmaz',
				'address_1'  => 'Gizli Sokak 1',
				'phone'      => '+905551112233',
				'email'      => 'ayse@example.com',
			),
			'shipping'   => array( 'address_1' => 'Başka Sokak' ),
			'line_items' => array( array( 'name' => 'Kahve' ) ),
			'customer_ip_address' => '1.2.3.4',
		);
	}

	public function test_the_filter_is_hooked(): void {
		( new Webhooks( $this->connected() ) )->register();
		$this->assertNotFalse( Filters\has( 'woocommerce_webhook_payload', 'Rewloy\WooCommerce\Webhooks->trim_payload()' ) );
	}

	public function test_our_webhook_carries_only_what_rewloy_reads(): void {
		$hooks = new Webhooks( $this->connected() );
		$out   = $hooks->trim_payload( $this->fullOrder(), 'order', 55, 41 );
		$this->assertSame(
			array(
				'id'       => 55,
				'number'   => '55',
				'status'   => 'processing',
				'currency' => 'TRY',
				'total'    => '250.00',
				'billing'  => array( 'email' => 'ayse@example.com' ),
			),
			$out
		);
		$json = (string) json_encode( $out );
		foreach ( array( 'Ayşe', 'Yılmaz', 'Gizli Sokak', '+90555', 'Başka', 'Kahve', '1.2.3.4' ) as $private ) {
			$this->assertStringNotContainsString( $private, $json );
		}
	}

	public function test_the_billing_email_goes_only_once_the_order_is_paid(): void {
		$hooks = new Webhooks( $this->connected() );
		foreach ( array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded', '' ) as $status ) {
			$order           = $this->fullOrder();
			$order['status'] = $status;
			$out             = $hooks->trim_payload( $order, 'order', 55, 41 );
			$this->assertSame( '', $out['billing']['email'], $status );
			$this->assertStringNotContainsString( 'ayse@example.com', (string) json_encode( $out ), $status );
		}
		foreach ( array( 'processing', 'completed' ) as $status ) {
			$order           = $this->fullOrder();
			$order['status'] = $status;
			$this->assertSame( 'ayse@example.com', $hooks->trim_payload( $order, 'order', 55, 41 )['billing']['email'], $status );
		}
	}

	public function test_only_a_webhook_that_delivers_to_our_link_is_touched(): void {
		$settings = $this->connected();
		$hooks    = new Webhooks( $settings );
		$other    = $this->makeWebhook( 41, 'https://other.example/hook', 'disabled', 3 );
		$this->assertFalse( $hooks->health( 41 )['exists'] );
		$this->assertFalse( $hooks->reactivate( 41 ) );
		$hooks->delete( 41 );
		$this->assertFalse( $other->deleted, 'the id is stored, but the address does not name our link' );
		$this->assertSame( 'disabled', $other->props['status'] );

		$wrongLink = $this->makeWebhook( 42, 'https://app.rewloy.com/hooks/store/0192ffff-5c6d-7e8f-9a0b-1c2d3e4f5a6b' );
		$this->assertFalse( ( new Webhooks( $this->connected( array( 'webhook_id' => 42 ) ) ) )->reactivate( 42 ) );
		$this->assertFalse( $wrongLink->deleted );
	}

	public function test_another_webhook_is_left_alone(): void {
		$hooks = new Webhooks( $this->connected() );
		$order = $this->fullOrder();
		$this->assertSame( $order, $hooks->trim_payload( $order, 'order', 55, 99 ), 'somebody else\'s webhook' );
		$this->assertSame( $order, $hooks->trim_payload( $order, 'product', 55, 41 ), 'not an order' );
		$this->assertSame( $order, $hooks->trim_payload( $order, 'order', 55 ), 'no webhook id' );
		$this->assertSame( 'x', $hooks->trim_payload( 'x', 'order', 55, 41 ) );
	}

	public function test_nothing_is_trimmed_when_not_connected(): void {
		$hooks = new Webhooks( new \Rewloy\WooCommerce\Settings() );
		$order = $this->fullOrder();
		$this->assertSame( $order, $hooks->trim_payload( $order, 'order', 55, 0 ) );
	}

	public function test_health_of_a_missing_webhook(): void {
		$h = ( new Webhooks( $this->connected() ) )->health( 41 );
		$this->assertFalse( $h['exists'] );
		$this->assertFalse( ( new Webhooks( $this->connected() ) )->reactivate( 41 ) );
	}

	public function test_reactivate_turns_a_disabled_webhook_on_with_a_clean_count(): void {
		$hook = $this->makeWebhook( 41, null, 'disabled', 5 );
		$this->assertTrue( ( new Webhooks( $this->connected() ) )->reactivate( 41 ) );
		$this->assertSame( 'active', $hook->props['status'] );
		$this->assertSame( 0, $hook->props['failure_count'] );
	}
}
