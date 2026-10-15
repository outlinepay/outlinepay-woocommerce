<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Settings;
use OutlinePay\WooCommerce\Site_Identity;

/**
 * Staging-copy detection and the two ways out of it.
 */
class SiteIdentityTest extends TestCase {

	/** @var array Options in memory. */
	private $options = array();

	/** @var string This site's address in the test. */
	private $home = 'https://shop.example.com/';

	protected function setUp(): void {
		parent::setUp();
		$options = &$this->options;
		$home    = &$this->home;
		Credentials::reset_cache();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->alias(
			static function () use ( &$home ) {
				return $home;
			}
		);
		Functions\when( 'rest_url' )->alias(
			static function ( $path ) use ( &$home ) {
				return rtrim( $home, '/' ) . '/wp-json/' . $path;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			}
		);
		// Any real HTTP call would go through here. None may happen on the copy path.
		Functions\expect( 'wp_remote_request' )->never();
	}

	protected function tearDown(): void {
		Credentials::reset_cache();
		parent::tearDown();
	}

	private function connect_at( $url ) {
		Credentials::save( 'olp_it_test_TOKEN', 'olp_isec_SECRET' );
		Credentials::reset_cache();
		$this->options[ Settings::OPTION_INSTALLATION ] = array(
			'id'     => 'inst_1',
			'status' => 'connected',
		);
		$this->options[ Site_Identity::OPTION ]         = $url;
	}

	/**
	 * @dataProvider same_addresses
	 */
	public function test_normalize_ignores_scheme_case_and_trailing_slash( $a, $b ) {
		$this->assertSame( Site_Identity::normalize( $a ), Site_Identity::normalize( $b ) );
	}

	public function same_addresses() {
		return array(
			'scheme'         => array( 'http://shop.example.com/', 'https://shop.example.com/' ),
			'trailing slash' => array( 'https://shop.example.com', 'https://shop.example.com/' ),
			'host case'      => array( 'https://Shop.EXAMPLE.com/', 'https://shop.example.com' ),
			'subfolder'      => array( 'https://example.com/store/', 'http://EXAMPLE.com/store' ),
			'query dropped'  => array( 'https://shop.example.com/?lang=ko', 'https://shop.example.com/' ),
		);
	}

	/**
	 * @dataProvider different_addresses
	 */
	public function test_normalize_keeps_what_tells_sites_apart( $a, $b ) {
		$this->assertNotSame( Site_Identity::normalize( $a ), Site_Identity::normalize( $b ) );
	}

	public function different_addresses() {
		return array(
			'staging host' => array( 'https://shop.example.com/', 'https://staging.shop.example.com/' ),
			'other domain' => array( 'https://shop.example.com/', 'https://shop-example.wpstaging.net/' ),
			'path'         => array( 'https://example.com/store/', 'https://example.com/store-copy/' ),
			'path case'    => array( 'https://example.com/Store/', 'https://example.com/store/' ),
			'port'         => array( 'https://shop.example.com/', 'https://shop.example.com:8443/' ),
		);
	}

	public function test_the_connected_store_is_not_a_copy() {
		$this->connect_at( 'http://SHOP.example.com' );
		$this->assertFalse( Site_Identity::is_copy() );
		$this->assertTrue( Settings::is_connected() );
	}

	public function test_a_site_at_another_address_with_the_credentials_is_a_copy() {
		$this->connect_at( 'https://shop.example.com/' );
		$this->home = 'https://staging.shop.example.com/';
		$this->assertTrue( Site_Identity::is_copy() );
		$this->assertFalse( Settings::is_connected(), 'a copy is never connected, so gateways and the scheduler stay off' );
	}

	public function test_no_saved_address_or_no_credentials_is_not_a_copy() {
		$this->options[ Site_Identity::OPTION ] = 'https://other.example.com/';
		$this->assertFalse( Site_Identity::is_copy(), 'no credentials' );
		$this->connect_at( '' );
		$this->assertFalse( Site_Identity::is_copy(), 'nothing saved' );
	}

	public function test_an_older_install_saves_its_address_once_without_a_notice() {
		$this->connect_at( '' );
		unset( $this->options[ Site_Identity::OPTION ] );
		Site_Identity::ensure_saved();
		$this->assertSame( 'https://shop.example.com/', $this->options[ Site_Identity::OPTION ] );

		$this->home = 'https://staging.shop.example.com/';
		Site_Identity::ensure_saved();
		$this->assertSame( 'https://shop.example.com/', $this->options[ Site_Identity::OPTION ], 'never overwritten' );
		$this->assertTrue( Site_Identity::is_copy() );
	}

	public function test_nothing_is_saved_for_a_store_that_never_connected() {
		Site_Identity::ensure_saved();
		$this->assertArrayNotHasKey( Site_Identity::OPTION, $this->options );
	}

	public function test_connect_this_copy_separately_clears_locally_and_never_disconnects() {
		$this->connect_at( 'https://shop.example.com/' );
		$this->home = 'https://staging.shop.example.com/';

		Site_Identity::forget_copied_connection();

		Credentials::reset_cache();
		$this->assertFalse( Credentials::present() );
		$this->assertArrayNotHasKey( Settings::OPTION_INSTALLATION, $this->options );
		$this->assertArrayNotHasKey( Site_Identity::OPTION, $this->options );
		$this->assertFalse( Site_Identity::is_copy(), 'the normal connect form shows again' );
		// wp_remote_request expected never(): no DELETE /installation went out.
	}

	public function test_i_moved_my_store_saves_the_address_and_only_updates_the_callback() {
		$this->connect_at( 'https://shop.example.com/' );
		$this->home = 'https://new-shop.example.com/';
		$requests   = array();
		$client     = new Api_Client(
			static function () {
				return 'olp_it_test_TOKEN';
			},
			static function ( $method, $url, $headers, $body ) use ( &$requests ) {
				$requests[] = array( $method, $url, $body );
				return array(
					'status' => 200,
					'body'   => '{"id":"inst_1"}',
				);
			},
			'https://api.example.test/plugin/v1'
		);

		$this->assertTrue( Site_Identity::accept_move( $client ) );
		$this->assertFalse( Site_Identity::is_copy() );
		$this->assertTrue( Settings::is_connected(), 'payments are back on' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'PATCH', $requests[0][0] );
		$this->assertSame( 'https://api.example.test/plugin/v1/installation', $requests[0][1] );
		$this->assertSame( '{"callback_url":"https:\/\/new-shop.example.com\/wp-json\/outlinepay\/v1\/callback"}', $requests[0][2] );
	}

	public function test_a_move_still_turns_payments_on_when_the_callback_update_fails() {
		$this->connect_at( 'https://shop.example.com/' );
		$this->home = 'https://new-shop.example.com/';
		$client     = new Api_Client(
			static function () {
				return 'olp_it_test_TOKEN';
			},
			static function () {
				return array(
					'status' => 503,
					'body'   => '{"error":{"code":"processor_error"}}',
				);
			},
			'https://api.example.test/plugin/v1'
		);
		$this->assertFalse( Site_Identity::accept_move( $client ) );
		$this->assertFalse( Site_Identity::is_copy() );
	}
}
