<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Admin_Page;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Plugin;
use OutlinePay\WooCommerce\Scheduler;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Settings;
use OutlinePay\WooCommerce\Setup;

/**
 * The OutlinePay page: activation notice, connect, and the fixes under failed checks.
 */
class AdminPageTest extends TestCase {

	use ContractsFixture;

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
		Settings::forget_price_decimals();
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
		Admin_Page::set_redirect( null );
		Admin_Page::set_self_check( null );
		Credentials::reset_cache();
		Settings::forget_price_decimals();
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
		$this->assertStringContainsString( 'The store currency is USD. CookiePay takes payments in KRW only. Prices keep their numbers. Check your product prices after switching.', $html );
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

	public function test_setup_support_line_without_a_known_provider_has_no_link() {
		$this->connected_store();
		unset( $this->options['outlinepay_installation']['provider'] );
		$this->options['outlinepay_self_check_last'] = Self_Check::offline(
			Self_Check::local_checks( self::healthy_facts( 'USD' ), self::NOW - 60 ),
			'page',
			self::NOW - 60
		);

		$html = $this->render_page( '' );

		$this->assertStringContainsString( 'Still stuck? Contact your payment provider. Give them this code:', $html );
		$this->assertStringNotContainsString( 'outlinepay-support-line" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:16px 0 4px">Still stuck? <a', $html );
	}

	public function test_provider_support_url_prefers_the_record_then_the_known_page() {
		$this->options['outlinepay_installation'] = array( 'id' => 'inst_1', 'status' => 'connected', 'provider' => 'cookiepay' );
		$this->assertSame( 'https://www.cookiepayments.com/iroboard/list?bId=contact', Settings::provider_support_url() );

		$this->options['outlinepay_installation']['support_url'] = 'https://help.example.com/plugin';
		$this->assertSame( 'https://help.example.com/plugin', Settings::provider_support_url() );

		$this->options['outlinepay_installation']['support_url'] = 'http://help.example.com/plugin';
		$this->assertSame( 'https://www.cookiepayments.com/iroboard/list?bId=contact', Settings::provider_support_url(), 'plain http is ignored' );

		$this->options['outlinepay_installation']['support_url'] = 'javascript:alert(1)';
		$this->assertSame( 'https://www.cookiepayments.com/iroboard/list?bId=contact', Settings::provider_support_url() );

		$this->options['outlinepay_installation'] = array( 'id' => 'inst_1', 'status' => 'connected', 'provider' => 'other' );
		$this->assertSame( '', Settings::provider_support_url(), 'unknown provider, no record: no link' );
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

	public function test_prices_show_no_decimals_on_a_connected_krw_store() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->assertSame( 0, Settings::price_decimals( 2 ) );
		$this->assertArrayNotHasKey( 'woocommerce_price_num_decimals', $this->options, 'no option write' );
	}

