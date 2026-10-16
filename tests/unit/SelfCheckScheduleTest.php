<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Scheduler;

/**
 * The daily self-check on Action Scheduler.
 */
class SelfCheckScheduleTest extends TestCase {

	/** @var array Options in memory. */
	private $options = array();

	/** @var array Recurring actions scheduled. */
	private $scheduled = array();

	/** @var array Hooks already scheduled. */
	private $existing = array();

	protected function setUp(): void {
		parent::setUp();
		$options   = &$this->options;
		$scheduled = &$this->scheduled;
		$existing  = &$this->existing;
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
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( $hook ) use ( &$existing ) {
				return in_array( $hook, $existing, true );
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( $timestamp, $interval, $hook, $args, $group ) use ( &$scheduled ) {
				$scheduled[ $hook ] = compact( 'timestamp', 'interval', 'args', 'group' );
				return 1;
			}
		);
	}

	public function test_next_daily_run_is_today_at_the_offset_or_tomorrow() {
		$midnight = 1790035200; // A UTC midnight.
		$this->assertSame( 0, $midnight % 86400 );
		$this->assertSame( $midnight + 7200, Scheduler::next_daily_run( $midnight + 3600, 7200 ), 'later today' );
		$this->assertSame( $midnight + 86400 + 3600, Scheduler::next_daily_run( $midnight + 7200, 3600 ), 'passed today: tomorrow' );
		$this->assertSame( $midnight + 86400 + 3600, Scheduler::next_daily_run( $midnight + 3600, 3600 ), 'exactly now: tomorrow' );
	}

	public function test_the_offset_is_picked_once_and_kept() {
		Functions\expect( 'wp_rand' )->once()->with( 0, 86399 )->andReturn( 12345 );
		$this->assertSame( 12345, Scheduler::daily_offset() );
		$this->assertSame( 12345, Scheduler::daily_offset() );
		$this->assertSame( 12345, $this->options[ Scheduler::OPTION_DAILY_AT ] );
	}

	public function test_a_stored_offset_out_of_range_is_picked_again() {
		$this->options[ Scheduler::OPTION_DAILY_AT ] = 90000;
		Functions\expect( 'wp_rand' )->once()->andReturn( 5 );
		$this->assertSame( 5, Scheduler::daily_offset() );
	}

	public function test_schedule_recurring_adds_the_daily_check_once_a_day_at_the_offset() {
		$this->options[ Scheduler::OPTION_DAILY_AT ] = 4321;
		Scheduler::schedule_recurring();

		$this->assertArrayHasKey( Scheduler::HOOK_SELF_CHECK, $this->scheduled );
		$daily = $this->scheduled[ Scheduler::HOOK_SELF_CHECK ];
		$this->assertSame( 86400, $daily['interval'] );
		$this->assertSame( 'outlinepay', $daily['group'] );
		$this->assertSame( 4321, $daily['timestamp'] % 86400 );
		$this->assertGreaterThan( time(), $daily['timestamp'] );
		$this->assertLessThanOrEqual( time() + 86400, $daily['timestamp'] );
	}

	public function test_schedule_recurring_leaves_an_existing_daily_check_alone() {
		$this->existing = array( Scheduler::HOOK_SELF_CHECK, Scheduler::HOOK_RECONCILE, Scheduler::HOOK_METHODS );
		Scheduler::schedule_recurring();
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_unschedule_all_covers_the_daily_check() {
		$this->assertContains( Scheduler::HOOK_SELF_CHECK, Scheduler::hooks() );
	}

	public function test_register_hooks_the_daily_run() {
		Scheduler::register();
		$this->assertNotFalse( has_action( Scheduler::HOOK_SELF_CHECK, array( 'OutlinePay\WooCommerce\Self_Check', 'run_daily' ) ) );
	}
}
