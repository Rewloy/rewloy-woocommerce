<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Client;
use Rewloy\WooCommerce\Issuer;
use Rewloy\WooCommerce\Settings;
use Rewloy\WooCommerce\Webhooks;

final class IssuerTest extends TestCase {

	private const CARD_URL = 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=PRIVATEVIEWINGKEY';

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->connected();
	}

	/** @var list<array{int,int}> The runs the issuer scheduled: [timestamp, order id]. */
	private array $scheduled = array();
	private bool $canSchedule = true;

	private function issuer( ?Client $client = null ): Issuer {
		$this->scheduled = array();
		return new Issuer(
			$this->settings,
			null === $client ? $this->factory() : fn( string $k = '' ) => $client,
			null,
			new Webhooks( $this->settings ),
			function ( int $when, int $order_id ): bool {
				if ( ! $this->canSchedule ) {
					return false;
				}
				$this->scheduled[] = array( $when, $order_id );
				return true;
			}
		);
	}

	/** Lets an unclear order's next attempt come: the schedule's time has passed. */
	private function itIsTime( \WC_Order $order ): void {
		$try         = (array) $order->get_meta( Issuer::META_TRY );
		$try['next'] = time() - 1;
		$order->update_meta_data( Issuer::META_TRY, $try );
	}

	private function order( int $id = 55, string $status = 'processing', string $email = 'ayse@example.com', bool $ticked = true ): \WC_Order {
		$order = new \WC_Order( $id, $status, $email );
		if ( $ticked ) {
			$order->update_meta_data( Issuer::META_INVITE, 'yes' );
		}
		return $order;
	}

	/**
	 * Rewloy's answer to issuePass for an order: the card, and what became of the order.
	 *
	 * @param array<string,mixed> $order   What `order` carries (`result`, ...), or array() for no order.
	 * @param array<string,string> $headers Response headers (lower-case names).
	 */
	private function issued( array $order = array( 'result' => 'waiting' ), array $headers = array() ): array {
		$data = array( 'serial' => 'ABCD-EFGH-JKLM', 'cardUrl' => self::CARD_URL );
		if ( array() !== $order ) {
			$data['order'] = array_merge( array( 'shopId' => self::LINK, 'orderId' => '55', 'outcome' => null ), $order );
		}
		return $this->answer( 201, array( 'data' => $data ), $headers );
	}

	public function test_a_paid_ticked_order_gets_exactly_one_issue_pass_with_its_idempotency_key_and_names_its_order(): void {
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
			array( 'programId' => self::PROGRAM, 'email' => 'ayse@example.com', 'kvkkConsent' => true, 'orderId' => '55', 'shopId' => self::LINK ),
			json_decode( $req['args']['body'], true ),
			'the e-mail, and the order and link it came by: no name, phone or address'
		);
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
		$this->assertSame( 'ABCD-EFGH-JKLM', $order->get_meta( Issuer::META_SERIAL ) );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'ABCD-EFGH-JKLM', $order->notes[0]['note'] );
		$this->assertEmpty( $order->notes[0]['customer'], 'the note is private, not a customer note' );
		$this->assertArrayNotHasKey( Issuer::META_CODE, $order->meta, 'the SENDING marker is gone' );
		$this->assertArrayNotHasKey( Issuer::META_TRY, $order->meta, 'the record of the request is not needed once the card is open' );
		$this->assertSame( array(), preg_grep( '/^rewloy_wc_claim_/', array_keys( $this->options ) ), 'nothing is held for good: the card is the order\'s state, and Rewloy keeps the rest' );
		$this->assertCount( 1, $this->mails );
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
		$forced = null;
		$client = new Client(
			self::KEY,
			'https://app.rewloy.com',
			function ( string $url, array $args ) use ( &$inner, &$forced, &$issuer ) {
				$this->requests[] = array( 'url' => $url, 'args' => $args );
				$inner            = $issuer->run( 55 ); // The same order, in the middle of the first call.
				$forced           = $issuer->run( 55, true ); // And a person pressing the order action.
				return $this->issued();
			},
			static function ( float $s ): void {}
		);
		$issuer = $this->issuer( $client );
		$issuer->run( 55 );
		$this->assertSame( 'done', $inner, 'the first run wrote "unknown" and its record before its request: it is not yet time for another' );
		$this->assertSame( 'claimed', $forced, 'and even a person\'s own request meets the lock' );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 1, $this->mails );
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

	public function test_a_held_lock_blocks_a_run_and_a_forgotten_one_does_not(): void {
		$order = $this->order();
		$this->options[ Settings::CLAIM_PREFIX . '55' ] = (string) time();
		$this->assertSame( 'claimed', $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );

		// A process that died holds nothing for good (0.1 kept a claim forever).
		$this->options[ Settings::CLAIM_PREFIX . '55' ] = (string) ( time() - 3 * Issuer::LOCK_TTL );
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
	}

	public function test_the_locks_are_let_go_after_a_card_and_after_a_refusal_and_after_an_unclear_answer(): void {
		$this->order( 55 );
		$this->order( 56, 'processing', 'b@example.com' );
		$this->order( 57, 'processing', 'c@example.com' );
		$this->script( $this->issued(), $this->failure( 422, 'EMAIL_BLOCKED' ), $this->failure( 500, 'INTERNAL' ) );
		$issuer = $this->issuer();
		$issuer->run( 55 );
		$issuer->run( 56 );
		$issuer->run( 57 );
		$this->assertSame( array(), preg_grep( '/^rewloy_wc_claim_/', array_keys( $this->options ) ) );
	}

	public function test_the_locks_are_let_go_when_the_run_throws(): void {
		$this->order();
		$issuer = new Issuer( $this->settings, static function ( string $k = '' ): never {
			throw new \LogicException( 'the factory blew up' );
		} );
		try {
			$issuer->run( 55 );
			$this->fail( 'expected the exception' );
		} catch ( \LogicException $e ) {
			$this->assertSame( array(), preg_grep( '/^rewloy_wc_claim_/', array_keys( $this->options ) ) );
		}
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
		$this->assertArrayNotHasKey( Issuer::META_TRY, $order->meta, 'Rewloy does not bind a key to a refused request: nothing to repeat' );
	}

	public function test_a_server_error_is_unknown_and_the_same_request_is_asked_again_later_not_now(): void {
		$order = $this->order();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued( array( 'result' => 'waiting' ), array( 'idempotent-replayed' => 'true' ) ) );
		$issuer = $this->issuer();
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ) );
		$this->assertSame( 'INTERNAL', $order->get_meta( Issuer::META_CODE ) );
		$this->assertSame( 'done', $issuer->run( 55 ), 'not before its time' );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'may or may not', $order->notes[0]['note'] );
		$this->assertStringContainsString( 'repeated automatically', $order->notes[0]['note'] );
		$this->assertStringContainsString( 'a second one cannot be opened', $order->notes[0]['note'] );

		$this->itIsTime( $order );
		$this->assertSame( Issuer::STATE_ISSUED, $issuer->run( 55 ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'], 'the same body' );
		$this->assertSame( $this->requests[0]['args']['headers']['Idempotency-Key'], $this->requests[1]['args']['headers']['Idempotency-Key'], 'under the same key: Rewloy replays, it does not open another' );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'an earlier request of this order had opened', $order->notes[1]['note'] );
		$this->assertArrayNotHasKey( Issuer::META_TRY, $order->meta );
	}

	public function test_a_timeout_is_unknown_after_the_clients_own_retries_and_is_asked_again_later(): void {
		$order = $this->order();
		$lost  = new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$this->script( $lost, $lost, $lost, $this->issued() );
		$this->assertSame( Issuer::STATE_UNKNOWN, $this->issuer()->run( 55 ) );
		$this->assertCount( 3, $this->requests, 'one attempt and the client\'s two retries, all with the same key' );
		$this->assertCount( 1, array_unique( array_map( static fn( $r ) => $r['args']['headers']['Idempotency-Key'], $this->requests ) ) );
		$this->assertSame( Issuer::STATE_UNKNOWN, $order->get_meta( Issuer::META_STATE ) );
	}

	public function test_a_still_running_first_request_is_unknown_not_a_refusal(): void {
		$order = $this->order();
		$busy  = $this->failure( 409, 'IDEMPOTENCY_IN_PROGRESS' );
		$this->script( $busy, $busy, $busy );
		$this->assertSame( Issuer::STATE_UNKNOWN, $this->issuer()->run( 55 ) );
		$this->assertSame( Issuer::STATE_UNKNOWN, $order->get_meta( Issuer::META_STATE ), 'a refusal would be final; this is not one' );
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

	/** The legacy order store ignores a meta_query: another order of the address must not count unless it invited. */
	public function test_an_earlier_order_that_never_invited_does_not_block(): void {
		$this->order( 40, 'completed', 'ayse@example.com', false );
		$this->order( 41, 'completed', 'ayse@example.com', true );
		$this->order( 55 );
		$this->script( $this->issued() );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_search_for_an_earlier_invitation_reads_past_the_first_page(): void {
		$old = $this->order( 40, 'completed' );
		$old->update_meta_data( Issuer::META_STATE, Issuer::STATE_ISSUED );
		for ( $i = 100; $i < 155; $i++ ) {
			$this->order( $i, 'completed', 'ayse@example.com', false );
		}
		$order = $this->order( 55 );
		$this->assertSame( Issuer::STATE_EXISTS, $this->issuer()->run( 55 ) );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( '#40', $order->notes[0]['note'] );
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

	public function test_on_paid_does_nothing_for_an_order_already_settled_and_queues_an_unclear_one(): void {
		foreach ( array( Issuer::STATE_ISSUED, Issuer::STATE_FAILED, Issuer::STATE_EXISTS ) as $state ) {
			$order = $this->order();
			$order->update_meta_data( Issuer::META_STATE, $state );
			Functions\expect( 'as_enqueue_async_action' )->never();
			$this->issuer()->on_paid( 55, $order );
		}
		$order = $this->order();
		$order->update_meta_data( Issuer::META_SERIAL, 'ABCD-EFGH-JKLM' ); // The state was erased; the card is still there.
		Functions\expect( 'as_enqueue_async_action' )->never();
		$this->issuer()->on_paid( 55, $order );

		$unclear = $this->order();
		$unclear->update_meta_data( Issuer::META_STATE, Issuer::STATE_UNKNOWN );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( Issuer::HOOK, array( 55 ), Issuer::GROUP, true );
		$this->issuer()->on_paid( 55, $unclear );
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

	public function test_the_retry_action_exists_after_a_refusal_or_an_unclear_answer_and_only_then(): void {
		global $theorder;
		$issuer = $this->issuer();

		$theorder = $this->order( 55 );
		$this->assertSame( array(), $issuer->order_actions( array() ) );
		foreach ( array( Issuer::STATE_ISSUED, Issuer::STATE_EXISTS ) as $state ) {
			$theorder->update_meta_data( Issuer::META_STATE, $state );
			$this->assertSame( array(), $issuer->order_actions( array() ), $state );
		}
		foreach ( array( Issuer::STATE_FAILED, Issuer::STATE_UNKNOWN ) as $state ) {
			$theorder->update_meta_data( Issuer::META_STATE, $state );
			$this->assertArrayHasKey( 'rewloy_retry_card', $issuer->order_actions( array() ), $state );
		}
		$theorder->update_meta_data( Issuer::META_SERIAL, 'ABCD-EFGH-JKLM' );
		$this->assertSame( array(), $issuer->order_actions( array() ), 'a card is open: nothing to try' );
		$theorder->delete_meta_data( Issuer::META_SERIAL );
		$this->can = false;
		$this->assertSame( array(), $issuer->order_actions( array() ), 'it needs manage_woocommerce, like every other action' );
	}

	public function test_retry_runs_again_after_a_refusal_and_repeats_the_same_request_after_an_unclear_answer(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->failure( 422, 'EMAIL_BLOCKED' ), $this->issued() );
		$issuer->run( 55 );
		$this->assertArrayNotHasKey( Settings::CLAIM_PREFIX . '55', $this->options, 'a clear refusal holds nothing' );
		$issuer->retry( $order );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );

		$unknown = $this->order( 56, 'processing', 'b@example.com' );
		$this->script( $this->failure( 502, 'INTERNAL' ), $this->failure( 502, 'INTERNAL' ), $this->failure( 502, 'INTERNAL' ), $this->issued( array( 'result' => 'waiting' ), array( 'idempotent-replayed' => 'true' ) ) );
		$issuer->run( 56 );
		$this->assertCount( 5, $this->requests, 'the second order: a request and the client\'s two retries' );
		$this->assertSame( Issuer::STATE_UNKNOWN, $unknown->get_meta( Issuer::META_STATE ) );
		$issuer->retry( $unknown ); // A person does not wait for the schedule.
		$this->assertCount( 6, $this->requests );
		$this->assertSame( Issuer::STATE_ISSUED, $unknown->get_meta( Issuer::META_STATE ) );
		$this->assertSame( $this->requests[2]['args']['headers']['Idempotency-Key'], $this->requests[5]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( $this->requests[2]['args']['body'], $this->requests[5]['args']['body'] );
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
		$this->assertCount( 1, $this->requests, 'the card\'s serial, kept beside the state, stops a second request' );
		$this->assertSame( array(), array_slice( $this->mails, 1 ) );
	}

	public function test_a_run_after_the_state_was_erased_is_still_stopped_by_the_serial(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->issued() );
		$issuer->run( 55 );
		$order->delete_meta_data( Issuer::META_STATE ); // Whatever erased it.
		$this->script( $this->issued() );
		$this->assertSame( 'done', $issuer->run( 55 ) );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 1, $this->mails );
	}

	/** Both erased: nothing on the order says a card was opened. Rewloy still does: the same key replays the first answer. */
	public function test_even_with_everything_erased_the_same_key_gets_the_same_card_back_not_a_second_one(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->issued() );
		$issuer->run( 55 );
		$order->delete_meta_data( Issuer::META_STATE );
		$order->delete_meta_data( Issuer::META_SERIAL );
		$this->script( $this->issued( array( 'result' => 'waiting' ), array( 'idempotent-replayed' => 'true' ) ) );
		$this->assertSame( Issuer::STATE_ISSUED, $issuer->run( 55 ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( $this->requests[0]['args']['headers']['Idempotency-Key'], $this->requests[1]['args']['headers']['Idempotency-Key'] );
		$this->assertStringContainsString( 'an earlier request of this order had opened', $order->notes[1]['note'] );
	}

	public function test_the_state_and_the_next_attempt_are_there_before_the_request_so_a_dying_process_leaves_what_a_repeat_needs(): void {
		$order  = $this->order();
		$seen   = null;
		$issuer = null;
		$client = new Client(
			self::KEY,
			'https://app.rewloy.com',
			function ( string $url, array $args ) use ( $order, &$seen, &$issuer ) {
				$seen = array( $order->get_meta( Issuer::META_STATE ), $order->get_meta( Issuer::META_CODE ), (array) $order->get_meta( Issuer::META_TRY ), $this->scheduled );
				throw new \RuntimeException( 'the process dies here' );
			},
			static function ( float $s ): void {}
		);
		$issuer = $this->issuer( $client );
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ) );
		$this->assertSame( Issuer::STATE_UNKNOWN, $seen[0] );
		$this->assertSame( 'SENDING', $seen[1] );
		$this->assertSame( 1, $seen[2]['n'] );
		$this->assertNotSame( '', $seen[2]['sig'] );
		$this->assertCount( 1, $seen[3], 'the next attempt was scheduled before the request went out' );
		$this->assertSame( $order->get_meta( Issuer::META_TRY )['next'], $seen[3][0][0] );
		$this->assertSame( Issuer::STATE_UNKNOWN, $order->get_meta( Issuer::META_STATE ), 'anything thrown leaves "unknown", asked again later' );
		$this->assertSame( 'done', $issuer->run( 55 ), 'not before its time' );
		$this->assertSame( array(), preg_grep( '/^rewloy_wc_claim_/', array_keys( $this->options ) ), 'and the locks are let go' );
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
		$client = new Client(
			self::KEY,
			'https://app.rewloy.com',
			function ( string $url, array $args ) use ( &$second, &$issuer ) {
				$this->requests[] = array( 'url' => $url, 'args' => $args );
				$second           = $issuer->run( 56 ); // The other order of the same address, paid at the same moment.
				return $this->issued();
			},
			static function ( float $s ): void {}
		);
		$issuer = $this->issuer( $client );
		$issuer->run( 55 );
		$this->assertSame( Issuer::STATE_EXISTS, $second );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'another order of this e-mail address', \WC_Order::$db[56]->notes[0]['note'] );
	}

	/** The address is locked before it is searched: an order that finishes between the two cannot be missed. */
	public function test_an_order_that_finished_before_the_lock_is_found_by_the_search(): void {
		$first = $this->order( 55 );
		$this->script( $this->issued() );
		$this->issuer()->run( 55 );
		$this->assertSame( Issuer::STATE_ISSUED, $first->get_meta( Issuer::META_STATE ) );
		$second = $this->order( 56 );
		$this->assertSame( Issuer::STATE_EXISTS, $this->issuer()->run( 56 ) );
		$this->assertStringContainsString( '#55', $second->notes[0]['note'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_address_lock_stops_an_order_whose_sibling_has_not_written_its_state_yet(): void {
		$this->order( 56 );
		$this->options[ Settings::CLAIM_PREFIX . 'email_' . substr( md5( 'salt' . 'ayse@example.com' ), 0, 32 ) ] = (string) time();
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

	/* ------------------------------------------------------------------ an unclear answer is asked again, with the same key */

	public function test_an_unclear_answer_schedules_the_next_attempt_over_hours_and_gives_up_in_the_notes_not_in_silence(): void {
		$order   = $this->order();
		$issuer  = $this->issuer();
		$timeout = $this->failure( 500, 'INTERNAL' );
		$this->script( $timeout, $timeout, $timeout, $timeout, $timeout );
		$before = time();

		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ) );
		$this->assertCount( 1, $this->scheduled );
		$this->assertEqualsWithDelta( $before + 300, $this->scheduled[0][0], 3, 'after the first request: in 5 minutes' );
		$this->assertSame( 55, $this->scheduled[0][1] );
		$this->assertSame( 1, $order->get_meta( Issuer::META_TRY )['n'] );
		$this->assertCount( 1, $order->notes );

		$this->itIsTime( $order );
		$issuer->run( 55 );
		$this->assertEqualsWithDelta( time() + 3600, $this->scheduled[1][0], 3, 'after the second: in an hour' );
		$this->itIsTime( $order );
		$issuer->run( 55 );
		$this->assertEqualsWithDelta( time() + 21_600, $this->scheduled[2][0], 3, 'after the third: in six hours' );
		$this->assertCount( 1, $order->notes, 'the note is written on the first attempt, not on every one' );

		$this->itIsTime( $order );
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ) );
		$this->assertCount( 3, $this->scheduled, 'the fourth is the last: nothing more is scheduled' );
		$this->assertCount( 4, $this->requests );
		$this->assertCount( 2, $order->notes );
		$this->assertStringContainsString( '"Rewloy: try opening the card again"', $order->notes[1]['note'] );
		$this->assertStringContainsString( 'Rewloy panel', $order->notes[1]['note'] );

		$this->itIsTime( $order );
		$this->assertSame( 'done', $issuer->run( 55 ), 'the automatic attempts are used up' );
		$this->assertCount( 4, $this->requests );

		$this->assertSame( Issuer::STATE_UNKNOWN, $order->get_meta( Issuer::META_STATE ) );
		$issuer->retry( $order ); // A person may still ask, inside the six days.
		$this->assertCount( 5, $this->requests );
		$this->assertSame( 5, $order->get_meta( Issuer::META_TRY )['n'] );
		$this->assertCount( 3, $this->scheduled, 'and a person\'s fifth sends no more by itself');
	}

	public function test_without_action_scheduler_the_note_sends_the_person_to_the_order_action_at_once(): void {
		$this->canSchedule = false;
		$order             = $this->order();
		$this->script( $this->failure( 500, 'INTERNAL' ) );
		$this->issuer()->run( 55 );
		$this->assertSame( array(), $this->scheduled );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( '"Rewloy: try opening the card again"', $order->notes[0]['note'] );
		$this->assertStringNotContainsString( 'repeated automatically', $order->notes[0]['note'] );
		$this->canSchedule = true;
	}

	public function test_an_unclear_order_is_not_asked_again_before_its_time(): void {
		$order  = $this->order();
		$issuer = $this->issuer();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued() );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		$issuer->run( 55 );
		foreach ( array( 1, 2, 3 ) as $i ) {
			$this->assertSame( 'done', $issuer->run( 55 ), 'a status change, or any other run, is not the schedule' );
		}
		$this->assertCount( 1, $this->requests );
		$issuer->on_paid( 55, $order ); // The order went from processing to completed.
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_repeat_after_six_days_is_not_sent_because_rewloy_no_longer_remembers_the_key(): void {
		$order  = $this->order();
		$issuer = $this->issuer();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued() );
		$issuer->run( 55 );
		$try       = (array) $order->get_meta( Issuer::META_TRY );
		$try['at'] = time() - Issuer::KEY_WINDOW - 60;
		$order->update_meta_data( Issuer::META_TRY, $try );
		$this->itIsTime( $order );
		$this->assertSame( 'done', $issuer->run( 55 ) );
		$issuer->retry( $order );
		$this->assertCount( 1, $this->requests, 'asking again could open a second card' );
		$this->assertStringContainsString( 'older than six days', end( $order->notes )['note'] );
		$this->assertStringContainsString( 'Rewloy panel', end( $order->notes )['note'] );
	}

	/** @return array<string,array{0:callable(self,\WC_Order):void}> */
	public static function changes(): array {
		return array(
			'the billing e-mail was edited' => array( static fn( self $t, \WC_Order $o ) => $o->set_billing_email( 'baska@example.com' ) ),
			'the shop was connected again'  => array( static fn( self $t, \WC_Order $o ) => $t->reconnect( 'other-link' ) ),
			'the card was changed'          => array( static fn( self $t, \WC_Order $o ) => $t->reconnect( null, '0192ffff-5c6d-7e8f-9a0b-1c2d3e4f5a6b' ) ),
			'another key is in use'         => array( static fn( self $t, \WC_Order $o ) => $t->saveKey( 'rwk_0a1b2c3d4eANOTHERKEYANOTHERKEYANOTHER' ) ),
		);
	}

	public function reconnect( ?string $link = null, ?string $program = null ): void {
		$this->settings->update( array_filter( array( 'link_id' => $link, 'program_id' => $program ) ) );
	}

	public function saveKey( string $key ): void {
		$this->settings->save_api_key( $key );
	}

	/** @dataProvider changes */
	public function test_a_repeat_that_would_not_be_the_same_request_is_not_sent( callable $change ): void {
		$order  = $this->order();
		$issuer = $this->issuer();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued() );
		$issuer->run( 55 );
		$change( $this, $order );
		$this->itIsTime( $order );
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ), 'Rewloy would refuse another body under this key, and another key could open a second card' );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'CHANGED', $order->get_meta( Issuer::META_CODE ) );
		$this->assertStringContainsString( 'cannot be repeated', end( $order->notes )['note'] );
		$this->assertStringContainsString( 'Rewloy panel', end( $order->notes )['note'] );

		$notes = count( $order->notes );
		$this->assertSame( 'done', $issuer->run( 55 ), 'and it stays that way' );
		$issuer->retry( $order );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( $notes + 1, $order->notes, 'the order action explains itself' );
	}

	public function test_rewloy_refusing_the_key_for_another_body_is_unknown_and_final_never_a_refusal_to_retry(): void {
		$order = $this->order();
		$this->script( $this->failure( 422, 'IDEMPOTENCY_KEY_REUSED' ), $this->issued() );
		$issuer = $this->issuer();
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ), 'a card may be open under this key' );
		$this->assertSame( 'CHANGED', $order->get_meta( Issuer::META_CODE ) );
		$this->assertStringContainsString( 'Rewloy panel', $order->notes[0]['note'] );
		$this->itIsTime( $order );
		$issuer->retry( $order );
		$this->assertCount( 1, $this->requests );
	}

	public function test_an_unclear_order_without_a_record_of_its_first_request_is_never_asked_again(): void {
		$order = $this->order();
		$order->update_meta_data( Issuer::META_STATE, Issuer::STATE_UNKNOWN ); // Whatever left it so: no record.
		$issuer = $this->issuer();
		$this->assertSame( 'done', $issuer->run( 55 ) );
		$issuer->retry( $order );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'no record of its first request', $order->notes[0]['note'] );
	}

	public function test_a_failed_order_asks_afresh_with_what_is_now_on_the_order(): void {
		$order = $this->order( 55, 'processing', 'not-an-email' );
		$this->script( $this->issued() );
		$issuer = $this->issuer();
		$this->assertSame( Issuer::STATE_FAILED, $issuer->run( 55 ) );
		$order->set_billing_email( 'duzeltilmis@example.com' );
		$issuer->retry( $order );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'duzeltilmis@example.com', json_decode( $this->requests[0]['args']['body'], true )['email'] );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
	}

	public function test_a_refusal_after_an_unclear_attempt_is_a_refusal(): void {
		$order  = $this->order();
		$issuer = $this->issuer();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->failure( 422, 'EMAIL_BLOCKED' ) );
		$issuer->run( 55 );
		$this->itIsTime( $order );
		$this->assertSame( Issuer::STATE_FAILED, $issuer->run( 55 ) );
		$this->assertArrayNotHasKey( Issuer::META_TRY, $order->meta, 'Rewloy does not bind a key to a refused request' );
	}

	/* ------------------------------------------------------------------ the order, when the card comes after its webhook */

	public function test_when_rewloy_asks_for_the_order_again_it_is_delivered_again_through_its_webhook(): void {
		$hook  = $this->makeWebhook();
		$order = $this->order();
		$this->script( $this->issued( array( 'result' => 'resend' ) ) );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
		$this->assertSame( array( 55 ), $hook->processed );
		$this->assertStringContainsString( 'queued to be sent again', $order->notes[0]['note'] );
		$this->assertStringContainsString( 'ABCD-EFGH-JKLM', $order->notes[0]['note'] );
		$this->assertCount( 1, $this->mails );
	}

	public function test_nothing_is_delivered_again_unless_rewloy_asks(): void {
		$hook = $this->makeWebhook();
		foreach ( array( array( 'result' => 'waiting' ), array( 'result' => 'recorded', 'outcome' => 'credited' ), array() ) as $i => $order_answer ) {
			$order = $this->order( 60 + $i, 'processing', 'x' . $i . '@example.com' );
			$this->script( $this->issued( $order_answer ) );
			$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 60 + $i ) );
			$this->assertStringNotContainsString( 'sent again', $order->notes[0]['note'] );
		}
		$this->assertSame( array(), $hook->processed );
	}

	public function test_a_card_whose_order_cannot_be_delivered_again_is_still_a_card_and_the_note_says_what_to_do(): void {
		foreach ( array( 'the webhook is off' => $this->makeWebhook( 41, null, 'disabled', 5 ), 'the webhook is gone' => null ) as $why => $hook ) {
			\WC_Webhook::$db = null === $hook ? array() : \WC_Webhook::$db;
			$order           = $this->order( 55 );
			$this->script( $this->issued( array( 'result' => 'resend' ) ) );
			$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ), $why );
			$this->assertStringContainsString( 'could not be sent again', $order->notes[0]['note'], $why );
			$this->assertStringContainsString( 'save the order again within seven days', $order->notes[0]['note'], $why );
			$this->assertCount( 1, $this->mails, $why );
			$this->mails = array();
		}
	}

	public function test_an_exception_from_the_webhook_cannot_undo_the_card(): void {
		$order  = $this->order();
		$hook   = $this->makeWebhook();
		$broken = new class() extends \WC_Webhook {
			public function process( mixed $arg ): mixed {
				throw new \RuntimeException( 'WooCommerce\'s queue blew up' );
			}
		};
		$broken->id        = 41;
		\WC_Webhook::$db[41] = $broken;
		$broken->set_delivery_url( 'https://app.rewloy.com/hooks/store/' . self::LINK );
		$broken->set_status( 'active' );
		$this->script( $this->issued( array( 'result' => 'resend' ) ) );
		$this->assertSame( Issuer::STATE_ISSUED, $this->issuer()->run( 55 ) );
		$this->assertSame( Issuer::STATE_ISSUED, $order->get_meta( Issuer::META_STATE ) );
		$this->assertStringContainsString( 'could not be sent again', $order->notes[0]['note'] );
		$this->assertSame( array(), $hook->processed );
	}

	public function test_a_repeat_that_replays_a_resend_delivers_the_order_then(): void {
		$hook   = $this->makeWebhook();
		$order  = $this->order();
		$issuer = $this->issuer();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued( array( 'result' => 'resend' ), array( 'idempotent-replayed' => 'true' ) ) );
		$issuer->run( 55 );
		$this->assertSame( array(), $hook->processed );
		$this->itIsTime( $order );
		$issuer->run( 55 );
		$this->assertSame( array( 55 ), $hook->processed );
		$this->assertStringContainsString( 'an earlier request of this order had opened', $order->notes[1]['note'] );
		$this->assertStringContainsString( 'queued to be sent again', $order->notes[1]['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_action_scheduler_the_next_attempt_is_a_unique_single_action_and_is_cancelled_once_settled(): void {
		$order = $this->order();
		$this->script( $this->failure( 500, 'INTERNAL' ), $this->issued() );
		Functions\expect( 'as_schedule_single_action' )->twice()->with( \Mockery::type( 'int' ), Issuer::HOOK, array( 55 ), Issuer::GROUP, true );
		Functions\expect( 'as_unschedule_action' )->once()->with( Issuer::HOOK, array( 55 ), Issuer::GROUP );
		$issuer = new Issuer( $this->settings, $this->factory() );
		$this->assertSame( Issuer::STATE_UNKNOWN, $issuer->run( 55 ) );
		$this->itIsTime( $order );
		$this->assertSame( Issuer::STATE_ISSUED, $issuer->run( 55 ) );
	}
}
