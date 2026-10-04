<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Issuer;
use Rewloy\WooCommerce\Settings;

final class IssuerTest extends TestCase {

	private const CARD_URL = 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=PRIVATEVIEWINGKEY';

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->connected();
	}

	private function issuer(): Issuer {
		return new Issuer( $this->settings, $this->factory() );
	}

	private function order( int $id = 55, string $status = 'processing', string $email = 'ayse@example.com', bool $ticked = true ): \WC_Order {
		$order = new \WC_Order( $id, $status, $email );
		if ( $ticked ) {
			$order->update_meta_data( Issuer::META_INVITE, 'yes' );
		}
		return $order;
	}

	private function issued(): array {
		return $this->answer( 201, array( 'data' => array( 'serial' => 'ABCD-EFGH-JKLM', 'cardUrl' => self::CARD_URL ) ) );
	}

	public function test_a_paid_ticked_order_gets_exactly_one_issue_pass_with_its_idempotency_key(): void {
		$order = $this->order();
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );

		$this->assertCount( 1, $this->requests );
		$req = $this->requests[0];
		$this->assertSame( 'POST', $req['args']['method'] );
		$this->assertSame( 'https://app.rewloy.com/v1/passes', $req['url'] );
		$this->assertSame( 'woo-' . $this->settings->site_id() . '-55', $req['args']['headers']['Idempotency-Key'] );
		$this->assertLessThanOrEqual( 64, strlen( $req['args']['headers']['Idempotency-Key'] ) );
		$this->assertGreaterThanOrEqual( 8, strlen( $req['args']['headers']['Idempotency-Key'] ) );
		$this->assertSame(
			array( 'programId' => self::PROGRAM, 'email' => 'ayse@example.com', 'kvkkConsent' => true ),
			json_decode( $req['args']['body'], true ),
			'only the e-mail goes to Rewloy: no name, phone or address'
		);
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
		$this->assertSame( 'ABCD-EFGH-JKLM', $order->get_meta( Issuer::META_SERIAL ) );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'ABCD-EFGH-JKLM', $order->notes[0]['note'] );
		$this->assertEmpty( $order->notes[0]['customer'], 'the note is private, not a customer note' );
		$this->assertArrayHasKey( Settings::CLAIM_PREFIX . '55', $this->options, 'a card was opened: the claim is kept for good' );
		$this->assertCount( 2, preg_grep( '/^rewloy_wc_claim_/', array_keys( $this->options ) ), 'the order and its e-mail address' );
		$this->assertArrayNotHasKey( Issuer::META_CODE, $order->meta, 'the SENDING marker is gone' );
	}

	public function test_the_private_card_link_goes_to_the_customer_and_nowhere_else(): void {
		$order = $this->order();
		$this->script( $this->issued() );
		$this->issuer()->run( 55 );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ayse@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( self::CARD_URL, $this->mails[0]['body'] );
		$this->assertStringContainsString( 'Örnek Mağaza', $this->mails[0]['body'], 'the controller named in the mail is the shop' );

		$kept = serialize( array( $order->meta, $order->notes, $this->options, $this->transients ) );
		$this->assertStringNotContainsString( 'PRIVATEVIEWINGKEY', $kept );
		$this->assertStringNotContainsString( '?k=', $kept );
	}

	public function test_a_second_run_for_the_same_order_sends_nothing(): void {
		$this->order();
		$this->script( $this->issued(), $this->issued() );
		$issuer = $this->issuer();
		$issuer->run( 55 );
		$this->assertSame( 'done', $issuer->run( 55 ) );
		$this->assertSame( 'done', $issuer->run( 55 ) );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_run_that_overlaps_another_sends_nothing(): void {
		$this->order();
		$issuer = $this->issuer();
		$inner  = null;
		$client = new \Rewloy\WooCommerce\Client(
			self::KEY,
			'https://app.rewloy.com',
			function ( string $url, array $args ) use ( &$inner, &$issuer ) {
				$this->requests[] = array( 'url' => $url, 'args' => $args );
				$inner            = $issuer->run( 55 ); // The same order, in the middle of the first call.
				return $this->issued();
			},
			static function ( float $s ): void {}
		);
		$issuer = new Issuer( $this->settings, fn( string $k = '' ) => $client );
		$issuer->run( 55 );
		$this->assertSame( 'done', $inner, 'the first run wrote "unknown" before its request, so the second sees a settled order' );
		$this->assertCount( 1, $this->requests );
	}

	public function test_if_the_database_refuses_the_claim_nothing_is_sent(): void {
		$this->order();
		$this->wpdb->failInserts = true;
		$this->assertSame( 'claimed', $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_lock_with_a_ttl_is_taken_over_only_when_it_is_old(): void {
		$lock = new \Rewloy\WooCommerce\Lock();
		$this->assertTrue( $lock->acquire( 'rewloy_wc_claim_t', 120 ) );
		$this->assertFalse( $lock->acquire( 'rewloy_wc_claim_t', 120 ), 'fresh: turned away' );
		$this->options['rewloy_wc_claim_t'] = (string) ( time() - 300 );
		$this->assertTrue( $lock->acquire( 'rewloy_wc_claim_t', 120 ), 'old: taken over' );
		$this->assertFalse( $lock->acquire( 'rewloy_wc_claim_t', 120 ), 'and fresh again' );
		$this->options['rewloy_wc_claim_t'] = (string) ( time() - 300 );
		$this->assertFalse( $lock->acquire( 'rewloy_wc_claim_t' ), 'a lock without a ttl is never taken over' );
	}

	public function test_the_claim_is_an_insert_ignore_so_only_one_process_can_win_it(): void {
		$lock = new \Rewloy\WooCommerce\Lock();
		$this->assertTrue( $lock->acquire( 'rewloy_wc_claim_9' ) );
		$this->assertFalse( $lock->acquire( 'rewloy_wc_claim_9' ), 'the second caller is turned away' );
		$this->assertStringContainsString( 'INSERT IGNORE INTO `wp_options`', implode( "\n", $this->wpdb->queries ) );
		$lock->release( 'rewloy_wc_claim_9' );
		$this->assertTrue( $lock->acquire( 'rewloy_wc_claim_9' ) );
	}

	public function test_a_held_claim_blocks_a_run(): void {
		$this->order();
		$this->options[ Settings::CLAIM_PREFIX . '55' ] = '1';
		$this->assertSame( 'claimed', $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_no_call_when_the_box_was_not_ticked(): void {
		$this->order( 55, 'processing', 'ayse@example.com', false );
		$this->assertSame( 'not-ticked', $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_no_call_when_the_order_is_not_paid(): void {
		foreach ( array( 'pending', 'on-hold', 'cancelled', 'failed', 'refunded' ) as $status ) {
			$this->order( 55, $status );
			$this->assertSame( 'not-paid', $this->issuer()->run( 55 ), $status );
		}
		$this->assertSame( array(), $this->requests );
	}

	public function test_completed_counts_as_paid_too(): void {
		$this->order( 55, 'completed' );
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
	}

	public function test_no_call_when_the_invitation_is_off_or_the_shop_is_not_connected(): void {
		$this->order();
		$this->settings->update( array( 'invite' => false ) );
		$this->assertSame( 'off', $this->issuer()->run( 55 ) );
		$this->settings->update( array( 'invite' => true ) );
		$this->settings->clear_connection();
		$this->settings->update( array( 'invite' => true ) );
		$this->assertSame( 'off', $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_refusal_is_final_and_leaves_a_note_in_our_words(): void {
		$order = $this->order();
		$this->script( $this->failure( 422, 'EMAIL_BLOCKED' ), $this->issued() );
		$this->assertSame( Issuer::STATE_FAILED, $this->issuer()->run( 55 ) );
		$this->assertSame( 'EMAIL_BLOCKED', $order->get_meta( Issuer::META_CODE ) );
		$this->assertStringContainsString( 'no card was opened', $order->notes[0]['note'] );
		$this->assertStringContainsString( 'cannot be given a card', $order->notes[0]['note'] );
		$this->assertSame( 'done', $this->issuer()->run( 55 ), 'not retried on its own' );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_server_error_is_unknown_and_is_never_repeated(): void {
		$order = $this->order();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued() );
		$this->assertSame( Issuer::STATE_UNKNOWN, $this->issuer()->run( 55 ) );
		$this->assertSame( 'done', $this->issuer()->run( 55 ) );
		$this->assertCount( 1, $this->requests, 'a possibly-issued card is not asked for again' );
		$this->assertStringContainsString( 'may or may not', $order->notes[0]['note'] );
		$this->assertStringContainsString( 'not sent again', $order->notes[0]['note'] );
	}

	public function test_a_timeout_is_unknown_and_is_never_repeated(): void {
		$order = $this->order();
		$this->script( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), $this->issued() );
		$this->assertSame( Issuer::STATE_UNKNOWN, $this->issuer()->run( 55 ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( Issuer::STATE_UNKNOWN, $order->get_meta( Issuer::META_STATE ) );
	}

	public function test_an_unreadable_2xx_is_unknown(): void {
		$this->order();
		$this->script( array( 'response' => array( 'code' => 201 ), 'body' => 'oops', 'headers' => array() ) );
		$this->assertSame( Issuer::STATE_UNKNOWN, $this->issuer()->run( 55 ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( array(), $this->mails );
	}

	public function test_an_address_already_invited_from_another_order_is_not_invited_again(): void {
		$first = $this->order( 40, 'completed', 'AYSE@example.com' );
		$first->update_meta_data( Issuer::META_STATE, Issuer::STATE_ISSUED );
		$order = $this->order( 55 );
		$this->assertSame( Issuer::STATE_EXISTS, $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( '#40', $order->notes[0]['note'] );
	}

	public function test_a_possibly_issued_earlier_card_also_blocks(): void {
		$first = $this->order( 40, 'completed' );
		$first->update_meta_data( Issuer::META_STATE, Issuer::STATE_UNKNOWN );
		$this->order( 55 );
		$this->assertSame( Issuer::STATE_EXISTS, $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_earlier_refusal_does_not_block_a_new_order(): void {
		$first = $this->order( 40, 'completed' );
		$first->update_meta_data( Issuer::META_STATE, Issuer::STATE_FAILED );
		$this->order( 55 );
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
	}

	public function test_an_order_without_a_valid_email_gets_no_call(): void {
		$order = $this->order( 55, 'processing', 'not-an-email' );
		$this->assertSame( Issuer::STATE_FAILED, $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'NO_EMAIL', $order->get_meta( Issuer::META_CODE ) );
	}

	public function test_without_a_key_nothing_is_sent(): void {
		$this->order();
		$issuer = new Issuer( $this->settings, static fn( string $k = '' ) => null );
		$this->assertSame( Issuer::STATE_FAILED, $issuer->run( 55 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_if_the_mail_cannot_be_sent_the_card_is_still_recorded_and_the_note_says_so(): void {
		$this->mailOk = false;
		$order        = $this->order();
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
		$this->assertStringContainsString( 'could not be sent', $order->notes[0]['note'] );
		$this->assertStringNotContainsString( 'PRIVATEVIEWINGKEY', $order->notes[0]['note'] );
	}

	public function test_a_card_link_off_rewloy_com_is_never_mailed(): void {
		$this->order();
		$this->script( $this->answer( 201, array( 'data' => array( 'serial' => 'A', 'cardUrl' => 'https://evil.test/p/A?k=1' ) ) ) );
		$this->issuer()->run( 55 );
		$this->assertSame( array(), $this->mails );
	}

	public function test_on_paid_queues_a_ticked_paid_order_and_writes_nothing_to_it(): void {
		$order = $this->order();
		Functions\expect( 'as_enqueue_async_action' )->once()->with( Issuer::HOOK, array( 55 ), Issuer::GROUP, true );
		$saves = $order->saves;
		$this->issuer()->on_paid( 55, $order );
		$this->assertSame( '', $order->get_meta( Issuer::META_STATE ), 'no state is written: a stale order object cannot overwrite a later one' );
		$this->assertSame( $saves, $order->saves );
		$this->assertSame( array(), $this->requests, 'queuing makes no API call' );
	}

	public function test_on_paid_reads_the_order_afresh_not_the_object_it_was_given(): void {
		$stale = new \WC_Order( 77, 'processing', 'x@y.co' ); // Ticked in the "database"? No: this one is not.
		$real  = $this->order( 55 );
		Functions\expect( 'as_enqueue_async_action' )->once();
		$this->issuer()->on_paid( 55, $stale ); // The id decides; the object WooCommerce passed is not trusted.
		$this->assertSame( $real, \WC_Order::$db[55] );
	}

	public function test_on_paid_does_nothing_for_an_order_already_settled(): void {
		foreach ( array( Issuer::STATE_ISSUED, Issuer::STATE_UNKNOWN, Issuer::STATE_FAILED, Issuer::STATE_EXISTS ) as $state ) {
			$order = $this->order();
			$order->update_meta_data( Issuer::META_STATE, $state );
			Functions\expect( 'as_enqueue_async_action' )->never();
			$this->issuer()->on_paid( 55, $order );
		}
		$this->addToAssertionCount( 1 );
	}

	public function test_on_paid_ignores_an_unticked_order_and_an_invitation_that_is_off(): void {
		$unticked = $this->order( 55, 'processing', 'a@b.co', false );
		Functions\expect( 'as_enqueue_async_action' )->never();
		$this->issuer()->on_paid( 55, $unticked );
		$this->assertSame( '', $unticked->get_meta( Issuer::META_STATE ) );

		$ticked = $this->order( 56 );
		$this->settings->update( array( 'invite' => false ) );
		$this->issuer()->on_paid( 56, $ticked );
		$this->assertSame( '', $ticked->get_meta( Issuer::META_STATE ) );
	}

	public function test_the_block_checkout_field_counts_as_ticked(): void {
		$order = $this->order( 55, 'processing', 'ayse@example.com', false );
		$order->update_meta_data( Issuer::META_INVITE_BLOCKS, '1' );
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );

		$off = $this->order( 56, 'processing', 'b@example.com', false );
		$off->update_meta_data( Issuer::META_INVITE_BLOCKS, '0' );
		$this->assertSame( 'not-ticked', $this->issuer()->run( 56 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_action_scheduler_a_paid_order_is_run_directly(): void {
		$this->settings = $this->connected();
		$order          = $this->order();
		$this->script( $this->issued() );
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ) );
		$this->issuer()->on_paid( 55, $order );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
	}

	public function test_the_retry_action_exists_only_after_a_clear_refusal(): void {
		global $theorder;
		$issuer = $this->issuer();

		$theorder = $this->order( 55 );
		$this->assertSame( array(), $issuer->order_actions( array() ) );
		foreach ( array( Issuer::STATE_ISSUED, Issuer::STATE_UNKNOWN, Issuer::STATE_EXISTS ) as $state ) {
			$theorder->update_meta_data( Issuer::META_STATE, $state );
			$this->assertSame( array(), $issuer->order_actions( array() ), $state );
		}
		$theorder->update_meta_data( Issuer::META_STATE, Issuer::STATE_FAILED );
		$this->assertArrayHasKey( 'rewloy_retry_card', $issuer->order_actions( array() ) );
		$this->can = false;
		$this->assertSame( array(), $issuer->order_actions( array() ), 'it needs manage_woocommerce, like every other action' );
	}

	public function test_retry_runs_again_after_a_refusal_but_never_after_an_unclear_answer(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->failure( 422, 'EMAIL_BLOCKED' ), $this->issued() );
		$issuer->run( 55 );
		$this->assertArrayNotHasKey( Settings::CLAIM_PREFIX . '55', $this->options, 'a clear refusal holds nothing' );
		$issuer->retry( $order );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );

		$unknown = $this->order( 56, 'processing', 'b@example.com' );
		$this->script( $this->failure( 502, 'INTERNAL' ), $this->issued() );
		$issuer->run( 56 );
		$issuer->retry( $unknown );
		$this->assertCount( 3, $this->requests, 'an unclear answer is not retried, not even by the button' );
		$this->assertSame( Issuer::STATE_UNKNOWN, $unknown->get_meta( Issuer::META_STATE ) );
	}

	public function test_retry_needs_manage_woocommerce(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->failure( 422, 'EMAIL_BLOCKED' ), $this->issued() );
		$issuer->run( 55 );
		$this->can = false;
		$issuer->retry( $order );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( Issuer::STATE_FAILED, $order->get_meta( Issuer::META_STATE ) );
	}

	public function test_a_stale_retry_cannot_open_a_second_card(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $issuer->run( 55 ) );
		// A slow page that loaded the order while it was "failed" writes that state back, then the button is pressed.
		$order->update_meta_data( Issuer::META_STATE, Issuer::STATE_FAILED );
		$this->script( $this->issued() );
		$issuer->retry( $order );
		$this->assertCount( 1, $this->requests, 'the claim kept after the first card stops a second request' );
		$this->assertSame( array(), array_slice( $this->mails, 1 ) );
	}

	public function test_a_run_after_the_state_was_overwritten_is_still_stopped_by_the_claim(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->issued() );
		$issuer->run( 55 );
		$order->delete_meta_data( Issuer::META_STATE ); // Whatever erased it.
		$this->script( $this->issued() );
		$this->assertSame( 'claimed', $issuer->run( 55 ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_state_is_unknown_before_the_request_so_a_dying_process_leaves_the_safe_state(): void {
		$order = $this->order();
		$seen  = null;
		$client = new \Rewloy\WooCommerce\Client(
			self::KEY,
			'https://app.rewloy.com',
			function ( string $url, array $args ) use ( $order, &$seen ) {
				$seen = array( $order->get_meta( Issuer::META_STATE ), $order->get_meta( Issuer::META_CODE ) );
				throw new \RuntimeException( 'the process dies here' );
			},
			static function ( float $s ): void {}
		);
		$issuer = new Issuer( $this->settings, fn( string $k = '' ) => $client );
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ) );
		$this->assertSame( array( Issuer::STATE_UNKNOWN, 'SENDING' ), $seen );
		$this->assertSame( Issuer::STATE_UNKNOWN, $order->get_meta( Issuer::META_STATE ), 'anything thrown leaves "unknown", final' );
		$this->assertArrayHasKey( Settings::CLAIM_PREFIX . '55', $this->options );
		$this->assertSame( 'done', $issuer->run( 55 ) );
	}

	public function test_a_crash_while_mailing_leaves_the_card_recorded(): void {
		$order = $this->order();
		$this->script( $this->issued() );
		Functions\when( 'wp_mail' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'an SMTP plugin blew up' );
			}
		);
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
		$this->assertSame( 'ABCD-EFGH-JKLM', $order->get_meta( Issuer::META_SERIAL ) );
		$this->assertStringContainsString( 'could not be sent', $order->notes[0]['note'] );
	}

	public function test_two_orders_of_one_address_cannot_both_open_a_card(): void {
		$this->order( 55 );
		$this->order( 56 );
		$issuer = $this->issuer();
		$second = null;
		$client = new \Rewloy\WooCommerce\Client(
			self::KEY,
			'https://app.rewloy.com',
			function ( string $url, array $args ) use ( &$second, &$issuer ) {
				$this->requests[] = array( 'url' => $url, 'args' => $args );
				$second           = $issuer->run( 56 ); // The other order of the same address, paid at the same moment.
				return $this->issued();
			},
			static function ( float $s ): void {}
		);
		$issuer = new Issuer( $this->settings, fn( string $k = '' ) => $client );
		$issuer->run( 55 );
		$this->assertSame( Issuer::STATE_EXISTS, $second );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '#55', \WC_Order::$db[56]->notes[0]['note'] );
	}

	public function test_the_address_claim_stops_an_order_whose_sibling_has_not_written_its_state_yet(): void {
		$this->order( 56 );
		$this->options[ Settings::CLAIM_PREFIX . 'email_' . substr( md5( 'salt' . 'ayse@example.com' ), 0, 32 ) ] = '1';
		$this->assertSame( Issuer::STATE_EXISTS, $this->issuer()->run( 56 ) );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'another order of this e-mail address', \WC_Order::$db[56]->notes[0]['note'] );
	}

	public function test_a_clear_refusal_lets_the_address_be_invited_from_another_order(): void {
		$this->order( 55 );
		$this->order( 56 );
		$this->script( $this->failure( 422, 'EMAIL_BLOCKED' ), $this->issued() );
		$issuer = $this->issuer();
		$this->assertSame( Issuer::STATE_FAILED, $issuer->run( 55 ) );
		$this->assertSame( Issuer::STATE_ISSUED, $issuer->run( 56 ) );
	}

	public function test_the_address_claim_holds_no_address_in_the_clear(): void {
		$this->order();
		$this->script( $this->issued() );
		$this->issuer()->run( 55 );
		foreach ( array_keys( $this->options ) as $name ) {
			$this->assertStringNotContainsString( 'ayse', (string) $name );
			$this->assertStringNotContainsString( 'example.com', (string) $name );
		}
	}
}
