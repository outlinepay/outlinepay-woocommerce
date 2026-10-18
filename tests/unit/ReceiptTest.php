<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Order_Display;
use OutlinePay\WooCommerce\Order_Sync;

/**
 * The card receipt link: stored on apply, shown escaped, only from safe addresses.
 */
class ReceiptTest extends RefundTestCase {

	const RECEIPT = 'https://www.cookiepayments.com/api/receipt?tid=QUJD';

	public function test_a_paid_payment_with_a_receipt_stores_it() {
		Order_Sync::apply( $this->order, self::payment( array( 'receipt_url' => self::RECEIPT ) ) );
		$this->assertSame( self::RECEIPT, $this->order->meta[ Order_Sync::META_RECEIPT ] );
	}

	public function test_a_receipt_of_a_payment_that_did_not_pay_the_order_is_not_stored() {
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine', 'ppay_dup' );
		Order_Sync::apply(
			$this->order,
			self::payment(
				array(
					'id'          => 'ppay_dup',
					'status'      => 'duplicate',
					'receipt_url' => self::RECEIPT,
				)
			)
		);
		$this->assertArrayNotHasKey( Order_Sync::META_RECEIPT, $this->order->meta );
	}

	/**
	 * @dataProvider urls
	 */
	public function test_only_https_or_the_api_host_is_accepted( $url, $ok ) {
		$this->assertSame( $ok ? $url : '', Order_Display::safe_receipt_url( $url ) );
	}

	public function urls() {
		return array(
			'https'              => array( self::RECEIPT, true ),
			'http elsewhere'     => array( 'http://www.cookiepayments.com/api/receipt?tid=QUJD', false ),
			'http on api host'   => array( 'http://api.outlinepay.com/receipt', true ),
			'javascript'         => array( 'javascript:alert(1)', false ),
			'data'               => array( 'data:text/html,hi', false ),
			'quote'              => array( 'https://x.example/"onmouseover=alert(1)', false ),
			'angle'              => array( 'https://x.example/<script>', false ),
			'credentials in url' => array( 'https://user:pass@x.example/', false ),
			'relative'           => array( '/receipt', false ),
			'not a string'       => array( array( 'https://x.example' ), false ),
			'empty'              => array( '', false ),
		);
	}

	public function test_the_link_is_escaped_and_opens_in_a_new_tab() {
		\Brain\Monkey\Functions\when( 'esc_url' )->alias(
			static function ( $url ) {
				return 'escaped(' . $url . ')';
			}
		);
		$this->order->meta[ Order_Sync::META_RECEIPT ] = 'https://x.example/r?a=1&b=2';
		$link = Order_Display::receipt_link( $this->order );
		$this->assertStringContainsString( 'href="escaped(https://x.example/r?a=1&b=2)"', $link );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $link );
		$this->assertStringContainsString( '>View card receipt</a>', $link );
	}

	public function test_a_stored_value_that_is_not_safe_is_never_shown() {
		$this->order->meta[ Order_Sync::META_RECEIPT ] = 'javascript:alert(1)';
		$this->assertSame( '', Order_Display::receipt_link( $this->order ) );
		$this->order->meta[ Order_Sync::META_RECEIPT ] = self::RECEIPT;
		$this->order->payment_method                   = 'bacs';
		$this->assertSame( '', Order_Display::receipt_link( $this->order ), 'moved to another gateway' );
	}

	public function test_the_completed_order_email_carries_the_link_and_other_emails_do_not() {
		$this->order->meta[ Order_Sync::META_RECEIPT ] = self::RECEIPT;
		$completed     = (object) array( 'id' => 'customer_completed_order' );
		$processing    = (object) array( 'id' => 'customer_processing_order' );

		ob_start();
		Order_Display::email_receipt( $this->order, false, false, $completed );
		$this->assertStringContainsString( 'View card receipt', ob_get_clean() );

		ob_start();
		Order_Display::email_receipt( $this->order, false, true, $completed );
		$this->assertStringContainsString( 'View card receipt: ' . self::RECEIPT, ob_get_clean() );

		ob_start();
		Order_Display::email_receipt( $this->order, false, false, $processing );
		Order_Display::email_receipt( $this->order, true, false, $completed );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_my_account_order_page_shows_the_deposit_details_and_the_receipt() {
		\Brain\Monkey\Functions\when( 'wp_date' )->justReturn( '2026-10-20 09:00' );
		$this->order->status                           = 'on-hold';
		$this->order->meta[ Order_Sync::META_HOLD ]    = 'deposit';
		$this->order->meta[ Order_Sync::META_VA ]      = array(
			'bank'           => 'KB<b>',
			'account_number' => '9123',
			'deadline'       => '2026-10-20T00:00:00Z',
		);
		ob_start();
		Order_Display::view_order( 42 );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Deposit details', $html );
		$this->assertStringContainsString( 'KB&lt;b&gt;', $html );
		$this->assertStringContainsString( '9123', $html );

		$this->order->status                           = 'processing';
		$this->order->meta[ Order_Sync::META_RECEIPT ] = self::RECEIPT;
		ob_start();
		Order_Display::view_order( 42 );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Deposit details', $html );
		$this->assertStringContainsString( 'View card receipt', $html );
	}
}
