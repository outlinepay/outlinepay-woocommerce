<?php
/**
 * Handles an order that moves from OutlinePay to another payment method.
 *
 * A shopper can open an OutlinePay window, come back, and finish the same
 * order with another gateway. Before the other gateway charges (classic
 * checkout, Checkout block, order-pay page), the order's OutlinePay attempts
 * are checked. Open ones are expired. If one is already paid or under review,
 * the order is settled from it right away and the other checkout is stopped,
 * so the shopper is not charged twice.
 *
 * When another gateway reports a payment by itself (payment_complete), the
 * same check runs in the background, after that gateway is done.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway switch handling.
 */
final class Switch_Guard {

	/**
	 * Hooks the checkout paths before payment, and payment completion by other gateways.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic_checkout' ), 5, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_block_checkout' ), 5, 1 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'on_pay_page' ), 5, 1 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_payment_complete' ), 20, 1 );
		add_action( Scheduler::HOOK_SWITCH_CHECK, array( self::class, 'run_background_check' ) );
	}

	/**
	 * True when the order uses another gateway but has OutlinePay attempts.
	 *
	 * @param mixed       $order      Order.
	 * @param string|null $gateway_id Gateway the shopper is about to use, when it is not on the order yet.
	 * @return bool
	 */
	public static function switched_away( $order, $gateway_id = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$gateway_id = null === $gateway_id ? $order->get_payment_method() : $gateway_id;
		return null === Settings::method_for_gateway( $gateway_id ) && array() !== Order_Sync::attempts( $order );
	}

	/**
	 * Expires the order's open attempts and returns one that is paid or under
	 * review, if any. A refusal on one attempt does not stop the others.
	 *
	 * @param int        $order_id Order id.
	 * @param Api_Client $client   Client.
	 * @return array|null The settled payment.
	 * @throws Api_Error When the attempts cannot be listed.
	 */
	public static function find_settled( $order_id, $client ) {
		$settled = array();
		foreach ( $client->list_payments( $order_id ) as $payment ) {
			$status = isset( $payment['status'] ) ? $payment['status'] : '';
			if ( isset( $payment['id'] ) && in_array( $status, Unpaid_Guard::WAITING, true ) ) {
				try {
					$payment = $client->expire_payment( $payment['id'] );
					$status  = isset( $payment['status'] ) ? $payment['status'] : '';
				} catch ( Api_Error $error ) {
					// One refusal (a 409, a timeout) must not stop the rest.
					Logger::warning(
						'Expiring an attempt after a gateway switch failed',
						array(
							'order_id'   => $order_id,
							'payment_id' => $payment['id'],
							'code'       => $error->get_error_code(),
						)
					);
					continue;
				}
				Logger::info(
					'Expired an open attempt after a gateway switch',
					array(
						'order_id'   => $order_id,
						'payment_id' => isset( $payment['id'] ) ? $payment['id'] : '',
						'status'     => $status,
					)
				);
			}
			if ( in_array( $status, array( 'paid', 'needs_review' ), true ) ) {
				$settled[] = $payment;
			}
		}
		return empty( $settled ) ? null : Order_Sync::pick( $settled );
	}

	/**
	 * The check for a checkout that is about to charge with another gateway.
	 * Returns the shopper message when that checkout must stop, null otherwise.
	 *
	 * @param \WC_Order $order Order.
	 * @return string|null
	 */
	public static function block_reason( $order ) {
		try {
			$settled = self::find_settled( $order->get_id(), ( new Api_Client() )->with_timeout( Api_Client::SHORT_TIMEOUT ) );
		} catch ( Api_Error $error ) {
			// OutlinePay did not answer in time. The checkout goes ahead. A late
			// OutlinePay payment then lands as a double-payment note for a refund.
			return null;
		}
		if ( null === $settled ) {
			return null;
		}
		Order_Sync::apply( $order, $settled );
		Logger::warning(
			'Checkout with another method stopped: OutlinePay already took the payment',
			array(
				'order_id'   => $order->get_id(),
				'payment_id' => $settled['id'],
				'status'     => $settled['status'],
			)
		);
		return self::message( $settled );
	}

	/**
	 * Shopper message for a stopped checkout.
	 *
	 * @param array $settled Settled payment.
	 * @return string
	 */
	public static function message( array $settled ) {
		if ( 'needs_review' === $settled['status'] ) {
			return __( 'A payment for this order is being reviewed. The store will contact you. Do not pay again.', 'outlinepay-for-woocommerce' );
		}
		return __( 'This order is already paid. You do not need to pay again.', 'outlinepay-for-woocommerce' );
	}

	/**
	 * Classic checkout: runs after the order is saved and before the gateway charges.
	 * WooCommerce shows a thrown exception as an error notice.
	 *
	 * @param int        $order_id Order id.
	 * @param array|null $posted   Posted data.
	 * @param mixed      $order    Order.
	 * @return void
	 * @throws \Exception To stop the checkout.
	 */
	public static function on_classic_checkout( $order_id, $posted = null, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! self::switched_away( $order ) || ! Settings::is_connected() ) {
			return;
		}
		$reason = self::block_reason( $order );
		if ( null !== $reason ) {
			throw new \Exception( esc_html( $reason ) );
		}
	}

	/**
	 * Checkout block: runs before the Store API processes the payment.
	 * A RouteException becomes an error notice in the block.
	 *
	 * @param mixed $order Order.
	 * @return void
	 * @throws \Exception To stop the checkout.
	 */
	public static function on_block_checkout( $order ) {
		if ( ! self::switched_away( $order ) || ! Settings::is_connected() ) {
			return;
		}
		$reason = self::block_reason( $order );
		if ( null === $reason ) {
			return;
		}
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'outlinepay_order_already_paid', esc_html( $reason ), 409 );
		}
		throw new \Exception( esc_html( $reason ) );
	}

	/**
	 * Order-pay page: runs before the posted method is set and charged. When
	 * OutlinePay already took the payment, the shopper goes to the order's
	 * thank-you page instead.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public static function on_pay_page( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the woocommerce-pay nonce before this action.
		$gateway_id = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( '' === $gateway_id || ! self::switched_away( $order, $gateway_id ) || ! Settings::is_connected() ) {
			return;
		}
		$reason = self::block_reason( $order );
		if ( null === $reason ) {
			return;
		}
		wc_add_notice( $reason, 'error' );
		$fresh = wc_get_order( $order->get_id() );
		wp_safe_redirect( $fresh instanceof \WC_Order ? $fresh->get_checkout_order_received_url() : $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * Another gateway completed a payment by itself: check our attempts later,
	 * in the background, so that gateway's request is not held up.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_payment_complete( $order_id ) {
		if ( ! self::switched_away( wc_get_order( $order_id ) ) || ! Settings::is_connected() ) {
			return;
		}
		Scheduler::queue_switch_check( $order_id );
	}

	/**
	 * Background check after another gateway's payment. A paid OutlinePay
	 * attempt then lands as the "shopper paid twice" note (see Order_Sync).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function run_background_check( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! self::switched_away( $order ) || ! Settings::is_connected() ) {
			return;
		}
		try {
			$settled = self::find_settled( $order->get_id(), ( new Api_Client() )->with_timeout( Api_Client::SHORT_TIMEOUT ) );
		} catch ( Api_Error $error ) {
			return;
		}
		if ( null !== $settled ) {
			Order_Sync::apply( $order, $settled );
		}
	}
}
