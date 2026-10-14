<?php
/**
 * Lock rows in the options table, written and read directly.
 *
 * Direct queries on purpose: add_option() upserts and the option caches would
 * hide another request's row, which defeats a lock. The rows are never
 * autoloaded and never cached.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic operations on single option rows.
 */
final class Options_Lock_Store {

	/**
	 * Inserts the row unless it exists.
	 *
	 * @param string $name  Option name.
	 * @param string $value Value.
	 * @return bool True when this call created it.
	 */
	public function insert_if_absent( $name, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; caching would defeat it.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
	}

	/**
	 * Reads the row.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public function get( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the lock row itself, never a cached copy.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Replaces the value only while it still equals $old.
	 *
	 * @param string $name Option name.
	 * @param string $old   Value that was read.
	 * @param string $fresh New value.
	 * @return bool True when this call replaced it.
	 */
	public function replace_if( $name, $old, $fresh ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional takeover of an expired lock row.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $fresh, $name, $old ) );
	}

	/**
	 * Deletes the row only while it holds $value.
	 *
	 * @param string $name  Option name.
	 * @param string $value Value this holder wrote.
	 * @return bool
	 */
	public function delete_if( $name, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- releases only this holder's lock row.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
	}
}
