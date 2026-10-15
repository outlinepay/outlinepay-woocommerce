<?php
/**
 * Tells the connected store apart from a copy of it.
 *
 * The store's address is saved when it connects. A site whose address later
 * differs (a one-click staging copy, a migration copy) holds the live store's
 * credentials but must not take payments with them. On such a copy the
 * gateways are off and nothing calls OutlinePay until the merchant either
 * connects the copy separately or confirms that the store moved.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The saved store address and the copy check.
 */
final class Site_Identity {

	const OPTION = 'outlinepay_site_url';

	/**
	 * Comparable form of a URL: no scheme, lowercase host, no trailing slash.
	 * The path is kept, so a store in a subfolder stays distinct.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function normalize( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url  = trim( $url );
		$url  = preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $url );
		$url  = preg_replace( '#^//#', '', $url );
		$cut  = strcspn( $url, '/?#' );
		$host = strtolower( substr( $url, 0, $cut ) );
		$rest = substr( $url, $cut );
		$rest = preg_replace( '/[?#].*$/', '', $rest );
		return $host . rtrim( $rest, '/' );
	}

	/**
	 * The address the store was connected at, as saved.
	 *
	 * @return string
	 */
	public static function saved() {
		$saved = get_option( self::OPTION, '' );
		return is_string( $saved ) ? $saved : '';
	}

	/**
	 * This site's address now.
	 *
	 * @return string
	 */
	public static function current() {
		return (string) home_url( '/' );
	}

	/**
	 * Saves this site's address as the store's address.
	 *
	 * @return void
	 */
	public static function remember_current() {
		update_option( self::OPTION, self::current(), true );
	}

	/**
	 * Forgets the saved address.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}

	/**
	 * Saves the address once for stores connected before this check existed.
	 * Only when credentials exist and nothing is saved yet. No notice.
	 *
	 * @return void
	 */
	public static function ensure_saved() {
		if ( '' === self::saved() && Credentials::present() ) {
			self::remember_current();
		}
	}

	/**
	 * True when this site holds credentials saved at another address.
	 *
	 * @return bool
	 */
	public static function is_copy() {
		$saved = self::saved();
		if ( '' === $saved || ! Credentials::present() ) {
			return false;
		}
		return self::normalize( $saved ) !== self::normalize( self::current() );
	}

	/**
	 * "Connect this copy separately": drops the copied connection here only.
	 * The live store's credentials are not revoked: nothing is sent to OutlinePay.
	 *
	 * @return void
	 */
	public static function forget_copied_connection() {
		Settings::clear_connection();
		self::forget();
		Scheduler::unschedule_all();
		Logger::info( 'Copied connection removed from this site' );
	}

	/**
	 * "I moved my store to this address": saves the new address and tells
	 * OutlinePay the new callback address, best effort.
	 *
	 * @param Api_Client|null $client Client.
	 * @return bool True when OutlinePay accepted the new callback address.
	 */
	public static function accept_move( $client = null ) {
		self::remember_current();
		$client = $client ? $client : new Api_Client();
		try {
			$client->update_installation( array( 'callback_url' => Settings::callback_url() ) );
			Logger::info( 'Store moved, callback address updated' );
			return true;
		} catch ( Api_Error $error ) {
			Logger::warning( 'Store moved, callback address not updated', array( 'code' => $error->get_error_code() ) );
			return false;
		}
	}
}
