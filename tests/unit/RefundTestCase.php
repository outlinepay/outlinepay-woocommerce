<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Api_Error;
use OutlinePay\WooCommerce\Order_Lock;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Refunds;

/**
 * Shared setup for refund tests: a paid card order, a connected store with
 * refunds on, a fake API and a wc_create_refund that behaves like WooCommerce's.
 */
abstract class RefundTestCase extends TestCase {

	/** @var \WC_Order */
	protected $order;

	/** @var ArrayLockStore */
	protected $locks;

	/** @var array Requests the fake API received. */
	protected $requests = array();

	/** @var array Answers the fake API gives, in turn. */
	protected $answers = array();

	/** @var array Options by name. */
	protected $options = array();

	/** @var int Next WooCommerce refund id. */
	protected $next_refund_id = 900;

	protected function setUp(): void {
		parent::setUp();
		$this->order                                      = new \WC_Order( 42 );
		$this->order->status                              = 'processing';
		$this->order->customer_id                         = 7;
		$this->order->date_paid                           = '2026-10-17T10:00:00+00:00';
		$this->order->meta[ Order_Sync::META_PAYMENT_ID ] = 'ppay_mine';
		$this->order->meta[ Order_Sync::META_ATTEMPTS ]   = array( 'ppay_mine' );
		$this->order->meta[ Order_Sync::META_APPLIED ]    = array( 'ppay_mine:paid' );

		$this->locks = new ArrayLockStore();
		Order_Lock::set_store( $this->locks );

		$this->options = array(
			'outlinepay_installation'  => array(
				'id'          => 'inst_1',
				'status'      => 'connected',
				'permissions' => array( 'payments.create', 'payments.read', 'refunds.create' ),
			),
			'outlinepay_methods'       => array(
				'methods' => array(
					array(
						'method'   => 'card',
						'label_ko' => '신용카드',
					),
				),
			),
			'outlinepay_shopper_cancel' => array(
				'enabled'  => 'yes',
				'statuses' => array( 'processing' ),
			),
			'outlinepay_setup_done'     => 1790000000,
		);

		$order   = $this->order;
		$options = &$this->options;
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $order ) {
				return (int) $id === $order->get_id() ? $order : false;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wc_create_refund' )->alias( array( $this, 'fake_wc_create_refund' ) );

		// Connected: sealed credentials that open, and no saved address, so not a copy.
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		\OutlinePay\WooCommerce\Credentials::reset_cache();
		$this->options['outlinepay_credentials'] = \OutlinePay\WooCommerce\Credentials::seal(
			json_encode(
				array(
					'token'          => 'olp_it_test_token',
					'signing_secret' => 'olp_isec_test',
				)
			),
			\OutlinePay\WooCommerce\Credentials::key()
		);

		$requests = &$this->requests;
		$answers  = &$this->answers;
		Refunds::set_client_factory(
			static function () use ( &$requests, &$answers ) {
				return new Api_Client(
					static function () {
						return 'olp_it_test_token';
					},
					static function ( $method, $url, $headers, $body, $timeout ) use ( &$requests, &$answers ) {
						$requests[] = array(
							'method' => $method,
							'url'    => $url,
							'body'   => null === $body ? null : json_decode( $body, true ),
						);
						$next = array_shift( $answers );
						if ( $next instanceof Api_Error ) {
							throw $next;
						}
						return $next;
					},
					'https://api.example.test/plugin/v1'
				);
			}
		);
	}

	protected function tearDown(): void {
		Refunds::set_client_factory( null );
		Order_Lock::set_store( null );
		\OutlinePay\WooCommerce\Credentials::reset_cache();
		parent::tearDown();
	}

	protected function disconnect() {
		$this->options['outlinepay_installation']['status'] = 'revoked';
	}

	protected function refunds_off() {
		$this->options['outlinepay_installation']['permissions'] = array( 'payments.create', 'payments.read' );
	}

	/** Behaves like WooCommerce's wc_create_refund for the fake order. */
	public function fake_wc_create_refund( $args ) {
		$order     = $this->order;
		$remaining = (float) $order->get_total() - $order->get_total_refunded();
		if ( $args['amount'] > $remaining ) {
			return new \WP_Error( 'error', 'Invalid refund amount.' );
		}
		$refund           = new \WC_Order_Refund( $this->next_refund_id++, (string) $args['amount'], (string) $args['reason'] );
		$order->refunds[] = $refund;
		if ( ! empty( $args['refund_payment'] ) ) {
			$this->fail( 'a mirror must never send money' );
		}
		if ( $remaining - $args['amount'] <= 0 ) {
			$status = Refunds::fully_refunded_status( 'refunded', $order->get_id() );
			if ( $status ) {
				$order->update_status( $status );
			}
		}
		return $refund;
	}

	/** The refund row WooCommerce saves before it calls process_refund. */
	protected function wc_refund_row( $amount, $id = null ) {
		$refund                  = new \WC_Order_Refund( null === $id ? $this->next_refund_id++ : $id, (string) $amount );
		$this->order->refunds[] = $refund;
		return $refund;
	}

	protected static function json( $status, $data ) {
		return array(
			'status' => $status,
			'body'   => json_encode( $data ),
		);
	}

	protected static function refusal( $status, $code, $param = null ) {
		return self::json(
			$status,
			array(
				'error' => array_filter(
					array(
						'code'    => $code,
						'message' => 'Refused.',
						'param'   => $param,
					)
				),
			)
		);
	}

	protected static function refund( array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => 'pref_one',
				'object'       => 'plugin_refund',
				'mode'         => 'live',
				'payment'      => 'ppay_mine',
				'order_id'     => '42',
				'amount'       => 25000,
				'currency'     => 'KRW',
				'source'       => 'store',
				'requested_by' => 'store',
				'refund_id'    => '900',
				'status'       => 'succeeded',
				'failure_code' => null,
			),
			$overrides
		);
	}

	protected static function payment( array $overrides = array() ) {
		return array_merge(
			array(
				'id'              => 'ppay_mine',
				'installation'    => 'inst_1',
				'mode'            => 'live',
				'order_id'        => '42',
				'amount'          => 25000,
				'currency'        => 'KRW',
				'payment_method'  => 'card',
				'status'          => 'paid',
				'refunded_amount' => 0,
				'refund_state'    => 'none',
				'transaction_id'  => 'CP123',
				'receipt_url'     => null,
			),
			$overrides
		);
	}

	protected function notes() {
		return implode( "\n", $this->order->notes );
	}
}
