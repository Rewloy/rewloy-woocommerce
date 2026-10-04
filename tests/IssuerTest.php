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
		$this->assertArrayNotHasKey( Settings::CLAIM_PREFIX . '55', $this->options, 'the claim is dropped once the state is written' );
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

	public function test_a_run_that_overlaps_another_is_stopped_by_the_claim(): void {
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
		$this->assertSame( 'claimed', $inner );
		$this->assertCount( 1, $this->requests );
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

	public function test_on_paid_queues_the_order_once_and_not_twice(): void {
		$order = $this->order();
		Functions\expect( 'as_enqueue_async_action' )->once()->with( Issuer::HOOK, array( 55 ), Issuer::GROUP, true );
		$issuer = $this->issuer();
		$issuer->on_paid( 55, $order );
		$this->assertSame( Issuer::STATE_QUEUED, $order->get_meta( Issuer::META_STATE ) );
		$issuer->on_paid( 55, $order ); // processing, then completed.
		$this->assertSame( array(), $this->requests, 'queuing makes no API call' );
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
		foreach ( array( Issuer::STATE_QUEUED, Issuer::STATE_ISSUED, Issuer::STATE_UNKNOWN, Issuer::STATE_EXISTS ) as $state ) {
			$theorder->update_meta_data( Issuer::META_STATE, $state );
			$this->assertSame( array(), $issuer->order_actions( array() ), $state );
		}
		$theorder->update_meta_data( Issuer::META_STATE, Issuer::STATE_FAILED );
		$this->assertArrayHasKey( 'rewloy_retry_card', $issuer->order_actions( array() ) );
	}

	public function test_retry_runs_again_after_a_refusal_but_never_after_an_unclear_answer(): void {
		$issuer = $this->issuer();
		$order  = $this->order( 55 );
		$this->script( $this->failure( 422, 'EMAIL_BLOCKED' ), $this->issued() );
		$issuer->run( 55 );
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
}
