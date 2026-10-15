<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Scheduler;
use OutlinePay\WooCommerce\Switch_Guard;
use OutlinePay\WooCommerce\Order_Sync;

class ReconcileTest extends TestCase {

	const NOW = 1790000000;
	const DAY = 86400;

	private function facts( array $overrides ) {
		return array_merge(
			array(
				'status'   => 'pending',
				'hold'     => '',
				'settled'  => '',
				'created'  => self::NOW - self::DAY,
				'deadline' => '',
			),
			$overrides
		);
	}

	public function test_a_virtual_account_deposited_on_day_three_is_still_followed() {
		$facts = $this->facts(
			array(
				'status'   => 'on-hold',
				'hold'     => 'deposit',
				'created'  => self::NOW - 3 * self::DAY,
				'deadline' => gmdate( 'c', self::NOW - 3600 ),
			)
		);
		$this->assertTrue( Scheduler::should_reconcile( $facts, self::NOW ), 'within deadline plus a day' );
		$facts['deadline'] = gmdate( 'c', self::NOW - self::DAY - 60 );
		$this->assertFalse( Scheduler::should_reconcile( $facts, self::NOW ), 'past deadline plus a day' );
	}

	public function test_settled_holds_are_skipped() {
		foreach ( array( 'mismatch', 'review', 'double', 'foreign' ) as $settled ) {
			$this->assertFalse( Scheduler::should_reconcile( $this->facts( array( 'status' => 'on-hold', 'settled' => $settled ) ), self::NOW ), $settled );
		}
	}

	public function test_other_unsettled_orders_are_followed_for_a_week() {
		$this->assertTrue( Scheduler::should_reconcile( $this->facts( array( 'created' => self::NOW - 6 * self::DAY ) ), self::NOW ) );
		$this->assertTrue( Scheduler::should_reconcile( $this->facts( array( 'status' => 'on-hold', 'created' => self::NOW - 3 * self::DAY ) ), self::NOW ), 'a switched BACS order on hold' );
		$this->assertFalse( Scheduler::should_reconcile( $this->facts( array( 'created' => self::NOW - 8 * self::DAY ) ), self::NOW ) );
		$this->assertFalse( Scheduler::should_reconcile( $this->facts( array( 'status' => 'processing' ) ), self::NOW ) );
	}

	private static function rows( array $pairs ) {
		return array_map(
			static function ( $pair ) {
				return array(
					'id' => $pair[0],
					't'  => $pair[1],
				);
			},
			$pairs
		);
	}

	public function test_the_cursor_walks_on_and_starts_over_at_the_end() {
		$cursor = Scheduler::read_cursor( null );
		$this->assertSame( 0, $cursor['t'] );

		// First run: 3 rows back for a limit of 2, so more remain.
		$batch = Scheduler::next_batch( self::rows( array( array( 1, 100 ), array( 2, 200 ), array( 3, 200 ) ) ), $cursor, 2 );
		$this->assertSame( array( 1, 2 ), array_column( $batch['rows'], 'id' ) );
		$this->assertFalse( $batch['complete'] );
		$cursor = Scheduler::advance_cursor( $cursor, $batch['rows'], $batch['complete'] );
		$this->assertSame(
			array(
				't'   => 200,
				'ids' => array( 2 ),
			),
			$cursor
		);

		// Second run fetches from second 200 again and skips order 2.
		$batch = Scheduler::next_batch( self::rows( array( array( 2, 200 ), array( 3, 200 ) ) ), $cursor, 2 );
		$this->assertSame( array( 3 ), array_column( $batch['rows'], 'id' ) );
		$this->assertTrue( $batch['complete'] );
		$cursor = Scheduler::advance_cursor( $cursor, $batch['rows'], $batch['complete'] );
		$this->assertSame( 0, $cursor['t'], 'starts over' );
	}

	public function test_a_full_page_at_the_cursor_second_is_not_mistaken_for_the_end() {
		$cursor = array(
			't'   => 200,
			'ids' => array( 2 ),
		);
		// Query limit was 2 + 1 skipped id = 3, and 3 came back: more may exist.
		$batch = Scheduler::next_batch( self::rows( array( array( 2, 200 ), array( 3, 200 ), array( 4, 300 ) ) ), $cursor, 2 );
		$this->assertFalse( $batch['complete'] );
	}

	public function test_an_interrupted_run_keeps_its_place() {
		$cursor = array(
			't'   => 100,
			'ids' => array(),
		);
		$this->assertSame( $cursor, Scheduler::advance_cursor( $cursor, array(), false ) );
	}

	public function test_switched_away_means_another_gateway_with_our_attempts() {
		$order = new \WC_Order( 9 );
		$order->payment_method = 'bacs';
		$this->assertFalse( Switch_Guard::switched_away( $order ), 'no attempts' );
		$order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_a' );
		$this->assertTrue( Switch_Guard::switched_away( $order ) );
		$order->payment_method = 'outlinepay_card';
		$this->assertFalse( Switch_Guard::switched_away( $order ), 'still ours' );
	}

	public function test_the_walk_never_starts_before_the_query_window() {
		$old = Scheduler::clamp_cursor(
			array(
				't'   => 0,
				'ids' => array( 3 ),
			),
			self::NOW
		);
		$this->assertSame( self::NOW - Scheduler::QUERY_WINDOW, $old['t'] );
		$this->assertSame( array(), $old['ids'] );
		$recent = array(
			't'   => self::NOW - self::DAY,
			'ids' => array( 3 ),
		);
		$this->assertSame( $recent, Scheduler::clamp_cursor( $recent, self::NOW ) );
		$this->assertGreaterThanOrEqual( Scheduler::PENDING_WINDOW, Scheduler::QUERY_WINDOW );
	}

	public function test_an_order_failing_with_server_errors_is_stepped_past_after_three_tries() {
		$failures = array();
		$first    = Scheduler::note_failure( $failures, 7 );
		$this->assertFalse( $first['step_past'] );
		$second = Scheduler::note_failure( $first['failures'], 7 );
		$this->assertFalse( $second['step_past'] );
		$third = Scheduler::note_failure( $second['failures'], 7 );
		$this->assertTrue( $third['step_past'] );
		$this->assertArrayNotHasKey( 7, $third['failures'], 'count cleared once stepped past' );
		$other = Scheduler::note_failure( $second['failures'], 8 );
		$this->assertSame( 2, $other['failures'][7], 'counts are per order' );
	}
}
