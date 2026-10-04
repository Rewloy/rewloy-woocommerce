<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Rewloy\WooCommerce\ApiError;
use Rewloy\WooCommerce\Messages;

/**
 * With the Turkish catalogue loaded the screens and notes read in Turkish.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TurkishRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$mo   = (string) file_get_contents( dirname( __DIR__ ) . '/languages/rewloy-for-woocommerce-tr_TR.mo' );
		$h    = unpack( 'Vrev/Vn/Vo/Vt', substr( $mo, 4, 16 ) );
		$cat  = array();
		for ( $i = 0; $i < $h['n']; $i++ ) {
			$o = unpack( 'Vlen/Voff', substr( $mo, $h['o'] + 8 * $i, 8 ) );
			$t = unpack( 'Vlen/Voff', substr( $mo, $h['t'] + 8 * $i, 8 ) );
			$cat[ substr( $mo, $o['off'], $o['len'] ) ] = substr( $mo, $t['off'], $t['len'] );
		}
		Functions\when( '__' )->alias( static fn( $s ) => $cat[ $s ] ?? $s );
		Functions\when( '_n' )->alias(
			static function ( $one, $many, $n ) use ( $cat ) {
				$forms = explode( "\0", $cat[ $one . "\0" . $many ] ?? '' );
				return $forms[ $n > 1 ? 1 : 0 ] ?? ( 1 === $n ? $one : $many );
			}
		);
	}

	public function test_outcomes_read_in_the_panels_turkish(): void {
		$this->assertSame( 'Karta işlendi', Messages::outcome_label( 'credited' ) );
		$this->assertSame( 'Kartı yok', Messages::outcome_label( 'unmatched' ) );
		$this->assertSame( 'Siparişteki e-postayla bu kartta müşteri yok; kart açılmadı.', Messages::outcome_why( 'unmatched' ) );
	}

	public function test_rules_and_errors_read_in_turkish(): void {
		$this->assertSame( 'Sipariş tutarında her 100,00 TRY için 2 damga (bir siparişte en fazla 50 damga).', Messages::rule_text( 'stamp', 'amount', 10000, 2, 'TRY' ) );
		$this->assertSame( 'Her ödenmiş sipariş için 1 puan.', Messages::rule_text( 'points', 'order', 0, 1, 'TRY' ) );
		$this->assertSame( 'Her ödenmiş sipariş bir ziyaret sayılır.', Messages::rule_text( 'vip', 'order', 0, 1, 'TRY' ) );
		$this->assertSame(
			'Mağaza bağlantısı bu işletmenin Rewloy planında yok. (istek: req-1)',
			Messages::for_error( new ApiError( 'x', 403, 'PLAN_FEATURE_MISSING', 'req-1' ) )
		);
	}

	public function test_the_health_labels_and_the_connect_errors_read_in_turkish(): void {
		$this->assertSame( 'Karta işlendi', Messages::delivery_label( 'credited' ) );
		$this->assertSame( 'Kayıtlı siparişin tekrarı', Messages::delivery_label( 'duplicate' ) );
		$this->assertSame( 'Okunamadı', Messages::delivery_label( 'bad_body' ) );
		$this->assertSame( 'İmza tutmadı', Messages::refusal_label( 'bad_signature' ) );
		$this->assertStringStartsWith( 'Rewloy bu kodu kabul etmedi:', Messages::for_error( new ApiError( 'x', 404, 'CONNECT_TOKEN_INVALID', '' ) ) );
	}
}
