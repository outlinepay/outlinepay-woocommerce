<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Refunds;
use OutlinePay\WooCommerce\Shopper_Cancel;

/**
 * A refund request OutlinePay never answered may still have gone through
 * (the server made it and the answer was lost). The order says so, the
 * request is remembered under its id, and the refund delivery that follows
 * a settled refund completes it.
 */
class UnansweredRefundNoteTest extends RefundTestCase {

	public function test_a_store_refund_with_no_answer_leaves_a_note_on_the_order() {
		$this->wc_refund_row( 5000 );
		$this->answers[] = new Api_Error( 0, 'network_error' );

		$result = Refunds::process( 42, 5000 );
		$this->assertStringContainsString( 'check the order notes', $result->get_error_message() );
		$this->assertNotSame( array(), $this->order->notes, 'the merchant is told to check the notes, so the notes must say a refund of 5,000 won was asked for and may have gone through' );
		$note = end( $this->order->notes );
		$this->assertStringContainsString( 'OutlinePay did not answer a refund of 5,000 won. It may or may not have gone through.', $note );
		$this->assertStringContainsString( 'Do not refund this amount in your payment provider\'s dashboard. If it went through, it will appear on this order automatically, usually within a few minutes. Reload this order to check. Refunding the same amount again is safe: OutlinePay will not refund twice.', $note );
		$this->assertSame( array( '900' => 5000 ), $this->order->meta[ Refunds::META_UNANSWERED ] );
	}

	public function test_an_unreadable_answer_keeps_the_id_so_a_retry_cannot_make_a_second_refund() {
		$first           = $this->wc_refund_row( 5000 );
		$this->answers[] = array(
			'status' => 200,
			'body'   => '{"id":"garbled"}',
		);
		$result = Refunds::process( 42, 5000 );
		$this->assertStringContainsString( 'could not be read', $result->get_error_message() );
		$this->assertSame( array( (string) $first->get_id() => 5000 ), $this->order->meta[ Refunds::META_UNANSWERED ] );
		$this->assertStringContainsString( 'may or may not have gone through', end( $this->order->notes ) );

		$this->order->refunds = array();
		$this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000 ) ) );
		$this->assertTrue( Refunds::process( 42, 5000 ) );
		$this->assertSame( (string) $first->get_id(), $this->requests[1]['body']['refund_id'] );
		$this->assertArrayNotHasKey( Refunds::META_UNANSWERED, $this->order->meta );
	}

	public function test_an_unanswered_refund_that_went_through_arrives_by_delivery_and_clears_the_marker() {
		$this->wc_refund_row( 5000, 900 );
		$this->answers[] = new Api_Error( 0, 'network_error' );
		Refunds::process( 42, 5000 );
		$this->order->refunds = array(); // WooCommerce deleted its row.

		\OutlinePay\WooCommerce\Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 5000 ) ), array( self::refund( array( 'amount' => 5000, 'refund_id' => '900' ) ) ) );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertSame( 'Refund confirmed by the payment provider', $this->order->refunds[0]->get_reason() );
		$this->assertArrayNotHasKey( Refunds::META_UNANSWERED, $this->order->meta );
	}

	public function test_an_unanswered_refund_that_failed_arrives_by_delivery_as_a_note_and_clears_the_marker() {
		$this->wc_refund_row( 5000, 900 );
		$this->answers[] = new Api_Error( 0, 'network_error' );
		Refunds::process( 42, 5000 );
		$this->order->refunds = array();

		\OutlinePay\WooCommerce\Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'amount' => 5000, 'refund_id' => '900', 'status' => 'failed' ) ) ) );
		$this->assertCount( 0, $this->order->refunds );
		$this->assertStringContainsString( 'did not complete the refund of 5,000 won', end( $this->order->notes ) );
		$this->assertArrayNotHasKey( Refunds::META_UNANSWERED, $this->order->meta );
	}

	public function test_a_synchronous_success_then_the_same_refund_by_delivery_is_one_refund() {
		$row             = $this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'refund_id' => (string) $row->get_id() ) ) );
		$this->assertTrue( Refunds::process( 42, 5000 ) );
		$notes = count( $this->order->notes );

		\OutlinePay\WooCommerce\Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 5000 ) ), array( self::refund( array( 'amount' => 5000, 'refund_id' => (string) $row->get_id() ) ) ) );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertCount( $notes, $this->order->notes );
	}

	public function test_a_store_retry_never_reuses_a_shopper_cancel_id() {
		Refunds::remember_unanswered( $this->order, 'shopper-cancel-42', 25000 );
		$row             = $this->wc_refund_row( 25000 );
		$this->answers[] = self::json( 200, self::refund() );
		Refunds::process( 42, 25000 );
		$this->assertSame( (string) $row->get_id(), $this->requests[0]['body']['refund_id'] );
	}

	public function test_a_shopper_cancel_with_no_answer_leaves_a_note_on_the_order() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->answers[] = new Api_Error( 0, 'network_error' );

		$result = Shopper_Cancel::cancel( 42, 7, 'nonce' );
		$this->assertSame( 'notice', $result['type'] );
		$this->assertSame( 'We could not confirm your cancellation yet. We will email you.', $result['message'] );
		$this->assertNotSame( array(), $this->order->notes, 'the shopper may never retry; the merchant must see that a full refund was asked for and may have gone through before shipping' );
		$this->assertSame( 'The shopper asked to cancel, but OutlinePay\'s answer was lost. If the refund went through, this order will be cancelled automatically. Do not ship it until then.', end( $this->order->notes ) );
		$this->assertSame( array( 'shopper-cancel-42' => 25000 ), $this->order->meta[ Refunds::META_UNANSWERED ] );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertFalse( Shopper_Cancel::can_cancel( $this->order, 7 ), 'treated like a pending cancel' );
	}

	public function test_an_unreadable_shopper_cancel_answer_is_treated_the_same() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->answers[] = array(
			'status' => 200,
			'body'   => '[]',
		);
		$result = Shopper_Cancel::cancel( 42, 7, 'nonce' );
		$this->assertSame( 'notice', $result['type'] );
		$this->assertSame( array( 'shopper-cancel-42' => 25000 ), $this->order->meta[ Refunds::META_UNANSWERED ] );
	}

	public function test_an_unanswered_shopper_cancel_that_went_through_cancels_the_order_by_delivery() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->answers[] = new Api_Error( 0, 'network_error' );
		Shopper_Cancel::cancel( 42, 7, 'nonce' );

		\OutlinePay\WooCommerce\Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'requested_by' => 'shopper', 'refund_id' => 'shopper-cancel-42' ) ) ) );
		$this->assertSame( 'cancelled', $this->order->status );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertArrayNotHasKey( Refunds::META_UNANSWERED, $this->order->meta );
	}
}
