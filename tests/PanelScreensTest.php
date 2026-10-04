<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Admin;
use Rewloy\WooCommerce\Ajax;
use Rewloy\WooCommerce\Connection;
use Rewloy\WooCommerce\Links;
use Rewloy\WooCommerce\Panel;
use Rewloy\WooCommerce\Settings;
use Rewloy\WooCommerce\Till;
use Rewloy\WooCommerce\Webhooks;

/**
 * The "Rewloy" menu's screens (0.3.0): Özet, Kartlar, Kasa; each shown only with the ability Rewloy names; the
 * scripts on their own tab only; the admin-ajax door (capability, POST, nonce) and its answers; no personal data.
 */
final class PanelScreensTest extends TestCase {

	/** @var list<array{0:array<string,mixed>,1:int}> */
	private array $sent = array();
	/** @var list<string> */
	private array $scripts = array();
	private bool $ajaxNonceOk = true;
	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->sent        = array();
		$this->scripts     = array();
		$this->ajaxNonceOk = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
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
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'check_ajax_referer' )->alias( fn() => $this->ajaxNonceOk ? 1 : false );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ) => gmdate( 'd.m.Y H:i', $ts ) );
		Functions\when( 'plugins_url' )->alias( static fn( string $p ) => 'https://shop.example.com/wp-content/plugins/rewloy-for-woocommerce/' . $p );
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( string $h ): void {
				$this->scripts[] = 'style:' . $h;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $h ): void {
				$this->scripts[] = $h;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		$this->settings = $this->connected( array( 'currency' => 'TRY' ) );
		$this->settings->save_api_key( self::PLUGIN_KEY );
		$this->makeWebhook();
		$_GET  = array();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	private function panel(): Panel {
		return new Panel( $this->settings, $this->factory(), new Links() );
	}

	private function admin( ?Panel $panel = null ): Admin {
		return new Admin( $this->settings, new Connection( $this->settings, new Webhooks( $this->settings ), $this->factory() ), static function (): void {}, $panel ?? $this->panel() );
	}

	private function render( string $tab ): string {
		$_GET['tab'] = $tab;
		ob_start();
		try {
			$this->admin()->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private function shopAnswer(): array {
		return $this->answer( 200, array( 'data' => array( 'id' => self::LINK, 'enabled' => true, 'lastOrderAt' => null, 'lastDelivery' => array( 'at' => '2026-10-04T09:00:00.000Z', 'result' => 'credited' ), 'lastRefusal' => null, 'orders' => array(), 'pluginKey' => null ) ) );
	}

	private function ordersAnswer(): array {
		return $this->answer( 200, array( 'data' => array( array( 'orderId' => '1042', 'outcome' => 'credited', 'at' => '2026-10-04T09:00:00.000Z' ) ), 'meta' => array( 'page' => 1, 'pageSize' => 10, 'total' => 1 ) ) );
	}

	private function activityAnswer(): array {
		return $this->answer(
			200,
			array(
				'data' => array(
					array( 'at' => '2026-10-04T10:00:00.000Z', 'kind' => 'earn', 'delta' => 1, 'unit' => 'stamp', 'currency' => 'TRY', 'serial' => 'ABCD-EFGH-JKLM', 'personId' => null, 'program' => 'Kahve', 'location' => 'Moda', 'actor' => 'ekip üyesi', 'actorKind' => 'seat' ),
					// Were Rewloy ever to send a name or an address, the screen would still not show it.
					array( 'at' => '2026-10-04T09:00:00.000Z', 'kind' => 'spend', 'delta' => -1250, 'unit' => 'try_minor', 'currency' => 'TRY', 'serial' => 'WXYZ-1234-QRST', 'personId' => 'p', 'program' => 'Kahve', 'location' => 'Moda', 'actor' => 'ayse@example.com', 'actorKind' => 'seat' ),
				),
				'meta' => array( 'page' => 1, 'pageSize' => 20, 'total' => 2 ),
			)
		);
	}

	/* ------------------------------------------------------------------- tabs */

	public function test_the_tabs_are_all_there_when_connected_and_only_settings_before(): void {
		$this->script( $this->meAbilities( array( 'view' ) ), $this->answer( 200, array( 'data' => array( 'kpis' => array( 'activeCards' => 41, 'newCards' => 7, 'visits' => 120, 'redeems' => 9 ) ) ) ), $this->shopAnswer(), $this->ordersAnswer() );
		$html = $this->render( 'overview' );
		foreach ( array( 'Overview', 'Cards', 'Till', 'Settings' ) as $tab ) {
			$this->assertStringContainsString( '>' . $tab . '</a>', $html );
		}
		$this->assertStringContainsString( 'nav-tab-active" aria-current="page">Overview', $html );
		$this->settings->clear_connection();
		$_GET['tab'] = 'till';
		$this->assertSame( 'settings', $this->admin()->current_tab(), 'nothing but the settings before connecting' );
	}

	public function test_the_overview_names_the_card_the_business_its_numbers_by_what_they_count_and_links_to_the_panel(): void {
		$this->script( $this->meAbilities( array( 'view' ) ), $this->answer( 200, array( 'data' => array( 'kpis' => array( 'activeCards' => 41, 'newCards' => 7, 'visits' => 120, 'redeems' => 9 ) ) ) ), $this->shopAnswer(), $this->ordersAnswer() );
		$html = html_entity_decode( $this->render( 'overview' ), ENT_QUOTES );
		$this->assertStringContainsString( 'Kahve Kartı (Stamp card)', $html );
		$this->assertStringContainsString( 'Örnek Kafe', $html );
		$this->assertStringContainsString( '<dt>Open cards now</dt><dd>41</dd>', $html );
		$this->assertStringContainsString( '<dt>Cards given in the last 30 days</dt><dd>7</dd>', $html );
		$this->assertStringContainsString( '<dt>Visits in the last 30 days</dt><dd>120</dd>', $html );
		$this->assertStringContainsString( '<dt>Rewards used in the last 30 days</dt><dd>9</dd>', $html );
		$this->assertStringContainsString( '#1042', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/programs/' . self::PROGRAM, $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/programs/new', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/plan', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/team', $html );
		$this->assertStringContainsString( 'Done in the Rewloy panel', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/v1/analytics?programId=' . self::PROGRAM . '&days=30', $this->requests[1]['url'] );
	}

	public function test_without_view_the_overview_asks_for_no_numbers_and_links_to_where_it_is_turned_on(): void {
		$this->script( $this->meAbilities( array() ), $this->shopAnswer(), $this->ordersAnswer() );
		$html = html_entity_decode( $this->render( 'overview' ), ENT_QUOTES );
		$this->assertStringContainsString( '"Görüntüleme" is off for this shop.', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/settings/shops/' . self::LINK . '#wordpress-yetkileri', $html );
		foreach ( $this->requests as $r ) {
			$this->assertStringNotContainsString( '/v1/analytics', $r['url'] );
		}
	}

	public function test_the_cards_tab_is_hidden_without_view(): void {
		$this->script( $this->meAbilities( array( 'till' ) ) );
		$html = html_entity_decode( $this->render( 'cards' ), ENT_QUOTES );
		$this->assertStringContainsString( '"Görüntüleme" is off for this shop.', $html );
		$this->assertStringNotContainsString( 'rewloy-wc-activity-rows', $html );
		$this->assertStringNotContainsString( 'name="rewloy_card"', $html );
		$this->assertCount( 1, $this->requests, 'only `me`' );
	}

	public function test_the_cards_tab_shows_activity_masked_and_by_kind_never_a_name_or_an_address(): void {
		$this->script( $this->meAbilities( array( 'view' ) ), $this->activityAnswer() );
		$html = html_entity_decode( $this->render( 'cards' ), ENT_QUOTES );
		$this->assertStringContainsString( '••••-••••-JKLM', $html );
		$this->assertStringContainsString( '••••-••••-QRST', $html );
		$this->assertStringNotContainsString( '>ABCD-EFGH-JKLM<', $html, 'the whole number only in the field\'s placeholder' );
		$this->assertStringNotContainsString( 'ayse@example.com', $html );
		$this->assertStringNotContainsString( 'ekip üyesi', $html );
		$this->assertStringContainsString( 'Team member', $html );
		$this->assertStringContainsString( 'Added: 1 stamp(s)', $html );
		$this->assertStringContainsString( 'Spent: 12,50 TRY', $html );
		$this->assertStringContainsString( 'Moda', $html );
		$this->assertStringContainsString( 'name="rewloy_card"', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/v1/activity?programId=' . self::PROGRAM . '&limit=20', $this->requests[1]['url'] );
	}

	public function test_a_card_looked_up_on_the_cards_tab_is_masked_and_the_customer_is_a_link_to_rewloy(): void {
		$_POST = array( 'rewloy_card' => 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=SECRETVIEWKEY123' );
		$this->script( $this->meAbilities( array( 'view' ) ), $this->passAnswer(), $this->activityAnswer() );
		$html = html_entity_decode( $this->render( 'cards' ), ENT_QUOTES );
		$this->assertStringContainsString( '<td>••••-••••-JKLM</td>', $html );
		$this->assertStringContainsString( 'Give the stamp reward', $html );
		$this->assertStringContainsString( 'A sale adds a stamp.', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/customers?q=ABCD-EFGH-JKLM', $html );
		$this->assertStringNotContainsString( 'SECRETVIEWKEY', $html . json_encode( $this->requests ) );
	}

	public function test_another_programmes_card_is_not_shown(): void {
		$_POST = array( 'rewloy_card' => self::SERIAL );
		$this->script( $this->meAbilities( array( 'view' ) ), $this->passAnswer( array( 'programId' => self::LINK ) ), $this->activityAnswer() );
		$html = html_entity_decode( $this->render( 'cards' ), ENT_QUOTES );
		$this->assertStringContainsString( 'belongs to another Rewloy card', $html );
		$this->assertStringNotContainsString( 'Give the stamp reward', $html );
	}

	public function test_the_till_tab_is_hidden_without_till_and_says_what_it_is_and_why_it_is_off(): void {
		$this->script( $this->meAbilities( array( 'view' ) ) );
		$html = html_entity_decode( $this->render( 'till' ), ENT_QUOTES );
		$this->assertStringContainsString( 'The till is off for this shop.', $html );
		$this->assertStringContainsString( 'spend customers\' balances', $html );
		$this->assertStringContainsString( 'https://app.rewloy.com/panel/settings/shops/' . self::LINK . '#wordpress-yetkileri', $html );
		$this->assertStringNotContainsString( 'rewloy-wc-till-card', $html );
	}

	public function test_the_till_tab_with_till_names_the_branch_and_has_one_field_for_a_typed_or_scanned_card(): void {
		$this->script( $this->meAbilities( array( 'till' ) ) );
		$html = html_entity_decode( $this->render( 'till' ), ENT_QUOTES );
		$this->assertStringContainsString( 'Branch: Moda.', $html );
		$this->assertStringContainsString( 'id="rewloy-wc-till-card"', $html );
		$this->assertStringContainsString( 'id="rewloy-wc-till-reference"', $html );
		$this->assertStringNotContainsString( self::PLUGIN_KEY, $html, 'the key never reaches the browser' );
	}

	public function test_the_scripts_load_on_their_own_tab_only(): void {
		$admin = $this->admin();
		$_GET  = array( 'tab' => 'till' );
		$admin->enqueue( 'woocommerce_page_wc-settings' );
		$this->assertSame( array(), $this->scripts, 'nothing on other admin pages' );
		$admin->enqueue( 'toplevel_page_rewloy-for-woocommerce' );
		$this->assertSame( array( 'style:rewloy-wc-panel', 'rewloy-wc-till' ), $this->scripts );
		$this->scripts = array();
		$_GET          = array( 'tab' => 'cards' );
		$admin->enqueue( 'toplevel_page_rewloy-for-woocommerce' );
		$this->assertSame( array( 'style:rewloy-wc-panel', 'rewloy-wc-watch' ), $this->scripts );
		$this->scripts = array();
		$_GET          = array( 'tab' => 'settings' );
		$admin->enqueue( 'toplevel_page_rewloy-for-woocommerce' );
		$this->assertSame( array( 'style:rewloy-wc-panel' ), $this->scripts );
		$this->scripts = array();
		$this->can     = false;
		$admin->enqueue( 'toplevel_page_rewloy-for-woocommerce' );
		$this->assertSame( array(), $this->scripts );
	}

	public function test_the_open_tab_is_the_current_entry_of_the_rewloy_menu(): void {
		$admin = $this->admin();
		$_GET  = array( 'tab' => 'till' );
		$this->assertSame( 'admin.php?page=rewloy-for-woocommerce&tab=till', $admin->submenu_file( null, 'rewloy-for-woocommerce' ) );
		$this->assertSame( 'x', $admin->submenu_file( 'x', 'woocommerce' ), 'other menus untouched' );
		$_GET = array();
		$this->assertNull( $admin->submenu_file( null, 'rewloy-for-woocommerce' ) );
	}

	/* ------------------------------------------------------------------- ajax */

	private function ajax(): Ajax {
		$panel = $this->panel();
		return new Ajax(
			$panel,
			new Till( $panel ),
			function ( array $body, int $status ): void {
				$this->sent[] = array( $body, $status );
			}
		);
	}

	/** @return array<string,array{0:string}> */
	public static function ajaxActions(): array {
		return array(
			'activity' => array( 'activity' ),
			'lookup'   => array( 'till_lookup' ),
			'sale'     => array( 'till_sale' ),
			'action'   => array( 'till_action' ),
		);
	}

	/** @dataProvider ajaxActions */
	public function test_every_ajax_action_needs_the_capability_first( string $method ): void {
		$this->can = false;
		$this->ajax()->{$method}();
		$this->assertSame( 403, $this->sent[0][1] );
		$this->assertFalse( $this->sent[0][0]['ok'] );
		$this->assertSame( array(), $this->requests );
	}

	/** @dataProvider ajaxActions */
	public function test_every_ajax_action_needs_its_nonce_and_a_post( string $method ): void {
		$this->ajaxNonceOk = false;
		$this->ajax()->{$method}();
		$this->assertSame( 403, $this->sent[0][1] );
		$this->ajaxNonceOk         = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->ajax()->{$method}();
		$this->assertSame( 405, $this->sent[1][1] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_activity_refresh_answers_masked_rows_only_with_view(): void {
		$this->script( $this->meAbilities( array( 'till' ) ) );
		$this->ajax()->activity();
		$this->assertSame( 403, $this->sent[0][1] );
		$this->script( $this->meAbilities( array( 'view' ) ), $this->activityAnswer() );
		$this->ajax()->activity();
		$body = $this->sent[1][0];
		$this->assertTrue( $body['ok'] );
		$this->assertSame( '••••-••••-JKLM', $body['rows'][0]['card'] );
		$json = (string) json_encode( $body );
		$this->assertStringNotContainsString( 'ayse@example.com', $json );
		$this->assertStringNotContainsString( 'ABCD-EFGH-JKLM', $json );
	}

	public function test_a_sale_through_the_door_reaches_rewloy_with_the_posted_press_key(): void {
		$_POST = array( 'serial' => self::SERIAL, 'amount' => '99,90', 'reference' => 'Z-7', 'key' => '6f1c2e8a-3b4d-4c5e-8f60-718293a4b5c6' );
		$this->script( $this->meAbilities( array( 'till' ) ), $this->answer( 200, array( 'data' => array( 'type' => 'stamp', 'applied' => 'stamps', 'credited' => 1, 'balance' => 1, 'duplicate' => false, 'rewardReady' => false, 'rewardsReady' => 0 ) ) ), $this->tillAnswer() );
		$this->ajax()->till_sale();
		$this->assertSame( 200, $this->sent[0][1] );
		$this->assertTrue( $this->sent[0][0]['ok'] );
		$this->assertSame( '6f1c2e8a-3b4d-4c5e-8f60-718293a4b5c6', $this->requests[1]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( 9990, json_decode( $this->requests[1]['args']['body'], true )['amountMinor'] );
	}
}
