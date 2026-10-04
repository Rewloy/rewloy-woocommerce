<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\Links;
use Rewloy\WooCommerce\Panel;
use Rewloy\WooCommerce\Till;

/** The till (0.3.0): only with Rewloy's `till`, at its one branch; a scanned link's key goes nowhere; words for every answer. */
final class TillTest extends TestCase {

	private const PRESS = '6f1c2e8a-3b4d-4c5e-8f60-718293a4b5c6';

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ) => gmdate( 'd.m.Y H:i', $ts ) );
	}

	private function till(): Till {
		$settings = $this->connected( array( 'currency' => 'TRY' ) );
		$settings->save_api_key( self::PLUGIN_KEY );
		return new Till( new Panel( $settings, $this->factory(), new Links() ) );
	}

	public function test_without_till_nothing_is_read_or_written_and_the_way_to_turn_it_on_is_said(): void {
		$this->script( $this->meAbilities( array( 'view' ) ) );
		$r = $this->till()->sale( self::SERIAL, '100', '', self::PRESS );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'Rewloy panel', $r['message'] );
		$this->assertCount( 1, $this->requests, 'only `me`' );
		$this->assertStringEndsWith( '/v1/me', $this->requests[0]['url'] );
	}

	public function test_a_scanned_link_is_read_by_its_number_alone_at_the_tills_branch_and_its_key_goes_nowhere(): void {
		$this->script( $this->meAbilities( array( 'view', 'till' ) ), $this->tillAnswer(), $this->passAnswer() );
		$r = $this->till()->lookup( 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=SECRETVIEWKEY123' );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( self::SERIAL, $r['card']['serial'], 'the whole number on the till, after the scan' );
		$this->assertSame( self::SERIAL, $r['card']['shown'] );
		$this->assertTrue( $r['card']['allowed'] );
		$this->assertSame( 'https://app.rewloy.com/v1/passes/ABCD-EFGH-JKLM/till?locationId=' . self::BRANCH, $this->requests[1]['url'] );
		$everything = json_encode( array( $this->requests, $this->options, $this->transients, $r ) );
		$this->assertStringNotContainsString( 'SECRETVIEWKEY', (string) $everything );
		$this->assertStringNotContainsString( 'k=', (string) json_encode( $this->requests ) );
		// What the card accepts, in words; `load` is never offered.
		$this->assertSame( array( 'earn-stamps', 'redeem-stamps' ), array_column( $r['card']['actions'], 'action' ) );
		$this->assertTrue( $r['card']['actions'][1]['spends'], 'giving the reward spends: the screen asks to confirm' );
		$this->assertFalse( $r['card']['actions'][0]['spends'] );
		$this->assertSame( 'https://app.rewloy.com/panel/customers?q=ABCD-EFGH-JKLM', $r['card']['customer_url'] );
	}

	public function test_a_gift_card_top_up_is_never_offered_or_sent(): void {
		$this->script(
			$this->meAbilities( array( 'view', 'till' ) ),
			$this->tillAnswer(),
			$this->passAnswer( array( 'type' => 'giftcard', 'actions' => array( array( 'action' => 'spend', 'needs' => array( 'amountMinor' ), 'ready' => true ), array( 'action' => 'load', 'needs' => array( 'amountMinor' ), 'ready' => true ) ) ) )
		);
		$till = $this->till();
		$r    = $till->lookup( self::SERIAL );
		$this->assertSame( array( 'spend' ), array_column( $r['card']['actions'], 'action' ) );
		$this->requests = array();
		$no             = $till->action( self::SERIAL, 'load', array( 'amount' => '100' ), self::PRESS );
		$this->assertFalse( $no['ok'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_wrong_branch_is_said_with_rewloys_notice_and_no_sale_is_offered(): void {
		$notice = array( array( 'tone' => 'block', 'text' => 'Bu kart yalnız Kadıköy şubesinde geçerli', 'note' => '' ) );
		$this->script( $this->meAbilities( array( 'view', 'till' ) ), $this->tillAnswer( false, $notice ), $this->passAnswer() );
		$r = $this->till()->lookup( self::SERIAL );
		$this->assertTrue( $r['ok'] );
		$this->assertFalse( $r['card']['allowed'] );
		$this->assertSame( array( array( 'tone' => 'block', 'text' => 'Bu kart yalnız Kadıköy şubesinde geçerli' ) ), $r['card']['notices'] );
	}

	public function test_a_sale_is_recorded_with_the_press_key_and_the_receipt_number_and_said_in_words(): void {
		$this->script(
			$this->meAbilities( array( 'view', 'till' ) ),
			$this->answer( 200, array( 'data' => array( 'type' => 'stamp', 'applied' => 'stamps', 'credited' => 2, 'balance' => 4, 'duplicate' => false, 'promotion' => array( 'id' => self::LINK, 'name' => 'Çifte damga', 'factor' => 2 ), 'rewardReady' => false, 'rewardsReady' => 0 ) ) ),
			$this->tillAnswer(),
			$this->passAnswer( array( 'balance' => 4, 'progressValue' => '4/8', 'rewardReady' => false ) )
		);
		$r = $this->till()->sale( 'abcdefghjklm', '125,50', "  FIS-1042\n<b>x</b> ", self::PRESS );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( self::PRESS, $this->requests[1]['args']['headers']['Idempotency-Key'] );
		$body = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( array( 'locationId' => self::BRANCH, 'amountMinor' => 12550, 'reference' => 'FIS-1042 x' ), $body );
		$this->assertStringContainsString( '2 stamps added', $r['message'] );
		$this->assertStringContainsString( 'Çifte damga', $r['message'] );
		$this->assertSame( '4/8', $r['card']['progress'], 'the card as it is after the sale' );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function sales(): array {
		return array(
			'points'     => array( array( 'applied' => 'points', 'credited' => 12 ), '12 points added' ),
			'visit'      => array( array( 'applied' => 'visit', 'credited' => 1 ), 'a visit counted' ),
			'cashback'   => array( array( 'applied' => 'cashback', 'credited' => 627 ), '6,27 TRY cashback' ),
			'below'      => array( array( 'applied' => 'none', 'credited' => 0, 'reason' => 'below_minimum' ), 'less than one point' ),
			'counted'    => array( array( 'applied' => 'none', 'credited' => 0, 'reason' => 'visit_already_counted' ), 'already counted' ),
			'full'       => array( array( 'applied' => 'none', 'credited' => 0, 'reason' => 'card_full' ), 'Give the reward first' ),
			'gift card'  => array( array( 'applied' => 'none', 'credited' => 0, 'reason' => 'type_does_not_earn' ), 'earn nothing from a sale' ),
			'a repeat'   => array( array( 'applied' => 'stamps', 'credited' => 1, 'duplicate' => true ), 'nothing was written twice' ),
		);
	}

	/**
	 * @dataProvider sales
	 * @param array<string,mixed> $answer
	 */
	public function test_every_sale_answer_is_said_in_the_panels_words( array $answer, string $want ): void {
		$this->script( $this->meAbilities( array( 'till' ) ), $this->answer( 200, array( 'data' => $answer + array( 'type' => 'stamp', 'balance' => 1, 'duplicate' => false, 'rewardReady' => false, 'rewardsReady' => 0 ) ) ), $this->tillAnswer() );
		$r = $this->till()->sale( self::SERIAL, '10', '', self::PRESS );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( $want, $r['message'] );
	}

	public function test_without_view_the_till_still_sells_and_reads_no_card_state(): void {
		$this->script( $this->meAbilities( array( 'till' ) ), $this->tillAnswer() );
		$r = $this->till()->lookup( self::SERIAL );
		$this->assertTrue( $r['ok'] );
		$this->assertArrayNotHasKey( 'type_label', $r['card'] );
		$this->assertCount( 2, $this->requests, 'no getPass without "Görüntüleme"' );
	}

	/** @return array<string,array{0:int,1:string,2:string}> */
	public static function refusals(): array {
		return array(
			'key reused'      => array( 422, 'IDEMPOTENCY_KEY_REUSED', 'different operation for this press' ),
			'branch gone'     => array( 404, 'LOCATION_NOT_FOUND', 'branch no longer exists' ),
			'wrong branch'    => array( 409, 'WRONG_LOCATION', 'not valid at this branch' ),
			'closed'          => array( 409, 'PASS_INACTIVE', 'closed' ),
			'no balance'      => array( 409, 'INSUFFICIENT_BALANCE', 'balance is not enough' ),
			'no card'         => array( 404, 'PASS_NOT_FOUND', 'No card with this number' ),
			'turned off'      => array( 403, 'FORBIDDEN', 'turned off in the Rewloy panel' ),
		);
	}

	/** @dataProvider refusals */
	public function test_every_refusal_is_said_in_the_panels_words_never_rewloys_own_text( int $status, string $code, string $want ): void {
		$this->script( $this->meAbilities( array( 'view', 'till' ) ), $this->failure( $status, $code ) );
		$r = $this->till()->action( self::SERIAL, 'spend', array( 'amount' => '10' ), self::PRESS );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( $want, $r['message'] );
		$this->assertStringNotContainsString( 'Türkçe API iletisi', $r['message'] );
		$this->assertArrayNotHasKey( 'retry', $r, 'a clear refusal is not a retry' );
	}

	public function test_no_clear_answer_offers_the_same_press_again(): void {
		$this->script( $this->meAbilities( array( 'till' ) ), new \WP_Error( 'x', 'down' ), new \WP_Error( 'x', 'down' ), new \WP_Error( 'x', 'down' ) );
		$r = $this->till()->sale( self::SERIAL, '10', '', self::PRESS );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['retry'] ?? false );
		$this->assertStringContainsString( 'Try again', $r['message'] );
	}

	public function test_input_that_is_not_right_is_refused_before_anything_is_sent(): void {
		$this->script( $this->meAbilities( array( 'till' ) ) );
		$till = $this->till();
		$this->assertFalse( $till->sale( self::SERIAL, '12.345', '', self::PRESS )['ok'] );
		$this->assertFalse( $till->sale( self::SERIAL, '100001', '', self::PRESS )['ok'] );
		$this->assertFalse( $till->sale( self::SERIAL, '10', '', 'fis-1042' )['ok'], 'the key is the press\'s UUID, never a receipt number' );
		$this->assertFalse( $till->sale( 'nope', '10', '', self::PRESS )['ok'] );
		$this->assertFalse( $till->action( self::SERIAL, 'spend', array( 'amount' => '0' ), self::PRESS )['ok'] );
		$this->assertFalse( $till->action( self::SERIAL, 'spend-points', array( 'points' => 'x' ), self::PRESS )['ok'] );
		$this->assertFalse( $till->action( self::SERIAL, 'adjust', array(), self::PRESS )['ok'] );
		$this->assertFalse( $till->lookup( 'https://evil.example/p/ABCD-EFGH-JKLM' )['ok'] );
		$this->assertCount( 1, $this->requests, 'only `me`' );
	}

	public function test_an_action_sends_what_it_needs(): void {
		$this->script( $this->meAbilities( array( 'till' ) ), $this->answer( 200, array( 'data' => array( 'balance' => 10, 'duplicate' => false ) ) ), $this->tillAnswer() );
		$r = $this->till()->action( self::SERIAL, 'spend-points', array( 'points' => '50' ), self::PRESS );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'action' => 'spend-points', 'locationId' => self::BRANCH, 'points' => 50 ), json_decode( $this->requests[1]['args']['body'], true ) );
		$this->assertStringContainsString( 'Spend points', $r['message'] );
	}
}
