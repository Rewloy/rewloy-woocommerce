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
		$hook     = new \WC_Webhook();
		$hook->id = 41;
		$hook->set_status( 'disabled' );
		$hook->set_failure_count( 5 );
		$hook->save();
		$this->assertTrue( ( new Webhooks( $this->connected() ) )->reactivate( 41 ) );
		$this->assertSame( 'active', $hook->props['status'] );
		$this->assertSame( 0, $hook->props['failure_count'] );
	}
}
