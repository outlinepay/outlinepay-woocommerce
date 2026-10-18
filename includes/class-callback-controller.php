<?php
/**
 * POST /wp-json/outlinepay/v1/callback: OutlinePay says a payment changed.
 *
 * The signature is the authentication. A valid callback is only a reason to
 * look: the payment is read back with GET /payments/:id and that answer is
 * what gets applied. The callback body itself is never applied.
 *
 * A refund delivery is the payment with a refunds list. Each refund in it
 * that this order has not handled yet is read back with GET /refunds/:id,
 * and those answers are applied, never the list itself.
 *
 * The same address answers OutlinePay's self-check ping, a signed body
 * {"type":"self_check","installation":"inst_...","sent_at":"..."}: it only
 * proves the address is reachable and touches no order and no option.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The REST callback.
 */
final class Callback_Controller {

	/**
	 * Returns the signing secret or null.
	 *
	 * @var callable
	 */
	private $secret_provider;

	/**
	 * Reads a payment: receives a payment id, returns the payment array or throws Api_Error.
	 *
	 * @var callable
	 */
	private $payment_reader;

	/**
	 * Reads a refund: receives a refund id, returns the refund array or throws Api_Error.
	 *
	 * @var callable
	 */
	private $refund_reader;

	/**
	 * Finds an order: receives an order id, returns WC_Order or null.
	 *
	 * @var callable
	 */
	private $order_finder;

	/**
	 * Applies a payment: receives ( order, payment, refunds ), returns a decision.
	 *
	 * @var callable
	 */
	private $applier;

	/**
	 * True when this site is a copy of the store (see Site_Identity).
	 *
	 * @var callable
	 */
	private $copy_check;

	/**
	 * Returns the saved installation id (inst_...) or ''.
	 *
	 * @var callable
	 */
	private $installation_id;

