<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Credentials;

class CredentialsTest extends TestCase {

	public function test_seal_and_open_round_trip() {
		$key    = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$sealed = Credentials::seal( 'olp_it_test_abc', $key );
		$this->assertStringNotContainsString( 'olp_it_test_abc', $sealed );
		$this->assertStringNotContainsString( 'olp_it_test_abc', base64_decode( $sealed ) );
		$this->assertSame( 'olp_it_test_abc', Credentials::open( $sealed, $key ) );
	}

	public function test_each_seal_uses_a_fresh_nonce() {
		$key = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$this->assertNotSame( Credentials::seal( 'same', $key ), Credentials::seal( 'same', $key ) );
	}

	public function test_another_key_or_a_tampered_value_does_not_open() {
		$key    = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$sealed = Credentials::seal( 'secret', $key );
		$this->assertNull( Credentials::open( $sealed, random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$raw                 = base64_decode( $sealed );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertNull( Credentials::open( base64_encode( $raw ), $key ) );
		$this->assertNull( Credentials::open( 'not base64 !!', $key ) );
		$this->assertNull( Credentials::open( '', $key ) );
	}

	public function test_the_key_comes_from_the_site_salts() {
		Functions\when( 'wp_salt' )->alias(
			static function ( $scheme ) {
				return 'salt-' . $scheme;
			}
		);
		$first = Credentials::key();
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen( $first ) );

		Functions\when( 'wp_salt' )->alias(
			static function ( $scheme ) {
				return 'other-' . $scheme;
			}
		);
		$this->assertNotSame( $first, Credentials::key() );
	}

	public function test_stored_credentials_read_back_and_are_never_autoloaded() {
		$options = array();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value, $unused, $autoload ) use ( &$options ) {
				$options[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return isset( $options[ $name ] ) ? $options[ $name ][0] : $fallback;
			}
		);

		Credentials::save( 'olp_it_test_TOKEN', 'olp_isec_SECRET' );
		Credentials::reset_cache();

		$this->assertSame( 'no', $options[ Credentials::OPTION ][1] );
		$this->assertStringNotContainsString( 'olp_it_test_TOKEN', $options[ Credentials::OPTION ][0] );
		$this->assertSame( 'olp_it_test_TOKEN', Credentials::token() );
		$this->assertSame( 'olp_isec_SECRET', Credentials::signing_secret() );

		Credentials::clear();
		$this->assertNull( Credentials::token() );
	}
}
