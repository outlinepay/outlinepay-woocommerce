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

	public function test_an_upgraded_connected_store_with_a_failing_stored_result_gets_the_flag() {
		$this->options[ Settings::OPTION_VERSION ]   = '0.4.2';
		$this->options[ Self_Check::OPTION_LAST ]    = self::result( array(), array( Self_Check::check( 'open_problems_7d', false, 'unknown', array() ) ) );
		$this->options[ Self_Check::OPTION_LAST ]['failed_count'] = 1;

		$this->assertTrue( Setup::upgrade() );
		$this->assertGreaterThan( 0, $this->options[ Setup::OPTION_DONE ] );
		$this->assertTrue( $this->gateway()->is_available(), 'checkout stays on' );
	}

	public function test_an_upgraded_connected_store_with_no_stored_result_gets_the_flag() {
		$this->options[ Settings::OPTION_VERSION ] = '0.4.1';
		$this->assertTrue( Setup::upgrade() );
		$this->assertTrue( Setup::is_done() );
	}

	public function test_a_connected_store_with_no_saved_version_counts_as_upgraded() {
		$this->assertArrayNotHasKey( Settings::OPTION_VERSION, $this->options );
		$this->assertTrue( Setup::upgrade() );
		$this->assertTrue( Setup::is_done() );
	}

	public function test_a_fresh_connect_on_this_version_goes_through_setup() {
		Functions\when( 'home_url' )->justReturn( 'https://shop.example/' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'offline' ) );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		$options = &$this->options;
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		\OutlinePay\WooCommerce\Admin_Page::finish_connect(
			array(
				'id'             => 'inst_2',
				'status'         => 'connected',
				'token'          => 'olp_it_test_token2',
				'signing_secret' => 'olp_isec_test2',
			)
		);
		$this->assertSame( OUTLINEPAY_VERSION, $this->options[ Settings::OPTION_VERSION ] );

		$this->assertFalse( Setup::upgrade() );
		$this->assertFalse( Setup::is_done() );
		$this->assertFalse( $this->gateway()->is_available() );
	}

	public function test_an_upgrade_without_a_connection_sets_nothing() {
		$this->options[ Settings::OPTION_VERSION ] = '0.4.2';
		unset( $this->options['outlinepay_credentials'] );
		\OutlinePay\WooCommerce\Credentials::reset_cache();

		$this->assertFalse( Setup::upgrade() );
		$this->assertArrayNotHasKey( Setup::OPTION_DONE, $this->options );
	}

	public function test_a_stored_all_green_result_alone_no_longer_counts_as_done() {
		$this->options[ Settings::OPTION_VERSION ] = OUTLINEPAY_VERSION;
		$this->options[ Self_Check::OPTION_LAST ]  = array(
			'code'         => 'chk_Old',
			'online'       => true,
			'trigger'      => 'daily',
			'ran_at'       => 1780000000,
			'failed_count' => 0,
			'worst_side'   => '',
			'checks'       => array( Self_Check::check( 'versions', true, 'store', array() ) ),
		);
		$this->assertFalse( Setup::is_done() );
		$this->assertFalse( Setup::upgrade() );
	}

	public function test_the_boot_runs_the_upgrade_rule() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-plugin.php' );
		$this->assertStringContainsString( 'Setup::upgrade();', $source );
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
