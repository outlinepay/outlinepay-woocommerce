<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Actions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Api_Error;

class ApiClientTest extends TestCase {

	const TOKEN = 'olp_it_test_SECRETTOKEN1234567890';
	const BASE  = 'https://api.example.test/plugin/v1';

	/** @var array Requests the fake transport received. */
	private $requests = array();

	/**
	 * A client whose transport answers with the given responses in turn.
	 */
	private function client( array $responses, $token = self::TOKEN ) {
		$requests = &$this->requests;
		$transport = static function ( $method, $url, $headers, $body, $timeout ) use ( &$requests, &$responses ) {
			$requests[] = compact( 'method', 'url', 'headers', 'body', 'timeout' );
			$next       = array_shift( $responses );
			if ( $next instanceof Api_Error ) {
				throw $next;
			}
			return $next;
		};
		return new Api_Client(
			static function () use ( $token ) {
				return $token;
			},
			$transport,
			self::BASE
		);
	}

	private static function json( $status, $data ) {
		return array(
			'status' => $status,
			'body'   => json_encode( $data ),
		);
	}

	public function test_a_success_returns_the_decoded_body_and_sends_the_right_headers() {
		$client  = $this->client( array( self::json( 200, array( 'id' => 'ppay_abc', 'status' => 'paid' ) ) ) );
		$payment = $client->get_payment( 'ppay_abc' );

		$this->assertSame( 'paid', $payment['status'] );
		$request = $this->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( self::BASE . '/payments/ppay_abc', $request['url'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $request['headers']['Authorization'] );
		$this->assertSame( 'OutlinePay-WooCommerce/0.1.0', $request['headers']['User-Agent'] );
		$this->assertSame( 20, $request['timeout'] );
		$this->assertNull( $request['body'] );
	}

	public function test_create_sends_json_with_an_integer_amount() {
		$client = $this->client( array( self::json( 200, array( 'id' => 'ppay_abc', 'redirect_url' => 'https://pay.example/w' ) ) ) );
		$client->create_payment(
			array(
				'order_id' => 42,
				'amount'   => 25000,
				'currency' => 'KRW',
			)
		);
		$request = $this->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertStringContainsString( '"amount":25000', $request['body'] );
	}

	public function test_a_409_refusal_carries_its_code_and_payment() {
		$client = $this->client(
			array(
				self::json(
					409,
					array(
						'error'   => array(
							'code'    => 'order_already_paid',
							'details' => array(),
						),
						'payment' => array(
							'id'     => 'ppay_paid',
							'status' => 'paid',
						),
					)
				),
			)
		);
		try {
			$client->create_payment( array( 'order_id' => 42 ) );
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( 409, $error->get_http_status() );
			$this->assertSame( 'order_already_paid', $error->get_error_code() );
			$this->assertSame( 'ppay_paid', $error->get_payment()['id'] );
			$this->assertFalse( $error->is_transient() );
		}
	}

	public function test_an_error_envelope_maps_code_message_and_param() {
		$client = $this->client(
			array(
				self::json(
					400,
					array(
						'error' => array(
							'code'    => 'payment_method_not_enabled',
							'message' => 'not enabled',
							'param'   => 'payment_method',
						),
					)
				),
			)
		);
		try {
			$client->create_payment( array() );
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( 'payment_method_not_enabled', $error->get_error_code() );
			$this->assertSame( 'payment_method', $error->get_param() );
			$this->assertSame( 'not enabled', $error->getMessage() );
			$this->assertNull( $error->get_payment() );
		}
	}

	public function test_a_401_announces_the_rejected_token() {
		Actions\expectDone( 'outlinepay_token_rejected' )->once();
		$client = $this->client( array( self::json( 401, array( 'error' => array( 'code' => 'authentication_error' ) ) ) ) );
		try {
			$client->get_installation();
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( 'authentication_error', $error->get_error_code() );
		}
	}

	public function test_a_refused_connect_does_not_announce_a_rejected_token() {
		Actions\expectDone( 'outlinepay_token_rejected' )->never();
		$client = $this->client( array( self::json( 401, array( 'error' => array( 'code' => 'authentication_error' ) ) ) ) );
		try {
			$client->connect( 'olp_cc_test_x', 'https://shop.example/', 'https://shop.example/wp-json/outlinepay/v1/callback', array() );
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( 'authentication_error', $error->get_error_code() );
			$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['headers'] );
		}
	}

