<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Callback_Controller;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Signature;

class CallbackControllerTest extends TestCase {

	const SECRET = 'olp_isec_callbacksecret';
	const NOW    = 1790000000;

	/** @var array Calls the fakes saw. */
	private $reads   = array();
	private $applied = array();

	private function body( array $overrides = array() ) {
		return json_encode(
			array_merge(
				array(
					'id'       => 'ppay_abc',
					'object'   => 'plugin_payment',
					'order_id' => '42',
					'status'   => 'paid',
				),
				$overrides
			)
		);
	}

	/** @var array Refund ids the fake refund reader saw. */
	private $refund_reads = array();

	private function controller( $secret = self::SECRET, $reader = null, $order = 'order-42', $decision = Order_Sync::COMPLETE, $copy = false, $installation = 'inst_abc', $refund_reader = null ) {
		$reads        = &$this->reads;
		$applied      = &$this->applied;
		$refund_reads = &$this->refund_reads;
		return new Callback_Controller(
			static function () use ( $secret ) {
				return $secret;
			},
			$reader ? $reader : static function ( $id ) use ( &$reads ) {
				$reads[] = $id;
				return array(
					'id'     => $id,
					'status' => 'paid',
				);
			},
			static function ( $order_id ) use ( $order ) {
				return 42 === $order_id ? $order : null;
			},
			static function ( $order, $payment, $refunds = array() ) use ( &$applied, $decision ) {
				$applied[] = array( $order, $payment, $refunds );
				return $decision;
			},
			static function () use ( $copy ) {
				return $copy;
			},
			static function () use ( $installation ) {
				return $installation;
			},
			$refund_reader ? $refund_reader : static function ( $id ) use ( &$refund_reads ) {
				$refund_reads[] = $id;
				return array(
					'id'     => $id,
					'status' => 'succeeded',
				);
			}
		);
	}

