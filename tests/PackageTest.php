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
		$zip = $this->out . '/rewloy-for-woocommerce-0.2.0.zip';
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
}
