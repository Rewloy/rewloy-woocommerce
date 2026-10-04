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
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) );
		$_POST = array();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		$_POST = array();
		$_GET  = array();
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

	/** The Ayarlar tab (the 0.2 screen), unless a test asks for another. */
	private function render( Admin $a, string $tab = 'settings' ): string {
		$_GET['tab'] = $tab;
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
		foreach ( array( 'connect_code', 'save_key', 'forget_key', 'connect', 'toggle', 'disconnect', 'reactivate', 'save_options', 'save_checkout' ) as $a ) {
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
		$_POST    = array( 'rewloy_api_key' => self::KEY, 'rewloy_connect_code' => self::CODE, 'program_id' => self::PROGRAM, 'invite' => '1', 'pause' => '1' );
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

	public function test_the_capability_is_manage_woocommerce_and_the_menu_is_top_level_with_the_old_woocommerce_entry_kept(): void {
		$this->assertSame( 'manage_woocommerce', Admin::CAPABILITY );
		$menus = array();
		$subs  = array();
		Functions\when( 'add_menu_page' )->alias(
			function ( ...$args ) use ( &$menus ): string {
				$menus[] = $args;
				return 'toplevel_page_rewloy-for-woocommerce';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function ( ...$args ) use ( &$subs ): string {
				$subs[] = $args;
				return '';
			}
		);
		$this->admin()->add_menu();
		$this->assertCount( 1, $menus );
		$this->assertSame( array( 'manage_woocommerce', 'rewloy-for-woocommerce' ), array( $menus[0][2], $menus[0][3] ), 'the page keeps the slug it had as a submenu' );
		foreach ( $subs as $sub ) {
			$this->assertSame( 'manage_woocommerce', $sub[3] );
		}
		$slugs = array_map( static fn( array $sub ): string => $sub[0] . ' ' . $sub[4], $subs );
		$this->assertContains( 'woocommerce admin.php?page=rewloy-for-woocommerce&tab=settings', $slugs, 'WooCommerce › Rewloy still opens the settings' );
		$this->assertContains( 'rewloy-for-woocommerce admin.php?page=rewloy-for-woocommerce&tab=till', $slugs );
		$this->assertContains( 'rewloy-for-woocommerce admin.php?page=rewloy-for-woocommerce&tab=cards', $slugs );
	}

	public function test_the_screen_itself_needs_the_capability(): void {
		$this->can = false;
		$this->expectException( \RuntimeException::class );
		$this->render( $this->admin() );
	}

	public function test_saving_a_key_saves_it_flashes_and_redirects_without_echoing_the_key(): void {
		$_POST = array( 'rewloy_api_key' => '  ' . self::KEY . ' ' );
		$this->script( $this->meAnswer() );
		$admin = new Admin(
			$this->settings,
			new Connection( $this->settings, new Webhooks( $this->settings ), fn( string $k = '' ) => $this->client( '' !== $k ? $k : self::KEY ) ),
			function ( string $url ): void {
				$this->redirects[] = $url;
			}
		);
		$admin->handle_save_key();
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ] );
		$this->assertSame( array( 'https://shop.example.com/wp-admin/admin.php?page=rewloy-for-woocommerce&tab=settings' ), $this->redirects );
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

	public function test_the_screen_without_a_key_asks_for_a_code_first_and_a_key_only_as_an_advanced_way(): void {
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'type="password" name="rewloy_connect_code"', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_connect_code', $html );
		$this->assertStringContainsString( 'value="rewloy_wc_connect_code"', $html );
		$this->assertStringContainsString( 'one-time code that works for 15 minutes', $html );
		$this->assertStringContainsString( 'Rewloy eklentisiyle', $html, 'the panel\'s own path to the code' );
		$this->assertStringContainsString( 'You need no API key of your own', $html );
		$this->assertLessThan( strpos( $html, 'rewloy_api_key' ), strpos( $html, 'rewloy_connect_code' ), 'the code comes first' );
		$this->assertMatchesRegularExpression( '#<details[^>]*><summary><strong>Advanced: connect with an API key instead</strong></summary>.*rewloy_api_key.*</details>#s', $html, 'the key form sits inside the advanced block' );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( 'value="rwk_', $html );
		$this->assertStringNotContainsString( 'value="rwc_', $html );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_save_key', $html );
		$this->assertStringContainsString( 'value="rewloy_wc_save_key"', $html );
	}

	public function test_the_advanced_block_says_why_it_exists_and_which_role_to_use(): void {
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'WP-CLI', $html );
		$this->assertStringContainsString( 'single-use and lasts 15 minutes', $html );
		$this->assertStringContainsString( 'E-ticaret role', $html );
		$this->assertStringNotContainsString( 'manage API keys', $html, 'the key no longer needs to manage keys' );
	}

	public function test_a_code_is_connected_with_the_posted_code_cleaned_and_the_flash_never_echoes_it(): void {
		$_POST = array( 'rewloy_connect_code' => '  ' . self::CODE . ' ' );
		$this->script( $this->connectAnswer(), $this->answer( 200, array( 'data' => array() ) ) );
		$admin = new Admin(
			$this->settings,
			new Connection( $this->settings, new Webhooks( $this->settings ), $this->keyedFactory( $this->settings ), null, $this->anonymousFactory() ),
			function ( string $url ): void {
				$this->redirects[] = $url;
			}
		);
		$admin->handle_connect_code();
		$this->assertSame( array( 'token' => self::CODE, 'shopName' => 'Örnek Mağaza' ), json_decode( $this->requests[0]['args']['body'], true ) );
		$this->assertTrue( $this->settings->is_connected() );
		$this->assertSame( self::PLUGIN_KEY, $this->options[ Settings::KEY_OPTION ] );
		$this->assertSame( array( 'https://shop.example.com/wp-admin/admin.php?page=rewloy-for-woocommerce&tab=settings' ), $this->redirects );
		$flash = (string) json_encode( $this->transients['rewloy_wc_flash_7'] );
		$this->assertStringNotContainsString( 'ABCDEFGH', $flash );
		$this->assertStringNotContainsString( 'SECRET', $flash );
	}

	public function test_a_refused_code_is_a_flashed_error_and_changes_nothing(): void {
		$_POST = array( 'rewloy_connect_code' => self::CODE );
		$this->script( $this->failure( 404, 'CONNECT_TOKEN_INVALID' ) );
		$admin = new Admin(
			$this->settings,
			new Connection( $this->settings, new Webhooks( $this->settings ), $this->keyedFactory( $this->settings ), null, $this->anonymousFactory() ),
			function ( string $url ): void {
				$this->redirects[] = $url;
			}
		);
		$admin->handle_connect_code();
		$this->assertFalse( $this->transients['rewloy_wc_flash_7']['ok'] );
		$this->assertStringNotContainsString( 'ABCDEFGH', (string) json_encode( $this->transients['rewloy_wc_flash_7'] ) );
		$this->assertFalse( $this->settings->is_connected() );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
	}

	public function test_a_key_that_a_code_made_is_shown_as_such_and_never_back(): void {
		$settings = $this->connected( array( 'via' => Settings::VIA_CODE ) );
		$settings->save_api_key( self::PLUGIN_KEY );
		$this->script( $this->answer( 200, array( 'data' => array( 'enabled' => true, 'orders' => array() ) ) ), $this->answer( 200, array( 'data' => array() ) ) );
		$html = $this->render( $this->admin( $settings ) );
		$this->assertStringContainsString( 'rwk_0a1b2c3d4e••••••••', $html );
		$this->assertStringContainsString( 'made by the connect code', $html );
		$this->assertStringNotContainsString( 'SECRETPLUGINKEY', $html );
		$this->assertStringNotContainsString( self::PLUGIN_KEY, $html );
		$this->assertStringNotContainsString( 'Test environment', $html );
	}

	public function test_a_test_environment_key_is_called_out_on_the_screen(): void {
		$settings = $this->connected( array( 'via' => Settings::VIA_CODE ) );
		$settings->save_api_key( 'rwk_test_0a1b2c3d4eSECRETPLUGINKEYSECRETPLUGINKEY' );
		$this->script( $this->answer( 200, array( 'data' => array( 'enabled' => true, 'orders' => array() ) ) ), $this->answer( 200, array( 'data' => array() ) ) );
		$html = $this->render( $this->admin( $settings ) );
		$this->assertStringContainsString( 'Test environment: this key belongs to your Rewloy test environment', $html );
		$this->assertStringContainsString( 'rwk_test_0a1b2c3d4e••••••••', $html );
		$this->assertStringNotContainsString( 'SECRETPLUGINKEY', $html );
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

	/** @return array<string,mixed> A programme as GET /v1/programs lists it. */
	private function listed( string $id, string $type, string $name, array $config = array() ): array {
		return array( 'id' => $id, 'type' => $type, 'name' => $name, 'status' => 'active', 'joinUrl' => null, 'config' => $config );
	}

	public function test_the_connect_screen_names_each_cards_type_and_says_what_an_order_does_for_it(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script(
			$this->answer(
				200,
				array(
					'data' => array(
						$this->listed( self::PROGRAM, 'stamp', 'Kahve Kartı' ),
						$this->listed( '0192bbbb-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'vip', 'Üyelik' ),
						$this->listed( '0192cccc-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'cashback', 'İade Kartı', array( 'cashbackRate' => 5, 'currency' => 'TRY' ) ),
						$this->listed( '0192dddd-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'giftcard', 'Hediye' ),
					),
				)
			)
		);
		$html = html_entity_decode( $this->render( $this->admin() ), ENT_QUOTES );
		$this->assertStringContainsString( 'Kahve Kartı (Stamp card)', $html );
		$this->assertStringContainsString( 'Üyelik (VIP card)', $html );
		$this->assertStringContainsString( 'İade Kartı (Cashback card)', $html );
		$this->assertStringNotContainsString( 'Hediye', $html, 'a gift card cannot be linked to a shop' );
		$this->assertStringContainsString( 'Stamp and points cards: the rule below', $html );
		$this->assertStringContainsString( 'VIP cards: every paid order counts as one visit', $html );
		$this->assertStringContainsString( 'Cashback card "İade Kartı": 5% of every paid order\'s total is added to the card\'s balance', $html );
		$this->assertStringContainsString( 'an order of 400 TRY adds 20 TRY', $html );
		$this->assertStringContainsString( 'Rule (stamp and points cards only)', $html );
		$this->assertStringContainsString( 'name="rule" value="order" checked', $html, 'a stamp card is in the list, so the rule fields stay' );
		$this->assertStringContainsString( 'name="step"', $html );
	}

	public function test_a_cashback_card_without_a_listed_rate_is_described_without_a_number(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script( $this->answer( 200, array( 'data' => array( $this->listed( self::PROGRAM, 'cashback', 'İade', array( 'cashbackRate' => 'high' ) ), $this->listed( '0192bbbb-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'points', 'Puan' ) ) ) ) );
		$html = html_entity_decode( $this->render( $this->admin() ), ENT_QUOTES );
		$this->assertStringContainsString( 'Cashback card "İade": the card\'s own rate is applied to the total of every paid order', $html );
		$this->assertStringNotContainsString( 'For example, an order of', $html );
		$this->assertStringNotContainsString( '%', $html );
	}

	public function test_with_only_vip_and_cashback_cards_the_rule_fields_are_not_shown(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script( $this->answer( 200, array( 'data' => array( $this->listed( self::PROGRAM, 'vip', 'Üyelik' ), $this->listed( '0192cccc-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'cashback', 'İade', array( 'cashbackRate' => 7.5 ) ) ) ) ) );
		$html = html_entity_decode( $this->render( $this->admin() ), ENT_QUOTES );
		$this->assertStringContainsString( 'NONCE-rewloy_wc_connect', $html );
		$this->assertStringContainsString( 'name="program_id"', $html );
		$this->assertStringContainsString( 'VIP cards:', $html );
		$this->assertStringContainsString( '7,50% of every paid order', $html );
		$this->assertStringNotContainsString( 'Stamp and points cards: the rule below', $html );
		$this->assertStringNotContainsString( 'name="rule"', $html );
		$this->assertStringNotContainsString( 'name="step"', $html );
		$this->assertStringNotContainsString( 'name="per_amount"', $html );
		$this->assertStringContainsString( 'Connecting creates the link in Rewloy', $html, 'the form is still closed properly' );
		$this->assertSame( substr_count( $html, '<form' ), substr_count( $html, '</form>' ) );
	}

	public function test_the_connected_screen_explains_below_for_a_cashback_card_without_a_threshold(): void {
		$settings = $this->connected( array( 'program_type' => 'cashback' ) );
		$settings->save_api_key( self::KEY );
		$this->makeWebhook( 41, null, 'active', 0 );
		$this->script(
			$this->answer( 200, array( 'data' => array( 'enabled' => true, 'orders' => array( 'credited' => 1, 'unmatched' => 0, 'below' => 2, 'paused' => 0, 'currency' => 0 ) ) ) ),
			$this->answer( 200, array( 'data' => array() ) )
		);
		$html = $this->render( $this->admin( $settings ) );
		$this->assertStringContainsString( 'The cashback on the order came to nothing', $html );
		$this->assertStringNotContainsString( 'did not reach the rule', $html );
		$this->assertStringContainsString( 'Cashback card', $html );
		$this->assertStringContainsString( 'cashback rate is applied to the order total', $html );
	}

	public function test_a_refused_key_on_the_connect_step_is_shown_as_an_error(): void {
		$this->settings->save_api_key( self::KEY );
		$this->script( $this->failure( 401, 'INVALID_API_KEY' ) );
		$html = $this->render( $this->admin() );
		$this->assertStringContainsString( 'did not accept this API key', $html );
		$this->assertStringNotContainsString( 'SECRET', $html );
	}

	public function test_when_rewloy_refuses_a_code_connections_key_the_way_out_is_opened(): void {
		$settings = $this->connected( array( 'via' => Settings::VIA_CODE ) );
		$settings->save_api_key( self::PLUGIN_KEY );
		$this->script( $this->failure( 401, 'INVALID_API_KEY' ) );
		$html = $this->render( $this->admin( $settings ) );
		$this->assertStringContainsString( 'deleted in the Rewloy panel', $html );
		$this->assertMatchesRegularExpression( '/<details[^>]* open>\s*<summary>If Rewloy cannot be reached/', $html );
	}

	public function test_the_way_out_stays_closed_while_rewloy_answers(): void {
		$settings = $this->connected();
		$settings->save_api_key( self::KEY );
		$this->script(
			$this->answer( 200, array( 'data' => array( 'enabled' => true, 'orders' => array() ) ) ),
			$this->answer( 200, array( 'data' => array() ) )
		);
		$html = $this->render( $this->admin( $settings ) );
		$this->assertStringContainsString( '<summary>If Rewloy cannot be reached', $html );
		$this->assertDoesNotMatchRegularExpression( '/<details[^>]* open>\s*<summary>If Rewloy cannot be reached/', $html );
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
		$this->assertStringContainsString( 'Deleting this plugin removes its settings, its key and its webhook', $html );
		$this->assertStringContainsString( 'does not delete anything in Rewloy', $html );
		$this->assertStringContainsString( 'deleting the link revokes the key', $html );
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
		$this->assertStringContainsString( 'the code and this site&#039;s title', $html );
		$this->assertStringContainsString( 'number, status, currency and total', $html );
		$this->assertStringContainsString( 'billing e-mail once the order is processing or completed', $html );
		$this->assertStringContainsString( 'no tracking', $html );
	}

	public function test_a_settings_link_is_added_to_the_plugin_row(): void {
		$links = $this->admin()->action_links( array( '<a href="x">Deactivate</a>' ) );
		$this->assertStringContainsString( 'page=rewloy-for-woocommerce', $links[0] );
	}

	/** The connected screen for a link as getShop answers it. */
	private function connectedScreen( array $link ): string {
		$settings = $this->connected( array( 'via' => Settings::VIA_CODE ) );
		$settings->save_api_key( self::PLUGIN_KEY );
		$this->makeWebhook();
		$this->script( $this->answer( 200, array( 'data' => array_merge( array( 'enabled' => true, 'orders' => array() ), $link ) ) ), $this->answer( 200, array( 'data' => array() ) ) );
		return $this->render( $this->admin( $settings ) );
	}

	public function test_the_last_request_and_its_result_are_shown_in_the_panels_words(): void {
		$html = $this->connectedScreen( array( 'lastDelivery' => array( 'at' => '2026-10-04T08:30:00Z', 'result' => 'credited' ) ) );
		$this->assertStringContainsString( 'Last request from the shop', $html );
		$this->assertStringContainsString( '04.10.2026 08:30 · Added to the card', $html );
		$this->assertStringNotContainsString( 'Last refused request', $html );
		$this->assertStringNotContainsString( 'signature did not match came', $html );
	}

	public function test_every_result_rewloy_can_give_has_a_label(): void {
		foreach ( \Rewloy\WooCommerce\Messages::DELIVERY_RESULTS as $result ) {
			$this->options = array();
			$html          = $this->connectedScreen( array( 'lastDelivery' => array( 'at' => '2026-10-04T08:30:00Z', 'result' => $result ) ) );
			$this->assertStringContainsString( '04.10.2026 08:30 · ' . \Rewloy\WooCommerce\Messages::delivery_label( $result ), $html, $result );
			$this->assertNotSame( $result, \Rewloy\WooCommerce\Messages::delivery_label( $result ), $result . ' is not shown as its raw code' );
		}
	}

	public function test_a_shop_that_has_sent_nothing_signed_says_so(): void {
		$html = $this->connectedScreen( array( 'lastDelivery' => null, 'lastRefusal' => null ) );
		$this->assertStringContainsString( 'No signed request has come from the shop yet.', $html );
		$html = $this->connectedScreen( array() );
		$this->assertStringContainsString( 'No signed request has come from the shop yet.', $html, 'an answer without the field (an older Rewloy) reads the same' );
	}

	public function test_a_refused_request_is_shown_with_what_it_means(): void {
		$html = $this->connectedScreen(
			array(
				'lastDelivery' => array( 'at' => '2026-10-04T08:30:00Z', 'result' => 'credited' ),
				'lastRefusal'  => array( 'at' => '2026-10-04T09:45:00Z', 'reason' => 'bad_signature' ),
			)
		);
		$this->assertStringContainsString( 'Last refused request', $html );
		$this->assertStringContainsString( '04.10.2026 09:45 · Signature did not match', $html );
		$this->assertStringContainsString( 'came to this shop&#039;s address and was refused; no order was affected', $html );
		$this->assertStringContainsString( 'check the settings of the webhook in WooCommerce', $html );
	}

	public function test_what_rewloy_says_about_health_is_escaped_and_a_result_it_adds_later_does_not_break_the_screen(): void {
		$html = $this->connectedScreen(
			array(
				'lastDelivery' => array( 'at' => '2026-10-04T08:30:00Z', 'result' => '<script>x</script>' ),
				'lastRefusal'  => array( 'at' => 'not a date', 'reason' => '<b>why</b>' ),
				'pluginKey'    => array( 'id' => self::LINK, 'prefix' => '<i>ab</i>0123456789', 'name' => '<script>alert(1)</script>' ),
			)
		);
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>why</b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'none yet · ', $html );
	}

	public function test_the_key_rewloy_lists_for_the_link_is_shown_by_name_and_public_prefix_only(): void {
		$html = $this->connectedScreen( array( 'pluginKey' => array( 'id' => self::LINK, 'prefix' => '0a1b2c3d4e', 'name' => 'WooCommerce · Örnek Mağaza' ) ) );
		$this->assertStringContainsString( 'Key in Rewloy', $html );
		$this->assertStringContainsString( 'WooCommerce · Örnek Mağaza (0a1b2c3d4e…)', $html );
		$this->assertStringNotContainsString( 'SECRETPLUGINKEY', $html );
		$this->assertStringNotContainsString( 'Key in Rewloy', $this->connectedScreen( array( 'pluginKey' => null ) ) );
	}
}
