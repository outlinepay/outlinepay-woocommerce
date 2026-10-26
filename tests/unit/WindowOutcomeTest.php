<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Return_Handler;

/**
 * What the shopper did in the provider's window: the notice on the way back
 * and one private note per attempt and outcome. The payment status still
 * decides the order.
 */
class WindowOutcomeTest extends RefundTestCase {

	const CANCELLED_NOTE = 'OutlinePay payment ppay_mine: the shopper cancelled in the payment window. The order is still waiting for payment.';
	const FAILED_NOTE    = 'OutlinePay payment ppay_mine: the payment provider refused the payment in its window and did not say why. The order is still waiting for payment.';

	/** @var string[] Notices shown to the shopper. */
	private $notices = array();

	protected function setUp(): void {
		parent::setUp();
		$this->order->status                           = 'pending';
		$this->order->date_paid                        = null;
		$this->order->meta[ Order_Sync::META_APPLIED ] = array();

		$notices = &$this->notices;
		Functions\when( 'wc_add_notice' )->alias(
			static function ( $message ) use ( &$notices ) {
				$notices[] = $message;
			}
		);
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => new \stdClass() ) );
	}

	private static function open( array $overrides = array() ) {
		return self::payment( array_merge( array( 'status' => 'awaiting_payment' ), $overrides ) );
	}

	// ---- reading the outcome ----

	public function test_the_outcome_is_read_for_an_open_or_failed_payment_only() {
		$cancelled = self::open( array( 'window_outcome' => 'cancelled' ) );
		$this->assertSame( 'cancelled', Order_Sync::window_outcome( $cancelled, Order_Sync::WAIT ) );
		$this->assertSame( 'failed', Order_Sync::window_outcome( self::open( array( 'window_outcome' => 'failed' ) ), Order_Sync::RETRY ) );
		$this->assertNull( Order_Sync::window_outcome( $cancelled, Order_Sync::MISMATCH ), 'another order\'s payment says nothing' );
		$this->assertNull( Order_Sync::window_outcome( $cancelled, Order_Sync::COMPLETE ) );
		$this->assertNull( Order_Sync::window_outcome( $cancelled, null ), 'the payment could not be read' );
	}

	public function test_an_old_server_or_an_unknown_value_gives_no_outcome() {
		$this->assertNull( Order_Sync::window_outcome( self::open(), Order_Sync::WAIT ), 'field absent' );
		$this->assertNull( Order_Sync::window_outcome( self::open( array( 'window_outcome' => null ) ), Order_Sync::WAIT ) );
		$this->assertNull( Order_Sync::window_outcome( self::open( array( 'window_outcome' => 'abandoned' ) ), Order_Sync::WAIT ) );
		$this->assertNull( Order_Sync::window_outcome( self::open( array( 'window_outcome' => array( 'cancelled' ) ) ), Order_Sync::WAIT ) );
	}

	// ---- notes ----

	public function test_a_cancel_in_the_window_leaves_one_note() {
		$decision = Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );

		$this->assertSame( Order_Sync::WAIT, $decision );
		$this->assertSame( array( self::CANCELLED_NOTE ), $this->order->notes );
		$this->assertSame( 'pending', $this->order->status, 'the status decides the order' );
	}

	public function test_a_refusal_in_the_window_leaves_one_note() {
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'failed' ) ) );

		$this->assertSame( array( self::FAILED_NOTE ), $this->order->notes );
	}

	public function test_a_failed_payment_with_a_window_refusal_gets_the_window_note_only() {
		$decision = Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'status'         => 'failed',
					'window_outcome' => 'failed',
				)
			)
		);

		$this->assertSame( Order_Sync::RETRY, $decision );
		$this->assertSame( array( self::FAILED_NOTE ), $this->order->notes );
	}

	public function test_an_expired_payment_the_shopper_cancelled_keeps_both_notes() {
		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'status'         => 'expired',
					'window_outcome' => 'cancelled',
				)
			)
		);

		$this->assertCount( 2, $this->order->notes );
		$this->assertStringContainsString( 'ppay_mine is expired', $this->notes() );
		$this->assertStringContainsString( self::CANCELLED_NOTE, $this->notes() );
	}

	public function test_a_reload_or_a_second_return_adds_no_other_note() {
		$payment = self::open( array( 'window_outcome' => 'cancelled' ) );
		Order_Sync::apply( $this->order, $payment );
		Order_Sync::apply( $this->order, $payment );
		Order_Sync::apply( $this->order, array_merge( $payment, array( 'status' => 'failed' ) ) );

		$this->assertSame( 1, substr_count( $this->notes(), self::CANCELLED_NOTE ) );
	}

	public function test_a_new_attempt_gets_its_own_note() {
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine', 'ppay_next' );
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		Order_Sync::apply(
			$this->order,
			self::open(
				array(
					'id'             => 'ppay_next',
					'window_outcome' => 'cancelled',
				)
			)
		);

		$this->assertCount( 2, $this->order->notes );
		$this->assertStringContainsString( 'OutlinePay payment ppay_next: the shopper cancelled', $this->notes() );
	}

	public function test_one_attempt_can_note_each_outcome_once() {
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'failed' ) ) );
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'failed' ) ) );

		$this->assertSame( array( self::FAILED_NOTE, self::CANCELLED_NOTE ), $this->order->notes );
	}

	public function test_no_note_for_an_old_server_an_unknown_value_or_another_stores_payment() {
		Order_Sync::apply( $this->order, self::open() );
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'abandoned' ) ) );
		Order_Sync::apply(
			$this->order,
			self::open(
				array(
					'id'             => 'ppay_fromclone',
					'window_outcome' => 'cancelled',
				)
			)
		);

		$this->assertSame( array(), $this->order->notes );
	}

	public function test_no_note_when_the_order_is_paid_or_on_hold() {
		$this->order->status = 'processing';
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		$this->order->status = 'on-hold';
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'failed' ) ) );

		$this->assertSame( array(), $this->order->notes );
	}

	public function test_a_held_order_whose_attempt_fails_keeps_the_status_note() {
		$this->order->status                        = 'on-hold';
		$this->order->meta[ Order_Sync::META_HOLD ] = 'mismatch';

		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'status'         => 'failed',
					'window_outcome' => 'failed',
				)
			)
		);

		$this->assertSame( array( 'OutlinePay payment ppay_mine is failed. The order is waiting for payment.' ), $this->order->notes );
	}

	public function test_an_order_another_gateway_already_paid_gets_no_window_note() {
		$this->order->payment_method = 'bacs';
		$this->order->date_paid      = '2026-10-17T10:00:00+00:00';

		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'status'         => 'failed',
					'window_outcome' => 'failed',
				)
			)
		);

		$this->assertSame( array(), $this->order->notes );
	}

	public function test_a_test_attempt_says_test_mode_in_its_window_note() {
		Order_Sync::apply(
			$this->order,
			self::open(
				array(
					'mode'           => 'test',
					'window_outcome' => 'cancelled',
				)
			)
		);
		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'mode'           => 'test',
					'status'         => 'failed',
					'window_outcome' => 'failed',
				)
			)
		);

		$this->assertSame( array( self::CANCELLED_NOTE . ' (test mode)', self::FAILED_NOTE . ' (test mode)' ), $this->order->notes );
	}

	public function test_the_note_is_written_in_the_site_language() {
		$locales = array( 'en_US' );
		Functions\when( 'get_locale' )->justReturn( 'ko_KR' );
		Functions\when( 'switch_to_locale' )->alias(
			static function ( $locale ) use ( &$locales ) {
				$locales[] = $locale;
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			static function () use ( &$locales ) {
				array_pop( $locales );
				return true;
			}
		);
		Functions\when( '__' )->alias(
			static function ( $text ) use ( &$locales ) {
				return '[' . end( $locales ) . '] ' . $text;
			}
		);

		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );

		$this->assertSame( array( '[ko_KR] ' . self::CANCELLED_NOTE ), $this->order->notes );
		$this->assertSame( array( 'en_US' ), $locales, 'the request locale comes back' );
	}

	// ---- the shopper's notice ----

	public function test_a_cancel_or_refusal_goes_to_the_pay_page_without_a_session_notice() {
		$this->assertSame( $this->order->get_checkout_payment_url(), Return_Handler::destination( $this->order, Order_Sync::WAIT, 'cancelled' ) );
		$this->assertSame( $this->order->get_checkout_payment_url(), Return_Handler::destination( $this->order, Order_Sync::RETRY, 'failed' ) );
		$this->assertSame( array(), $this->notices, 'the pay page tells it from the order' );
	}

	// ---- the pay page tells the shopper, from the order ----

	/** @var string[] Notices the pay page printed. */
	private $printed = array();

	/** Prints what the pay page shows for the order, with no shopper session. */
	private function pay_page() {
		$printed = &$this->printed;
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );
		Functions\when( 'wc_print_notice' )->alias(
			static function ( $message, $type ) use ( &$printed ) {
				$printed[] = $type . ': ' . $message;
			}
		);
		Return_Handler::pay_page_notice( $this->order );
		return $this->printed;
	}

	public function test_the_pay_page_tells_a_cancel_from_the_order_with_no_session() {
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );

		$this->assertSame( array( 'error: You cancelled the payment. Pay again or choose another method.' ), $this->pay_page() );
	}

	public function test_the_pay_page_tells_a_refusal_from_the_order() {
		Order_Sync::apply( $this->order, self::payment( array( 'status' => 'failed', 'window_outcome' => 'failed' ) ) );

		$this->assertSame( array( 'error: The payment was refused. Try another card or payment method.' ), $this->pay_page() );
	}

	public function test_the_pay_page_tells_it_once() {
		$payment = self::open( array( 'window_outcome' => 'cancelled' ) );
		Order_Sync::apply( $this->order, $payment );
		$this->pay_page();
		$this->pay_page();
		// A second return or a late payment update for the same attempt.
		Order_Sync::apply( $this->order, $payment );
		$this->pay_page();

		$this->assertCount( 1, $this->printed );
		$this->assertArrayNotHasKey( Order_Sync::META_TELL, $this->order->meta );
	}

	public function test_the_pay_page_says_nothing_once_a_newer_attempt_started() {
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		$this->order->meta[ Order_Sync::META_ATTEMPTS ][] = 'ppay_next';

		$this->assertSame( array(), $this->pay_page() );
	}

	public function test_a_newer_attempt_gets_its_own_message() {
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine', 'ppay_next' );
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		$this->assertSame( array(), $this->pay_page(), 'ppay_mine is not the latest attempt' );

		Order_Sync::apply( $this->order, self::open( array( 'id' => 'ppay_next', 'window_outcome' => 'failed' ) ) );
		$this->assertSame( array( 'error: The payment was refused. Try another card or payment method.' ), $this->pay_page() );
	}

	public function test_the_pay_page_says_nothing_when_the_order_is_paid() {
		Order_Sync::apply( $this->order, self::open( array( 'window_outcome' => 'cancelled' ) ) );
		$this->order->status = 'processing';

		$this->assertSame( array(), $this->pay_page() );
	}

	public function test_the_pay_page_says_nothing_without_an_outcome() {
		Order_Sync::apply( $this->order, self::open() );
		$saves = $this->order->saves;

		$this->assertSame( array(), $this->pay_page() );
		$this->assertSame( $saves, $this->order->saves, 'nothing to clear: no save' );
	}

	public function test_without_an_outcome_the_notices_stay_as_before() {
		Return_Handler::destination( $this->order, Order_Sync::RETRY, null );
		Return_Handler::destination( $this->order, Order_Sync::WAIT, null );
		Return_Handler::destination( $this->order, null );

		$this->assertSame(
			array(
				'Your payment was not completed. Try again or choose another method.',
				'We could not confirm your payment yet. If you paid, this order updates in a few minutes. Otherwise, pay again below.',
				'We could not confirm your payment yet. If you paid, this order updates in a few minutes. Otherwise, pay again below.',
			),
			$this->notices
		);
	}

	public function test_a_paid_or_held_order_goes_to_the_thank_you_page_without_a_notice() {
		$this->order->status = 'processing';
		$this->assertSame( $this->order->get_checkout_order_received_url(), Return_Handler::destination( $this->order, Order_Sync::WAIT, 'cancelled' ) );
		$this->order->status = 'on-hold';
		$this->assertSame( $this->order->get_checkout_order_received_url(), Return_Handler::destination( $this->order, Order_Sync::RETRY, 'failed' ) );
		$this->assertSame( array(), $this->notices );
	}
}
