<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_when_nothing_is_saved(): void {
		$s = ( new Settings() )->get();
		$this->assertSame( '', $s['link_id'] );
		$this->assertFalse( $s['invite'] );
		$this->assertFalse( $s['account_tab'] );
		$this->assertSame( 1, $s['step'] );
	}

	public function test_update_keeps_known_keys_only_and_is_not_autoloaded(): void {
		$settings = new Settings();
		$settings->update(
			array(
				'invite'  => true,
				'bogus'   => 'x',
				'api_key' => self::KEY,
			)
		);
		$saved = $this->options[ Settings::OPTION ];
		$this->assertTrue( $saved['invite'] );
		$this->assertArrayNotHasKey( 'bogus', $saved );
		$this->assertArrayNotHasKey( 'api_key', $saved, 'the key never goes into the settings option' );
		$this->assertStringNotContainsString( 'SECRET', serialize( $saved ) );
	}

	public function test_get_coerces_types_from_a_damaged_option(): void {
		$this->options[ Settings::OPTION ] = array(
			'link_id'    => array( 'x' ),
			'webhook_id' => '41',
			'step'       => 999,
			'rule'       => 'evil',
			'invite'     => 'yes',
		);
		$s = ( new Settings() )->get();
		$this->assertSame( '', $s['link_id'] );
		$this->assertSame( 41, $s['webhook_id'] );
		$this->assertSame( 100, $s['step'] );
		$this->assertSame( 'order', $s['rule'] );
		$this->assertTrue( $s['invite'] );
	}

	public function test_api_key_is_saved_in_its_own_option_that_is_not_autoloaded(): void {
		$settings = new Settings();
		$settings->save_api_key( self::KEY );
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ] );
		$this->assertSame( 'false', $this->autoload[ Settings::KEY_OPTION ] );
		$this->assertSame( 'option', $settings->key_source() );
		$this->assertSame( self::KEY, $settings->api_key() );
		$settings->delete_api_key();
		$this->assertSame( 'none', $settings->key_source() );
	}

	public function test_sanitize_api_key_accepts_the_shape_and_nothing_else(): void {
		$settings = new Settings();
		$this->assertSame( self::KEY, $settings->sanitize_api_key( "  " . self::KEY . "\n" ) );
		$this->assertSame( '', $settings->sanitize_api_key( 'rws_abcdefghijklmnop' ), 'a staff session is not an API key' );
		$this->assertSame( '', $settings->sanitize_api_key( 'rwk_short' ) );
		$this->assertSame( '', $settings->sanitize_api_key( 'rwk_abcdefghij<script>alert(1)</script>' ) );
		$this->assertSame( '', $settings->sanitize_api_key( '' ) );
	}

	public function test_mask_shows_only_the_public_prefix(): void {
		$settings = new Settings();
		$mask     = $settings->mask( self::KEY );
		$this->assertSame( 'rwk_abcdefghij••••••••', $mask );
		$this->assertStringNotContainsString( 'SECRET', $mask );
		$this->assertSame( 'rwk_••••••••', $settings->mask( 'rwk_short' ) );
		$this->assertSame( '', $settings->mask( '' ) );
	}

	public function test_amount_to_minor(): void {
		$settings = new Settings();
		$this->assertSame( 10000, $settings->amount_to_minor( '100' ) );
		$this->assertSame( 9950, $settings->amount_to_minor( '99,50' ) );
		$this->assertSame( 125000, $settings->amount_to_minor( '1250.00' ) );
		$this->assertSame( 100, $settings->amount_to_minor( '1' ) );
		$this->assertSame( 10_000_000, $settings->amount_to_minor( '100000' ) );
		$this->assertNull( $settings->amount_to_minor( '0,50' ) );
		$this->assertNull( $settings->amount_to_minor( '100001' ) );
		$this->assertNull( $settings->amount_to_minor( 'abc' ) );
		$this->assertNull( $settings->amount_to_minor( '1.234,56' ) );
		$this->assertNull( $settings->amount_to_minor( '' ) );
	}

	public function test_sanitize_options(): void {
		$settings = new Settings();
		$clean    = $settings->sanitize_options(
			array(
				'invite'           => '1',
				'controller_name'  => '  <b>Örnek A.Ş.</b>  ',
				'controller_email' => 'not an email',
			)
		);
		$this->assertTrue( $clean['invite'] );
		$this->assertFalse( $clean['account_tab'] );
		$this->assertSame( 'Örnek A.Ş.', $clean['controller_name'] );
		$this->assertSame( '', $clean['controller_email'] );

		$clean = $settings->sanitize_options(
			array(
				'controller_name'  => str_repeat( 'a', 300 ),
				'controller_email' => 'destek@ornek.com',
			)
		);
		$this->assertSame( 120, mb_strlen( $clean['controller_name'] ) );
		$this->assertSame( 'destek@ornek.com', $clean['controller_email'] );
		$this->assertFalse( $clean['invite'] );
	}

	public function test_site_id_is_short_and_stable(): void {
		$settings = new Settings();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{10}$/', $settings->site_id() );
		$this->assertSame( $settings->site_id(), $settings->site_id() );
	}

	public function test_only_rewloy_https_urls_are_printable(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->is_rewloy_url( 'https://rewloy.com/join/' . self::PROGRAM ) );
		$this->assertTrue( $settings->is_rewloy_url( 'https://app.rewloy.com/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'http://rewloy.com/join/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://rewloy.com.evil.test/join/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://evilrewloy.com/join/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://user@rewloy.com/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'javascript:alert(1)' ) );
		$this->assertTrue( $settings->is_rewloy_url( 'https://REWLOY.com/cuzdan/' ) );
	}

	public function test_a_url_that_browsers_and_php_read_differently_is_refused(): void {
		$settings = new Settings();
		// Browsers read a backslash as a slash: this goes to evil.com, while PHP's parser says the host ends in .rewloy.com.
		$this->assertFalse( $settings->is_rewloy_url( 'https://evil.com\\.rewloy.com/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( "https://rewloy.com/x\n" ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://rewloy.com/a b' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://rewloy.com:8443/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://user:pw@rewloy.com/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https://-.rewloy.com.evil.test/x' ) );
		$this->assertFalse( $settings->is_rewloy_url( 'https:///rewloy.com/x' ) );
	}

	public function test_a_short_key_shows_no_prefix_at_all(): void {
		$settings = new Settings();
		$this->assertSame( 'rwk_••••••••', $settings->mask( 'rwk_abcdefghijk' ), '15 characters: 14 would be almost all of it' );
		$this->assertSame( 'rwk_••••••••', $settings->mask( 'rwk_' . str_repeat( 'a', 20 ) ) );
		$this->assertSame( 'rwk_aaaaaaaaaa••••••••', $settings->mask( 'rwk_' . str_repeat( 'a', 28 ) ) );
	}

	public function test_clear_connection_keeps_the_choices(): void {
		$settings = $this->connected( array( 'account_tab' => true, 'controller_name' => 'Örnek A.Ş.' ) );
		$settings->clear_connection();
		$s = $settings->get();
		$this->assertSame( '', $s['link_id'] );
		$this->assertSame( 0, $s['webhook_id'] );
		$this->assertFalse( $s['invite'], 'the invitation needs a card, so it goes off' );
		$this->assertTrue( $s['account_tab'] );
		$this->assertSame( 'Örnek A.Ş.', $s['controller_name'] );
	}
}
