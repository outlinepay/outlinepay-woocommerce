<?php
/**
 * Closes OutlinePay windows when an order moves to another payment method.
 *
 * A shopper can open an OutlinePay window, come back, and finish the same
 * order with another gateway. Their open OutlinePay attempts are expired at
 * that point, best effort. If one turns out paid already, the order is synced
 * after the current request, once the other gateway has finished with it.
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
	 * Hooks checkout (classic and block) and payment completion by other gateways.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_order_id' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_order_id' ), 20, 1 );
	}

	/**
	 * Hook adapter for actions that pass an order id.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_order_id( $order_id ) {
		self::on_order( wc_get_order( $order_id ) );
	}

	/**
	 * True when the order now uses another gateway but still has OutlinePay attempts.
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	public static function switched_away( $order ) {
		return $order instanceof \WC_Order
			&& null === Settings::method_for_gateway( $order->get_payment_method() )
			&& array() !== Order_Sync::attempts( $order );
	}

	/**
	 * Expires the order's open attempts when it moved to another gateway.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public static function on_order( $order ) {
		if ( ! self::switched_away( $order ) || ! Settings::is_connected() ) {
			return;
		}
		$client   = new Api_Client();
		$settle   = false;
		$order_id = $order->get_id();
		try {
			foreach ( $client->list_payments( $order_id ) as $payment ) {
				$status = isset( $payment['status'] ) ? $payment['status'] : '';
				if ( isset( $payment['id'] ) && in_array( $status, Unpaid_Guard::WAITING, true ) ) {
					$payment = $client->expire_payment( $payment['id'] );
					$status  = isset( $payment['status'] ) ? $payment['status'] : '';
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
					$settle = true;
				}
			}
		} catch ( Api_Error $error ) {
			// Best effort. A payment through the old window still reaches the order by callback or reconcile.
			return;
		}
		if ( $settle ) {
			Scheduler::queue_order_sync( $order_id );
		}
	}
}
