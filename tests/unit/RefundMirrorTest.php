<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Refunds;

/**
 * Refunds made elsewhere, mirrored onto the order through Order_Sync::apply.
 */
class RefundMirrorTest extends RefundTestCase {

	private function dashboard_refund( array $overrides = array() ) {
		return self::refund(
			array_merge(
				array(
					'id'           => 'pref_dash',
					'amount'       => 10000,
					'source'       => 'provider',
					'requested_by' => null,
					'refund_id'    => null,
				),
				$overrides
			)
		);
	}

	public function test_a_dashboard_refund_becomes_a_woocommerce_refund_without_sending_money() {
		Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 10000 ) ), array( $this->dashboard_refund() ) );

		$this->assertCount( 1, $this->order->refunds );
		$refund = $this->order->refunds[0];
		$this->assertSame( '10000', $refund->get_amount() );
		$this->assertSame( 'Refunded in the payment provider\'s dashboard', $refund->get_reason() );
		$this->assertSame( 'pref_dash', $refund->meta[ Refunds::META_REFUND_ID ] );
		$this->assertSame( 'processing', $this->order->status, 'a partial refund leaves the status' );
		$this->assertStringContainsString( 'pref_dash', $this->notes() );
		$this->assertSame( array(), $this->requests, 'nothing sent to OutlinePay' );
		$this->assertSame( array(), $this->locks->rows );
	}

	public function test_the_same_delivery_twice_adds_one_refund() {
		$payment = self::payment( array( 'refunded_amount' => 10000 ) );
		Order_Sync::apply( $this->order, $payment, array( $this->dashboard_refund() ) );
		$notes = count( $this->order->notes );
		Order_Sync::apply( $this->order, $payment, array( $this->dashboard_refund() ) );

		$this->assertCount( 1, $this->order->refunds );
		$this->assertCount( $notes, $this->order->notes );
	}

	public function test_a_refund_the_refund_button_recorded_is_not_mirrored_again() {
		// A later delivery carries every refund of the payment, the store's own included.
		$row                                  = $this->wc_refund_row( 5000 );
		$row->meta[ Refunds::META_REFUND_ID ] = 'pref_one';
		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'amount' => 5000 ) ), $this->dashboard_refund() ) );

		$this->assertCount( 2, $this->order->refunds );
	}

	public function test_a_pending_refund_then_succeeded_is_added_with_the_confirmation_reason() {
		Refunds::remember_pending( $this->order, 'pref_one', 5000, 'store' );
		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'amount' => 5000, 'status' => 'pending' ) ) ) );
		$this->assertCount( 0, $this->order->refunds, 'still pending: nothing yet' );

		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'amount' => 5000 ) ) ) );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertSame( 'Refund confirmed by the payment provider', $this->order->refunds[0]->get_reason() );
		$this->assertSame( array(), Refunds::pending( $this->order ) );
	}

	public function test_a_pending_refund_that_failed_only_adds_a_note() {
		Refunds::remember_pending( $this->order, 'pref_one', 5000, 'store' );
		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'amount' => 5000, 'status' => 'failed', 'failure_code' => 'not_cancellable' ) ) ) );

		$this->assertCount( 0, $this->order->refunds );
		$this->assertStringContainsString( 'did not complete the refund of 5,000 won', $this->notes() );
		$this->assertSame( array(), Refunds::pending( $this->order ) );
		$notes = count( $this->order->notes );
		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'amount' => 5000, 'status' => 'failed' ) ) ) );
		$this->assertCount( $notes, $this->order->notes, 'once' );
	}

	public function test_a_full_refund_marks_the_order_refunded() {
		Order_Sync::apply( $this->order, self::payment(), array( $this->dashboard_refund( array( 'amount' => 25000 ) ) ) );
		$this->assertSame( 'refunded', $this->order->status );
	}

	public function test_a_full_refund_never_moves_a_cancelled_order_away_from_cancelled() {
		$this->order->status = 'cancelled';
		Order_Sync::apply( $this->order, self::payment(), array( $this->dashboard_refund( array( 'amount' => 25000 ) ) ) );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertSame( 'cancelled', $this->order->status );
	}

	public function test_a_confirmed_shopper_cancel_cancels_the_order() {
		Refunds::remember_pending( $this->order, 'pref_one', 25000, 'shopper' );
		Order_Sync::apply(
			$this->order,
			self::payment(),
			array(
				self::refund(
					array(
						'requested_by' => 'shopper',
						'refund_id'    => 'shopper-cancel-42',
					)
				),
			)
		);
		$this->assertSame( 'cancelled', $this->order->status );
		$this->assertSame( 'Cancelled by the shopper', $this->order->refunds[0]->get_reason() );
	}

	public function test_a_shopper_cancel_is_recognised_without_the_requested_by_field() {
		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'requested_by' => null, 'refund_id' => 'shopper-cancel-42' ) ) ) );
		$this->assertSame( 'cancelled', $this->order->status );
	}

	public function test_a_refund_of_a_duplicate_payment_is_a_note_not_a_refund() {
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine', 'ppay_dup' );
		$duplicate = self::payment(
			array(
				'id'     => 'ppay_dup',
				'status' => 'duplicate',
			)
		);
		$refund    = self::refund(
			array(
				'id'           => 'pref_dup',
				'payment'      => 'ppay_dup',
				'source'       => 'duplicate',
				'requested_by' => null,
				'refund_id'    => null,
			)
		);
		Order_Sync::apply( $this->order, $duplicate, array( $refund ) );
		Order_Sync::apply( $this->order, $duplicate, array( $refund ) );

		$this->assertCount( 0, $this->order->refunds, 'the order\'s own payment is unchanged' );
		$this->assertSame( 1, substr_count( $this->notes(), 'cancelled the duplicate payment ppay_dup' ) );
		$this->assertSame( 'processing', $this->order->status );
	}

	public function test_a_refund_of_another_payment_is_ignored() {
		Order_Sync::apply( $this->order, self::payment(), array( $this->dashboard_refund( array( 'payment' => 'ppay_other' ) ) ) );
		$this->assertCount( 0, $this->order->refunds );
	}

	public function test_a_refund_woocommerce_will_not_add_is_noted_once() {
		$manual = $this->wc_refund_row( 25000 );
		unset( $manual );
		Order_Sync::apply( $this->order, self::payment(), array( $this->dashboard_refund() ) );
		Order_Sync::apply( $this->order, self::payment(), array( $this->dashboard_refund() ) );

		$this->assertCount( 1, $this->order->refunds, 'only the manual one' );
		$this->assertSame( 1, substr_count( $this->notes(), 'could not add it to this order' ) );
	}

	public function test_a_gap_between_the_payment_and_the_order_is_logged() {
		Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 3000 ) ) );
		$this->assertStringContainsString( 'more refunded than the order', implode( "\n", $this->log_lines ) );
	}

	public function test_a_late_awaiting_deposit_never_moves_a_paid_order_back_on_hold() {
		$this->order->payment_method = 'outlinepay_virtual_account';
		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'payment_method'  => 'virtual_account',
					'status'          => 'awaiting_deposit',
					'virtual_account' => array(
						'bank'           => 'KB',
						'account_number' => '9123',
						'deadline'       => '2026-10-20T00:00:00Z',
					),
				)
			)
		);
		$this->assertSame( 'processing', $this->order->status );
		$this->assertArrayNotHasKey( Order_Sync::META_HOLD, $this->order->meta );
	}

	public function test_awaiting_deposit_puts_an_unpaid_order_on_hold_with_the_account() {
		$this->order->status          = 'pending';
		$this->order->date_paid       = null;
		$this->order->payment_method  = 'outlinepay_virtual_account';
		$this->order->meta            = array( Order_Sync::META_ATTEMPTS => array( 'ppay_va' ) );
		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'id'              => 'ppay_va',
					'payment_method'  => 'virtual_account',
					'status'          => 'awaiting_deposit',
					'virtual_account' => array(
						'bank'           => 'KB',
						'account_number' => '9123',
						'deadline'       => '2026-10-20T00:00:00Z',
					),
				)
			)
		);
		$this->assertSame( 'on-hold', $this->order->status );
		$this->assertSame( 'deposit', $this->order->meta[ Order_Sync::META_HOLD ] );
		$this->assertSame( '9123', $this->order->meta[ Order_Sync::META_VA ]['account_number'] );
	}

	public function test_follow_pending_reads_back_the_payment_and_each_pending_refund() {
		Refunds::remember_pending( $this->order, 'pref_one', 5000, 'store' );
		$order = $this->order;
		\Brain\Monkey\Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );
		$this->answers = array(
			self::json( 200, self::payment( array( 'refunded_amount' => 5000 ) ) ),
			self::json( 200, self::refund( array( 'amount' => 5000 ) ) ),
		);

		$this->assertSame( 1, Refunds::follow_pending() );
		$this->assertStringEndsWith( '/payments/ppay_mine', $this->requests[0]['url'] );
		$this->assertStringEndsWith( '/refunds/pref_one', $this->requests[1]['url'] );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertSame( array(), Refunds::pending( $this->order ) );
	}

	public function test_follow_pending_stops_when_outlinepay_is_unreachable() {
		Refunds::remember_pending( $this->order, 'pref_one', 5000, 'store' );
		$order = $this->order;
		\Brain\Monkey\Functions\when( 'wc_get_orders' )->justReturn( array( $order, $order ) );
		$this->answers = array( new Api_Error( 0, 'network_error' ) );

		$this->assertSame( 0, Refunds::follow_pending() );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 1, Refunds::pending( $this->order ) );
	}

	public function test_callback_ids_are_the_unhandled_ones_in_the_delivery_and_the_pending_ones() {
		Refunds::mark_done( $this->order, 'pref_done' );
		Refunds::remember_pending( $this->order, 'pref_wait', 5000, 'store' );
		$payload = array(
			'id'      => 'ppay_mine',
			'refunds' => array(
				array( 'id' => 'pref_done' ),
				array( 'id' => 'pref_new' ),
				array( 'id' => '../evil' ),
				'junk',
			),
		);
		$this->assertSame( array( 'pref_new', 'pref_wait' ), Refunds::ids_to_read( $this->order, $payload ) );
		$this->assertSame( array(), Refunds::ids_to_read( 'not an order', array() ) );
	}
}
