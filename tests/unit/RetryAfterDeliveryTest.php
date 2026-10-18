<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Refunds;

/**
 * The unanswered note tells the merchant "refund the same amount again:
 * OutlinePay will not refund twice". WooCommerce does not reload the order
 * page after a refund error, so the merchant who follows that advice a little
 * later may click after the refund delivery already mirrored the first refund
 * and cleared the marker. That click must not become a second refund.
 */
class RetryAfterDeliveryTest extends RefundTestCase {

	public function test_a_retry_after_the_delivery_mirrored_it_does_not_make_a_second_refund() {
		$this->wc_refund_row( 5000, 900 );
		$this->answers[] = new Api_Error( 0, 'network_error' );
		Refunds::process( 42, 5000 );
		$this->order->refunds = array(); // WooCommerce deleted its row.

		// The delivery lands first and mirrors the refund OutlinePay made under id 900.
		Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 5000 ) ), array( self::refund( array( 'amount' => 5000, 'refund_id' => '900' ) ) ) );
		$this->assertCount( 1, $this->order->refunds );

		// The merchant, still on the stale page, follows the note and refunds 5,000 won again.
		$this->wc_refund_row( 5000, 901 );
		$this->answers[] = self::json( 200, self::refund( array( 'id' => 'pref_two', 'amount' => 5000, 'refund_id' => '901' ) ) );
		Refunds::process( 42, 5000 );

		foreach ( $this->requests as $request ) {
			$this->assertSame( '900', $request['body']['refund_id'], 'the retry the note asked for must reuse the first id (or be refused) so OutlinePay does not refund the same 5,000 won twice' );
		}
	}

	/** Unanswered refund of 5,000 won (id 900), then its delivery mirrors it. */
	private function unanswered_then_mirrored() {
		$this->wc_refund_row( 5000, 900 );
		$this->answers[] = new Api_Error( 0, 'network_error' );
		Refunds::process( 42, 5000 );
		$this->order->refunds = array();
		Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 5000 ) ), array( self::refund( array( 'amount' => 5000, 'refund_id' => '900' ) ) ) );
		$this->assertCount( 1, $this->order->refunds );
	}

	public function test_a_retry_after_the_mirror_is_refused_once_then_a_deliberate_second_refund_works() {
		$this->unanswered_then_mirrored();
		$sent = count( $this->requests );

		$this->wc_refund_row( 5000, 901 );
		$result = Refunds::process( 42, 5000 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'This refund already went through and is on this order. Reload the page to see it.', $result->get_error_message() );
		$this->assertCount( $sent, $this->requests, 'nothing sent' );
		$this->assertArrayNotHasKey( Refunds::META_ANSWERED, $this->order->meta, 'consumed' );

		// WooCommerce deleted row 901. The merchant really means a second refund.
		$this->order->refunds = array_values( array_filter( $this->order->refunds, static function ( $r ) { return 901 !== $r->get_id(); } ) );
		$this->wc_refund_row( 5000, 902 );
		$this->answers[] = self::json( 200, self::refund( array( 'id' => 'pref_two', 'amount' => 5000, 'refund_id' => '902' ) ) );
		$this->assertTrue( Refunds::process( 42, 5000 ) );
		$this->assertSame( '902', end( $this->requests )['body']['refund_id'] );
	}

	public function test_a_retry_with_a_different_amount_is_sent() {
		$this->unanswered_then_mirrored();
		$this->wc_refund_row( 3000, 901 );
		$this->answers[] = self::json( 200, self::refund( array( 'id' => 'pref_two', 'amount' => 3000, 'refund_id' => '901' ) ) );
		$this->assertTrue( Refunds::process( 42, 3000 ) );
		$this->assertSame( '901', end( $this->requests )['body']['refund_id'] );
		$this->assertArrayHasKey( Refunds::META_ANSWERED, $this->order->meta, 'the 5,000 won memory stays' );
	}

	public function test_a_retry_before_any_delivery_still_reuses_the_first_id() {
		$this->wc_refund_row( 5000, 900 );
		$this->answers[] = new Api_Error( 0, 'network_error' );
		Refunds::process( 42, 5000 );
		$this->order->refunds = array();
		$this->wc_refund_row( 5000, 901 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'refund_id' => '900' ) ) );
		$this->assertTrue( Refunds::process( 42, 5000 ) );
		$this->assertSame( '900', end( $this->requests )['body']['refund_id'] );
	}

	public function test_a_pending_store_refund_mirrored_also_refuses_one_repeat() {
		Refunds::remember_pending( $this->order, 'pref_one', 5000, 'store' );
		Order_Sync::apply( $this->order, self::payment( array( 'refunded_amount' => 5000 ) ), array( self::refund( array( 'amount' => 5000 ) ) ) );
		$this->wc_refund_row( 5000, 901 );
		$this->assertInstanceOf( \WP_Error::class, Refunds::process( 42, 5000 ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_answer_older_than_a_day_no_longer_refuses() {
		$this->order->meta[ Refunds::META_ANSWERED ] = array(
			array(
				'id'     => 'pref_old',
				'amount' => 5000,
				'at'     => time() - Refunds::ANSWERED_WINDOW - 60,
			),
		);
		$this->wc_refund_row( 5000, 901 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'refund_id' => '901' ) ) );
		$this->assertTrue( Refunds::process( 42, 5000 ) );
	}

	public function test_a_mirrored_shopper_cancel_leaves_no_answered_memory() {
		Refunds::remember_unanswered( $this->order, 'shopper-cancel-42', 25000 );
		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'requested_by' => 'shopper', 'refund_id' => 'shopper-cancel-42' ) ) ) );
		$this->assertArrayNotHasKey( Refunds::META_ANSWERED, $this->order->meta );
	}
}
