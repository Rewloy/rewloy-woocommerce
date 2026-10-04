<?php
/**
 * PHPUnit bootstrap: no WordPress, no WooCommerce. WordPress functions come from
 * Brain Monkey (see TestCase), the few WooCommerce classes the plugin touches from
 * tests/Doubles.php.
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'REWLOY_WC_FILE', dirname( __DIR__ ) . '/rewloy-for-woocommerce.php' );

// WordPress constants the plugin uses.
define( 'EP_ROOT', 64 );
define( 'EP_PAGES', 4096 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/autoload.php';
require_once __DIR__ . '/Doubles.php';
require_once __DIR__ . '/FeaturesUtil.php';
