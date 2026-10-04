<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;

/** bin/build-zip: the WordPress.org zip holds the plugin's files and nothing else. */
final class PackageTest extends TestCase {

	private string $out = '';

	protected function setUp(): void {
		parent::setUp();
		if ( '' === trim( (string) shell_exec( 'command -v zip' ) ) || '' === trim( (string) shell_exec( 'command -v unzip' ) ) ) {
			$this->markTestSkipped( 'zip and unzip are needed' );
		}
		$this->out = sys_get_temp_dir() . '/rewloy-wc-zip-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		if ( '' !== $this->out && is_dir( $this->out ) ) {
			shell_exec( 'rm -rf ' . escapeshellarg( $this->out ) );
		}
		parent::tearDown();
	}

	/** @return list<string> */
	private function build(): array {
		$root = dirname( __DIR__ );
		$cmd  = escapeshellarg( $root . '/bin/build-zip' ) . ' ' . escapeshellarg( $this->out ) . ' 2>&1';
		exec( $cmd, $lines, $code );
		$this->assertSame( 0, $code, implode( "\n", $lines ) );
		$zip = $this->out . '/rewloy-for-woocommerce-' . \Rewloy\WooCommerce\Plugin::VERSION . '.zip';
		$this->assertFileExists( $zip );
		return array_values( array_filter( explode( "\n", (string) shell_exec( 'unzip -Z1 ' . escapeshellarg( $zip ) ) ) ) );
	}

	public function test_the_zip_has_the_plugin_files_in_a_folder_named_after_the_slug(): void {
		$files = $this->build();
		foreach ( array( 'rewloy-for-woocommerce.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'src/Client.php', 'src/Issuer.php', 'src/autoload.php', 'languages/rewloy-for-woocommerce-tr_TR.mo', 'languages/rewloy-for-woocommerce-tr_TR.po', 'languages/rewloy-for-woocommerce.pot' ) as $f ) {
			$this->assertContains( 'rewloy-for-woocommerce/' . $f, $files );
		}
		foreach ( $files as $f ) {
			$this->assertStringStartsWith( 'rewloy-for-woocommerce/', $f );
		}
	}

	public function test_the_zip_has_nothing_that_is_not_the_plugin(): void {
		$files = $this->build();
		foreach ( $files as $f ) {
			$this->assertDoesNotMatchRegularExpression( '#/(tests|vendor|bin|docs|build|\.github|node_modules)/#', $f, $f );
			$this->assertDoesNotMatchRegularExpression( '#/(composer\.(json|lock)|phpunit\.xml\.dist|phpstan\.neon\.dist|README\.md|SECURITY\.md|\.gitignore|\.env.*)$#', $f, $f );
			$this->assertDoesNotMatchRegularExpression( '#\.(zip|log|bak|swp)$#', $f, $f );
		}
		$this->assertLessThan( 40, count( $files ) );
	}

	/** The version lives in five places; a release that forgets one is caught here. */
	public function test_every_place_the_version_lives_agrees(): void {
		$root = dirname( __DIR__ );
		$v    = \Rewloy\WooCommerce\Plugin::VERSION;
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $v );
		$this->assertStringContainsString( ' * Version:           ' . $v . "\n", (string) file_get_contents( $root . '/rewloy-for-woocommerce.php' ) );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$this->assertStringContainsString( "Stable tag: $v\n", $readme );
		$this->assertStringContainsString( "== Changelog ==\n\n= $v =\n", $readme );
		$this->assertStringContainsString( "== Upgrade Notice ==\n\n= $v =\n", $readme );
		foreach ( array( 'rewloy-for-woocommerce.pot', 'rewloy-for-woocommerce-tr_TR.po' ) as $f ) {
			$this->assertStringContainsString( '"Project-Id-Version: Rewloy for WooCommerce ' . $v . '\n"', (string) file_get_contents( $root . '/languages/' . $f ), $f );
		}
		$this->assertStringContainsString( "## $v (", (string) file_get_contents( $root . '/CHANGELOG.md' ) );
	}
}
