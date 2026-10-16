<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Scheduler;
use OutlinePay\WooCommerce\Self_Check;

/**
 * The check engine: every local check, the offline code, the run against
 * OutlinePay, storage and the rate limit.
 */
class SelfCheckTest extends TestCase {

	const NOW   = 1790000000;
	const TOKEN = 'olp_it_test_SELFCHECK';

	/** @var array Options in memory. */
	private $options = array();

	/** @var array Autoload flag passed to update_option, by name. */
	private $autoload = array();

	/** @var array Requests the fake transport received. */
	private $requests = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$options  = &$this->options;
		$autoload = &$this->autoload;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $load = null ) use ( &$options, &$autoload ) {
				$options[ $name ]  = $value;
				$autoload[ $name ] = $load;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Facts for a healthy, connected store.
	 */
	private static function facts( array $overrides = array() ) {
		return array_merge(
			array(
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
				'methods_enabled'      => 3,
				'methods_shown'        => 2,
				'currency'             => 'KRW',
				'php'                  => '8.3.6',
				'wp'                   => '7.1.3',
				'wc'                   => '11.2.1',
				'min_php'              => '7.4',
				'min_wp'               => '6.2',
				'min_wc'               => '8.0',
				'hpos'                 => 'declared',
			),
			$overrides
		);
	}

	private static function by_code( array $checks ) {
		$out = array();
		foreach ( $checks as $check ) {
			$out[ $check['code'] ] = $check;
		}
		return $out;
	}

	/**
	 * A client whose transport answers with the given responses in turn.
	 */
	private function client( array $responses ) {
		$requests  = &$this->requests;
		$transport = static function ( $method, $url, $headers, $body, $timeout ) use ( &$requests, &$responses ) {
			$requests[] = compact( 'method', 'url', 'headers', 'body', 'timeout' );
			$next       = array_shift( $responses );
			if ( $next instanceof Api_Error ) {
				throw $next;
			}
			return $next;
		};
		return new Api_Client(
			static function () {
				return self::TOKEN;
			},
			$transport,
			'https://api.example.test/plugin/v1'
		);
	}

	private static function json( $status, $data ) {
		return array(
			'status' => $status,
			'body'   => json_encode( $data ),
		);
	}

	private function engine( array $facts, array $responses = array() ) {
		return new Self_Check(
			static function () use ( $facts ) {
				return $facts;
			},
			$this->client( $responses ),
			static function () {
				return self::NOW;
			}
		);
	}

	private static function server_run( array $checks ) {
		$failed = 0;
		foreach ( $checks as $check ) {
			$failed += $check['ok'] ? 0 : 1;
		}
		return array(
			'id'           => 'chk_AbC123',
			'object'       => 'plugin_self_check',
			'trigger'      => 'page',
			'mode'         => 'test',
			'created_at'   => '2026-10-16T01:02:03Z',
			'failed_count' => $failed,
			'worst_side'   => null,
			'checks'       => $checks,
		);
	}

	// ---- each local check ----

	public function test_a_healthy_store_passes_every_local_check_in_letter_order() {
		$checks = Self_Check::local_checks( self::facts(), self::NOW );
		$this->assertSame(
			array( 'connection_saved', 'connection_status', 'scheduler_jobs', 'wp_cron', 'methods_fresh', 'store_currency', 'not_a_copy', 'versions' ),
			array_column( $checks, 'code' )
		);
		foreach ( $checks as $check ) {
			$this->assertTrue( $check['ok'], $check['code'] );
			$this->assertContains( $check['side'], Self_Check::SIDE_ORDER );
		}
	}

	public function test_connection_saved_fails_without_an_installation_or_readable_credentials() {
		$this->assertFalse( Self_Check::connection_saved( self::facts( array( 'has_installation' => false ) ) )['ok'] );
		$unreadable = Self_Check::connection_saved( self::facts( array( 'credentials_readable' => false ) ) );
		$this->assertFalse( $unreadable['ok'] );
		$this->assertSame( 'store', $unreadable['side'] );
		$this->assertSame( 'unreadable', $unreadable['detail']['credentials'] );
		$missing = Self_Check::connection_saved(
			self::facts(
				array(
					'credentials_readable' => false,
					'credentials_stored'   => false,
				)
			)
		);
		$this->assertSame( 'missing', $missing['detail']['credentials'] );
	}

	public function test_connection_status_revoked_is_the_providers_to_fix_anything_else_the_stores() {
		$this->assertTrue( Self_Check::connection_status( self::facts() )['ok'] );
		$revoked = Self_Check::connection_status( self::facts( array( 'status' => 'revoked' ) ) );
		$this->assertFalse( $revoked['ok'] );
		$this->assertSame( 'processor', $revoked['side'] );
		$pending = Self_Check::connection_status( self::facts( array( 'status' => 'pending' ) ) );
		$this->assertFalse( $pending['ok'] );
		$this->assertSame( 'store', $pending['side'] );
		$this->assertSame( 'none', Self_Check::connection_status( self::facts( array( 'status' => '' ) ) )['detail']['status'] );
	}

	public function test_scheduler_jobs_pass_when_scheduled_or_running() {
		$check = Self_Check::scheduler_jobs(
			self::facts(
				array(
					'jobs' => array(
						Scheduler::HOOK_RECONCILE => true,
						Scheduler::HOOK_METHODS   => self::NOW - 3000,
					),
				)
			),
			self::NOW
		);
		$this->assertTrue( $check['ok'] );
		$this->assertSame( 'running', $check['detail']['reconcile'] );
		$this->assertSame( 'scheduled', $check['detail']['methods'] );
	}

	public function test_scheduler_jobs_fail_when_missing_or_over_an_hour_late() {
		$missing = Self_Check::scheduler_jobs( self::facts( array( 'jobs' => array( Scheduler::HOOK_METHODS => self::NOW ) ) ), self::NOW );
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'missing', $missing['detail']['reconcile'] );

		$late = Self_Check::scheduler_jobs(
			self::facts(
				array(
					'jobs' => array(
						Scheduler::HOOK_RECONCILE => self::NOW - 3601,
						Scheduler::HOOK_METHODS   => self::NOW,
					),
				)
			),
			self::NOW
		);
		$this->assertFalse( $late['ok'] );
		$this->assertSame( 'late', $late['detail']['reconcile'] );
		$this->assertSame( 'store', $late['side'] );

		$no_scheduler = Self_Check::scheduler_jobs( self::facts( array( 'as_available' => false ) ), self::NOW );
		$this->assertFalse( $no_scheduler['ok'] );
	}

	public function test_wp_cron_fails_only_when_disabled_and_nothing_ran_for_two_hours() {
		$this->assertTrue( Self_Check::wp_cron( self::facts( array( 'as_ran_recently' => false ) ) )['ok'], 'WP-Cron on' );
		$this->assertTrue( Self_Check::wp_cron( self::facts( array( 'cron_disabled' => true ) ) )['ok'], 'server cron runs actions' );
		$check = Self_Check::wp_cron(
			self::facts(
				array(
					'cron_disabled'   => true,
					'as_ran_recently' => false,
				)
			)
		);
		$this->assertFalse( $check['ok'] );
		$this->assertSame( 'yes', $check['detail']['disable_wp_cron'] );
		$this->assertSame( 'no', $check['detail']['recent_action'] );
	}

	public function test_methods_fresh_fails_when_stale_never_fetched_or_none_shown() {
		$stale = Self_Check::methods_fresh( self::facts( array( 'methods_fetched_at' => self::NOW - 7201 ) ), self::NOW );
		$this->assertFalse( $stale['ok'] );
		$this->assertSame( 'stale', $stale['detail']['problem'] );
		$this->assertSame( '120', $stale['detail']['fetched_min'] );

		$never = Self_Check::methods_fresh( self::facts( array( 'methods_fetched_at' => 0 ) ), self::NOW );
		$this->assertFalse( $never['ok'] );
		$this->assertSame( 'never', $never['detail']['fetched_min'] );

		$hidden = Self_Check::methods_fresh( self::facts( array( 'methods_shown' => 0 ) ), self::NOW );
		$this->assertFalse( $hidden['ok'] );
		$this->assertSame( 'none_shown', $hidden['detail']['problem'] );
		$this->assertStringContainsString( 'No OutlinePay method shows', Self_Check::sentence( $hidden ) );
	}

	public function test_store_currency_must_be_krw() {
		$this->assertTrue( Self_Check::store_currency( self::facts() )['ok'] );
		$usd = Self_Check::store_currency( self::facts( array( 'currency' => 'USD' ) ) );
		$this->assertFalse( $usd['ok'] );
		$this->assertSame( 'USD', $usd['detail']['currency'] );
		$this->assertStringContainsString( 'USD', Self_Check::sentence( $usd ) );
	}

	public function test_not_a_copy() {
		$this->assertTrue( Self_Check::not_a_copy( self::facts() )['ok'] );
		$this->assertFalse( Self_Check::not_a_copy( self::facts( array( 'is_copy' => true ) ) )['ok'] );
	}

	public function test_versions_compare_against_the_header_minimums_and_hpos() {
		$this->assertTrue( Self_Check::versions( self::facts() )['ok'] );

		$old_wc = Self_Check::versions( self::facts( array( 'wc' => '7.9.0' ) ) );
		$this->assertFalse( $old_wc['ok'] );
		$this->assertSame( 'woocommerce', $old_wc['detail']['needs'] );

		$no_wc = Self_Check::versions( self::facts( array( 'wc' => '' ) ) );
		$this->assertFalse( $no_wc['ok'] );
		$this->assertSame( 'missing', $no_wc['detail']['woocommerce'] );

		$old = Self_Check::versions(
			self::facts(
				array(
					'php'  => '7.3.0',
					'wp'   => '6.1',
					'hpos' => 'missing',
				)
			)
		);
		$this->assertSame( 'php,wordpress,hpos', $old['detail']['needs'] );
	}

	public function test_detail_is_held_to_the_server_shape() {
		$detail = array(
			'Bad-Key' => 'x',
			'ok_key'  => str_repeat( 'a', 300 ),
			'flag'    => true,
			'list'    => array( 1 ),
			'number'  => 12,
		);
		for ( $i = 0; $i < 15; $i++ ) {
			$detail[ 'k_' . chr( 97 + $i ) ] = 'v';
		}
		$clean = Self_Check::clean_detail( $detail );
		$this->assertArrayNotHasKey( 'Bad-Key', $clean );
		$this->assertArrayNotHasKey( 'list', $clean );
		$this->assertSame( 200, strlen( $clean['ok_key'] ) );
		$this->assertSame( 'true', $clean['flag'] );
		$this->assertSame( '12', $clean['number'] );
		$this->assertCount( 10, $clean );
		foreach ( $clean as $value ) {
			$this->assertIsString( $value );
		}
	}

	// ---- offline code ----

	public function test_the_offline_code_has_one_letter_per_failed_check_in_a_fixed_order() {
		$checks = array(
			Self_Check::check( 'store_currency', false, 'store', array() ),
			Self_Check::check( 'connection_saved', false, 'store', array() ),
			Self_Check::check( 'api_reachable', false, 'store', array() ),
			Self_Check::check( 'not_a_copy', true, 'store', array() ),
			Self_Check::check( 'versions', false, 'store', array() ),
		);
		$this->assertSame( 'OP-OFFLINE-ACGI', Self_Check::offline_code( $checks ) );
	}

	public function test_every_local_check_has_its_own_letter() {
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' ), array_values( Self_Check::LETTERS ) );
		foreach ( array_keys( Self_Check::LETTERS ) as $code ) {
			$this->assertSame( 'OP-OFFLINE-' . Self_Check::LETTERS[ $code ], Self_Check::offline_code( array( Self_Check::check( $code, false, 'store', array() ) ) ) );
		}
	}

	public function test_an_offline_code_with_nothing_failed_still_names_api_reachable() {
		$this->assertSame( 'OP-OFFLINE-C', Self_Check::offline_code( array( Self_Check::check( 'versions', true, 'store', array() ) ) ) );
	}

	// ---- the run ----

	public function test_a_copy_runs_local_checks_only_and_never_calls_outlinepay() {
		$outcome = $this->engine( self::facts( array( 'is_copy' => true ) ) )->run( 'page' );

		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'OP-OFFLINE-H', $outcome['result']['code'] );
		$this->assertFalse( $outcome['result']['online'] );
		$this->assertNotContains( 'api_reachable', array_column( $outcome['result']['checks'], 'code' ) );
		$this->assertSame( $outcome['result'], $this->options[ Self_Check::OPTION_LAST ] );
	}

	public function test_a_store_without_a_connection_never_calls_outlinepay() {
		$outcome = $this->engine(
			self::facts(
				array(
					'has_installation'     => false,
					'credentials_readable' => false,
					'credentials_stored'   => false,
					'status'               => '',
				)
			)
		)->run( 'page' );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'OP-OFFLINE-AB', $outcome['result']['code'] );
	}

	public function test_a_connected_store_posts_its_checks_and_shows_the_servers_answer() {
		$server_checks = array(
			array(
				'code'   => 'connection_saved',
				'ok'     => true,
				'side'   => 'store',
				'detail' => array(),
			),
			array(
				'code'   => 'callback_reachable',
				'ok'     => false,
				'side'   => 'store',
				'detail' => array(
					'outcome'     => 'rejected',
					'http_status' => 403,
				),
			),
			array(
				'code'   => 'open_problems_7d',
				'ok'     => true,
				'side'   => 'unknown',
				'detail' => array(),
			),
		);
		$outcome       = $this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 201, self::server_run( $server_checks ) ),
			)
		)->run( 'page' );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertSame( 5, $this->requests[0]['timeout'] );
		$this->assertSame( 'POST', $this->requests[1]['method'] );
		$this->assertStringEndsWith( '/self_checks', $this->requests[1]['url'] );

		$sent = json_decode( $this->requests[1]['body'], true );
		$this->assertSame( array( 'trigger', 'checks' ), array_keys( $sent ) );
		$this->assertSame( 'page', $sent['trigger'] );
		$codes = array_column( $sent['checks'], 'code' );
		$this->assertSame( array_keys( Self_Check::LETTERS ), $codes, 'every local check, letter order' );
		foreach ( $sent['checks'] as $check ) {
			$this->assertSame( array(), array_diff( array_keys( $check ), array( 'code', 'ok', 'side', 'detail' ) ) );
			if ( isset( $check['detail'] ) ) {
				$this->assertNotSame( array(), $check['detail'], 'an empty detail would go out as a JSON list' );
			}
		}
		$this->assertStringNotContainsString( '"detail":[]', $this->requests[1]['body'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', array_column( $sent['checks'], null, 'code' )['api_reachable']['detail']['elapsed_ms'] );

		$result = $outcome['result'];
		$this->assertFalse( $outcome['rate_limited'] );
		$this->assertSame( 'chk_AbC123', $result['code'] );
		$this->assertTrue( $result['online'] );
		$this->assertSame( 1, $result['failed_count'] );
		$this->assertSame( 'store', $result['worst_side'] );
		$this->assertSame( array( 'connection_saved', 'callback_reachable', 'open_problems_7d' ), array_column( $result['checks'], 'code' ), 'the answer is the truth, server codes with digits included' );
		$this->assertSame( '403', $result['checks'][1]['detail']['http_status'] );
		$this->assertSame( $result, $this->options[ Self_Check::OPTION_LAST ] );
		$this->assertFalse( $this->autoload[ Self_Check::OPTION_LAST ], 'not autoloaded' );
	}

	public function test_the_daily_trigger_is_sent_as_daily() {
		$this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 201, self::server_run( array( array( 'code' => 'versions', 'ok' => true, 'side' => 'store' ) ) ) ),
			)
		)->run( 'daily' );
		$this->assertSame( 'daily', json_decode( $this->requests[1]['body'], true )['trigger'] );
	}

	public function test_outlinepay_unreachable_on_get_is_the_stores_side_and_skips_the_post() {
		$outcome = $this->engine( self::facts(), array( new Api_Error( 0, 'network_error' ) ) )->run( 'page' );
		$this->assertCount( 1, $this->requests );
		$check = self::by_code( $outcome['result']['checks'] )['api_reachable'];
		$this->assertFalse( $check['ok'] );
		$this->assertSame( 'store', $check['side'] );
		$this->assertSame( 'network_error', $check['detail']['outcome'] );
		$this->assertArrayHasKey( 'elapsed_ms', $check['detail'] );
		$this->assertSame( 'OP-OFFLINE-C', $outcome['result']['code'] );
	}

	public function test_a_server_error_on_get_is_outlinepays_side() {
		$outcome = $this->engine( self::facts(), array( self::json( 503, array( 'error' => array( 'code' => 'unavailable' ) ) ) ) )->run( 'page' );
		$check   = self::by_code( $outcome['result']['checks'] )['api_reachable'];
		$this->assertSame( 'outlinepay', $check['side'] );
		$this->assertSame( 'server_error', $check['detail']['outcome'] );
	}

	public function test_a_refused_token_is_the_providers_side_and_marks_the_connection_revoked() {
		$outcome = $this->engine( self::facts(), array( self::json( 401, array( 'error' => array( 'code' => 'authentication_error' ) ) ) ) )->run( 'page' );
		$this->assertCount( 1, $this->requests, 'no POST with a refused token' );
		$checks = self::by_code( $outcome['result']['checks'] );
		$this->assertSame( 'processor', $checks['api_reachable']['side'] );
		$this->assertFalse( $checks['connection_status']['ok'] );
		$this->assertSame( 'processor', $checks['connection_status']['side'] );
		$this->assertSame( 'OP-OFFLINE-BC', $outcome['result']['code'] );
		$this->assertSame( 'processor', $outcome['result']['worst_side'] );
	}

	public function test_a_revoked_status_from_outlinepay_skips_the_post() {
		$outcome = $this->engine( self::facts(), array( self::json( 200, array( 'status' => 'revoked' ) ) ) )->run( 'page' );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'OP-OFFLINE-B', $outcome['result']['code'] );
	}

	public function test_all_green_but_the_post_failing_still_yields_the_api_letter() {
		$outcome = $this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				new Api_Error( 0, 'network_error' ),
			)
		)->run( 'page' );
		$this->assertSame( 'OP-OFFLINE-C', $outcome['result']['code'] );
		$this->assertFalse( $outcome['result']['online'] );
		$this->assertSame( 'self_check', self::by_code( $outcome['result']['checks'] )['api_reachable']['detail']['stage'] );
		$this->assertSame( $outcome['result'], $this->options[ Self_Check::OPTION_LAST ] );
	}

	public function test_offline_letters_add_up_with_local_failures() {
		$outcome = $this->engine(
			self::facts(
				array(
					'currency'      => 'USD',
					'methods_shown' => 0,
				)
			),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 500, array() ),
			)
		)->run( 'page' );
		$this->assertSame( 'OP-OFFLINE-CFG', $outcome['result']['code'] );
		$this->assertSame( 'outlinepay', $outcome['result']['worst_side'] );
	}

	public function test_an_unreadable_answer_counts_as_offline() {
		$outcome = $this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 201, array( 'id' => 'not-a-check' ) ),
			)
		)->run( 'page' );
		$this->assertSame( 'OP-OFFLINE-C', $outcome['result']['code'] );
	}

	public function test_rate_limited_returns_the_stored_result_and_keeps_it() {
		$stored                                  = array(
			'code'         => 'chk_Earlier',
			'online'       => true,
			'trigger'      => 'page',
			'ran_at'       => self::NOW - 30,
			'failed_count' => 0,
			'worst_side'   => '',
			'checks'       => array( Self_Check::check( 'versions', true, 'store', array() ) ),
		);
		$this->options[ Self_Check::OPTION_LAST ] = $stored;

		$outcome = $this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 429, array( 'error' => array( 'code' => 'rate_limited' ) ) ),
			)
		)->run( 'page' );

		$this->assertTrue( $outcome['rate_limited'] );
		$this->assertSame( $stored, $outcome['result'] );
		$this->assertSame( $stored, $this->options[ Self_Check::OPTION_LAST ] );
	}

	public function test_rate_limited_with_nothing_stored_falls_back_to_an_offline_result() {
		$outcome = $this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 429, array( 'error' => array( 'code' => 'rate_limited' ) ) ),
			)
		)->run( 'page' );
		$this->assertTrue( $outcome['rate_limited'] );
		$this->assertSame( 'OP-OFFLINE-C', $outcome['result']['code'] );
	}

	// ---- storage and wording ----

	public function test_last_reads_back_a_stored_result_and_ignores_anything_else() {
		$this->assertNull( Self_Check::last() );
		$this->options[ Self_Check::OPTION_LAST ] = 'garbage';
		$this->assertNull( Self_Check::last() );
		$result = Self_Check::offline( array( Self_Check::check( 'not_a_copy', false, 'store', array() ) ), 'page', self::NOW );
		Self_Check::store( $result );
		$this->assertSame( $result, Self_Check::last() );
		$this->assertFalse( $this->autoload[ Self_Check::OPTION_LAST ] );
	}

	public function test_every_check_has_a_sentence_for_pass_and_fail() {
		$codes = array_merge( array_keys( Self_Check::LETTERS ), array( 'callback_reachable', 'token_used_recently', 'deliveries_gave_up_7d', 'open_problems_7d', 'something_new' ) );
		foreach ( $codes as $code ) {
			foreach ( array( 'store', 'processor', 'outlinepay' ) as $side ) {
				$pass = Self_Check::sentence( Self_Check::check( $code, true, $side, array() ) );
				$fail = Self_Check::sentence( Self_Check::check( $code, false, $side, array() ) );
				$this->assertNotSame( '', $pass );
				$this->assertNotSame( '', $fail );
				$this->assertNotSame( $pass, $fail, $code );
			}
		}
	}

	public function test_who_acts_next_is_a_plain_label() {
		$this->assertSame( 'You', Self_Check::who( 'store' ) );
		$this->assertSame( 'Your payment provider', Self_Check::who( 'processor' ) );
		$this->assertSame( 'OutlinePay', Self_Check::who( 'outlinepay' ) );
		$this->assertSame( 'Shopper', Self_Check::who( 'shopper' ) );
		$this->assertSame( 'Not sure', Self_Check::who( 'unknown' ) );
	}

	public function test_the_server_codes_are_never_sent_by_the_plugin() {
		$this->engine(
			self::facts(),
			array(
				self::json( 200, array( 'status' => 'connected' ) ),
				self::json( 201, self::server_run( array( array( 'code' => 'versions', 'ok' => true, 'side' => 'store' ) ) ) ),
			)
		)->run( 'page' );
		$codes = array_column( json_decode( $this->requests[1]['body'], true )['checks'], 'code' );
		foreach ( array( 'callback_reachable', 'token_used_recently', 'deliveries_gave_up_7d', 'open_problems_7d' ) as $server_code ) {
			$this->assertNotContains( $server_code, $codes );
		}
		$this->assertLessThanOrEqual( 40, count( $codes ) );
	}
}
