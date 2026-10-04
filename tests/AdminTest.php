<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Admin;
use Rewloy\WooCommerce\Connection;
use Rewloy\WooCommerce\Plugin;
use Rewloy\WooCommerce\Settings;
use Rewloy\WooCommerce\Webhooks;

final class AdminTest extends TestCase {

	/** @var list<string> */
	private array $redirects = array();
	/** @var list<string> The nonce actions checked, in order. */
	private array $nonceChecks = array();
	private bool $nonceOk = true;
	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->redirects   = array();
		$this->nonceChecks = array();
		$this->nonceOk     = true;
		$this->settings    = new Settings();
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '' ): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action ) {
				$this->nonceChecks[] = $action;
				if ( ! $this->nonceOk ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="NONCE-' . $action . '" />';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( $text = '' ): void {
				echo '<button type="submit">' . htmlspecialchars( (string) $text ) . '</button>';
			}
		);
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = true ) => $a == $b ? ' checked="checked"' : '' );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ) => gmdate( 'd.m.Y H:i', $ts ) );
		Functions\when( 'plugin_basename' )->justReturn( 'rewloy-for-woocommerce/rewloy-for-woocommerce.php' );
		$_POST = array();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		$_POST = array();
		parent::tearDown();
	}

	private function admin( ?Settings $settings = null, ?\Rewloy\WooCommerce\Client $client = null ): Admin {
		$settings ??= $this->settings;
		$factory    = null === $client ? $this->factory() : $this->factory( $client );
		return new Admin(
			$settings,
			new Connection( $settings, new Webhooks( $settings ), $factory ),
			function ( string $url ): void {
				$this->redirects[] = $url;
			}
		);
	}

	private function render( Admin $a ): string {
		ob_start();
		try {
			$a->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** @return array<string,array{0:string}> */
	public static function actions(): array {
		$out = array();
		foreach ( array( 'save_key', 'forget_key', 'connect', 'toggle', 'disconnect', 'reactivate', 'save_options' ) as $a ) {
			$out[ $a ] = array( $a );
		}
		return $out;
	}

	/** @dataProvider actions */
	public function test_every_action_needs_manage_woocommerce_before_anything_else( string $action ): void {
		$this->can = false;
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		try {
			$this->admin( $this->connected() )->{'handle_' . $action}();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( array(), $this->nonceChecks, 'the capability is checked before the nonce' );
		$this->assertSame( array(), $this->requests, 'no API call without the capability' );
		$this->assertSame( array(), $this->redirects );
		$this->assertSame( array(), $this->transients );
	}

	/** @dataProvider actions */
	public function test_every_action_checks_its_own_nonce_and_stops_if_it_fails( string $action ): void {
		$this->nonceOk = false;
		$settings      = $this->connected();
		$before   = $this->options;
		$_POST    = array( 'rewloy_api_key' => self::KEY, 'program_id' => self::PROGRAM, 'invite' => '1', 'pause' => '1' );
		try {
			$this->admin( $settings )->{'handle_' . $action}();
			$this->fail( 'expected the nonce failure' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		}
		$this->assertSame( array( 'rewloy_wc_' . $action ), $this->nonceChecks, 'its own nonce action' );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( $before, $this->options, 'nothing was saved' );
	}

	/** @dataProvider actions */
	public function test_a_get_request_never_runs_an_action_even_with_a_valid_nonce( string $action ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$settings                  = $this->connected();
		$before                    = $this->options;
		try {
			$this->admin( $settings )->{'handle_' . $action}();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( array(), $this->nonceChecks );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( $before, $this->options );
	}

	public function test_the_capability_is_manage_woocommerce_and_the_screen_is_a_woocommerce_submenu(): void {
		$this->assertSame( 'manage_woocommerce', Admin::CAPABILITY );
		Functions\expect( 'add_submenu_page' )->once()->with( 'woocommerce', \Mockery::type( 'string' ), \Mockery::type( 'string' ), 'manage_woocommerce', 'rewloy-for-woocommerce', \Mockery::type( 'array' ) );
		$this->admin()->add_menu();
	}

	public function test_the_screen_itself_needs_the_capability(): void {
		$this->can = false;
		$this->expectException( \RuntimeException::class );
		$this->render( $this->admin() );
	}

	public function test_saving_a_key_saves_it_flashes_and_redirects_without_echoing_the_key(): void {
		$_POST = array( 'rewloy_api_key' => '  ' . self::KEY . ' ' );
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$admin = new Admin(
			$this->settings,
			new Connection( $this->settings, new Webhooks( $this->settings ), fn( string $k = '' ) => $this->client( '' !== $k ? $k : self::KEY ) ),
			function ( string $url ): void {
				$this->redirects[] = $url;
			}
		);
		$admin->handle_save_key();
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ] );
		$this->assertSame( array( 'https://shop.example.com/wp-admin/admin.php?page=rewloy-for-woocommerce' ), $this->redirects );
		$flash = $this->transients['rewloy_wc_flash_7'];
		$this->assertTrue( $flash['ok'] );
		$this->assertStringNotContainsString( 'SECRET', (string) json_encode( $flash ) );
	}

	public function test_connect_passes_the_posted_choices_on_sanitised(): void {
		$_POST = array( 'program_id' => self::PROGRAM, 'rule' => 'amount', 'per_amount' => ' 100 ', 'step' => '3<b>' );
		$this->script(
			$this->answer( 200, array( 'data' => array( array( 'id' => self::PROGRAM, 'type' => 'points', 'name' => 'P', 'status' => 'active', 'joinUrl' => null ) ) ) ),
			$this->answer( 201, array( 'data' => array( 'id' => self::LINK, 'programName' => 'P', 'webhookUrl' => 'https://app.rewloy.com/hooks/store/' . self::LINK, 'secret' => 'wc_x' ) ) )
		);
		$this->settings->save_api_key( self::KEY );
		$this->admin()->handle_connect();
		$body = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( 3, $body['step'], 'the tag is stripped, the number kept' );
		$this->assertSame( 10000, $body['perAmountMinor'] );
		$this->assertTrue( $this->settings->is_connected() );
	}

	public function test_save_options_sanitises_and_never_turns_things_on_while_not_connected(): void {
		$_POST = array( 'invite' => '1', 'account_tab' => '1', 'controller_name' => '<i>Ben</i>', 'controller_email' => 'x' );
		$this->admin()->handle_save_options();
		$s = $this->settings->get();
		$this->assertFalse( $s['invite'] );
		$this->assertFalse( $s['account_tab'] );
		$this->assertSame( 'Ben', $s['controller_name'] );
		$this->assertSame( '', $s['controller_email'] );
	}

	public function test_save_options_when_connected_and_the_tab_change_asks_for_a_rewrite_flush(): void {
		$settings = $this->connected( array( 'invite' => false ) );
		$_POST    = array( 'invite' => '1', 'account_tab' => '1', 'controller_name' => 'Örnek A.Ş.', 'controller_email' => 'a@b.co' );
		$this->admin( $settings )->handle_save_options();
		$s = $settings->get();
		$this->assertTrue( $s['invite'] );
		$this->assertTrue( $s['account_tab'] );
		$this->assertSame( '1', $this->options[ Plugin::FLUSH_OPTION ] );
		$this->assertSame( 'false', $this->autoload[ Plugin::FLUSH_OPTION ] );

		unset( $this->options[ Plugin::FLUSH_OPTION ] );
		$this->admin( $settings )->handle_save_options();
		$this->assertArrayNotHasKey( Plugin::FLUSH_OPTION, $this->options, 'no change, no flush' );
	}

	public function test_the_screen_without_a_key_asks_for_one_and_never_fills_the_field(): void {
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( 'value="rwk_', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_save_key', $html );
		$this->assertStringContainsString( 'value="rewloy_wc_save_key"', $html );
	}

	public function test_the_key_is_never_shown_back_only_the_mask(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script( $this->answer( 200, array( 'data' => array() ) ) );
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'rwk_abcdefghij••••••••', $html );
		$this->assertStringNotContainsString( 'SECRET', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringContainsString( 'never shown again', $html );
	}

	public function test_a_programme_name_from_the_api_is_escaped(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script( $this->answer( 200, array( 'data' => array( array( 'id' => self::PROGRAM, 'type' => 'stamp', 'name' => '<script>alert(1)</script>', 'status' => 'active', 'joinUrl' => null ) ) ) ) );
		$html = $this->render( $this->admin() );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_connect', $html );
		$this->assertStringContainsString( 'name="rule" value="order" checked', $html );
	}

	public function test_a_refused_key_on_the_connect_step_is_shown_as_an_error(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script( $this->failure( 401, 'INVALID_API_KEY' ) );
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'did not accept this API key', $html );
		$this->assertStringNotContainsString( 'SECRET', $html );
	}

	public function test_the_connected_screen_shows_health_orders_and_outcomes_in_the_panels_words(): void {
		$settings = $this->connected( array( 'account_tab' => true, 'controller_name' => 'Örnek A.Ş.' ) );
		$settings->save_api_key( self::KEY );
		$this->makeWebhook( 41, null, 'disabled', 5 );
		$this->script(
			$this->answer( 200, array( 'data' => array( 'enabled' => true, 'lastOrderAt' => '2026-10-03T10:00:00Z', 'orders' => array( 'credited' => 3, 'unmatched' => 1, 'below' => 0, 'paused' => 2, 'currency' => 1 ) ) ) ),
			$this->answer( 200, array( 'data' => array( array( 'orderId' => '<b>12</b>', 'outcome' => 'unmatched', 'at' => '2026-10-03T10:00:00Z' ) ) ) )
		);
		$html = $this->render( $this->admin( $settings ) );

		foreach ( array( 'Added to the card', 'No card', 'Below the threshold', 'While the link was off', 'Other currency' ) as $label ) {
			$this->assertStringContainsString( $label, $html );
		}
		$this->assertStringContainsString( 'failed deliveries: 5', $html );
		$this->assertStringContainsString( 'The webhook is not active', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_reactivate', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_toggle', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_disconnect', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_save_options', $html );
		$this->assertStringNotContainsString( '<b>12</b>', $html );
		$this->assertStringContainsString( '03.10.2026 10:00', $html );
		$this->assertStringContainsString( 'Kahve Kartı', $html );
		$this->assertStringContainsString( 'Deleting this plugin removes its settings', $html );
		$this->assertStringContainsString( 'does not delete anything in Rewloy', $html );
		$this->assertStringNotContainsString( 'SECRET', $html );
		$this->assertStringContainsString( 'value="Örnek A.Ş."', $html );
	}

	public function test_a_missing_webhook_is_called_out(): void {
		$settings = $this->connected();
		$settings->save_api_key( self::KEY );
		$this->script( $this->answer( 200, array( 'data' => array( 'enabled' => true, 'orders' => array() ) ) ), $this->answer( 200, array( 'data' => array() ) ) );
		$html = $this->render( $this->admin( $settings ) );
		$this->assertStringContainsString( 'Missing. It was deleted', $html );
	}

	public function test_the_flash_message_is_shown_once_and_escaped(): void {
		$this->transients['rewloy_wc_flash_7'] = array( 'ok' => false, 'text' => '<script>x</script>' );
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertArrayNotHasKey( 'rewloy_wc_flash_7', $this->transients );
		$this->assertStringNotContainsString( 'notice-error', $this->render( $this->admin() ) );
	}

	public function test_the_privacy_section_names_what_is_sent(): void {
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'number, status, currency and total', $html );
		$this->assertStringContainsString( 'billing e-mail once the order is processing or completed', $html );
		$this->assertStringContainsString( 'no tracking', $html );
	}

	public function test_a_settings_link_is_added_to_the_plugin_row(): void {
		$links = $this->admin()->action_links( array( '<a href="x">Deactivate</a>' ) );
		$this->assertStringContainsString( 'page=rewloy-for-woocommerce', $links[0] );
	}
}
