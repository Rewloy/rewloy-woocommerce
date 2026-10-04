<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Plugin;
use Rewloy\WooCommerce\Settings;
use Rewloy\WooCommerce\Uninstaller;

final class UninstallTest extends TestCase {

	private \FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = new \FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function webhook( string $url ): \WC_Webhook {
		$hook = new \WC_Webhook();
		$hook->id = 41;
		$hook->set_delivery_url( $url );
		$hook->save();
		return $hook;
	}

	public function test_uninstall_removes_the_options_and_the_webhook_and_calls_no_api(): void {
		Functions\expect( 'wp_remote_request' )->never();
		$settings = $this->connected();
		$settings->save_api_key( self::KEY );
		$this->options[ Plugin::FLUSH_OPTION ] = '1';
		$this->options['unrelated']            = 'stays';
		$hook = $this->webhook( 'https://app.rewloy.com/hooks/store/' . self::LINK );

		Uninstaller::run();

		$this->assertArrayNotHasKey( Settings::OPTION, $this->options );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
		$this->assertArrayNotHasKey( Plugin::FLUSH_OPTION, $this->options );
		$this->assertSame( 'stays', $this->options['unrelated'] );
		$this->assertTrue( $hook->deleted );
		$this->assertSame( array(), $this->requests );
	}

	public function test_it_removes_the_claim_rows_and_flash_transients_with_escaped_patterns(): void {
		Uninstaller::run();
		$joined = implode( "\n", $this->db->queries );
		$this->assertStringContainsString( 'rewloy\_wc\_claim\_%', $joined );
		$this->assertStringContainsString( '\_transient\_rewloy\_wc\_flash\_%', $joined );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $joined );
	}

	public function test_a_webhook_that_is_not_ours_is_left_alone(): void {
		$this->connected();
		$other = $this->webhook( 'https://other.example/hook' );
		Uninstaller::run();
		$this->assertFalse( $other->deleted, 'the delivery address does not name our link' );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->options );
	}

	public function test_uninstall_with_nothing_saved_is_harmless(): void {
		Uninstaller::run();
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), \WC_Webhook::$db );
	}

	public function test_the_uninstall_file_refuses_to_run_outside_wordpress_uninstall(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $src );
		$this->assertStringNotContainsString( 'Client', $src, 'it never talks to Rewloy' );
		$code = (string) file_get_contents( dirname( __DIR__ ) . '/src/Uninstaller.php' );
		$this->assertStringNotContainsString( 'Client', $code );
		$this->assertStringNotContainsString( 'wp_remote', $code );
	}
}
