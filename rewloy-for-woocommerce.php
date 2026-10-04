<?php
/**
 * Plugin Name:       Rewloy for WooCommerce
 * Plugin URI:        https://github.com/Rewloy/rewloy-woocommerce
 * Description:       Paid WooCommerce orders fill Rewloy loyalty cards, and customers pay with a Rewloy card at checkout with a one-time code; a small Rewloy panel in WordPress shows the card and its activity and can run a till. / Ödenen siparişler Rewloy sadakat kartlarını doldurur; müşteriler ödeme adımında Rewloy kartlarıyla öder.
 * Version:           0.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 * Author:            Rewloy
 * Author URI:        https://rewloy.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       rewloy-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'REWLOY_WC_FILE', __FILE__ );

require_once __DIR__ . '/src/autoload.php';

\Rewloy\WooCommerce\Plugin::boot();
