<?php
/**
 * The one place a payment from GET /payments becomes an order change.
 *
 * Idempotent: applying the same payment and status twice changes nothing the
 * second time. Unknown statuses change nothing. A payment for another order
 * or another installation is ignored.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Maps payments to orders.
 */
final class Order_Sync {

	const META_PAYMENT_ID = '_outlinepay_payment_id';
	const META_APPLIED    = '_outlinepay_applied';
	const META_HOLD       = '_outlinepay_hold';
	const META_VA         = '_outlinepay_virtual_account';

	const COMPLETE       = 'complete';
	const HOLD_MISMATCH  = 'hold_mismatch';
	const HOLD_DEPOSIT   = 'hold_deposit';
	const HOLD_REVIEW    = 'hold_review';
	const RETRY          = 'retry';
	const WAIT           = 'wait';
	const NOTE_DUPLICATE = 'note_duplicate';
	const IGNORE         = 'ignore';
	const MISMATCH       = 'ignore_mismatch';
	const UNKNOWN        = 'unknown';
	const BUSY           = 'busy';

	/**
	 * Decides what a payment means for an order. Pure: no WordPress calls.
	 *
	 * @param mixed       $payment         Payment JSON.
	 * @param int|string  $order_id        The order's id.
	 * @param int|null    $order_krw       The order total in whole KRW, null when not whole.
	 * @param string      $order_currency  The order currency.
	 * @param string|null $installation_id Our installation id, when known.
	 * @return string One of the decision constants.
	 */
	public static function decide( $payment, $order_id, $order_krw, $order_currency, $installation_id = null ) {
		if ( ! is_array( $payment ) || ! isset( $payment['id'], $payment['status'], $payment['order_id'] ) || ! is_string( $payment['status'] ) ) {
			return self::IGNORE;
		}
		if ( ! is_scalar( $payment['order_id'] ) || (string) $payment['order_id'] !== (string) $order_id ) {
			return self::MISMATCH;
		}
		if ( $installation_id && isset( $payment['installation'] ) && is_string( $payment['installation'] ) && $payment['installation'] !== $installation_id ) {
			return self::MISMATCH;
		}

		switch ( $payment['status'] ) {
			case 'paid':
				$amount_ok   = isset( $payment['amount'] ) && is_int( $payment['amount'] ) && null !== $order_krw && $payment['amount'] === $order_krw;
				$currency_ok = isset( $payment['currency'] ) && 'KRW' === $payment['currency'] && 'KRW' === $order_currency;
				return $amount_ok && $currency_ok ? self::COMPLETE : self::HOLD_MISMATCH;
			case 'awaiting_deposit':
				return self::HOLD_DEPOSIT;
			case 'awaiting_payment':
				return self::WAIT;
			case 'failed':
			case 'expired':
				return self::RETRY;
			case 'needs_review':
				return self::HOLD_REVIEW;
			case 'duplicate':
				return self::NOTE_DUPLICATE;
			case 'superseded':
				return self::IGNORE;
			default:
				return self::UNKNOWN;
		}
	}

	/**
	 * From all attempts of one order (newest first), the one that speaks for it:
	 * a paid attempt, else one needing review, else the newest that is not superseded.
	 *
	 * @param array $payments Payments, newest first.
	 * @return array|null
	 */
	public static function pick( array $payments ) {
		foreach ( array( 'paid', 'needs_review' ) as $status ) {
			foreach ( $payments as $payment ) {
				if ( is_array( $payment ) && isset( $payment['status'] ) && $status === $payment['status'] ) {
					return $payment;
				}
			}
		}
		foreach ( $payments as $payment ) {
			if ( is_array( $payment ) && isset( $payment['status'] ) && 'superseded' !== $payment['status'] ) {
				return $payment;
			}
		}
		return null;
	}

	/**
	 * True when the order was placed with one of our gateways.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_ours( $order ) {
		return $order instanceof \WC_Order && null !== Settings::method_for_gateway( $order->get_payment_method() );
	}

	/**
	 * Applies a payment to its order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $payment Payment JSON from GET /payments.
	 * @return string The decision taken.
	 */
	public static function apply( $order, $payment ) {
		if ( ! self::is_ours( $order ) ) {
			return self::IGNORE;
		}
		$order_id = $order->get_id();
		if ( ! self::lock( $order_id ) ) {
			Logger::warning( 'Order busy, sync skipped', array( 'order_id' => $order_id ) );
			return self::BUSY;
		}

		try {
			// Read fresh: another request may have changed the order while this
			// one waited for the lock, and this request may hold a cached copy.
			self::forget_cached_order( $order_id );
			$fresh = wc_get_order( $order_id );
			if ( $fresh instanceof \WC_Order ) {
				$order = $fresh;
			}
			$installation = Settings::installation();
			$decision     = self::decide(
				$payment,
				$order_id,
				Amount::to_krw( $order->get_total() ),
				$order->get_currency(),
				isset( $installation['id'] ) ? $installation['id'] : null
			);
			$context      = array(
				'order_id'   => $order_id,
				'payment_id' => is_array( $payment ) && isset( $payment['id'] ) ? $payment['id'] : '',
				'status'     => is_array( $payment ) && isset( $payment['status'] ) ? $payment['status'] : '',
				'decision'   => $decision,
			);

			if ( in_array( $decision, array( self::IGNORE, self::MISMATCH, self::UNKNOWN, self::WAIT ), true ) ) {
				if ( self::UNKNOWN === $decision || self::MISMATCH === $decision ) {
					Logger::warning( 'Payment not applied', $context );
				}
				return $decision;
			}

			$key     = $payment['id'] . ':' . $payment['status'];
			$applied = $order->get_meta( self::META_APPLIED );
			$applied = is_array( $applied ) ? $applied : array();
			if ( in_array( $key, $applied, true ) ) {
				return $decision;
			}

			self::act( $order, $payment, $decision );

			$applied[] = $key;
			$order->update_meta_data( self::META_APPLIED, array_slice( $applied, -20 ) );
			$order->save();
			Logger::info( 'Payment applied', $context );
			return $decision;
		} finally {
			self::unlock( $order_id );
		}
	}

