<?php
/**
 * Who may use the till (0.3.0, D45): its own capability, `rewloy_wc_till`, apart from `manage_woocommerce`.
 *
 * The till spends customers' rewards and balances, so it is not every shop manager's by default: administrators
 * (`manage_options`) hold it; shop managers too only when an administrator ticks "Mağaza yöneticileri de kasayı
 * kullanabilir" under Rewloy › Ayarlar (off by default). A role editor can give the capability to any role directly,
 * and the `rewloy_wc_till` filter can decide it for a user: `add_filter( 'rewloy_wc_till', fn( $can, $user ) => … )`.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Capability {

	public const TILL = 'rewloy_wc_till';

	public function __construct( private Settings $settings ) {
	}

	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 4 );
	}

	/**
	 * Adds `rewloy_wc_till` to a user's capabilities when it is asked for and the user may use the till.
	 *
	 * @param mixed $allcaps The user's capabilities, name => granted.
	 * @param mixed $caps    The primitive capabilities being checked.
	 * @param mixed $args    The check's arguments.
	 * @param mixed $user    The user.
	 * @return mixed
	 */
	public function grant( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( ! is_array( $allcaps ) || ! is_array( $caps ) || ! in_array( self::TILL, $caps, true ) || ! empty( $allcaps[ self::TILL ] ) ) {
			return $allcaps;
		}
		$can = ! empty( $allcaps['manage_options'] )
			|| ( $this->settings->get()['till_shop_managers'] && ! empty( $allcaps['manage_woocommerce'] ) );
		if ( (bool) apply_filters( 'rewloy_wc_till', $can, $user ) ) {
			$allcaps[ self::TILL ] = true;
		}
		return $allcaps;
	}
}
