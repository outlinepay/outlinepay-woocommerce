<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Order_Sync;

/**
 * The order screen offers "Refund via OutlinePay" only when money was taken.
 */
class RefundButtonTest extends TestCase {

	private $gateway;

	protected function setUp(): void {
		parent::setUp();
		$this->order            = new \WC_Order( 42 );
		$this->order->status    = 'pending';
		$this->order->date_paid = null;
		$this->order->meta      = array( Order_Sync::META_PAYMENT_ID => 'ppay_abc123' );

		$this->gateway                    = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$this->gateway->outlinepay_method = 'card';
		$this->gateway->supports          = array( 'products', 'refunds' );
	}

	public function test_a_pending_order_the_shopper_never_paid_gets_no_button() {
		$this->assertFalse( $this->gateway->can_refund_order( $this->order ) );
	}

	public function test_a_paid_order_gets_the_button() {
		$this->order->status    = 'processing';
		$this->order->date_paid = '2026-10-18 07:28:00';
		$this->assertTrue( $this->gateway->can_refund_order( $this->order ) );
	}

	public function test_a_hold_with_money_behind_it_gets_the_button() {
		$this->order->status = 'on-hold';
		foreach ( array( 'mismatch', 'review', 'foreign' ) as $hold ) {
			$this->order->meta[ Order_Sync::META_HOLD ] = $hold;
			$this->assertTrue( $this->gateway->can_refund_order( $this->order ), $hold );
		}
	}

	public function test_a_virtual_account_waiting_for_its_deposit_gets_no_button() {
		$this->order->status                       = 'on-hold';
		$this->order->meta[ Order_Sync::META_HOLD ] = 'deposit';
		$this->assertFalse( $this->gateway->can_refund_order( $this->order ) );
	}

	public function test_an_order_without_an_outlinepay_payment_gets_no_button() {
		$this->order->status    = 'processing';
		$this->order->date_paid = '2026-10-18 07:28:00';
		$this->order->meta      = array();
		$this->assertFalse( $this->gateway->can_refund_order( $this->order ) );
	}

	public function test_a_method_without_refunds_gets_no_button() {
		$this->order->status      = 'processing';
		$this->order->date_paid   = '2026-10-18 07:28:00';
		$this->gateway->supports  = array( 'products' );
		$this->assertFalse( $this->gateway->can_refund_order( $this->order ) );
	}
}
