<?php
/**
 * What deleting the plugin removes from this site: its options and its webhook.
 * It never calls Rewloy: the link and the cards there stay until the owner
 * deletes them in the Rewloy panel (the settings screen says so).
 *
 * Order data (the invitation state on orders) stays: it is the shop's own
 * record of what was sent, and keeping it is what stops a card being opened
 * twice if the plugin is installed again.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	/** Runs for this site, or for every site of a network. */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::run_site();
				restore_current_blog();
			}
			return;
		}
		self::run_site();
	}

	private static function run_site(): void {
		$saved = get_option( Settings::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$hook  = (int) ( $saved['webhook_id'] ?? 0 );
		$link  = is_string( $saved['link_id'] ?? null ) ? $saved['link_id'] : '';
		if ( $hook > 0 && '' !== $link ) {
			self::delete_webhook( $hook, $link );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Issuer::HOOK );
			// A checkout code's later tries (0.4.0): Rewloy's own backstop (the order webhook) and the hold's expiry
			// finish what they would have done.
			as_unschedule_all_actions( Holds::HOOK );
		}
		global $wpdb;
		$like = $wpdb->esc_like( Settings::CLAIM_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$flash = $wpdb->esc_like( '_transient_rewloy_wc_flash_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $flash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$flash = $wpdb->esc_like( '_transient_timeout_rewloy_wc_flash_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $flash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		delete_option( Settings::OPTION );
		delete_option( Settings::KEY_OPTION );
		delete_option( Plugin::FLUSH_OPTION );
		delete_option( CheckoutSettings::SEEN_OPTION );
	}

	/** Deletes the webhook if it is still there and is the one this plugin made (its delivery address names our link). */
	private static function delete_webhook( int $id, string $link_id ): void {
		if ( function_exists( 'wc_get_webhook' ) ) {
			$hook = wc_get_webhook( $id );
			if ( $hook instanceof \WC_Webhook && str_contains( (string) $hook->get_delivery_url(), '/hooks/store/' . $link_id ) ) {
				$hook->delete( true );
			}
			return;
		}
		// WooCommerce is not loaded while the plugin is deleted: remove the row, the same way.
		global $wpdb;
		$table = $wpdb->prefix . 'wc_webhooks';
		$url   = $wpdb->get_var( $wpdb->prepare( 'SELECT delivery_url FROM %i WHERE webhook_id = %d', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( is_string( $url ) && str_contains( $url, '/hooks/store/' . $link_id ) ) {
			$wpdb->delete( $table, array( 'webhook_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
