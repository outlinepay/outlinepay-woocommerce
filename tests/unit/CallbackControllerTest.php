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

	private function controller( $secret = self::SECRET, $reader = null, $order = 'order-42', $decision = Order_Sync::COMPLETE ) {
		$reads   = &$this->reads;
		$applied = &$this->applied;
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
			static function ( $order, $payment ) use ( &$applied, $decision ) {
				$applied[] = array( $order, $payment );
				return $decision;
			}
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
}
