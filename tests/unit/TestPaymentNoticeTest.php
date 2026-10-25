<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Order_Display;
use OutlinePay\WooCommerce\Order_Sync;

/**
 * The shopper's test payment notice: on the order received page and in
 * customer emails, only for an order OutlinePay completed in test mode.
 */
class TestPaymentNoticeTest extends RefundTestCase {

	const NOTICE = 'Test payment. This order was paid through the payment provider\'s test system and is not a real sale.';

	/** The notice as esc_html prints it. */
	const NOTICE_HTML = 'Test payment. This order was paid through the payment provider&#039;s test system and is not a real sale.';

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	private function thankyou() {
		ob_start();
		Order_Display::thankyou( 42 );
		return ob_get_clean();
	}

	private function email( $sent_to_admin = false, $plain_text = false ) {
		ob_start();
		Order_Display::email_test_payment( $this->order, $sent_to_admin, $plain_text );
		return ob_get_clean();
	}

	public function test_a_test_order_shows_the_notice_on_the_order_received_page() {
		$this->order->meta[ Order_Sync::META_MODE ] = 'test';

		$html = $this->thankyou();

		$this->assertSame( 1, substr_count( $html, self::NOTICE_HTML ) );
		$this->assertStringContainsString( 'class="woocommerce-info outlinepay-test-payment"', $html );
	}

	public function test_a_test_order_shows_the_notice_in_customer_emails() {
		$this->order->meta[ Order_Sync::META_MODE ] = 'test';

		$this->assertStringContainsString( '<strong>' . self::NOTICE_HTML . '</strong>', $this->email() );
		$this->assertSame( self::NOTICE . "\n\n", $this->email( false, true ), 'plain text keeps the apostrophe' );
		$this->assertSame( '', $this->email( true ), 'not in the email to the store' );
	}

	public function test_a_live_order_or_one_never_completed_shows_nothing() {
		$this->order->meta[ Order_Sync::META_MODE ] = 'live';
		$this->assertStringNotContainsString( 'Test payment', $this->thankyou() );
		$this->assertSame( '', $this->email() );

		unset( $this->order->meta[ Order_Sync::META_MODE ] );
		$this->assertStringNotContainsString( 'Test payment', $this->thankyou() );
		$this->assertSame( '', $this->email() );
	}

	public function test_the_store_being_in_test_mode_now_does_not_mark_a_live_order() {
		$this->options['outlinepay_installation']['mode'] = 'test';
		$this->order->meta[ Order_Sync::META_MODE ]       = 'live';

		$this->assertStringNotContainsString( 'Test payment', $this->thankyou() );
		$this->assertSame( '', $this->email() );
	}

	public function test_an_order_moved_to_another_method_shows_nothing() {
		$this->order->meta[ Order_Sync::META_MODE ] = 'test';
		$this->order->payment_method                = 'bacs';

		$this->assertSame( '', $this->email() );
	}

	public function test_a_test_completion_records_the_mode_for_the_notice() {
		$this->order->status                           = 'pending';
		$this->order->date_paid                        = null;
		$this->order->meta[ Order_Sync::META_APPLIED ] = array();

		Order_Sync::apply( $this->order, self::payment( array( 'mode' => 'test' ) ) );

		$this->assertSame( 'test', $this->order->meta[ Order_Sync::META_MODE ] );
		$this->assertStringContainsString( self::NOTICE_HTML, $this->email() );
	}

	public function test_the_notice_never_says_no_money_was_charged() {
		$this->assertStringNotContainsString( 'no money', strtolower( Order_Display::test_payment_notice() ) );
		$this->assertStringNotContainsString( 'not charged', strtolower( Order_Display::test_payment_notice() ) );
	}
}
