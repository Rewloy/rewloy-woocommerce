<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Account;

final class AccountTest extends TestCase {

	private function render( Account $a ): string {
		ob_start();
		$a->render();
		return (string) ob_get_clean();
	}

	public function test_the_tab_makes_no_api_call_and_shows_no_customer_data(): void {
		// A logged-in customer whose e-mail might (or might not) match a card: nothing may be looked up or shown.
		$user               = new \stdClass();
		$user->user_email   = 'victim@example.com';
		$user->display_name = 'Mağdur Kişi';
		Functions\when( 'wp_get_current_user' )->justReturn( $user );
		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'WC' )->never();

		$html = $this->render( new Account( $this->connected( array( 'account_tab' => true ) ) ) );

		$this->assertSame( array(), $this->requests );
		$this->assertStringNotContainsString( 'victim@example.com', $html );
		$this->assertStringNotContainsString( 'Mağdur', $html );
		$this->assertDoesNotMatchRegularExpression( '/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $html, 'no card serial' );
		$this->assertStringContainsString( 'https://rewloy.com/cuzdan/', $html );
		$this->assertStringContainsString( 'https://rewloy.com/join/' . self::PROGRAM, $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
	}

	public function test_the_tab_explains_why_it_shows_no_card(): void {
		$html = $this->render( new Account( $this->connected( array( 'account_tab' => true ) ) ) );
		$this->assertStringContainsString( 'prove the address is yours', $html );
	}

	public function test_without_a_join_link_only_the_wallet_button_is_shown(): void {
		$html = $this->render( new Account( $this->connected( array( 'account_tab' => true, 'join_url' => '' ) ) ) );
		$this->assertStringContainsString( 'https://rewloy.com/cuzdan/', $html );
		$this->assertStringNotContainsString( 'Get it here', $html );
	}

	public function test_a_join_link_off_rewloy_com_is_not_printed(): void {
		$html = $this->render( new Account( $this->connected( array( 'account_tab' => true, 'join_url' => 'https://evil.test/join/x' ) ) ) );
		$this->assertStringNotContainsString( 'evil.test', $html );
	}

	public function test_the_tab_has_no_heading_of_its_own(): void {
		// WooCommerce already puts the endpoint's title at the top of the page; a second one would repeat it.
		$html = $this->render( new Account( $this->connected( array( 'account_tab' => true ) ) ) );
		$this->assertDoesNotMatchRegularExpression( '/<h[1-6]/', $html );
	}

	public function test_nothing_is_rendered_while_off(): void {
		$this->assertSame( '', $this->render( new Account( $this->connected( array( 'account_tab' => false ) ) ) ) );
	}

	public function test_the_menu_item_is_added_before_logout_only_when_on(): void {
		$items = array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'customer-logout' => 'Log out' );
		$on    = ( new Account( $this->connected( array( 'account_tab' => true ) ) ) )->menu_item( $items );
		$this->assertSame( array( 'dashboard', 'orders', 'loyalty-card', 'customer-logout' ), array_keys( $on ) );
		$off = ( new Account( $this->connected( array( 'account_tab' => false ) ) ) )->menu_item( $items );
		$this->assertSame( $items, $off );
	}

	public function test_the_endpoint_is_registered_only_when_on(): void {
		Functions\expect( 'add_rewrite_endpoint' )->once()->with( 'loyalty-card', \Mockery::any() );
		( new Account( $this->connected( array( 'account_tab' => true ) ) ) )->add_endpoint();
		( new Account( $this->connected( array( 'account_tab' => false ) ) ) )->add_endpoint();
		$this->addToAssertionCount( 1 );
	}

	public function test_rewrite_rules_are_flushed_once_after_a_change(): void {
		$this->options[ \Rewloy\WooCommerce\Plugin::FLUSH_OPTION ] = '1';
		Functions\expect( 'flush_rewrite_rules' )->once()->with( false );
		$a = new Account( $this->connected() );
		$a->maybe_flush();
		$a->maybe_flush();
		$this->assertArrayNotHasKey( \Rewloy\WooCommerce\Plugin::FLUSH_OPTION, $this->options );
	}
}
