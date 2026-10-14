<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Order_Sync;

class OrderSyncDecideTest extends TestCase {

	private function payment( array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => 'ppay_abc',
				'object'       => 'plugin_payment',
				'installation' => 'inst_1',
				'order_id'     => '42',
				'amount'       => 25000,
				'currency'     => 'KRW',
				'status'       => 'paid',
			),
			$overrides
		);
	}

	private function decide( array $payment ) {
		return Order_Sync::decide( $payment, 42, 25000, 'KRW', 'inst_1' );
	}

	/**
	 * @dataProvider statuses
	 */
	public function test_maps_each_status( $status, $expected ) {
		$this->assertSame( $expected, $this->decide( $this->payment( array( 'status' => $status ) ) ) );
	}

	public function statuses() {
		return array(
			'paid'             => array( 'paid', Order_Sync::COMPLETE ),
			'awaiting_payment' => array( 'awaiting_payment', Order_Sync::WAIT ),
			'awaiting_deposit' => array( 'awaiting_deposit', Order_Sync::HOLD_DEPOSIT ),
			'failed'           => array( 'failed', Order_Sync::RETRY ),
			'expired'          => array( 'expired', Order_Sync::RETRY ),
			'superseded'       => array( 'superseded', Order_Sync::IGNORE ),
			'needs_review'     => array( 'needs_review', Order_Sync::HOLD_REVIEW ),
			'duplicate'        => array( 'duplicate', Order_Sync::NOTE_DUPLICATE ),
		);
	}

	/**
	 * @dataProvider unknown_statuses
	 */
	public function test_an_unknown_status_is_never_treated_as_failed( $status ) {
		$decision = $this->decide( $this->payment( array( 'status' => $status ) ) );
		$this->assertSame( Order_Sync::UNKNOWN, $decision );
		$this->assertNotSame( Order_Sync::RETRY, $decision );
	}

	public function unknown_statuses() {
		return array(
			array( 'refunded' ),
			array( 'partially_paid' ),
			array( 'PAID' ),
			array( '' ),
		);
	}

	public function test_paid_for_another_amount_holds() {
		$this->assertSame( Order_Sync::HOLD_MISMATCH, $this->decide( $this->payment( array( 'amount' => 26000 ) ) ) );
	}

	public function test_paid_amount_must_be_a_json_integer() {
		$this->assertSame( Order_Sync::HOLD_MISMATCH, $this->decide( $this->payment( array( 'amount' => '25000' ) ) ) );
		$this->assertSame( Order_Sync::HOLD_MISMATCH, $this->decide( $this->payment( array( 'amount' => 25000.0 ) ) ) );
		$payment = $this->payment();
		unset( $payment['amount'] );
		$this->assertSame( Order_Sync::HOLD_MISMATCH, $this->decide( $payment ) );
	}

	public function test_paid_in_another_currency_holds() {
		$this->assertSame( Order_Sync::HOLD_MISMATCH, $this->decide( $this->payment( array( 'currency' => 'USD' ) ) ) );
		$this->assertSame( Order_Sync::HOLD_MISMATCH, Order_Sync::decide( $this->payment(), 42, 25000, 'USD', 'inst_1' ) );
	}

	public function test_paid_against_an_order_total_that_is_not_whole_won_holds() {
		$this->assertSame( Order_Sync::HOLD_MISMATCH, Order_Sync::decide( $this->payment(), 42, null, 'KRW', 'inst_1' ) );
	}

	public function test_a_payment_for_another_order_is_ignored() {
		$this->assertSame( Order_Sync::MISMATCH, $this->decide( $this->payment( array( 'order_id' => '43' ) ) ) );
		$this->assertSame( Order_Sync::MISMATCH, $this->decide( $this->payment( array( 'order_id' => '042' ) ) ) );
		$this->assertSame( Order_Sync::MISMATCH, $this->decide( $this->payment( array( 'order_id' => array( '42' ) ) ) ) );
	}

	public function test_order_id_compares_as_a_string() {
		$this->assertSame( Order_Sync::COMPLETE, Order_Sync::decide( $this->payment( array( 'order_id' => 42 ) ), '42', 25000, 'KRW', 'inst_1' ) );
	}

	public function test_a_payment_of_another_installation_is_ignored() {
		$this->assertSame( Order_Sync::MISMATCH, $this->decide( $this->payment( array( 'installation' => 'inst_2' ) ) ) );
	}

	public function test_unknown_fields_are_ignored() {
		$this->assertSame( Order_Sync::COMPLETE, $this->decide( $this->payment( array( 'brand_new_field' => array( 'x' => 1 ) ) ) ) );
	}

	public function test_unreadable_payments_are_ignored() {
		$this->assertSame( Order_Sync::IGNORE, Order_Sync::decide( null, 42, 25000, 'KRW' ) );
		$this->assertSame( Order_Sync::IGNORE, Order_Sync::decide( 'paid', 42, 25000, 'KRW' ) );
		$this->assertSame( Order_Sync::IGNORE, Order_Sync::decide( array( 'status' => 'paid' ), 42, 25000, 'KRW' ) );
		$this->assertSame( Order_Sync::IGNORE, $this->decide( $this->payment( array( 'status' => array( 'paid' ) ) ) ) );
	}

	public function test_pick_prefers_paid_then_review_then_newest_open() {
		$superseded = $this->payment( array( 'id' => 'ppay_1', 'status' => 'superseded' ) );
		$failed     = $this->payment( array( 'id' => 'ppay_2', 'status' => 'failed' ) );
		$paid       = $this->payment( array( 'id' => 'ppay_3', 'status' => 'paid' ) );
		$review     = $this->payment( array( 'id' => 'ppay_4', 'status' => 'needs_review' ) );
		$waiting    = $this->payment( array( 'id' => 'ppay_5', 'status' => 'awaiting_payment' ) );

		$this->assertSame( 'ppay_3', Order_Sync::pick( array( $waiting, $review, $paid, $superseded ) )['id'] );
		$this->assertSame( 'ppay_4', Order_Sync::pick( array( $waiting, $review, $superseded ) )['id'] );
		$this->assertSame( 'ppay_5', Order_Sync::pick( array( $superseded, $waiting, $failed ) )['id'] );
		$this->assertSame( 'ppay_2', Order_Sync::pick( array( $failed, $waiting ) )['id'] );
		$this->assertNull( Order_Sync::pick( array( $superseded ) ) );
		$this->assertNull( Order_Sync::pick( array() ) );
		$this->assertNull( Order_Sync::pick( array( 'junk', null ) ) );
	}
}
