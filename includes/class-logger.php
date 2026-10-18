<?php
/**
 * Logging with a strict allowlist.
 *
 * Only ids, statuses, method keys and error codes are ever written. Messages
 * are fixed strings written in this plugin. Buyer data, tokens and secrets
 * have no way in: unknown context keys are dropped and every value is reduced
 * to a short token of safe characters.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log under the source "outlinepay".
 */
final class Logger {

	const SOURCE = 'outlinepay';

	/**
	 * Context keys allowed into a log line.
	 */
	const ALLOWED_KEYS = array( 'order_id', 'payment_id', 'refund_id', 'status', 'method', 'code', 'http_status', 'decision', 'amount', 'count' );

	/**
	 * Test seam: a callable receiving ( level, line ). Null uses wc_get_logger().
	 *
	 * @var callable|null
	 */
	private static $sink = null;

	/**
	 * Replaces the destination. Pass null to restore the default.
	 *
	 * @param callable|null $sink Receives ( string $level, string $line ).
	 * @return void
	 */
	public static function set_sink( $sink ) {
		self::$sink = $sink;
	}

	/**
	 * Info line.
	 *
	 * @param string $message Fixed message.
	 * @param array  $context Allowlisted context.
	 * @return void
	 */
	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Warning line.
	 *
	 * @param string $message Fixed message.
	 * @param array  $context Allowlisted context.
	 * @return void
	 */
	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Error line.
	 *
	 * @param string $message Fixed message.
	 * @param array  $context Allowlisted context.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Formats one line. Public so tests can check the redaction directly.
	 *
	 * @param string $message Fixed message.
	 * @param array  $context Context; keys outside ALLOWED_KEYS are dropped.
	 * @return string
	 */
	public static function format( $message, array $context ) {
		$message = preg_replace( '/[^A-Za-z0-9 _.,:()\-]/', '', (string) $message );
		$parts   = array( substr( $message, 0, 200 ) );
		foreach ( self::ALLOWED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $value );
			if ( self::looks_secret( $value ) ) {
				$value = '[redacted]';
			}
			$parts[] = $key . '=' . substr( $value, 0, 64 );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Credentials never belong in a log, whatever key they arrive under.
	 *
	 * @param string $value Sanitized value.
	 * @return bool
	 */
	private static function looks_secret( $value ) {
		return 0 === strpos( $value, 'olp_it_' ) || 0 === strpos( $value, 'olp_isec_' ) || 0 === strpos( $value, 'olp_cc_' );
	}

	/**
	 * Writes a line.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Fixed message.
	 * @param array  $context Allowlisted context.
	 * @return void
	 */
	private static function log( $level, $message, array $context ) {
		$line = self::format( $message, $context );
		if ( null !== self::$sink ) {
			call_user_func( self::$sink, $level, $line );
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
		}
	}
}
