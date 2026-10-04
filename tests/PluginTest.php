<?php

declare(strict_types=1);

namespace Rewloy\WooCommerce\Tests;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey\Functions;
use Rewloy\WooCommerce\Plugin;
use Rewloy\WooCommerce\Settings;

final class PluginTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	/** @return list<string> every PHP file the zip ships */
	private function shipped(): array {
		$files = array( self::ROOT . '/rewloy-for-woocommerce.php', self::ROOT . '/uninstall.php' );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT . '/src' ) ) as $f ) {
			if ( $f instanceof \SplFileInfo && 'php' === $f->getExtension() ) {
				$files[] = $f->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	private function header( string $name ): string {
		$src = (string) file_get_contents( self::ROOT . '/rewloy-for-woocommerce.php' );
		return 1 === preg_match( '/^ \* ' . preg_quote( $name, '/' ) . ':\s*(.+)$/m', $src, $m ) ? trim( $m[1] ) : '';
	}

	public function test_the_version_is_the_same_everywhere(): void {
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );
		$this->assertSame( Plugin::VERSION, $this->header( 'Version' ) );
		$this->assertMatchesRegularExpression( '/^Stable tag: ' . preg_quote( Plugin::VERSION, '/' ) . '$/m', $readme );
		$this->assertStringContainsString( '= ' . Plugin::VERSION . ' =', $readme );
		$this->assertStringContainsString( 'Rewloy for WooCommerce ' . Plugin::VERSION, (string) file_get_contents( self::ROOT . '/languages/rewloy-for-woocommerce.pot' ) );
	}

	public function test_the_requirements_are_declared(): void {
		$this->assertSame( '6.4', $this->header( 'Requires at least' ) );
		$this->assertSame( '8.1', $this->header( 'Requires PHP' ) );
		$this->assertSame( '8.0', $this->header( 'WC requires at least' ) );
		$this->assertSame( 'woocommerce', $this->header( 'Requires Plugins' ) );
		$this->assertSame( 'rewloy-for-woocommerce', $this->header( 'Text Domain' ) );
		$this->assertSame( 'MIT', $this->header( 'License' ) );
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );
		$this->assertStringContainsString( "Requires PHP: 8.1\n", $readme );
		$this->assertStringContainsString( "WC requires at least: 8.0\n", $readme );
		$this->assertStringContainsString( "Requires at least: 6.4\n", $readme );
		$this->assertSame( '>=8.1', json_decode( (string) file_get_contents( self::ROOT . '/composer.json' ), true )['require']['php'] );
	}

	public function test_no_file_runs_when_opened_directly(): void {
		foreach ( $this->shipped() as $file ) {
			$src = (string) file_get_contents( $file );
			if ( str_ends_with( $file, '/uninstall.php' ) ) {
				$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $src, $file );
				continue;
			}
			$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $src, $file );
		}
	}

	public function test_nothing_but_the_client_talks_to_the_network_and_no_risky_functions_exist(): void {
		foreach ( $this->shipped() as $file ) {
			$src = (string) file_get_contents( $file );
			if ( ! str_ends_with( $file, '/src/Client.php' ) ) {
				$this->assertDoesNotMatchRegularExpression( '/wp_remote_|curl_|fsockopen|file_get_contents\s*\(\s*[\'"]http|stream_context/', $src, $file );
			}
			$this->assertDoesNotMatchRegularExpression( '/\b(eval|exec|shell_exec|system|passthru|proc_open|popen|unserialize|base64_decode|create_function|assert)\s*\(/', $src, $file );
		}
	}

	public function test_the_only_hosts_in_the_code_are_rewloys(): void {
		$allowed = array( 'rewloy.com', 'app.rewloy.com', 'localhost' );
		foreach ( $this->shipped() as $file ) {
			preg_match_all( '#https?://([a-z0-9.\-]+)#i', (string) file_get_contents( $file ), $m );
			foreach ( $m[1] as $host ) {
				$host = strtolower( $host );
				if ( str_ends_with( $file, 'rewloy-for-woocommerce.php' ) && in_array( $host, array( 'github.com', 'opensource.org' ), true ) ) {
					continue; // The plugin header's own links.
				}
				$this->assertContains( $host, $allowed, "$file mentions $host" );
			}
		}
	}

	public function test_orders_are_touched_only_through_the_order_api_so_hpos_works(): void {
		foreach ( $this->shipped() as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/get_post_meta|update_post_meta|delete_post_meta|add_post_meta|get_post\s*\(|wp_posts|->posts\b|\bshop_order\b|WP_Query|get_posts\s*\(/', (string) file_get_contents( $file ), $file );
		}
	}

	public function test_every_visible_string_uses_the_plugins_text_domain(): void {
		$seen = 0;
		foreach ( $this->shipped() as $file ) {
			$src = (string) file_get_contents( $file );
			preg_match_all( "/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|_n)\(\s*(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")(?:\s*,\s*(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"))?\s*,\s*(?:\\\$[a-z_]+\s*,\s*)?'([^']*)'\s*\)/s", $src, $m );
			foreach ( $m[1] as $domain ) {
				$this->assertSame( 'rewloy-for-woocommerce', $domain, $file );
				++$seen;
			}
			// Every call is seen: as many matches as calls.
			$calls = preg_match_all( '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|_n)\(/', $src );
			$this->assertSame( (int) $calls, count( $m[1] ), "$file: a translation call the check could not read" );
		}
		$this->assertGreaterThan( 100, $seen );
	}

	public function test_boot_hooks_the_plugin(): void {
		Plugin::boot();
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( Plugin::class, 'declare_compatibility' ) ) );
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'init' ) ) );
		$this->assertNotFalse( has_action( 'init', array( Plugin::class, 'load_textdomain' ) ) );
	}

	public function test_hpos_and_block_checkout_compatibility_are_declared(): void {
		FeaturesUtil::$declared = array();
		Plugin::declare_compatibility();
		$this->assertSame(
			array(
				array( 'custom_order_tables', REWLOY_WC_FILE, true ),
				array( 'cart_checkout_blocks', REWLOY_WC_FILE, true ),
			),
			FeaturesUtil::$declared
		);
	}

	public function test_init_registers_the_components(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'rewloy-for-woocommerce/rewloy-for-woocommerce.php' );
		Plugin::init();
		$this->assertNotFalse( has_filter( 'woocommerce_webhook_payload' ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_processing' ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_completed' ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_account_menu_items' ) );
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_post_rewloy_wc_connect' ) );
		$this->assertNotFalse( has_action( 'admin_post_rewloy_wc_connect_code' ), 'the way in' );
		$this->assertNotFalse( has_action( \Rewloy\WooCommerce\Issuer::HOOK ), 'the scheduled run of an order\'s card, which an unclear answer is repeated by' );
		$this->assertNotFalse( has_action( 'woocommerce_order_action_rewloy_retry_card' ) );
		$this->assertFalse( has_action( 'admin_notices' ), 'WooCommerce is present, so no warning' );
		// 0.4.0: a Rewloy card at the checkout, in both checkouts and every order status that moves value.
		foreach ( array( 'woocommerce_get_shop_coupon_data', 'woocommerce_coupon_is_valid', 'woocommerce_cart_totals_get_fees_from_cart_taxes' ) as $filter ) {
			$this->assertNotFalse( has_filter( $filter ), $filter );
		}
		foreach ( array( 'woocommerce_cart_calculate_fees', 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed', 'woocommerce_order_status_cancelled', 'woocommerce_order_status_failed', 'woocommerce_order_status_refunded', 'woocommerce_order_partially_refunded', 'woocommerce_order_item_fee_after_calculate_taxes', \Rewloy\WooCommerce\Holds::HOOK, 'admin_post_rewloy_wc_save_checkout' ) as $action ) {
			$this->assertNotFalse( has_action( $action ), $action );
		}
	}

	/** The issuer delivers an order again through the shop's webhook when Rewloy asks: it must be given that webhook. */
	public function test_the_issuer_is_given_the_webhook_it_redelivers_through(): void {
		$src = (string) file_get_contents( self::ROOT . '/src/Plugin.php' );
		$this->assertMatchesRegularExpression( '/new Issuer\( \$settings, \$factory, null, \$webhooks \)/', $src );
	}

	public function test_the_front_end_has_no_admin_screen(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Plugin::init();
		$this->assertFalse( has_action( 'admin_menu' ) );
		$this->assertFalse( has_action( 'admin_post_rewloy_wc_connect' ) );
	}

	public function test_the_warning_without_woocommerce_is_for_people_who_can_activate_plugins(): void {
		ob_start();
		Plugin::missing_woocommerce_notice();
		$this->assertStringContainsString( 'needs WooCommerce', (string) ob_get_clean() );
		$this->can = false;
		ob_start();
		Plugin::missing_woocommerce_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_client_factory_returns_null_without_a_usable_key(): void {
		$settings = new Settings();
		$factory  = Plugin::client_factory( $settings );
		$this->assertNull( $factory() );
		$this->assertNull( $factory( 'rws_staffsession123456' ) );
		$this->assertNotNull( $factory( self::KEY ) );
		$settings->save_api_key( self::KEY );
		$this->assertNotNull( $factory() );
	}

	public function test_the_api_base_is_rewloys_by_default(): void {
		$this->assertSame( 'https://app.rewloy.com', Plugin::api_base() );
	}
}
