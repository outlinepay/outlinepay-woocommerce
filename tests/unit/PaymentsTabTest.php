<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Settings;

/**
 * One OutlinePay row on WooCommerce > Settings > Payments from WooCommerce 9.9.
 *
 * WC_VERSION is a constant, so each test that needs it runs in its own process.
 */
class PaymentsTabTest extends TestCase {

	/** @var array Options by name. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Credentials::reset_cache();
		$this->options = array(
			'outlinepay_installation' => array(
				'status'   => 'connected',
				'provider' => 'cookiepay',
			),
			'outlinepay_credentials'  => Credentials::seal(
				json_encode(
					array(
						'token'          => 'olp_it_test_token',
						'signing_secret' => 'olp_isec_test',
					)
				),
				Credentials::key()
			),
		);
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Credentials::reset_cache();
		parent::tearDown();
	}

	public function test_the_tab_collapses_from_woocommerce_9_9() {
		$this->assertTrue( Settings::collapse_payments_tab( '9.9.0' ) );
		$this->assertTrue( Settings::collapse_payments_tab( '11.2.1' ) );
		$this->assertFalse( Settings::collapse_payments_tab( '9.8.5' ) );
		$this->assertFalse( Settings::collapse_payments_tab( '8.0' ) );
		$this->assertFalse( Settings::collapse_payments_tab( '' ) );
	}

	public function test_the_plugin_headers_name_the_same_woocommerce_minimum() {
		$root = dirname( __DIR__, 2 );
		$this->assertStringContainsString( 'WC requires at least: ' . Settings::MIN_WC . "\n", file_get_contents( $root . '/outlinepay-for-woocommerce.php' ) );
		$this->assertStringContainsString( "\nWC requires at least: " . Settings::MIN_WC . "\n", file_get_contents( $root . '/readme.txt' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_on_9_9_only_the_card_gateway_has_an_admin_title() {
		define( 'WC_VERSION', '9.9.0' );
		foreach ( Settings::METHODS as $method ) {
			$gateway = new Gateway( $method );
			if ( 'card' === $method ) {
				$this->assertSame( 'OutlinePay', $gateway->method_title );
				$this->assertNotSame( '', $gateway->method_description );
			} else {
				$this->assertSame( '', $gateway->method_title, $method );
				$this->assertSame( '', $gateway->method_description, $method );
			}
			// The shopper still sees the method's own name.
			$this->assertSame( Settings::method_name( $method ), $gateway->title );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_on_9_9_the_row_says_when_the_store_is_not_connected() {
		define( 'WC_VERSION', '10.3.0' );
		$this->options['outlinepay_installation']['status'] = 'revoked';
		$gateway = new Gateway( 'card' );
		$this->assertSame( 'OutlinePay (not connected)', $gateway->method_title );
		$this->assertSame( 'Connect the store on the OutlinePay page to take payments.', $gateway->method_description );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_before_9_9_every_method_keeps_its_own_row() {
		define( 'WC_VERSION', '9.8.5' );
		foreach ( Settings::METHODS as $method ) {
			$gateway = new Gateway( $method );
			$this->assertSame( 'OutlinePay: ' . Settings::method_name( $method ), $gateway->method_title, $method );
			$this->assertNotSame( '', $gateway->method_description, $method );
		}
	}
}
