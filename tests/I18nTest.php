<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The translation files: the template is complete, the Turkish is complete and
 * keeps every placeholder, and the compiled .mo says what the .po says.
 */
final class I18nTest extends TestCase {

	private const DIR = __DIR__ . '/../languages';

	/**
	 * A minimal PO reader: msgid => array of msgstr forms.
	 *
	 * @return array<string,list<string>>
	 */
	private function po( string $file ): array {
		$entries = array();
		$id      = null;
		$field   = '';
		$cur     = array();
		$flush   = static function () use ( &$entries, &$id, &$cur ): void {
			if ( null !== $id ) {
				$entries[ $id ] = array_values( $cur );
			}
		};
		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( 1 === preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
				$flush();
				$id    = $this->unquote( $m[1] );
				$cur   = array();
				$field = 'msgid';
			} elseif ( 1 === preg_match( '/^msgid_plural "(.*)"$/', $line ) ) {
				$field = 'msgid_plural';
			} elseif ( 1 === preg_match( '/^msgstr(?:\[(\d)\])? "(.*)"$/', $line, $m ) ) {
				$idx         = '' === $m[1] ? 0 : (int) $m[1];
				$cur[ $idx ] = $this->unquote( $m[2] );
				$field       = 'msgstr' . $idx;
			} elseif ( 1 === preg_match( '/^"(.*)"$/', $line, $m ) ) {
				if ( 'msgid' === $field && null !== $id ) {
					$id .= $this->unquote( $m[1] );
				} elseif ( str_starts_with( $field, 'msgstr' ) ) {
					$idx          = (int) substr( $field, 6 );
					$cur[ $idx ] .= $this->unquote( $m[1] );
				}
			}
		}
		$flush();
		return $entries;
	}

	private function unquote( string $s ): string {
		return (string) preg_replace_callback( '/\\\\(.)/', static fn( $m ) => array( 'n' => "\n", 't' => "\t", '"' => '"', '\\' => '\\' )[ $m[1] ] ?? $m[1], $s );
	}

	/**
	 * Reads a .mo file: original => translation (plural forms joined by NUL, as in the file).
	 *
	 * @return array<string,string>
	 */
	private function mo( string $file ): array {
		$data = (string) file_get_contents( $file );
		$u    = unpack( 'V', substr( $data, 0, 4 ) );
		$this->assertSame( 0x950412de, $u[1], 'little-endian .mo magic' );
		$h = unpack( 'Vrev/Vn/Vo/Vt', substr( $data, 4, 16 ) );
		$out = array();
		for ( $i = 0; $i < $h['n']; $i++ ) {
			$o = unpack( 'Vlen/Voff', substr( $data, $h['o'] + 8 * $i, 8 ) );
			$t = unpack( 'Vlen/Voff', substr( $data, $h['t'] + 8 * $i, 8 ) );
			$out[ substr( $data, $o['off'], $o['len'] ) ] = substr( $data, $t['off'], $t['len'] );
		}
		return $out;
	}

	/** @return list<string> */
	private function placeholders( string $s ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
		$p = $m[0];
		sort( $p );
		return $p;
	}

	public function test_the_template_and_the_turkish_file_have_the_same_messages(): void {
		$pot = $this->po( self::DIR . '/rewloy-for-woocommerce.pot' );
		$tr  = $this->po( self::DIR . '/rewloy-for-woocommerce-tr_TR.po' );
		$this->assertGreaterThan( 100, count( $pot ) );
		$this->assertSame( array_keys( $pot ), array_keys( $tr ), 'the .po follows the .pot (bin/make-pot, then msgmerge)' );
	}

	public function test_every_turkish_message_is_translated_and_keeps_its_placeholders(): void {
		$tr = $this->po( self::DIR . '/rewloy-for-woocommerce-tr_TR.po' );
		unset( $tr[''] );
		foreach ( $tr as $id => $forms ) {
			$this->assertNotSame( array(), $forms, $id );
			foreach ( $forms as $form ) {
				$this->assertNotSame( '', trim( $form ), "untranslated: $id" );
				$this->assertSame( $this->placeholders( $id ), $this->placeholders( $form ), "placeholders differ in: $id" );
			}
		}
	}

	public function test_the_compiled_catalogue_says_what_the_po_says(): void {
		$tr = $this->po( self::DIR . '/rewloy-for-woocommerce-tr_TR.po' );
		unset( $tr[''] );
		$mo = $this->mo( self::DIR . '/rewloy-for-woocommerce-tr_TR.mo' );
		foreach ( $tr as $id => $forms ) {
			if ( 1 === count( $forms ) ) {
				$this->assertSame( $forms[0], $mo[ $id ] ?? null, "mo out of date for: $id (run bin/make-mo)" );
			}
		}
		$this->assertGreaterThanOrEqual( count( $tr ), count( $mo ) - 1 );
	}

	public function test_the_outcome_labels_are_the_panels_own_words(): void {
		$tr = $this->po( self::DIR . '/rewloy-for-woocommerce-tr_TR.po' );
		$this->assertSame( array( 'Karta işlendi' ), $tr['Added to the card'] );
		$this->assertSame( array( 'Kartı yok' ), $tr['No card'] );
		$this->assertSame( array( 'Eşiğin altında' ), $tr['Below the threshold'] );
		$this->assertSame( array( 'Bağlantı kapalıyken' ), $tr['While the link was off'] );
		$this->assertSame( array( 'Başka para birimi' ), $tr['Other currency'] );
		$this->assertSame( array( 'Sadakat kartım' ), $tr['My loyalty card'] );
		$this->assertSame( array( 'Bağla' ), $tr['Connect'] );
	}

	/** The labels of src/views/shops.ts (DELIVERY) and its "İmza tutmadı" badge, word for word. */
	public function test_the_health_labels_are_the_panels_own_words(): void {
		$tr = $this->po( self::DIR . '/rewloy-for-woocommerce-tr_TR.po' );
		$this->assertSame( array( 'Kayıtlı siparişin tekrarı' ), $tr['Repeat of a recorded order'] );
		$this->assertSame( array( 'Ödenmemiş sipariş (kaydedilmedi)' ), $tr['Unpaid order (not recorded)'] );
		$this->assertSame( array( 'Sipariş numarası yok' ), $tr['No order number'] );
		$this->assertSame( array( 'Okunamadı' ), $tr['Could not be read'] );
		$this->assertSame( array( 'İmza tutmadı' ), $tr['Signature did not match'] );
		$this->assertSame( array( 'Reddedilen son istek' ), $tr['Last refused request'] );
		$this->assertSame( array( 'Mağazadan henüz imzalı bir istek gelmedi.' ), $tr['No signed request has come from the shop yet.'] );
	}

	public function test_the_header_is_turkish_with_its_plural_rule(): void {
		$src = (string) file_get_contents( self::DIR . '/rewloy-for-woocommerce-tr_TR.po' );
		$this->assertStringContainsString( '"Language: tr_TR\n"', $src );
		$this->assertStringContainsString( '"Plural-Forms: nplurals=2; plural=(n > 1);\n"', $src );
		$this->assertStringContainsString( '"Content-Type: text/plain; charset=UTF-8\n"', $src );
	}
}
