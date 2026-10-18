<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Refunds;
use OutlinePay\WooCommerce\Settings;

/**
 * The Refund button: Gateway::process_refund through Refunds::process.
 */
class ProcessRefundTest extends RefundTestCase {

	public function test_a_succeeded_refund_returns_true_and_is_recorded_on_the_woocommerce_refund() {
		$row             = $this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'refund_id' => (string) $row->get_id() ) ) );

		$this->assertTrue( Refunds::process( 42, '5000.00' ) );

		$request = $this->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://api.example.test/plugin/v1/payments/ppay_mine/refunds', $request['url'] );
		$this->assertSame( 5000, $request['body']['amount'], 'a JSON number' );
		$this->assertSame( (string) $row->get_id(), $request['body']['refund_id'] );
		$this->assertSame( 'store', $request['body']['requested_by'] );

		$this->assertSame( 'pref_one', $row->meta[ Refunds::META_REFUND_ID ] );
		$this->assertSame( 'succeeded', $row->meta[ Refunds::META_REFUND_STATUS ] );
		$this->assertStringContainsString( '5,000 won', $this->notes() );
		$this->assertStringContainsString( 'pref_one', $this->notes() );
		$this->assertSame( array(), $this->locks->rows, 'the lock is released' );
	}

	public function test_the_gateway_hands_the_refund_to_refunds() {
		$this->wc_refund_row( 25000 );
		$this->answers[] = self::json( 200, self::refund() );
		$gateway         = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$this->assertTrue( $gateway->process_refund( 42, 25000, 'damaged' ) );
		$this->assertArrayNotHasKey( 'reason', $this->requests[0]['body'], 'the reason stays in WooCommerce' );
	}

	public function test_the_newest_unrecorded_refund_of_that_amount_is_the_one_just_saved() {
		$older = $this->wc_refund_row( 5000, 800 );
		$taken = $this->wc_refund_row( 5000, 850 );
		$taken->meta[ Refunds::META_REFUND_ID ] = 'pref_old';
		$other = $this->wc_refund_row( 7000, 870 );
		$new   = $this->wc_refund_row( 5000, 860 );

		$this->assertSame( $new, Refunds::find_new_refund( $this->order, 5000 ) );
		$this->assertSame( $other, Refunds::find_new_refund( $this->order, 7000 ) );
		$this->assertNull( Refunds::find_new_refund( $this->order, 9000 ) );
		unset( $older );
	}

	public function test_no_matching_row_refuses_without_calling_outlinepay() {
		$result = Refunds::process( 42, 5000 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * @dataProvider failures
	 */
	public function test_a_failed_refund_is_an_error_with_a_plain_sentence( $failure_code, $needle ) {
		$this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'status' => 'failed', 'failure_code' => $failure_code ) ) );

		$result = Refunds::process( 42, 5000 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( $needle, $result->get_error_message() );
		$this->assertStringContainsString( 'Nothing was refunded', $this->notes() );
		$this->assertContains( 'pref_one', $this->order->meta[ Refunds::META_DONE ] );
	}

	public function failures() {
		return array(
			'not cancellable' => array( 'not_cancellable', 'refused this refund' ),
			'invalid amount'  => array( 'invalid_amount', 'did not accept this amount' ),
		);
	}

	/**
	 * @dataProvider open_statuses
	 */
	public function test_a_refund_still_being_confirmed_is_remembered_and_reported( $status ) {
		$this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'status' => $status ) ) );

		$result = Refunds::process( 42, 5000 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'OutlinePay is confirming this refund with the payment provider. It will appear on this order automatically once confirmed.', $result->get_error_message() );
		$this->assertSame(
			array(
				'pref_one' => array(
					'amount' => 5000,
					'by'     => 'store',
				),
			),
			Refunds::pending( $this->order )
		);
		$this->assertStringContainsString( 'confirming a refund of 5,000 won', $this->notes() );
	}

	public function open_statuses() {
		return array(
			'pending' => array( 'pending' ),
			'unknown' => array( 'unknown' ),
		);
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_each_refusal_is_one_plain_sentence_and_records_nothing( $status, $code, $needle ) {
		$this->wc_refund_row( 5000 );
		$this->answers[] = self::refusal( $status, $code );

		$result = Refunds::process( 42, 5000 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( $needle, $result->get_error_message() );
		$this->assertStringNotContainsString( $code, $result->get_error_message(), 'no technical codes' );
		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( array(), Refunds::pending( $this->order ) );
		$this->assertArrayNotHasKey( Refunds::META_UNANSWERED, $this->order->meta );
	}

	public function refusals() {
		return array(
			array( 409, 'refunds_paused', 'Refunds are paused' ),
			array( 409, 'payment_not_refundable', 'cannot refund this payment' ),
			array( 409, 'refund_not_supported', 'cannot be refunded from WooCommerce' ),
			array( 409, 'partial_refund_not_supported', 'Partial refunds work for card payments only. Refund the full amount, or refund the rest in your payment provider\'s dashboard.' ),
			array( 409, 'refund_exceeds_remaining', 'This is more than what is left to refund.' ),
			array( 409, 'refund_cap_reached', 'Today\'s refund limit for this store is reached. Ask your payment provider.' ),
			array( 409, 'refund_id_reused', 'already has a different refund' ),
			array( 409, 'shopper_cancel_not_full', 'everything that is left' ),
			array( 409, 'some_new_code', 'did not accept this refund' ),
			array( 403, 'permission_denied', 'has not turned on refunds' ),
			array( 400, 'invalid_request', 'whole number of won' ),
			array( 404, 'not_found', 'does not know this payment' ),
		);
	}

	public function test_no_answer_is_reported_honestly_and_a_retry_of_the_same_amount_reuses_the_first_id() {
		$first           = $this->wc_refund_row( 5000 );
		$this->answers[] = new Api_Error( 0, 'network_error' );

		$result = Refunds::process( 42, 5000 );
		$this->assertStringContainsString( 'may or may not have gone through', $result->get_error_message() );
		$this->assertSame( array( (string) $first->get_id() => 5000 ), $this->order->meta[ Refunds::META_UNANSWERED ] );

		// WooCommerce deleted the first row. The merchant clicks Refund again.
		$this->order->refunds = array();
		$second               = $this->wc_refund_row( 5000 );
		$this->answers[]      = self::json( 200, self::refund( array( 'amount' => 5000 ) ) );

		$this->assertTrue( Refunds::process( 42, 5000 ) );
		$this->assertSame( (string) $first->get_id(), $this->requests[1]['body']['refund_id'], 'OutlinePay answers the refund it already made' );
		$this->assertSame( 'pref_one', $second->meta[ Refunds::META_REFUND_ID ] );
		$this->assertArrayNotHasKey( Refunds::META_UNANSWERED, $this->order->meta );
	}

	public function test_server_trouble_is_treated_like_no_answer() {
		$this->wc_refund_row( 5000 );
		$this->answers[] = self::refusal( 502, 'processor_error' );
		$this->assertStringContainsString( 'may or may not have gone through', Refunds::process( 42, 5000 )->get_error_message() );
	}

	public function test_a_retry_answered_with_a_refund_the_order_already_shows_is_not_recorded_twice() {
		$recorded                                  = $this->wc_refund_row( 5000, 700 );
		$recorded->meta[ Refunds::META_REFUND_ID ] = 'pref_one';
		$this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000 ) ) );

		$result = Refunds::process( 42, 5000 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'already on this order', $result->get_error_message() );
	}

	public function test_nothing_is_sent_when_the_store_is_not_connected_or_the_order_cannot_be_refunded() {
		$this->wc_refund_row( 5000 );

		$this->assertInstanceOf( \WP_Error::class, Refunds::process( 42, '5000.50' ), 'not whole won' );
		$this->assertInstanceOf( \WP_Error::class, Refunds::process( 99, 5000 ), 'no such order' );

		$this->order->meta['_outlinepay_settled'] = 'double';
		$this->assertInstanceOf( \WP_Error::class, Refunds::process( 42, 5000 ), 'the payment did not pay this order' );
		unset( $this->order->meta['_outlinepay_settled'] );

		$this->disconnect();
		$this->assertStringContainsString( 'not connected', Refunds::process( 42, 5000 )->get_error_message() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_refunds_are_offered_only_with_the_permission_and_a_method_the_provider_can_refund() {
		foreach ( array( 'card', 'foreign_card_krw', 'bank_transfer', 'kakaopay', 'naverpay' ) as $method ) {
			$this->assertSame( array( 'products', 'refunds' ), Gateway::features( $method ), $method );
		}
		foreach ( array( 'virtual_account', 'carrier_billing' ) as $method ) {
			$this->assertSame( array( 'products' ), Gateway::features( $method ), $method );
		}
		$this->refunds_off();
		$this->assertSame( array( 'products' ), Gateway::features( 'card' ) );
		$this->assertFalse( Settings::refunds_permitted() );
	}

	public function test_the_installation_keeps_the_refund_fields() {
		$saved = null;
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);
		Settings::save_installation(
			array(
				'id'               => 'inst_1',
				'refunds_enabled'  => true,
				'refund_daily_cap' => 500000,
				'merchant_email'   => 'kept-out@example.com',
				'token'            => 'olp_it_secret',
			)
		);
		$this->assertTrue( $saved['refunds_enabled'] );
		$this->assertSame( 500000, $saved['refund_daily_cap'] );
		$this->assertArrayNotHasKey( 'merchant_email', $saved );
		$this->assertArrayNotHasKey( 'token', $saved );
	}
}
