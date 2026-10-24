<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Launch_Page;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Return_Handler;

/**
 * The pay click: a form window goes through the store's launch page, a
 * redirect window goes straight to the provider.
 */
class GatewayLaunchTest extends RefundTestCase {

	/** @var Gateway */
	private $gateway;

	/** @var string[] Notices shown to the shopper. */
	private $notices = array();

	protected function setUp(): void {
		parent::setUp();
		$this->order->status    = 'pending';
		$this->order->date_paid = null;
		$this->order->meta      = array();

		$this->gateway                    = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$this->gateway->outlinepay_method = 'card';

		$requests = &$this->requests;
		$answers  = &$this->answers;
		Gateway::set_client_factory(
			static function () use ( &$requests, &$answers ) {
				return new Api_Client(
					static function () {
						return 'olp_it_test_token';
					},
					static function ( $method, $url, $headers, $body ) use ( &$requests, &$answers ) {
						$requests[] = array(
							'method' => $method,
							'url'    => $url,
							'body'   => null === $body ? null : json_decode( $body, true ),
						);
						return array_shift( $answers );
					},
					'https://api.example.test/plugin/v1'
				);
			}
		);

		$notices = &$this->notices;
		Functions\when( 'wc_add_notice' )->alias(
			static function ( $message ) use ( &$notices ) {
				$notices[] = $message;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '쿠키 &amp; 상점' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'WC' )->justReturn(
			new class() {
				public function api_request_url( $endpoint ) {
					return 'https://shop.example/?wc-api=' . $endpoint;
				}
			}
		);
	}

	protected function tearDown(): void {
		Gateway::set_client_factory( null );
		parent::tearDown();
	}

	private static function form_window( array $overrides = array() ) {
		return array_merge(
			array(
				'type'           => 'form',
				'action'         => 'https://pay.example.test/pay/start',
				'method'         => 'post',
				'accept_charset' => 'euc-kr',
				'width'          => 548,
				'height'         => 550,
				'fields'         => array( array( 'ORDERNO', '42' ), array( 'AMOUNT', '25000' ) ),
				'expires_at'     => gmdate( 'Y-m-d\TH:i:s\Z', time() + 1800 ),
			),
			$overrides
		);
	}

	private static function opened( array $extra ) {
		return self::json(
			201,
			array_merge(
				self::payment(
					array(
						'id'     => 'ppay_new',
						'status' => 'awaiting_payment',
					)
				),
				$extra
			)
		);
	}

	public function test_a_form_window_goes_through_the_launch_page() {
		$this->answers[] = self::opened( array( 'window' => self::form_window() ) );

		$result = $this->gateway->process_payment( 42 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://shop.example/?wc-api=outlinepay_launch&key=wc_order_abc123&payment_id=ppay_new', $result['redirect'] );
		$launch = $this->order->meta[ Order_Sync::META_LAUNCH ];
		$this->assertSame( 'ppay_new', $launch['payment_id'] );
		$this->assertSame( 'https://pay.example.test/pay/start', $launch['action'] );
		$this->assertSame( array( array( 'ORDERNO', '42' ), array( 'AMOUNT', '25000' ) ), $launch['fields'] );
		$this->assertSame( '쿠키 & 상점 #42', $launch['product_name'] );
		$this->assertSame( 'ppay_new', $this->order->meta[ Order_Sync::META_PAYMENT_ID ] );
		$this->assertSame( array( 'ppay_new' ), Order_Sync::attempts( $this->order ) );
		$this->assertSame( '쿠키 & 상점 #42', $this->requests[0]['body']['product_name'] );
		$this->assertStringNotContainsString( 'ORDERNO', implode( "\n", $this->log_lines ), 'form fields are never logged' );
	}

	public function test_a_redirect_window_goes_straight_to_the_provider_and_drops_an_old_form() {
		$this->order->meta[ Order_Sync::META_LAUNCH ] = array( 'payment_id' => 'ppay_old' );
		$this->answers[]                               = self::opened(
			array(
				'redirect_url' => 'https://pay.example.test/window/abc',
				'window'       => array(
					'type' => 'redirect',
					'url'  => 'https://pay.example.test/window/abc',
				),
			)
		);

		$result = $this->gateway->process_payment( 42 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://pay.example.test/window/abc', $result['redirect'] );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
	}

	public function test_an_answer_with_only_redirect_url_still_works() {
		$this->answers[] = self::opened( array( 'redirect_url' => 'https://pay.example.test/window/abc' ) );

		$result = $this->gateway->process_payment( 42 );

		$this->assertSame( 'https://pay.example.test/window/abc', $result['redirect'] );
	}

	public function test_a_form_this_store_cannot_post_is_refused() {
		$this->answers[] = self::opened( array( 'window' => self::form_window( array( 'action' => 'javascript:alert(1)' ) ) ) );

		$result = $this->gateway->process_payment( 42 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array( 'The payment could not be started. Try again in a moment.' ), $this->notices );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
		$this->assertArrayNotHasKey( Order_Sync::META_PAYMENT_ID, $this->order->meta );
		$this->assertStringContainsString( 'payment form this store cannot post', implode( "\n", $this->log_lines ) );
	}

	public function test_an_answer_with_neither_form_nor_redirect_is_refused() {
		$this->answers[] = self::opened( array() );

		$result = $this->gateway->process_payment( 42 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertStringContainsString( 'missing id or redirect', implode( "\n", $this->log_lines ) );
	}

	public function test_the_product_name_is_cut_to_forty_characters_not_bytes() {
		$store = str_repeat( '가나다라마바사아자차', 5 );
		$name  = Gateway::product_name( $store, '1234' );

		$this->assertSame( 40, mb_strlen( $name, 'UTF-8' ) );
		$this->assertSame( mb_substr( $store, 0, 40, 'UTF-8' ), $name );
		$this->assertTrue( mb_check_encoding( $name, 'UTF-8' ) );
	}

	public function test_a_short_product_name_keeps_the_order_number() {
		$this->assertSame( '쿠키 상점 #1234', Gateway::product_name( '쿠키 상점', '1234' ) );
		$this->assertSame( '#1234', Gateway::product_name( '', '1234' ) );
		$this->assertSame( 'Shop Two #7', Gateway::product_name( "Shop\t\x01Two", '7' ), 'control characters become one space' );
		$this->assertSame( 'A B #7', Gateway::product_name( "A\xE2\x80\x8DB", '7' ), 'a zero-width joiner (a format character) becomes a space' );
		$this->assertSame( str_repeat( 'a', 39 ), Gateway::product_name( str_repeat( 'a', 39 ) . ' b', '7' ), 'no trailing space after the cut' );
		$this->assertSame( '', Gateway::product_name( "\xC3\x28", '' ), 'invalid UTF-8 sends no name' );
	}

	public function test_a_paid_order_drops_its_form() {
		$this->order->meta[ Order_Sync::META_LAUNCH ]   = array( 'payment_id' => 'ppay_mine' );
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine' );

		Order_Sync::apply( $this->order, self::payment() );

		$this->assertTrue( $this->order->is_paid() );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
	}

	public function test_a_failed_attempt_keeps_the_form() {
		$this->order->meta[ Order_Sync::META_LAUNCH ]   = array( 'payment_id' => 'ppay_mine' );
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine' );

		Order_Sync::apply( $this->order, self::payment( array( 'status' => 'failed' ) ) );

		$this->assertArrayHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
	}

	public function test_the_return_page_hands_the_destination_to_the_store_tab() {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value, $flags = 0 ) {
				return json_encode( $value, $flags );
			}
		);
		Functions\when( 'esc_url' )->alias(
			static function ( $url ) {
				// Like WordPress: characters outside a URL are dropped, & and ' encoded.
				$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
				return str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url );
			}
		);
		$html = Return_Handler::finish_page( 'https://shop.example/checkout/order-received/42/?key=wc_order_abc123&x="</script>' );

		$bs = chr( 92 ); // A backslash: the JSON escapes <, >, & and " as unicode escapes.
		$this->assertStringContainsString( 'var destination = "https://shop.example/checkout/order-received/42/?key=wc_order_abc123' . $bs . 'u0026x=' . $bs . 'u0022' . $bs . 'u003C/script' . $bs . 'u003E";', $html );
		$this->assertStringContainsString( 'opener.location.host === window.location.host', $html );
		$this->assertStringContainsString( 'window.close();', $html );
		$this->assertStringContainsString( 'window.location.replace(destination);', $html );
		$this->assertStringContainsString( '<meta http-equiv="refresh" content="2;url=https://shop.example/checkout/order-received/42/?key=wc_order_abc123&#038;x=/script">', $html );
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
	}

	public function test_the_launch_url_carries_the_order_key_and_payment() {
		$this->assertSame(
			'https://shop.example/?wc-api=outlinepay_launch&key=wc_order_abc123&payment_id=ppay_new',
			Launch_Page::url( $this->order, 'ppay_new' )
		);
	}

	public function test_a_started_attempt_gets_one_private_note() {
		$this->answers[] = self::opened( array( 'window' => self::form_window() ) );
		$this->gateway->process_payment( 42 );
		$this->assertSame( array( 'OutlinePay payment ppay_new started. Waiting for the shopper.' ), $this->order->notes );

		// The server answers the same attempt again: no second note.
		$this->answers[] = self::opened( array( 'window' => self::form_window() ) );
		$this->gateway->process_payment( 42 );
		$this->assertCount( 1, $this->order->notes );
	}

	public function test_a_provider_error_gets_one_note_however_often_the_shopper_presses() {
		$error = self::json( 502, array( 'error' => array( 'code' => 'processor_error', 'message' => 'Upstream failed.' ) ) );
		$this->answers[] = $error;
		$this->answers[] = $error;
		$this->answers[] = $error;

		$this->gateway->process_payment( 42 );
		$this->gateway->process_payment( 42 );
		$result = $this->gateway->process_payment( 42 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 1, $this->order->notes, 'one note for three presses' );
		$this->assertStringContainsString( 'An OutlinePay payment by Credit or debit card could not start:', $this->order->notes[0] );
		$this->assertStringContainsString( 'returned an error. The shopper saw an error and can try again.', $this->order->notes[0] );
	}

	public function test_a_method_the_provider_has_not_enabled_gets_its_own_note() {
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		$this->answers[] = self::json( 400, array( 'error' => array( 'code' => 'payment_method_not_enabled', 'message' => 'No.' ) ) );

		$this->gateway->process_payment( 42 );

		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'has not enabled this method for the store. The shopper was asked to choose another method.', $this->order->notes[0] );
	}