	/**
	 * @dataProvider transient_answers
	 */
	public function test_server_trouble_is_transient( $response, $code ) {
		$client = $this->client( array( $response ) );
		try {
			$client->get_methods();
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( $code, $error->get_error_code() );
			$this->assertTrue( $error->is_transient() );
		}
	}

	public function transient_answers() {
		return array(
			'html 500'       => array(
				array(
					'status' => 500,
					'body'   => '<html>oops</html>',
				),
				'http_500',
			),
			'503 processor'  => array( self::json( 503, array( 'error' => array( 'code' => 'processor_error' ) ) ), 'processor_error' ),
			'502 processor'  => array( self::json( 502, array( 'error' => array( 'code' => 'processor_error' ) ) ), 'processor_error' ),
			'429'            => array( self::json( 429, array( 'error' => array( 'code' => 'rate_limited' ) ) ), 'rate_limited' ),
			'network'        => array( new Api_Error( 0, 'network_error' ), 'network_error' ),
		);
	}

	public function test_an_unreadable_success_is_an_error() {
		$client = $this->client(
			array(
				array(
					'status' => 200,
					'body'   => 'not json',
				),
			)
		);
		$this->expectException( Api_Error::class );
		$client->get_installation();
	}

	public function test_without_a_token_nothing_is_sent() {
		$client = $this->client( array(), null );
		try {
			$client->get_methods();
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( 'not_connected', $error->get_error_code() );
			$this->assertCount( 0, $this->requests );
		}
	}

	public function test_a_malformed_payment_id_never_reaches_the_server() {
		$client = $this->client( array() );
		foreach ( array( '../installation', 'ppay_a/b', '', 'ppay_a?b' ) as $id ) {
			try {
				$client->get_payment( $id );
				$this->fail( 'expected Api_Error' );
			} catch ( Api_Error $error ) {
				$this->assertSame( 'invalid_payment_id', $error->get_error_code() );
			}
		}
		$this->assertCount( 0, $this->requests );
	}

	public function test_methods_keep_only_well_formed_rows() {
		$client  = $this->client(
			array(
				self::json(
					200,
					array(
						'object' => 'list',
						'data'   => array(
							array(
								'method'   => 'card',
								'label_ko' => '신용카드',
							),
							array( 'label_ko' => 'no method' ),
							'junk',
							array( 'method' => 'kakaopay' ),
						),
					)
				),
			)
		);
		$methods = $client->get_methods();
		$this->assertSame(
			array(
				array(
					'method'   => 'card',
					'label_ko' => '신용카드',
				),
				array(
					'method'   => 'kakaopay',
					'label_ko' => '',
				),
			),
			$methods
		);
	}

	public function test_list_and_expire_use_the_documented_paths() {
		$client = $this->client(
			array(
				self::json( 200, array( 'data' => array( array( 'id' => 'ppay_1' ) ) ) ),
				self::json( 200, array( 'id' => 'ppay_1', 'status' => 'expired' ) ),
			)
		);
		$this->assertCount( 1, $client->list_payments( 42 ) );
		$client->expire_payment( 'ppay_1' );
		$this->assertSame( self::BASE . '/payments?order_id=42', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[1]['method'] );
		$this->assertSame( self::BASE . '/payments/ppay_1/expire', $this->requests[1]['url'] );
	}

	public function test_the_base_defaults_to_production() {
		$this->assertSame( 'https://api.outlinepay.com/plugin/v1', Api_Client::configured_base() );
	}
}
