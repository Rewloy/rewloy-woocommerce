<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Rewloy\WooCommerce\Links;
use Rewloy\WooCommerce\Serial;

/** Card numbers as typed or scanned, the masks, and the Rewloy panel's exact pages (0.3.0). */
final class SerialTest extends TestCase {

	/** @return array<string,array{0:string,1:string}> */
	public static function inputs(): array {
		return array(
			'as Rewloy writes it'          => array( 'ABCD-EFGH-JKLM', 'ABCD-EFGH-JKLM' ),
			'small letters, no dashes'     => array( 'abcdefghjklm', 'ABCD-EFGH-JKLM' ),
			'spaces'                       => array( ' abcd efgh jklm ', 'ABCD-EFGH-JKLM' ),
			'the QR link with its key'     => array( 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=SECRETVIEWKEY123', 'ABCD-EFGH-JKLM' ),
			'a subdomain, a trailing /'    => array( 'https://app.rewloy.com/p/abcd-efgh-jklm/?k=x', 'ABCD-EFGH-JKLM' ),
			'a scanner in a TR layout'     => array( 'httpsŞ..rewloy.com.p.ABCD*EFGH*JKLM,k=SECRETVIEWKEY123', 'ABCD-EFGH-JKLM' ),
			'another host'                 => array( 'https://evil.example/p/ABCD-EFGH-JKLM?k=x', '' ),
			'a look-alike host'            => array( 'https://rewloy.com.evil.example/p/ABCD-EFGH-JKLM', '' ),
			'plain http'                   => array( 'http://rewloy.com/p/ABCD-EFGH-JKLM', '' ),
			'a port'                       => array( 'https://rewloy.com:8443/p/ABCD-EFGH-JKLM', '' ),
			'credentials in the link'      => array( 'https://a:b@rewloy.com/p/ABCD-EFGH-JKLM', '' ),
			'another path'                 => array( 'https://rewloy.com/join/ABCD-EFGH-JKLM', '' ),
			'a backslash'                  => array( 'https://rewloy.com\\@evil.example/p/ABCD-EFGH-JKLM', '' ),
			'too short'                    => array( 'ABCD-EFGH', '' ),
			'signs'                        => array( 'ABCD-EFGH-JKL!', '' ),
			'nothing'                      => array( '', '' ),
		);
	}

	/** @dataProvider inputs */
	public function test_a_typed_or_scanned_card_gives_its_number_and_nothing_else( string $raw, string $want ): void {
		$this->assertSame( $want, Serial::from_input( $raw ) );
	}

	public function test_a_scanned_links_private_key_is_not_in_what_comes_out(): void {
		$out = Serial::from_input( 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=SECRETVIEWKEY123' );
		$this->assertStringNotContainsString( 'SECRET', $out );
		$this->assertStringNotContainsString( 'k=', $out );
	}

	public function test_the_watching_screens_show_the_last_four_only(): void {
		$this->assertSame( '••••-••••-JKLM', Serial::mask( 'ABCD-EFGH-JKLM' ) );
		$this->assertSame( '••••-••••-JKLM', Serial::mask( 'abcdefghjklm' ) );
		$this->assertSame( '', Serial::mask( 'not a card' ) );
	}

	public function test_the_links_go_to_the_exact_pages_of_the_rewloy_panel(): void {
		$l = new Links( 'https://app.rewloy.com/' );
		$this->assertSame( 'https://app.rewloy.com/panel/programs/' . self::PROGRAM, $l->program( self::PROGRAM ) );
		$this->assertSame( 'https://app.rewloy.com/panel/programs/new', $l->new_program() );
		$this->assertSame( 'https://app.rewloy.com/panel/settings/shops/' . self::LINK, $l->shop( self::LINK ) );
		$this->assertSame( 'https://app.rewloy.com/panel/settings/shops/' . self::LINK . '#wordpress-yetkileri', $l->shop( self::LINK, true ) );
		$this->assertSame( 'https://app.rewloy.com/panel/customers?program=' . self::PROGRAM, $l->customers( self::PROGRAM ) );
		$this->assertSame( 'https://app.rewloy.com/panel/customers?q=ABCD-EFGH-JKLM', $l->customer_of( 'abcdefghjklm' ) );
		$this->assertSame( 'https://app.rewloy.com/panel/activity?serial=ABCD-EFGH-JKLM', $l->activity( '', self::SERIAL ) );
		$this->assertSame( 'https://app.rewloy.com/panel/activity?programId=' . self::PROGRAM, $l->activity( self::PROGRAM ) );
		$this->assertSame( 'https://app.rewloy.com/panel/campaigns', $l->campaigns() );
		$this->assertSame( 'https://app.rewloy.com/panel/analytics', $l->analytics() );
		$this->assertSame( 'https://app.rewloy.com/scan', $l->scan() );
		$this->assertSame( 'https://app.rewloy.com/panel/team', $l->team() );
		$this->assertSame( 'https://app.rewloy.com/panel/developers', $l->developers() );
		$this->assertSame( 'https://app.rewloy.com/panel/settings/locations', $l->locations() );
		$this->assertSame( 'https://app.rewloy.com/panel/plan', $l->plan() );
	}

	public function test_a_link_never_carries_anything_but_an_id_or_a_card_number(): void {
		$l = new Links();
		$this->assertSame( 'https://app.rewloy.com/panel/programs', $l->program( '../../evil' ) );
		$this->assertSame( 'https://app.rewloy.com/panel/settings/shops', $l->shop( 'x"><script>' ) );
		$this->assertSame( 'https://app.rewloy.com/panel/customers', $l->customer_of( 'https://rewloy.com/p/ABCD-EFGH-JKLM?k=SECRET' ) );
		$this->assertSame( 'https://app.rewloy.com/panel/customers', $l->customers( 'nope' ) );
	}
}
