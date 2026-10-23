<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Admin_Page;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Plugin;
use OutlinePay\WooCommerce\Scheduler;
use OutlinePay\WooCommerce\Settings;

/**
 * The OutlinePay page: activation notice, connect, and the fixes under failed checks.
 */
class AdminPageTest extends TestCase {

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
		Admin_Page::finish_connect( self::answer() );

		$this->assertSame( array( 'GET https://api.outlinepay.com/plugin/v1/methods' ), $this->requests );
		$this->assertTrue( Settings::method_enabled_by_server( 'card' ) );
		$this->assertSame( array(), $this->queued, 'no background refresh needed' );
		$this->assertArrayNotHasKey( 'token', $this->options['outlinepay_installation'] );
	}

	public function test_connect_falls_back_to_the_background_refresh_when_the_fetch_fails() {
		$this->answer = new \WP_Error( 'http_request_failed', 'timeout' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'wp_rand' )->justReturn( 5 );

		Admin_Page::finish_connect( self::answer() );

		$this->assertCount( 1, $this->requests );
		$this->assertContains( Scheduler::HOOK_METHODS_NOW, $this->queued );
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
		Admin_Page::finish_connect( self::answer() );
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
		$_GET['outlinepay_result'] = 'connected';
		$render                    = new \ReflectionMethod( Admin_Page::class, 'render_notice' );
		$render->setAccessible( true );
		ob_start();
		$render->invoke( null );
		$html = ob_get_clean();
		unset( $_GET['outlinepay_result'] );

		$this->assertStringContainsString( 'Your store is connected.', $html );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( 'href="https://shop.example/checkout/"', $html );
		$this->assertStringContainsString( 'value="outlinepay_self_check"', $html );
		$this->assertStringContainsString( 'nonce-outlinepay_self_check', $html );
		$this->assertStringContainsString( 'href="https://shop.example/shop/"', $html );
	}

	public function test_failed_checks_show_their_fix_and_the_email_line_names_the_address() {
		Admin_Page::finish_connect( self::answer() );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_date' )->justReturn( '2026-10-23 10:00' );
		Functions\when( 'checked' )->justReturn( '' );
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
		$this->options['admin_email']                = 'owner@shop.example';
		$this->options['outlinepay_self_check_last'] = array(
			'code'         => 'chk_abc123',
			'online'       => true,
			'trigger'      => 'page',
			'ran_at'       => 1790000000,
			'failed_count' => 4,
			'worst_side'   => 'store',
			'checks'       => array(
				array( 'code' => 'scheduler_jobs', 'ok' => false, 'side' => 'store', 'detail' => array() ),
				array( 'code' => 'store_currency', 'ok' => false, 'side' => 'store', 'detail' => array( 'currency' => 'USD', 'accepted' => 'KRW', 'provider' => 'CookiePay' ) ),
				array( 'code' => 'not_a_copy', 'ok' => true, 'side' => 'store', 'detail' => array() ),
				array( 'code' => 'open_problems_7d', 'ok' => false, 'side' => 'unknown', 'detail' => array() ),
				array( 'code' => 'wp_cron', 'ok' => false, 'side' => 'store', 'detail' => array() ),
			),
		);
		$this->currency = 'USD';
		$render         = new \ReflectionMethod( Admin_Page::class, 'render_self_check' );
		$render->setAccessible( true );
		ob_start();
		$render->invoke( null );
		$html = ob_get_clean();

		$this->assertSame( 2, substr_count( $html, 'value="outlinepay_run_jobs"' ), 'Run now under scheduler_jobs and wp_cron' );
		$this->assertStringContainsString( 'nonce-outlinepay_run_jobs', $html );
		$this->assertStringContainsString( 'value="Run now"', $html );
		$this->assertStringContainsString( 'Copy code', $html );
		$this->assertSame( 2, substr_count( $html, 'data-outlinepay-copy' ), 'the code box and the problems row' );
		$this->assertStringNotContainsString( 'Use this address', $html, 'not a copy: no move button' );
		$this->assertStringContainsString( 'The store currency is USD. CookiePay takes payments in KRW only.', $html );
		$this->assertStringContainsString( 'value="Set store currency to KRW"', $html );
		$this->assertStringContainsString( 'Email me when a daily check fails (to the site admin address, owner@shop.example)', $html );
	}

	public function test_every_result_a_form_handler_sends_back_has_a_notice() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-admin-page.php' );
		preg_match_all( '/self::back\(([^;]*)\);/', $source, $calls );
		$keys = array();
		foreach ( $calls[1] as $args ) {
			preg_match_all( "/'([a-z_]+)'/", $args, $found );
			$keys = array_merge( $keys, $found[1] );
		}
		// The rate-limit line sits in the Self-check section (render_self_check), not on top.
		$keys = array_diff( array_unique( $keys ), array( 'self_check_rate_limited' ) );
		$this->assertContains( 'jobs_ran_methods_failed', $keys );

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example/checkout/' );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://shop.example/shop/' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		$render = new \ReflectionMethod( Admin_Page::class, 'render_notice' );
		$render->setAccessible( true );
		foreach ( $keys as $key ) {
			$_GET['outlinepay_result'] = $key;
			ob_start();
			$render->invoke( null );
			$this->assertStringContainsString( 'class="notice', ob_get_clean(), $key );
		}
		unset( $_GET['outlinepay_result'] );
	}

	public function test_provider_currencies_default_to_krw_for_a_record_saved_before_the_list() {
		$this->options['outlinepay_installation'] = array( 'id' => 'inst_1', 'status' => 'connected' );
		$this->assertSame( array( 'KRW' ), Settings::provider_currencies() );

		$this->options['outlinepay_installation']['currencies'] = array( 'JPY', 'KRW', 'bad', 7, 'JPY' );
		$this->assertSame( array( 'JPY', 'KRW' ), Settings::provider_currencies() );
	}

	public function test_connect_keeps_the_provider_currencies() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->assertSame( array( 'KRW' ), $this->options['outlinepay_installation']['currencies'] );
		$this->assertTrue( Settings::provider_takes( 'KRW' ) );
		$this->assertFalse( Settings::provider_takes( 'USD' ) );
	}

	public function test_set_store_currency_writes_the_currency_and_its_decimals() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->options['woocommerce_currency']           = 'USD';
		$this->options['woocommerce_price_num_decimals'] = 2;

		$this->assertSame( 'KRW', Settings::set_store_currency() );
		$this->assertSame( 'KRW', $this->options['woocommerce_currency'] );
		$this->assertSame( 0, $this->options['woocommerce_price_num_decimals'] );
	}

	public function test_the_connected_notice_offers_the_currency_switch_for_a_usd_store() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->currency = 'USD';
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
		$_GET['outlinepay_result'] = 'connected';
		$render                    = new \ReflectionMethod( Admin_Page::class, 'render_notice' );
		$render->setAccessible( true );
		ob_start();
		$render->invoke( null );
		$html = ob_get_clean();
		unset( $_GET['outlinepay_result'] );

		$this->assertStringContainsString( 'Your store currency is USD. CookiePay takes payments in KRW only.', $html );
		$this->assertStringContainsString( 'value="outlinepay_set_currency"', $html );
		$this->assertStringContainsString( 'nonce-outlinepay_set_currency', $html );
		$this->assertStringContainsString( 'value="Set store currency to KRW"', $html );
		$this->assertStringContainsString( 'What&#039;s next', $html );
		$this->assertStringNotContainsString( 'now show at checkout', $html, 'not true until the currency is switched' );
	}
}
