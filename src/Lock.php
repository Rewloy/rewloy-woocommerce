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

	/**
	 * Takes the lock; false if another process holds it (or the database refused).
	 *
	 * @param string $name The lock's name (an option name).
	 * @param int    $ttl  Seconds after which a lock left behind by a dead process may be taken over;
	 *                     0 for never (a claim that guards a card is kept for good).
	 */
	public function acquire( string $name, int $ttl = 0 ): bool {
		global $wpdb;
		if ( 1 === $this->insert( $name ) ) {
			return true;
		}
		if ( $ttl > 0 ) {
			// Only a row older than the ttl is removed (conditionally, so a fresh winner's row is never taken).
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND CAST(`option_value` AS UNSIGNED) < %d /* rewloy lock */",
					$name,
					time() - $ttl
				)
			);
			return 1 === $this->insert( $name );
		}
		return false;
	}

	/**
	 * @phpstan-impure It changes the database, and a second call answers differently.
	 * @return int|false Rows inserted: 1 for the winner, 0 for everyone else, false on a database error.
	 */
	private function insert( string $name ) {
		global $wpdb;
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* rewloy lock */",
				$name,
				(string) time()
			)
		);
	}

	/** Lets go. Straight to the table: the row never went through the options cache. */
	public function release( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
