<?php
/**
 * Runs when the plugin is deleted from WordPress: removes the plugin's options
 * and its webhook from this site. It does not call Rewloy.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/autoload.php';

\Rewloy\WooCommerce\Uninstaller::run();
