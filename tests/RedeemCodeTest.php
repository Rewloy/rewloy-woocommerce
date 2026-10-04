<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\RedeemCode;

/** The checkout code's shape and check character, as Rewloy makes them (ADR 179). */
final class RedeemCodeTest extends TestCase {

	/** A code with a right check character, made the way Rewloy makes one. */
	public static function code( string $seven = 'TQYULAE' ): string {
		$full = $seven . RedeemCode::check_char( $seven );
		return 'RW-' . substr( $full, 0, 4 ) . '-' . substr( $full, 4 );
	}

	public function test_codes_rewloy_minted_on_the_real_store_pass_the_check(): void {
		// Codes the local Rewloy minted during the real-store run (spent there).
		foreach ( array( 'RW-TQYU-LAEX', 'RW-XV5C-RHBE', 'RW-TY7K-V22Q' ) as $minted ) {
			$this->assertSame( str_replace( '-', '', $minted ), RedeemCode::normalize( $minted ), $minted );
		}
	}

	public function test_case_spaces_and_dashes_do_not_matter(): void {
		$this->assertSame( 'RWXV5CRHBE', RedeemCode::normalize( 'rw xv5c rhbe' ) );
		$this->assertSame( 'RWXV5CRHBE', RedeemCode::normalize( " rw-xv5c-rhbe\t" ) );
	}

	public function test_any_one_wrong_character_is_caught_without_a_call(): void {
		$good = RedeemCode::normalize( self::code() );
		for ( $i = 2; $i < 10; $i++ ) {
			foreach ( str_split( RedeemCode::ALPHABET ) as $c ) {
				if ( $c === $good[ $i ] ) {
					continue;
				}
				$typo = substr_replace( $good, $c, $i, 1 );
				$this->assertSame( '', RedeemCode::normalize( $typo ), $typo );
				$this->assertTrue( RedeemCode::looks_like( $typo ) );
			}
		}
	}

	public function test_what_is_not_a_rewloy_code_is_not_taken_for_one(): void {
		foreach ( array( 'INDIRIM10', 'RW-123', 'RWABCDEFGHIJ', 'XW-TQYU-LAEX', '', 'RW-TQYU-LAE' ) as $other ) {
			$this->assertFalse( RedeemCode::looks_like( $other ), $other );
			$this->assertSame( '', RedeemCode::normalize( $other ) );
		}
		// 0, O, 1 and I are not in the alphabet: such a code looks like one but is never valid.
		$this->assertTrue( RedeemCode::looks_like( 'RW-0O1I-AAAA' ) );
		$this->assertSame( '', RedeemCode::normalize( 'RW-0O1I-AAAA' ) );
	}

	public function test_display_last4_and_a_ref_that_is_not_the_code(): void {
		$this->assertSame( 'RW-XV5C-RHBE', RedeemCode::display( 'RWXV5CRHBE' ) );
		$this->assertSame( 'RHBE', RedeemCode::last4( 'RWXV5CRHBE' ) );
		$ref = RedeemCode::ref( 'RWXV5CRHBE' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $ref );
		$this->assertStringNotContainsString( 'XV5C', strtoupper( $ref ) );
		$this->assertNotSame( $ref, RedeemCode::ref( 'RWTQYULAEX' ) );
	}
}
