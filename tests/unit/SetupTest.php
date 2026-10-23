<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Settings;
use OutlinePay\WooCommerce\Setup;

/**
 * The one-time setup gate: the flag, the upgrade rule, disconnect, checkout.
 */
class SetupTest extends RefundTestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $this->options[ Setup::OPTION_DONE ] );
		$options = &$this->options;
		Functions\when( 'update_option' )->alias(
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
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'KRW' );
	}

	/**
	 * A result with every local row passing, then $overrides by code.
	 */
	private static function result( array $overrides = array(), array $extra = array() ) {
		$checks = array();
		foreach ( array_keys( Self_Check::LETTERS ) as $code ) {
			$checks[] = Self_Check::check( $code, isset( $overrides[ $code ] ) ? $overrides[ $code ] : true, 'store', array() );
		}
		$checks = array_merge( $checks, $extra );
		$failed = 0;
		foreach ( $checks as $check ) {
			$failed += $check['ok'] ? 0 : 1;
		}
		return array(
			'code'         => 'chk_Setup1',
			'online'       => true,
			'trigger'      => 'page',
			'ran_at'       => 1790000000,
			'failed_count' => $failed,
			'worst_side'   => '',
			'checks'       => $checks,
		);
	}

	private function gateway() {
		$gateway                    = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->outlinepay_method = 'card';
		$gateway->enabled           = 'yes';
		return $gateway;
	}

	public function test_a_run_with_a_failed_row_does_not_finish_setup() {
		Self_Check::store( self::result( array( 'wp_cron' => false ) ) );
		$this->assertArrayNotHasKey( Setup::OPTION_DONE, $this->options );
		$this->assertFalse( Setup::is_done() );
	}

	public function test_a_run_missing_a_local_row_does_not_finish_setup() {
		$result           = self::result();
		$result['checks'] = array_slice( $result['checks'], 1 );
		$this->assertFalse( Setup::complete( $result ) );
		Self_Check::store( array_merge( $result, array( 'failed_count' => 1 ) ) );
		$this->assertArrayNotHasKey( Setup::OPTION_DONE, $this->options );
	}

	public function test_the_first_all_green_run_saves_the_flag() {
		Self_Check::store( self::result() );
		$this->assertGreaterThan( 0, $this->options[ Setup::OPTION_DONE ] );
		$this->assertTrue( Setup::is_done() );
	}

	public function test_a_failing_server_row_does_not_block_setup() {
		$result = self::result( array(), array( Self_Check::check( 'callback_reachable', false, 'store', array() ) ) );
		$this->assertTrue( Setup::complete( $result ) );
	}

	public function test_the_gate_is_one_time() {
		Self_Check::store( self::result() );
		$done = $this->options[ Setup::OPTION_DONE ];
		Self_Check::store( self::result( array( 'wp_cron' => false ) ) );

		$this->assertSame( $done, $this->options[ Setup::OPTION_DONE ] );
		$this->assertTrue( Setup::is_done() );
		$this->assertTrue( $this->gateway()->is_available(), 'a later failure does not hide checkout' );
	}

	public function test_disconnect_clears_the_flag() {
		Self_Check::store( self::result() );
		Settings::clear_connection();
		$this->assertArrayNotHasKey( Setup::OPTION_DONE, $this->options );
		$this->assertFalse( Setup::is_done() );
	}

	public function test_upgrade_rule_a_stored_all_green_result_counts_as_done() {
		// Saved by 0.4.x: no currency row, no flag.
		$this->options[ Self_Check::OPTION_LAST ] = array(
			'code'         => 'chk_Old',
			'online'       => true,
			'trigger'      => 'daily',
			'ran_at'       => 1780000000,
			'failed_count' => 0,
			'worst_side'   => '',
			'checks'       => array( Self_Check::check( 'versions', true, 'store', array() ) ),
		);
		$this->assertTrue( Setup::is_done() );
		$this->assertGreaterThan( 0, $this->options[ Setup::OPTION_DONE ], 'saved, so a later failure cannot undo it' );
	}

	public function test_upgrade_rule_a_stored_failing_result_shows_setup() {
		$this->options[ Self_Check::OPTION_LAST ] = self::result( array( 'methods_fresh' => false ) );
		$this->assertFalse( Setup::is_done() );
		$this->assertArrayNotHasKey( Setup::OPTION_DONE, $this->options );
	}

	public function test_no_stored_result_and_no_flag_is_not_done() {
		$this->assertFalse( Setup::is_done() );
	}

	public function test_checkout_hides_the_methods_until_setup_is_done() {
		$this->assertFalse( $this->gateway()->is_available() );
		Self_Check::store( self::result() );
		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_a_pay_click_before_setup_is_refused() {
		$notices = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( $message ) use ( &$notices ) {
				$notices[] = $message;
			}
		);
		$this->order->status = 'pending';

		$result = $this->gateway()->process_payment( 42 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array( 'This payment method is not available right now. Choose another method.' ), $notices );
		$this->assertSame( array(), $this->requests, 'nothing sent to OutlinePay' );
	}
}
