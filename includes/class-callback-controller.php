<?php
/**
 * POST /wp-json/outlinepay/v1/callback: OutlinePay says a payment changed.
 *
 * The signature is the authentication. A valid callback is only a reason to
 * look: the payment is read back with GET /payments/:id and that answer is
 * what gets applied. The callback body itself is never applied.
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
	 * Finds an order: receives an order id, returns WC_Order or null.
	 *
	 * @var callable
	 */
	private $order_finder;

	/**
	 * Applies a payment: receives ( order, payment ), returns a decision.
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
	 * Constructor. All arguments are test seams with production defaults.
	 *
	 * @param callable|null $secret_provider Secret provider.
	 * @param callable|null $payment_reader  Payment reader.
	 * @param callable|null $order_finder    Order finder.
	 * @param callable|null $applier         Applier.
	 * @param callable|null $copy_check      Copy check.
	 */
	public function __construct( $secret_provider = null, $payment_reader = null, $order_finder = null, $applier = null, $copy_check = null ) {
		$this->copy_check      = $copy_check ? $copy_check : array( Site_Identity::class, 'is_copy' );
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

		$payload    = json_decode( $body, true );
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

		$decision = call_user_func( $this->applier, $order, $payment );
		if ( Order_Sync::BUSY === $decision ) {
			return self::answer( 503, 'retry_later' );
		}
		return self::answer( 200, 'received' );
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
