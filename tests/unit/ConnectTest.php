<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Admin_Page;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Plugin;
use OutlinePay\WooCommerce\Scheduler;
use OutlinePay\WooCommerce\Settings;

/**
 * Activation notice and what a successful connect does.
 */
class ConnectTest extends TestCase {

	/** @var array Options in memory. */
	private $options = array();

	/** @var array Requests sent over the WordPress HTTP API. */
	private $requests = array();

	/** @var mixed What wp_remote_request answers. */
	private $answer;

	/** @var array Async actions queued. */
	private $queued = array();

	/** @var array Transients in memory. */
	private $transients = array();

	/** @var string Store currency. */
	private $currency = 'KRW';

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Credentials::reset_cache();
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'data' => array( array( 'method' => 'card', 'label_ko' => '신용카드' ) ) ) ),
		);

		$options    = &$this->options;
		$requests   = &$this->requests;
		$answer     = &$this->answer;
		$queued     = &$this->queued;
		$transients = &$this->transients;
		$currency   = &$this->currency;
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
		Functions\when( 'set_transient' )->alias(
			static function ( $name, $value ) use ( &$transients ) {
				$transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				return isset( $transients[ $name ] ) ? $transients[ $name ] : false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				unset( $transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.example/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$requests, &$answer ) {
				$requests[] = $args['method'] . ' ' . $url;
				return $answer;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 1 );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook ) use ( &$queued ) {
				$queued[] = $hook;
				return 1;
			}
		);
		Functions\when( 'get_woocommerce_currency' )->alias(
			static function () use ( &$currency ) {
				return $currency;
			}
		);
		Functions\when( 'wc_get_price_decimals' )->alias(
			static function () use ( &$options ) {
				return isset( $options['woocommerce_price_num_decimals'] ) ? (int) $options['woocommerce_price_num_decimals'] : 2;
			}
		);
	}

	protected function tearDown(): void {
		Credentials::reset_cache();
		parent::tearDown();
	}

	private static function answer() {
		return array(
			'id'             => 'inst_1',
			'mode'           => 'live',
			'status'         => 'connected',
			'provider'       => 'cookiepay',
			'token'          => 'olp_it_test_token',
			'signing_secret' => 'olp_isec_test',
		);
	}

	public function test_connect_fetches_the_methods_once_right_away() {
		Admin_Page::finish_connect( self::answer(), false );

		$this->assertSame( array( 'GET https://api.outlinepay.com/plugin/v1/methods' ), $this->requests );
		$this->assertTrue( Settings::method_enabled_by_server( 'card' ) );
		$this->assertSame( array(), $this->queued, 'no background refresh needed' );
		$this->assertArrayNotHasKey( 'token', $this->options['outlinepay_installation'] );
	}

	public function test_connect_falls_back_to_the_background_refresh_when_the_fetch_fails() {
		$this->answer = new \WP_Error( 'http_request_failed', 'timeout' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 5 );

		Admin_Page::finish_connect( self::answer(), false );

		$this->assertCount( 1, $this->requests );
		$this->assertContains( Scheduler::HOOK_METHODS_NOW, $this->queued );
	}

	public function test_connect_sets_the_decimals_when_the_box_is_checked() {
		$args = Admin_Page::finish_connect( self::answer(), true );

		$this->assertSame( 0, $this->options['woocommerce_price_num_decimals'] );
		$this->assertSame( array( 'outlinepay_decimals' => '1' ), $args );
	}

	public function test_connect_leaves_the_decimals_alone_when_the_box_is_unchecked() {
		$args = Admin_Page::finish_connect( self::answer(), false );

		$this->assertArrayNotHasKey( 'woocommerce_price_num_decimals', $this->options );
		$this->assertSame( array(), $args );
	}

	public function test_connect_leaves_the_decimals_alone_when_the_store_is_not_in_krw() {
		$this->currency = 'USD';
		$args           = Admin_Page::finish_connect( self::answer(), true );

		$this->assertArrayNotHasKey( 'woocommerce_price_num_decimals', $this->options );
		$this->assertSame( array(), $args );
	}

	public function test_activation_of_a_store_that_is_not_connected_shows_the_notice_once() {
		Plugin::activate();

		ob_start();
		Admin_Page::activation_notice();
		$first = ob_get_clean();
		ob_start();
		Admin_Page::activation_notice();
		$second = ob_get_clean();

		$this->assertStringContainsString( 'OutlinePay is active. Paste your connect code to start taking payments.', $first );
		$this->assertStringContainsString( 'focus=connect_code', $first );
		$this->assertStringContainsString( 'Go to OutlinePay', $first );
		$this->assertSame( '', $second );
	}

	public function test_activation_of_a_connected_store_shows_no_notice() {
		Admin_Page::finish_connect( self::answer(), false );
		Plugin::activate();

		$this->assertArrayNotHasKey( Plugin::TRANSIENT_ACTIVATED, $this->transients );
	}

	public function test_the_connected_notice_lists_three_next_steps() {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example/checkout/' );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://shop.example/shop/' );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action ) {
				echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( $text ) {
				echo '<input type="submit" value="' . $text . '">';
			}
		);
		$_GET['outlinepay_result']   = 'connected';
		$_GET['outlinepay_decimals'] = '1';
		$render                      = new \ReflectionMethod( Admin_Page::class, 'render_notice' );
		$render->setAccessible( true );
		ob_start();
		$render->invoke( null );
		$html = ob_get_clean();
		unset( $_GET['outlinepay_result'], $_GET['outlinepay_decimals'] );

		$this->assertStringContainsString( 'Your store is connected.', $html );
		$this->assertStringContainsString( 'Number of decimals set to 0.', $html );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( 'href="https://shop.example/checkout/"', $html );
		$this->assertStringContainsString( 'value="outlinepay_self_check"', $html );
		$this->assertStringContainsString( 'nonce-outlinepay_self_check', $html );
		$this->assertStringContainsString( 'href="https://shop.example/shop/"', $html );
	}
}
