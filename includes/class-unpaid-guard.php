<?php
/**
 * Stops WooCommerce from cancelling an unpaid order whose payment is still open.
 *
 * Before an order of ours is cancelled for being unpaid, every open attempt is
 * expired at OutlinePay. If one comes back paid, the order is synced and kept.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The woocommerce_cancel_unpaid_order filter.
 */
final class Unpaid_Guard {

	const WAITING = array( 'awaiting_payment', 'awaiting_deposit' );

	/**
	 * Hooks the filter.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * Decides whether WooCommerce may cancel the order.
	 *
	 * @param bool      $cancel Whether WooCommerce would cancel.
	 * @param \WC_Order $order  Order.
	 * @return bool
	 */
	public static function filter( $cancel, $order ) {
		if ( ! $cancel || ! Order_Sync::is_ours( $order ) || ! Settings::is_connected() ) {
			return $cancel;
		}

		$client = new Api_Client();
		try {
			$payments = $client->list_payments( $order->get_id() );
			foreach ( $payments as $index => $payment ) {
				if ( isset( $payment['status'], $payment['id'] ) && in_array( $payment['status'], self::WAITING, true ) ) {
					$payments[ $index ] = $client->expire_payment( $payment['id'] );
					Logger::info(
						'Expired an open attempt before cancelling',
						array(
							'order_id'   => $order->get_id(),
							'payment_id' => $payment['id'],
							'status'     => isset( $payments[ $index ]['status'] ) ? $payments[ $index ]['status'] : '',
						)
					);
				}
			}
		} catch ( Api_Error $error ) {
			// Unsure whether the shopper paid. Keep the order; WooCommerce asks again on its next run.
			return false;
		}

		$best = Order_Sync::pick( $payments );
		if ( $best && in_array( $best['status'], array( 'paid', 'needs_review', 'awaiting_deposit' ), true ) ) {
			Order_Sync::apply( $order, $best );
			return false;
		}
		return true;
	}
}
