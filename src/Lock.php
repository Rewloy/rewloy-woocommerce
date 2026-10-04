<?php
/**
 * A lock that only one process can take: an options row added with INSERT IGNORE.
 *
 * WordPress core takes its own upgrade lock the same way (WP_Upgrader::create_lock).
 * add_option() is not used because its "ON DUPLICATE KEY UPDATE" can report success
 * to both of two racing callers when the values differ, and because it reads the
 * object cache first. INSERT IGNORE affects one row for the process that inserted
 * and none for every other, whatever the connection's flags.
 *
 * @package Rewloy_For_WooCommerce
 */

declare(strict_types=1);

namespace Rewloy\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Lock {

	/** Takes the lock; false if another process holds it (or the database refused). */
	public function acquire( string $name ): bool {
		global $wpdb;
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* rewloy lock */",
				$name,
				(string) time()
			)
		);
		return 1 === $result;
	}

	/** Lets go. Straight to the table: the row never went through the options cache. */
	public function release( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