	public function test_decimals_are_left_alone_when_the_store_currency_is_not_a_provider_currency() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->currency = 'USD';
		$this->assertSame( 2, Settings::price_decimals( 2 ) );
		$this->assertSame( 3, Settings::price_decimals( 3 ) );
	}

	public function test_decimals_are_left_alone_when_the_store_is_not_connected() {
		$this->assertSame( 2, Settings::price_decimals( 2 ) );
		$this->options['outlinepay_installation'] = array( 'status' => 'revoked', 'currencies' => array( 'KRW' ) );
		$this->assertSame( 2, Settings::price_decimals( 2 ) );
	}

	public function test_the_minor_unit_table_and_the_boot_hook() {
		$this->assertSame( 0, Settings::minor_units( 'KRW' ) );
		$this->assertSame( 0, Settings::minor_units( 'pyg' ) );
		$this->assertSame( 2, Settings::minor_units( 'USD' ) );
		$this->assertSame( 2, Settings::minor_units( 'XYZ' ), 'unknown: 2' );
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-plugin.php' );
		$this->assertStringContainsString( "add_filter( 'wc_get_price_decimals', array( Settings::class, 'price_decimals' ) );", $source );
	}

	public function test_price_decimals_works_its_answer_out_once_per_currency() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$reads   = 0;
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options, &$reads ) {
				if ( 'outlinepay_installation' === $name ) {
					++$reads;
				}
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);

		$this->assertSame( 0, Settings::price_decimals( 2 ) );
		$first = $reads;
		$this->assertGreaterThan( 0, $first );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 0, Settings::price_decimals( 2 ) );
		}
		$this->assertSame( $first, $reads, 'no more reads for the same currency' );

		$this->currency = 'USD';
		$this->assertSame( 2, Settings::price_decimals( 2 ) );
		$this->assertGreaterThan( $first, $reads, 'a new currency is worked out again' );

		$this->currency = 'KRW';
		$this->options['outlinepay_installation']['status'] = 'revoked';
		$this->assertSame( 0, Settings::price_decimals( 2 ), 'still the cached answer' );
		Settings::mark_revoked();
		$this->assertSame( 2, Settings::price_decimals( 2 ), 'a revoked connection is seen in the same request' );
	}

	public function test_set_store_currency_drops_the_cached_decimals() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->assertSame( 0, Settings::price_decimals( 2 ), 'cached for KRW' );

		// The provider list changes under the cache, then the merchant switches currency.
		$this->options['outlinepay_installation']['currencies'] = array( 'USD' );
		$this->assertSame( 0, Settings::price_decimals( 2 ), 'still the cached answer' );
		Settings::set_store_currency();
		$this->assertSame( 2, Settings::price_decimals( 2 ), 'worked out again after the switch' );
	}

	private function render_private( $method ) {
		$render = new \ReflectionMethod( Admin_Page::class, $method );
		$render->setAccessible( true );
		ob_start();
		$render->invoke( null );
		return ob_get_clean();
	}

	/**
	 * Runs Admin_Page::set_currency() as admin-post.php would: the result key
	 * it redirects with, or the wp_die / nonce refusal.
	 */
	private function post_set_currency( $can = true, $nonce_ok = true ) {
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( $nonce_ok ) {
				if ( 'outlinepay_set_currency' !== $action || ! $nonce_ok ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return 1;
			}
		);
		Admin_Page::set_redirect(
			static function ( $url ) {
				throw new \RuntimeException( 'redirect ' . $url );
			}
		);
		try {
			Admin_Page::set_currency();
		} catch ( \RuntimeException $stop ) {
			return $stop->getMessage();
		}
		$this->fail( 'the handler must stop' );
	}

	public function test_set_currency_needs_the_capability() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		Functions\when( 'esc_html__' )->returnArg();
		$this->assertSame( 'wp_die', $this->post_set_currency( false ) );
		$this->assertArrayNotHasKey( 'woocommerce_currency', $this->options );
	}

	public function test_set_currency_needs_a_good_nonce() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->assertSame( 'bad nonce', $this->post_set_currency( true, false ) );
		$this->assertArrayNotHasKey( 'woocommerce_currency', $this->options );
	}

	public function test_set_currency_on_a_store_that_is_not_connected_changes_nothing() {
		$this->options['woocommerce_currency'] = 'USD';
		$result = $this->post_set_currency();

		$this->assertStringContainsString( 'outlinepay_result=currency_not_connected', $result );
		$this->assertSame( 'USD', $this->options['woocommerce_currency'] );
		$this->assertArrayNotHasKey( 'woocommerce_price_num_decimals', $this->options );
		$this->assertSame( 'The store is not connected. Nothing changed.', $this->notice_for( 'currency_not_connected' ) );
	}

	public function test_set_currency_on_a_connected_store_writes_both_options() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->options['woocommerce_currency']           = 'USD';
		$this->options['woocommerce_price_num_decimals'] = 2;
		$result = $this->post_set_currency();

		$this->assertStringContainsString( 'outlinepay_result=currency_set', $result );
		$this->assertSame( 'KRW', $this->options['woocommerce_currency'] );
		$this->assertSame( 0, $this->options['woocommerce_price_num_decimals'] );
		$this->currency = 'KRW';
		$this->assertSame( 'Store currency set to KRW. Prices kept their numbers. Check your product prices.', $this->notice_for( 'currency_set' ) );
	}

	/** The HTML of the notice the page shows for a result key. */
	private function notice_html( $key ) {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET['outlinepay_result'] = $key;
		$html                      = $this->render_private( 'render_notice' );
		unset( $_GET['outlinepay_result'] );
		return $html;
	}

	/** The text of the notice the page shows for a result key. */
	private function notice_for( $key ) {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET['outlinepay_result'] = $key;
		$html                      = $this->render_private( 'render_notice' );
		unset( $_GET['outlinepay_result'] );
		return trim( strip_tags( $html ) );
	}

	// ---- setup screen and Fix all ----

	const NOW = 1790000000;

	/** @var array Fact overrides for the injected Self_Check. */
	private $facts = array();

	/** @var mixed What POST /self_checks answers, or an Api_Error to throw. */
	private $self_check_answer;

	/** @var string[] Calls to as_schedule_recurring_action, by hook. */
	private $scheduled = array();

	/**
	 * A connected store with an injected Self_Check: facts from $this->facts
	 * over a healthy store, the network routed by path.
	 */
	private function connected_store() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->requests = array();
		$this->self_check_answer = array(
			'status' => 201,
			'body'   => json_encode( array( 'id' => 'chk_Server1', 'checks' => array() ) ),
		);
		$facts     = &$this->facts;
		$currency  = &$this->currency;
		$options   = &$this->options;
		$requests  = &$this->requests;
		$sc_answer = &$this->self_check_answer;
		$scheduled = &$this->scheduled;
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( $time, $interval, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return 1;
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		$transport = static function ( $method, $url ) use ( &$requests, &$sc_answer ) {
			$requests[] = $method . ' ' . $url;
			if ( false !== strpos( $url, '/self_checks' ) ) {
				if ( $sc_answer instanceof Api_Error ) {
					throw $sc_answer;
				}
				return $sc_answer;
			}
			return array(
				'status' => 200,
				'body'   => json_encode( array( 'status' => 'connected', 'currencies' => array( 'KRW' ) ) ),
			);
		};
		Admin_Page::set_self_check(
			static function () use ( &$facts, &$currency, &$options, $transport ) {
				return new Self_Check(
					static function () use ( &$facts, &$currency, &$options ) {
						// WooCommerce reads the currency option: a switch shows at once.
						return array_merge( self::healthy_facts( isset( $options['woocommerce_currency'] ) ? $options['woocommerce_currency'] : $currency ), $facts );
					},
					new Api_Client(
						static function () {
							return 'olp_it_test_token';
						},
						$transport,
						'https://api.example.test/plugin/v1'
					),
					static function () {
						return self::NOW;
					}
				);
			}
		);
	}

	private static function healthy_facts( $currency ) {
		return array(
			'has_installation'     => true,
			'credentials_stored'   => true,
			'credentials_readable' => true,
			'status'               => 'connected',
			'is_copy'              => false,
			'as_available'         => true,
			'jobs'                 => array(
				Scheduler::HOOK_RECONCILE => self::NOW + 600,
				Scheduler::HOOK_METHODS   => self::NOW + 1800,
			),
			'cron_disabled'        => false,
			'as_ran_recently'      => true,
			'methods_fetched_at'   => self::NOW - 600,
			'methods_enabled'      => 1,
			'methods_shown'        => 1,
			'currency'             => $currency,
			'provider_currencies'  => array( 'KRW' ),
			'provider'             => 'CookiePay',
			'php'                  => '8.3.6',
			'wp'                   => '7.1.3',
			'wc'                   => '11.2.1',
			'min_php'              => '7.4',
			'min_wp'               => '6.2',
			'min_wc'               => '9.9',
			'hpos'                 => 'declared',
		);
	}

	/** The server answer to POST /self_checks: it echoes the rows it got. */
	private function server_echoes( array $extra = array() ) {
		$this->self_check_answer = null;
		$requests                = &$this->requests;
		$extra_rows              = $extra;
		// Rebuild the engine so its transport echoes the posted body.
		$facts     = &$this->facts;
		$currency  = &$this->currency;
		$options   = &$this->options;
		$transport = static function ( $method, $url, $headers, $body ) use ( &$requests, $extra_rows ) {
			$requests[] = $method . ' ' . $url;
			if ( false !== strpos( $url, '/self_checks' ) ) {
				$sent = json_decode( $body, true );
				return array(
					'status' => 201,
					'body'   => json_encode(
						array(
							'id'     => 'chk_Echo1',
							'checks' => array_merge( $sent['checks'], $extra_rows ),
						)
					),
				);
			}
			return array(
				'status' => 200,
				'body'   => json_encode( array( 'status' => 'connected' ) ),
			);
		};
		Admin_Page::set_self_check(
			static function () use ( &$facts, &$currency, &$options, $transport ) {
				return new Self_Check(
					static function () use ( &$facts, &$currency, &$options ) {
						// WooCommerce reads the currency option: a switch shows at once.
						return array_merge( self::healthy_facts( isset( $options['woocommerce_currency'] ) ? $options['woocommerce_currency'] : $currency ), $facts );
					},
					new Api_Client(
						static function () {
							return 'olp_it_test_token';
						},
						$transport,
						'https://api.example.test/plugin/v1'
					),
					static function () {
						return self::NOW;
					}
				);
			}
		);
	}

	/**
	 * Runs a form handler as admin-post.php would: the redirect URL, or the
	 * wp_die / nonce refusal.
	 */
	private function post( $handler, $nonce_action, $can = true, $nonce_ok = true ) {
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( $nonce_action, $nonce_ok ) {
				if ( $nonce_action !== $action || ! $nonce_ok ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return 1;
			}
		);
		Admin_Page::set_redirect(
			static function ( $url ) {
				throw new \RuntimeException( 'redirect ' . $url );
			}
		);
		try {
			call_user_func( array( Admin_Page::class, $handler ) );
		} catch ( \RuntimeException $stop ) {
			return $stop->getMessage();
		}
		$this->fail( 'the handler must stop' );
	}

	public function test_fix_all_needs_the_capability() {
		$this->connected_store();
		$this->currency = 'USD';
		$this->assertSame( 'wp_die', $this->post( 'fix_all', 'outlinepay_fix_all', false ) );
		$this->assertArrayNotHasKey( 'woocommerce_currency', $this->options );
		$this->assertSame( array(), $this->requests );
	}

	public function test_fix_all_needs_a_good_nonce() {
		$this->connected_store();
		$this->currency = 'USD';
		$this->assertSame( 'bad nonce', $this->post( 'fix_all', 'outlinepay_fix_all', true, false ) );
		$this->assertArrayNotHasKey( 'woocommerce_currency', $this->options );
		$this->assertSame( array(), $this->requests );
	}

	public function test_fix_all_on_a_store_that_is_not_connected_changes_nothing() {
		$this->currency = 'USD';
		$result         = $this->post( 'fix_all', 'outlinepay_fix_all' );
		$this->assertStringContainsString( 'outlinepay_result=fix_not_connected', $result );
		$this->assertArrayNotHasKey( 'woocommerce_currency', $this->options );
		$this->assertSame( array(), $this->requests );
	}

	public function test_fix_all_applies_only_the_fixes_that_are_needed() {
		$this->connected_store();
		$this->server_echoes();
		$this->post( 'fix_all', 'outlinepay_fix_all' );

		$this->assertArrayNotHasKey( 'woocommerce_currency', $this->options, 'KRW already: no switch' );
		$this->assertArrayNotHasKey( Scheduler::OPTION_CURSOR, $this->options, 'jobs fine: no reconcile' );
		$this->assertSame( 0, count( preg_grep( '#/methods$#', $this->requests ) ), 'methods fresh: no refresh' );
		$this->assertSame( 1, count( preg_grep( '#POST .*/self_checks$#', $this->requests ) ), 'the checks ran again' );
	}

	public function test_fix_all_switches_the_currency_runs_the_jobs_and_refreshes_the_methods() {
		$this->connected_store();
		$this->server_echoes();
		$this->currency = 'USD';
		$this->facts    = array(
			'jobs'               => array(),
			'methods_fetched_at' => 0,
		);
		$currency = &$this->currency;
		$options  = &$this->options;
		Functions\when( 'get_woocommerce_currency' )->alias(
			static function () use ( &$currency, &$options ) {
				return isset( $options['woocommerce_currency'] ) ? $options['woocommerce_currency'] : $currency;
			}
		);

		$result = $this->post( 'fix_all', 'outlinepay_fix_all' );

		$this->assertSame( 'KRW', $this->options['woocommerce_currency'] );
		$this->assertSame( 0, $this->options['woocommerce_price_num_decimals'] );
		$this->assertArrayHasKey( Scheduler::OPTION_CURSOR, $this->options, 'reconcile ran' );
		$this->assertSame( 1, count( preg_grep( '#GET https://api\.outlinepay\.com/plugin/v1/methods$#', $this->requests ) ), 'methods refreshed' );
		$this->assertStringContainsString( 'outlinepay_result=setup_pending', $result, 'the facts still say the jobs are missing' );
		$this->assertSame( '2 checks still need you. Follow the step in each row.', $this->notice_for( 'setup_pending' ), 'jobs and methods' );
		$this->assertStringContainsString( 'notice-warning', $this->notice_html( 'setup_pending' ), 'not green while a check fails' );
	}

	public function test_fix_all_that_completes_setup_says_so() {
		$this->connected_store();
		$this->server_echoes();
		$this->currency = 'USD';
		$this->options['outlinepay_self_check_last'] = Self_Check::offline( Self_Check::local_checks( self::healthy_facts( 'USD' ), self::NOW ), 'page', self::NOW );
		$this->assertFalse( Setup::is_done() );

		$result = $this->post( 'fix_all', 'outlinepay_fix_all' );

		$this->assertStringContainsString( 'outlinepay_result=setup_done', $result );
		$this->assertTrue( Setup::is_done() );
		$this->assertSame( 'Setup is complete. Your payment methods now show at checkout.', $this->notice_for( 'setup_done' ) );
	}

	public function test_fix_all_within_the_rate_limit_uses_the_fresh_local_rows() {
		$this->connected_store();
		$this->options['outlinepay_self_check_last'] = Self_Check::from_server(
			array(
				'id'     => 'chk_First',
				'checks' => array_merge(
					Self_Check::wire( Self_Check::local_checks( self::healthy_facts( 'USD' ), self::NOW - 20 ) ),
					array(
						array( 'code' => 'api_reachable', 'ok' => true, 'side' => 'store', 'detail' => array( 'elapsed_ms' => '80' ) ),
						array( 'code' => 'callback_reachable', 'ok' => true, 'side' => 'store' ),
					)
				),
			),
			'page',
			self::NOW - 20
		);
		$this->self_check_answer = new Api_Error( 429, 'rate_limited' );
		$this->currency          = 'USD';

		$result = $this->post( 'fix_all', 'outlinepay_fix_all' );

		$this->assertStringContainsString( 'outlinepay_result=setup_done', $result, 'the merged result decides' );
		$last = Self_Check::last();
		$this->assertSame( 'chk_First', $last['code'] );
		$this->assertSame( 0, $last['failed_count'] );
		$this->assertContains( 'callback_reachable', array_column( $last['checks'], 'code' ) );
	}

	/** A stored online result: every local row passed, plus $extra server rows, answered at $at. */
	private function stored_online( array $extra, $at ) {
		$this->options['outlinepay_self_check_last'] = Self_Check::from_server(
			array(
				'id'     => 'chk_First',
				'checks' => array_merge(
					Self_Check::wire( Self_Check::local_checks( self::healthy_facts( 'KRW' ), $at ) ),
					array( array( 'code' => 'api_reachable', 'ok' => true, 'side' => 'store', 'detail' => array( 'elapsed_ms' => '80' ) ) ),
					$extra
				),
			),
			'page',
			$at
		);
	}

	// ---- Run check and the rate limit ----

	public function test_run_check_says_it_finished_when_it_ran() {
		$this->connected_store();
		$this->server_echoes();
		$this->options[ Setup::OPTION_DONE ] = self::NOW;

		$result = $this->post( 'self_check', 'outlinepay_self_check' );

		$this->assertStringContainsString( 'outlinepay_result=self_check_done', $result );
		$this->assertSame( 'The check finished. The results are below.', $this->notice_for( 'self_check_done' ) );
	}

	public function test_run_check_right_after_a_queued_run_says_it_finished() {
		// A run queued by a change ran on the page load 20 seconds ago.
		$this->connected_store();
		$this->options[ Setup::OPTION_DONE ] = self::NOW;
		$this->stored_online( array(), self::NOW - 20 );
		$this->self_check_answer = new Api_Error( 429, 'rate_limited' );

		$result = $this->post( 'self_check', 'outlinepay_self_check' );

		$this->assertStringContainsString( 'outlinepay_result=self_check_done', $result, 'the results are a minute fresh' );
		$this->assertSame( self::NOW, Self_Check::last()['ran_at'], 'the local rows ran now' );
		$this->assertSame( self::NOW - 20, Self_Check::last()['answered_at'], 'the server rows keep their time' );
	}

	public function test_run_check_without_fresh_server_rows_says_a_check_ran_less_than_a_minute_ago() {
		$this->connected_store();
		$this->options[ Setup::OPTION_DONE ] = self::NOW;
		$this->self_check_answer = new Api_Error( 429, 'rate_limited' );

		// The other run is still going: what is stored is older.
		$this->stored_online( array(), self::NOW - 600 );
		$this->assertStringContainsString( 'outlinepay_result=self_check_rate_limited', $this->post( 'self_check', 'outlinepay_self_check' ) );

		// Nothing stored at all.
		unset( $this->options['outlinepay_self_check_last'] );
		$this->assertStringContainsString( 'outlinepay_result=self_check_rate_limited', $this->post( 'self_check', 'outlinepay_self_check' ) );

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET['outlinepay_result'] = 'self_check_rate_limited';
		$html                      = $this->render_private( 'render_rate_limited_notice' );
		unset( $_GET['outlinepay_result'] );
		$this->assertStringContainsString( 'A check ran less than a minute ago.', $html );
	}

	public function test_an_hourly_record_keeps_the_time_outlinepay_answered() {
		$this->stored_online( array(), self::NOW - 3600 );
		$merged = Self_Check::merge( Self_Check::last(), Self_Check::local_checks( self::healthy_facts( 'KRW' ), self::NOW ), 'hourly', self::NOW );
		$this->assertSame( self::NOW - 3600, $merged['answered_at'] );
		$this->assertFalse( Self_Check::answered_recently( $merged, self::NOW ) );
		$offline = Self_Check::offline( Self_Check::local_checks( self::healthy_facts( 'KRW' ), self::NOW ), 'page', self::NOW );
		$this->assertFalse( Self_Check::answered_recently( $offline, self::NOW ) );
	}

	// ---- blocking and advisory checks ----

	public function test_the_check_table_has_a_blocking_group_and_an_advisory_group() {
		$this->stored_online(
			array(
				array( 'code' => 'callback_reachable', 'ok' => false, 'side' => 'store', 'severity' => 'advisory' ),
				array( 'code' => 'open_problems_7d', 'ok' => true, 'side' => 'store' ),
			),
			self::NOW
		);
		Functions\when( 'wp_date' )->justReturn( '2026-10-23 10:00' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );

		$html = $this->render_private( 'render_self_check' );

		$blocking = strpos( $html, 'Needed to take payments</h3>' );
		$advisory = strpos( $html, 'Keeps things running smoothly</h3>' );
		$this->assertNotFalse( $blocking );
		$this->assertGreaterThan( $blocking, $advisory, 'blocking first' );
		$this->assertSame( 2, substr_count( $html, 'Who acts next' ), 'each group keeps the columns' );
		list( $top, $bottom ) = explode( 'Keeps things running smoothly', $html );
		$this->assertSame( 9, substr_count( $top, '<tr class="outlinepay-check-' ), 'the nine local rows' );
		$this->assertSame( 2, substr_count( $bottom, '<tr class="outlinepay-check-' ) );
		$this->assertLessThan( strpos( $top, 'The store currency is KRW.' ), strpos( $top, 'The store is connected.' ), 'result order inside a group' );
		$this->assertStringContainsString( 'Until this passes, orders paid by shoppers who close the window update within 15 minutes instead of right away.', $bottom );
		$this->assertLessThan( strpos( $bottom, 'No open problems' ), strpos( $bottom, 'payment update address' ), 'result order inside a group' );
	}

	public function test_a_result_with_only_local_rows_shows_one_group() {
		$this->options['outlinepay_self_check_last'] = Self_Check::offline( Self_Check::local_checks( self::healthy_facts( 'KRW' ), self::NOW ), 'page', self::NOW );
		Functions\when( 'wp_date' )->justReturn( '2026-10-23 10:00' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );

		$html = $this->render_private( 'render_self_check' );

		$this->assertStringContainsString( 'Needed to take payments</h3>', $html );
		$this->assertStringNotContainsString( 'Keeps things running smoothly', $html );
	}

	public function test_setup_with_a_failing_advisory_check_says_how_many_need_attention() {
		$this->connected_store();
		$this->server_echoes(
			array(
				array( 'code' => 'callback_reachable', 'ok' => false, 'side' => 'store', 'severity' => 'advisory' ),
			)
		);

		$result = $this->post( 'fix_all', 'outlinepay_fix_all' );

		$this->assertStringContainsString( 'outlinepay_result=setup_done', $result, 'advisory rows never block setup' );
		$this->assertTrue( Setup::is_done() );
		$this->assertSame( 'Setup is complete and your payment methods show at checkout. 1 recommended check needs attention.', $this->notice_for( 'setup_done' ) );
		$this->assertStringContainsString( 'notice-warning', $this->notice_html( 'setup_done' ) );
	}

	public function test_the_summary_counts_failing_advisory_checks_in_the_plural() {
		$this->connected_store();
		$this->server_echoes(
			array(
				array( 'code' => 'callback_reachable', 'ok' => false, 'side' => 'store' ),
				array( 'code' => 'deliveries_gave_up_7d', 'ok' => false, 'side' => 'outlinepay', 'severity' => 'advisory' ),
			)
		);
		$this->options[ Setup::OPTION_DONE ] = self::NOW;

		$result = $this->post( 'self_check', 'outlinepay_self_check' );

		$this->assertStringContainsString( 'outlinepay_result=self_check_done', $result );
		$this->assertSame( 'Setup is complete and your payment methods show at checkout. 2 recommended checks need attention.', $this->notice_for( 'self_check_done' ) );
	}

	public function test_a_failing_blocking_check_keeps_the_usual_summary() {
		$this->connected_store();
		$this->server_echoes(
			array(
				array( 'code' => 'callback_reachable', 'ok' => false, 'side' => 'store', 'severity' => 'advisory' ),
			)
		);
		$this->options[ Setup::OPTION_DONE ] = self::NOW;
		$this->facts = array( 'cron_disabled' => true, 'as_ran_recently' => false );

		$this->post( 'self_check', 'outlinepay_self_check' );

		$this->assertSame( 'The check finished. The results are below.', $this->notice_for( 'self_check_done' ) );
		$this->assertStringContainsString( 'notice-success', $this->notice_html( 'self_check_done' ) );
	}

	public function test_a_failing_blocking_check_during_setup_keeps_the_count_of_blocking_checks() {
		$this->connected_store();
		$this->server_echoes(
			array(
				array( 'code' => 'callback_reachable', 'ok' => false, 'side' => 'store', 'severity' => 'advisory' ),
			)
		);
		$this->facts = array( 'cron_disabled' => true, 'as_ran_recently' => false );

		$result = $this->post( 'fix_all', 'outlinepay_fix_all' );

		$this->assertStringContainsString( 'outlinepay_result=setup_pending', $result );
		$this->assertSame( '1 check still needs you. Follow the step in its row.', $this->notice_for( 'setup_pending' ), 'the advisory row is not counted' );
	}

	/** Renders the whole page with the stubs a page view needs. */
	private function render_page( $provider = 'cookiepay' ) {
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
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array_filter( array( 'status' => 'connected', 'provider' => $provider, 'currencies' => array( 'KRW' ), 'data' => array( array( 'method' => 'card', 'label_ko' => '신용카드' ) ) ) ) ),
		);
		ob_start();
		Admin_Page::render();
		return ob_get_clean();
	}

	public function test_before_setup_the_page_is_the_setup_screen() {
		$this->connected_store();
		$this->currency = 'USD';
		$this->options['outlinepay_self_check_last'] = Self_Check::offline(
			array_merge(
				array( Self_Check::check( 'api_reachable', true, 'store', array( 'elapsed_ms' => '80' ) ) ),
				Self_Check::local_checks( self::healthy_facts( 'USD' ), self::NOW - 60 )
			),
			'page',
			self::NOW - 60
		);

		$html = $this->render_page();

		$this->assertStringContainsString( 'Set up OutlinePay', $html );
		$this->assertStringContainsString( 'value="outlinepay_fix_all"', $html );
		$this->assertStringContainsString( 'nonce-outlinepay_fix_all', $html );
		$this->assertStringContainsString( 'value="Fix all"', $html );
		$this->assertStringContainsString( 'value="Check again"', $html );
		$this->assertSame( 9, substr_count( $html, '<tr class="outlinepay-check-' ), 'all nine rows' );
		$this->assertStringContainsString( 'Payment methods show at checkout when every check needed to take payments passes.', $html );
		$this->assertStringContainsString( 'The store currency is USD. CookiePay takes payments in KRW only. Fix all sets the store currency to KRW. Prices keep their numbers.', $html );
		$this->assertStringContainsString( 'Still stuck?', $html );
		$this->assertStringContainsString( 'href="https://www.cookiepayments.com/iroboard/list?bId=contact"', $html );
		$this->assertStringContainsString( 'Contact CookiePay support.</a>', $html );
		$this->assertStringContainsString( 'id="outlinepay-check-code"', $html );
		$this->assertStringNotContainsString( 'Send this code to your payment provider', $html, 'no diagnostic panel on the setup screen' );
		$this->assertStringNotContainsString( 'value="Set store currency to KRW"', $html, 'Fix all covers it' );
		$this->assertStringNotContainsString( 'Payment methods</h2>', $html );
		$this->assertStringNotContainsString( 'Refunds and cancellations', $html );
		$this->assertStringNotContainsString( 'Email me when a daily check fails', $html );
		$this->assertStringContainsString( 'value="outlinepay_disconnect"', $html );
		$this->assertSame( array(), preg_grep( '#/self_checks$#', $this->requests ), 'a stored run: local rows only' );
		$this->assertFalse( Setup::is_done() );
	}

	public function test_opening_the_page_after_a_fix_made_elsewhere_finishes_setup() {
		$this->connected_store();
		$this->options['outlinepay_self_check_last'] = Self_Check::offline(
			array_merge(
				array( Self_Check::check( 'api_reachable', true, 'store', array( 'elapsed_ms' => '80' ) ) ),
				Self_Check::local_checks( self::healthy_facts( 'USD' ), self::NOW - 60 )
			),
			'page',
			self::NOW - 60
		);
		// The merchant switched to KRW in WooCommerce settings.
		$html = $this->render_page();

		$this->assertTrue( Setup::is_done() );
		$this->assertStringContainsString( 'Setup is complete. Your payment methods now show at checkout.', $html );
		$this->assertStringNotContainsString( 'Set up OutlinePay', $html );
		$this->assertStringContainsString( 'Payment methods</h2>', $html );
	}

	public function test_the_first_visit_after_connect_runs_the_full_check() {
		$this->connected_store();
		$this->server_echoes();
		$this->facts = array( 'cron_disabled' => true, 'as_ran_recently' => false );
		$_GET['outlinepay_result'] = 'connected';
		$html                      = $this->render_page();
		unset( $_GET['outlinepay_result'] );

		$this->assertCount( 1, preg_grep( '#POST .*/self_checks$#', $this->requests ) );
		$this->assertStringContainsString( 'Your store is connected. Finish setup below.', $html );
		$this->assertStringNotContainsString( 'What&#039;s next', $html );
		$this->assertStringContainsString( 'Ask your host to run wp-cron.php from a server cron job.', $html );
		$this->assertStringNotContainsString( 'value="Run now"', $html );
		$this->assertFalse( Setup::is_done(), 'WP-Cron blocks too' );
	}

	public function test_after_setup_the_page_is_the_connected_view() {
		$this->connected_store();
		$this->options[ Setup::OPTION_DONE ] = self::NOW;
		$this->options['outlinepay_self_check_last'] = Self_Check::offline( Self_Check::local_checks( self::healthy_facts( 'KRW' ), self::NOW ), 'daily', self::NOW );

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'Set up OutlinePay', $html );
		$this->assertStringContainsString( 'Self-check</h2>', $html );
		$this->assertStringContainsString( 'Payment methods</h2>', $html );
		$this->assertStringContainsString( 'Refunds and cancellations', $html );
		$this->assertStringContainsString( '<th scope="row" style="width:200px">Payment provider</th><td>CookiePay</td>', $html );
		$this->assertStringContainsString( '<th scope="row" style="width:200px">Plugin</th><td>OutlinePay for WooCommerce ' . OUTLINEPAY_VERSION . '</td>', $html );
		$this->assertStringContainsString( 'outlinepay-wordmark', $html );
		$this->assertSame( array(), preg_grep( '#/self_checks$#', $this->requests ), 'no run on a plain visit' );
	}

	public function test_check_again_with_checks_still_failing_warns_with_the_count() {
		$this->connected_store();
		$this->server_echoes();
		$this->facts = array(
			'cron_disabled'   => true,
			'as_ran_recently' => false,
		);
		$result = $this->post( 'self_check', 'outlinepay_self_check' );

		$this->assertStringContainsString( 'outlinepay_result=setup_pending', $result );
		$html = $this->notice_html( 'setup_pending' );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( 'notice-success', $html );
		$this->assertSame( '1 check still needs you. Follow the step in its row.', trim( strip_tags( $html ) ), 'WP-Cron only' );
	}

	public function test_check_again_that_completes_setup_says_so() {
		$this->connected_store();
		$this->server_echoes();
		$result = $this->post( 'self_check', 'outlinepay_self_check' );
		$this->assertStringContainsString( 'outlinepay_result=setup_done', $result );
	}

	public function test_the_email_box_is_ticked_until_the_merchant_turns_it_off() {
		$this->options['admin_email'] = 'owner@shop.example';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current ) {
				return $checked === $current ? ' checked=\'checked\'' : '';
			}
		);
		$box = '/name="outlinepay_self_check_email" value="yes" checked/';
		$this->assertMatchesRegularExpression( $box, $this->render_private( 'render_self_check' ), 'never saved: on' );
		$this->options[ Self_Check::OPTION_EMAIL ] = 'no';
		$this->assertDoesNotMatchRegularExpression( $box, $this->render_private( 'render_self_check' ) );
	}

	public function test_the_currency_warning_shows_on_the_connected_view() {
		Admin_Page::finish_connect( array_merge( self::answer(), array( 'currencies' => array( 'KRW' ) ) ) );
		$this->currency = 'USD';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			static function ( $text ) {
				echo '<input type="submit" value="' . $text . '">';
			}
		);
		$warning = $this->render_private( 'render_currency_warning' );
		$this->assertStringContainsString( 'Your store currency is USD. CookiePay takes payments in KRW only. Prices keep their numbers.', $warning );
		$this->assertStringContainsString( 'Set store currency to KRW', $warning );
		$this->currency = 'KRW';
		$this->assertSame( '', $this->render_private( 'render_currency_warning' ) );
	}

	/** A store whose provider sends methods grouped by contract. */
	private function store_with_contracts() {
		$this->options['outlinepay_methods'] = self::methods_option();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	public function test_saving_methods_saves_the_shown_set_and_the_pg_choice() {
		$this->store_with_contracts();
		$_POST['outlinepay_shown']   = array( 'card', 'kakaopay' );
		$_POST['outlinepay_offered'] = array( 'card', 'kakaopay', 'foreign_card_krw' );
		$_POST['outlinepay_pg']      = array( '3' => '토스페이' );

		$result = $this->post( 'save_methods', 'outlinepay_methods' );
		unset( $_POST['outlinepay_shown'], $_POST['outlinepay_offered'], $_POST['outlinepay_pg'] );

		$this->assertStringContainsString( 'outlinepay_result=methods_saved', $result );
		$this->assertSame( '토스페이', Settings::pg_for_contract( 3 ) );
		$this->assertTrue( Settings::shown( 'card' ) );
		$this->assertTrue( Settings::shown( 'kakaopay' ) );
		$this->assertFalse( Settings::shown( 'foreign_card_krw' ) );
		$this->assertArrayNotHasKey( 'woocommerce_outlinepay_naverpay_settings', $this->options, 'not on the form for Toss: left as it was' );
		$this->assertFalse( Settings::method_enabled_by_server( 'naverpay' ) );
	}

	public function test_saving_methods_ignores_a_pg_the_contract_does_not_list() {
		$this->store_with_contracts();
		$this->options['outlinepay_pg'] = array( '3' => '토스페이' );
		$_POST['outlinepay_shown']      = array( 'card' );
		$_POST['outlinepay_pg']         = array(
			'3' => 'NicePay',
			'7' => array( 'not a string' ),
		);

		$result = $this->post( 'save_methods', 'outlinepay_methods' );
		unset( $_POST['outlinepay_shown'], $_POST['outlinepay_pg'] );

		$this->assertStringContainsString( 'outlinepay_result=methods_saved', $result );
		$this->assertSame( '토스페이', Settings::pg_for_contract( 3 ) );
		$this->assertSame( '키움페이', Settings::pg_for_contract( 7 ) );
	}

	public function test_saving_methods_needs_the_capability_and_a_good_nonce() {
		$this->store_with_contracts();
		$_POST['outlinepay_pg'] = array( '3' => '토스페이' );
		$this->assertSame( 'wp_die', $this->post( 'save_methods', 'outlinepay_methods', false ) );
		$this->assertSame( 'bad nonce', $this->post( 'save_methods', 'outlinepay_methods', true, false ) );
		unset( $_POST['outlinepay_pg'] );
		$this->assertArrayNotHasKey( 'outlinepay_pg', $this->options );
	}

	public function test_saving_methods_without_contracts_works_as_before() {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$this->options['outlinepay_methods'] = array(
			'methods' => array(
				array( 'method' => 'card', 'label_ko' => '' ),
				array( 'method' => 'naverpay', 'label_ko' => '' ),
			),
		);
		$_POST['outlinepay_shown'] = array( 'card' );

		$this->post( 'save_methods', 'outlinepay_methods' );
		unset( $_POST['outlinepay_shown'] );

		$this->assertTrue( Settings::shown( 'card' ) );
		$this->assertFalse( Settings::shown( 'naverpay' ) );
		$this->assertArrayNotHasKey( 'outlinepay_pg', $this->options );
	}

	public function test_the_methods_section_groups_methods_by_contract_with_a_pg_choice() {
		$this->store_with_contracts();
		$this->options['outlinepay_pg'] = array( '3' => '토스페이' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current ) {
				return $checked === $current ? ' checked=\'checked\'' : '';
			}
		);

		$html = $this->render_private( 'render_methods' );

		$this->assertStringContainsString( 'grouped by contract. Choose which to show at checkout. Where a contract runs on more than one payment gateway, choose the one to use.', $html );
		$this->assertStringContainsString( '<strong>3. Authenticated general</strong>', $html );
		$this->assertStringContainsString( '<strong>7. Authenticated overseas card (KRW)</strong>', $html );
		$this->assertSame( 2, substr_count( $html, 'type="radio"' ), 'one radio per pg, contract 7 has none' );
		$this->assertStringContainsString( 'name="outlinepay_pg[3]" value="토스페이" data-outlinepay-pg-choice data-contract="3" checked', $html );
		$this->assertStringContainsString( '<span style="color:#646970">PG</span>', $html );
		$this->assertStringNotContainsString( 'outlinepay_pg[7]', $html );
		// Kiwoom's rows are in the page, hidden and disabled. Toss's are live.
		$this->assertMatchesRegularExpression( '#<tr data-outlinepay-pg-rows data-contract="3" data-pg="키움페이" style="display:none">.*?value="naverpay"[^>]* disabled>#u', $html );
		$this->assertMatchesRegularExpression( '#<tr data-outlinepay-pg-rows data-contract="3" data-pg="토스페이"><td></td>.*?value="kakaopay"[^>]*>#u', $html );
		$this->assertDoesNotMatchRegularExpression( '#data-pg="토스페이"><td></td>[^\n]*?value="kakaopay"[^>]* disabled>#u', $html );
		$this->assertSame( 1, preg_match_all( '#type="checkbox"[^>]*value="naverpay"#', $html ), 'Toss has no NaverPay row' );
		$this->assertStringContainsString( '<input type="hidden" name="outlinepay_offered[]" value="naverpay" disabled>', $html, 'disabled with its box' );
		$this->assertStringContainsString( '<input type="hidden" name="outlinepay_offered[]" value="kakaopay">', $html );
		$this->assertStringContainsString( 'value="outlinepay_methods"', $html );
	}

	public function test_the_methods_section_without_contracts_is_the_flat_table() {
		$this->options['outlinepay_methods'] = array( 'methods' => array( array( 'method' => 'card', 'label_ko' => '신용카드' ) ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );

		$html = $this->render_private( 'render_methods' );

		$this->assertStringContainsString( 'You can hide any of them. To add a method, ask your payment provider.', $html );
		$this->assertStringContainsString( 'id="outlinepay_shown_card"', $html );
		$this->assertStringNotContainsString( 'type="radio"', $html );
	}
}
