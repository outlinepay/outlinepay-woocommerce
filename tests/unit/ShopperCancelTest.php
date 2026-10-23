<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Refunds;
use OutlinePay\WooCommerce\Shopper_Cancel;

/**
 * "Cancel order" in My Account.
 */
class ShopperCancelTest extends RefundTestCase {

	const USER = 7;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'good-' . $action === $nonce ? 1 : false;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( self::USER );
	}

	private function cancel( $nonce = 'good-outlinepay_cancel_order_42', $user = self::USER ) {
		return Shopper_Cancel::cancel( 42, $user, $nonce );
	}

	public function test_a_paid_card_order_of_this_shopper_can_be_cancelled() {
		$this->assertTrue( Shopper_Cancel::can_cancel( $this->order, self::USER ) );
	}

	/**
	 * @dataProvider hidden_cases
	 */
	public function test_the_button_is_hidden_unless_every_rule_holds( $change ) {
		$change( $this );
		$this->assertFalse( Shopper_Cancel::can_cancel( $this->order, self::USER ) );
	}

	public function hidden_cases() {
		return array(
			'setting off'          => array(
				static function ( $t ) {
					$t->options['outlinepay_shopper_cancel']['enabled'] = 'no';
				},
			),
			'status not chosen'    => array(
				static function ( $t ) {
					$t->order->status = 'completed';
				},
			),
			'on hold not chosen'   => array(
				static function ( $t ) {
					$t->order->status = 'on-hold';
				},
			),
			'method not refundable' => array(
				static function ( $t ) {
					$t->order->payment_method = 'outlinepay_virtual_account';
				},
			),
			'another gateway'      => array(
				static function ( $t ) {
					$t->order->payment_method = 'bacs';
				},
			),
			'no refund permission' => array(
				static function ( $t ) {
					$t->options['outlinepay_installation']['permissions'] = array( 'payments.read' );
				},
			),
			'not connected'        => array(
				static function ( $t ) {
					$t->options['outlinepay_installation']['status'] = 'revoked';
				},
			),
			'not paid'             => array(
				static function ( $t ) {
					$t->order->date_paid = null;
				},
			),
			'held for review'      => array(
				static function ( $t ) {
					$t->order->meta[ Order_Sync::META_SETTLED ] = 'review';
				},
			),
			'already refunded'     => array(
				static function ( $t ) {
					$t->order->refunds[] = new \WC_Order_Refund( 1, '1000' );
				},
			),
			'refund pending'       => array(
				static function ( $t ) {
					Refunds::remember_pending( $t->order, 'pref_one', 25000, 'shopper' );
				},
			),
			'someone else\'s order' => array(
				static function ( $t ) {
					$t->order->customer_id = 8;
				},
			),
			'guest order'          => array(
				static function ( $t ) {
					$t->order->customer_id = 0;
				},
			),
		);
	}

	public function test_on_hold_can_be_offered_by_the_merchant() {
		$this->options['outlinepay_shopper_cancel']['statuses'] = array( 'processing', 'on-hold' );
		$this->order->status                                    = 'on-hold';
		$this->assertTrue( Shopper_Cancel::can_cancel( $this->order, self::USER ) );
	}

	public function test_the_setting_is_off_by_default_and_keeps_known_statuses_only() {
		unset( $this->options['outlinepay_shopper_cancel'] );
		$setting = \OutlinePay\WooCommerce\Settings::shopper_cancel();
		$this->assertFalse( $setting['enabled'] );
		$this->assertSame( array( 'processing' ), $setting['statuses'] );

		$this->options['outlinepay_shopper_cancel'] = array(
			'enabled'  => 'yes',
			'statuses' => array( 'completed', 'on-hold', 'refunded' ),
		);
		$this->assertSame( array( 'on-hold' ), \OutlinePay\WooCommerce\Settings::shopper_cancel()['statuses'] );
	}

	public function test_the_orders_list_links_to_the_order_page_never_to_a_cancel_url() {
		$actions = Shopper_Cancel::list_action( array( 'view' => array() ), $this->order );
		$this->assertSame( 'https://shop.example/my-account/view-order/42/#outlinepay-cancel', $actions['outlinepay-cancel']['url'] );
		$this->assertSame( 'Cancel', $actions['outlinepay-cancel']['name'], 'WooCommerce\'s own label' );
		$this->assertSame( 'Cancel order 42', $actions['outlinepay-cancel']['aria-label'] );

		$this->order->customer_id = 8;
		$this->assertSame( array( 'view' => array() ), Shopper_Cancel::list_action( array( 'view' => array() ), $this->order ) );
	}

	public function test_the_order_page_shows_only_the_form_not_a_second_cancel_link() {
		Functions\when( 'is_wc_endpoint_url' )->alias(
			static function ( $endpoint ) {
				return 'view-order' === $endpoint;
			}
		);
		$this->assertSame( array(), Shopper_Cancel::list_action( array(), $this->order ) );
	}

	public function test_woocommerce_native_cancel_is_removed_and_refused_for_a_paid_outlinepay_order() {
		$native = array(
			'view'   => array( 'url' => 'v' ),
			'cancel' => array( 'url' => 'https://shop.example/?cancel_order=true' ),
		);
		// Even with shopper cancel off and a status list widened by another plugin.
		$this->options['outlinepay_shopper_cancel']['enabled'] = 'no';
		$this->assertSame( array( 'view' => array( 'url' => 'v' ) ), Shopper_Cancel::list_action( $native, $this->order ) );
		$this->assertSame( array(), Shopper_Cancel::native_cancel_statuses( array( 'pending', 'failed', 'processing' ), $this->order ) );
	}

	public function test_an_unpaid_outlinepay_order_keeps_the_native_cancel() {
		$native                 = array( 'cancel' => array( 'url' => 'https://shop.example/?cancel_order=true' ) );
		$this->order->status    = 'pending';
		$this->order->date_paid = null;
		$this->assertSame( $native, Shopper_Cancel::list_action( $native, $this->order ) );
		$this->assertSame( array( 'pending', 'failed' ), Shopper_Cancel::native_cancel_statuses( array( 'pending', 'failed' ), $this->order ) );

		$other = new \WC_Order( 50 );
		$other->payment_method = 'bacs';
		$other->date_paid      = '2026-10-17T10:00:00+00:00';
		$this->assertSame( array( 'pending', 'failed' ), Shopper_Cancel::native_cancel_statuses( array( 'pending', 'failed' ), $other ), 'another gateway\'s order is not ours' );
		$this->assertSame( array( 'pending' ), Shopper_Cancel::native_cancel_statuses( array( 'pending' ), null ) );
	}

	public function test_the_form_is_a_post_with_a_nonce_and_a_confirm() {
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="good-' . $action . '">';
			}
		);
		ob_start();
		Shopper_Cancel::render_form( 42 );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'name="_outlinepay_cancel_nonce" value="good-outlinepay_cancel_order_42"', $html );
		$this->assertStringContainsString( 'Cancel this order? The full payment goes back to your card or account.', $html );
		$this->assertStringContainsString( '>Cancel</button>', $html );

		$this->order->customer_id = 8;
		ob_start();
		Shopper_Cancel::render_form( 42 );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_a_refund_on_the_order_shows_why_cancel_is_gone() {
		$this->order->refunds[] = new \WC_Order_Refund( 1, '1000' );
		$this->assertFalse( Shopper_Cancel::can_cancel( $this->order, self::USER ) );
		$this->assertTrue( Shopper_Cancel::blocked_by_refund( $this->order, self::USER ) );

		ob_start();
		Shopper_Cancel::render_form( 42 );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Cancellation is not available for this order. Contact the store.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_a_pending_refund_also_shows_why_cancel_is_gone() {
		Refunds::remember_pending( $this->order, 'pref_one', 25000, 'shopper' );
		$this->assertTrue( Shopper_Cancel::blocked_by_refund( $this->order, self::USER ) );
	}

	/**
	 * @dataProvider hidden_cases
	 */
	public function test_the_refund_line_shows_only_where_cancel_would_otherwise_show( $change ) {
		$change( $this );
		$this->order->refunds[] = new \WC_Order_Refund( 1, '1000' );
		$blocked                = Shopper_Cancel::blocked_by_refund( $this->order, self::USER );
		ob_start();
		Shopper_Cancel::render_form( 42 );
		$html = ob_get_clean();
		// Only the refund rules leave the line showing: every other rule hides it too.
		$this->assertSame( in_array( $this->dataName(), array( 'already refunded', 'refund pending' ), true ), $blocked, (string) $this->dataName() );
		$this->assertSame( $blocked, '' !== $html );
		if ( $blocked ) {
			$this->assertStringContainsString( 'Cancellation is not available', $html );
		}
	}

	public function test_no_refund_line_on_an_order_without_a_refund() {
		$this->assertFalse( Shopper_Cancel::blocked_by_refund( $this->order, self::USER ) );
	}

	public function test_no_refund_line_on_an_order_paid_another_way() {
		$this->order->payment_method = 'bacs';
		$this->order->refunds[]      = new \WC_Order_Refund( 1, '1000' );
		$this->assertFalse( Shopper_Cancel::blocked_by_refund( $this->order, self::USER ) );
	}

	public function test_a_bad_nonce_or_another_shopper_is_refused_before_anything_is_sent() {
		$this->assertSame( 'error', $this->cancel( 'stale' )['type'] );
		$this->assertSame( 'error', $this->cancel( 'good-outlinepay_cancel_order_42', 8 )['type'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'processing', $this->order->status );
	}

	public function test_a_confirmed_cancel_refunds_in_full_and_cancels_the_order() {
		$this->answers[] = self::json( 200, self::refund( array( 'requested_by' => 'shopper', 'refund_id' => 'shopper-cancel-42' ) ) );

		$result = $this->cancel();
		$this->assertSame( 'success', $result['type'] );
		$this->assertSame( 'Your order is cancelled. The full payment goes back to your card or account.', $result['message'] );

		$body = $this->requests[0]['body'];
		$this->assertSame( 25000, $body['amount'] );
		$this->assertSame( 'shopper-cancel-42', $body['refund_id'] );
		$this->assertSame( 'shopper', $body['requested_by'] );

		$this->assertSame( 'cancelled', $this->order->status, 'Cancelled, not Refunded' );
		$this->assertCount( 1, $this->order->refunds );
		$this->assertSame( 'Cancelled by the shopper', $this->order->refunds[0]->get_reason() );
		$this->assertSame( 'pref_one', $this->order->refunds[0]->meta[ Refunds::META_REFUND_ID ] );
		$this->assertSame( 'Order cancelled by customer. 25,000 won refunded through OutlinePay (refund pref_one).', $this->find_note( 'Order cancelled by customer.' ) );
		$this->assertSame( array(), $this->locks->rows );

		$this->assertSame( 'error', $this->cancel()['type'], 'a second click is refused' );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_cancel_being_confirmed_tells_the_shopper_and_completes_from_the_mirror() {
		$this->answers[] = self::json( 200, self::refund( array( 'status' => 'unknown', 'requested_by' => 'shopper', 'refund_id' => 'shopper-cancel-42' ) ) );

		$result = $this->cancel();
		$this->assertSame( 'notice', $result['type'] );
		$this->assertSame( 'Your cancellation is being confirmed. We will email you.', $result['message'] );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertSame( 'shopper', Refunds::pending( $this->order )['pref_one']['by'] );
		$this->assertSame( 'Order cancelled by customer. The refund is being confirmed with the payment provider (refund pref_one).', $this->find_note( 'Order cancelled by customer.' ) );
		$this->assertFalse( Shopper_Cancel::can_cancel( $this->order, self::USER ), 'no second request while it is confirmed' );

		Order_Sync::apply( $this->order, self::payment(), array( self::refund( array( 'requested_by' => 'shopper', 'refund_id' => 'shopper-cancel-42' ) ) ) );
		$this->assertSame( 'cancelled', $this->order->status );
		$this->assertStringContainsString( 'OutlinePay confirmed a refund of 25,000 won (refund pref_one): Cancelled by the shopper.', $this->notes() );
	}

	private function find_note( $prefix ) {
		foreach ( $this->order->notes as $note ) {
			if ( 0 === strpos( $note, $prefix ) ) {
				return $note;
			}
		}
		return null;
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_a_refused_cancel_leaves_the_order_and_shows_no_codes( $answer, $needle ) {
		$this->answers[] = $answer;
		$result          = $this->cancel();
		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( $needle, $result['message'] );
		$this->assertStringNotContainsString( '_', $result['message'] );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertCount( 0, $this->order->refunds );
	}

	public function refusals() {
		return array(
			'paused'      => array( self::refusal( 409, 'refunds_paused' ), 'Contact the store' ),
			'cap'         => array( self::refusal( 409, 'refund_cap_reached' ), 'Contact the store' ),
			'not full'    => array( self::refusal( 409, 'shopper_cancel_not_full' ), 'Contact the store' ),
			'provider no' => array( self::json( 200, self::refund( array( 'status' => 'failed', 'failure_code' => 'not_cancellable' ) ) ), 'Contact the store' ),
		);
	}
}
