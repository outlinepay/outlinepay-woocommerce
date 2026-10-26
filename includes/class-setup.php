<?php
/**
 * Setup after connect: the store takes payments only once every blocking
 * self-check row has passed in one run (see Self_Check::severity): the
 * nine local rows A to I, each present and passed.
 *
 * The gate is one-time. The first all-green run saves OPTION_DONE. From then
 * on later failures show on the OutlinePay page but never hide checkout
 * again. Disconnect deletes the flag (Settings::clear_connection), so a
 * reconnect goes through setup again.
 *
 * Rows OutlinePay adds (such as callback_reachable) are advisory unless
 * OutlinePay marks one blocking. An advisory row shows on the page but
 * never blocks setup.
 *
 * Upgrade rule (see upgrade()): a store that was already connected when it
 * updated from a version before 0.5.0 gets the flag outright, on the first
 * request after the update. Its checkout was live, so it never goes through
 * setup. A store that connects on 0.5.0 or later always does.
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
	 * Whether setup is done: the flag is saved.
	 *
	 * @return bool
	 */
	public static function is_done() {
		return (int) get_option( self::OPTION_DONE, 0 ) > 0;
	}

	/**
	 * Upgrade rule, on every request from Plugin::boot, before checkout asks
	 * a gateway: saves the flag when the store holds a connection and the
	 * last version it reported is before 0.5.0, or no version is saved.
	 *
	 * finish_connect() saves the running version (0.5.0 or later) in the same
	 * request that saves the connection, so a fresh connect never matches.
	 * Disconnect deletes the version with the connection. Cheap when the
	 * flag is saved: one autoloaded option.
	 *
	 * @return bool True when the flag was saved now.
	 */
	public static function upgrade() {
		if ( self::is_done() ) {
			return false;
		}
		$version = (string) get_option( Settings::OPTION_VERSION, '' );
		if ( '' !== $version && version_compare( $version, '0.5.0', '>=' ) ) {
			return false;
		}
		if ( empty( Settings::installation() ) || ! Credentials::present() ) {
			return false;
		}
		self::mark_done();
		Logger::info( 'Setup counted as done for a store connected before 0.5.0', array( 'status' => '' !== $version ? $version : 'none' ) );
		return true;
	}

	/**
	 * True when every blocking row is in the result and passed.
	 *
	 * @param array $result Self-check result.
	 * @return bool
	 */
	public static function complete( array $result ) {
		return 0 === self::remaining( $result );
	}

	/**
	 * How many blocking rows have not passed in the result: a local row
	 * failed or missing from it, or another row marked blocking that failed.
	 *
	 * @param array $result Self-check result.
	 * @return int
	 */
	public static function remaining( array $result ) {
		$passed = array();
		$count  = 0;
		foreach ( isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array() as $check ) {
			if ( ! isset( $check['code'] ) ) {
				continue;
			}
			if ( ! empty( $check['ok'] ) ) {
				$passed[ $check['code'] ] = true;
			} elseif ( ! array_key_exists( $check['code'], Self_Check::LETTERS ) && Self_Check::BLOCKING === Self_Check::severity( $check ) ) {
				++$count;
			}
		}
		foreach ( array_keys( Self_Check::LETTERS ) as $code ) {
			if ( ! isset( $passed[ $code ] ) ) {
				++$count;
			}
		}
		return $count;
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
