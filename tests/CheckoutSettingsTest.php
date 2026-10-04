<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Admin;
use Rewloy\WooCommerce\CheckoutSettings;
use Rewloy\WooCommerce\Connection;
use Rewloy\WooCommerce\Webhooks;

/** The checkout codes' settings in Rewloy › Ayarlar (0.4.0, §12): read from the link, saved with a PATCH of what changed. */
final class CheckoutSettingsTest extends TestCase {

	private const GIFT = '0192eeee-5c6d-7e8f-9a0b-1c2d3e4f5a01';
	private const DISC = '0192eeee-5c6d-7e8f-9a0b-1c2d3e4f5a02';
	private bool $isAdmin = true;

	protected function setUp(): void {
		parent::setUp();
		$this->isAdmin = true;
		Functions\when( 'current_user_can' )->alias( fn( string $cap ) => 'manage_options' === $cap ? $this->isAdmin : $this->can );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_nonce_field' )->alias( static function ( $action ): void {
			echo '<input type="hidden" name="_wpnonce" value="NONCE-' . $action . '" />';
		} );
		Functions\when( 'submit_button' )->alias( static function ( $text = '' ): void {
			echo '<button type="submit">' . htmlspecialchars( (string) $text ) . '</button>';
		} );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = true ) => $a == $b ? ' checked="checked"' : '' );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ) => gmdate( 'd.m.Y H:i', $ts ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) );
		Functions\when( 'plugin_basename' )->justReturn( 'rewloy-for-woocommerce/rewloy-for-woocommerce.php' );
	}

	private function link( array $settings = array(), ?array $ceiling = array( self::GIFT, self::DISC ), array $accepted = array() ): array {
		return array(
			'id'       => self::LINK,
			'enabled'  => true,
			'orders'   => array(),
			'settings' => array_merge( array( 'tax' => array( 'giftcard' => 'payment', 'cashback' => 'discount', 'voucher' => 'discount' ), 'refundReverses' => 'code_orders', 'holdDays' => 7 ), $settings ),
			'accepts'  => array( 'programIds' => $accepted, 'ceiling' => $ceiling ),
			'unbacked' => array( 'count' => 0, 'lastAt' => null ),
		);
	}

	private function cs(): CheckoutSettings {
		$s = $this->connected();
		$s->save_api_key( self::PLUGIN_KEY );
		return new CheckoutSettings( $s, $this->factory() );
	}

	public function test_the_view_takes_rewloys_values_and_its_defaults_where_one_is_missing(): void {
		$v = CheckoutSettings::view( array() );
		$this->assertSame( array( 'giftcard' => 'payment', 'cashback' => 'discount', 'voucher' => 'discount' ), $v['tax'] );
		$this->assertSame( array( 'code_orders', 7, null ), array( $v['refundReverses'], $v['holdDays'], $v['ceiling'] ) );
		$v = CheckoutSettings::view( $this->link( array( 'tax' => array( 'giftcard' => 'discount', 'cashback' => 'bogus' ), 'holdDays' => 99 ), array( strtoupper( self::GIFT ), 'not-a-uuid' ) ) );
		$this->assertSame( 'discount', $v['tax']['giftcard'] );
		$this->assertSame( 'discount', $v['tax']['cashback'], 'an unknown value falls back to the default' );
		$this->assertSame( 7, $v['holdDays'] );
		$this->assertSame( array( self::GIFT ), $v['ceiling'] );
	}

	public function test_only_what_changed_is_sent(): void {
		$this->script( $this->answer( 200, array( 'data' => $this->link() ) ), $this->answer( 200, array( 'data' => $this->link() ) ) );
		$r = $this->cs()->save(
			array( 'tax_giftcard' => 'discount', 'tax_cashback' => 'discount', 'tax_voucher' => 'payment', 'refund_reverses' => 'all', 'hold_days' => '7', 'accepts_shown' => '1', 'accepts' => array( self::GIFT, '0192ffff-5c6d-7e8f-9a0b-1c2d3e4f5a99' ) ),
			true
		);
		$this->assertTrue( $r->ok );
		$this->assertSame( 'PATCH', $this->requests[1]['args']['method'] );
		$this->assertSame(
			array( 'tax' => array( 'giftcard' => 'discount', 'voucher' => 'payment' ), 'refundReverses' => 'all', 'accepts' => array( 'programIds' => array( self::GIFT ) ) ),
			json_decode( $this->requests[1]['args']['body'], true ),
			'the hold days did not change, and a programme outside the ceiling is never sent'
		);
	}

	public function test_nothing_changed_sends_no_patch(): void {
		$this->script( $this->answer( 200, array( 'data' => $this->link() ) ) );
		$r = $this->cs()->save( array( 'tax_giftcard' => 'payment', 'hold_days' => '7', 'accepts_shown' => '1' ), true );
		$this->assertTrue( $r->ok );
		$this->assertSame( 'Nothing changed.', $r->message );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_hold_length_is_one_to_thirty_days(): void {
		foreach ( array( '0', '31', 'abc', '7.5' ) as $bad ) {
			$this->script( $this->answer( 200, array( 'data' => $this->link() ) ) );
			$r = $this->cs()->save( array( 'hold_days' => $bad ), true );
			$this->assertFalse( $r->ok, $bad );
		}
		$this->script( $this->answer( 200, array( 'data' => $this->link() ) ), $this->answer( 200, array( 'data' => $this->link() ) ) );
		$this->cs()->save( array( 'hold_days' => '30' ), true );
		$this->assertSame( array( 'holdDays' => 30 ), json_decode( (string) end( $this->requests )['args']['body'], true ) );
	}

	public function test_only_an_administrator_changes_them(): void {
		$r = $this->cs()->save( array( 'tax_giftcard' => 'discount' ), false );
		$this->assertFalse( $r->ok );
		$this->assertSame( 'Only an administrator can change the checkout settings.', $r->message );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_card_outside_the_ceiling_is_explained(): void {
		$this->script( $this->answer( 200, array( 'data' => $this->link() ) ), $this->failure( 403, 'OUT_OF_SCOPE' ) );
		$r = $this->cs()->save( array( 'accepts_shown' => '1', 'accepts' => array( self::GIFT ) ), true );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'Eklentinin açabileceği kartlar', $r->message );
	}

	public function test_names_come_from_what_the_key_may_list_then_from_codes_the_shop_has_seen(): void {
		CheckoutSettings::remember( array( 'programId' => strtoupper( self::DISC ), 'programName' => 'İndirim kartı', 'type' => 'discount' ) );
		$this->script( $this->answer( 200, array( 'data' => array( array( 'id' => self::PROGRAM, 'name' => 'Damga', 'type' => 'stamp' ) ) ) ) );
		$names = $this->cs()->names( array( self::GIFT, self::DISC ) );
		$this->assertSame( array( self::DISC => array( 'name' => 'İndirim kartı', 'type' => 'discount' ) ), $names );
		CheckoutSettings::remember( array( 'programId' => 'nope', 'programName' => 'x' ) );
		$this->assertCount( 1, get_option( CheckoutSettings::SEEN_OPTION ) );
	}

	private function render( bool $admin ): string {
		$this->isAdmin = $admin;
		$s             = $this->connected( array( 'program_name' => 'Damga <b>kartı</b>' ) );
		$s->save_api_key( self::PLUGIN_KEY );
		$this->makeWebhook();
		$this->script(
			$this->answer( 200, array( 'data' => array_merge( $this->link( array( 'holdDays' => 3 ), array( self::GIFT, self::DISC ), array( self::GIFT ) ), array( 'unbacked' => array( 'count' => 2, 'lastAt' => null ) ) ) ) ),
			$this->answer( 200, array( 'data' => array() ) ),
			$this->answer( 200, array( 'data' => array( array( 'id' => self::GIFT, 'name' => 'Hediye <i>kartı</i>', 'type' => 'giftcard' ) ) ) )
		);
		$admin_screen = new Admin( $s, new Connection( $s, new Webhooks( $s ), $this->factory() ), static function (): void {}, null, new CheckoutSettings( $s, $this->factory() ) );
		$_GET['tab']  = 'settings';
		ob_start();
		try {
			$admin_screen->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = array();
		}
	}

	public function test_the_ayarlar_section_shows_every_setting_with_its_default_and_plain_words(): void {
		$html = $this->render( true );
		$this->assertStringContainsString( 'Rewloy cards at the checkout', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_save_checkout', $html );
		$this->assertStringContainsString( 'Damga &lt;b&gt;kartı&lt;/b&gt;', $html, 'escaped' );
		$this->assertStringContainsString( 'Hediye &lt;i&gt;kartı&lt;/i&gt; (Gift card)', $html );
		$this->assertMatchesRegularExpression( '/name="accepts\[\]" value="' . self::GIFT . '" checked="checked"/', $html );
		$this->assertStringContainsString( 'Card programme …4f5a02', $html, 'a card the key may not name' );
		$this->assertStringContainsString( 'the KDV of the goods stays as it is', $html );
		$this->assertStringContainsString( 'the KDV base goes down', $html );
		$this->assertStringContainsString( 'for your accountant to say', $html );
		$this->assertMatchesRegularExpression( '/name="tax_giftcard" value="payment" checked="checked"[^>]*\/> As a payment[^<]*\(default\)/', $html );
		$this->assertStringContainsString( 'value="3"', $html );
		$this->assertStringContainsString( '2 code uses were paid after their hold had run out', $html );
		$this->assertStringContainsString( 'Save the checkout settings', $html );
		$this->assertStringNotContainsString( 'disabled="disabled" /> As a', $html );
	}

	public function test_a_shop_manager_sees_the_settings_but_cannot_change_them(): void {
		$html = $this->render( false );
		$this->assertStringContainsString( 'Only an administrator can change these.', $html );
		$this->assertStringNotContainsString( 'Save the checkout settings', $html );
		$this->assertMatchesRegularExpression( '/name="hold_days"[^>]*disabled="disabled"/', $html );
		$this->assertMatchesRegularExpression( '/name="accepts\[\]"[^>]*disabled="disabled"/', $html );
	}

	public function test_a_connection_made_with_a_key_of_ones_own_says_where_its_cards_are_decided(): void {
		$s = $this->connected();
		$s->save_api_key( self::KEY );
		$this->makeWebhook();
		$this->script( $this->answer( 200, array( 'data' => $this->link( array(), null ) ) ), $this->answer( 200, array( 'data' => array() ) ) );
		$a           = new Admin( $s, new Connection( $s, new Webhooks( $s ), $this->factory() ), static function (): void {}, null, new CheckoutSettings( $s, $this->factory() ) );
		$_GET['tab'] = 'settings';
		ob_start();
		$a->render();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'connected with an API key of your own', $html );
		$this->assertStringNotContainsString( 'accepts_shown', $html );
	}

	public function test_coupons_turned_off_in_woocommerce_are_called_out(): void {
		Functions\when( 'wc_coupons_enabled' )->justReturn( false );
		$this->assertStringContainsString( 'Coupons are turned off in WooCommerce', $this->render( true ) );
	}
}
