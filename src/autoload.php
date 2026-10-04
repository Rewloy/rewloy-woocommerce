<?php
/**
 * A tiny PSR-4 loader for this plugin's own classes. The plugin has no Composer
 * dependency at runtime: another plugin's vendor/ must never clash with it.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Rewloy\\WooCommerce\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9]+$/', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