	/**
	 * Performs the order change for a decision.
	 *
	 * @param \WC_Order $order    Order.
	 * @param array     $payment  Payment.
	 * @param string    $decision Decision.
	 * @return void
	 */
	private static function act( $order, array $payment, $decision ) {
		$payment_id = (string) $payment['id'];
		$paid       = $order->is_paid();

		switch ( $decision ) {
			case self::COMPLETE:
				if ( $paid ) {
					return;
				}
				$order->update_meta_data( self::META_PAYMENT_ID, $payment_id );
				$order->delete_meta_data( self::META_HOLD );
				$transaction = isset( $payment['transaction_id'] ) && is_string( $payment['transaction_id'] ) ? $payment['transaction_id'] : '';
				$order->add_order_note(
					/* translators: %s: OutlinePay payment id. */
					sprintf( __( 'OutlinePay payment %s is paid.', 'outlinepay-for-woocommerce' ), $payment_id )
				);
				$order->payment_complete( $transaction );
				return;

			case self::HOLD_MISMATCH:
				if ( $paid ) {
					return;
				}
				$order->update_meta_data( self::META_HOLD, 'mismatch' );
				$order->update_status(
					'on-hold',
					/* translators: %s: OutlinePay payment id. */
					sprintf( __( 'OutlinePay payment %s reports paid, but the amount or currency does not match this order. Check it before shipping.', 'outlinepay-for-woocommerce' ), $payment_id )
				);
				return;

			case self::HOLD_DEPOSIT:
				if ( $paid ) {
					return;
				}
				$account = isset( $payment['virtual_account'] ) && is_array( $payment['virtual_account'] ) ? $payment['virtual_account'] : array();
				$order->update_meta_data( self::META_PAYMENT_ID, $payment_id );
				$order->update_meta_data(
					self::META_VA,
					array(
						'bank'           => isset( $account['bank'] ) ? sanitize_text_field( (string) $account['bank'] ) : '',
						'account_number' => isset( $account['account_number'] ) ? sanitize_text_field( (string) $account['account_number'] ) : '',
						'deadline'       => isset( $account['deadline'] ) ? sanitize_text_field( (string) $account['deadline'] ) : '',
					)
				);
				$order->update_meta_data( self::META_HOLD, 'deposit' );
				if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
					$order->update_status( 'on-hold', __( 'OutlinePay issued a virtual account. Waiting for the deposit.', 'outlinepay-for-woocommerce' ) );
				}
				return;

			case self::RETRY:
				if ( $paid ) {
					return;
				}
				if ( $order->has_status( 'on-hold' ) && 'deposit' === $order->get_meta( self::META_HOLD ) && $payment_id === $order->get_meta( self::META_PAYMENT_ID ) ) {
					$order->delete_meta_data( self::META_HOLD );
					$order->delete_meta_data( self::META_VA );
					$order->update_status(
						'pending',
						/* translators: %s: OutlinePay payment id. */
						sprintf( __( 'OutlinePay payment %s expired before the deposit arrived.', 'outlinepay-for-woocommerce' ), $payment_id )
					);
					return;
				}
				$order->add_order_note(
					/* translators: 1: OutlinePay payment id, 2: status such as failed or expired. */
					sprintf( __( 'OutlinePay payment %1$s is %2$s. The order is waiting for payment.', 'outlinepay-for-woocommerce' ), $payment_id, $payment['status'] )
				);
				return;

			case self::HOLD_REVIEW:
				if ( $paid ) {
					return;
				}
				$order->update_meta_data( self::META_HOLD, 'review' );
				$order->update_status(
					'on-hold',
					/* translators: %s: OutlinePay payment id. */
					sprintf( __( 'OutlinePay payment %s needs review: it was paid for a different amount than ordered. OutlinePay has been alerted. Do not ship until it is settled.', 'outlinepay-for-woocommerce' ), $payment_id )
				);
				return;

			case self::NOTE_DUPLICATE:
				$order->add_order_note(
					/* translators: %s: OutlinePay payment id. */
					sprintf( __( 'OutlinePay payment %s was paid after this order was already paid. The shopper paid twice. OutlinePay has been alerted. Refund the second payment in your payment provider dashboard.', 'outlinepay-for-woocommerce' ), $payment_id )
				);
				return;
		}
	}

	/**
	 * Drops this request's cached copy of the order, so the next read comes from storage.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function forget_cached_order( $order_id ) {
		if ( function_exists( 'wp_cache_flush_runtime' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'orders' );
	}

	/**
	 * Takes a short per-order lock so a callback and a return cannot apply at once.
	 *
	 * An INSERT IGNORE on the options table is atomic, unlike add_option(), which
	 * upserts. The row is never cached and never autoloaded.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private static function lock( $order_id ) {
		global $wpdb;
		$option = 'outlinepay_lock_' . (int) $order_id;
		for ( $try = 0; $try < 12; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; caching would defeat it.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, (string) time() ) );
			if ( 1 === (int) $inserted ) {
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the lock row itself.
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			if ( $since > 0 && time() - $since > 30 ) {
				self::unlock( $order_id );
				continue;
			}
			usleep( 250000 );
		}
		return false;
	}

	/**
	 * Releases the lock.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function unlock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the lock row.
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'outlinepay_lock_' . (int) $order_id ) );
	}
}