	public function test_another_outlinepay_error_names_its_code() {
		Functions\when( 'sanitize_key' )->returnArg();
		$this->answers[] = self::json( 400, array( 'error' => array( 'code' => 'invalid_request', 'message' => 'Bad.' ) ) );

		$this->gateway->process_payment( 42 );

		$this->assertSame( array( 'An OutlinePay payment by Credit or debit card could not start (invalid_request). The shopper saw an error and can try again.' ), $this->order->notes );
	}

	public function test_an_answer_the_store_cannot_use_gets_a_note() {
		$this->answers[] = self::opened( array( 'window' => self::form_window( array( 'action' => 'javascript:alert(1)' ) ) ) );

		$this->gateway->process_payment( 42 );

		$this->assertSame( array( "An OutlinePay payment by Credit or debit card could not start: OutlinePay's answer could not be used. The shopper saw an error and can try again." ), $this->order->notes );
	}

	public function test_a_site_that_cannot_reach_outlinepay_gets_its_own_note() {
		$this->assertTrue( Order_Sync::note_refused( $this->order, 'unreachable', 'card' ) );
		$this->assertFalse( Order_Sync::note_refused( $this->order, 'unreachable', 'card' ), 'once per order and reason' );
		$this->assertSame( array( 'An OutlinePay payment by Credit or debit card could not start: this site could not reach OutlinePay. The shopper saw an error and can try again.' ), $this->order->notes );
	}

	public function test_a_started_attempt_after_a_refusal_keeps_both_notes() {
		$this->answers[] = self::json( 502, array( 'error' => array( 'code' => 'processor_error', 'message' => 'Upstream failed.' ) ) );
		$this->answers[] = self::opened( array( 'window' => self::form_window() ) );

		$this->gateway->process_payment( 42 );
		$this->gateway->process_payment( 42 );

		$this->assertCount( 2, $this->order->notes );
		$this->assertSame( 'OutlinePay payment ppay_new started. Waiting for the shopper.', $this->order->notes[1] );
	}
}
