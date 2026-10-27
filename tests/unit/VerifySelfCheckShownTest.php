<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Settings;

/**
 * Verifier: the self-check counts the methods shown at checkout. With Toss
 * chosen for contract 3, a method only Kiwoom lists is not at checkout even
 * when its switch is on, so it must not count as shown.
 */
class VerifySelfCheckShownTest extends TestCase {

	use ContractsFixture;

	/** @var array Options in memory. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$options = &$this->options;
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
		Functions\when( 'get_file_data' )->justReturn(
			array(
				'php' => '7.4',
				'wp'  => '6.2',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'KRW' );
		$this->options['outlinepay_methods'] = self::methods_option();
	}

	public function test_a_method_the_chosen_pg_lacks_does_not_count_as_shown() {
		$this->options['outlinepay_pg'] = array( '3' => '토스페이' );
		foreach ( Settings::METHODS as $method ) {
			Settings::set_shown( $method, false );
		}
		// Only methods Toss does not list are switched on: nothing is at checkout.
		Settings::set_shown( 'naverpay', true );
		Settings::set_shown( 'bank_transfer', true );
		$this->assertFalse( Settings::method_enabled_by_server( 'naverpay' ) );

		$facts = Self_Check::gather_facts();

		$this->assertSame( 0, $facts['methods_shown'], 'no method is at checkout, so the check must say none shown' );
	}
}
