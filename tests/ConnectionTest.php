<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\Connection;
use Rewloy\WooCommerce\Settings;
use Rewloy\WooCommerce\Webhooks;

final class ConnectionTest extends TestCase {

	private function connection( ?Settings $settings = null ): Connection {
		$settings ??= new Settings();
		return new Connection( $settings, new Webhooks( $settings ), $this->factory(), null, $this->anonymousFactory() );
	}

	/** A connection that makes each client with the key it is asked for, as the plugin does. */
	private function keyedConnection( Settings $settings ): Connection {
		return new Connection( $settings, new Webhooks( $settings ), $this->keyedFactory( $settings ), null, $this->anonymousFactory() );
	}

	/** The programme list as the API answers it. */
	private function programs(): array {
		return $this->answer(
			200,
			array(
				'data' => array(
					array( 'id' => self::PROGRAM, 'type' => 'stamp', 'name' => 'Kahve Kartı', 'status' => 'active', 'joinUrl' => 'https://rewloy.com/join/' . self::PROGRAM ),
					array( 'id' => '0192bbbb-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'type' => 'giftcard', 'name' => 'Hediye', 'status' => 'active', 'joinUrl' => null ),
					array( 'id' => '0192cccc-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'type' => 'vip', 'name' => 'VIP', 'status' => 'active', 'joinUrl' => 'https://evil.test/join/x' ),
					array( 'id' => '0192dddd-5c6d-7e8f-9a0b-1c2d3e4f5a6b', 'type' => 'points', 'name' => 'Eski', 'status' => 'archived', 'joinUrl' => null ),
				),
			)
		);
	}

	private function shopAnswer( array $over = array() ): array {
		return $this->answer(
			201,
			array(
				'data' => array_merge(
					array(
						'id'          => self::LINK,
						'platform'    => 'woocommerce',
						'programId'   => self::PROGRAM,
						'programName' => 'Kahve Kartı',
						'currency'    => 'TRY',
						'webhookUrl'  => 'https://app.rewloy.com/hooks/store/' . self::LINK,
						'secret'      => 'wc_SECRETSECRETSECRETSECRETSECRETSECRET',
					),
					$over
				),
			)
		);
	}

	public function test_save_key_checks_the_key_with_the_api_and_saves_it_only_if_accepted(): void {
		$settings = new Settings();
		$this->script( $this->meAnswer() );
		$conn = new Connection( $settings, new Webhooks( $settings ), fn( string $key = '' ) => $this->client( '' !== $key ? $key : self::KEY ) );
		$r    = $conn->save_key( '  ' . self::KEY . ' ' );
		$this->assertTrue( $r->ok );
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ] );
		$this->assertSame( 'false', $this->autoload[ Settings::KEY_OPTION ] );
		$this->assertStringNotContainsString( 'SECRET', $r->message );
		$this->assertStringNotContainsString( 'more than this plugin needs', $r->message );
		$this->assertSame( 'Bearer ' . self::KEY, $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'https://app.rewloy.com/v1/me', $this->requests[0]['url'], 'the key is checked with me, which says what it may do' );
	}

