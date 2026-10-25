<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Scheduler;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Self_Check_Watch;
use OutlinePay\WooCommerce\Settings;
use OutlinePay\WooCommerce\Site_Identity;

/**
 * The self-check after a change, the hourly record and the admin notice.
 */
class SelfCheckWatchTest extends TestCase {

	const NOW = 1790000000;

	/** @var array Options in memory. */
	private $options = array();

	/** @var string[] Hooks queued with as_enqueue_async_action. */
	private $queued = array();

	/** @var string[] Requests the fake transport received. */
	private $requests = array();

	/** @var array Facts over a healthy store. */
	private $facts = array();

	/** @var array Fact overrides the last engine got from the watch. */
	private $override = array();

	/** @var array Rows the fake POST /self_checks answer carries. */
	private $server_rows = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Credentials::reset_cache();
		$options  = &$this->options;
		$queued   = &$this->queued;
		$requests = &$this->requests;
		$facts    = &$this->facts;
		$override = &$this->override;
		$rows     = &$this->server_rows;
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
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.com/' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( $hook ) use ( &$queued ) {
				return in_array( $hook, $queued, true );
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook ) use ( &$queued ) {
				$queued[] = $hook;
				return 1;
			}
		);

		Credentials::save( 'olp_it_test_TOKEN', 'olp_isec_SECRET' );
		Credentials::reset_cache();
		$this->options[ Settings::OPTION_INSTALLATION ] = array(
			'id'     => 'inst_test',
			'status' => 'connected',
		);
		$this->options[ Site_Identity::OPTION ]         = 'https://shop.example.com/';

		$this->facts = self::healthy_facts();
		$this->server_rows = array(
			array(
				'code' => 'store_currency',
				'ok'   => true,
				'side' => 'store',
			),
		);
		$transport         = static function ( $method, $url ) use ( &$requests, &$rows ) {
			$requests[] = $method . ' ' . $url;
			if ( false !== strpos( $url, '/self_checks' ) ) {
				return array(
					'status' => 201,
					'body'   => json_encode(
						array(
							'id'     => 'chk_Watch1',
							'checks' => $rows,
						)
					),
				);
			}
			return array(
				'status' => 200,
				'body'   => json_encode( array( 'status' => 'connected' ) ),
			);
		};
		Self_Check_Watch::set_self_check(
			static function ( array $extra ) use ( &$facts, &$override, $transport ) {
				$override = $extra;
				return new Self_Check(
					static function () use ( &$facts, $extra ) {
						return array_merge( $facts, $extra );
					},
					new Api_Client(
						static function () {
							return 'olp_it_test_TOKEN';
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

	protected function tearDown(): void {
		Self_Check_Watch::set_self_check( null );
		Credentials::reset_cache();
		unset( $_GET['page'] );
		parent::tearDown();
	}

	private static function healthy_facts() {
		return array(
			'has_installation'     => true,
			'credentials_stored'   => true,
			'credentials_readable' => true,
			'status'               => 'connected',
			'is_copy'              => false,
			'as_available'         => true,
			'jobs'                 => array(
				Scheduler::HOOK_RECONCILE => self::NOW + 600,
				Scheduler::HOOK_METHODS   => true,
			),
			'cron_disabled'        => false,
			'as_ran_recently'      => true,
			'methods_fetched_at'   => self::NOW - 60,
			'methods_enabled'      => 1,
			'methods_shown'        => 1,
			'currency'             => 'KRW',
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

	/** How many runs reached OutlinePay (POST /self_checks). */
	private function posted_runs() {
		return count(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return 0 === strpos( $request, 'POST ' ) && false !== strpos( $request, '/self_checks' );
				}
			)
		);
	}

	/** The queued action runs, as Action Scheduler would run it. */
	private function run_queued() {
		foreach ( $this->queued as $hook ) {
			$this->assertSame( Scheduler::HOOK_CHECK_NOW, $hook );
			Self_Check_Watch::run_now();
		}
		$this->queued = array();
	}

	private function notice() {
		ob_start();
		Self_Check_Watch::notice();
		return ob_get_clean();
	}

	public function test_register_hooks_every_listener_and_the_notice() {
		Self_Check_Watch::register();
		$this->assertNotFalse( has_action( 'update_option_woocommerce_currency', array( Self_Check_Watch::class, 'changed' ) ) );
		$this->assertNotFalse( has_action( 'update_option_siteurl', array( Self_Check_Watch::class, 'changed' ) ) );
		$this->assertNotFalse( has_action( 'update_option_home', array( Self_Check_Watch::class, 'changed' ) ) );
		$this->assertNotFalse( has_action( 'deactivated_plugin', array( Self_Check_Watch::class, 'plugin_deactivated' ) ) );
		$this->assertNotFalse( has_action( 'upgrader_process_complete', array( Self_Check_Watch::class, 'upgraded' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Self_Check_Watch::class, 'notice' ) ) );
	}

	public function test_scheduler_hooks_the_queued_run_and_the_hourly_record() {
		Scheduler::register();
		$this->assertNotFalse( has_action( Scheduler::HOOK_CHECK_NOW, array( Self_Check_Watch::class, 'run_now' ) ) );
		$this->assertNotFalse( has_action( Scheduler::HOOK_METHODS, array( Scheduler::class, 'hourly' ) ) );
		$this->assertContains( Scheduler::HOOK_CHECK_NOW, Scheduler::hooks(), 'unscheduled on deactivation' );
	}

	public function test_an_option_change_runs_the_check_once() {
		// The currency, siteurl and home hooks all call changed().
		Self_Check_Watch::changed();
		$this->assertSame( array(), $this->requests, 'nothing runs inside the request' );
		Self_Check_Watch::flush();
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
		$this->run_queued();
		$this->assertSame( 1, $this->posted_runs() );
		$this->assertSame( 'chk_Watch1', Self_Check::last()['code'] );
		$this->assertSame( 'page', Self_Check::last()['trigger'], 'recorded like the page run' );
	}

	public function test_a_woocommerce_update_runs_the_check_once() {
		Self_Check_Watch::upgraded(
			null,
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'plugins' => array( 'akismet/akismet.php', 'woocommerce/woocommerce.php' ),
			)
		);
		Self_Check_Watch::flush();
		$this->run_queued();
		$this->assertSame( 1, $this->posted_runs() );
	}

	public function test_a_single_woocommerce_update_counts_too() {
		Self_Check_Watch::upgraded(
			null,
			array(
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => 'woocommerce/woocommerce.php',
			)
		);
		Self_Check_Watch::flush();
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
	}

	public function test_other_updates_and_deactivations_run_nothing() {
		Self_Check_Watch::upgraded(
			null,
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'plugins' => array( 'akismet/akismet.php' ),
			)
		);
		Self_Check_Watch::upgraded(
			null,
			array(
				'type'   => 'theme',
				'action' => 'update',
			)
		);
		Self_Check_Watch::upgraded( null, array( 'type' => 'plugin', 'action' => 'install' ) );
		Self_Check_Watch::plugin_deactivated( 'akismet/akismet.php' );
		Self_Check_Watch::flush();
		$this->assertSame( array(), $this->queued );
		$this->assertSame( array(), $this->requests );
	}

	public function test_woocommerce_deactivation_runs_once_now_with_woocommerce_missing() {
		Self_Check_Watch::plugin_deactivated( 'woocommerce/woocommerce.php' );
		Self_Check_Watch::flush();

		$this->assertSame( 1, $this->posted_runs(), 'one run, in this request' );
		$this->assertSame( array( 'wc' => '' ), $this->override );
		$this->assertSame( 'chk_Watch1', Self_Check::last()['code'] );
		// The queued action waits while WooCommerce is off and runs when it is back.
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
	}

	public function test_several_changes_in_one_request_run_the_check_once() {
		Actions\expectAdded( 'shutdown' )->once();
		Self_Check_Watch::changed();
		Self_Check_Watch::changed();
		Self_Check_Watch::changed();
		Self_Check_Watch::upgraded(
			null,
			array(
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => 'woocommerce/woocommerce.php',
			)
		);
		Self_Check_Watch::flush();
		Self_Check_Watch::flush();
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
		$this->run_queued();
		$this->assertSame( 1, $this->posted_runs() );
	}

	public function test_a_run_already_waiting_is_not_queued_twice() {
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		// A later request, before Action Scheduler ran the first.
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
	}

	public function test_a_front_end_request_queues_nothing() {
		Functions\when( 'is_admin' )->justReturn( false );
		Actions\expectAdded( 'shutdown' )->never();
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		$this->assertSame( array(), $this->queued );
	}

	public function test_cron_queues_the_run() {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
	}

	public function test_a_store_that_is_not_connected_queues_nothing() {
		unset( $this->options[ Settings::OPTION_INSTALLATION ] );
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		$this->assertSame( array(), $this->queued );
		Self_Check_Watch::run_now();
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_hourly_record_stores_a_failing_currency_without_calling_outlinepay() {
		$this->facts['currency'] = 'USD';
		Self_Check_Watch::record_local();

		$this->assertSame( array(), $this->requests );
		$last = Self_Check::last();
		$this->assertSame( 'hourly', $last['trigger'] );
		$this->assertSame( self::NOW, $last['ran_at'] );
		$this->assertSame( 1, $last['failed_count'] );
		$this->assertSame( 'OP-OFFLINE-G', $last['code'] );
	}

	public function test_the_hourly_action_refreshes_the_methods_then_records() {
		// Revoked: no refresh call, and the record shows the connection is off.
		$this->options[ Settings::OPTION_INSTALLATION ]['status'] = 'revoked';
		$this->facts['status']                                    = 'revoked';
		Scheduler::hourly();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'OP-OFFLINE-B', Self_Check::last()['code'] );
		$this->assertStringContainsString( 'payment methods are not showing at checkout', $this->notice() );
	}

	public function test_the_hourly_record_keeps_the_stored_online_code() {
		Self_Check_Watch::run_now();
		$this->facts['methods_shown'] = 0;
		Self_Check_Watch::record_local();
		$last = Self_Check::last();
		$this->assertSame( 'chk_Watch1', $last['code'] );
		$this->assertSame( 1, $last['failed_count'] );
		$this->assertSame( 1, $this->posted_runs() );
	}

	public function test_the_notice_shows_only_while_the_last_run_fails() {
		$this->assertSame( '', $this->notice(), 'no run yet' );

		$this->facts['currency'] = 'USD';
		Self_Check_Watch::record_local();
		$html = $this->notice();
		$this->assertStringContainsString( 'OutlinePay: payment methods are not showing at checkout.', $html );
		$this->assertStringContainsString( 'Open the OutlinePay page to fix it.', $html );
		$this->assertStringContainsString( 'href="https://shop.example.com/wp-admin/admin.php?page=outlinepay"', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html );

		$this->facts['currency'] = 'KRW';
		Self_Check_Watch::run_now();
		$this->assertSame( 0, Self_Check::last()['failed_count'] );
		$this->assertSame( '', $this->notice(), 'cleared by a passing run' );
	}

	public function test_the_notice_is_not_shown_on_the_outlinepay_page() {
		$this->facts['currency'] = 'USD';
		Self_Check_Watch::record_local();
		$_GET['page'] = 'outlinepay';
		$this->assertSame( '', $this->notice() );
		$_GET['page'] = 'wc-settings';
		$this->assertStringContainsString( 'not showing at checkout', $this->notice() );
	}

	public function test_the_notice_needs_the_capability() {
		$this->facts['currency'] = 'USD';
		Self_Check_Watch::record_local();
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( '', $this->notice() );
	}

	public function test_a_site_address_change_on_a_copy_is_recorded_without_calling_outlinepay() {
		$this->options[ Site_Identity::OPTION ] = 'https://live.example.com/';
		$this->facts['is_copy']                 = true;
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		$this->run_queued();
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'OP-OFFLINE-H', Self_Check::last()['code'] );
	}

	public function test_the_notice_is_not_shown_on_a_copy() {
		$this->facts['currency'] = 'USD';
		Self_Check_Watch::record_local();
		$this->options[ Site_Identity::OPTION ] = 'https://live.example.com/';
		$this->assertSame( '', $this->notice(), 'the copy notice covers it' );
	}

	public function test_a_plugin_update_queues_the_check_after_the_version_report() {
		$this->options[ Settings::OPTION_VERSION ] = '0.5.1';
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.3' );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'status' => 'connected' ) ),
			)
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'status' => 'connected' ) ) );
		Scheduler::report_versions();
		$this->assertSame( OUTLINEPAY_VERSION, $this->options[ Settings::OPTION_VERSION ] );
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
	}

	public function test_the_email_is_on_for_a_store_that_never_saved_the_setting() {
		$this->assertArrayNotHasKey( Self_Check::OPTION_EMAIL, $this->options );
		$this->assertTrue( Self_Check::email_enabled() );
		$this->options[ Self_Check::OPTION_EMAIL ] = 'yes';
		$this->assertTrue( Self_Check::email_enabled() );
		$this->options[ Self_Check::OPTION_EMAIL ] = 'no';
		$this->assertFalse( Self_Check::email_enabled() );
	}

	/** A server answer: every local row passed, plus these rows. */
	private function server_answers( array $rows ) {
		$this->server_rows = array();
		foreach ( array_keys( Self_Check::LETTERS ) as $code ) {
			$this->server_rows[] = array(
				'code' => $code,
				'ok'   => true,
				'side' => 'store',
			);
		}
		$this->server_rows = array_merge( $this->server_rows, $rows );
	}

	public function test_notice_level_reads_the_rows() {
		$level = static function ( array $checks ) {
			return Self_Check_Watch::notice_level( Self_Check::offline( $checks, 'page', self::NOW ) );
		};
		foreach ( array( 'connection_saved', 'connection_status', 'store_currency', 'not_a_copy' ) as $code ) {
			$this->assertSame( 'hidden', $level( array( Self_Check::check( $code, false, 'store', array() ) ) ), $code );
		}
		$this->assertSame( 'hidden', $level( array( Self_Check::check( 'connection_status', false, 'processor', array( 'status' => 'revoked' ) ) ) ), 'revoked hides, whoever acts' );
		$this->assertSame( 'hidden', $level( array( Self_Check::check( 'methods_fresh', false, 'store', array( 'problem' => 'none_shown' ) ) ) ) );
		$this->assertSame( 'problem', $level( array( Self_Check::check( 'methods_fresh', false, 'store', array( 'problem' => 'stale' ) ) ) ), 'the cached list still shows' );
		foreach ( array( 'scheduler_jobs', 'wp_cron', 'versions' ) as $code ) {
			$this->assertSame( 'problem', $level( array( Self_Check::check( $code, false, 'store', array() ) ) ), $code );
		}
		$this->assertSame( '', $level( array( Self_Check::check( 'api_reachable', false, 'outlinepay', array( 'outcome' => 'server_error' ) ) ) ) );
		$this->assertSame( '', $level( array( Self_Check::check( 'versions', true, 'store', array() ) ) ), 'nothing failed' );
	}

	public function test_a_server_row_the_store_fixes_gets_the_soft_notice() {
		$this->server_answers(
			array(
				array(
					'code' => 'callback_reachable',
					'ok'   => false,
					'side' => 'store',
				),
			)
		);
		Self_Check_Watch::run_now();
		$html = $this->notice();
		$this->assertStringContainsString( 'self-check found a problem.', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Open the OutlinePay page to fix it.', $html );
		$this->assertStringNotContainsString( 'not showing at checkout', $html );
	}

	public function test_server_rows_only_outlinepay_or_the_provider_can_fix_show_no_notice() {
		$this->server_answers(
			array(
				array(
					'code' => 'deliveries_gave_up_7d',
					'ok'   => false,
					'side' => 'outlinepay',
				),
				array(
					'code' => 'open_problems_7d',
					'ok'   => false,
					'side' => 'processor',
				),
			)
		);
		Self_Check_Watch::run_now();
		$this->assertSame( 2, Self_Check::last()['failed_count'] );
		$this->assertSame( '', $this->notice() );
	}

	public function test_an_api_reachable_failure_alone_shows_no_notice() {
		$this->options[ Self_Check::OPTION_LAST ] = Self_Check::offline(
			array_merge(
				array( Self_Check::check( 'api_reachable', false, 'outlinepay', array( 'outcome' => 'server_error' ) ) ),
				Self_Check::local_checks( $this->facts, self::NOW )
			),
			'page',
			self::NOW
		);
		$this->assertSame( 1, Self_Check::last()['failed_count'] );
		$this->assertSame( '', $this->notice() );
	}

	public function test_a_hourly_refresh_that_worked_records_outlinepay_reached() {
		$this->options[ Self_Check::OPTION_LAST ] = Self_Check::offline(
			array_merge(
				array( Self_Check::check( 'api_reachable', false, 'store', array( 'outcome' => 'network_error' ) ) ),
				Self_Check::local_checks( $this->facts, self::NOW - 3600 )
			),
			'page',
			self::NOW - 3600
		);
		Self_Check_Watch::record_local( 140 );
		$last = Self_Check::last();
		$this->assertSame( 0, $last['failed_count'] );
		$row = $last['checks'][ array_search( 'api_reachable', array_column( $last['checks'], 'code' ), true ) ];
		$this->assertTrue( $row['ok'] );
		$this->assertSame( '140', $row['detail']['elapsed_ms'] );
		$this->assertCount( 1, array_keys( array_column( $last['checks'], 'code' ), 'api_reachable', true ), 'replaced in place' );
	}

	public function test_a_hourly_refresh_that_failed_leaves_api_reachable_as_stored() {
		$this->options[ Self_Check::OPTION_LAST ] = Self_Check::offline(
			array_merge(
				array( Self_Check::check( 'api_reachable', false, 'store', array( 'outcome' => 'network_error' ) ) ),
				Self_Check::local_checks( $this->facts, self::NOW - 3600 )
			),
			'page',
			self::NOW - 3600
		);
		Self_Check_Watch::record_local( null );
		$last = Self_Check::last();
		$this->assertSame( 1, $last['failed_count'] );
		$this->assertSame( 'OP-OFFLINE-C', $last['code'] );
	}

	public function test_the_hourly_action_times_a_refresh_that_worked() {
		Functions\when( 'wp_remote_request' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'data' => array( array( 'method' => 'card' ) ) ) ) );
		Scheduler::hourly();
		$last = Self_Check::last();
		$this->assertContains( 'api_reachable', array_column( $last['checks'], 'code' ) );
		$this->assertSame( 0, $last['failed_count'] );
	}

	public function test_no_queued_run_when_a_page_run_followed_the_change() {
		// Fix all: the currency is set, then the page check runs inline.
		Self_Check_Watch::changed();
		Self_Check_Watch::run_now();
		Self_Check_Watch::flush();
		$this->assertSame( array(), $this->queued );
		$this->assertSame( 1, $this->posted_runs() );
	}

	public function test_a_change_after_a_page_run_still_queues() {
		Self_Check_Watch::run_now();
		Self_Check_Watch::changed();
		Self_Check_Watch::flush();
		$this->assertSame( array( Scheduler::HOOK_CHECK_NOW ), $this->queued );
	}
}
