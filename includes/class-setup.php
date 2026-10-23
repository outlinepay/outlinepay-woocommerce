<?php
/**
 * Setup after connect: the store takes payments only once every local
 * self-check row (A to I, see Self_Check) has passed in one run.
 *
 * The gate is one-time. The first all-green run saves OPTION_DONE. From then
 * on later failures show on the OutlinePay page but never hide checkout
 * again. Disconnect deletes the flag (Settings::clear_connection), so a
 * reconnect goes through setup again.
 *
 * Upgrade rule: a store connected before 0.5.0 has no flag. Its setup counts
 * as done when the stored self-check result has no failed row. Otherwise it
 * gets the setup screen. Stores from before 0.5.0 never ran the currency row,
 * so the rule looks at the failed count, not at the row list. The first time
 * the rule says done, the flag is saved, so a later failing run cannot undo it.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The setup-done flag.
 */
final class Setup {

	const OPTION_DONE = 'outlinepay_setup_done';

	/**
	 * Whether setup is done: the flag is saved, or the upgrade rule above says so.
	 *
	 * @return bool
	 */
	public static function is_done() {
		if ( (int) get_option( self::OPTION_DONE, 0 ) > 0 ) {
			return true;
		}
		$last = Self_Check::last();
		if ( $last && isset( $last['failed_count'] ) && 0 === (int) $last['failed_count'] ) {
			self::mark_done();
			return true;
		}
		return false;
	}

	/**
	 * True when every local row is in the result and passed.
	 *
	 * @param array $result Self-check result.
	 * @return bool
	 */
	public static function complete( array $result ) {
		$passed = array();
		foreach ( isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array() as $check ) {
			if ( isset( $check['code'] ) && ! empty( $check['ok'] ) ) {
				$passed[ $check['code'] ] = true;
			}
		}
		foreach ( array_keys( Self_Check::LETTERS ) as $code ) {
			if ( ! isset( $passed[ $code ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Saves the flag when a stored result completes setup. Called for every
	 * stored result.
	 *
	 * @param array $result Self-check result.
	 * @return void
	 */
	public static function note( array $result ) {
		if ( (int) get_option( self::OPTION_DONE, 0 ) > 0 || ! self::complete( $result ) ) {
			return;
		}
		self::mark_done();
	}

	/**
	 * Saves the flag: the time setup finished. Autoloaded, since checkout reads it.
	 *
	 * @return void
	 */
	private static function mark_done() {
		update_option( self::OPTION_DONE, time(), true );
		Logger::info( 'Setup complete' );
	}
}