	/**
	 * Constructor. All arguments are test seams with production defaults.
	 *
	 * @param callable|null $secret_provider Secret provider.
	 * @param callable|null $payment_reader  Payment reader.
	 * @param callable|null $order_finder    Order finder.
	 * @param callable|null $applier         Applier.
	 * @param callable|null $copy_check      Copy check.
	 * @param callable|null $installation_id Saved installation id.
	 * @param callable|null $refund_reader   Refund reader.
	 */
	public function __construct( $secret_provider = null, $payment_reader = null, $order_finder = null, $applier = null, $copy_check = null, $installation_id = null, $refund_reader = null ) {
		$this->refund_reader   = $refund_reader ? $refund_reader : static function ( $refund_id ) {
			return ( new Api_Client() )->get_refund( $refund_id );
		};
		$this->copy_check      = $copy_check ? $copy_check : array( Site_Identity::class, 'is_copy' );
		$this->installation_id = $installation_id ? $installation_id : static function () {
			$installation = Settings::installation();
			return isset( $installation['id'] ) && is_string( $installation['id'] ) ? $installation['id'] : '';
		};
		$this->secret_provider = $secret_provider ? $secret_provider : array( Credentials::class, 'signing_secret' );
		$this->payment_reader  = $payment_reader ? $payment_reader : static function ( $payment_id ) {
			return ( new Api_Client() )->get_payment( $payment_id );
		};
		$this->order_finder    = $order_finder ? $order_finder : static function ( $order_id ) {
			$order = wc_get_order( $order_id );
			return Order_Sync::is_ours( $order ) ? $order : null;
		};
		$this->applier         = $applier ? $applier : array( Order_Sync::class, 'apply' );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'outlinepay/v1',
					'/callback',
					array(
						'methods'             => 'POST',
						'callback'            => array( new self(), 'rest' ),
						// The OutlinePay-Signature header is the authentication, checked in the handler.
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * REST handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest( $request ) {
		$result = $this->process( (string) $request->get_body(), (string) $request->get_header( 'outlinepay_signature' ), time() );
		return new \WP_REST_Response( $result['body'], $result['status'] );
	}

	/**
	 * Verifies, reads back and applies. Returns array( 'status' => int, 'body' => array ).
	 *
	 * @param string $body   Raw body.
	 * @param string $header OutlinePay-Signature header.
	 * @param int    $now    Unix time.
	 * @return array
	 */
	public function process( $body, $header, $now ) {
		$secret = call_user_func( $this->secret_provider );
		if ( ! is_string( $secret ) || '' === $secret || ! Signature::verify( $header, $secret, $body, $now ) ) {
			Logger::warning( 'Callback rejected: bad or missing signature' );
			return self::answer( 401, 'invalid_signature' );
		}

		if ( call_user_func( $this->copy_check ) ) {
			// A copy of the store holds the live store's secret but must not touch orders.
			Logger::warning( 'Callback received on a copy of the store, not applied' );
			return self::answer( 200, 'ignored_copy' );
		}

		$payload = json_decode( $body, true );
		if ( is_array( $payload ) && isset( $payload['type'] ) && 'self_check' === $payload['type'] ) {
			return $this->self_check( $payload );
		}

		$payment_id = is_array( $payload ) && isset( $payload['id'] ) ? $payload['id'] : null;
		$order_id   = is_array( $payload ) && isset( $payload['order_id'] ) && is_scalar( $payload['order_id'] ) ? (string) $payload['order_id'] : '';
		if ( ! Api_Client::valid_payment_id( $payment_id ) || ! preg_match( '/^\d{1,20}$/', $order_id ) ) {
			Logger::warning( 'Callback rejected: unreadable body' );
			return self::answer( 400, 'invalid_body' );
		}

		$order = call_user_func( $this->order_finder, (int) $order_id );
		if ( ! $order ) {
			Logger::info(
				'Callback for an order this store does not have',
				array(
					'order_id'   => $order_id,
					'payment_id' => $payment_id,
				)
			);
			return self::answer( 200, 'ignored' );
		}

		try {
			$payment = call_user_func( $this->payment_reader, $payment_id );
		} catch ( Api_Error $error ) {
			if ( $error->is_transient() ) {
				return self::answer( 503, 'retry_later' );
			}
			return self::answer( 200, 'ignored' );
		}

		$refunds = array();
		foreach ( Refunds::ids_to_read( $order, $payload ) as $refund_id ) {
			try {
				$refunds[] = call_user_func( $this->refund_reader, $refund_id );
			} catch ( Api_Error $error ) {
				if ( $error->is_transient() ) {
					return self::answer( 503, 'retry_later' );
				}
				// Not this store's refund: skip it.
				Logger::warning(
					'Callback refund could not be read',
					array(
						'order_id'  => $order_id,
						'refund_id' => $refund_id,
						'code'      => $error->get_error_code(),
					)
				);
			}
		}

		$decision = call_user_func( $this->applier, $order, $payment, $refunds );
		if ( Order_Sync::BUSY === $decision ) {
			return self::answer( 503, 'retry_later' );
		}
		return self::answer( 200, 'received' );
	}

	/**
	 * Answers a signed self-check ping: 200 {"ok":true} when it names this
	 * store's installation. Nothing else happens.
	 *
	 * @param array $payload Decoded body.
	 * @return array
	 */
	private function self_check( array $payload ) {
		$saved = (string) call_user_func( $this->installation_id );
		$named = isset( $payload['installation'] ) && is_string( $payload['installation'] ) ? $payload['installation'] : '';
		if ( '' === $saved || ! hash_equals( $saved, $named ) ) {
			Logger::warning( 'Self-check ping for another installation' );
			return self::answer( 400, 'wrong_installation' );
		}
		return array(
			'status' => 200,
			'body'   => array( 'ok' => true ),
		);
	}

	/**
	 * Response shape.
	 *
	 * @param int    $status HTTP status.
	 * @param string $result Short result word.
	 * @return array
	 */
	private static function answer( $status, $result ) {
		return array(
			'status' => $status,
			'body'   => array( 'result' => $result ),
		);
	}
}
