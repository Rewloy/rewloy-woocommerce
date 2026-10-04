<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Checkout;
use Rewloy\WooCommerce\Issuer;
use Rewloy\WooCommerce\Settings;

final class CheckoutTest extends TestCase {

	private function checkout( Settings $settings ): Checkout {
		return new Checkout( $settings, new Issuer( $settings, $this->factory() ) );
	}

	private function box( Checkout $c ): string {
		ob_start();
		$c->render_box();
		return (string) ob_get_clean();
	}

	protected function tearDown(): void {
		unset( $_POST[ Checkout::FIELD ] );
		parent::tearDown();
	}

	public function test_the_box_is_shown_when_the_invitation_is_on_and_is_never_pre_ticked_or_required(): void {
		$html = $this->box( $this->checkout( $this->connected( array( 'controller_name' => 'Örnek A.Ş.', 'controller_email' => 'kvkk@ornek.com' ) ) ) );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'name="rewloy_invite"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringNotContainsString( 'required', $html );
		$this->assertStringContainsString( 'Kahve Kartı', $html );
		$this->assertStringContainsString( 'Data controller: Örnek A.Ş. (kvkk@ornek.com)', $html );
		$this->assertStringContainsString( 'https://rewloy.com/gizlilik#kart-sahipleri', $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
	}

	public function test_the_controller_defaults_to_the_site_name(): void {
		$html = $this->box( $this->checkout( $this->connected() ) );
		$this->assertStringContainsString( 'Data controller: Örnek Mağaza.', $html );
	}

	public function test_nothing_is_shown_when_the_invitation_is_off_or_nothing_is_connected(): void {
		$this->assertSame( '', $this->box( $this->checkout( $this->connected( array( 'invite' => false ) ) ) ) );
		$this->assertSame( '', $this->box( $this->checkout( new Settings() ) ) );
	}

	public function test_everything_printed_is_escaped(): void {
		$html = $this->box( $this->checkout( $this->connected( array( 'program_name' => '<script>alert(1)</script>', 'controller_name' => '"><img src=x onerror=alert(2)>' ) ) ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_a_ticked_box_is_kept_on_the_order(): void {
		$_POST[ Checkout::FIELD ] = '1';
		$order                    = new \WC_Order( 5 );
		$this->checkout( $this->connected() )->save_choice( $order, array() );
		$this->assertSame( 'yes', $order->get_meta( Issuer::META_INVITE ) );
	}

	public function test_an_unticked_box_or_another_value_keeps_nothing(): void {
		$c = $this->checkout( $this->connected() );
		$order = new \WC_Order( 5 );
		$c->save_choice( $order, array() );
		$this->assertSame( '', $order->get_meta( Issuer::META_INVITE ), 'nothing posted' );
		foreach ( array( '0', '', 'yes', 'on', 'true' ) as $v ) {
			$_POST[ Checkout::FIELD ] = $v;
			$order                    = new \WC_Order( 6 );
			$c->save_choice( $order, array() );
			$this->assertSame( '', $order->get_meta( Issuer::META_INVITE ), "value '$v'" );
		}
	}

	public function test_a_posted_array_is_not_a_tick_and_raises_no_warning(): void {
		$_POST[ Checkout::FIELD ] = array( '1' );
		$order                    = new \WC_Order( 5 );
		$this->checkout( $this->connected() )->save_choice( $order, array() );
		$this->assertSame( '', $order->get_meta( Issuer::META_INVITE ) );
	}

	public function test_a_tick_is_not_kept_while_the_invitation_is_off(): void {
		$_POST[ Checkout::FIELD ] = '1';
		$order                    = new \WC_Order( 5 );
		$this->checkout( $this->connected( array( 'invite' => false ) ) )->save_choice( $order, array() );
		$this->assertSame( '', $order->get_meta( Issuer::META_INVITE ) );
	}

	public function test_the_block_checkout_gets_an_optional_checkbox_field(): void {
		Functions\expect( 'woocommerce_register_additional_checkout_field' )->once()->with(
			\Mockery::on(
				static function ( array $args ): bool {
					return 'rewloy-for-woocommerce/invite' === $args['id']
						&& 'checkbox' === $args['type']
						&& 'order' === $args['location']
						&& false === $args['required']
						&& str_contains( $args['label'], 'Data controller' )
						&& str_contains( $args['label'], 'https://rewloy.com/gizlilik#kart-sahipleri' );
				}
			)
		);
		$this->checkout( $this->connected() )->register_block_field();
		$this->addToAssertionCount( 1 );
	}

	public function test_the_block_field_is_not_registered_while_off(): void {
		Functions\expect( 'woocommerce_register_additional_checkout_field' )->never();
		$this->checkout( $this->connected( array( 'invite' => false ) ) )->register_block_field();
		$this->addToAssertionCount( 1 );
	}

	public function test_register_hooks_checkout_and_both_paid_statuses(): void {
		$c = $this->checkout( $this->connected() );
		$c->register();
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', 'Rewloy\WooCommerce\Checkout->render_box()' ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_create_order', 'Rewloy\WooCommerce\Checkout->save_choice()' ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_processing', 'Rewloy\WooCommerce\Issuer->on_paid()' ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_completed', 'Rewloy\WooCommerce\Issuer->on_paid()' ) );
		$this->assertNotFalse( has_action( Issuer::HOOK, 'Rewloy\WooCommerce\Issuer->run_queued()' ) );
		foreach ( array( 'woocommerce_order_status_pending', 'woocommerce_order_status_on-hold', 'woocommerce_order_status_cancelled', 'woocommerce_payment_complete' ) as $unpaid ) {
			$this->assertFalse( has_action( $unpaid, 'Rewloy\WooCommerce\Issuer->on_paid()' ), $unpaid );
		}
	}
}