	public function test_a_refund_delivery_reads_each_refund_back_and_applies_those_answers() {
		$body   = $this->body(
			array(
				'refunds' => array(
					array(
						'id'     => 'pref_a',
						'status' => 'succeeded',
						'amount' => 999999,
					),
					array( 'id' => 'pref_b' ),
				),
			)
		);
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'pref_a', 'pref_b' ), $this->refund_reads );
		$this->assertSame(
			array(
				array(
					'id'     => 'pref_a',
					'status' => 'succeeded',
				),
				array(
					'id'     => 'pref_b',
					'status' => 'succeeded',
				),
			),
			$this->applied[0][2],
			'the read-back answers, never the delivered list'
		);
	}

	public function test_server_trouble_while_reading_a_refund_asks_for_a_retry() {
		$reader = static function () {
			throw new Api_Error( 0, 'network_error' );
		};
		$body   = $this->body( array( 'refunds' => array( array( 'id' => 'pref_a' ) ) ) );
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::COMPLETE, false, 'inst_abc', $reader )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 503, $result['status'] );
		$this->assertSame( array(), $this->applied );
	}

	public function test_a_refund_this_store_cannot_read_is_skipped() {
		$reader = static function () {
			throw new Api_Error( 404, 'not_found' );
		};
		$body   = $this->body( array( 'refunds' => array( array( 'id' => 'pref_a' ) ) ) );
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::COMPLETE, false, 'inst_abc', $reader )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), $this->applied[0][2] );
	}

	public function test_an_awaiting_deposit_delivery_is_read_back_and_applied() {
		$body   = $this->body( array( 'status' => 'awaiting_deposit' ) );
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'ppay_abc' ), $this->reads );
		$this->assertCount( 1, $this->applied );
	}

	private static function ping( $installation = 'inst_abc' ) {
		return json_encode(
			array(
				'type'         => 'self_check',
				'installation' => $installation,
				'sent_at'      => '2026-10-16T01:02:03Z',
			)
		);
	}

	public function test_a_valid_callback_reads_the_payment_back_and_applies_that() {
		$body   = $this->body( array( 'status' => 'paid' ) );
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'ppay_abc' ), $this->reads );
		$this->assertCount( 1, $this->applied );
		$this->assertSame( 'order-42', $this->applied[0][0] );
	}

	public function test_a_missing_signature_is_401() {
		$result = $this->controller()->process( $this->body(), '', self::NOW );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( array(), $this->reads );
	}

	public function test_a_stale_signature_is_401() {
		$body   = $this->body();
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW - 600 ), self::NOW );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( array(), $this->reads );
	}

	public function test_a_tampered_body_is_401() {
		$body   = $this->body();
		$header = Signature::header( self::SECRET, $body, self::NOW );
		$result = $this->controller()->process( $this->body( array( 'order_id' => '43' ) ), $header, self::NOW );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( array(), $this->applied );
	}

	public function test_a_malformed_signature_is_401() {
		$result = $this->controller()->process( $this->body(), 'v1=deadbeef', self::NOW );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_a_store_that_is_not_connected_rejects_everything() {
		$body   = $this->body();
		$result = $this->controller( null )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_a_signed_but_unreadable_body_is_400() {
		foreach ( array( 'not json', json_encode( array( 'id' => '../x', 'order_id' => '42' ) ), json_encode( array( 'id' => 'ppay_abc', 'order_id' => 'abc' ) ) ) as $body ) {
			$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
			$this->assertSame( 400, $result['status'] );
		}
		$this->assertSame( array(), $this->reads );
	}

	public function test_an_order_this_store_does_not_have_is_acknowledged_and_ignored() {
		$body   = $this->body( array( 'order_id' => '999' ) );
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), $this->reads );
	}

	public function test_server_trouble_while_reading_back_asks_for_a_retry() {
		$reader = static function () {
			throw new Api_Error( 503, 'processor_error' );
		};
		$body   = $this->body();
		$result = $this->controller( self::SECRET, $reader )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 503, $result['status'] );
		$this->assertSame( array(), $this->applied );
	}

	public function test_a_payment_the_server_does_not_know_is_acknowledged() {
		$reader = static function () {
			throw new Api_Error( 404, 'not_found' );
		};
		$body   = $this->body();
		$result = $this->controller( self::SECRET, $reader )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), $this->applied );
	}

	public function test_a_busy_order_asks_for_a_retry() {
		$body   = $this->body();
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::BUSY )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 503, $result['status'] );
	}

	public function test_a_copy_of_the_store_answers_signed_callbacks_without_touching_orders() {
		$body   = $this->body();
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::COMPLETE, true )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'ignored_copy', $result['body']['result'] );
		$this->assertSame( array(), $this->reads, 'no call to OutlinePay' );
		$this->assertSame( array(), $this->applied, 'no order change' );
	}

	public function test_a_copy_still_rejects_a_bad_signature() {
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::COMPLETE, true )->process( $this->body(), 't=1,v1=00', self::NOW );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_a_signed_self_check_ping_for_this_installation_answers_ok_and_touches_nothing() {
		$body   = self::ping();
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'ok' => true ), $result['body'] );
		$this->assertSame( array(), $this->reads, 'no call to OutlinePay' );
		$this->assertSame( array(), $this->applied, 'no order change' );
	}

	public function test_a_self_check_ping_for_another_installation_is_400() {
		$body   = self::ping( 'inst_other' );
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 400, $result['status'] );
		$this->assertSame( array(), $this->reads );
	}

	public function test_a_self_check_ping_without_an_installation_id_is_400() {
		$body   = json_encode( array( 'type' => 'self_check' ) );
		$result = $this->controller()->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 400, $result['status'] );
	}

	public function test_a_self_check_ping_when_no_installation_is_saved_is_400() {
		$body   = self::ping();
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::COMPLETE, false, '' )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 400, $result['status'] );
	}

	public function test_a_self_check_ping_with_a_bad_signature_gets_the_usual_refusal() {
		$body   = self::ping();
		$result = $this->controller()->process( $body, Signature::header( 'olp_isec_wrong', $body, self::NOW ), self::NOW );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'invalid_signature', $result['body']['result'] );
	}

	public function test_a_copy_answers_a_self_check_ping_as_copies_answer() {
		$body   = self::ping();
		$result = $this->controller( self::SECRET, null, 'order-42', Order_Sync::COMPLETE, true )->process( $body, Signature::header( self::SECRET, $body, self::NOW ), self::NOW );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'ignored_copy', $result['body']['result'] );
	}
}
