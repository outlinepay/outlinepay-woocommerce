<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Switch_Guard;

class SwitchGuardTest extends TestCase {

	/** @var array Requests seen by the fake transport. */
	private $requests = array();

	/**
	 * A client answering list with $list and each expire from $expires (by id).
	 */
	private function client( array $list, array $expires ) {
		$requests = &$this->requests;
		return new Api_Client(
			static function () {
				return 'olp_it_test_TOKEN';
			},
			static function ( $method, $url, $headers, $body, $timeout ) use ( &$requests, $list, $expires ) {
				$requests[] = array( $method, $url, $timeout );
				if ( 'GET' === $method ) {
					return array(
						'status' => 200,
						'body'   => json_encode( array( 'data' => $list ) ),
					);
				}
				preg_match( '#/payments/(ppay_\w+)/expire#', $url, $m );
				$answer = $expires[ $m[1] ];
				if ( $answer instanceof Api_Error ) {
					throw $answer;
				}
				return $answer;
			},
			'https://api.example.test/plugin/v1'
		);
	}

	private static function ok( $id, $status ) {
		return array(
			'status' => 200,
			'body'   => json_encode(
				array(
					'id'     => $id,
					'status' => $status,
				)
			),
		);
	}

	public function test_a_409_on_one_expire_does_not_stop_the_others_and_a_paid_one_is_found() {
		Functions\when( 'do_action' )->justReturn( null );
		$list   = array(
			array(
				'id'     => 'ppay_a',
				'status' => 'awaiting_payment',
			),
			array(
				'id'     => 'ppay_b',
				'status' => 'awaiting_payment',
			),
		);
		$client = $this->client(
			$list,
			array(
				'ppay_a' => array(
					'status' => 409,
					'body'   => '{"error":{"code":"invalid_request"}}',
				),
				'ppay_b' => self::ok( 'ppay_b', 'paid' ),
			)
		);
		$settled = Switch_Guard::find_settled( 42, $client );
		$this->assertSame( 'ppay_b', $settled['id'] );
		$this->assertCount( 3, $this->requests, 'list, then both expires' );
	}

	public function test_a_network_error_on_one_expire_is_skipped_too() {
		$client  = $this->client(
			array(
				array(
					'id'     => 'ppay_a',
					'status' => 'awaiting_deposit',
				),
				array(
					'id'     => 'ppay_b',
					'status' => 'paid',
				),
			),
			array( 'ppay_a' => new Api_Error( 0, 'network_error' ) )
		);
		$settled = Switch_Guard::find_settled( 42, $client );
		$this->assertSame( 'ppay_b', $settled['id'] );
	}

	public function test_nothing_settled_when_every_open_attempt_expires() {
		$client = $this->client(
			array(
				array(
					'id'     => 'ppay_a',
					'status' => 'awaiting_payment',
				),
				array(
					'id'     => 'ppay_old',
					'status' => 'superseded',
				),
			),
			array( 'ppay_a' => self::ok( 'ppay_a', 'expired' ) )
		);
		$this->assertNull( Switch_Guard::find_settled( 42, $client ) );
	}

	public function test_needs_review_also_stops_the_other_checkout() {
		$client  = $this->client(
			array(
				array(
					'id'     => 'ppay_r',
					'status' => 'needs_review',
				),
			),
			array()
		);
		$settled = Switch_Guard::find_settled( 42, $client );
		$this->assertSame( 'needs_review', $settled['status'] );
		Functions\stubTranslationFunctions();
		$this->assertStringContainsString( 'reviewed', Switch_Guard::message( $settled ) );
		$this->assertStringContainsString( 'already paid', Switch_Guard::message( array( 'status' => 'paid' ) ) );
	}

	public function test_switch_checks_use_the_short_timeout() {
		$client = $this->client( array(), array() )->with_timeout( Api_Client::SHORT_TIMEOUT );
		Switch_Guard::find_settled( 42, $client );
		$this->assertSame( 5, $this->requests[0][2] );
	}

	public function test_switched_away_reads_the_gateway_about_to_be_used() {
		$order = new \WC_Order( 9 );
		$order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_a' );
		$this->assertFalse( Switch_Guard::switched_away( $order ), 'still on our gateway' );
		$this->assertTrue( Switch_Guard::switched_away( $order, 'bacs' ), 'the pay page posted bacs' );
		$this->assertFalse( Switch_Guard::switched_away( $order, 'outlinepay_kakaopay' ) );
	}
}
