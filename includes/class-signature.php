<?php
/**
 * Verifies the OutlinePay-Signature header on callbacks.
 *
 * Header shape: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Signature check for signed callbacks.
 */
final class Signature {

	/**
	 * How far the signed timestamp may be from now, in seconds.
	 */
	const TOLERANCE = 300;

	/**
	 * True when the header carries a fresh, valid signature of the body.
	 *
	 * @param string $header The raw OutlinePay-Signature header value.
	 * @param string $secret The installation signing secret.
	 * @param string $body   The raw request body, exactly as received.
	 * @param int    $now    Current unix time.
	 * @return bool
	 */
	public static function verify( $header, $secret, $body, $now ) {
		if ( ! is_string( $header ) || '' === $header || ! is_string( $secret ) || '' === $secret || ! is_string( $body ) ) {
			return false;
		}

		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && null === $timestamp ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = strtolower( $pair[1] );
			}
		}

		if ( null === $timestamp || ! preg_match( '/^\d{1,12}$/', $timestamp ) || empty( $signatures ) ) {
			return false;
		}
		$timestamp = (int) $timestamp;
		if ( 0 === $timestamp || abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		foreach ( $signatures as $given ) {
			if ( hash_equals( $expected, $given ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a header value. Used by tests.
	 *
	 * @param string $secret Signing secret.
	 * @param string $body   Body.
	 * @param int    $time   Unix time to sign with.
	 * @return string
	 */
	public static function header( $secret, $body, $time ) {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}
}
