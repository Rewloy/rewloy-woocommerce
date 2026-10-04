<?php
/**
 * Wires the plugin up: components, hooks, HPOS declaration.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const VERSION = '0.1.0';
	/** Set when the My Account tab is turned on or off, so the rewrite rules are flushed once. */
	public const FLUSH_OPTION = 'rewloy_wc_flush_rewrite';
	public const TEXT_DOMAIN  = 'rewloy-for-woocommerce';

	/** Hooks the plugin up. Called once from the main plugin file. */
	public static function boot(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( self::class, 'init' ), 20 );
	}

	/** HPOS (custom order tables): the plugin reads and writes orders only through the WC_Order API. */
	public static function declare_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', REWLOY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', REWLOY_WC_FILE, true );
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( REWLOY_WC_FILE ) ) . '/languages' );
	}

	/** Builds the components once WooCommerce is there. Without it the plugin only says so. */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_woocommerce_notice' ) );
			return;
		}
		$settings = new Settings();
		$factory  = self::client_factory( $settings );
		$webhooks = new Webhooks( $settings );
		$webhooks->register();
		$issuer = new Issuer( $settings, $factory );
		( new Checkout( $settings, $issuer ) )->register();
		( new Account( $settings ) )->register();
		if ( is_admin() ) {
			( new Admin( $settings, new Connection( $settings, $webhooks, $factory ) ) )->register();
		}
	}

	public static function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Rewloy for WooCommerce needs WooCommerce to be installed and active.', 'rewloy-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * A function that makes a Client for the key given, or for the saved one; null when there is no usable key.
	 *
	 * @return callable(string=): ?Client
	 */
	public static function client_factory( Settings $settings ): callable {
		return static function ( #[\SensitiveParameter] string $key = '' ) use ( $settings ): ?Client {
			$key = '' !== $key ? $key : $settings->api_key();
			if ( '' === $key ) {
				return null;
			}
			try {
				return new Client( $key, self::api_base() );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
				return null;
			}
		};
	}

	/** The API's origin: Rewloy's, unless wp-config.php says otherwise (a staging API, a test server). */
	public static function api_base(): string {
		if ( defined( 'REWLOY_API_URL' ) && is_string( constant( 'REWLOY_API_URL' ) ) && '' !== constant( 'REWLOY_API_URL' ) ) {
			return (string) constant( 'REWLOY_API_URL' );
		}
		return Client::DEFAULT_BASE_URL;
	}
}
