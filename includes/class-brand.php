<?php
/**
 * The payment provider's brand, for the pages and notes the merchant reads
 * day to day. The entry points (the menu, the Payments row, the plugin list)
 * stay OutlinePay.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the brand object OutlinePay keeps on the installation record. Never
 * fetches anything. The record is untrusted input: every value is checked
 * here and escaped where it is printed.
 */
final class Brand {

	/**
	 * The name every method answers before the store is connected.
	 */
	const DEFAULT_NAME = 'OutlinePay';

	/**
	 * Longest brand name kept, in characters.
	 */
	const MAX_NAME = 60;

	/**
	 * True when the store is connected and the provider is known. Until
	 * then every method answers the OutlinePay defaults.
	 *
	 * @return bool
	 */
	public static function connected() {
		return Settings::is_connected() && '' !== self::known_name();
	}

	/**
	 * The provider's name, or OutlinePay before connect.
	 *
	 * @return string
	 */
	public static function name() {
		return self::connected() ? self::known_name() : self::DEFAULT_NAME;
	}

	/**
	 * The name for a locale: the Korean name on a Korean site when the record
	 * has one, else name().
	 *
	 * @param string $locale Locale such as ko_KR.
	 * @return string
	 */
	public static function name_in( $locale ) {
		if ( self::connected() && 0 === strpos( (string) $locale, 'ko' ) ) {
			$name_ko = self::clean_name( self::field( 'name_ko' ) );
			if ( '' !== $name_ko ) {
				return $name_ko;
			}
		}
		return self::name();
	}

	/**
	 * The name in the site language, for order notes (see Notes).
	 *
	 * @return string
	 */
	public static function site_name() {
		return self::name_in( get_locale() );
	}

	/**
	 * The name in the language of the admin who is reading.
	 *
	 * @return string
	 */
	public static function user_name() {
		return self::name_in( get_user_locale() );
	}

	/**
	 * The logo address when it is https, else null. Null before connect.
	 *
	 * @return string|null
	 */
	public static function logo_url() {
		if ( ! self::connected() ) {
			return null;
		}
		$url = self::field( 'logo_url' );
		return self::https_url( $url ) ? $url : null;
	}

	/**
	 * The accent color as #rrggbb, else null. Null before connect.
	 *
	 * @return string|null
	 */
	public static function accent() {
		if ( ! self::connected() ) {
			return null;
		}
		$accent = self::field( 'accent' );
		return is_string( $accent ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ? strtolower( $accent ) : null;
	}

	/**
	 * The provider's support page (see Settings::provider_support_url).
	 *
	 * @return string
	 */
	public static function support_url() {
		return Settings::provider_support_url();
	}

	/**
	 * True when $url is an https address.
	 *
	 * @param mixed $url Value from the record.
	 * @return bool
	 */
	public static function https_url( $url ) {
		return is_string( $url ) && 0 === strpos( $url, 'https://' ) && false !== filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * The brand's name, else the name of the provider key (an older server
	 * sends no brand), else an empty string.
	 *
	 * @return string
	 */
	private static function known_name() {
		$name = self::clean_name( self::field( 'name' ) );
		if ( '' !== $name ) {
			return $name;
		}
		$installation = Settings::installation();
		return isset( $installation['provider'] ) ? self::clean_name( Settings::provider_name( (string) $installation['provider'] ) ) : '';
	}

	/**
	 * A field of the stored brand object, or null.
	 *
	 * @param string $key Field name.
	 * @return mixed
	 */
	public static function field( $key ) {
		$installation = Settings::installation();
		$brand        = isset( $installation['brand'] ) && is_array( $installation['brand'] ) ? $installation['brand'] : array();
		return isset( $brand[ $key ] ) ? $brand[ $key ] : null;
	}

	/**
	 * A name from the record: one line, no control characters, at most
	 * MAX_NAME characters. Empty when it is not a string.
	 *
	 * @param mixed $name Value from the record.
	 * @return string
	 */
	private static function clean_name( $name ) {
		if ( ! is_string( $name ) ) {
			return '';
		}
		$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $name ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::MAX_NAME ) : substr( $name, 0, self::MAX_NAME );
	}
}
