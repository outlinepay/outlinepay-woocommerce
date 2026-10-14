<?php
/**
 * Converts WooCommerce order totals to whole KRW.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * KRW has no minor unit. A total with a fractional part is refused, never rounded.
 */
final class Amount {

	/**
	 * Largest amount the server accepts.
	 */
	const MAX = 1000000000000;

	/**
	 * Returns the total as a positive whole number of KRW, or null when it is not one.
	 *
	 * @param mixed $total Order total as WooCommerce returns it (string or number).
	 * @return int|null
	 */
	public static function to_krw( $total ) {
		if ( is_int( $total ) ) {
			$total = (string) $total;
		} elseif ( is_float( $total ) ) {
			if ( ! is_finite( $total ) || floor( $total ) !== $total ) {
				return null;
			}
			$total = number_format( $total, 0, '.', '' );
		}
		if ( ! is_string( $total ) ) {
			return null;
		}

		$total = trim( $total );
		if ( ! preg_match( '/^(\d+)(?:\.0*)?$/', $total, $matches ) ) {
			return null;
		}
		$digits = ltrim( $matches[1], '0' );
		if ( '' === $digits || strlen( $digits ) > 13 ) {
			return null;
		}
		$value = (int) $digits;
		if ( $value <= 0 || $value > self::MAX ) {
			return null;
		}
		return $value;
	}
}
