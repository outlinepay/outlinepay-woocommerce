<?php
/**
 * Encrypted storage for the store token and signing secret.
 *
 * Both are sealed with sodium_crypto_secretbox under a key derived from the
 * site's auth salts, and kept in one option that is never autoloaded.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the credentials option.
 */
final class Credentials {

	const OPTION = 'outlinepay_credentials';

	/**
	 * Decrypted credentials for this request, to avoid repeated decryption.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Stores both credentials.
	 *
	 * @param string $token          Store token (olp_it_...).
	 * @param string $signing_secret Signing secret (olp_isec_...).
	 * @return bool
	 */
	public static function save( $token, $signing_secret ) {
		$plain       = wp_json_encode(
			array(
				'token'          => (string) $token,
				'signing_secret' => (string) $signing_secret,
			)
		);
		$sealed      = self::seal( $plain, self::key() );
		self::$cache = null;
		delete_option( self::OPTION );
		return add_option( self::OPTION, $sealed, '', 'no' );
	}

	/**
	 * Removes both credentials.
	 *
	 * @return void
	 */
	public static function clear() {
		self::$cache = null;
		delete_option( self::OPTION );
	}

	/**
	 * The store token, or null.
	 *
	 * @return string|null
	 */
	public static function token() {
		$all = self::load();
		return $all ? $all['token'] : null;
	}

	/**
	 * The signing secret, or null.
	 *
	 * @return string|null
	 */
	public static function signing_secret() {
		$all = self::load();
		return $all ? $all['signing_secret'] : null;
	}

	/**
	 * True when credentials exist and decrypt.
	 *
	 * @return bool
	 */
	public static function present() {
		return null !== self::load();
	}

	/**
	 * True when an option exists but no longer decrypts (the salts changed).
	 *
	 * @return bool
	 */
	public static function unreadable() {
		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) && '' !== $stored && null === self::load();
	}

	/**
	 * Encrypts with a fresh nonce. Returns base64( nonce . ciphertext ).
	 *
	 * @param string $plain Plaintext.
	 * @param string $key   32-byte key.
	 * @return string
	 */
	public static function seal( $plain, $key ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding of ciphertext.
	}

	/**
	 * Decrypts a sealed value. Returns null when it does not open.
	 *
	 * @param string $sealed Value from seal().
	 * @param string $key    32-byte key.
	 * @return string|null
	 */
	public static function open( $sealed, $key ) {
		$raw = base64_decode( (string) $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding of ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Key derived from the site's auth salts.
	 *
	 * @return string 32 bytes.
	 */
	public static function key() {
		return sodium_crypto_generichash( 'outlinepay-credentials|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Clears the per-request cache. Used by tests.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = null;
	}

	/**
	 * Loads and decrypts.
	 *
	 * @return array|null
	 */
	private static function load() {
		if ( null !== self::$cache ) {
			return self::$cache ? self::$cache : null;
		}
		self::$cache = false;
		$stored      = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$plain = self::open( $stored, self::key() );
		if ( null === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) || empty( $data['token'] ) || empty( $data['signing_secret'] ) ) {
			return null;
		}
		self::$cache = array(
			'token'          => (string) $data['token'],
			'signing_secret' => (string) $data['signing_secret'],
		);
		return self::$cache;
	}
}
