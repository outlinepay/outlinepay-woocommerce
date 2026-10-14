<?php
/**
 * A short per-order lock, so a callback, a return and a background sync never
 * apply a payment to the same order at once.
 *
 * Each holder writes its own random token. A lock older than the timeout may
 * be taken over, but only by a conditional update on the exact value that was
 * read, so two waiters cannot both take it. Release deletes the row only while
 * it still holds this holder's token, so a holder that overran its time never
 * removes a newer holder's lock.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Per-order lock over a small key-value store.
 */
final class Order_Lock {

	const TIMEOUT  = 30;
	const ATTEMPTS = 12;
	const WAIT_US  = 250000;

	/**
	 * Store with insert_if_absent, get, replace_if and delete_if. Null uses the options table.
	 *
	 * @var object|null
	 */
	private static $store = null;

	/**
	 * Tokens this request holds, by order id.
	 *
	 * @var array
	 */
	private static $held = array();

	/**
	 * Test seam: replaces the store. Pass null to restore the options table.
	 *
	 * @param object|null $store Store.
	 * @return void
	 */
	public static function set_store( $store ) {
		self::$store = $store;
		self::$held  = array();
	}

	/**
	 * Takes the lock for an order.
	 *
	 * @param int      $order_id Order id.
	 * @param int|null $now      Unix time, for tests.
	 * @param int      $attempts How many tries before giving up.
	 * @return bool
	 */
	public static function acquire( $order_id, $now = null, $attempts = self::ATTEMPTS ) {
		$store = self::store();
		$name  = self::name( $order_id );
		for ( $try = 0; $try < $attempts; $try++ ) {
			$time  = null === $now ? time() : $now;
			$value = $time . '|' . bin2hex( random_bytes( 16 ) );
			if ( $store->insert_if_absent( $name, $value ) ) {
				self::$held[ (int) $order_id ] = $value;
				return true;
			}
			$current = $store->get( $name );
			if ( is_string( $current ) && self::expired( $current, $time ) && $store->replace_if( $name, $current, $value ) ) {
				self::$held[ (int) $order_id ] = $value;
				return true;
			}
			if ( $try + 1 < $attempts ) {
				usleep( self::WAIT_US );
			}
		}
		return false;
	}

	/**
	 * Releases the lock if this request still holds it.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function release( $order_id ) {
		if ( ! isset( self::$held[ (int) $order_id ] ) ) {
			return;
		}
		self::store()->delete_if( self::name( $order_id ), self::$held[ (int) $order_id ] );
		unset( self::$held[ (int) $order_id ] );
	}

	/**
	 * True when a lock value is older than the timeout. An unreadable value counts as expired.
	 *
	 * @param string $value Stored value, "<unix>|<token>".
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function expired( $value, $now ) {
		$since = (int) strtok( $value, '|' );
		return $since <= 0 || $now - $since > self::TIMEOUT;
	}

	/**
	 * Row name for an order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function name( $order_id ) {
		return 'outlinepay_lock_' . (int) $order_id;
	}

	/**
	 * The store in use.
	 *
	 * @return object
	 */
	private static function store() {
		if ( null === self::$store ) {
			self::$store = new Options_Lock_Store();
		}
		return self::$store;
	}
}
