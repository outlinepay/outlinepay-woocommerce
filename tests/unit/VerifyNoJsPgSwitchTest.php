<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Admin_Page;
use OutlinePay\WooCommerce\Settings;

/**
 * Verifier: saving the Payment methods form without JavaScript after changing
 * the PG radio. The browser sends the boxes of the PG that was rendered (the
 * old one); the new PG's boxes were disabled, so they are absent. A method the
 * merchant had shown, listed only by the new PG, must keep its choice.
 */
class VerifyNoJsPgSwitchTest extends TestCase {

	use ContractsFixture;

	/** @var array Options in memory. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
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
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://shop.example/wp-admin/' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Admin_Page::set_redirect(
			static function ( $url ) {
				throw new \RuntimeException( 'redirect ' . $url );
			}
		);
		$this->options['outlinepay_methods'] = self::methods_option();
	}

	protected function tearDown(): void {
		unset( $_POST['outlinepay_shown'], $_POST['outlinepay_pg'] );
		parent::tearDown();
	}

	public function test_switching_pg_without_javascript_keeps_the_new_pgs_shown_methods() {
		// Toss is chosen and rendered. Under Toss the form has card and kakaopay only.
		$this->options['outlinepay_pg'] = array( '3' => '토스페이' );
		// Earlier, under Kiwoom, the merchant showed naverpay and bank transfer.
		Settings::set_shown( 'naverpay', true );
		Settings::set_shown( 'bank_transfer', true );
		Settings::set_shown( 'card', true );
		Settings::set_shown( 'kakaopay', true );

		// No JS: the radio moves to Kiwoom but the submitted boxes are Toss's.
		$_POST['outlinepay_shown'] = array( 'card', 'kakaopay' );
		$_POST['outlinepay_pg']    = array( '3' => '키움페이' );

		try {
			Admin_Page::save_methods();
		} catch ( \RuntimeException $stop ) {
			$this->assertStringContainsString( 'methods_saved', $stop->getMessage() );
		}

		$this->assertSame( '키움페이', Settings::pg_for_contract( 3 ) );
		$this->assertTrue( Settings::shown( 'card' ) );
		$this->assertTrue( Settings::shown( 'kakaopay' ) );
		$this->assertTrue( Settings::shown( 'naverpay' ), 'its box was not on the form: the choice must stay' );
		$this->assertTrue( Settings::shown( 'bank_transfer' ), 'its box was not on the form: the choice must stay' );
	}
}
