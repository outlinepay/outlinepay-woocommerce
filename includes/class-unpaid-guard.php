<?php
/**
 * Stops WooCommerce from cancelling an unpaid order whose payment is still open.
 *
 * Before an order of ours is cancelled for being unpaid, every open attempt is
 * expired at OutlinePay. The order is kept when one comes back paid, when an
 * open attempt did not expire, or when a superseded attempt can still be paid
 * (OutlinePay keeps those payable until they expire and cannot expire them
 * early).
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

	const CANCEL      = 'cancel';
	const KEEP        = 'keep';
	const KEEP_SETTLE = 'keep_settle';

	/**
	 * Hooks the filter.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * Decides from the attempts after expiry. Pure.
	 *
	 * @param array $payments Attempts after the open ones were expired.
	 * @param int   $now      Unix time.
	 * @return string CANCEL, KEEP, or KEEP_SETTLE (keep and apply the picked payment).
	 */
	public static function decide( array $payments, $now ) {
		if ( null !== self::settle_target( $payments ) ) {
			return self::KEEP_SETTLE;
		}
		foreach ( $payments as $payment ) {
			$status = is_array( $payment ) && isset( $payment['status'] ) ? $payment['status'] : '';
			if ( in_array( $status, self::WAITING, true ) ) {
				return self::KEEP;
			}
			if ( 'superseded' === $status ) {
				$expires = isset( $payment['expires_at'] ) ? strtotime( (string) $payment['expires_at'] ) : false;
				if ( false === $expires || $expires > $now ) {
					return self::KEEP;
				}
			}
		}
		return self::CANCEL;
	}

	/**
	 * The attempt to apply when the order is kept: a paid or review attempt,
	 * else an issued virtual account. Pure.
	 *
	 * @param array $payments Attempts, newest first.
	 * @return array|null
	 */
	public static function settle_target( array $payments ) {
		$best = Order_Sync::pick( $payments );
		if ( $best && in_array( $best['status'], array( 'paid', 'needs_review' ), true ) ) {
			return $best;
		}
		foreach ( $payments as $payment ) {
			if ( is_array( $payment ) && isset( $payment['status'] ) && 'awaiting_deposit' === $payment['status'] ) {
				return $payment;
			}
		}
		return null;
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

		$decision = self::decide( $payments, time() );
		if ( self::KEEP_SETTLE === $decision ) {
			Order_Sync::apply( $order, self::settle_target( $payments ) );
		}
		if ( self::CANCEL !== $decision ) {
			Logger::info(
				'Unpaid order kept',
				array(
					'order_id' => $order->get_id(),
					'decision' => $decision,
				)
			);
			return false;
		}
		return true;
	}
}
