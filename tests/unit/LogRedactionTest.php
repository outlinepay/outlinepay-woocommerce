<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Logger;

/**
 * Buyer fields, tokens and secrets must never reach a log line.
 */
class LogRedactionTest extends TestCase {

	const TOKEN  = 'olp_it_test_TOKENVALUE0123456789abcd';
	const SECRET = 'olp_isec_SECRETVALUE0123456789abcd';

	private function buyer() {
		return array(
			'name'    => '홍길동 Hong Gildong',
			'email'   => 'buyer-private@example.com',
			'phone'   => '010-1234-5678',
			'address' => '서울특별시 강남구 테헤란로 123',
		);
	}

	/**
	 * Strings that must not appear in any log line.
	 */
	private function forbidden() {
		return array_merge(
			array_values( $this->buyer() ),
			array( 'Hong', 'Gildong', '홍길동', 'buyer-private', '1234-5678', '테헤란로', self::TOKEN, self::SECRET, 'TOKENVALUE', 'SECRETVALUE' )
		);
	}

	private function assert_logs_clean() {
		$this->assertNotEmpty( $this->log_lines, 'the scenario should have logged something' );
		foreach ( $this->log_lines as $line ) {
			foreach ( $this->forbidden() as $needle ) {
				$this->assertStringNotContainsString( $needle, $line );
			}
		}
	}

	public function test_context_outside_the_allowlist_is_dropped() {
		Logger::warning(
			'Payment refused',
			array_merge(
				$this->buyer(),
				array(
					'buyer'          => $this->buyer(),
					'token'          => self::TOKEN,
					'signing_secret' => self::SECRET,
					'order_id'       => 42,
				)
			)
		);
		$this->assertCount( 1, $this->log_lines );
		$this->assertStringContainsString( 'order_id=42', $this->log_lines[0] );
		$this->assert_logs_clean();
	}

	public function test_credentials_under_an_allowed_key_are_redacted() {
		Logger::error(
			'Odd',
			array(
				'code'       => self::TOKEN,
				'payment_id' => self::SECRET,
				'status'     => 'olp_cc_test_CONNECTCODE',
			)
		);
		$this->assertStringContainsString( 'code=[redacted]', $this->log_lines[0] );
		$this->assertStringNotContainsString( 'CONNECTCODE', $this->log_lines[0] );
		$this->assert_logs_clean();
	}

	public function test_allowed_values_cannot_smuggle_text() {
		Logger::info( 'Payment applied', array( 'status' => 'paid buyer-private@example.com' ) );
		$this->assertStringNotContainsString( '@', $this->log_lines[0] );
		$this->assertStringNotContainsString( ' buyer', $this->log_lines[0] );
	}

	public function test_a_refused_payment_with_a_buyer_logs_ids_and_codes_only() {
		$echoing_server = static function ( $method, $url, $headers, $body ) {
			// A server that quotes the request back in its error, the worst case.
			return array(
				'status' => 400,
				'body'   => json_encode(
					array(
						'error' => array(
							'code'    => 'invalid_request',
							'message' => 'bad request: ' . $body . ' ' . $headers['Authorization'],
							'param'   => 'buyer.email',
						),
					)
				),
			);
		};
		$client         = new Api_Client(
			static function () {
				return LogRedactionTest::TOKEN;
			},
			$echoing_server,
			'https://api.example.test/plugin/v1'
		);

		try {
			$client->create_payment(
				array(
					'order_id'       => 42,
					'amount'         => 25000,
					'currency'       => 'KRW',
					'payment_method' => 'card',
					'buyer'          => $this->buyer(),
				)
			);
			$this->fail( 'expected Api_Error' );
		} catch ( Api_Error $error ) {
			$this->assertSame( 'invalid_request', $error->get_error_code() );
		}

		$this->assert_logs_clean();
		$this->assertStringContainsString( 'code=invalid_request', implode( "\n", $this->log_lines ) );
	}

	public function test_a_network_failure_logs_no_credentials() {
		$client = new Api_Client(
			static function () {
				return LogRedactionTest::TOKEN;
			},
			static function () {
				throw new Api_Error( 0, 'network_error', 'could not reach ' . LogRedactionTest::TOKEN );
			},
			'https://api.example.test/plugin/v1'
		);
		try {
			$client->get_payment( 'ppay_abc' );
		} catch ( Api_Error $error ) {
			$this->assertTrue( $error->is_transient() );
		}
		$this->assert_logs_clean();
	}
}