	public function test_a_key_that_may_do_more_than_the_plugin_needs_is_saved_with_a_word_about_it(): void {
		$settings = new Settings();
		$this->script( $this->meAnswer( array( 'passes.issue', 'programs.read', 'apikeys.manage', 'settings.read', 'customers.read', 'team.manage' ) ) );
		$r = $this->keyedConnection( $settings )->save_key( self::KEY );
		$this->assertTrue( $r->ok, 'the old names (apikeys.manage, settings.read) still do' );
		$this->assertStringContainsString( 'more than this plugin needs', $r->message );
		$this->assertStringContainsString( 'E-ticaret', $r->message );
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ] );
	}

	public function test_a_key_missing_a_permission_is_refused_and_says_which(): void {
		foreach ( array(
			'shops.manage'  => array( 'passes.issue', 'programs.read', 'shops.read' ),
			'passes.issue'  => array( 'programs.read', 'shops.manage', 'shops.read' ),
			'programs.read' => array( 'passes.issue', 'shops.manage', 'shops.read' ),
			'shops.read'    => array( 'passes.issue', 'programs.read', 'shops.manage' ),
		) as $missing => $have ) {
			$this->requests = array();
			$this->script( $this->meAnswer( $have ) );
			$r = $this->keyedConnection( new Settings() )->save_key( self::KEY );
			$this->assertFalse( $r->ok, $missing );
			$this->assertStringContainsString( $missing, $r->message );
			$this->assertStringContainsString( 'E-ticaret', $r->message );
			$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options, 'a key that cannot do the job is not kept' );
		}
	}

	public function test_a_key_that_belongs_to_a_shop_link_cannot_make_another_and_is_refused(): void {
		$this->script( $this->meAnswer( null, self::LINK ) );
		$r = $this->keyedConnection( new Settings() )->save_key( self::KEY );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'shop link of its own', $r->message );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
	}

	public function test_an_answer_that_is_not_a_key_is_refused(): void {
		$this->script( $this->answer( 200, array( 'data' => array( 'kind' => 'staff' ) ) ) );
		$this->assertFalse( $this->keyedConnection( new Settings() )->save_key( self::KEY )->ok );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
	}

	public function test_a_refused_key_is_not_saved_and_the_message_does_not_repeat_it(): void {
		$settings = new Settings();
		$this->script( $this->failure( 401, 'INVALID_API_KEY' ) );
		$conn = new Connection( $settings, new Webhooks( $settings ), fn( string $key = '' ) => $this->client( $key ) );
		$r    = $conn->save_key( self::KEY );
		$this->assertFalse( $r->ok );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
		$this->assertStringContainsString( 'did not accept this API key', $r->message );
		$this->assertStringNotContainsString( 'SECRET', $r->message );
	}

	public function test_a_malformed_key_does_not_even_reach_the_api(): void {
		$r = $this->connection()->save_key( 'hello' );
		$this->assertFalse( $r->ok );
		$this->assertSame( array(), $this->requests );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_key_in_its_own_process(): void {
		define( 'REWLOY_API_KEY', self::KEY );
		$settings = new Settings();
		$this->assertSame( 'constant', $settings->key_source() );
		$this->assertSame( self::KEY, $settings->api_key() );
		$this->options[ Settings::KEY_OPTION ] = 'rwk_savedkeysavedkey1234';
		$this->assertSame( self::KEY, $settings->api_key(), 'the constant wins over the option' );
		$r = ( new Connection( $settings, new Webhooks( $settings ), $this->factory() ) )->save_key( 'rwk_anotherkeyanotherkey1' );
		$this->assertFalse( $r->ok );
		$this->assertSame( array(), $this->requests );
		$r = ( new Connection( $settings, new Webhooks( $settings ), $this->factory(), null, $this->anonymousFactory() ) )->connect_with_code( self::CODE );
		$this->assertFalse( $r->ok, 'the constant would win over the key a code makes' );
		$this->assertStringContainsString( 'REWLOY_API_KEY', $r->message );
		$this->assertSame( array(), $this->requests, 'and the code is not spent' );
	}

	public function test_programs_lists_only_what_an_order_can_fill(): void {
		$this->script( $this->programs() );
		$list = $this->connection()->programs();
		$this->assertSame( array( self::PROGRAM, '0192cccc-5c6d-7e8f-9a0b-1c2d3e4f5a6b' ), array_column( $list, 'id' ) );
		$this->assertSame( 'https://rewloy.com/join/' . self::PROGRAM, $list[0]['join_url'] );
		$this->assertSame( '', $list[1]['join_url'], 'a join link off rewloy.com is not kept' );
	}

	public function test_connect_creates_the_link_then_the_webhook_and_stores_both_ids(): void {
		$settings = new Settings();
		$this->script( $this->programs(), $this->shopAnswer() );
		$r = $this->connection( $settings )->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'amount', 'per_amount' => '99,50', 'step' => '2' ) );
		$this->assertTrue( $r->ok, $r->message );

		$create = $this->requests[1];
		$this->assertSame( 'POST', $create['args']['method'] );
		$this->assertSame(
			array( 'platform' => 'woocommerce', 'programId' => self::PROGRAM, 'rule' => 'amount', 'step' => 2, 'perAmountMinor' => 9950 ),
			json_decode( $create['args']['body'], true )
		);

		$this->assertCount( 1, \WC_Webhook::$db );
		$hook = \WC_Webhook::$db[41];
		$this->assertSame( 'order.updated', $hook->props['topic'] );
		$this->assertSame( 'wp_api_v3', $hook->props['api_version'] );
		$this->assertSame( 'active', $hook->props['status'] );
		$this->assertSame( 'https://app.rewloy.com/hooks/store/' . self::LINK, $hook->props['delivery_url'] );
		$this->assertSame( 'wc_SECRETSECRETSECRETSECRETSECRETSECRET', $hook->props['secret'] );
		$this->assertSame( 7, $hook->props['user_id'] );

		$s = $settings->get();
		$this->assertSame( self::LINK, $s['link_id'] );
		$this->assertSame( Settings::VIA_KEY, $s['via'] );
		$this->assertSame( 41, $s['webhook_id'] );
		$this->assertSame( 'amount', $s['rule'] );
		$this->assertSame( 9950, $s['per_amount_minor'] );
		$this->assertSame( 2, $s['step'] );
		$this->assertSame( 'https://rewloy.com/join/' . self::PROGRAM, $s['join_url'] );
		$this->assertStringNotContainsString( 'SECRET', serialize( $this->options ), 'the secret is kept nowhere but in the webhook' );
	}

	public function test_connect_for_a_vip_card_sends_no_amount_rule(): void {
		$vip = '0192cccc-5c6d-7e8f-9a0b-1c2d3e4f5a6b';
		$this->script( $this->programs(), $this->shopAnswer( array( 'programId' => $vip ) ) );
		$r = $this->connection()->connect( array( 'program_id' => $vip, 'rule' => 'amount', 'per_amount' => '100', 'step' => '5' ) );
		$this->assertTrue( $r->ok, $r->message );
		$this->assertSame( array( 'platform' => 'woocommerce', 'programId' => $vip, 'rule' => 'order' ), json_decode( $this->requests[1]['args']['body'], true ) );
	}

	public function test_connect_refuses_what_the_panel_would_refuse_before_calling_create(): void {
		$cases = array(
			'no card'          => array( array( 'program_id' => '' ), 'Choose a card' ),
			'not a uuid'       => array( array( 'program_id' => '../x' ), 'Choose a card' ),
			'a gift card'      => array( array( 'program_id' => '0192bbbb-5c6d-7e8f-9a0b-1c2d3e4f5a6b' ), 'cannot fill it' ),
			'bad step'         => array( array( 'program_id' => self::PROGRAM, 'step' => '0' ), 'between 1 and 100' ),
			'bad amount'       => array( array( 'program_id' => self::PROGRAM, 'rule' => 'amount', 'per_amount' => '0,10', 'step' => '1' ), 'amount threshold' ),
		);
		foreach ( $cases as $name => [ $input, $text ] ) {
			$this->requests = array();
			$this->script( $this->programs() );
			$r = $this->connection()->connect( $input );
			$this->assertFalse( $r->ok, $name );
			$this->assertStringContainsString( $text, $r->message, $name );
			foreach ( $this->requests as $req ) {
				$this->assertSame( 'GET', $req['args']['method'], $name . ': nothing was created' );
			}
		}
	}

	public function test_a_refusal_from_create_shop_is_shown_in_our_words(): void {
		$this->script( $this->programs(), $this->failure( 409, 'LIMIT' ) );
		$r = $this->connection()->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'At most 5 shops', $r->message );
		$this->assertStringContainsString( 'req-123', $r->message );
		$this->assertStringNotContainsString( 'Türkçe API iletisi', $r->message );
		$this->assertSame( array(), \WC_Webhook::$db );
	}

	public function test_if_the_webhook_cannot_be_made_the_link_is_deleted_again(): void {
		\WC_Webhook::$failSave = true;
		$settings              = new Settings();
		$this->script( $this->programs(), $this->shopAnswer(), $this->answer( 204 ) );
		$r = $this->connection( $settings )->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertFalse( $r->ok );
		$this->assertSame( 'DELETE', $this->requests[2]['args']['method'] );
		$this->assertStringEndsWith( '/v1/shops/' . self::LINK, $this->requests[2]['url'] );
		$this->assertFalse( $settings->is_connected() );
	}

	public function test_if_the_link_cannot_be_removed_again_the_message_says_where_to_delete_it(): void {
		\WC_Webhook::$failSave = true;
		$this->script( $this->programs(), $this->shopAnswer(), $this->failure( 403, 'FORBIDDEN' ) );
		$r = $this->connection()->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'could not be removed again', $r->message );
		$this->assertStringContainsString( 'E-ticaret', $r->message );
	}

	public function test_a_delivery_address_on_another_host_is_never_trusted(): void {
		foreach ( array(
			'https://evil.test/hooks/store/' . self::LINK,
			'http://app.rewloy.com/hooks/store/' . self::LINK,
			'https://app.rewloy.com/hooks/store/other-id',
			'https://app.rewloy.com/hooks/store/' . self::LINK . '?x=1',
			'https://app.rewloy.com:8443/hooks/store/' . self::LINK,
			'https://user:pw@app.rewloy.com/hooks/store/' . self::LINK,
			'https://user@app.rewloy.com/hooks/store/' . self::LINK,
			'',
		) as $url ) {
			$this->requests = array();
			\WC_Webhook::$db = array();
			$settings       = new Settings();
			$this->script( $this->programs(), $this->shopAnswer( array( 'webhookUrl' => $url ) ), $this->answer( 204 ) );
			$r = $this->connection( $settings )->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
			$this->assertFalse( $r->ok, $url );
			$this->assertSame( array(), \WC_Webhook::$db, 'no webhook (and so no secret) for ' . $url );
			$this->assertSame( 'DELETE', $this->requests[2]['args']['method'] );
			$this->assertFalse( $settings->is_connected() );
		}
	}

	public function test_a_second_connect_at_the_same_moment_is_turned_away_without_any_call(): void {
		$this->options['rewloy_wc_claim_connecting'] = (string) time();
		$r = $this->connection()->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'already being made', $r->message );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_connect_lock_is_let_go_after_a_connect_and_after_a_failed_one(): void {
		$this->script( $this->programs(), $this->failure( 409, 'LIMIT' ) );
		$this->connection()->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertArrayNotHasKey( 'rewloy_wc_claim_connecting', $this->options );
		$this->script( $this->programs(), $this->shopAnswer() );
		$this->assertTrue( $this->connection()->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) )->ok );
		$this->assertArrayNotHasKey( 'rewloy_wc_claim_connecting', $this->options );
		$this->assertCount( 1, \WC_Webhook::$db, 'one link, one webhook' );
	}

	public function test_a_connect_lock_left_by_a_dead_process_is_taken_over_after_two_minutes(): void {
		$this->options['rewloy_wc_claim_connecting'] = (string) ( time() - 600 );
		$this->script( $this->programs(), $this->shopAnswer() );
		$this->assertTrue( $this->connection()->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) )->ok );
	}

	public function test_connect_when_already_connected_does_nothing(): void {
		$settings = $this->connected();
		$r        = $this->connection( $settings )->connect( array( 'program_id' => self::PROGRAM ) );
		$this->assertFalse( $r->ok );
		$this->assertSame( array(), $this->requests );
	}

	public function test_pause_and_resume_set_the_link_with_patch(): void {
		$settings = $this->connected();
		$this->script( $this->answer( 200, array( 'data' => array( 'enabled' => false ) ) ), $this->answer( 200, array( 'data' => array( 'enabled' => true ) ) ) );
		$conn = $this->connection( $settings );
		$this->assertTrue( $conn->set_paused( true )->ok );
		$this->assertSame( array( 'enabled' => false ), json_decode( $this->requests[0]['args']['body'], true ) );
		$this->assertTrue( $conn->set_paused( false )->ok );
		$this->assertSame( array( 'enabled' => true ), json_decode( $this->requests[1]['args']['body'], true ) );
		$this->assertStringEndsWith( '/v1/shops/' . self::LINK, $this->requests[0]['url'] );
	}

	public function test_connecting_and_disconnecting_ask_for_one_rewrite_flush_only_when_the_account_tab_is_on(): void {
		$this->script( $this->programs(), $this->shopAnswer(), $this->answer( 204 ) );
		$settings = new Settings();
		$this->connection( $settings )->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertArrayNotHasKey( \Rewloy\WooCommerce\Plugin::FLUSH_OPTION, $this->options, 'tab off: nothing to flush' );
		$settings->update( array( 'account_tab' => true ) );
		$this->connection( $settings )->disconnect();
		$this->assertSame( '1', $this->options[ \Rewloy\WooCommerce\Plugin::FLUSH_OPTION ] ?? null, 'the tab goes away with the connection' );

		unset( $this->options[ \Rewloy\WooCommerce\Plugin::FLUSH_OPTION ] );
		$this->script( $this->programs(), $this->shopAnswer() );
		$this->connection( $settings )->connect( array( 'program_id' => self::PROGRAM, 'rule' => 'order', 'step' => '1' ) );
		$this->assertSame( '1', $this->options[ \Rewloy\WooCommerce\Plugin::FLUSH_OPTION ] ?? null, 'and comes back with it' );
	}

	public function test_disconnect_deletes_the_link_and_the_webhook_and_forgets_the_connection(): void {
		$settings = $this->connected();
		$hook     = $this->makeWebhook();
		$this->script( $this->answer( 204 ) );
		$r = $this->connection( $settings )->disconnect();
		$this->assertTrue( $r->ok, $r->message );
		$this->assertSame( 'DELETE', $this->requests[0]['args']['method'] );
		$this->assertTrue( $hook->deleted );
		$this->assertFalse( $settings->is_connected() );
		$this->assertSame( 0, $settings->get()['webhook_id'] );
	}

	public function test_disconnect_when_the_link_is_already_gone_in_rewloy_still_finishes(): void {
		$settings = $this->connected();
		$this->script( $this->failure( 404, 'SHOP_NOT_FOUND' ) );
		$this->assertTrue( $this->connection( $settings )->disconnect()->ok );
		$this->assertFalse( $settings->is_connected() );
	}

	public function test_disconnect_keeps_everything_when_rewloy_refuses(): void {
		$settings = $this->connected();
		$hook     = $this->makeWebhook();
		$this->script( $this->failure( 403, 'FORBIDDEN' ) );
		$r = $this->connection( $settings )->disconnect();
		$this->assertFalse( $r->ok );
		$this->assertTrue( $settings->is_connected(), 'the connection is not forgotten while Rewloy still has the link' );
		$this->assertFalse( $hook->deleted );
	}

	public function test_forgetting_locally_makes_no_api_call(): void {
		$settings = $this->connected();
		$hook     = $this->makeWebhook();
		$r = $this->connection( $settings )->disconnect( true );
		$this->assertTrue( $r->ok );
		$this->assertSame( array(), $this->requests );
		$this->assertTrue( $hook->deleted );
		$this->assertFalse( $settings->is_connected() );
	}

	public function test_forget_key_only_when_not_connected(): void {
		$settings = new Settings();
		$settings->save_api_key( self::KEY );
		$this->assertTrue( $this->connection( $settings )->forget_key()->ok );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );

		$settings = $this->connected();
		$settings->save_api_key( self::KEY );
		$this->assertFalse( $this->connection( $settings )->forget_key()->ok );
		$this->assertArrayHasKey( Settings::KEY_OPTION, $this->options );
	}

	public function test_health_reads_the_link_and_the_last_orders_and_the_webhook(): void {
		$settings = $this->connected();
		$this->makeWebhook( 41, null, 'disabled', 5 );
		$this->script(
			$this->answer( 200, array( 'data' => array( 'id' => self::LINK, 'enabled' => true, 'lastOrderAt' => '2026-10-03T10:00:00Z', 'orders' => array( 'credited' => 3 ) ) ) ),
			$this->answer( 200, array( 'data' => array( array( 'orderId' => '12', 'outcome' => 'unmatched', 'at' => '2026-10-03T10:00:00Z' ) ) ) )
		);
		$h = $this->connection( $settings )->health();
		$this->assertSame( '', $h['error'] );
		$this->assertTrue( $h['link']['enabled'] );
		$this->assertSame( 'unmatched', $h['orders'][0]['outcome'] );
		$this->assertTrue( $h['webhook']['exists'] );
		$this->assertSame( 'disabled', $h['webhook']['status'] );
		$this->assertSame( 5, $h['webhook']['failures'] );
		$this->assertStringContainsString( 'edit-webhook=41', $h['webhook']['edit_url'] );
	}

	public function test_health_reports_an_api_failure_without_throwing(): void {
		$settings = $this->connected();
		$this->script( $this->failure( 401, 'INVALID_API_KEY' ) );
		$h = $this->connection( $settings )->health();
		$this->assertNull( $h['link'] );
		$this->assertStringContainsString( 'did not accept this API key', $h['error'] );
		$this->assertFalse( $h['webhook']['exists'], 'no webhook 41 exists in this test' );
	}

	/* ------------------------------------------------------------------ the connect code */

	private function programsOfTheKey(): array {
		return $this->answer( 200, array( 'data' => array( array( 'id' => self::PROGRAM, 'type' => 'stamp', 'name' => 'Kahve Kartı', 'status' => 'active', 'joinUrl' => 'https://rewloy.com/join/' . self::PROGRAM ) ) ) );
	}

	public function test_a_code_connects_the_shop_in_one_step_and_keeps_only_what_it_should(): void {
		$settings = new Settings();
		$this->script( $this->connectAnswer(), $this->programsOfTheKey() );
		$r = $this->keyedConnection( $settings )->connect_with_code( '  ' . self::CODE . "\n" );
		$this->assertTrue( $r->ok, $r->message );

		$spend = $this->requests[0];
		$this->assertSame( 'POST', $spend['args']['method'] );
		$this->assertSame( 'https://app.rewloy.com/v1/shops/connect', $spend['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $spend['args']['headers'], 'the code is the credential: no key goes with it' );
		$this->assertSame( array( 'token' => self::CODE, 'shopName' => 'Örnek Mağaza' ), json_decode( $spend['args']['body'], true ) );

		$this->assertCount( 1, \WC_Webhook::$db );
		$hook = \WC_Webhook::$db[41];
		$this->assertSame( 'order.updated', $hook->props['topic'] );
		$this->assertSame( 'wp_api_v3', $hook->props['api_version'] );
		$this->assertSame( 'active', $hook->props['status'] );
		$this->assertSame( 'https://app.rewloy.com/hooks/store/' . self::LINK, $hook->props['delivery_url'] );
		$this->assertSame( 'wc_SECRETSECRETSECRETSECRETSECRETSECRET', $hook->props['secret'] );

		$s = $settings->get();
		$this->assertSame( self::LINK, $s['link_id'] );
		$this->assertSame( Settings::VIA_CODE, $s['via'] );
		$this->assertSame( 41, $s['webhook_id'] );
		$this->assertSame( self::PROGRAM, $s['program_id'] );
		$this->assertSame( 'Kahve Kartı', $s['program_name'] );
		$this->assertSame( 'stamp', $s['program_type'] );
		$this->assertSame( 'TRY', $s['currency'] );
		$this->assertSame( 'amount', $s['rule'] );
		$this->assertSame( 9950, $s['per_amount_minor'] );
		$this->assertSame( 2, $s['step'] );
		$this->assertSame( 'https://rewloy.com/join/' . self::PROGRAM, $s['join_url'], 'asked of Rewloy with the new key' );
		$this->assertSame( 'Bearer ' . self::PLUGIN_KEY, $this->requests[1]['args']['headers']['Authorization'] );

		$this->assertSame( self::PLUGIN_KEY, $this->options[ Settings::KEY_OPTION ] );
		$this->assertSame( 'false', $this->autoload[ Settings::KEY_OPTION ], 'the key is not loaded on every request' );
		$this->assertSame( 'option', $settings->key_source() );
		$kept = serialize( array_diff_key( $this->options, array( Settings::KEY_OPTION => 1 ) ) );
		$this->assertStringNotContainsString( 'SECRET', $kept, 'the secret is kept nowhere but in the webhook; the key nowhere but in its own option' );
		$this->assertStringNotContainsString( self::CODE, $kept . $r->message );
		$this->assertStringNotContainsString( 'SECRET', $r->message );
		$this->assertStringContainsString( 'this shop\'s link only', $r->message );
	}

	public function test_a_code_for_a_card_that_is_not_filled_by_orders_still_connects_with_what_rewloy_says(): void {
		$settings = new Settings();
		$this->script( $this->connectAnswer( array( 'shop' => array( 'id' => self::LINK, 'programId' => self::PROGRAM, 'programName' => 'VIP', 'programType' => 'vip', 'currency' => 'TRY', 'rule' => 'order', 'perAmountMinor' => 0, 'step' => 1, 'webhookUrl' => 'https://app.rewloy.com/hooks/store/' . self::LINK ) ) ), $this->failure( 500, 'INTERNAL' ) );
		$r = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
		$this->assertTrue( $r->ok, 'the card list is only for the join link: a failure there changes nothing' );
		$s = $settings->get();
		$this->assertSame( 'vip', $s['program_type'] );
		$this->assertSame( 'order', $s['rule'] );
		$this->assertSame( '', $s['join_url'] );
	}

	public function test_a_malformed_code_never_reaches_rewloy(): void {
		foreach ( array( '', 'hello', 'rwk_' . str_repeat( 'a', 43 ), 'rwc_short', self::CODE . 'x', '<script>' . self::CODE ) as $bad ) {
			$r = $this->keyedConnection( new Settings() )->connect_with_code( $bad );
			$this->assertFalse( $r->ok, $bad );
			$this->assertStringContainsString( 'not a Rewloy connect code', $r->message );
			$this->assertStringContainsString( 'Rewloy eklentisiyle', $r->message );
		}
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_refused_code_says_why_it_may_be_and_stores_nothing(): void {
		$this->script( $this->failure( 404, 'CONNECT_TOKEN_INVALID' ) );
		$settings = new Settings();
		$r        = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( '15 minutes', $r->message );
		$this->assertStringContainsString( 'used already', $r->message );
		$this->assertStringNotContainsString( self::CODE, $r->message );
		$this->assertStringNotContainsString( 'Türkçe API iletisi', $r->message );
		$this->assertFalse( $settings->is_connected() );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
		$this->assertSame( array(), \WC_Webhook::$db );
	}

	public function test_a_refusal_from_the_plan_or_the_limit_is_shown_in_our_words(): void {
		foreach ( array( array( 409, 'LIMIT', 'At most 5 shops' ), array( 403, 'PLAN_FEATURE_MISSING', 'not in this business' ), array( 429, 'RATE_LIMITED', 'too many requests' ) ) as [ $status, $code, $text ] ) {
			$this->requests = array();
			$this->script( $this->failure( $status, $code ) );
			$r = $this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
			$this->assertFalse( $r->ok, $code );
			$this->assertStringContainsString( $text, $r->message, $code );
		}
	}

	public function test_with_no_clear_answer_the_code_is_never_sent_twice_and_the_message_says_what_to_do(): void {
		foreach ( array( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), $this->failure( 502, 'INTERNAL' ), $this->failure( 500, 'INTERNAL' ) ) as $failure ) {
			$this->requests = array();
			$this->script( $failure, $this->connectAnswer() );
			$r = $this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
			$this->assertFalse( $r->ok );
			$this->assertCount( 1, $this->requests, 'a spent code answers 404 the second time and the answer is shown only once: no retry' );
			$this->assertStringContainsString( 'may or may not have been spent', $r->message );
			$this->assertStringContainsString( 'delete this shop\'s link', $r->message );
			$this->assertSame( array(), \WC_Webhook::$db );
		}
	}

	public function test_a_delivery_address_that_is_not_the_links_is_never_trusted_and_the_link_is_taken_back_with_its_own_key(): void {
		foreach ( array(
			'https://evil.test/hooks/store/' . self::LINK,
			'http://app.rewloy.com/hooks/store/' . self::LINK,
			'https://app.rewloy.com/hooks/store/other-id',
			'https://app.rewloy.com:8443/hooks/store/' . self::LINK,
			'',
		) as $url ) {
			$this->requests  = array();
			\WC_Webhook::$db = array();
			$this->options   = array();
			$settings        = new Settings();
			$this->script( $this->connectAnswer( array( 'shop' => array( 'id' => self::LINK, 'webhookUrl' => $url ) ) ), $this->answer( 204 ) );
			$r = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
			$this->assertFalse( $r->ok, $url );
			$this->assertSame( array(), \WC_Webhook::$db, 'no webhook (and so no secret) for ' . $url );
			$this->assertSame( 'DELETE', $this->requests[1]['args']['method'] );
			$this->assertStringEndsWith( '/v1/shops/' . self::LINK, $this->requests[1]['url'] );
			$this->assertSame( 'Bearer ' . self::PLUGIN_KEY, $this->requests[1]['args']['headers']['Authorization'], 'the key the code made removes its own link' );
			$this->assertFalse( $settings->is_connected() );
			$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options, 'a key for a link that is gone is not kept' );
			$this->assertStringContainsString( 'The code is spent', $r->message );
		}
	}

	public function test_an_answer_without_a_usable_key_or_secret_connects_nothing(): void {
		foreach ( array(
			'no secret'   => $this->connectAnswer( array( 'secret' => '' ) ),
			'a staff key' => $this->connectAnswer( array(), array( 'token' => 'rws_notakeynotakeynotakey' ) ),
			'no key'      => $this->connectAnswer( array( 'apiKey' => array() ) ),
		) as $name => $answer ) {
			$this->requests  = array();
			\WC_Webhook::$db = array();
			$this->options   = array();
			$settings        = new Settings();
			$this->script( $answer, $this->answer( 204 ) );
			$r = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
			$this->assertFalse( $r->ok, $name );
			$this->assertSame( array(), \WC_Webhook::$db, $name );
			$this->assertFalse( $settings->is_connected(), $name );
			$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options, $name );
			$this->assertStringContainsString( 'not what a connection looks like', $r->message, $name );
		}
		$this->assertStringContainsString( 'delete it there', $r->message, 'with no key there is nothing to take the link back with: the message says where it is' );
	}

	public function test_if_the_webhook_cannot_be_made_the_link_and_its_key_are_removed_again_and_the_key_is_not_kept(): void {
		\WC_Webhook::$failSave = true;
		$settings              = new Settings();
		$this->script( $this->connectAnswer(), $this->answer( 204 ) );
		$r = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
		$this->assertFalse( $r->ok );
		$this->assertSame( 'DELETE', $this->requests[1]['args']['method'] );
		$this->assertSame( 'Bearer ' . self::PLUGIN_KEY, $this->requests[1]['args']['headers']['Authorization'] );
		$this->assertStringContainsString( 'removed again', $r->message );
		$this->assertStringContainsString( 'make a new one', $r->message );
		$this->assertFalse( $settings->is_connected() );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
	}

	public function test_if_even_taking_the_link_back_fails_the_message_says_where_to_delete_it(): void {
		\WC_Webhook::$failSave = true;
		$this->script( $this->connectAnswer(), $this->failure( 503, 'INTERNAL' ), $this->failure( 503, 'INTERNAL' ) );
		$r = $this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'could not be removed again', $r->message );
		$this->assertStringContainsString( 'E-ticaret', $r->message );
	}

	public function test_a_test_environment_key_is_said_to_be_one(): void {
		$testKey = 'rwk_test_0a1b2c3d4eSECRETPLUGINKEYSECRETPLUGINKEY';
		$this->script( $this->connectAnswer( array( 'mode' => 'test' ), array( 'token' => $testKey ) ), $this->programsOfTheKey() );
		$r = $this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
		$this->assertTrue( $r->ok, $r->message );
		$this->assertStringContainsString( 'test environment', $r->message );
		$this->assertStringContainsString( 'e-mails the card link', $r->message );
		$this->assertSame( $testKey, $this->options[ Settings::KEY_OPTION ] );
	}

	public function test_a_code_is_refused_while_a_key_is_saved_or_the_shop_is_connected_without_any_call(): void {
		$settings = new Settings();
		$settings->save_api_key( self::KEY );
		$r = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'API key is saved', $r->message );
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ], 'the key of the advanced way is never overwritten' );

		$connected = $this->connected();
		$this->assertFalse( $this->keyedConnection( $connected )->connect_with_code( self::CODE )->ok );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_second_press_at_the_same_moment_does_not_spend_the_code_twice(): void {
		$this->options['rewloy_wc_claim_connecting'] = (string) time();
		$r = $this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
		$this->assertFalse( $r->ok );
		$this->assertStringContainsString( 'already being made', $r->message );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_connect_lock_is_let_go_after_a_code_whether_it_worked_or_not(): void {
		$this->script( $this->failure( 404, 'CONNECT_TOKEN_INVALID' ), $this->connectAnswer(), $this->programsOfTheKey() );
		$this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
		$this->assertArrayNotHasKey( 'rewloy_wc_claim_connecting', $this->options );
		$this->assertTrue( $this->keyedConnection( new Settings() )->connect_with_code( self::CODE )->ok );
		$this->assertArrayNotHasKey( 'rewloy_wc_claim_connecting', $this->options );
	}

	public function test_a_long_or_odd_site_title_is_cut_for_the_key_s_name(): void {
		\Brain\Monkey\Functions\when( 'get_bloginfo' )->justReturn( str_repeat( 'Ç', 60 ) . ' &amp; <b>Ortak</b>' );
		$this->script( $this->connectAnswer(), $this->programsOfTheKey() );
		$this->keyedConnection( new Settings() )->connect_with_code( self::CODE );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 40, mb_strlen( $body['shopName'] ) );
	}

	public function test_disconnecting_a_code_connection_asks_rewloy_with_the_plugins_key_and_drops_that_key(): void {
		$settings = new Settings();
		$this->script( $this->connectAnswer(), $this->programsOfTheKey(), $this->answer( 204 ) );
		$conn = $this->keyedConnection( $settings );
		$conn->connect_with_code( self::CODE );
		$hook = \WC_Webhook::$db[41];
		$r    = $conn->disconnect();
		$this->assertTrue( $r->ok, $r->message );
		$this->assertSame( 'DELETE', $this->requests[2]['args']['method'] );
		$this->assertSame( 'Bearer ' . self::PLUGIN_KEY, $this->requests[2]['args']['headers']['Authorization'] );
		$this->assertTrue( $hook->deleted );
		$this->assertFalse( $settings->is_connected() );
		$this->assertSame( '', $settings->get()['via'] );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options, 'Rewloy revoked it with the link: the plugin does not keep it' );
		$this->assertStringContainsString( 'revoked the key', $r->message );
		$this->assertSame( 'none', $settings->key_source(), 'back to the code screen' );
	}

	public function test_forgetting_a_code_connection_on_this_site_drops_the_key_too_without_any_call(): void {
		$settings = $this->connected( array( 'via' => Settings::VIA_CODE ) );
		$settings->save_api_key( self::PLUGIN_KEY );
		$this->makeWebhook();
		$r = $this->keyedConnection( $settings )->disconnect( true );
		$this->assertTrue( $r->ok );
		$this->assertSame( array(), $this->requests );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, $this->options );
		$this->assertStringContainsString( 'revokes the key', $r->message );
	}

	public function test_a_key_made_by_hand_stays_after_disconnecting(): void {
		$settings = $this->connected( array( 'via' => Settings::VIA_KEY ) );
		$settings->save_api_key( self::KEY );
		$this->script( $this->answer( 204 ) );
		$this->assertTrue( $this->keyedConnection( $settings )->disconnect()->ok );
		$this->assertSame( self::KEY, $this->options[ Settings::KEY_OPTION ], 'it was the person\'s own key' );
	}

	public function test_the_key_of_a_code_connection_is_in_no_message_and_no_note(): void {
		$settings = new Settings();
		$this->script( $this->connectAnswer(), $this->programsOfTheKey() );
		$r = $this->keyedConnection( $settings )->connect_with_code( self::CODE );
		$this->assertStringNotContainsString( 'SECRETPLUGINKEY', $r->message );
		$this->assertStringNotContainsString( 'SECRETPLUGINKEY', serialize( $settings->get() ), 'not in the settings option either' );
	}
}
